<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Port;

use mxr576\ddqg\Infrastructure\DrupalOrg\UpdateStatusApi\Type\SemVer;

/**
 * @internal This class is not part of the module's public programming API.
 */
final class ReleaseMetada
{
    public function __construct(
        public readonly SemVer $version,
        public readonly \DateTimeImmutable $releaseDate,
    ) {
    }
}
