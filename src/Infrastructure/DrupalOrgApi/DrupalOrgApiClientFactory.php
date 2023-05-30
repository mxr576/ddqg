<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrgApi;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\FlysystemStorage;
use Kevinrob\GuzzleCache\Strategy\PublicCacheStrategy;
use League\Flysystem\Adapter\Local;
use mxr576\ddqg\Infrastructure\HttpClient\Guzzle7ClientFactory;

/**
 * @internal
 */
final class DrupalOrgApiClientFactory implements Guzzle7ClientFactory
{
    private ClientInterface|null $client = null;

    public function getClient(): ClientInterface
    {
        if (null === $this->client) {
            $stack = HandlerStack::create();
            $stack->push(GuzzleRetryMiddleware::factory());
            $stack->push(
                new CacheMiddleware(
                    new PublicCacheStrategy(
                        new FlysystemStorage(
                            // TODO Make location configurable.
                            new Local(sys_get_temp_dir() . '/mxr576/ddqg/' . InstalledVersions::getPrettyVersion('mxr576/ddqg') . '/cache')
                        ),
                    )
                ),
                'cache'
            );
            $this->client = new Client([
              'base_uri' => 'https://www.drupal.org/api-d7/',
              'headers' => [
                'User-Agent' => 'mxr576/ddqg',
                'Accept' => 'application/json',
              ],
              'handler' => $stack,
            ]);
        }

        return $this->client;
    }
}
