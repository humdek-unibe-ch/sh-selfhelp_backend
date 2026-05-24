<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Security\ProtectedTablesPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for `ProtectedTablesPolicy::TABLES`.
 *
 * Plugins are blocked from writing to or DROP/TRUNCATE/DELETE-ing
 * the tables listed in the policy. If a table is misspelled relative
 * to the canonical baseline migration, the guard silently lets
 * plugins through whenever they happen to use the real table name.
 *
 * This test scans the canonical baseline migration (the single source
 * of truth for the install schema, per `AGENTS.md`) and asserts each
 * protected table appears in it. The check uses backtick-quoted
 * `createTable(...)` and `CREATE TABLE` markers — Doctrine uses both
 * forms in the baseline and the stored-procedure helpers.
 */
final class ProtectedTablesPolicyTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../../migrations';

    public function testEveryProtectedTableExistsInMigrations(): void
    {
        $allMigrationSource = $this->concatAllMigrations();
        self::assertNotSame('', $allMigrationSource, 'No migration files were read.');

        $orphans = [];
        foreach (ProtectedTablesPolicy::TABLES as $table) {
            // Doctrine generates `CREATE TABLE foo` and plugin-layer
            // migrations also use `createTable('foo'` via the Schema
            // helper; check both forms.
            $patterns = [
                sprintf("createTable('%s'", $table),
                sprintf('createTable("%s"', $table),
                sprintf('CREATE TABLE `%s`', $table),
                sprintf('CREATE TABLE %s', $table),
                // Plugin migrations often use sprintf-built names
                // sandwiched between backticks inside SQL strings,
                // e.g. `CREATE TABLE IF NOT EXISTS \`%s\``. Match the
                // unadorned name as a final fallback to avoid false
                // negatives when the SQL is composed dynamically.
                sprintf('`%s`', $table),
                sprintf("'%s'", $table),
            ];
            $found = false;
            foreach ($patterns as $needle) {
                if (str_contains($allMigrationSource, $needle)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $orphans[] = $table;
            }
        }

        self::assertSame(
            [],
            $orphans,
            sprintf(
                "ProtectedTablesPolicy::TABLES contains tables that do NOT appear in any migration:\n  - %s\n" .
                'Either remove the entry, fix the spelling, or add the table via a migration.',
                implode("\n  - ", $orphans),
            ),
        );
    }

    private function concatAllMigrations(): string
    {
        $contents = '';
        foreach (glob(self::MIGRATIONS_DIR . '/*.php') ?: [] as $migrationPath) {
            $contents .= "\n" . (string) file_get_contents($migrationPath);
        }
        return $contents;
    }

    public function testProtectedListHasNoDuplicates(): void
    {
        $tables = ProtectedTablesPolicy::TABLES;
        $unique = array_unique($tables);
        self::assertSame(
            count($tables),
            count($unique),
            'ProtectedTablesPolicy::TABLES contains duplicate entries: ' .
            implode(', ', array_diff_assoc($tables, $unique)),
        );
    }

    public function testIsProtectedMatchesQuotedAndUnquotedNames(): void
    {
        self::assertTrue(ProtectedTablesPolicy::isProtected('users'));
        self::assertTrue(ProtectedTablesPolicy::isProtected('`users`'));
        self::assertTrue(ProtectedTablesPolicy::isProtected('"users"'));
        self::assertTrue(ProtectedTablesPolicy::isProtected('USERS'));
        self::assertFalse(ProtectedTablesPolicy::isProtected('some_plugin_table'));
    }
}
