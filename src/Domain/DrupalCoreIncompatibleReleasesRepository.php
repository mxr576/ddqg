<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface DrupalCoreIncompatibleReleasesRepository
{
    public function findDrupalCoreIncompatibleVersions(string $drupalCoreVersionString, string ...$project_ids): array;
}
