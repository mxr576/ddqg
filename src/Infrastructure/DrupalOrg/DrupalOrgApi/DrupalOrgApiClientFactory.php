<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\DrupalOrgApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\PublicCacheStrategy;
use mxr576\ddqg\Supportive\Guzzle\CacheStorage;
use mxr576\ddqg\Supportive\Guzzle\Guzzle7ClientFactory;

/**
 * @internal
 */
final class DrupalOrgApiClientFactory implements Guzzle7ClientFactory
{
    private ?ClientInterface $client = null;

    public function getClient(): ClientInterface
    {
        if (null === $this->client) {
            $stack = HandlerStack::create();
            $stack->push(new CacheMiddleware(new PublicCacheStrategy(new CacheStorage())), 'cache');
            $stack->push(new CustomErrorHandler(), 'customErrorHandler');
            $stack->remove('http_errors');
            $stack->push(GuzzleRetryMiddleware::factory(), 'retryAfter');
            $stack->push(new ZeroRetryAfterHeaderFixHandler(), 'zeroRetryAfterHeaderFix');
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
