<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface AbandonedProjectsRepository
{
    /**
     * @phpstan-return array<string>
     */
    public function fetchAllAbandonedProjectIds(): array;
}
