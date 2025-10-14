<?php

declare(strict_types=1);

namespace mxr576\ddqg\Application\DrupalCoreCompatibility\Port;

use Webmozart\Assert\Assert;

/**
 * @internal This class is not part of the module's public programming API.
 */
final class ProjectInfo
{
    /**
     * Constructs a new object.
     *
     * @param ReleaseMetada[] $releases
     */
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $type,
        public readonly array $releases,
    ) {
        Assert::oneOf($type, ['theme', 'module', 'profile']);
    }

    public function getFirstStableTaggedRelease(): ?ReleaseMetada
    {
        if ([] === $this->releases) {
            return null;
        }

        $releases = $this->releases;
        $releases = array_filter($releases, function (ReleaseMetada $release): bool {
            return !$release->version->preRelease && !$release->version->buildMetadata;
        });
        if ([] === $releases) {
            return null;
        }
        usort($releases, static fn (ReleaseMetada $a, ReleaseMetada $b) => $a->releaseDate <=> $b->releaseDate);

        return reset($releases);
    }

    public function getLatestTaggedRelease(): ?ReleaseMetada
    {
        if ([] === $this->releases) {
            return null;
        }

        $releases = $this->releases;
        usort($releases, static fn (ReleaseMetada $a, ReleaseMetada $b) => $a->releaseDate <=> $b->releaseDate);

        return end($releases);
    }
}
