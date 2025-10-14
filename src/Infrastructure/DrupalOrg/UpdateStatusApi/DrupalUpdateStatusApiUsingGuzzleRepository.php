<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\UpdateStatusApi;

use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListAllTaggedReleases;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListReleasesWithDrupalCoreCompatibility;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ProjectInfo;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ReleaseMetada;
use mxr576\ddqg\Domain\DrupalCoreIncompatibleReleasesRepository;
use mxr576\ddqg\Domain\InsecureVersionRangesRepository;
use mxr576\ddqg\Domain\ProjectIdRepository;
use mxr576\ddqg\Domain\UnsupportedReleasesRepository;
use mxr576\ddqg\Infrastructure\DrupalOrg\Enum\ProjectTypesExposedViaDrupalPackagist;
use mxr576\ddqg\Infrastructure\DrupalOrg\UpdateStatusApi\Type\SemVer;
use mxr576\ddqg\Supportive\Guzzle\Guzzle7ClientFactory;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Parser\UniqueNode;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class DrupalUpdateStatusApiUsingGuzzleRepository implements
    ProjectIdRepository,
    UnsupportedReleasesRepository,
    InsecureVersionRangesRepository,
    DrupalCoreIncompatibleReleasesRepository,
    ListReleasesWithDrupalCoreCompatibility,
    ListAllTaggedReleases
{
    private ClientInterface $client;

    public function __construct(Guzzle7ClientFactory $clientFactory, private readonly LoggerInterface $logger)
    {
        $this->client = $clientFactory->getClient();
    }

    public function fetchProjectIds(): array
    {
        $ids = [];
        // [RequestOptions::STREAM => TRUE] option kills reading stream with cold
        // cache...
        $response = $this->client->request('GET', 'project-list/all');
        $parser = new UniqueNode(['uniqueNode' => 'project']);
        $streamer = new XmlStringStreamer($parser, new XmlStreamBridgeForPsrStream($response->getBody()));
        while ($node = $streamer->getNode()) {
            if (is_bool($node)) {
                continue;
            }
            // @todo Silencing errors is not nice, added due to the missing "dc"
            //   namespace def.
            $node_as_simplexml = @simplexml_load_string($node);
            assert(!is_bool($node_as_simplexml));

            // Only these type of projects can be installed from D.o packagist via Composer as dependencies.
            $type_condition = implode(' or ', array_map(static fn (string $type): string => "type = '{$type}'", array_column(ProjectTypesExposedViaDrupalPackagist::cases(), 'value')));
            $id = $node_as_simplexml->xpath("//project[project_status = 'published' and ($type_condition) and link[not(contains(text(), 'sandbox'))]]/short_name/text()");
            if ([] === $id) {
                continue;
            }
            $ids[] = (string) $id[0];
        }

        return $ids;
    }

    public function fetchUnsupportedVersions(string ...$project_ids): array
    {
        $client = $this->client;
        $requests = static function (string ...$project_ids) {
            foreach ($project_ids as $project_id) {
                yield $project_id => new Request('GET', $project_id . '/current');
            }
        };

        $conflicts = [];

        $pool = new Pool($client, $requests(...$project_ids), [
            'concurrency' => 10,
            'fulfilled' => function (Response $response, $index) use (&$conflicts) {
                $project_as_simple_xml = simplexml_load_string($response->getBody()->getContents());
                assert(!is_bool($project_as_simple_xml));

                // No project release.
                if (!empty($project_as_simple_xml->xpath('/error'))) {
                    // TODO Consider logging this as it should not happen at this point.
                    return $response;
                }

                $composer_namespace = $project_as_simple_xml->xpath('/project/composer_namespace/text()');
                if (empty($composer_namespace)) {
                    $composer_namespace = 'drupal/' . (string) $project_as_simple_xml->short_name;
                } else {
                    $composer_namespace = (string) $composer_namespace[0];
                }

                // @todo Find a better workaround for packages with invalid name,
                //   like _config, or dummy__common that cannot be installed with
                //   Composer anyway.
                if (false == preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $composer_namespace)) {
                    return $response;
                }

                // Project is either unsupported or unpublished, mark all releases as uncovered.
                if (empty($project_as_simple_xml->xpath('/project[project_status = "published"]'))) {
                    $all_unsupported_versions = array_map(static fn (\SimpleXMLElement $e): string => (string) $e, $project_as_simple_xml->xpath('//releases/release/version'));
                } else {
                    $supported_branches = explode(',', (string) $project_as_simple_xml->xpath('/project/supported_branches/text()')[0]);
                    $supported_release_xpath_as_array = array_map(static function (string $branch): string {
                        return sprintf('starts-with(version, "%s")', $branch);
                    }, $supported_branches);
                    $not_supported_release_xpath_as_array = array_map(static function (string $xpath): string {
                        return sprintf('not(%s)', $xpath);
                    }, $supported_release_xpath_as_array);

                    $supported_release_xpath = implode(' or ', $supported_release_xpath_as_array);
                    $not_supported_release_xpath = implode(' and ', $not_supported_release_xpath_as_array);

                    // Add all versions that are not part of any supported branches or in a
                    // supported branch but not covered by security team.
                    // Also, even if the project is opted-in for security coverage but the release
                    // is unstable (e.g, 0.0.1) it has to be considered unsupported. At the time when
                    // the code was written Drupal Update status returned <security covered="1"> for
                    // 0.x.y releases too.
                    // @see https://www.drupal.org/project/infrastructure/issues/3437828
                    $all_unsupported_versions = array_map(static fn (\SimpleXMLElement $e): string => (string) $e, $project_as_simple_xml->xpath(sprintf('//releases/release[((%s) and security[not(@covered or @covered=0)]) or %s or starts-with(version, "0.")]/version', $supported_release_xpath, $not_supported_release_xpath)) ?: []);
                }

                $logger = $this->logger;
                $all_unsupported_version_constraints = array_reduce($all_unsupported_versions, static function (array $carry, string $version) use ($index, $logger) {
                    // 10.x-dev, 9.x-dev...
                    if (preg_match('/^[1-9]\d*\.x-dev$/', $version)) {
                        $carry[] = $version;

                        return $carry;
                    }

                    // 2.0.x-dev, 8.x-1.x-dev...
                    $matches = [];
                    if (preg_match('/^(?P<core_compat>0|[1-9]\d*\.x-)?(?P<dev_branch_tag>(?:0|[1-9]\d*)\.(?:x|(?:(?:0|[1-9]\d*)\.x))-dev)$/', $version, $matches)) {
                        $carry[] = $matches['dev_branch_tag'];
                    } else {
                        $semver = SemVer::tryFromPackageVersionString($version);
                        if (null === $semver) {
                            // There are invalid semver releases on Drupal.org like:
                            //  * aes:2.0-unstable1
                            //  * https://www.drupal.org/project/amazon/releases/8.x-1.0-unstable3
                            $logger->warning(sprintf('Unable to parse version of "%s" with "%s".', $index, $version));
                        } else {
                            $carry[] = $semver->asString;
                        }
                    }

                    return $carry;
                }, []);

                if (!empty($all_unsupported_version_constraints)) {
                    $conflicts[$composer_namespace] = $all_unsupported_version_constraints;
                }
            },
            'rejected' => static function (GuzzleException $reason, $index): void {
                throw new \RuntimeException(sprintf('Failed to fetch project information for "%s". Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $conflicts;
    }

    public function fetchInsecureVersionRanges(string ...$project_ids): array
    {
        $client = $this->client;
        $requests = static function (string ...$project_ids) {
            foreach ($project_ids as $project_id) {
                yield $project_id => new Request('GET', $project_id . '/current');
            }
        };

        $conflicts = [];

        $pool = new Pool($client, $requests(...$project_ids), [
            'concurrency' => 10,
            'fulfilled' => function (Response $response, $index) use (&$conflicts) {
                $project_as_simple_xml = simplexml_load_string($response->getBody()->getContents());
                assert(!is_bool($project_as_simple_xml));
                /** @var array<string,string> $constraints */
                $constraints = [];

                // No project release.
                if (!empty($project_as_simple_xml->xpath('/error'))) {
                    // TODO Consider logging this as it should not happen at this point.
                    return $response;
                }

                $composer_namespace = $project_as_simple_xml->xpath('/project/composer_namespace/text()');
                if (empty($composer_namespace)) {
                    $composer_namespace = 'drupal/' . (string) $project_as_simple_xml->short_name;
                } else {
                    $composer_namespace = (string) $composer_namespace[0];
                }

                // @todo Find a better workaround for packages with invalid name,
                //   like _config, or dummy__common that cannot be installed with
                //   Composer anyway.
                if (false == preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $composer_namespace)) {
                    return $response;
                }

                if (empty($project_as_simple_xml->xpath('/project[project_status = "published"]'))) {
                    return $response;
                }

                $supported_branches = explode(',', (string) $project_as_simple_xml->xpath('/project/supported_branches/text()')[0]);
                foreach ($supported_branches as $supported_branch) {
                    try {
                        $supported_branch_version = SemVer::fromSupportedBranchesString($supported_branch);
                    } catch (\InvalidArgumentException $e) {
                        // At the time when this code was written the only two projects
                        // that fail on this is "clickandpledge_drupalcommerce" and
                        // "test_proj_3", so we are not handling this case.
                        // "clickandpledge_drupalcommerce": "8.x-03.0" is not a valid version string with Drupal core compatibility prefix.
                        // https://updates.drupal.org/release-history/clickandpledge_drupalcommerce/current
                        // Composer sees: 3.2008100000.0, 3.2008010100.0, 3.2008010000.0, 3.2008000100.0, 3.2008000001.0, 3.2008000000.0
                        // https://www.drupal.org/project/test_proj_3/releases are even weirder...
                        $this->logger->warning(sprintf('Unable to parse version of "%s" with "%s".', $index, $supported_branch), ['exception' => $e]);
                        continue;
                    }
                    $supported_release_xpath = sprintf('starts-with(version, "%s")', $supported_branch);

                    $sec_releases = $project_as_simple_xml->xpath(sprintf('//releases/release[(%s) and (terms/term/name="Release type" and terms/term/value="Security update")][1]/version', $supported_release_xpath));
                    $sec_release_version = null;
                    if (!empty($sec_releases) && array_key_exists(0, $sec_releases)) {
                        $sec_release_version = SemVer::tryFromPackageVersionString((string) $sec_releases[0]);
                        if (null === $sec_release_version) {
                            $this->logger->warning(sprintf('Unable to parse version of "%s" with "%s".', $index, (string) $sec_releases[0]));
                        } else {
                            $constraints[$supported_branch . '-security'] = $this->generateConstraint($composer_namespace, $supported_branch_version, $sec_release_version);
                        }
                    }

                    // Insecure = https://www.drupal.org/taxonomy/term/188131?
                    // "Security team only — this specific release is insecure, due to a future version being a security release."
                    // e.g, https://www.drupal.org/project/vendor_stream_wrapper/releases/2.0.0 is marked as "insecure"
                    // most likely because https://www.drupal.org/project/vendor_stream_wrapper/releases/2.0.1 should have been
                    // marked as a "Security release" by the maintainers.
                    $insec_releases = $project_as_simple_xml->xpath(sprintf('//releases/release[(%s) and (terms/term/name="Release type" and terms/term/value="Insecure")][1]/version', $supported_release_xpath));
                    $should_lookup_for_releases = false;
                    if (!empty($insec_releases) && array_key_exists(0, $insec_releases)) {
                        $insecure_release_version_string = (string) $insec_releases[0];
                        // Check if the insecure release is older than the latest sec release.
                        if (null !== $sec_release_version) {
                            if (Comparator::lessThan($sec_release_version->asString, $insecure_release_version_string)) {
                                $should_lookup_for_releases = true;
                            }
                        } else {
                            $should_lookup_for_releases = true;
                        }

                        // We have to find the oldest release, which is newer than
                        // the newest insecure release.
                        if ($should_lookup_for_releases) {
                            $latest_should_be_secure_release_string = null;
                            $releases = $project_as_simple_xml->xpath(sprintf('//releases/release[(%s)]/version', $supported_release_xpath));
                            assert(is_array($releases));
                            foreach ($releases as $release) {
                                if (Comparator::greaterThan((string) $release, $insecure_release_version_string)) {
                                    $latest_should_be_secure_release_string = (string) $release;
                                    continue;
                                }
                                break;
                            }

                            $latest_should_be_secure_release = null === $latest_should_be_secure_release_string ? SemVer::tryFromPackageVersionString($insecure_release_version_string) : SemVer::tryFromPackageVersionString($latest_should_be_secure_release_string);
                            if (null === $latest_should_be_secure_release) {
                                $this->logger->warning(sprintf('Unable to parse "the latest should be secure" version of "%s" with "%s".', $index, $latest_should_be_secure_release_string ?? $insecure_release_version_string));
                            } else {
                                $constraints[$supported_branch . '-insecure'] = $this->generateConstraint($composer_namespace, $supported_branch_version, $latest_should_be_secure_release);
                            }
                        }
                    }
                }

                if (!empty($constraints)) {
                    // If multiple constraints contain the same version range
                    // we should only keep one.
                    // @see https://github.com/mxr576/ddqg/issues/3
                    $conflicts[$composer_namespace] = array_unique(array_values($constraints));
                }
            },
            'rejected' => static function (GuzzleException $reason, $index): void {
                throw new \RuntimeException(sprintf('Failed to fetch project information for "%s". Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $conflicts;
    }

    public function findDrupalCoreIncompatibleVersions(string $drupalCoreVersionString, string ...$project_ids): array
    {
        $client = $this->client;
        $version_parser = new VersionParser();
        $provider = new Constraint('>=', $version_parser->normalize($drupalCoreVersionString));

        // Drupal (core) should not be on the list.
        $drupal_array_index = array_search('drupal', $project_ids, true);
        if (false !== $drupal_array_index) {
            unset($project_ids[$drupal_array_index]);
        }

        $requests = static function (string ...$project_ids) {
            foreach ($project_ids as $project_id) {
                yield $project_id => new Request('GET', $project_id . '/current');
            }
        };

        $conflicts = [];

        $pool = new Pool($client, $requests(...$project_ids), [
            'concurrency' => 10,
            'fulfilled' => function (Response $response, $index) use (&$conflicts, $version_parser, $provider) {
                $project_as_simple_xml = simplexml_load_string($response->getBody()->getContents());
                assert(!is_bool($project_as_simple_xml));
                $all_unsupported_versions = [];

                // No project release.
                if (!empty($project_as_simple_xml->xpath('/error'))) {
                    // TODO Consider logging this as it should not happen at this point.
                    return $response;
                }

                // Skip distributions because they cannot be installed with Composer.
                if ('project_distribution' === (string) $project_as_simple_xml->type) {
                    return $response;
                }

                $composer_namespace = $project_as_simple_xml->xpath('/project/composer_namespace/text()');
                if (empty($composer_namespace)) {
                    $composer_namespace = 'drupal/' . (string) $project_as_simple_xml->short_name;
                } else {
                    $composer_namespace = (string) $composer_namespace[0];
                }

                // @todo Find a better workaround for packages with invalid name,
                //   like _config, or dummy__common that cannot be installed with
                //   Composer anyway.
                if (false == preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $composer_namespace)) {
                    return $response;
                }

                $releases = $project_as_simple_xml->xpath('//release');
                if (!empty($releases)) {
                    foreach ($releases as $release) {
                        if ($release->core_compatibility) {
                            $core_version_constraint_string = (string) $release->core_compatibility;
                        } elseif (str_contains((string) $release->version, '.x-')) {
                            [$core_version_constraint_string] = explode('-', (string) $release->version, 2);
                        } else {
                            // The majority of this should be noise caused by weird
                            // things done my maintainers... like a tagged 1.0.0
                            // with a README only, etc.
                            // It looks like the core compatibility information is
                            // also missing from project_drupalorg and
                            // project_general type of packages by design.
                            // @see https://www.drupal.org/project/drupalorg/issues/3358784
                            $this->logger->warning('Core version requirement could not be identified for "{project}:{version}" with "{type}" type.', [
                                'project' => (string) $project_as_simple_xml->short_name,
                                'type' => (string) $project_as_simple_xml->type,
                                'version' => (string) $release->version,
                            ]);
                            continue;
                        }
                        try {
                            $parsed_constraints = $version_parser->parseConstraints($core_version_constraint_string);
                        } catch (\Exception) {
                            $this->logger->warning('Unable to parse "{core_compatibility_string}" core version compatibility string of {project}:{version}.', [
                                'core_compatibility_string' => $core_version_constraint_string,
                                'project' => (string) $project_as_simple_xml->short_name,
                                'version' => (string) $release->version,
                            ]);
                            continue;
                        }
                        if (!$parsed_constraints->matches($provider)) {
                            $all_unsupported_versions[] = (string) $release->version;
                        }
                    }
                }

                $logger = $this->logger;
                $all_unsupported_version_constraints = array_reduce($all_unsupported_versions, static function (array $carry, string $version) use ($index, $logger) {
                    // 10.x-dev, 9.x-dev...
                    if (preg_match('/^[1-9]\d*\.x-dev$/', $version)) {
                        $carry[] = $version;

                        return $carry;
                    }

                    // 2.0.x-dev, 8.x-1.x-dev...
                    $matches = [];
                    if (preg_match('/^(?P<core_compat>0|[1-9]\d*\.x-)?(?P<dev_branch_tag>(?:0|[1-9]\d*)\.(?:x|(?:(?:0|[1-9]\d*)\.x))-dev)$/', $version, $matches)) {
                        $carry[] = $matches['dev_branch_tag'];
                    } else {
                        $semver = SemVer::tryFromPackageVersionString($version);
                        if (null === $semver) {
                            // There are invalid semver releases on Drupal.org like:
                            //  * aes:2.0-unstable1
                            //  * https://www.drupal.org/project/amazon/releases/8.x-1.0-unstable3
                            $logger->warning(sprintf('Unable to parse version of "%s" with "%s".', $index, $version));
                        } else {
                            $carry[] = $semver->asString;
                        }
                    }

                    return $carry;
                }, []);

                if (!empty($all_unsupported_version_constraints)) {
                    $conflicts[$composer_namespace] = $all_unsupported_version_constraints;
                }
            },
            'rejected' => static function (GuzzleException $reason, $index): void {
                throw new \RuntimeException(sprintf('Failed to fetch project information for "%s". Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $conflicts;
    }

    public function fetchTaggedReleases(): array
    {
        return $this->fetchReleases();
    }

    public function fetchReleasesWithDrupalCoreCompatibility(ConstraintInterface $drupalCoreVersionConstraint): array
    {
        return $this->fetchReleases($drupalCoreVersionConstraint);
    }

    /**
     * @return ProjectInfo[]
     */
    private function fetchReleases(?ConstraintInterface $drupalCoreVersionConstraint = null): array
    {
        $client = $this->client;
        $version_parser = new VersionParser();

        $project_ids = $this->fetchProjectIds();
        // @todo Consider excluding it in fetchProjectIds().
        $drupal_array_index = array_search('drupal', $project_ids, true);
        if (false !== $drupal_array_index) {
            unset($project_ids[$drupal_array_index]);
        }

        $requests = static function (string ...$project_ids) {
            foreach ($project_ids as $project_id) {
                yield $project_id => new Request('GET', $project_id . '/current');
            }
        };

        $project_info = [];

        $pool = new Pool($client, $requests(...$project_ids), [
            'concurrency' => 10,
            'fulfilled' => function (Response $response, $index) use (&$project_info, $version_parser, $drupalCoreVersionConstraint) {
                $project_as_simple_xml = simplexml_load_string($response->getBody()->getContents());
                assert(!is_bool($project_as_simple_xml));
                $compatible_releases = [];

                // No project release.
                if (!empty($project_as_simple_xml->xpath('/error'))) {
                    // TODO Consider logging this as it should not happen at this point.
                    return $response;
                }

                // @todo Consider excluding these types by default in fetchProjectIds().
                if (in_array((string) $project_as_simple_xml->type, ['project_core', 'project_general', 'project_distribution'], true)) {
                    return $response;
                }

                $composer_namespace = $project_as_simple_xml->xpath('/project/composer_namespace/text()');
                if (empty($composer_namespace)) {
                    $composer_namespace = 'drupal/' . (string) $project_as_simple_xml->short_name;
                } else {
                    $composer_namespace = (string) $composer_namespace[0];
                }

                // @todo Find a better workaround for packages with invalid name,
                //   like _config, or dummy__common that cannot be installed with
                //   Composer anyway.
                if (false == preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $composer_namespace)) {
                    return $response;
                }

                $releases = $project_as_simple_xml->xpath('//release');
                if (!empty($releases)) {
                    foreach ($releases as $release) {
                        $release_version = SemVer::tryFromPackageVersionString((string) $release->version);
                        if (null === $release_version) {
                            $this->logger->warning(sprintf('Unable to parse release version of "%s" with "%s".', $index, (string) $release->version));
                            continue;
                        }

                        if ($drupalCoreVersionConstraint) {
                            if ($release->core_compatibility) {
                                $core_version_constraint_string = (string) $release->core_compatibility;
                            } elseif (str_contains((string) $release->version, '.x-')) {
                                [$core_version_constraint_string] = explode('-', (string) $release->version, 2);
                            } else {
                                // The majority of this should be noise caused by weird
                                // things done my maintainers... like a tagged 1.0.0
                                // with a README only, etc.
                                // It looks like the core compatibility information is
                                // also missing from project_drupalorg and
                                // project_general type of packages by design.
                                // @see https://www.drupal.org/project/drupalorg/issues/3358784
                                $this->logger->warning('Core version requirement could not be identified for "{project}:{version}" with "{type}" type.', [
                                    'project' => (string) $project_as_simple_xml->short_name,
                                    'type' => (string) $project_as_simple_xml->type,
                                    'version' => (string) $release->version,
                                ]);
                                continue;
                            }
                            try {
                                $parsed_constraints = $version_parser->parseConstraints($core_version_constraint_string);
                            } catch (\Exception) {
                                $this->logger->warning('Unable to parse "{core_compatibility_string}" core version compatibility string of {project}:{version}.', [
                                    'core_compatibility_string' => $core_version_constraint_string,
                                    'project' => (string) $project_as_simple_xml->short_name,
                                    'version' => (string) $release->version,
                                ]);
                                continue;
                            }
                            if ($parsed_constraints->matches($drupalCoreVersionConstraint)) {
                                $compatible_releases[] = new ReleaseMetada(
                                    $release_version,
                                    \DateTimeImmutable::createFromFormat('U', (string) $release->date)
                                );
                            }
                        } else {
                            $compatible_releases[] = new ReleaseMetada(
                                $release_version,
                                \DateTimeImmutable::createFromFormat('U', (string) $release->date)
                            );
                        }
                    }
                }

                $project_info[] = new ProjectInfo($composer_namespace, (string) $project_as_simple_xml->title, str_replace('project_', '', (string) $project_as_simple_xml->type), $compatible_releases);
            },
            'rejected' => static function (GuzzleException $reason, $index): void {
                throw new \RuntimeException(sprintf('Failed to fetch project information for "%s". Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $project_info;
    }

    private function generateConstraint(string $composer_namespace, SemVer $lowest_boundary, SemVer $highest_boundary): string
    {
        if ('drupal/drupal' === $composer_namespace) {
            $constraint_lowest_boundary = "$highest_boundary->major.$highest_boundary->minor.0";
        } else {
            $constraint_lowest_boundary = $lowest_boundary->asString;
        }

        // The mail_login case:
        // <supported_branches>3.2.,4.2.,8.x-2.</supported_branches>
        // and 4.2.0 was the latest security release.
        // This means both the lowest- and the highest boundary was 4.2.0
        // at a time.
        if ($highest_boundary->asString === $constraint_lowest_boundary) {
            $constraint_lowest_boundary = "$highest_boundary->major.0.0";
        }

        // Avoid validation error:
        // "this version constraint cannot possibly match anything (>=1.0.0,<1.0.0)".
        if ($highest_boundary->asString === $constraint_lowest_boundary) {
            return $highest_boundary->asString;
        }

        return ">=$constraint_lowest_boundary,<$highest_boundary->asString";
    }
}
