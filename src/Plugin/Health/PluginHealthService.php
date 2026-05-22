<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Health;

use App\Entity\Plugin\Plugin;
use App\Exception\ServiceException;
use App\Plugin\Lifecycle\PluginLockFileReader;
use App\Plugin\Lifecycle\PluginSafeMode;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;

/**
 * Aggregates plugin health information.
 *
 * Two top-level entry points:
 *
 *   - {@see runForPlugin()} — single-plugin report (used by
 *     `/cms-api/v1/admin/plugins/{pluginId}/health`).
 *   - {@see runGlobalDoctor()} — site-wide doctor report (used by the
 *     `selfhelp:plugin:doctor` command and the global admin badge).
 *
 * Plugins that implement `PluginHealthCheckInterface` and register
 * with the `selfhelp.plugin.health_check` tag have their checks
 * delegated into the report.
 */
final class PluginHealthService
{
    /**
     * @param iterable<PluginHealthCheckInterface> $pluginChecks
     */
    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly PluginOperationRepository $operations,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginSafeMode $safeMode,
        private readonly PluginCompatibilityValidator $compatibility,
        private readonly iterable $pluginChecks = [],
        private readonly ?HubInterface $mercureHub = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function runForPlugin(string $pluginId): array
    {
        $plugin = $this->plugins->findOneByPluginId($pluginId);
        if (!$plugin instanceof Plugin) {
            throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
        }

        $manifest = new PluginManifest($plugin->getManifestJson());
        $compat = $this->compatibility->check($manifest);

        $pluginCustom = null;
        foreach ($this->pluginChecks as $check) {
            if ($check->getPluginId() === $pluginId) {
                try {
                    $pluginCustom = $check->runHealthCheck();
                } catch (\Throwable $e) {
                    $pluginCustom = [
                        'status' => 'failed',
                        'subchecks' => [[
                            'name' => 'custom',
                            'status' => 'failed',
                            'message' => $e->getMessage(),
                        ]],
                    ];
                }
                break;
            }
        }

        $recentOps = array_map(
            fn($op) => [
                'id' => $op->getId(),
                'type' => $op->getType(),
                'status' => $op->getStatus(),
                'createdAt' => $op->getCreatedAt()->format(DATE_ATOM),
                'finishedAt' => $op->getFinishedAt()?->format(DATE_ATOM),
                'errorSummary' => $op->getErrorSummary(),
            ],
            $this->operations->findByPluginId($pluginId, 5),
        );

        return [
            'pluginId' => $pluginId,
            'version' => $plugin->getVersion(),
            'enabled' => $plugin->isEnabled(),
            'installMode' => $plugin->getInstallMode(),
            'trustLevel' => $plugin->getTrustLevel(),
            'compatibility' => $compat,
            'recentOperations' => $recentOps,
            'pluginCheck' => $pluginCustom,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runGlobalDoctor(): array
    {
        $safeModeOn = $this->safeMode->isEnabled();
        $plugins = $this->plugins->findAllOrderedByName();

        $checks = [
            'safeMode' => [
                'name' => 'Safe mode',
                'status' => $safeModeOn ? 'warning' : 'ok',
                'message' => $safeModeOn ? 'Safe mode is enabled (plugins are not loaded at boot).' : 'Safe mode is disabled.',
            ],
            'lockFile' => $this->checkLockFile($plugins),
            'mercure' => $this->checkMercure(),
            'failedOperations' => $this->checkFailedOperations(),
        ];

        $pluginReports = [];
        foreach ($plugins as $plugin) {
            $pluginReports[] = $this->runForPlugin($plugin->getPluginId());
        }

        return [
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'siteChecks' => $checks,
            'plugins' => $pluginReports,
        ];
    }

    /**
     * @param list<Plugin> $plugins
     * @return array<string,mixed>
     */
    private function checkLockFile(array $plugins): array
    {
        $lock = $this->lockFileReader->read();
        if ($lock === null) {
            if ($plugins === []) {
                return ['name' => 'Lock file', 'status' => 'ok', 'message' => 'No plugins installed and no lock file (expected).'];
            }
            return ['name' => 'Lock file', 'status' => 'warning', 'message' => 'Lock file missing while plugins are installed; run selfhelp:plugin:repair.'];
        }

        $lockIds = array_map(static fn(array $entry): string => isset($entry['id']) ? (string) $entry['id'] : '', $lock->plugins);
        $lockIds = array_filter($lockIds);
        $dbIds = array_map(static fn(Plugin $p): string => $p->getPluginId(), $plugins);
        $missingInLock = array_diff($dbIds, $lockIds);
        $extraInLock = array_diff($lockIds, $dbIds);

        if ($missingInLock === [] && $extraInLock === []) {
            return ['name' => 'Lock file', 'status' => 'ok', 'message' => 'Lock file matches the plugins table.'];
        }
        return [
            'name' => 'Lock file',
            'status' => 'warning',
            'message' => sprintf(
                'Drift between plugins table and lock file (missing: %s; extra: %s).',
                implode(', ', $missingInLock) ?: 'none',
                implode(', ', $extraInLock) ?: 'none'
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMercure(): array
    {
        if ($this->mercureHub === null) {
            return ['name' => 'Mercure hub', 'status' => 'warning', 'message' => 'Mercure hub not configured; realtime updates disabled.'];
        }
        return ['name' => 'Mercure hub', 'status' => 'ok', 'message' => 'Mercure hub is wired into the plugin layer.'];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFailedOperations(): array
    {
        $failed = $this->operations->findBy(['status' => 'failed'], ['createdAt' => 'DESC'], 5);
        if ($failed === []) {
            return ['name' => 'Recent failed operations', 'status' => 'ok', 'message' => 'No failed plugin operations.'];
        }
        return [
            'name' => 'Recent failed operations',
            'status' => 'warning',
            'message' => sprintf('%d failed operation(s) require attention.', count($failed)),
            'metadata' => [
                'recent' => array_map(
                    static fn($op): array => [
                        'id' => $op->getId(),
                        'pluginId' => $op->getPluginId(),
                        'type' => $op->getType(),
                        'errorSummary' => $op->getErrorSummary(),
                    ],
                    $failed,
                ),
            ],
        ];
    }
}
