<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Promotes a validated `.shplugin` staging dir into:
 *
 *   - `var/plugins/<id>-<version>/installed/`        (full staging copy)
 *   - `public/plugin-artifacts/<id>-<version>/`      (web-served runtime artifacts)
 *
 * After promotion the manifest's `frontend.runtime.entrypoint` and
 * `frontend.runtime.stylesheet` are rewritten to web paths so the host
 * frontend can `import(/plugin-artifacts/<id>-<ver>/plugin.esm.js)`
 * without an additional CDN.
 *
 * The promoter is **safe by construction**: it writes the new payload
 * into a sibling `<dir>.new-<uniq>` temp directory first, then does a
 * single `rename()` to swap it in. The previous `installed/` and
 * `public/plugin-artifacts/<id>-<version>/` are renamed aside to
 * `<dir>.bak-<uniq>` and only removed once the new copy is in place.
 * If anything fails before the swap, the old artifacts are still
 * serving and admins can re-trigger promotion.
 */
final class PluginArchivePromoter
{
    public function __construct(
        private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param array<string,mixed> $manifestArray
     * @return array<string,mixed> rewritten manifest array (frontend.runtime URLs replaced with host paths)
     */
    public function promote(string $stagingDir, array $manifestArray): array
    {
        if (!is_dir($stagingDir)) {
            throw new PluginArchiveException(sprintf('Staging dir "%s" does not exist.', $stagingDir));
        }
        $pluginId = (string) ($manifestArray['id'] ?? '');
        $version = (string) ($manifestArray['version'] ?? '');
        if ($pluginId === '' || $version === '') {
            throw new PluginArchiveException('Manifest must have id + version to promote artifacts.');
        }

        $installedDir = $this->installedDir($pluginId, $version);
        $publicDir = $this->publicDir($pluginId, $version);
        $artifactsDir = $stagingDir . '/artifacts';
        if (!is_dir($artifactsDir)) {
            throw new PluginArchiveException('Staging artifacts dir is missing — promotion aborted.');
        }

        $this->filesystem->mkdir(dirname($installedDir), 0775);
        $this->filesystem->mkdir(dirname($publicDir), 0775);

        $this->atomicReplace(
            target: $installedDir,
            populate: function (string $tmpDir) use ($stagingDir): void {
                $this->filesystem->mirror($stagingDir, $tmpDir);
            },
        );

        $this->atomicReplace(
            target: $publicDir,
            populate: function (string $tmpDir) use ($artifactsDir): void {
                $this->filesystem->mirror($artifactsDir, $tmpDir);
            },
        );

        $manifestArray['frontend']['runtime']['entrypoint'] = $this->publicEntrypoint($pluginId, $version, 'plugin.esm.js');
        if (is_file($publicDir . '/plugin.css')) {
            $manifestArray['frontend']['runtime']['stylesheet'] = $this->publicEntrypoint($pluginId, $version, 'plugin.css');
        }

        return $manifestArray;
    }

    /**
     * Copy-then-rename helper. Writes new content into `<target>.new-<uniq>`,
     * moves any existing `<target>` aside to `<target>.bak-<uniq>`, then
     * renames the new dir into place. The backup is removed only after
     * the swap succeeds; if `$populate` throws, the temp dir is removed
     * and the original `<target>` is left untouched.
     */
    private function atomicReplace(string $target, \Closure $populate): void
    {
        $uniq = bin2hex(random_bytes(4));
        $newDir = $target . '.new-' . $uniq;
        $bakDir = $target . '.bak-' . $uniq;

        if ($this->filesystem->exists($newDir)) {
            $this->filesystem->remove($newDir);
        }
        $this->filesystem->mkdir($newDir, 0775);

        try {
            $populate($newDir);
        } catch (\Throwable $e) {
            $this->filesystem->remove($newDir);
            throw $e;
        }

        $hadExisting = $this->filesystem->exists($target);
        if ($hadExisting) {
            $this->filesystem->rename($target, $bakDir);
        }
        try {
            $this->filesystem->rename($newDir, $target);
        } catch (\Throwable $e) {
            if ($hadExisting && $this->filesystem->exists($bakDir)) {
                $this->filesystem->rename($bakDir, $target);
            }
            $this->filesystem->remove($newDir);
            throw $e;
        }

        if ($hadExisting && $this->filesystem->exists($bakDir)) {
            $this->filesystem->remove($bakDir);
        }
    }

    public function installedDir(string $pluginId, string $version): string
    {
        return rtrim($this->projectDir, '/\\')
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . $pluginId . '-' . $version
            . DIRECTORY_SEPARATOR . 'installed';
    }

    public function publicDir(string $pluginId, string $version): string
    {
        return rtrim($this->projectDir, '/\\')
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'plugin-artifacts'
            . DIRECTORY_SEPARATOR . $pluginId . '-' . $version;
    }

    private function publicEntrypoint(string $pluginId, string $version, string $file): string
    {
        return '/plugin-artifacts/' . rawurlencode($pluginId . '-' . $version) . '/' . $file;
    }
}
