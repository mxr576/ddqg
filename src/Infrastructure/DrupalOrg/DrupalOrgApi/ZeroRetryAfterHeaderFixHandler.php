<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\DrupalOrgApi;

use Psr\Http\Message\ResponseInterface;

/**
 * Update Status API returns Retry-After with 0 value that breaks retry strategy.
 *
 * @internal
 */
final class ZeroRetryAfterHeaderFixHandler
{
    public function __invoke(callable $handler): callable
    {
        return static function ($request, array $options) use ($handler) {
            return $handler($request, $options)->then(function (ResponseInterface $response) {
                if ($response->hasHeader('Retry-After')) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    if (0 === (int) $retryAfter) {
                        return $response->withHeader('Retry-After', '30');
                    }
                }

                return $response;
            });
        };
    }
}
