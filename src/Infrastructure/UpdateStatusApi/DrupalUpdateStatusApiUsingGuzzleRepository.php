<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mxr576\ddqg\Domain\ProjectIdRepository;
use mxr576\ddqg\Domain\UnsupportedReleasesRepository;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Parser\UniqueNode;

/**
 * @internal
 */
final class DrupalUpdateStatusApiUsingGuzzleRepository implements ProjectIdRepository, UnsupportedReleasesRepository
{
    private ClientInterface $client;

    public function __construct(Contract\Guzzle7ClientFactory $clientFactory)
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

              $all_unsupported_versions = array_map(static function (string $constraint): string {
                  static $prefixes = ['10.x-', '9.x-', '8.x-', '7.x-'];
                  foreach ($prefixes as $prefix) {
                      if (str_starts_with($constraint, $prefix)) {
                          if ($constraint === $prefix . 'dev') {
                              continue;
                          }

                          return substr_replace($constraint, '', 0, strlen($prefix));
                      }
                  }

                  return $constraint;
              }, $all_unsupported_versions);

              // @todo Find a better way to get rid of some invalid releases
              //   that are not available via Drupal Packagist anyway.
              //   Examples:
              //    * aes:2.0-unstable1
              //    * https://www.drupal.org/project/amazon/releases/8.x-1.0-unstable3
              $all_unsupported_versions = array_filter($all_unsupported_versions, static fn (string $constraint): bool => !str_contains($constraint, 'unstable'));

              if (!empty($all_unsupported_versions)) {
                  $conflicts[$composer_namespace] = $all_unsupported_versions;
              }
          },
          'rejected' => static function (RequestException $reason, $index): void {
              throw $reason;
          },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $conflicts;
    }
}
