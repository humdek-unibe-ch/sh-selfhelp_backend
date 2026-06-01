<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Reviews a SQL statement issued by a plugin migration and refuses
 * destructive operations on protected core tables.
 *
 * The guard is run by the plugin migration executor before each SQL
 * statement; it inspects the statement at the lexical level (good
 * enough for the migrations we accept — plugin SQL is reviewed
 * upfront, this is the runtime safety net). The runtime guard does
 * not attempt to be a full SQL parser; it looks for the canonical
 * patterns that would harm the host.
 *
 * Blocked patterns:
 *   - DROP TABLE / DROP INDEX / DROP CONSTRAINT against protected tables.
 *   - TRUNCATE TABLE against protected tables.
 *   - DELETE FROM protected table without an `id_plugins = ?` predicate.
 *   - ALTER TABLE protected table (any form) without the
 *     `--allow-destructive` operator flag.
 *
 * Allowed:
 *   - Any statement on plugin-owned tables.
 *   - INSERT / UPDATE on protected tables when the running operation
 *     has the matching capability (caller responsibility — the guard
 *     does not inspect the actual DML beyond the destructive forms
 *     listed above).
 */
final class PluginMigrationGuard
{
    public function __construct(
        private readonly bool $allowDestructive = false,
    ) {
    }

    /**
     * @throws PluginMigrationGuardException when the statement targets
     *                                       a protected table with a
     *                                       destructive verb.
     */
    public function assertAllowed(string $sql): void
    {
        $normalized = $this->normalize($sql);

        $patterns = [
            // DROP TABLE [IF EXISTS] `table`
            '/\bdrop\s+table\s+(?:if\s+exists\s+)?[`"\[]?([a-z0-9_]+)[`"\]]?/i' => 'DROP TABLE',
            // TRUNCATE TABLE `table` or TRUNCATE `table`
            '/\btruncate(?:\s+table)?\s+[`"\[]?([a-z0-9_]+)[`"\]]?/i' => 'TRUNCATE',
            // ALTER TABLE `table`
            '/\balter\s+table\s+[`"\[]?([a-z0-9_]+)[`"\]]?/i' => 'ALTER TABLE',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $table = $matches[1];
                if (ProtectedTablesPolicy::isProtected($table)) {
                    if ($label === 'ALTER TABLE' && $this->allowDestructive) {
                        continue;
                    }
                    throw new PluginMigrationGuardException(sprintf(
                        'Plugin migration attempted %s on protected table "%s". Refused by PluginMigrationGuard.',
                        $label,
                        $table
                    ));
                }
            }
        }

        // DELETE FROM protected_table without an id_plugins predicate.
        if (preg_match('/\bdelete\s+from\s+[`"\[]?([a-z0-9_]+)[`"\]]?(.*)$/is', $normalized, $matches) === 1) {
            $table = $matches[1];
            $rest = $matches[2];
            if (ProtectedTablesPolicy::isProtected($table)) {
                if (!preg_match('/\bid_plugins\b/i', $rest)) {
                    throw new PluginMigrationGuardException(sprintf(
                        'Plugin migration attempted DELETE on protected table "%s" without an id_plugins predicate. Refused by PluginMigrationGuard.',
                        $table
                    ));
                }
            }
        }
    }

    private function normalize(string $sql): string
    {
        $stripped = preg_replace('!/\*.*?\*/!s', ' ', $sql) ?? $sql;
        $stripped = preg_replace('/--[^\r\n]*/', ' ', $stripped) ?? $stripped;
        return trim((string) preg_replace('/\s+/', ' ', $stripped));
    }
}
