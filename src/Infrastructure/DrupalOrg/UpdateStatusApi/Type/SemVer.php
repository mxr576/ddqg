<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\DrupalOrg\UpdateStatusApi\Type;

/**
 * Semver according to Composer/Drupal Packagist.
 *
 * The traditional regex described on semver.org (see below) cannot be used
 * because Composer only supports a subset of pre-release tags, with additional
 * separators. Besides both Composer and Drupal Packagist allows leading zeros
 * in version number parts that get dropped when a semver string is generated.
 *
 * If this does not work we have to rely on \Composer\Semver\VersionParser::normalize().
 *
 * @see https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string
 * @see \Composer\Semver\VersionParser
 * @see https://git.drupalcode.org/project/project_composer/-/blob/62ac7f27f70fdfb2bf4e42c8eb27e7ada561db26/project_composer.module#L656-716
 *
 * @internal
 */
final class SemVer
{
    private const SEMVER_REGEX = '/^(?P<major>0?|[1-9]\d*)\.(?P<minor>0|[0-9]\d*)(?:\.(?P<patch>0|[0-9]\d*))?(?:[._-](?P<prerelease>[._-]?(?:(?:stable|beta|b|rc|RC|alpha|a|patch|pl|p)(?:(?:[.-]?\d+)*+)?)?(?:[.-]?dev)?))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * FTR, Drupal Packagist translates:
     *  * 8.x-1.01 to 1.1.0.
     *  * 8.x-2.05 to 2.5.0
     *  * 8.x-2.00-beta3 to 2.0.0-beta3
     *  * etc.
     */
    private const VERSION_STRING_WITH_CORE_COMPATIBILITY_REGEX = '/^(?P<core_compat>0|[1-9]\d*)\.x-(?P<major>0|[0-9]\d*)\.(?P<minor>0|[0-9]\d*)(?:[._-](?P<prerelease>[._-]?(?:(?:stable|beta|b|rc|RC|alpha|a|patch|pl|p)(?:(?:[.-]?\d+)*+)?)?(?:[.-]?dev)?))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    public readonly int $major;

    public readonly int $minor;

    public readonly int $patch;

    public readonly ?string $preRelease;

    public readonly ?string $buildMetadata;

    public readonly string $asString;

    public function __construct(int $major, int $minor, int $patch, ?string $preRelease, ?string $buildMetadata)
    {
        $this->major = $major;
        $this->minor = $minor;
        $this->patch = $patch;
        $this->preRelease = $preRelease;
        $this->buildMetadata = $buildMetadata;

        $semver = self::buildSemverString($major, $minor, $patch, $preRelease, $buildMetadata);

        $this->asString = $semver;
    }

    public static function fromSemver(string $value): self
    {
        $matches = [];
        if (!preg_match(self::SEMVER_REGEX, $value, $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid semver string.', $value));
        }

        // By casting parts to int we are also getting rid of
        // potential leading zeros. See examples on the regex constant.
        return new self((int) $matches['major'], (int) $matches['minor'], (int) ($matches['patch'] ?? 0), $matches['prerelease'] ?? null, $matches['buildmetadata'] ?? null);
    }

    public static function fromVersionWithDrupalCoreCompatibility(string $value): self
    {
        $matches = [];
        if (preg_match(self::VERSION_STRING_WITH_CORE_COMPATIBILITY_REGEX, $value, $matches)) {
            // By casting parts to int we are also getting rid of
            // potential leading zeros. See examples on the regex constant.
            return self::fromSemver(self::buildSemverString((int) $matches['major'], (int) $matches['minor'], 0, $matches['prerelease'] ?? null, $matches['buildmetadata'] ?? null));
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a valid version string with Drupal core compatibility prefix.', $value));
    }

    public static function tryFromPackageVersionString(string $value): ?self
    {
        try {
            return self::fromSemver($value);
        } catch (\InvalidArgumentException) {
            try {
                return self::fromVersionWithDrupalCoreCompatibility($value);
            } catch (\InvalidArgumentException) {
            }
        }

        return null;
    }

    public static function fromSupportedBranchesString(string $value): self
    {
        // KISS.
        // https://www.drupal.org/drupalorg/docs/apis/update-status-xml#s-top-level-project-element
        if (str_contains($value, '.x')) {
            return self::fromVersionWithDrupalCoreCompatibility($value . '0');
        }

        return self::fromSemver($value . '0');
    }

    private static function buildSemverString(int $major, int $minor, int $patch, ?string $preRelease, ?string $buildMetadata): string
    {
        $semver = implode('.', [$major, $minor, $patch]);
        if (null !== $preRelease) {
            $semver .= '-' . $preRelease;
        }
        if (null !== $buildMetadata) {
            $semver .= '+' . $buildMetadata;
        }

        return $semver;
    }
}
