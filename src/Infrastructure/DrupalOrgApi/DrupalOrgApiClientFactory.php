<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrgApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\PublicCacheStrategy;
use mxr576\ddqg\Infrastructure\HttpClient\Guzzle7ClientFactory;
use mxr576\ddqg\Supportive\Guzzle\CacheStorage;

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
            $stack->push(new CacheMiddleware(new PublicCacheStrategy(new CacheStorage())), 'cache');
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
