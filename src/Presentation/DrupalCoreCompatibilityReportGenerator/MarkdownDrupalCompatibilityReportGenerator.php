<?php

declare(strict_types=1);

namespace mxr576\ddqg\Presentation\DrupalCoreCompatibilityReportGenerator;

use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\DoesNotHaveDrupalCoreCompatibleRelease;
use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\HasDrupalCoreCompatibleRelease;

/**
 * @internal This class is not part of the module's public programming API.
 */
final class MarkdownDrupalCompatibilityReportGenerator
{
    /**
     * @param \Traversable<HasDrupalCoreCompatibleRelease|DoesNotHaveDrupalCoreCompatibleRelease> $releases
     *
     * @return string Raw markdown content
     */
    public function generateMarkdownReport(\Traversable $releases, string $drupalCoreVersion): string
    {
        $compatibleReleases = [];
        $incompatibleProjects = [];

        foreach ($releases as $release) {
            if ($release instanceof HasDrupalCoreCompatibleRelease) {
                $compatibleReleases[] = $release;
            } elseif ($release instanceof DoesNotHaveDrupalCoreCompatibleRelease) {
                $incompatibleProjects[] = $release;
            }
        }

        // Sort by project display name for consistent ordering
        usort($compatibleReleases, static function ($a, $b) {
            return strcasecmp($a->displayName, $b->displayName);
        });

        usort($incompatibleProjects, static function ($a, $b) {
            return strcasecmp($a->displayName, $b->displayName);
        });

        $markdown = $this->generateReportHeader($drupalCoreVersion);
        $markdown .= $this->generateSummarySection($compatibleReleases, $incompatibleProjects);
        $markdown .= $this->generateCompatibilityTable($compatibleReleases, $incompatibleProjects);

        return $markdown;
    }

    private function generateReportHeader(string $drupalCoreVersion): string
    {
        $generatedDate = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return "# Drupal contrib compatibility report for core {$drupalCoreVersion}\n\n" .
          "**Generated:** {$generatedDate}\n\n" .
          'This report shows when contributed projects got their first stable release ' .
          "compatible with Drupal core {$drupalCoreVersion}.\n\n";
    }

    /**
     * @param array<HasDrupalCoreCompatibleRelease> $compatibleReleases
     * @param array<DoesNotHaveDrupalCoreCompatibleRelease> $incompatibleProjects
     */
    private function generateSummarySection(array $compatibleReleases, array $incompatibleProjects): string
    {
        $totalProjects = count($compatibleReleases) + count($incompatibleProjects);
        $compatibleCount = count($compatibleReleases);
        $incompatibleCount = count($incompatibleProjects);
        $compatibilityRate = $totalProjects > 0 ?
          round(($compatibleCount / $totalProjects) * 100, 1) : 0;

        // Group by package type for detailed breakdown
        $compatibleByType = $this->groupByPackageType($compatibleReleases);
        $incompatibleByType = $this->groupByPackageType($incompatibleProjects);

        $markdown = "## Summary\n\n";

        // Overall statistics
        $markdown .= "### Overall compatibility\n\n";
        $markdown .= "| Metric | Value |\n";
        $markdown .= "|--------|-------|\n";
        $markdown .= "| Total projects analyzed | {$totalProjects} |\n";
        $markdown .= "| Projects with stable releases | {$compatibleCount} |\n";
        $markdown .= "| Projects without stable releases | {$incompatibleCount} |\n";
        $markdown .= "| Overall compatibility rate | {$compatibilityRate}% |\n\n";

        // Package type breakdown
        $markdown .= "### Compatibility by package type\n\n";
        $markdown .= "| Package type | Compatible | Incompatible | Total | Compatibility rate |\n";
        $markdown .= "|--------------|------------|--------------|-------|-------------------|\n";

        $packageTypes = array_unique(array_merge(
            array_keys($compatibleByType),
            array_keys($incompatibleByType)
        ));
        sort($packageTypes);

        foreach ($packageTypes as $type) {
            $compatibleForType = count($compatibleByType[$type] ?? []);
            $incompatibleForType = count($incompatibleByType[$type] ?? []);
            $totalForType = $compatibleForType + $incompatibleForType;
            $typeCompatibilityRate = $totalForType > 0 ?
              round(($compatibleForType / $totalForType) * 100, 1) : 0;

            $markdown .= "| {$type} | {$compatibleForType} | {$incompatibleForType} | {$totalForType} | {$typeCompatibilityRate}% |\n";
        }

        $markdown .= "\n";

        // Timeline information
        if (!empty($compatibleReleases)) {
            $markdown .= "### Release timeline\n\n";

            $sortedByDate = $compatibleReleases;
            usort($sortedByDate, static function ($a, $b) {
                return $a->firstCompatibleVersionReleaseDate <=> $b->firstCompatibleVersionReleaseDate;
            });

            $firstRelease = $sortedByDate[0];
            $lastRelease = end($sortedByDate);

            $markdown .= "| Metric | Project | Date |\n";
            $markdown .= "|--------|---------|------|\n";
            $markdown .= "| First project to get stable release | {$firstRelease->displayName} | {$firstRelease->firstCompatibleVersionReleaseDate->format('Y-m-d')} |\n";

            if ($firstRelease !== $lastRelease) {
                $markdown .= "| Most recent stable release | {$lastRelease->displayName} | {$lastRelease->firstCompatibleVersionReleaseDate->format('Y-m-d')} |\n";
            }

            $markdown .= "\n";
        }

        return $markdown;
    }

    /**
     * @param array<HasDrupalCoreCompatibleRelease> $compatibleReleases
     * @param array<DoesNotHaveDrupalCoreCompatibleRelease> $incompatibleProjects
     */
    private function generateCompatibilityTable(array $compatibleReleases, array $incompatibleProjects): string
    {
        $markdown = "## Detailed compatibility status\n\n";
        $markdown .= "| Project name | Package ID  | Type | Status | Compatible stable version | Compatible stable released on | Latest tagged release | Latest tagged released on |\n";
        $markdown .= "|--------------|------|------|--------|---------------------|--------------|----------------|--------------------|\n";

        // Add compatible releases
        foreach ($compatibleReleases as $release) {
            $markdown .= sprintf(
                "| %s | %s | %s | ✅ Compatible | %s | %s | %s | %s |\n",
                $this->escapeMarkdownTableCell($release->displayName),
                $this->escapeMarkdownTableCell($release->name),
                $this->escapeMarkdownTableCell($release->type),
                $this->escapeMarkdownTableCell($release->firstCompatibleVersion),
                $release->firstCompatibleVersionReleaseDate->format('Y-m-d'),
                $this->escapeMarkdownTableCell($release->latestTaggedVersion),
                $release->latestTaggedVersionReleaseDate->format('Y-m-d')
            );
        }

        // Add incompatible projects
        foreach ($incompatibleProjects as $project) {
            $latestVersion = $project->latestTaggedVersion ?? '-';
            $latestDate = $project->latestTaggedVersionReleaseDate?->format('Y-m-d') ?? '-';

            $markdown .= sprintf(
                "| %s | %s | %s | ❌ No stable release | - | - | %s | %s |\n",
                $this->escapeMarkdownTableCell($project->displayName),
                $this->escapeMarkdownTableCell($project->name),
                $this->escapeMarkdownTableCell($project->type),
                $this->escapeMarkdownTableCell($latestVersion),
                $latestDate
            );
        }

        return $markdown . "\n";
    }

    /**
     * @param array<HasDrupalCoreCompatibleRelease|DoesNotHaveDrupalCoreCompatibleRelease> $projects
     *
     * @return array<string, array>
     */
    private function groupByPackageType(array $projects): array
    {
        $grouped = [];

        foreach ($projects as $project) {
            $type = $project->type;

            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = $project;
        }

        return $grouped;
    }

    private function escapeMarkdownTableCell(string $text): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $text);
    }
}
