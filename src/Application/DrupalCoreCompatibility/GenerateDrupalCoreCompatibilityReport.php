<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility;

use Composer\Semver\VersionParser;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\DoesNotHaveDrupalCoreCompatibleRelease;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\HasDrupalCoreCompatibleRelease;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListAllTaggedReleases;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListReleasesWithDrupalCoreCompatibility;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ProjectInfo;

/**
 * @internal This class is not part of the module's public programming API.
 */
final class GenerateDrupalCoreCompatibilityReport
{
    public function __construct(
        private readonly ListAllTaggedReleases&ListReleasesWithDrupalCoreCompatibility $releasesFetcher,
    ) {
    }

    public function __invoke(string $drupalCoreVersion): \Traversable
    {
        /** @var ProjectInfo[] $all_releases_by_name */
        $all_releases_by_name = array_reduce($this->releasesFetcher->fetchTaggedReleases(), static function (\ArrayObject $carry, ProjectInfo $project): \ArrayObject {
            $carry[$project->name] = $project;

            return $carry;
        }, new \ArrayObject())->getArrayCopy();
        foreach ($this->releasesFetcher->fetchReleasesWithDrupalCoreCompatibility((new VersionParser())->parseConstraints($drupalCoreVersion)) as $release) {
            $compatible_release = $release->getFirstStableTaggedRelease();
            $all_tagged_releases_by_project = $all_releases_by_name[$release->name] ?? null;
            $latest_tagged_release = $all_tagged_releases_by_project?->getLatestTaggedRelease();
            if (null === $compatible_release) {
                yield new DoesNotHaveDrupalCoreCompatibleRelease($release->name, $release->displayName, $release->type, $latest_tagged_release?->version->asString, $latest_tagged_release?->releaseDate);
            } else {
                yield new HasDrupalCoreCompatibleRelease($release->name, $release->displayName, $release->type, $compatible_release->version->asString, $compatible_release->releaseDate, $latest_tagged_release?->version->asString, $latest_tagged_release?->releaseDate);
            }
        }
    }
}
