<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Dto;

interface HasProjectId
{
    public function getProjectId(): string;
}
