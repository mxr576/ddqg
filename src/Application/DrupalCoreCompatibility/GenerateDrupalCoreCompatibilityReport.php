<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\DoesNotHaveDrupalCoreCompatibleRelease;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\DrupalCoreCompatibilityReport;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\HasDrupalCoreCompatibleRelease;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListAllTaggedReleases;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ListReleasesWithDrupalCoreCompatibility;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Port\ReleaseMetada;
use Webmozart\Assert\Assert;

/**
 * Generates a Drupal Core compatibility report for all projects.
 *
 * This use case fetches all projects from Drupal.org and determines which ones
 * have releases compatible with the specified Drupal core version.
 *
 * @internal This class is not part of the module's public programming API.
 */
final class GenerateDrupalCoreCompatibilityReport
{
    public function __construct(
        private readonly ListAllTaggedReleases&ListReleasesWithDrupalCoreCompatibility $releasesFetcher,
    ) {
    }

    /**
     * Generates a complete compatibility report with all calculations performed.
     *
     * @param string $drupalCoreVersionString The Drupal core version constraint.
     */
    public function __invoke(string $drupalCoreVersionString): DrupalCoreCompatibilityReport
    {
        $versionParser = new VersionParser();
        $drupalCoreVersionConstraint = $versionParser->parseConstraints($drupalCoreVersionString);
        Assert::isInstanceOf($drupalCoreVersionConstraint, ConstraintInterface::class);

        $allProjectsByName = [];
        foreach ($this->releasesFetcher->fetchTaggedReleases() as $project) {
            $allProjectsByName[$project->name] = $project;
        }

        /** @var array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects */
        $compatibleProjects = [];
        /** @var array<string, list<DoesNotHaveDrupalCoreCompatibleRelease>> $incompatibleProjects */
        $incompatibleProjects = [];

        foreach ($this->releasesFetcher->fetchReleasesWithDrupalCoreCompatibility($drupalCoreVersionConstraint) as $projectWithFilteredReleases) {
            $projectName = $projectWithFilteredReleases->name;
            $type = $projectWithFilteredReleases->type;

            $firstStableCompatibleRelease = $projectWithFilteredReleases->getFirstStableTaggedRelease();

            $allReleasesForProject = $allProjectsByName[$projectName] ?? null;
            $latestTaggedRelease = $allReleasesForProject?->getLatestTaggedRelease();

            if (null === $firstStableCompatibleRelease) {
                $projectInfo = new DoesNotHaveDrupalCoreCompatibleRelease(
                    name: $projectName,
                    displayName: $projectWithFilteredReleases->displayName,
                    type: $type,
                    latestTaggedVersion: $latestTaggedRelease?->version->asString,
                    latestTaggedVersionReleaseDate: $latestTaggedRelease?->releaseDate,
                );

                if (!isset($incompatibleProjects[$type])) {
                    $incompatibleProjects[$type] = [];
                }
                $incompatibleProjects[$type][] = $projectInfo;
            } else {
                assert($latestTaggedRelease instanceof ReleaseMetada);
                $projectInfo = new HasDrupalCoreCompatibleRelease(
                    name: $projectName,
                    displayName: $projectWithFilteredReleases->displayName,
                    type: $type,
                    firstCompatibleVersion: $firstStableCompatibleRelease->version->asString,
                    firstCompatibleVersionReleaseDate: $firstStableCompatibleRelease->releaseDate,
                    latestTaggedVersion: $latestTaggedRelease->version->asString,
                    latestTaggedVersionReleaseDate: $latestTaggedRelease->releaseDate,
                );

                if (!isset($compatibleProjects[$type])) {
                    $compatibleProjects[$type] = [];
                }
                $compatibleProjects[$type][] = $projectInfo;
            }
        }

        foreach ($compatibleProjects as &$projects) {
            usort($projects, fn (HasDrupalCoreCompatibleRelease $a, HasDrupalCoreCompatibleRelease $b): int => strcasecmp($a->getProjectId(), $b->getProjectId()));
        }
        unset($projects);

        foreach ($incompatibleProjects as &$projects) {
            usort($projects, fn (DoesNotHaveDrupalCoreCompatibleRelease $a, DoesNotHaveDrupalCoreCompatibleRelease $b): int => strcasecmp($a->getProjectId(), $b->getProjectId()));
        }
        unset($projects);

        ksort($compatibleProjects);
        ksort($incompatibleProjects);

        // Re-index to ensure proper list types.
        foreach ($compatibleProjects as $type => $projects) {
            $compatibleProjects[$type] = array_values($projects);
        }
        foreach ($incompatibleProjects as $type => $projects) {
            $incompatibleProjects[$type] = array_values($projects);
        }

        $totalCompatible = array_sum(array_map('count', $compatibleProjects));
        $totalIncompatible = array_sum(array_map('count', $incompatibleProjects));

        $compatibilityByType = $this->calculateCompatibilityByType($compatibleProjects, $incompatibleProjects);

        $firstProject = $this->findFirstCompatibleProject($compatibleProjects);
        $lastProject = $this->findLastCompatibleProject($compatibleProjects);

        $allProjectsSorted = $this->createSortedProjectList($compatibleProjects, $incompatibleProjects);

        return new DrupalCoreCompatibilityReport(
            drupalCoreVersion: $drupalCoreVersionString,
            compatibleProjects: $compatibleProjects,
            incompatibleProjects: $incompatibleProjects,
            totalCompatibleCount: $totalCompatible,
            totalIncompatibleCount: $totalIncompatible,
            compatibilityByType: $compatibilityByType,
            firstCompatibleProject: $firstProject,
            lastCompatibleProject: $lastProject,
            allProjectsSorted: $allProjectsSorted,
        );
    }

    /**
     * Calculates compatibility statistics per package type.
     *
     * @param array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects
     * @param array<string, list<DoesNotHaveDrupalCoreCompatibleRelease>> $incompatibleProjects
     *
     * @return array<string, array{compatible: int, incompatible: int, total: int, rate: float}>
     */
    private function calculateCompatibilityByType(array $compatibleProjects, array $incompatibleProjects): array
    {
        $allTypes = array_unique(array_merge(
            array_keys($compatibleProjects),
            array_keys($incompatibleProjects)
        ));
        sort($allTypes);

        $result = [];
        foreach ($allTypes as $type) {
            $compatible = isset($compatibleProjects[$type]) ? count($compatibleProjects[$type]) : 0;
            $incompatible = isset($incompatibleProjects[$type]) ? count($incompatibleProjects[$type]) : 0;
            $total = $compatible + $incompatible;
            $rate = $total > 0 ? ($compatible / $total) * 100 : 0.0;

            $result[$type] = [
                'compatible' => $compatible,
                'incompatible' => $incompatible,
                'total' => $total,
                'rate' => $rate,
            ];
        }

        return $result;
    }

    /**
     * Finds the first project to get a compatible release.
     *
     * @param array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects
     *
     * @return array{name: string, date: \DateTimeImmutable}|null
     */
    private function findFirstCompatibleProject(array $compatibleProjects): ?array
    {
        $earliest = null;

        foreach ($compatibleProjects as $projects) {
            foreach ($projects as $project) {
                if (null === $earliest || $project->firstCompatibleVersionReleaseDate < $earliest['date']) {
                    $earliest = [
                        'name' => $project->displayName,
                        'date' => $project->firstCompatibleVersionReleaseDate,
                    ];
                }
            }
        }

        return $earliest;
    }

    /**
     * Finds the most recent compatible release.
     *
     * @param array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects
     *
     * @return array{name: string, date: \DateTimeImmutable}|null
     */
    private function findLastCompatibleProject(array $compatibleProjects): ?array
    {
        $latest = null;

        foreach ($compatibleProjects as $projects) {
            foreach ($projects as $project) {
                if (null === $latest || $project->firstCompatibleVersionReleaseDate > $latest['date']) {
                    $latest = [
                        'name' => $project->displayName,
                        'date' => $project->firstCompatibleVersionReleaseDate,
                    ];
                }
            }
        }

        return $latest;
    }

    /**
     * Creates a flat, sorted list of all projects for table display.
     *
     * @param array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects
     * @param array<string, list<DoesNotHaveDrupalCoreCompatibleRelease>> $incompatibleProjects
     *
     * @return list<array{name: string, id: string, type: string, status: 'compatible'|'incompatible', compatibleVersion: string|null, compatibleDate: \DateTimeImmutable|null, latestVersion: string|null, latestDate: \DateTimeImmutable|null}>
     */
    private function createSortedProjectList(array $compatibleProjects, array $incompatibleProjects): array
    {
        $allProjects = [];

        foreach ($compatibleProjects as $type => $projects) {
            foreach ($projects as $project) {
                $allProjects[] = [
                    'name' => $project->displayName,
                    'id' => $project->name,
                    'type' => $type,
                    'status' => 'compatible',
                    'compatibleVersion' => $project->firstCompatibleVersion,
                    'compatibleDate' => $project->firstCompatibleVersionReleaseDate,
                    'latestVersion' => $project->latestTaggedVersion,
                    'latestDate' => $project->latestTaggedVersionReleaseDate,
                ];
            }
        }

        foreach ($incompatibleProjects as $type => $projects) {
            foreach ($projects as $project) {
                $allProjects[] = [
                    'name' => $project->displayName,
                    'id' => $project->name,
                    'type' => $type,
                    'status' => 'incompatible',
                    'compatibleVersion' => null,
                    'compatibleDate' => null,
                    'latestVersion' => $project->latestTaggedVersion,
                    'latestDate' => $project->latestTaggedVersionReleaseDate,
                ];
            }
        }

        usort($allProjects, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $allProjects;
    }
}
