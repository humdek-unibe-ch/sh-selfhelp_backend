<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use App\Plugin\Manifest\PluginManifest;

/**
 * Scans a plugin's bundled migration directory for destructive SQL
 * statements against protected core tables.
 *
 * The scanner is purely advisory at install/update time — it writes a
 * structured report into the operation snapshot so the admin UI can
 * surface "this update contains migrations that touch protected
 * tables" warnings BEFORE the migrations actually run. The runtime
 * gate is {@see PluginMigrationGuard}, which inspects each statement
 * just before the migration executor sends it to the database.
 *
 * Detection is lexical: heredocs, single-quoted strings, and double-
 * quoted strings longer than three characters are extracted from the
 * migration file and filtered to those that contain a destructive
 * verb (`drop`, `truncate`, `alter`, `delete from`). Each candidate
 * is then handed to {@see PluginMigrationGuard::assertAllowed()} which
 * compares the targeted table against {@see ProtectedTablesPolicy}.
 *
 * False positives are possible (a literal string inside a comment, a
 * non-SQL string that happens to start with `drop`) but the scanner
 * is the soft gate — the only consequence is an informational entry
 * on the operation log. The destructive runtime gate refuses
 * statements at execution time regardless.
 *
 * Lifted out of {@see \App\Plugin\Lifecycle\PluginInstaller} +
 * {@see \App\Plugin\Lifecycle\PluginUpdater} so the install and
 * update orchestrators share one implementation.
 */
final class PluginMigrationScanner
{
    public function __construct(
        private readonly PluginMigrationGuard $migrationGuard,
    ) {
    }

    /**
     * Scan every PHP file under `<bundleDir>/Migrations/` for SQL
     * that would be refused by the migration guard. Returns a count
     * + a per-file violation list. When the manifest has no backend
     * bundle (frontend-only plugin) or the Migrations directory does
     * not exist, the returned report is empty rather than failing.
     *
     * @return array{scanned:int,files:list<array{file:string,violations:list<string>}>}
     */
    public function scan(PluginManifest $manifest): array
    {
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass === null || !class_exists($bundleClass)) {
            return ['scanned' => 0, 'files' => []];
        }
        $bundleReflection = new \ReflectionClass($bundleClass);
        $bundleDir = dirname((string) $bundleReflection->getFileName());
        $migrationsDir = $bundleDir . '/Migrations';
        if (!is_dir($migrationsDir)) {
            return ['scanned' => 0, 'files' => []];
        }

        $entries = glob($migrationsDir . '/*.php') ?: [];
        $report = ['scanned' => count($entries), 'files' => []];
        foreach ($entries as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            $violations = [];
            foreach ($this->extractStringLiterals($contents) as $sql) {
                try {
                    $this->migrationGuard->assertAllowed($sql);
                } catch (PluginMigrationGuardException $e) {
                    $violations[] = $e->getMessage();
                }
            }
            if ($violations !== []) {
                $report['files'][] = ['file' => basename($file), 'violations' => $violations];
            }
        }

        return $report;
    }

    /**
     * Pull every heredoc / single-quoted / double-quoted string of
     * length >= 4 out of the PHP source, then keep only the ones
     * that contain a destructive SQL verb. The result is the worst-
     * case candidate set for the guard.
     *
     * @return list<string>
     */
    private function extractStringLiterals(string $php): array
    {
        $matches = [];
        if (preg_match_all('/<<<["\']?(\w+)["\']?\R(.*?)\R\s*\1\s*;/s', $php, $heredocs)) {
            foreach ($heredocs[2] as $body) {
                $matches[] = $body;
            }
        }
        if (preg_match_all('/(?<!\\\\)\'([^\']{4,})\'/s', $php, $singles)) {
            foreach ($singles[1] as $body) {
                $matches[] = $body;
            }
        }
        if (preg_match_all('/(?<!\\\\)"([^"]{4,})"/s', $php, $doubles)) {
            foreach ($doubles[1] as $body) {
                $matches[] = $body;
            }
        }
        return array_values(array_filter(
            $matches,
            static fn(string $s): bool => preg_match('/\b(drop|truncate|alter|delete\s+from)\b/i', $s) === 1,
        ));
    }
}
