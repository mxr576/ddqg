<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface UnsupportedReleasesRepository
{
    /**
     * @phpstan-return array<string,string[]>
     */
    public function fetchUnsupportedVersions(string ...$project_ids): array;
}
