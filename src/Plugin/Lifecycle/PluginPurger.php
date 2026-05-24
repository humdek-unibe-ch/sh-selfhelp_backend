<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Exception\ServiceException;
use App\Plugin\Archive\PluginArchivePromoter;
use App\Plugin\Backup\PluginBackupHookInterface;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Lifecycle\PluginPurgedEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Security\ProtectedTablesPolicy;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only destructive plugin operation.
 *
 * Purge removes:
 *   - plugin-owned tables declared in `dataAccess.ownedTables`,
 *   - rows in shared core tables tagged with `id_plugins`
 *     (`styles`, `api_routes`, `fields`, `permissions`, `lookups`),
 *   - rows in `data_tables` tagged with `id_plugins` — plus every
 *     `data_cols` / `data_rows` / `data_cells` / `actions` /
 *     `scheduled_jobs` row that referenced them (via the existing
 *     `ON DELETE CASCADE` FKs), so the "data tables stay orphaned"
 *     regression is gone,
 *   - rows in `doctrine_migration_versions` whose `version` column
 *     starts with the plugin's `backend.migrationsNamespace` —
 *     without this the next install would skip every migration as
 *     "already applied" and never re-create the plugin's schema or
 *     CMS surface registrations (see
 *     {@see deletePluginMigrationVersions()}),
 *   - the plugin's row from `plugins`,
 *   - its operation history is preserved for audit.
 *
 * Owned tables are dropped after every foreign key declared on them is
 * removed (see {@see dropOwnedTableForeignKeys()}). This is what makes
 * the per-table `DROP TABLE IF EXISTS` loop FK-safe regardless of the
 * order the plugin manifest happens to list its tables in, including
 * schemas with circular FKs between two owned tables.
 *
 * The purger never touches protected tables (`ProtectedTablesPolicy`)
 * — even if the manifest claims to own one (manifests are validated
 * against the protected list at install time, but this is the runtime
 * safety net).
 *
 * Double confirmation is enforced by the controller and the CLI; the
 * orchestrator itself requires a `confirmedPluginId` argument that
 * must match the plugin id exactly.
 */
final class PluginPurger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly PluginBackupHookInterface $backupHook,
        private readonly InstallModeResolver $installModeResolver,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly PluginCacheInvalidator $cacheInvalidator,
    ) {
    }

    public function purge(string $pluginId, string $confirmedPluginId, bool $backupBefore = false): void
    {
        if ($pluginId !== $confirmedPluginId) {
            throw new ServiceException(
                'Purge confirmation does not match the plugin id. Aborting.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->lock->assertCanStart($pluginId);

        try {
            $plugin = $this->plugins->findOneByPluginId($pluginId);
            if (!$plugin instanceof Plugin) {
                throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
            }

            $manifestData = $plugin->getManifestJson();
            $ownedTables = $this->collectOwnedTables($manifestData);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_PURGE,
                $installMode,
                null,
                $plugin->getVersion(),
            );

            $backup = $this->backupHook->beforeDestructive($pluginId, PluginOperation::TYPE_PURGE, $ownedTables);
            $this->recorder->snapshot($operation, [
                'ownedTables' => $ownedTables,
                'manifestAtPurge' => $manifestData,
                'backup' => $backup,
                'backupRequested' => $backupBefore,
            ]);
            $this->recorder->markRunning($operation, 'Purging plugin');

            // MySQL implicitly COMMITs the active transaction the moment a
            // DDL statement runs (DROP TABLE, ALTER TABLE, ...). Wrapping
            // the purge in `em->beginTransaction()` therefore breaks every
            // subsequent recorder->appendLog() / em->rollback() call — the
            // ORM thinks it is still in transaction `nesting=1`, but the
            // connection has nothing to commit/rollback any more. The
            // original visible symptom was "There is no active transaction"
            // from the rollback path, which hid whatever caused the catch
            // in the first place and left the operation row stuck in
            // `running`. Run each step as its own autocommitted statement
            // instead — DDL has no rollback guarantee anyway.
            try {
                // Plugin-tagged rows on shared tables are removed via the
                // FK ON DELETE SET NULL on `id_plugins` when the plugin row
                // is removed. We additionally hard-delete rows whose
                // existence only makes sense for this plugin (styles /
                // api_routes / fields / permissions / lookups created by
                // the plugin and tagged with id_plugins). Doing this BEFORE
                // any DDL keeps the data deletes inside one happy autocommit
                // window.
                $this->deletePluginTaggedRows($plugin->getId());

                // Drop every foreign key declared on an owned table BEFORE
                // dropping the tables themselves. Without this step, a
                // plain `DROP TABLE` loop in manifest order fails as soon
                // as the manifest lists a parent before its child, and is
                // outright impossible when the plugin schema has a circular
                // FK (e.g. the SurveyJS plugin's
                // `surveys.id_current_survey_versions` <->
                // `survey_versions.id_surveys`). Dropping FKs first lets
                // the existing per-table `DROP TABLE IF EXISTS` loop
                // succeed in any order.
                $this->dropOwnedTableForeignKeys($ownedTables, $operation);

                foreach ($ownedTables as $table) {
                    $this->dropOwnedTable($table);
                    $this->recorder->appendLog($operation, 'Dropped plugin-owned table', ['table' => $table]);
                }

                // Forget every applied migration that belongs to this
                // plugin's `backend.migrationsNamespace`. Without this
                // step the rows persist in `doctrine_migration_versions`
                // even though we just dropped every table they created
                // and deleted every row they seeded — so the very next
                // install would log
                // `skippedReason: all-migrations-already-applied` from
                // PluginMigrationsRunner::migrate() and the CMS surface
                // (styles, fields, permissions, lookups) would never be
                // re-registered. The plugin would appear installed but
                // be completely inert.
                $manifest = new PluginManifest($manifestData);
                $this->deletePluginMigrationVersions(
                    $manifest->getBackendMigrationsNamespace(),
                    $operation
                );

                $this->em->remove($plugin);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->recorder->fail($operation, $e, 'purge');
                throw $e;
            }

            // Purge is destructive: every plugin-owned table is gone,
            // every `id_plugins`-tagged row across styles / permissions /
            // lookups / api_routes is gone. Clear every cached list that
            // could still reference them so the admin shell, the page
            // editor, and the permission resolver stop returning stale
            // entries without an operator having to flush Redis.
            $this->cacheInvalidator->invalidatePluginSurfaceCaches();
            $this->bundlesWriter->regenerate();
            $this->lockFileWriter->removePlugin($pluginId, $installMode);

            // Purge is destructive by definition — wipe the promoted
            // artefacts in `public/plugin-artifacts/<id>-<ver>/` and the
            // staged copy in `var/plugins/<id>-<ver>/`. Best-effort: any
            // IO error is recorded but does not abort the purge (the
            // plugins row + plugin-tagged data are already gone).
            $cleanupErrors = $this->archivePromoter->cleanupArtifacts($pluginId, $plugin->getVersion());
            $this->recorder->appendLog($operation, 'cleanup-artifacts', [
                'pluginId' => $pluginId,
                'version' => $plugin->getVersion(),
                'errors' => $cleanupErrors,
            ], 95);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                null,
                false,
                sprintf('Plugin purged: %s (irreversible)', $pluginId),
            );

            $this->recorder->succeed($operation, 'Plugin purged', null, $plugin->getVersion());
            $this->events->dispatch(new PluginPurgedEvent($plugin, $operation));
        } finally {
            $this->lock->release($pluginId);
        }
    }

    /**
     * @return list<string>
     */
    private function collectOwnedTables(array $manifestData): array
    {
        $owned = $manifestData['dataAccess']['ownedTables'] ?? [];
        if (!is_array($owned)) {
            return [];
        }
        $valid = [];
        foreach ($owned as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (ProtectedTablesPolicy::isProtected($name)) {
                throw new ServiceException(sprintf(
                    'Refusing to purge protected table "%s" declared by plugin. Manifest validation must reject this at install time.',
                    $name
                ), Response::HTTP_CONFLICT);
            }
            if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                throw new ServiceException(sprintf(
                    'Plugin-owned table name "%s" is not safe to use in DDL.',
                    $name
                ), Response::HTTP_CONFLICT);
            }
            $valid[] = $name;
        }
        return $valid;
    }

    private function dropOwnedTable(string $table): void
    {
        $sql = sprintf('DROP TABLE IF EXISTS `%s`', $table);
        $this->connection->executeStatement($sql);
    }

    /**
     * Drop every foreign key constraint declared on a plugin-owned table.
     *
     * The loop in `purge()` calls `DROP TABLE IF EXISTS` per owned table
     * in manifest order. MySQL refuses a `DROP TABLE` while another table
     * still references it via a FK, so any plugin whose owned tables
     * reference each other (or, worse, hold a circular FK like the
     * SurveyJS plugin's `surveys` <-> `survey_versions`) would fail with
     * "Cannot drop table X referenced by a foreign key constraint Y on
     * table Z". We discover those constraints via
     * `INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS` and drop them up
     * front, so subsequent `DROP TABLE` calls succeed regardless of
     * order.
     *
     * Only FKs whose CHILD side (the one carrying the column) is an
     * owned table are dropped. FKs from non-owned tables into owned
     * tables are NEVER touched — by host contract no shared/core table
     * holds a FK into a plugin table, so if we ever encounter one the
     * subsequent `DROP TABLE` will fail with a clear MySQL error and
     * surface the contract violation instead of being silently masked.
     *
     * @param list<string> $ownedTables
     */
    private function dropOwnedTableForeignKeys(array $ownedTables, PluginOperation $operation): void
    {
        if ($ownedTables === []) {
            return;
        }

        // Table names are validated by collectOwnedTables() against
        // ^[a-z0-9_]+$, so they are safe to splice into the SQL literal
        // here. We do this rather than binding because INFORMATION_SCHEMA
        // queries with bound IN() lists need ArrayParameterType juggling
        // for what is otherwise a trivial whitelist.
        $tableList = "'" . implode("','", $ownedTables) . "'";
        $sql = sprintf(
            'SELECT TABLE_NAME AS child_table, CONSTRAINT_NAME AS constraint_name '
            . 'FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME IN (%s)',
            $tableList
        );

        /** @var list<array{child_table: string, constraint_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        foreach ($rows as $row) {
            $childTable = (string) $row['child_table'];
            $constraintName = (string) $row['constraint_name'];

            // Defensive: the WHERE clause already filters by owned tables,
            // but re-checking keeps the safety invariant local.
            if (!in_array($childTable, $ownedTables, true)) {
                continue;
            }

            $this->connection->executeStatement(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                $childTable,
                $constraintName
            ));
            $this->recorder->appendLog($operation, 'Dropped plugin-owned foreign key', [
                'table' => $childTable,
                'constraint' => $constraintName,
            ]);
        }
    }

    /**
     * Forget every applied migration that belongs to this plugin's
     * `backend.migrationsNamespace`.
     *
     * Plugin migrations land in the SHARED `doctrine_migration_versions`
     * table (see {@see PluginMigrationsRunner}'s class docblock). The
     * `version` column stores the migration FQCN, so every row for a
     * given plugin shares a common namespace prefix —
     * e.g. `Humdek\SurveyJsBundle\Migrations\Version20260522063620`
     * for the SurveyJS plugin's
     * `backend.migrationsNamespace = Humdek\SurveyJsBundle\Migrations`.
     *
     * If we leave those rows behind after the purge, the next install
     * of the same plugin id will hit
     * `Doctrine\Migrations\Exception\NoMigrationsToExecute` in
     * {@see PluginMigrationsRunner::migrate()}, return
     * `skipped: true, skippedReason: 'all-migrations-already-applied'`,
     * and never re-create the plugin's tables / re-seed its CMS
     * surface registrations. The plugin row would be present in
     * `plugins` but the entire schema + styles/fields/permissions/
     * lookups would be missing — the exact "install ran but nothing
     * shows up" symptom reported in the field.
     *
     * Implementation notes:
     *   - The match is anchored at the start of the version string via
     *     `LOCATE(prefix, version) = 1`, which sidesteps MySQL `LIKE`'s
     *     backslash escape rules entirely (Doctrine FQCNs are full of
     *     `\` separators).
     *   - The trailing `\` is appended to the namespace so a plugin
     *     namespaced `Humdek\Foo` never matches another plugin
     *     namespaced `Humdek\FooBar`.
     *   - No-op when the manifest declares no
     *     `backend.migrationsNamespace` — plugins without backend
     *     migrations have nothing to forget.
     */
    private function deletePluginMigrationVersions(?string $namespace, PluginOperation $operation): void
    {
        if ($namespace === null || $namespace === '') {
            return;
        }

        $prefix = rtrim($namespace, '\\') . '\\';

        $affected = $this->connection->executeStatement(
            'DELETE FROM `doctrine_migration_versions` WHERE LOCATE(:prefix, `version`) = 1',
            ['prefix' => $prefix]
        );

        $this->recorder->appendLog($operation, 'Cleared doctrine_migration_versions', [
            'namespacePrefix' => $prefix,
            'rowsDeleted' => (int) $affected,
        ]);
    }

    /**
     * Hard-delete every row across shared core tables that is tagged
     * with this plugin id (via the nullable `id_plugins` FK added in
     * `Version20260522062453.php`).
     *
     * `data_tables` rows are deleted explicitly. The matching
     * `data_cols`, `data_rows`, and `data_cells` rows disappear via
     * the existing `ON DELETE CASCADE` FKs declared in
     * `Version20260501000000.php`; `actions`/`scheduled_jobs` pointed
     * at the purged data table also cascade (with
     * `scheduled_job_reminders` using `SET NULL` instead). Without
     * this explicit DELETE the FK on `data_tables(id_plugins) ON
     * DELETE SET NULL` would just orphan the table rows and the docs'
     * "purge is complete" claim would not hold.
     */
    private function deletePluginTaggedRows(?int $idPlugins): void
    {
        if ($idPlugins === null) {
            return;
        }
        $taggedTables = [
            'styles',
            'api_routes',
            'fields',
            'permissions',
            'lookups',
            'data_tables',
        ];
        foreach ($taggedTables as $table) {
            $this->connection->executeStatement(
                sprintf('DELETE FROM `%s` WHERE `id_plugins` = :idp', $table),
                ['idp' => $idPlugins]
            );
        }
    }
}
