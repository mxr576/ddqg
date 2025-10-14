<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Port;

/**
 * @internal This class is not part of the module's public programming API.
 */
interface ListAllTaggedReleases
{
    /**
     * @return ProjectInfo[]
     */
    public function fetchTaggedReleases(): array;
}
