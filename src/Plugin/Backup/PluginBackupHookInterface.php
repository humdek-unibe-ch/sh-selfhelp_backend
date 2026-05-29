<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Backup;

/**
 * Pluggable backup hook called by destructive plugin operations
 * (update, purge) before any data is written.
 *
 * The default implementation (`NoopPluginBackupHook`) prints a
 * recommendation in the operation log and emits a warning so admins
 * see a clear "no backup taken" banner. Sites that want automated
 * backups register their own implementation tagged
 * `selfhelp.plugin.backup_hook`.
 */
interface PluginBackupHookInterface
{
    /**
     * @param list<string> $affectedTables Plugin-owned + plugin-tagged tables.
     * @return array{
     *   performed: bool,
     *   reference: string|null,
     *   recommendation: string|null,
     * }
     */
    public function beforeDestructive(string $pluginId, string $operationType, array $affectedTables): array;
}
