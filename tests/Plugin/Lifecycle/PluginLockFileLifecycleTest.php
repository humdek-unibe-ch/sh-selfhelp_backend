<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Lifecycle;

use App\Plugin\Lifecycle\PluginLockFile;
use App\Plugin\Lifecycle\PluginLockFileReader;
use App\Plugin\Lifecycle\PluginLockFileWriter;
use App\Tests\Support\LockFileAssertion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Certifies the `selfhelp.plugins.lock.json` lifecycle primitives the plugin
 * orchestrators rely on (plan §"plugin certification" 8B/8C), end to end on a
 * real (temp) project dir through the REAL {@see PluginLockFileWriter} +
 * {@see PluginLockFileReader} — no DB, no kernel, no composer/npm, no clock.
 *
 * These are the file-layer effects that the HTTP/managed-mode certification
 * ({@see \App\Tests\Certification\InstallLifecycleCertificationTestCase})
 * deliberately does NOT exercise, because finalize writes to disk and is a
 * deployment step. Here we drive the writer directly against a throwaway dir so
 * the disk writes are safe and observable:
 *
 *   - install        → lock records the plugin entry (id/version/checksum),
 *   - uninstall       → removePlugin reverses ONLY that entry,
 *   - rollback        → restore(snapshot) brings the lock back byte-identical
 *                       (same SHA-256), and restore(null) removes it.
 *
 * What stays a documented deploy-time exception (see docs/plugins/testing-matrix.md):
 * the DB-orchestrated {@see \App\Plugin\Lifecycle\PluginRollbacker} that reads a
 * `failed` plugin_operations row's snapshot and calls this same `restore()` — it
 * needs real operation rows + non-transactional disk writes, so the CLI worker
 * covers it, not this suite.
 */
final class PluginLockFileLifecycleTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;
    private PluginLockFileWriter $writer;
    private PluginLockFileReader $reader;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/sh-lockfile-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($this->projectDir);
        $this->writer = new PluginLockFileWriter($this->projectDir);
        $this->reader = new PluginLockFileReader($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);
    }

    public function testWriteRecordsInstalledPluginEntry(): void
    {
        LockFileAssertion::assertLockFileMissing($this->projectDir);

        $this->writer->write($this->lockWith([
            $this->entry('qa_alpha', '1.2.0', 'sha256-alpha'),
        ]));

        LockFileAssertion::assertLockFileExists($this->projectDir);

        $lock = $this->reader->read();
        self::assertInstanceOf(PluginLockFile::class, $lock);
        self::assertSame(PluginLockFileWriter::SCHEMA_VERSION, $lock->schemaVersion);
        LockFileAssertion::assertHasPlugin($lock, 'qa_alpha');
        LockFileAssertion::assertPluginField($lock, 'qa_alpha', 'version', '1.2.0');
        LockFileAssertion::assertPluginField($lock, 'qa_alpha', 'checksum', 'sha256-alpha');
    }

    public function testRemovePluginReversesInstallStateForThatPluginOnly(): void
    {
        $this->writer->write($this->lockWith([
            $this->entry('qa_alpha', '1.2.0', 'sha256-alpha'),
            $this->entry('qa_beta', '0.9.1', 'sha256-beta'),
        ]));

        // Uninstall alpha: only alpha is reversed, beta survives.
        $this->writer->removePlugin('qa_alpha', 'managed');
        $afterAlpha = $this->reader->read();
        self::assertInstanceOf(PluginLockFile::class, $afterAlpha);
        LockFileAssertion::assertNotHasPlugin($afterAlpha, 'qa_alpha');
        LockFileAssertion::assertHasPlugin($afterAlpha, 'qa_beta');

        // Uninstall beta: lock file remains but records no plugins.
        $this->writer->removePlugin('qa_beta', 'managed');
        $afterBeta = $this->reader->read();
        self::assertInstanceOf(PluginLockFile::class, $afterBeta);
        LockFileAssertion::assertNotHasPlugin($afterBeta, 'qa_beta');
        self::assertSame([], $afterBeta->plugins, 'an emptied lock file must carry an empty plugins list');
    }

    public function testRestoreRollsLockFileBackToSnapshotHash(): void
    {
        // Last-known-good state captured as the operation snapshot.
        $this->writer->write($this->lockWith([
            $this->entry('qa_alpha', '1.2.0', 'sha256-alpha'),
        ]));
        $snapshotBefore = $this->reader->readRaw();
        self::assertIsArray($snapshotBefore, 'snapshot must capture the pre-operation lock');
        $hashBefore = LockFileAssertion::lockFileSha256($this->projectDir);

        // A failed operation mutates the lock (e.g. removed the plugin).
        $this->writer->removePlugin('qa_alpha', 'managed');
        LockFileAssertion::assertNotHasPlugin($this->reader->read() ?? [], 'qa_alpha');
        self::assertNotSame($hashBefore, LockFileAssertion::lockFileSha256($this->projectDir));

        // Rollback restores the snapshot verbatim — same content AND same hash.
        $this->writer->restore($snapshotBefore);
        $restored = $this->reader->read();
        self::assertInstanceOf(PluginLockFile::class, $restored);
        LockFileAssertion::assertHasPlugin($restored, 'qa_alpha');
        LockFileAssertion::assertLockFileHashMatches($this->projectDir, $hashBefore);
    }

    public function testRestoreNullRemovesLockFileForRollbackToFreshState(): void
    {
        $this->writer->write($this->lockWith([
            $this->entry('qa_alpha', '1.2.0', 'sha256-alpha'),
        ]));
        LockFileAssertion::assertLockFileExists($this->projectDir);

        // Rolling back an install whose "before" state had no lock file removes it.
        $this->writer->restore(null);
        LockFileAssertion::assertLockFileMissing($this->projectDir);
    }

    /**
     * @param list<array<string,mixed>> $plugins
     */
    private function lockWith(array $plugins): PluginLockFile
    {
        return new PluginLockFile(
            PluginLockFileWriter::SCHEMA_VERSION,
            'plugin-installer',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'managed',
            $plugins,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(string $id, string $version, string $checksum): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'version' => $version,
            'trustLevel' => 'untrusted',
            'installMode' => 'managed',
            'enabled' => true,
            'checksum' => $checksum,
            'migrations' => [],
        ];
    }
}
