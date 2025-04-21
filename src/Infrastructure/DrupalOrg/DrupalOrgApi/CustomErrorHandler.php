<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\DrupalOrgApi;

use GuzzleHttp\BodySummarizerInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Custom Guzzle error handler.
 *
 * Features:
 *  * Does not truncate error response body. https://github.com/guzzle/guzzle/issues/1722
 *  * Exposes response headers.
 *
 * @internal
 */
final class CustomErrorHandler
{
    /**
     * Throws exceptions when an HTTP error occurs.
     */
    public function __invoke(callable $handler): callable
    {
        return static function ($request, array $options) use ($handler) {
            if (empty($options['http_errors'])) {
                return $handler($request, $options);
            }

            return $handler($request, $options)->then(
                static function (ResponseInterface $response) use ($request) {
                    $code = $response->getStatusCode();
                    if ($code < 400) {
                        return $response;
                    }

                    throw RequestException::create($request, $response, null, [], new class implements BodySummarizerInterface {
                        public function summarize(MessageInterface $message): ?string
                        {
                            $parts = [];
                            $parts['headers'] = json_encode($message->getHeaders(), JSON_PRETTY_PRINT);
                            $parts['body'] = $message->getBody();

                            return implode("\n", $parts);
                        }
                    });
                }
            );
        };
    }
}
