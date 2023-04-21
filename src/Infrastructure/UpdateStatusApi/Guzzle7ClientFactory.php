<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\FlysystemStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use League\Flysystem\Adapter\Local;

/**
 * @internal
 */
final class Guzzle7ClientFactory implements Contract\Guzzle7ClientFactory
{
    private ClientInterface|null $client = null;

    public function getClient(): ClientInterface
    {
        if (null === $this->client) {
            $stack = HandlerStack::create();
            $stack->push(GuzzleRetryMiddleware::factory());
            $stack->push(
                new CacheMiddleware(
                    // Greedy strategy is necessary because important Cache-Control
                    // header values are missing from the response.
                    // @see https://www.drupal.org/project/infrastructure/issues/3353610
                    new GreedyCacheStrategy(
                        new FlysystemStorage(
                            // TODO Make location configurable.
                            new Local(sys_get_temp_dir() . '/mxr576/ddqg/' . InstalledVersions::getPrettyVersion('mxr576/ddqg') . '/cache')
                        ),
                        3600
                    )
                ),
                'cache'
            );
            $this->client = new Client([
              'base_uri' => 'https://updates.drupal.org/release-history/',
              'headers' => [
                'User-Agent' => 'mxr576/ddqg',
                'Accept' => 'application/xml',
              ],
              'handler' => $stack,
            ]);
        }

        return $this->client;
    }
}
