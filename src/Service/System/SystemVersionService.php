<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use App\Plugin\Versioning\PluginCompatibility;
use App\Repository\Plugin\PluginRepository;
use Doctrine\DBAL\Connection;

/**
 * Builds the instance version summary surfaced to the admin maintenance UI.
 *
 * Pure read-only, no Docker, no network: every fact comes from the running
 * application's own configuration and database (current version parameters, the
 * applied Doctrine migration head, and the installed-plugin table). Mirrors the
 * shared `ISystemVersion` contract.
 */
class SystemVersionService
{
    public function __construct(
        private readonly SystemInstanceService $instance,
        private readonly PluginRepository $pluginRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     instance_id: string,
     *     selfhelp_version: string,
     *     backend_version: string,
     *     frontend_version: string,
     *     mobile_preview_version: string,
     *     plugin_api_version: string,
     *     database_migration_version: string,
     *     deployment: string,
     *     safe_mode: bool,
     *     maintenance_mode: bool,
     *     installed_plugins: list<array{id: string, version: string, compatible: bool}>
     * }
     */
    public function getVersion(): array
    {
        $cmsVersion = $this->instance->getCmsVersion();

        return [
            'instance_id' => $this->instance->getInstanceId(),
            'selfhelp_version' => $cmsVersion,
            'backend_version' => $cmsVersion,
            'frontend_version' => $this->instance->getFrontendVersion(),
            'mobile_preview_version' => $this->instance->getMobilePreviewVersion(),
            'plugin_api_version' => $this->instance->getPluginApiVersion(),
            'database_migration_version' => $this->getDatabaseMigrationVersion(),
            'deployment' => $this->instance->getDeployment(),
            'safe_mode' => $this->instance->isSafeMode(),
            'maintenance_mode' => $this->instance->isMaintenanceMode(),
            'installed_plugins' => $this->getInstalledPlugins($cmsVersion),
        ];
    }

    /**
     * Highest applied Doctrine migration, e.g. `Version20260608131221`.
     * Returns an empty string when the metadata table is not present yet.
     */
    private function getDatabaseMigrationVersion(): string
    {
        try {
            $raw = $this->connection->fetchOne(
                'SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1'
            );
        } catch (\Throwable) {
            return '';
        }

        if (!is_string($raw) || $raw === '') {
            return '';
        }

        // Stored as the FQCN `DoctrineMigrations\VersionYYYY...`; expose the
        // short version identifier the manager + UI care about.
        $pos = strrpos($raw, '\\');

        return $pos === false ? $raw : substr($raw, $pos + 1);
    }

    /**
     * @return list<array{id: string, version: string, compatible: bool}>
     */
    private function getInstalledPlugins(string $cmsVersion): array
    {
        $result = [];
        foreach ($this->pluginRepository->findAllOrderedByName() as $plugin) {
            $result[] = [
                'id' => $plugin->getPluginId(),
                'version' => $plugin->getVersion(),
                'compatible' => $this->isPluginCompatible($plugin->getManifestJson(), $cmsVersion),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function isPluginCompatible(array $manifest, string $cmsVersion): bool
    {
        return PluginCompatibility::isManifestCoreCompatible($manifest, $cmsVersion);
    }
}
