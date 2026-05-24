<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Migration;

use App\Plugin\Manifest\PluginManifest;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Exception\NoMigrationsToExecute;
use Doctrine\Migrations\MigratorConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the Doctrine migrations shipped under a plugin's
 * `<bundle>/Migrations/` directory.
 *
 * The architecture document (docs/plugins/architecture.md §7) puts
 * "run plugin Doctrine migrations" between bundles-file regeneration
 * and lock-file write inside `PluginInstaller::finalize()` /
 * `PluginUpdater::finalize()`. The runner makes that step real.
 *
 * Design choices:
 *
 *   - A FRESH `DependencyFactory` is built per call. Reusing the
 *     container's `doctrine.migrations.dependency_factory` is not safe
 *     because its `Configuration` is frozen on first access and we
 *     need to register the plugin's migrations directory dynamically.
 *   - The host's `Doctrine\DBAL\Connection` is reused so plugin
 *     migrations land in the same `doctrine_migration_versions`
 *     metadata table as host migrations. Re-running a plugin's
 *     migrations is therefore idempotent — already-applied versions
 *     are skipped by Doctrine's built-in tracking.
 *   - The plugin's migration class is loaded through the secondary
 *     Composer autoloader registered by `PluginAutoloaderBootstrap`,
 *     so the Symfony bundle does NOT need to be in the container
 *     for `class_exists()` to succeed. That is the only reason
 *     `finalize()` can run migrations BEFORE the plugin is enabled.
 *   - Down migrations are intentionally NOT exposed. Uninstall +
 *     purge use the existing destructive-guard / lock-file flow
 *     instead; reverting a plugin migration during install would
 *     conflict with the snapshot/rollback machinery in
 *     `PluginRollbacker`.
 */
final class PluginMigrationsRunner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Apply every pending plugin migration. Returns a structured
     * summary that the install/update orchestrators write into
     * `plugin_operations.logs_json`.
     *
     * @return array{
     *     namespace: string|null,
     *     directory: string|null,
     *     applied: list<string>,
     *     skipped: bool,
     *     skippedReason: string|null
     * }
     */
    public function migrate(PluginManifest $manifest): array
    {
        $namespace = $manifest->getBackendMigrationsNamespace();
        $directory = $this->resolveMigrationsDirectory($manifest);

        if ($namespace === null || $namespace === '') {
            return [
                'namespace' => null,
                'directory' => $directory,
                'applied' => [],
                'skipped' => true,
                'skippedReason' => 'no-backend-migrations-namespace',
            ];
        }

        if ($directory === null) {
            return [
                'namespace' => $namespace,
                'directory' => null,
                'applied' => [],
                'skipped' => true,
                'skippedReason' => 'migrations-directory-missing',
            ];
        }

        $configuration = new Configuration();
        $configuration->addMigrationsDirectory($namespace, $directory);
        // Plugin migrations almost always ship DDL (CREATE TABLE,
        // ALTER TABLE). On MySQL DDL triggers an implicit COMMIT that
        // silently kills any outer transaction Doctrine has opened —
        // including the one `allOrNothing=true` would open here. The
        // resulting nesting-level drift breaks the very next ORM flush
        // with "SAVEPOINT DOCTRINE_n does not exist", which in turn
        // closes the EntityManager and leaves the half-installed plugin
        // row committed (architecture rules forbid the orphan). Keep
        // each migration responsible for its own transactional scope
        // (the migration class's own `isTransactional()`).
        $configuration->setAllOrNothing(false);
        $configuration->setCheckDatabasePlatform(false);

        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
            $this->logger,
        );

        $factory->getMetadataStorage()->ensureInitialized();

        $planCalculator = $factory->getMigrationPlanCalculator();
        if (count($planCalculator->getMigrations()) === 0) {
            return [
                'namespace' => $namespace,
                'directory' => $directory,
                'applied' => [],
                'skipped' => true,
                'skippedReason' => 'no-migrations-on-disk',
            ];
        }

        // Walk to the highest available version using the same alias
        // resolver the `doctrine:migrations:migrate` CLI uses. When no
        // migrations are pending the resolver throws — we catch it as
        // the "already applied" branch so re-running finalize() stays
        // idempotent (important for repair/retry flows).
        try {
            $targetVersion = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        } catch (NoMigrationsToExecute | NoMigrationsFoundWithCriteria) {
            return [
                'namespace' => $namespace,
                'directory' => $directory,
                'applied' => [],
                'skipped' => true,
                'skippedReason' => 'all-migrations-already-applied',
            ];
        }

        $plan = $planCalculator->getPlanUntilVersion($targetVersion);
        if (count($plan) === 0) {
            return [
                'namespace' => $namespace,
                'directory' => $directory,
                'applied' => [],
                'skipped' => true,
                'skippedReason' => 'all-migrations-already-applied',
            ];
        }

        $migratorConfiguration = (new MigratorConfiguration())
            ->setAllOrNothing(false)
            ->setTimeAllQueries(false);

        try {
            $factory->getMigrator()->migrate($plan, $migratorConfiguration);
        } finally {
            // Defensive cleanup against MySQL's implicit-commit-on-DDL
            // behaviour: even with `allOrNothing=false` an individual
            // migration whose `isTransactional()` defaults to `true`
            // still opens a transaction before running its CREATE/ALTER
            // TABLE, the DDL silently commits it on the driver side, and
            // the Doctrine wrapper's nesting counter is left at >0 with
            // no actual transaction underneath. Closing the connection
            // here resets the counter to 0 and lets the next query
            // lazy-reconnect with a clean state, so the caller's next
            // `em->flush()` does not blow up with
            // "SAVEPOINT DOCTRINE_n does not exist".
            if ($this->connection->getTransactionNestingLevel() > 0) {
                $this->connection->close();
            }
        }

        $applied = [];
        foreach ($plan->getItems() as $planItem) {
            $applied[] = (string) $planItem->getVersion();
        }

        return [
            'namespace' => $namespace,
            'directory' => $directory,
            'applied' => $applied,
            'skipped' => false,
            'skippedReason' => null,
        ];
    }

    /**
     * Locate the plugin's `Migrations/` directory by reflecting on the
     * backend bundle class. Mirrors the path-resolution used by
     * {@see \App\Plugin\Lifecycle\PluginLockFileWriter::collectMigrationHashes()}
     * so the migration hashes recorded in the lock file and the files
     * actually executed at runtime stay aligned.
     */
    private function resolveMigrationsDirectory(PluginManifest $manifest): ?string
    {
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass === null || $bundleClass === '' || !class_exists($bundleClass)) {
            return null;
        }
        $bundleReflection = new \ReflectionClass($bundleClass);
        $bundleFile = $bundleReflection->getFileName();
        if ($bundleFile === false) {
            return null;
        }
        $migrationsDir = dirname($bundleFile) . DIRECTORY_SEPARATOR . 'Migrations';
        return is_dir($migrationsDir) ? $migrationsDir : null;
    }
}
