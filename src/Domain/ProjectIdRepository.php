<?php

declare(strict_types=1);

namespace mxr576\ddqg\Domain;

interface ProjectIdRepository
{
    /**
     * @return string[]
     */
    public function fetchProjectIds(): array;
}
