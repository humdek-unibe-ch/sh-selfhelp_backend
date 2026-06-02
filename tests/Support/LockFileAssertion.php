<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Plugin\Lifecycle\PluginLockFile;
use PHPUnit\Framework\Assert;

/**
 * Reusable assertions for `selfhelp.plugins.lock.json` (plan §"plugin
 * certification" 8B/8C). Lets lifecycle tests assert the public, on-disk
 * contract of the lock file — entries present/absent after install/uninstall
 * and byte/hash identity after a rollback restore — without each test
 * re-implementing JSON plumbing (canonical Testing Rule 17: assert effects).
 *
 * Accepts either a {@see PluginLockFile} DTO or its decoded array form so it
 * works against both the reader output and a raw snapshot.
 */
final class LockFileAssertion
{
    public const LOCK_FILE_NAME = 'selfhelp.plugins.lock.json';

    private function __construct()
    {
    }

    public static function lockFilePath(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;
    }

    public static function assertLockFileExists(string $projectDir): void
    {
        Assert::assertFileExists(self::lockFilePath($projectDir), 'lock file must exist after a write/finalize');
    }

    public static function assertLockFileMissing(string $projectDir): void
    {
        Assert::assertFileDoesNotExist(
            self::lockFilePath($projectDir),
            'lock file must be absent (fresh host or restore(null))'
        );
    }

    /** SHA-256 of the lock file on disk (used to certify rollback hash restore). */
    public static function lockFileSha256(string $projectDir): string
    {
        $path = self::lockFilePath($projectDir);
        Assert::assertFileExists($path, 'cannot hash a lock file that does not exist');
        $hash = hash_file('sha256', $path);
        Assert::assertIsString($hash, 'hash_file must return a digest');

        return $hash;
    }

    /**
     * Rollback contract: after restoring the pre-operation snapshot the lock
     * file is byte-identical, so its SHA-256 matches the captured value.
     */
    public static function assertLockFileHashMatches(string $projectDir, string $expectedSha256): void
    {
        Assert::assertSame(
            $expectedSha256,
            self::lockFileSha256($projectDir),
            'lock file hash must match the pre-operation snapshot after rollback restore'
        );
    }

    /**
     * @param PluginLockFile|array<string,mixed> $lock
     * @return array<string,mixed>|null
     */
    public static function findPlugin(PluginLockFile|array $lock, string $pluginId): ?array
    {
        foreach (self::plugins($lock) as $entry) {
            $id = $entry['id'] ?? null;
            if (is_scalar($id) && (string) $id === $pluginId) {
                return $entry;
            }
        }

        return null;
    }

    /** @param PluginLockFile|array<string,mixed> $lock */
    public static function assertHasPlugin(PluginLockFile|array $lock, string $pluginId): void
    {
        Assert::assertNotNull(
            self::findPlugin($lock, $pluginId),
            sprintf('lock file must record an entry for plugin "%s"', $pluginId)
        );
    }

    /** @param PluginLockFile|array<string,mixed> $lock */
    public static function assertNotHasPlugin(PluginLockFile|array $lock, string $pluginId): void
    {
        Assert::assertNull(
            self::findPlugin($lock, $pluginId),
            sprintf('lock file must NOT record an entry for plugin "%s"', $pluginId)
        );
    }

    /** @param PluginLockFile|array<string,mixed> $lock */
    public static function assertPluginField(PluginLockFile|array $lock, string $pluginId, string $field, mixed $expected): void
    {
        $entry = self::findPlugin($lock, $pluginId);
        Assert::assertNotNull($entry, sprintf('lock file must record an entry for plugin "%s"', $pluginId));
        Assert::assertArrayHasKey($field, $entry, sprintf('plugin "%s" entry must carry "%s"', $pluginId, $field));
        Assert::assertSame($expected, $entry[$field], sprintf('plugin "%s" field "%s" mismatch', $pluginId, $field));
    }

    /**
     * @param PluginLockFile|array<string,mixed> $lock
     * @return list<array<string,mixed>>
     */
    private static function plugins(PluginLockFile|array $lock): array
    {
        $plugins = $lock instanceof PluginLockFile ? $lock->plugins : ($lock['plugins'] ?? []);
        if (!is_array($plugins)) {
            return [];
        }

        $out = [];
        foreach ($plugins as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized = [];
            foreach ($entry as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $out[] = $normalized;
        }

        return $out;
    }
}
