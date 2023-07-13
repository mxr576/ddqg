<?php

declare(strict_types=1);

namespace mxr576\ddqg\Supportive\Guzzle;

use Kevinrob\GuzzleCache\CacheEntry;
use Kevinrob\GuzzleCache\Storage\CacheStorageInterface;
use Kevinrob\GuzzleCache\Storage\FlysystemStorage;
use League\Flysystem\Adapter\Local;

/**
 * symfony/cache:6.3.1 was tried out as an alternative, but it caused and endless loop
 * in \mxr576\ddqg\Infrastructure\DrupalOrg\UpdateStatusApi\DrupalUpdateStatusApiUsingGuzzleRepository::fetchProjectIds().
 * Somehow non-sandbox project ids that would have matched the filtering criteria were never returned
 * when cache was warmed up.
 *
 * @code
 * $this->inner = new \Kevinrob\GuzzleCache\Storage\Psr6CacheStorage(
 *    new \Symfony\Component\Cache\Adapter\ChainAdapter([new \Symfony\Component\Cache\Adapter\ArrayAdapter(), new \Symfony\Component\Cache\Adapter\FilesystemAdapter(
 *      directory: dirname(__DIR__, 3) . '/.cache/'
 *    )])
 * );
 *
 * @endcode
 *
 * @internal
 */
final class CacheStorage implements CacheStorageInterface
{
    private readonly CacheStorageInterface $inner;

    public function __construct()
    {
        $this->inner = new FlysystemStorage(
            // Consider making location configurable.
            new Local(dirname(__DIR__, 3) . '/.cache')
        );
    }

      public function fetch($key): CacheEntry|null
      {
          return $this->inner->fetch($key);
      }

      public function save($key, CacheEntry $data): bool
      {
          return $this->inner->save($key, $data);
      }

      public function delete($key): bool
      {
          return $this->inner->delete($key);
      }
}
