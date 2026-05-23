<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Health;

use App\Entity\Plugin\Plugin;
use App\Exception\ServiceException;
use App\Plugin\Event\PluginRealtimeTopicRegistryEvent;
use App\Plugin\Lifecycle\PluginLockFileReader;
use App\Plugin\Lifecycle\PluginSafeMode;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly iterable $pluginChecks = [],
        private readonly ?HubInterface $mercureHub = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?string $mercureHubUrl = null,
        private readonly ?string $frontendHostDir = null,
        private readonly ?string $mobileHostDir = null,
    ) {
    }

    /**
     * Returns the catalog of plugin realtime topics, built by
     * dispatching `PluginRealtimeTopicRegistryEvent`. Used by the
     * admin "Realtime topics" tab, the doctor command, and by
     * deployment scripts that want to validate the JWT scopes.
     *
     * @return array<int, array{
     *   pluginId: string,
     *   key: string,
     *   description: string,
     *   requiredPermission: string|null,
     *   payloadSchemaPath: string|null,
     * }>
     */
    public function getRealtimeTopicCatalog(): array
    {
        $event = $this->eventDispatcher->dispatch(new PluginRealtimeTopicRegistryEvent());
        return $event->getTopics();
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

        $endpointCheck = $this->probePluginHealthEndpoint($manifest);

        // Surface every check the admin UI expects:
        //   - compatibility (semver vs host/sdk)
        //   - the optional in-process `PluginHealthCheckInterface` result
        //   - the plugin's HTTP healthEndpoint (when declared in the manifest)
        $checks = [];
        $checks[] = [
            'name' => 'Compatibility',
            'status' => $compat['severity'] === 'ok' ? 'ok' : ($compat['severity'] === 'warning' ? 'warning' : 'error'),
            'message' => $compat['reasons'] === [] ? 'Compatible with host/SDK.' : implode('; ', $compat['reasons']),
        ];
        if ($pluginCustom !== null) {
            foreach ($pluginCustom['subchecks'] as $sub) {
                $checks[] = [
                    'name' => 'plugin:' . $sub['name'],
                    'status' => $this->normalizeStatus($sub['status']),
                    'message' => $sub['message'],
                ];
            }
        }
        if ($endpointCheck !== null) {
            $checks[] = $endpointCheck;
        }

        return [
            'pluginId' => $pluginId,
            'version' => $plugin->getVersion(),
            'enabled' => $plugin->isEnabled(),
            'installMode' => $plugin->getInstallMode(),
            'trustLevel' => $plugin->getTrustLevel(),
            'compatibility' => $compat,
            'recentOperations' => $recentOps,
            'pluginCheck' => $pluginCustom,
            'checks' => $checks,
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
            'lockVersionParity' => $this->checkLockVersionParity($plugins),
            'mercure' => $this->checkMercureReachable(),
            'realtimeTopics' => $this->checkRealtimeTopicCatalog($plugins),
            'failedOperations' => $this->checkFailedOperations(),
            'frontendPackages' => $this->checkNpmPackagesInstalled($plugins, $this->frontendHostDir, 'frontend'),
            'mobilePackages' => $this->checkNpmPackagesInstalled($plugins, $this->mobileHostDir, 'mobile'),
        ];

        $pluginReports = [];
        foreach ($plugins as $plugin) {
            $pluginReports[] = $this->runForPlugin($plugin->getPluginId());
        }

        return [
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'siteChecks' => $checks,
            'realtimeTopicCatalog' => $this->getRealtimeTopicCatalog(),
            'plugins' => $pluginReports,
        ];
    }

    /**
     * Compares the runtime topic registrations against the manifest
     * declarations. Detects two kinds of drift:
     *   - manifest declares a topic that no listener registered at runtime
     *     (the plugin shipped a topic without wiring its subscriber).
     *   - runtime listener registers a topic that the plugin's manifest
     *     does not advertise (un-documented capability).
     *
     * @param list<Plugin> $plugins
     * @return array<string,mixed>
     */
    private function checkRealtimeTopicCatalog(array $plugins): array
    {
        $registered = [];
        foreach ($this->getRealtimeTopicCatalog() as $topic) {
            $registered[$topic['pluginId']][] = $topic['key'];
        }

        $drift = [];
        foreach ($plugins as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $declared = array_map(
                static fn(array $t): string => (string) ($t['key'] ?? ''),
                $manifest->getRealtimeTopics(),
            );
            $declared = array_values(array_filter($declared, static fn(string $k): bool => $k !== ''));
            $runtime = $registered[$plugin->getPluginId()] ?? [];

            $missingRuntime = array_diff($declared, $runtime);
            $undeclared = array_diff($runtime, $declared);
            if ($missingRuntime !== [] || $undeclared !== []) {
                $drift[] = sprintf(
                    '%s (declared but no subscriber: %s; subscriber but not declared: %s)',
                    $plugin->getPluginId(),
                    implode(',', $missingRuntime) ?: 'none',
                    implode(',', $undeclared) ?: 'none',
                );
            }
        }

        if ($drift === []) {
            return [
                'name' => 'Realtime topics',
                'status' => 'ok',
                'message' => 'Manifest realtime topics match runtime subscribers.',
            ];
        }

        return [
            'name' => 'Realtime topics',
            'status' => 'warning',
            'message' => sprintf('Drift detected: %s.', implode('; ', $drift)),
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

        $lockIds = [];
        foreach ($lock->plugins as $entry) {
            $id = $entry['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $lockIds[] = $id;
            }
        }
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
     * Probes the configured Mercure hub URL with a short-timeout HTTP GET.
     * The Mercure hub responds with 200 (or 401 when the request is
     * unauthenticated, which still proves the hub is reachable). A
     * network error / timeout / 5xx is reported as an "error" because
     * realtime updates would silently fail.
     *
     * @return array<string,mixed>
     */
    private function checkMercureReachable(): array
    {
        if ($this->mercureHub === null) {
            return ['name' => 'Mercure hub', 'status' => 'warning', 'message' => 'Mercure hub not configured; realtime updates disabled.'];
        }
        if ($this->httpClient === null || !$this->mercureHubUrl) {
            return ['name' => 'Mercure hub', 'status' => 'ok', 'message' => 'Mercure hub is wired into the plugin layer (URL probe skipped).'];
        }
        try {
            $resp = $this->httpClient->request('GET', $this->mercureHubUrl, ['timeout' => 3]);
            $status = $resp->getStatusCode();
            if ($status < 500) {
                return [
                    'name' => 'Mercure hub',
                    'status' => 'ok',
                    'message' => sprintf('Hub responded with HTTP %d at %s.', $status, $this->mercureHubUrl),
                ];
            }
            return [
                'name' => 'Mercure hub',
                'status' => 'error',
                'message' => sprintf('Hub returned HTTP %d at %s.', $status, $this->mercureHubUrl),
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Mercure hub',
                'status' => 'error',
                'message' => sprintf('Hub probe failed: %s', $e->getMessage()),
            ];
        }
    }

    /**
     * Compares each installed plugin's version against the lock-file
     * entry for the same plugin id. Drift means the lock file says one
     * thing and the DB says another — usually caused by a manually
     * edited lock file or a half-applied update.
     *
     * @param list<Plugin> $plugins
     * @return array<string,mixed>
     */
    private function checkLockVersionParity(array $plugins): array
    {
        $lock = $this->lockFileReader->read();
        if ($lock === null) {
            return ['name' => 'Lock version parity', 'status' => 'ok', 'message' => 'No lock file present; parity check skipped.'];
        }
        $lockIndex = [];
        foreach ($lock->plugins as $entry) {
            $id = $entry['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }
            $version = $entry['version'] ?? null;
            $lockIndex[$id] = is_string($version) ? $version : '';
        }
        $drift = [];
        foreach ($plugins as $plugin) {
            $lockVersion = $lockIndex[$plugin->getPluginId()] ?? null;
            if ($lockVersion === null || $lockVersion === '') {
                $drift[] = sprintf('%s: missing from lock', $plugin->getPluginId());
                continue;
            }
            if ($lockVersion !== $plugin->getVersion()) {
                $drift[] = sprintf('%s: lock=%s db=%s', $plugin->getPluginId(), $lockVersion, $plugin->getVersion());
            }
        }
        if ($drift === []) {
            return ['name' => 'Lock version parity', 'status' => 'ok', 'message' => 'Lock-file versions match the plugins table.'];
        }
        return [
            'name' => 'Lock version parity',
            'status' => 'warning',
            'message' => sprintf('Version drift detected: %s', implode('; ', $drift)),
        ];
    }

    /**
     * Shells out `npm ls <package>` in the configured frontend/mobile
     * host directory to confirm the plugin's package is installed in
     * the right node_modules. Plugins without a frontend/mobile
     * package are reported as "skipped".
     *
     * The check is intentionally best-effort: when the host directory
     * is not configured (e.g. a CI environment that does not check out
     * the frontend), the check reports as "ok" with a message rather
     * than failing the doctor.
     *
     * @param list<Plugin> $plugins
     * @return array<string,mixed>
     */
    private function checkNpmPackagesInstalled(array $plugins, ?string $hostDir, string $kind): array
    {
        if ($hostDir === null || !is_dir($hostDir)) {
            return [
                'name' => sprintf('%s npm packages', ucfirst($kind)),
                'status' => 'ok',
                'message' => sprintf('Host %s directory not configured; npm ls skipped.', $kind),
            ];
        }
        $missing = [];
        $checked = 0;
        foreach ($plugins as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $packageName = $kind === 'frontend' ? $manifest->getFrontendPackage() : $manifest->getMobilePackage();
            if ($packageName === null || $packageName === '') continue;
            $checked++;
            $process = new Process(['npm', 'ls', '--json', $packageName], $hostDir);
            $process->setTimeout(15);
            try {
                $process->run();
                if (!$process->isSuccessful()) {
                    $missing[] = $packageName;
                    continue;
                }
                $output = trim($process->getOutput());
                if ($output === '') {
                    $missing[] = $packageName;
                    continue;
                }
                $parsed = json_decode($output, true);
                $deps = is_array($parsed) ? ($parsed['dependencies'] ?? null) : null;
                if (!is_array($deps) || !isset($deps[$packageName])) {
                    $missing[] = $packageName;
                }
            } catch (\Throwable $e) {
                $missing[] = sprintf('%s (npm ls failed: %s)', $packageName, $e->getMessage());
            }
        }
        if ($checked === 0) {
            return [
                'name' => sprintf('%s npm packages', ucfirst($kind)),
                'status' => 'ok',
                'message' => sprintf('No installed plugins declare a %s package.', $kind),
            ];
        }
        if ($missing === []) {
            return [
                'name' => sprintf('%s npm packages', ucfirst($kind)),
                'status' => 'ok',
                'message' => sprintf('All %d plugin %s package(s) are installed in %s.', $checked, $kind, $hostDir),
            ];
        }
        return [
            'name' => sprintf('%s npm packages', ucfirst($kind)),
            'status' => 'warning',
            'message' => sprintf('Missing %s packages: %s', $kind, implode(', ', $missing)),
        ];
    }

    /**
     * If the manifest declares `healthEndpoint`, HTTP GET it with a
     * short timeout. Returns a check row or `null` when no endpoint is
     * declared. The endpoint contract:
     *
     *   { "status": "ok|warning|error", "message": "..." }
     *
     * @return array<string,mixed>|null
     */
    private function probePluginHealthEndpoint(PluginManifest $manifest): ?array
    {
        $endpoint = $manifest->getHealthEndpoint();
        if ($endpoint === null || $endpoint === '') return null;
        if ($this->httpClient === null) {
            return [
                'name' => 'Health endpoint',
                'status' => 'warning',
                'message' => sprintf('Cannot probe %s: HTTP client not wired.', $endpoint),
            ];
        }
        try {
            $resp = $this->httpClient->request('GET', $endpoint, ['timeout' => 3]);
            $status = $resp->getStatusCode();
            if ($status >= 500) {
                return [
                    'name' => 'Health endpoint',
                    'status' => 'error',
                    'message' => sprintf('%s returned HTTP %d.', $endpoint, $status),
                ];
            }
            $body = $resp->toArray(false);
            $reportedStatus = $this->normalizeStatus($body['status'] ?? 'ok');
            $rawMessage = $body['message'] ?? null;
            $message = is_string($rawMessage) && $rawMessage !== ''
                ? $rawMessage
                : sprintf('Endpoint responded with HTTP %d.', $status);
            return [
                'name' => 'Health endpoint',
                'status' => $reportedStatus,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Health endpoint',
                'status' => 'warning',
                'message' => sprintf('Probe failed: %s', $e->getMessage()),
            ];
        }
    }

    private function normalizeStatus(mixed $raw): string
    {
        if (!is_string($raw) && !is_int($raw) && !is_bool($raw) && !is_float($raw) && !($raw instanceof \Stringable)) {
            return 'error';
        }
        $s = strtolower((string) $raw);
        if ($s === 'ok' || $s === 'pass' || $s === 'success') return 'ok';
        if ($s === 'warn' || $s === 'warning') return 'warning';
        return 'error';
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
