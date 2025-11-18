<?php

declare(strict_types=1);

namespace mxr576\ddqg\Presentation\DrupalCoreCompatibilityReportGenerator;

use mxr576\ddqg\Application\DrupalCoreCompatibility\Dto\DrupalCoreCompatibilityReport;

/**
 * Generates a Markdown formatted report from a DrupalCoreCompatibilityReport.
 *
 * This class is responsible only for serializing the pre-calculated report data
 * into Markdown format. It performs no calculations or data transformations.
 *
 * @internal This class is not part of the module's public programming API.
 */
final class MarkdownDrupalCompatibilityReportGenerator
{
    /**
     * Generates a Markdown formatted compatibility report.
     *
     * @param DrupalCoreCompatibilityReport $report The pre-calculated report data.
     *
     * @return string The Markdown formatted report.
     */
    public function generateMarkdownReport(DrupalCoreCompatibilityReport $report): string
    {
        $output = '';

        $output .= $this->renderHeader($report);
        $output .= $this->renderSummary($report);
        $output .= $this->renderDetailedStatus($report);

        return $output;
    }

    /**
     * Renders the report header.
     */
    private function renderHeader(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "# Drupal contrib compatibility report for core {$report->drupalCoreVersion}\n\n";
        $markdown .= '**Generated:** ' . date('Y-m-d H:i:s') . "\n\n";
        $markdown .= "This report shows when contributed projects got their first stable release compatible with Drupal core {$report->drupalCoreVersion}.\n\n";

        return $markdown;
    }

    /**
     * Renders the summary section.
     */
    private function renderSummary(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "## Summary\n\n";
        $markdown .= $this->renderOverallCompatibilityTable($report);
        $markdown .= $this->renderCompatibilityByTypeTable($report);
        $markdown .= $this->renderReleaseTimelineTable($report);

        return $markdown;
    }

    /**
     * Renders the overall compatibility table.
     */
    private function renderOverallCompatibilityTable(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "### Overall compatibility\n\n";
        $markdown .= "| Metric | Value |\n";
        $markdown .= "|--------|-------|\n";
        $markdown .= sprintf("| Total projects analyzed | %d |\n", $report->getTotalProjects());
        $markdown .= sprintf("| Projects with stable releases | %d |\n", $report->totalCompatibleCount);
        $markdown .= sprintf("| Projects without stable releases | %d |\n", $report->totalIncompatibleCount);
        $markdown .= sprintf("| Overall compatibility rate | %.1f%% |\n\n", $report->getOverallCompatibilityRate());

        return $markdown;
    }

    private function renderCompatibilityByTypeTable(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "### Compatibility by package type\n\n";
        $markdown .= "| Package type | Compatible | Incompatible | Total | Compatibility rate |\n";
        $markdown .= "|--------------|------------|--------------|-------|-------------------|\n";

        foreach ($report->compatibilityByType as $type => $stats) {
            $markdown .= sprintf(
                "| %s | %d | %d | %d | %.1f%% |\n",
                $type,
                $stats['compatible'],
                $stats['incompatible'],
                $stats['total'],
                $stats['rate']
            );
        }

        $markdown .= "\n";

        return $markdown;
    }

    /**
     * Renders the release timeline table.
     */
    private function renderReleaseTimelineTable(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "### Release timeline\n\n";
        $markdown .= "| Metric | Project | Date |\n";
        $markdown .= "|--------|---------|------|\n";

        if (null !== $report->firstCompatibleProject) {
            $markdown .= sprintf(
                "| First project that got stable release | %s | %s |\n",
                $report->firstCompatibleProject['name'],
                $report->firstCompatibleProject['date']->format('Y-m-d')
            );
        }

        if (null !== $report->lastCompatibleProject) {
            $markdown .= sprintf(
                "| Most recent stable release | %s | %s |\n",
                $report->lastCompatibleProject['name'],
                $report->lastCompatibleProject['date']->format('Y-m-d')
            );
        }

        $markdown .= "\n";

        return $markdown;
    }

    /**
     * Renders the detailed status table.
     */
    private function renderDetailedStatus(DrupalCoreCompatibilityReport $report): string
    {
        $markdown = "## Detailed compatibility status\n\n";
        $markdown .= "| Project name | Package ID  | Type | Status | First compatible stable version | First compatible stable released on | Latest tagged release | Latest tagged released on |\n";
        $markdown .= "|--------------|------|------|--------|---------------------|--------------|----------------|--------------------|\n";

        foreach ($report->allProjectsSorted as $project) {
            $markdown .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $project['name'],
                $project['id'],
                $project['type'],
                'compatible' === $project['status'] ? '✅ Has compatible stable release' : '❌ No compatible stable release',
                $project['compatibleVersion'] ?? 'N/A',
                null !== $project['compatibleDate'] ? $project['compatibleDate']->format('Y-m-d') : 'N/A',
                $project['latestVersion'] ?? 'N/A',
                null !== $project['latestDate'] ? $project['latestDate']->format('Y-m-d') : 'N/A'
            );
        }

        return $markdown;
    }
}
