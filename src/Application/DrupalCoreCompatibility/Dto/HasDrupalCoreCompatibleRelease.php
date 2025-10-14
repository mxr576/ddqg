<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Dto;

/**
 * @internal This class is not part of the module's public programming API.
 */
final class HasDrupalCoreCompatibleRelease implements HasProjectId, HasProjectDisplayName, HasProjectType
{
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $type,
        public readonly string $firstCompatibleVersion,
        public readonly \DateTimeImmutable $firstCompatibleVersionReleaseDate,
        public readonly string $latestTaggedVersion,
        public readonly \DateTimeImmutable $latestTaggedVersionReleaseDate,
    ) {
    }

    public function getProjectId(): string
    {
        return $this->name;
    }

    public function getProjectDisplayName(): string
    {
        return $this->displayName;
    }

    public function getProjectTypeName(): string
    {
        return $this->type;
    }
}
