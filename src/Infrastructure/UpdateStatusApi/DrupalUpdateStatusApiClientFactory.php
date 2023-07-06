<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use mxr576\ddqg\Infrastructure\HttpClient\Guzzle7ClientFactory;
use mxr576\ddqg\Supportive\Guzzle\CacheStorage;

/**
 * @internal
 */
final class DrupalUpdateStatusApiClientFactory implements Guzzle7ClientFactory
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
                        new CacheStorage(),
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
