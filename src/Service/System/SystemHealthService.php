<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * Aggregated, instance-scoped system health/status for the admin maintenance UI.
 *
 * The backend can only honestly observe what it can reach from its own process:
 * the database (a real query), the cache/Redis backend (a round-trip), the
 * scheduled-jobs runner heartbeat and worker queue (their DB tables), and the
 * presence of Mercure/mailer/worker configuration. Cross-container liveness
 * (the manager's territory) is reported as "configured" rather than faked.
 *
 * It never leaks secrets: connection strings/DSNs are reduced to a boolean
 * "configured" plus a redacted scheme/host, never the raw value.
 *
 * Mirrors the shared `ISystemHealth` contract.
 */
class SystemHealthService
{
    private const COMPONENT_OK = 'ok';
    private const COMPONENT_DOWN = 'down';
    private const COMPONENT_DEGRADED = 'degraded';
    private const COMPONENT_CONFIGURED = 'configured';
    private const COMPONENT_NOT_CONFIGURED = 'not_configured';
    private const COMPONENT_UNKNOWN = 'unknown';

    private const REGISTRY_LAST_OK_CACHE_KEY = 'selfhelp_registry_last_successful_check';

    /**
     * The manager poller ticks every ~15s; no authenticated poll for this long
     * means the loop has stopped (manager down, watch loop killed, ...).
     */
    private const MANAGER_STALE_AFTER_SECONDS = 600;

    public function __construct(
        private readonly SystemInstanceService $instance,
        private readonly SystemVersionService $versionService,
        private readonly SystemUpdateService $updateService,
        private readonly SystemRegistryReader $registry,
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $mercureUrl,
        private readonly string $mailerDsn,
        private readonly string $messengerTransportDsn,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * @return array{
     *     instance_id: string,
     *     overall: string,
     *     checked_at: string,
     *     safe_mode: bool,
     *     maintenance_mode: bool,
     *     version: array{selfhelp: string, backend: string, frontend: string, plugin_api: string, database_migration: string},
     *     update: array{operation_id: string, status: string, progress_percent: int},
     *     components: list<array{name: string, status: string, detail: string}>
     * }
     */
    public function getHealth(): array
    {
        $version = $this->versionService->getVersion();
        $status = $this->updateService->getStatus();

        $components = [
            $this->checkDatabase(),
            $this->checkCache(),
            $this->checkRedis(),
            $this->checkMercure(),
            $this->checkWorker(),
            $this->checkScheduler(),
            $this->checkManagerLoop(),
            $this->checkMailer(),
            $this->checkRegistry(),
            $this->checkPlugins($version['installed_plugins']),
        ];

        return [
            'instance_id' => $this->instance->getInstanceId(),
            'overall' => $this->overall($components),
            'checked_at' => ($this->now ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'safe_mode' => $this->instance->isSafeMode(),
            'maintenance_mode' => $this->instance->isMaintenanceMode(),
            'version' => [
                'selfhelp' => $version['selfhelp_version'],
                'backend' => $version['backend_version'],
                'frontend' => $version['frontend_version'],
                'plugin_api' => $version['plugin_api_version'],
                'database_migration' => $version['database_migration_version'],
            ],
            'update' => [
                'operation_id' => is_string($status['operation_id'] ?? null) ? $status['operation_id'] : '',
                'status' => is_string($status['status'] ?? null) ? $status['status'] : 'unknown',
                'progress_percent' => is_int($status['progress_percent'] ?? null) ? $status['progress_percent'] : 0,
            ],
            'components' => $components,
        ];
    }

    /**
     * `down` if the database is down (cannot serve traffic); otherwise
     * `degraded` if any component is down/degraded or the instance is in
     * safe/maintenance mode; otherwise `healthy`.
     *
     * @param list<array{name: string, status: string, detail: string}> $components
     */
    private function overall(array $components): string
    {
        foreach ($components as $c) {
            if ($c['name'] === 'database' && $c['status'] === self::COMPONENT_DOWN) {
                return 'down';
            }
        }
        if ($this->instance->isSafeMode() || $this->instance->isMaintenanceMode()) {
            return 'degraded';
        }
        foreach ($components as $c) {
            if ($c['status'] === self::COMPONENT_DOWN || $c['status'] === self::COMPONENT_DEGRADED) {
                return 'degraded';
            }
        }

        return 'healthy';
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkDatabase(): array
    {
        try {
            $this->connection->fetchOne('SELECT 1');

            return $this->component('database', self::COMPONENT_OK, 'Connection healthy.');
        } catch (\Throwable $e) {
            return $this->component('database', self::COMPONENT_DOWN, 'Database query failed.');
        }
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkCache(): array
    {
        try {
            $item = $this->cache->getItem('selfhelp_system_health_probe');
            $item->set('ok');
            $this->cache->save($item);
            $ok = $this->cache->getItem('selfhelp_system_health_probe')->get() === 'ok';

            return $ok
                ? $this->component('cache', self::COMPONENT_OK, 'Cache round-trip succeeded.')
                : $this->component('cache', self::COMPONENT_DEGRADED, 'Cache round-trip returned no value.');
        } catch (\Throwable $e) {
            return $this->component('cache', self::COMPONENT_DOWN, 'Cache pool is not reachable.');
        }
    }

    /**
     * The cache pool IS the Redis client in production; report Redis from the
     * adapter class so dev/test (array adapter) is shown as not_configured
     * rather than falsely "ok". In dev the profiler decorates every pool with
     * TraceableAdapter, which would hide the Redis backend, so unwrap
     * decorators before sniffing the class.
     *
     * @return array{name: string, status: string, detail: string}
     */
    private function checkRedis(): array
    {
        $pool = $this->cache;
        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }
        $adapter = $pool::class;
        $isRedis = stripos($adapter, 'redis') !== false || stripos($adapter, 'predis') !== false;
        if (!$isRedis) {
            return $this->component('redis', self::COMPONENT_NOT_CONFIGURED, 'Cache backend is not Redis-based.');
        }
        try {
            $item = $this->cache->getItem('selfhelp_system_health_probe');
            $item->set('ok');
            $this->cache->save($item);

            return $this->component('redis', self::COMPONENT_OK, 'Redis-backed cache reachable.');
        } catch (\Throwable $e) {
            return $this->component('redis', self::COMPONENT_DOWN, 'Redis-backed cache is not reachable.');
        }
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkMercure(): array
    {
        if ($this->mercureUrl === '') {
            return $this->component('mercure', self::COMPONENT_NOT_CONFIGURED, 'No Mercure hub configured.');
        }

        return $this->component('mercure', self::COMPONENT_CONFIGURED, 'Mercure hub configured.');
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkWorker(): array
    {
        if ($this->messengerTransportDsn === '') {
            return $this->component('worker', self::COMPONENT_NOT_CONFIGURED, 'No messenger transport configured.');
        }
        try {
            $raw = $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');
            $queued = is_numeric($raw) ? (int) $raw : 0;

            return $this->component('worker', self::COMPONENT_OK, sprintf('%d message(s) queued.', $queued));
        } catch (\Throwable $e) {
            // Transport configured but the table is not present yet (created on
            // first dispatch). Configured, not failed.
            return $this->component('worker', self::COMPONENT_CONFIGURED, 'Transport configured; queue not initialised.');
        }
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkScheduler(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT started_at, status FROM scheduled_job_runner_runs ORDER BY started_at DESC LIMIT 1'
            );
        } catch (\Throwable $e) {
            return $this->component('scheduler', self::COMPONENT_UNKNOWN, 'Scheduler runner history unavailable.');
        }

        if ($row === false) {
            return $this->component('scheduler', self::COMPONENT_UNKNOWN, 'No scheduler runs recorded yet.');
        }

        $startedAt = is_scalar($row['started_at'] ?? null) ? (string) $row['started_at'] : 'unknown';
        $runStatus = is_scalar($row['status'] ?? null) ? (string) $row['status'] : 'unknown';

        return $this->component('scheduler', self::COMPONENT_OK, sprintf('Last run %s (%s).', $startedAt, $runStatus));
    }

    /**
     * CMS<->Manager update loop visibility. The backend cannot probe the
     * manager directly (it runs outside the stack), but it CAN report honestly
     * whether the loop is enabled (token configured) and when an authenticated
     * manager last polled — which is exactly what an operator needs when a
     * requested update "does nothing".
     *
     * @return array{name: string, status: string, detail: string}
     */
    private function checkManagerLoop(): array
    {
        $info = $this->updateService->getManagerLoopInfo();

        if (!$info['configured']) {
            return $this->component(
                'manager_loop',
                self::COMPONENT_NOT_CONFIGURED,
                'Manager loop disabled: SELFHELP_MANAGER_TOKEN is not set for this instance. CMS-requested updates will not execute. Update the instance with a current SelfHelp Manager (or run "sh-manager instance repair <id>") to provision the token.',
            );
        }

        $lastSeen = $info['last_seen_at'];
        if ($lastSeen === null) {
            return $this->component(
                'manager_loop',
                self::COMPONENT_DOWN,
                'Manager token is configured but no manager has ever polled this instance. Ensure the SelfHelp Manager web service is running (persistent mode) or a "sh-manager instance process-operations <id> --watch" loop is active.',
            );
        }

        $seenAt = null;
        try {
            $seenAt = new \DateTimeImmutable($lastSeen);
        } catch (\Throwable) {
            // Unparseable timestamp: fall through to the stale branch below.
        }
        $now = $this->now ?? new \DateTimeImmutable();
        if ($seenAt === null || ($now->getTimestamp() - $seenAt->getTimestamp()) > self::MANAGER_STALE_AFTER_SECONDS) {
            return $this->component(
                'manager_loop',
                self::COMPONENT_DEGRADED,
                sprintf('Manager last polled %s; it appears to have stopped. CMS-requested updates will wait until it returns.', $lastSeen),
            );
        }

        return $this->component('manager_loop', self::COMPONENT_OK, sprintf('Manager last polled %s.', $lastSeen));
    }

    /** @return array{name: string, status: string, detail: string} */
    private function checkMailer(): array
    {
        if ($this->mailerDsn === '') {
            return $this->component('mailer', self::COMPONENT_NOT_CONFIGURED, 'No mailer DSN configured.');
        }

        return $this->component('mailer', self::COMPONENT_CONFIGURED, 'Mailer configured.');
    }

    /**
     * Registry reachability + "last successful check". Deliberately reports
     * `unknown` (informational, NON-degrading) when unreachable, because an
     * existing instance must keep running through a registry outage (plan
     * invariant 20). The last successful check is tracked in the cache so the
     * UI can show how stale the registry view is.
     *
     * @return array{name: string, status: string, detail: string}
     */
    private function checkRegistry(): array
    {
        $nowIso = ($this->now ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        if ($this->registry->isReachable()) {
            try {
                $item = $this->cache->getItem(self::REGISTRY_LAST_OK_CACHE_KEY);
                $item->set($nowIso);
                $this->cache->save($item);
            } catch (\Throwable) {
                // Cache write failure must not turn a reachable registry into an error.
            }

            return $this->component('registry', self::COMPONENT_OK, sprintf('Official registry reachable; last successful check %s.', $nowIso));
        }

        $last = 'never';
        try {
            $stored = $this->cache->getItem(self::REGISTRY_LAST_OK_CACHE_KEY)->get();
            if (is_string($stored) && $stored !== '') {
                $last = $stored;
            }
        } catch (\Throwable) {
            // Ignore cache read failures; report "never".
        }

        return $this->component(
            'registry',
            self::COMPONENT_UNKNOWN,
            sprintf('Official registry unreachable; last successful check %s. Existing instances keep running; updates/plugin installs are deferred until it is reachable.', $last),
        );
    }

    /**
     * @param list<array{id: string, version: string, compatible: bool}> $plugins
     * @return array{name: string, status: string, detail: string}
     */
    private function checkPlugins(array $plugins): array
    {
        $incompatible = array_filter($plugins, static fn(array $p): bool => $p['compatible'] === false);
        $count = count($plugins);
        if ($incompatible !== []) {
            return $this->component(
                'plugins',
                self::COMPONENT_DEGRADED,
                sprintf('%d installed, %d incompatible with the current core.', $count, count($incompatible)),
            );
        }

        return $this->component('plugins', self::COMPONENT_OK, sprintf('%d installed, all compatible.', $count));
    }

    /** @return array{name: string, status: string, detail: string} */
    private function component(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
