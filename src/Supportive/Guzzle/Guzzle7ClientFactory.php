<?php

declare(strict_types=1);

namespace mxr576\ddqg\Supportive\Guzzle;

use GuzzleHttp\ClientInterface;

/**
 * @internal
 */
interface Guzzle7ClientFactory
{
    public function getClient(): ClientInterface;
}
