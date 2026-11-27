<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Keeps which plugin owned each catalogue row across an uninstall (F248).
 *
 * Uninstall deletes the `plugins` row, so the database sets `id_plugins` of
 * every catalogue row the plugin wrote to NULL (the "uninstall keeps data"
 * contract: sections keep their style and values, roles keep their
 * permissions and resource grants). A later install used to refuse those rows
 * as "belongs to core". Now:
 *
 *   1. {@see remember()} runs in the uninstaller **before** the plugin row is
 *      removed and writes the ids of the plugin's rows into the uninstall
 *      operation's `snapshots_json` (the column that holds the snapshot of
 *      plugin-owned rows).
 *   2. {@see reclaim()} runs at the start of {@see PluginCatalogueSynchronizer::sync()}
 *      and tags exactly those rows with the new plugin row, so the install
 *      finds its own rows with their old ids.
 *
 * Only rows that are still unowned are given back. A row that another plugin
 * adopted meanwhile, a row of core and a row the plugin never owned stay
 * refused by the synchronizer as before.
 */
final class PluginCatalogueOwnership
{
    /** The tables {@see PluginCatalogueSynchronizer} writes; each has `id_plugins`. */
    public const TABLES = ['style_groups', 'field_types', 'fields', 'styles', 'permissions', 'lookups'];

    /** Key in `plugin_operations.snapshots_json`: table name => list of row ids. */
    public const SNAPSHOT_KEY = 'catalogue_rows';

    public function __construct(
        private readonly Connection $connection,
        private readonly PluginOperationRecorder $recorder,
    ) {
    }

    /**
     * Write the ids of the plugin's catalogue rows into the uninstall
     * operation. Call it before the plugin row is deleted.
     */
    public function remember(PluginOperation $operation, Plugin $plugin): void
    {
        $pluginRowId = $plugin->getId();
        if ($pluginRowId === null) {
            return;
        }

        $rows = [];
        foreach (self::TABLES as $table) {
            $ids = $this->connection->fetchFirstColumn(
                sprintf('SELECT id FROM %s WHERE id_plugins = :plugin ORDER BY id', $table),
                ['plugin' => $pluginRowId]
            );
            if ($ids !== []) {
                $rows[$table] = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
            }
        }

        $this->recorder->snapshot($operation, [self::SNAPSHOT_KEY => $rows]);
    }

    /**
     * Tag the rows the plugin owned before its last uninstall with the new
     * plugin row. A no-op for a first install.
     *
     * @return int number of rows given back
     */
    public function reclaim(string $pluginId, int $pluginRowId): int
    {
        $snapshot = $this->connection->fetchOne(
            'SELECT snapshots_json FROM plugin_operations WHERE plugin_id = :plugin AND type = :type AND status = :status ORDER BY id DESC LIMIT 1',
            ['plugin' => $pluginId, 'type' => PluginOperation::TYPE_UNINSTALL, 'status' => PluginOperation::STATUS_SUCCEEDED]
        );
        if (!is_string($snapshot)) {
            return 0;
        }
        $decoded = json_decode($snapshot, true);
        $rows = is_array($decoded) ? ($decoded[self::SNAPSHOT_KEY] ?? null) : null;
        if (!is_array($rows)) {
            return 0;
        }

        $given = 0;
        foreach (self::TABLES as $table) {
            $remembered = $rows[$table] ?? null;
            $ids = is_array($remembered) ? array_values(array_filter($remembered, 'is_int')) : [];
            if ($ids === []) {
                continue;
            }
            $given += (int) $this->connection->executeStatement(
                sprintf('UPDATE %s SET id_plugins = :plugin WHERE id IN (:ids) AND id_plugins IS NULL', $table),
                ['plugin' => $pluginRowId, 'ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER]
            );
        }

        return $given;
    }
}
