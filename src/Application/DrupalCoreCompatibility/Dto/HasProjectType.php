<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Dto;

interface HasProjectType
{
    /**
     * @return 'theme'|'module'|'profile'
     */
    public function getProjectTypeName(): string;
}
