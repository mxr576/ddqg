<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi;

use Composer\Semver\Comparator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mxr576\ddqg\Domain\InsecureVersionRangesRepository;
use mxr576\ddqg\Domain\ProjectIdRepository;
use mxr576\ddqg\Domain\UnsupportedReleasesRepository;
use mxr576\ddqg\Infrastructure\UpdateStatusApi\Type\SemVer;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Parser\UniqueNode;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class DrupalUpdateStatusApiUsingGuzzleRepository implements ProjectIdRepository, UnsupportedReleasesRepository, InsecureVersionRangesRepository
{
    private ClientInterface $client;

    private LoggerInterface $logger;

    public function __construct(Contract\Guzzle7ClientFactory $clientFactory, LoggerInterface $logger)
    {
        $this->client = $clientFactory->getClient();
        $this->logger = $logger;
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

              $id = $node_as_simplexml->xpath("//project[link[not(contains(text(), 'sandbox'))] and project_status = 'published']/short_name/text()");
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
                // //release[not(starts-with(version, "5.2.")) and not(starts-with(version, "6.0."))]
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
                $all_unsupported_versions = array_map(static fn (\SimpleXMLElement $e): string => (string) $e, $project_as_simple_xml->xpath(sprintf('//releases/release[((%s) and security[not(@covered or @covered=0)]) or %s]/version', $supported_release_xpath, $not_supported_release_xpath)) ?: []);
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
        'rejected' => static function (RequestException $reason, $index): void {
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

                        $latest_should_be_secure_release = null === $latest_should_be_secure_release_string ? SemVer::tryFromPackageVersionString($insecure_release_version_string) : Semver::tryFromPackageVersionString($latest_should_be_secure_release_string);
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
        'rejected' => static function (RequestException $reason, $index): void {
            throw new \RuntimeException(sprintf('Failed to fetch project information for "%s". Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
        },
      ]);

      $promise = $pool->promise();
      $promise->wait();

      return $conflicts;
  }

  private function generateConstraint(string $composer_namespace, SemVer $lowest_boundary, SemVer $highest_boundary): string
  {
      if ('drupal/drupal' === $composer_namespace) {
          $constraint_lowest_boundary = "$highest_boundary->major.$highest_boundary->minor.0";
      } else {
          $constraint_lowest_boundary = $lowest_boundary->asString;
      }

      if ($highest_boundary->asString === $constraint_lowest_boundary) {
          return $highest_boundary->asString;
      }

      return ">=$constraint_lowest_boundary,<$highest_boundary->asString";
  }
}
