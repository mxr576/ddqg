<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\DrupalOrgApi;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamWrapper;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use loophp\collection\Collection;
use mxr576\ddqg\Domain\AbandonedProjectsRepository;
use mxr576\ddqg\Domain\ProjectIdRepository;
use mxr576\ddqg\Infrastructure\DrupalOrg\Enum\ProjectTypesExposedViaDrupalPackagist;
use mxr576\ddqg\Supportive\Guzzle\Guzzle7ClientFactory;

/**
 * @internal
 */
final class DrupalOrgApiRepository implements AbandonedProjectsRepository, ProjectIdRepository
{
    private const VOCAB_ID_DEVELOPMENT_STATUS = 46;

    private const VOCAB_ID_MAINTENANCE_STATUS = 44;

    private const TERM_ID_DEVELOPMENT_STATUS_OBSOLETE = 9994;

    private const TERM_ID_MAINTENANCE_STATUS_UNSUPPORTED = 13032;

    private readonly ClientInterface $client;

    public function __construct(Guzzle7ClientFactory $clientFactory)
    {
        $this->client = $clientFactory->getClient();
    }

    public function fetchAllAbandonedProjectIds(): array
    {
        return array_merge(
            $this->fetchProjectNames([
              'filter_by_term' => (object) [
                'vocab_id' => self::VOCAB_ID_DEVELOPMENT_STATUS,
                'term_id' => self::TERM_ID_DEVELOPMENT_STATUS_OBSOLETE,
              ],
              'page' => 0,
            ]),
            $this->fetchProjectNames([
              'filter_by_term' => (object) [
                'vocab_id' => self::VOCAB_ID_MAINTENANCE_STATUS,
                'term_id' => self::TERM_ID_MAINTENANCE_STATUS_UNSUPPORTED,
              ],
              'page' => 0,
            ]),
        );
    }

    public function fetchProjectIds(): array
    {
        return $this->fetchProjectNames([
          'page' => 0,
        ]);
    }

    /**
     * @phpstan-param array{types?: array<value-of<ProjectTypesExposedViaDrupalPackagist>>,filter_by_term?: object{"vocab_id":int,"term_id": int}, "page": 0|positive-int} $filter
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \JsonMachine\Exception\InvalidArgumentException
     *
     * @return string[]
     */
    private function fetchProjectNames(array $filter): array
    {
        $url_builder = static function (array $filter): string {
            $output = 'node.json?';
            /* @var array{filter_by_term?: object{"vocab_id":int,"term_id": int}, "page": 0|positive-int} $filter */
            // Hot take... maybe sorting result by last change date (changed) leads to data loss? Some project names
            // were missing from previous results when we compared the output by this API and the Update Status API.
            $query = [
              'type' => $filter['type'] ?? array_column(ProjectTypesExposedViaDrupalPackagist::cases(), 'value'),
              'field_project_has_releases' => 1,
              'field_project_type' => 'full',
              'sort' => 'field_project_machine_name',
              'page' => $filter['page'],
            ];
            if (array_key_exists('filter_by_term', $filter)) {
                $query['taxonomy_vocabulary_' . $filter['filter_by_term']->vocab_id] = $filter['filter_by_term']->term_id;
            }

            $output .= http_build_query($query);

            return $output;
        };

        $page_count = 0;
        $project_names_from_other_pages = [];

        $response = $this->client->request('GET', $url_builder($filter));
        $phpStream = StreamWrapper::getResource($response->getBody());

        $last_page_url = null;
        $project_names_on_first_page = Collection::fromIterable(Items::fromStream($phpStream,
            ['decoder' => new ExtJsonDecoder(true)]))
          ->filter(static function (mixed $v, mixed $k): bool {
              assert(is_string($k));

              return 'list' === $k || 'last' === $k;
          })
          ->apply(static function (mixed $v, mixed $k) use (&$last_page_url): bool {
              assert(is_string($k));
              if ('last' === $k) {
                  $last_page_url = $v;
              }

              return true;
          })
          ->filter(static function (mixed $v, mixed $k): bool {
              assert(is_string($k));

              return 'list' === $k;
          })
          ->map(
              static function ($value): array {
                  assert(is_array($value));

                  return Collection::fromIterable($value)
                    ->map(static function ($value): string {
                        assert(is_array($value));

                        return $value['field_project_machine_name'];
                    })->all(false);
              }
          )
          ->limit(1)->current(0, []);

        if (null !== $last_page_url) {
            assert(is_string($last_page_url));
            $parsed_url = parse_url($last_page_url, PHP_URL_QUERY);
            assert(is_string($parsed_url));
            $query_params = Query::parse($parsed_url);
            $page_count = (int) $query_params['page'];
            $client = $this->client;
            $requests = static function (int $max_pages) use ($url_builder, $filter) {
                for ($i = $filter['page'] + 1; $i <= $max_pages; ++$i) {
                    $filter['page'] = $i;
                    yield $i => new Request('GET', $url_builder($filter));
                }
            };

            $pool = new Pool($client, $requests($page_count), [
              'concurrency' => 10,
              'fulfilled' => function (Response $response, $index) use (&$project_names_from_other_pages): void {
                  $phpStream = StreamWrapper::getResource($response->getBody());
                  $collection = Collection::fromIterable(Items::fromStream($phpStream, ['decoder' => new ExtJsonDecoder(true)]))
                    ->filter(static function (mixed $v, mixed $k): bool {
                        assert(is_string($k));

                        return 'list' === $k;
                    })
                    ->map(
                        static function ($value): array {
                            assert(is_array($value));

                            return Collection::fromIterable($value)
                              ->map(static function ($value): string {
                                  assert(is_array($value));

                                  return $value['field_project_machine_name'];
                              })->all(false);
                        }
                    );
                  $project_names_from_other_pages[] = $collection->limit(1)->current(0, []);
              },
              'rejected' => static function (RequestException $reason, int $index): void {
                  throw new \RuntimeException(sprintf('Failed to fetch page %d. Reason: "%s".', $index, $reason->getMessage()), $reason->getCode(), $reason);
              },
            ]);

            $promise = $pool->promise();
            $promise->wait();
        }

        return array_merge($project_names_on_first_page, ...$project_names_from_other_pages);
    }
}
