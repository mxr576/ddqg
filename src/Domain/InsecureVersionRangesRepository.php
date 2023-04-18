<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface InsecureVersionRangesRepository
{
    /**
     * @phpstan-return array<string,string[]>
     */
    public function fetchInsecureVersionRanges(string ...$project_ids): array;
}
