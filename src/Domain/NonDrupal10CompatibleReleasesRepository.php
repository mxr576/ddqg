<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface NonDrupal10CompatibleReleasesRepository
{
    /**
     * @phpstan-return array<string,string[]>
     */
    public function fetchNonDrupal10CompatibleVersions(string ...$project_ids): array;
}
