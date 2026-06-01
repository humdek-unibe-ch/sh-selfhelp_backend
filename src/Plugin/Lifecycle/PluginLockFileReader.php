<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

/**
 * Reads `selfhelp.plugins.lock.json` from the project root.
 *
 * Returns `null` when the file does not exist (fresh install, no
 * plugins yet). Returns a `PluginLockFile` DTO when present. Reading
 * never mutates the file; mutations go through
 * `PluginLockFileWriter::write()`.
 */
final class PluginLockFileReader
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getLockFilePath(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'selfhelp.plugins.lock.json';
    }

    public function exists(): bool
    {
        return is_file($this->getLockFilePath());
    }

    public function read(): ?PluginLockFile
    {
        $path = $this->getLockFilePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Failed to read lock file at "%s".', $path));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Lock file at "%s" is not a JSON object.', $path));
        }
        return PluginLockFile::fromArray(self::toAssoc($data));
    }

    /**
     * Read the raw JSON data without DTO conversion. Used by operation
     * snapshots so the orchestrators can restore the lock file
     * verbatim during rollback.
     *
     * @return array<string,mixed>|null
     */
    public function readRaw(): ?array
    {
        $path = $this->getLockFilePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? self::toAssoc($data) : null;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,mixed>
     */
    private static function toAssoc(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }
}
