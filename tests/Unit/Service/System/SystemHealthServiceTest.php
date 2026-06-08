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
use App\Service\System\SystemRegistryGatewayInterface;
use App\Service\System\SystemUpdateService;
use App\Service\System\SystemVersionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit coverage for the aggregated system health endpoint (HIGH 5).
 *
 * Asserts the observable contract: overall verdict derivation (down when the
 * DB is down, degraded under safe/maintenance mode or an incompatible plugin,
 * healthy otherwise) and that no secret/DSN value leaks into the payload.
 */
final class SystemHealthServiceTest extends TestCase
{
    private function makeService(
        Connection $connection,
        bool $safeMode = false,
        bool $maintenanceMode = false,
        bool $pluginCompatible = true,
        string $mercureUrl = 'http://mercure/.well-known/mercure',
        string $mailerDsn = 'smtp://mail:1025',
        string $messengerDsn = 'doctrine://default',
        bool $registryReachable = true,
    ): SystemHealthService {
        // env-forced maintenance mirrors the parameter; no file is touched.
        $maintenance = new MaintenanceModeService(sys_get_temp_dir() . '/shqa-health-no-maint', $maintenanceMode);
        $instance = new SystemInstanceService('inst-qa-health', '8.0.0', '2.1', '8.0.0', $safeMode, $maintenance);

        $versionService = $this->createStub(SystemVersionService::class);
        $versionService->method('getVersion')->willReturn([
            'instance_id' => 'inst-qa-health',
            'selfhelp_version' => '8.0.0',
            'backend_version' => '8.0.0',
            'frontend_version' => '8.0.0',
            'plugin_api_version' => '1.1',
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

        $registry = $this->createStub(SystemRegistryGatewayInterface::class);
        $registry->method('fetchIndex')->willReturn($registryReachable ? ['core' => []] : null);
        $registry->method('fetchCoreRelease')->willReturn(null);

        return new SystemHealthService(
            $instance,
            $versionService,
            $updateService,
            $registry,
            $connection,
            new ArrayAdapter(),
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
        self::assertSame('8.0.0', $health['version']['selfhelp']);

        $byName = [];
        foreach ($health['components'] as $c) {
            $byName[$c['name']] = $c['status'];
        }
        self::assertSame('ok', $byName['database']);
        self::assertSame('ok', $byName['cache']);
        self::assertSame('ok', $byName['worker']);
        self::assertSame('ok', $byName['scheduler']);
        self::assertSame('configured', $byName['mercure']);
        self::assertSame('configured', $byName['mailer']);
        self::assertSame('ok', $byName['registry']);
        self::assertSame('ok', $byName['plugins']);
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
