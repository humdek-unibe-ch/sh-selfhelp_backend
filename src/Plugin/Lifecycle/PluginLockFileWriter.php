<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Plugin\Manifest\PluginManifest;

/**
 * Atomically writes `selfhelp.plugins.lock.json`.
 *
 * Atomic-rename strategy: write the new content to
 * `selfhelp.plugins.lock.json.tmp`, then `rename()` over the original
 * path. POSIX renames are atomic, so a process that reads the lock
 * file concurrently never sees a partially written document.
 *
 * The writer keeps a small backup file (`.bak`) for the previous lock
 * so the doctor command can restore in a pinch.
 */
final class PluginLockFileWriter
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    private function lockPath(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'selfhelp.plugins.lock.json';
    }

    public function write(PluginLockFile $lock): void
    {
        $path = $this->lockPath();
        $tmp = $path . '.tmp';
        $bak = $path . '.bak';

        $json = json_encode($lock->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException(sprintf('Failed to write lock tmp file at "%s".', $tmp));
        }

        if (is_file($path)) {
            // Best-effort backup. We don't fail the write if the
            // backup cannot be created (e.g. read-only on a CI runner)
            // because the new content is already on disk in the tmp
            // file.
            @copy($path, $bak);
        }

        if (is_file($path) && PHP_OS_FAMILY === 'Windows') {
            @unlink($path);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to rename lock tmp file to "%s".', $path));
        }
    }

    /**
     * Convenience: load → upsert one plugin → write.
     */
    public function upsertPlugin(Plugin $plugin, PluginManifest $manifest): void
    {
        $reader = new PluginLockFileReader($this->projectDir);
        $existing = $reader->read();
        $plugins = $existing !== null ? $existing->plugins : [];

        $found = false;
        foreach ($plugins as $index => $entry) {
            if (isset($entry['id']) && (string) $entry['id'] === $plugin->getPluginId()) {
                $plugins[$index] = $this->renderPluginEntry($plugin, $manifest);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $plugins[] = $this->renderPluginEntry($plugin, $manifest);
        }

        $this->write(new PluginLockFile(
            self::SCHEMA_VERSION,
            'plugin-installer',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $plugin->getInstallMode(),
            $plugins,
        ));
    }

    /**
     * Convenience: load → remove a plugin → write.
     */
    public function removePlugin(string $pluginId, string $installMode): void
    {
        $reader = new PluginLockFileReader($this->projectDir);
        $existing = $reader->read();
        if ($existing === null) {
            return;
        }
        $plugins = array_values(array_filter(
            $existing->plugins,
            static fn(array $entry): bool => !isset($entry['id']) || (string) $entry['id'] !== $pluginId,
        ));
        $this->write(new PluginLockFile(
            self::SCHEMA_VERSION,
            'plugin-uninstaller',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $installMode,
            $plugins,
        ));
    }

    /**
     * Restore the lock file from a previously snapshotted raw payload.
     */
    public function restore(?array $rawSnapshot): void
    {
        $path = $this->lockPath();
        if ($rawSnapshot === null) {
            @unlink($path);
            return;
        }

        $tmp = $path . '.tmp';
        $json = json_encode($rawSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException(sprintf('Failed to write lock tmp file at "%s".', $tmp));
        }
        if (is_file($path) && PHP_OS_FAMILY === 'Windows') {
            @unlink($path);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to rename lock tmp file to "%s".', $path));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function renderPluginEntry(Plugin $plugin, PluginManifest $manifest): array
    {
        return [
            'id' => $plugin->getPluginId(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
            'pluginApiVersion' => $plugin->getPluginApiVersion(),
            'trustLevel' => $plugin->getTrustLevel(),
            'installMode' => $plugin->getInstallMode(),
            'enabled' => $plugin->isEnabled(),
            'backend' => [
                'package' => $plugin->getBackendPackage(),
                'bundleClass' => $plugin->getBackendBundleClass(),
            ],
            'frontend' => [
                'runtimeUrl' => $plugin->getFrontendRuntimeUrl(),
                'stylesheetUrl' => $plugin->getFrontendRuntimeStylesheetUrl(),
                'integrity' => $plugin->getFrontendRuntimeIntegrity(),
                'format' => $plugin->getFrontendRuntimeFormat(),
            ],
            'mobile' => [
                'package' => $plugin->getMobilePackage(),
                'version' => $plugin->getMobilePackageVersion(),
            ],
            'capabilities' => $plugin->getCapabilitiesJson(),
            'checksum' => $plugin->getChecksumSha256(),
            'signing' => [
                'keyId' => $plugin->getSigningKeyId(),
                'signature' => $plugin->getSignatureEd25519(),
            ],
            'compatibility' => [
                'selfhelp' => $manifest->getCmsCompatibilityRange(),
            ],
            'migrations' => $this->collectMigrationHashes($manifest),
            'updatedAt' => $plugin->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Walks the plugin bundle's `Migrations/` folder and records the
     * SHA-256 of every migration file so the lock can be diffed against
     * a later host that should boot with the same migration set. We
     * intentionally read files lazily (only when the bundle class is
     * autoloadable) so frontend-only plugins return an empty array
     * without surprising the writer.
     *
     * @return list<array{file:string,sha256:string}>
     */
    private function collectMigrationHashes(PluginManifest $manifest): array
    {
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass === null || $bundleClass === '' || !class_exists($bundleClass)) {
            return [];
        }
        $reflection = new \ReflectionClass($bundleClass);
        $bundleFile = $reflection->getFileName();
        if (!is_string($bundleFile)) {
            return [];
        }
        $migrationsDir = dirname($bundleFile) . '/Migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $entries = glob($migrationsDir . '/*.php') ?: [];
        sort($entries);
        $out = [];
        foreach ($entries as $file) {
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                continue;
            }
            $out[] = ['file' => basename($file), 'sha256' => $hash];
        }
        return $out;
    }
}
