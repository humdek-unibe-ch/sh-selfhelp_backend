<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use App\Entity\Plugin\Plugin;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Doctrine ORM listener that blocks plugins from writing to core
 * tables they have not declared in `dataAccess.write`. Reads are
 * allowed by Doctrine itself, but writes (insert/update/delete) on
 * unowned/undeclared tables get intercepted at `onFlush` time and a
 * `PluginCapabilityViolationException` is thrown with the offending
 * plugin id + table name.
 *
 * Detection is call-stack based: we walk the PHP backtrace looking
 * for a class whose namespace matches one of the installed plugin
 * bundle namespaces. Core writes — issued from `App\…` services —
 * always bypass the guard.
 *
 * The protected-tables list is delegated to {@see ProtectedTablesPolicy}
 * so the migration-runner guard ({@see PluginMigrationGuard}), the
 * runtime entity guard (this class) and the purger
 * ({@see \App\Plugin\Lifecycle\PluginPurger}) all agree on what
 * counts as protected.
 *
 * NOTE: this is the entity-write path. Raw DBAL queries that bypass
 * Doctrine's UnitOfWork (rare in the new codebase but possible in
 * legacy services) are *not* covered. The matching deny-list at the
 * migration-runner level catches destructive DDL on those tables.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class PluginDataAccessGuard
{
    public function __construct(
        private readonly PluginRegistryService $plugins,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        $pluginContext = $this->detectPluginContextFromBacktrace();
        if ($pluginContext === null) {
            return;
        }

        $manifest = new PluginManifest($pluginContext->getManifestJson());
        $allowedWrites = $manifest->getDataAccessWrite();
        $ownedTables = $manifest->getOwnedTables();
        $pluginId = $pluginContext->getPluginId();

        foreach (['getScheduledEntityInsertions', 'getScheduledEntityUpdates', 'getScheduledEntityDeletions'] as $bucket) {
            foreach ($uow->{$bucket}() as $entity) {
                $meta = $args->getObjectManager()->getClassMetadata(get_class($entity));
                $this->assertWriteAllowed($pluginId, $meta, $allowedWrites, $ownedTables);
            }
        }
    }

    /**
     * @param list<string> $allowedWrites
     * @param list<string> $ownedTables
     */
    private function assertWriteAllowed(
        string $pluginId,
        ClassMetadata $meta,
        array $allowedWrites,
        array $ownedTables,
    ): void {
        $table = (string) ($meta->getTableName() ?? '');
        if ($table === '') {
            return;
        }

        // Plugins always own their own tables.
        if (in_array($table, $ownedTables, true)) {
            return;
        }

        // Owned data_table prefix (sh2_surveyjs_*).
        if ($this->matchesOwnedDataTablePrefix($pluginId, $table)) {
            return;
        }

        if (ProtectedTablesPolicy::isProtected($table)) {
            // Protected tables require explicit grant on top of being
            // listed in dataAccess.write.
            if (!in_array($table, $allowedWrites, true)) {
                $this->refuse($pluginId, $table, 'protected-core-table');
            }
            return;
        }

        // Non-protected, non-owned tables: still require declaration in
        // dataAccess.write. This is the deny-by-default rule.
        if (!in_array($table, $allowedWrites, true)) {
            $this->refuse($pluginId, $table, 'undeclared-write');
        }
    }

    private function matchesOwnedDataTablePrefix(string $pluginId, string $table): bool
    {
        $manifest = $this->loadManifestFor($pluginId);
        if ($manifest === null) {
            return false;
        }
        $prefix = $manifest->getOwnedDataTablePrefix();
        return $prefix !== null && $prefix !== '' && str_starts_with($table, $prefix);
    }

    private function loadManifestFor(string $pluginId): ?PluginManifest
    {
        $plugin = $this->plugins->findByPluginId($pluginId);
        return $plugin === null ? null : new PluginManifest($plugin->getManifestJson());
    }

    private function refuse(string $pluginId, string $table, string $reason): never
    {
        $this->logger->warning('Plugin data access denied', [
            'pluginId' => $pluginId,
            'table' => $table,
            'reason' => $reason,
        ]);
        throw new PluginCapabilityViolationException(sprintf(
            'Plugin "%s" attempted to write table "%s" (%s). Declare it in plugin.json#dataAccess.write or use the plugin\'s own tables.',
            $pluginId,
            $table,
            $reason,
        ));
    }

    /**
     * Walks the call stack looking for a class whose namespace matches
     * one of the installed plugin bundle namespaces. Returns the
     * Plugin entity for the first match, or `null` for core code.
     *
     * Iterates at most ~60 frames so it stays cheap. The detection is
     * deliberately conservative — anything outside the registered
     * plugin namespaces is treated as core.
     */
    private function detectPluginContextFromBacktrace(): ?Plugin
    {
        $bundleClassByNamespace = [];
        foreach ($this->plugins->getEnabled() as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $bundleClass = $manifest->getBackendBundleClass();
            if ($bundleClass === null || $bundleClass === '') {
                continue;
            }
            $namespace = $this->extractNamespace($bundleClass);
            if ($namespace !== '') {
                $bundleClassByNamespace[$namespace] = $plugin;
            }
        }

        if ($bundleClassByNamespace === []) {
            return null;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
        foreach ($trace as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if ($class === '') {
                continue;
            }
            foreach ($bundleClassByNamespace as $namespace => $plugin) {
                if (str_starts_with($class, $namespace . '\\')) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    private function extractNamespace(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }
}
