<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi\Contract;

use GuzzleHttp\ClientInterface;

interface Guzzle7ClientFactory
{
    public function getClient(): ClientInterface;
}
