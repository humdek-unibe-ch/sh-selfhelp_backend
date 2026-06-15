<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Service\System\MaintenanceModeService;
use App\Service\System\SystemHealthService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryReader;
use App\Service\System\SystemUpdateService;
use App\Service\System\SystemVersionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * In-memory pool whose class name carries "Redis" so the health check's
 * adapter sniffing treats it as Redis-backed without a live Redis server.
 */
final class QaRedisFlavouredArrayAdapter extends ArrayAdapter
{
}

/**
 * Unit coverage for the aggregated system health endpoint (HIGH 5).
 *
 * Asserts the observable contract: overall verdict derivation (down when the
 * DB is down, degraded under safe/maintenance mode or an incompatible plugin,
 * healthy otherwise) and that no secret/DSN value leaks into the payload.
 */
final class SystemHealthServiceTest extends TestCase
{
    /** @param array{configured: bool, last_seen_at: string|null}|null $managerLoop */
    private function makeService(
        Connection $connection,
        bool $safeMode = false,
        bool $maintenanceMode = false,
        bool $pluginCompatible = true,
        string $mercureUrl = 'http://mercure/.well-known/mercure',
        string $mailerDsn = 'smtp://mail:1025',
        string $messengerDsn = 'doctrine://default',
        bool $registryReachable = true,
        ?CacheItemPoolInterface $cache = null,
        ?array $managerLoop = null,
    ): SystemHealthService {
        // env-forced maintenance mirrors the parameter; no file is touched.
        $maintenance = new MaintenanceModeService(sys_get_temp_dir() . '/shqa-health-no-maint', $maintenanceMode);
        $instance = new SystemInstanceService('inst-qa-health', '0.1.0', '0.1.0', '0.1.0', 'source', $safeMode, $maintenance);

        $versionService = $this->createStub(SystemVersionService::class);
        $versionService->method('getVersion')->willReturn([
            'instance_id' => 'inst-qa-health',
            'selfhelp_version' => '0.1.0',
            'backend_version' => '0.1.0',
            'frontend_version' => '0.1.0',
            'plugin_api_version' => '0.1.0',
            'database_migration_version' => 'Version20260608181148',
            'safe_mode' => $safeMode,
            'maintenance_mode' => $maintenanceMode,
            'installed_plugins' => [
                ['id' => 'sh-shp-survey-js', 'version' => '0.2.20', 'compatible' => $pluginCompatible],
            ],
        ]);

        $updateService = $this->createStub(SystemUpdateService::class);
        $updateService->method('getStatus')->willReturn([
            'operation_id' => 'op_123',
            'status' => 'succeeded',
            'progress_percent' => 100,
        ]);
        // Default: a healthy manager loop (token configured, polled 30s before
        // the injected health-check clock 2026-06-08T18:00:00Z).
        $updateService->method('getManagerLoopInfo')->willReturn(
            $managerLoop ?? ['configured' => true, 'last_seen_at' => '2026-06-08T17:59:30+00:00'],
        );

        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('isReachable')->willReturn($registryReachable);

        return new SystemHealthService(
            $instance,
            $versionService,
            $updateService,
            $registry,
            $connection,
            $cache ?? new ArrayAdapter(),
            $mercureUrl,
            $mailerDsn,
            $messengerDsn,
            new \DateTimeImmutable('2026-06-08T18:00:00+00:00'),
        );
    }

    private function healthyConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): mixed => str_contains($sql, 'messenger_messages') ? '2' : 1
        );
        $connection->method('fetchAssociative')->willReturn(['started_at' => '2026-06-08 10:00:00', 'status' => 'done']);

        return $connection;
    }

    public function testHealthyInstanceReportsHealthyOverall(): void
    {
        $health = $this->makeService($this->healthyConnection())->getHealth();

        self::assertSame('healthy', $health['overall']);
        self::assertSame('inst-qa-health', $health['instance_id']);
        self::assertSame('0.1.0', $health['version']['selfhelp']);

        $byName = [];
        foreach ($health['components'] as $c) {
            $byName[$c['name']] = $c['status'];
        }
        self::assertSame('ok', $byName['database']);
        self::assertSame('ok', $byName['cache']);
        self::assertSame('not_configured', $byName['redis']);
        self::assertSame('ok', $byName['worker']);
        self::assertSame('ok', $byName['scheduler']);
        self::assertSame('ok', $byName['manager_loop']);
        self::assertSame('configured', $byName['mercure']);
        self::assertSame('configured', $byName['mailer']);
        self::assertSame('ok', $byName['registry']);
        self::assertSame('ok', $byName['plugins']);
    }

    public function testWorkerProbesTheRealPluginOpsTransportAndIsNotFalselyNotConfigured(): void
    {
        // Regression: the worker health probe now reads the SINGLE real worker
        // transport (MESSENGER_PLUGIN_OPS_DSN, doctrine default) instead of a
        // never-set MESSENGER_TRANSPORT_DSN alias. A normally-installed instance
        // must therefore report the worker as running — not the old false
        // "not_configured" that showed on every maintenance page.
        $health = $this->makeService(
            $this->healthyConnection(),
            messengerDsn: 'doctrine://default?queue_name=plugin_ops&auto_setup=true',
        )->getHealth();

        $worker = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'worker'))[0];
        self::assertSame('ok', $worker['status']);
        self::assertStringContainsString('queued', $worker['detail']);
        self::assertNotSame('not_configured', $worker['status']);
    }

    public function testManagerLoopNotConfiguredIsInformationalButExplains(): void
    {
        $health = $this->makeService(
            $this->healthyConnection(),
            managerLoop: ['configured' => false, 'last_seen_at' => null],
        )->getHealth();

        // Not configured = informational (a CLI-managed instance still works);
        // the detail must spell out that CMS-requested updates will not run.
        self::assertSame('healthy', $health['overall']);
        $loop = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'manager_loop'))[0];
        self::assertSame('not_configured', $loop['status']);
        self::assertStringContainsString('SELFHELP_MANAGER_TOKEN', $loop['detail']);
        self::assertStringContainsString('will not execute', $loop['detail']);
    }

    public function testManagerLoopNeverSeenIsDownAndDegradesInstance(): void
    {
        $health = $this->makeService(
            $this->healthyConnection(),
            managerLoop: ['configured' => true, 'last_seen_at' => null],
        )->getHealth();

        self::assertSame('degraded', $health['overall']);
        $loop = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'manager_loop'))[0];
        self::assertSame('down', $loop['status']);
        self::assertStringContainsString('no manager has ever polled', $loop['detail']);
    }

    public function testManagerLoopStalePollDegrades(): void
    {
        $health = $this->makeService(
            $this->healthyConnection(),
            // Last poll 2 hours before the injected clock — way beyond 10 min.
            managerLoop: ['configured' => true, 'last_seen_at' => '2026-06-08T16:00:00+00:00'],
        )->getHealth();

        self::assertSame('degraded', $health['overall']);
        $loop = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'manager_loop'))[0];
        self::assertSame('degraded', $loop['status']);
        self::assertStringContainsString('stopped', $loop['detail']);
    }

    public function testRedisReportedOkWhenDevProfilerWrapsRedisBackedCachePool(): void
    {
        // Regression: in dev the profiler decorates cache.app with
        // TraceableAdapter, which used to hide the Redis backend and made the
        // admin system page show "not_configured" despite Redis serving the cache.
        $wrapped = new TraceableAdapter(new QaRedisFlavouredArrayAdapter());
        $health = $this->makeService($this->healthyConnection(), cache: $wrapped)->getHealth();

        $redis = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'redis'))[0];
        self::assertSame('ok', $redis['status']);
        self::assertSame('Redis-backed cache reachable.', $redis['detail']);
    }

    public function testUnreachableRegistryIsInformationalAndDoesNotDegradeInstance(): void
    {
        $health = $this->makeService($this->healthyConnection(), registryReachable: false)->getHealth();

        // Plan invariant 20: a registry outage must NOT mark the instance degraded.
        self::assertSame('healthy', $health['overall']);

        $registry = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'registry'))[0];
        self::assertSame('unknown', $registry['status']);
        self::assertStringContainsString('last successful check', $registry['detail']);
        self::assertStringContainsString('unreachable', strtolower($registry['detail']));
    }

    public function testReachableRegistryReportsLastSuccessfulCheckTimestamp(): void
    {
        $health = $this->makeService($this->healthyConnection())->getHealth();

        $registry = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'registry'))[0];
        self::assertSame('ok', $registry['status']);
        self::assertStringContainsString('2026-06-08T18:00:00+00:00', $registry['detail']);
    }

    public function testDatabaseDownReportsOverallDown(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection refused'));
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('connection refused'));

        $health = $this->makeService($connection)->getHealth();

        self::assertSame('down', $health['overall']);
        $db = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'database'))[0];
        self::assertSame('down', $db['status']);
    }

    public function testMaintenanceModeDegradesOtherwiseHealthyInstance(): void
    {
        $health = $this->makeService($this->healthyConnection(), maintenanceMode: true)->getHealth();

        self::assertSame('degraded', $health['overall']);
        self::assertTrue($health['maintenance_mode']);
    }

    public function testIncompatiblePluginDegradesInstance(): void
    {
        $health = $this->makeService($this->healthyConnection(), pluginCompatible: false)->getHealth();

        self::assertSame('degraded', $health['overall']);
        $plugins = array_values(array_filter($health['components'], static fn (array $c): bool => $c['name'] === 'plugins'))[0];
        self::assertSame('degraded', $plugins['status']);
    }

    public function testUnconfiguredMercureAndMailerAreNotConfiguredButNotDegrading(): void
    {
        $health = $this->makeService($this->healthyConnection(), mercureUrl: '', mailerDsn: '')->getHealth();

        self::assertSame('healthy', $health['overall']);
        $byName = [];
        foreach ($health['components'] as $c) {
            $byName[$c['name']] = $c['status'];
        }
        self::assertSame('not_configured', $byName['mercure']);
        self::assertSame('not_configured', $byName['mailer']);
    }

    public function testPayloadNeverLeaksConfiguredDsnValues(): void
    {
        $secretDsn = 'smtp://user:SUPERSECRET@mail:1025';
        $health = $this->makeService(
            $this->healthyConnection(),
            mailerDsn: $secretDsn,
            messengerDsn: 'redis://default:REDISSECRET@redis:6379/messages',
        )->getHealth();

        self::assertStringNotContainsString('SUPERSECRET', json_encode($health, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('REDISSECRET', json_encode($health, JSON_THROW_ON_ERROR));
    }
}
