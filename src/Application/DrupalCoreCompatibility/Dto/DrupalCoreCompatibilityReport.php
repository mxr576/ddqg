<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Dto;

/**
 * Represents a complete Drupal Core compatibility report with all calculated data.
 *
 * @internal This class is not part of the module's public programming API.
 */
final class DrupalCoreCompatibilityReport
{
    /**
     * @param array<string, list<HasDrupalCoreCompatibleRelease>> $compatibleProjects
     * @param array<string, list<DoesNotHaveDrupalCoreCompatibleRelease>> $incompatibleProjects
     * @param array<string, array{compatible: int, incompatible: int, total: int, rate: float}> $compatibilityByType
     * @param array{name: string, date: \DateTimeImmutable}|null $firstCompatibleProject
     * @param array{name: string, date: \DateTimeImmutable}|null $lastCompatibleProject
     * @param list<array{name: string, id: string, type: string, status: string, compatibleVersion: string|null, compatibleDate: \DateTimeImmutable|null, latestVersion: string|null, latestDate: \DateTimeImmutable|null}> $allProjectsSorted
     */
    public function __construct(
        public readonly string $drupalCoreVersion,
        public readonly array $compatibleProjects,
        public readonly array $incompatibleProjects,
        public readonly int $totalCompatibleCount,
        public readonly int $totalIncompatibleCount,
        public readonly array $compatibilityByType,
        public readonly ?array $firstCompatibleProject,
        public readonly ?array $lastCompatibleProject,
        public readonly array $allProjectsSorted,
    ) {
    }

    /**
     * Returns compatible projects grouped by type (module, theme, profile).
     *
     * @return array<string, list<HasDrupalCoreCompatibleRelease>>
     */
    public function getCompatibleProjectsByType(): array
    {
        return $this->compatibleProjects;
    }

    /**
     * Returns incompatible projects grouped by type (module, theme, profile).
     *
     * @return array<string, list<DoesNotHaveDrupalCoreCompatibleRelease>>
     */
    public function getIncompatibleProjectsByType(): array
    {
        return $this->incompatibleProjects;
    }

    /**
     * Returns the total number of projects analyzed.
     */
    public function getTotalProjects(): int
    {
        return $this->totalCompatibleCount + $this->totalIncompatibleCount;
    }

    /**
     * Returns the overall compatibility rate as a percentage.
     */
    public function getOverallCompatibilityRate(): float
    {
        $total = $this->getTotalProjects();

        return $total > 0 ? ($this->totalCompatibleCount / $total) * 100 : 0.0;
    }
}
