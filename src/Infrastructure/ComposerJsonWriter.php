<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure;

/**
 * @internal
 */
final class ComposerJsonWriter
{
    /**
     * @throws \RuntimeException
     *   When the file could not be saved.
     */
    public function __invoke(string $file_path, array $composer_data): void
    {
        $composer = [
          'name' => 'mxr576/ddqg',
          'description' => 'Ensures that your project does not have installed dependencies with known quality problems.',
          'type' => 'metapackage',
          'license' => 'MIT',
          'conflict' => [],
        ];
        $composer = array_merge($composer, $composer_data);

        foreach ($composer['conflict'] as $package => $constraints) {
            natsort($constraints);
            $composer['conflict'][$package] = implode('|', $constraints);
        }

        // drupal/core is a subtree split for drupal/drupal and has no own SAs.
        // @see https://github.com/drush-ops/drush/issues/3448
        if (isset($composer['conflict']['drupal/drupal']) && !isset($composer['conflict']['drupal/core'])) {
            $composer['conflict']['drupal/core'] = $composer['conflict']['drupal/drupal'];
        }

        ksort($composer['conflict']);

        if (!file_put_contents($file_path, json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n")) {
            throw new \RuntimeException(sprintf('Failed to write file at "%s".', $file_path));
        }
    }
}
