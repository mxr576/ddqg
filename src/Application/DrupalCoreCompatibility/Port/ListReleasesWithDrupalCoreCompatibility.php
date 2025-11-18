<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Port;

use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @internal This class is not part of the module's public programming API.
 */
interface ListReleasesWithDrupalCoreCompatibility
{
    /**
     * @return ProjectInfo[]
     */
    public function fetchReleasesWithDrupalCoreCompatibility(ConstraintInterface $drupalCoreVersionConstraint): array;
}
