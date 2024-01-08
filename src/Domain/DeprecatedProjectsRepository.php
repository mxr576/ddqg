<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

/**
 * @internal
 */
interface DeprecatedProjectsRepository
{
    /**
     * @phpstan-return array<string>
     */
    public function fetchAllDeprecatedProjectIds(): array;
}
