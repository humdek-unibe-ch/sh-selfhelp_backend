<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Repository\Plugin\PluginRepository;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemVersionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Version reconciliation regression: nothing has shipped yet, so every version
 * axis the CMS surfaces (core/backend/frontend/plugin-API) starts at `0.1.0`.
 * The version service must report the server-derived instance facts verbatim.
 */
final class SystemVersionServiceTest extends TestCase
{
    public function testReportsReconciledZeroOneZeroVersionAxes(): void
    {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getInstanceId')->willReturn('inst-qa-version');
        $instance->method('getCmsVersion')->willReturn('0.1.0');
        $instance->method('getFrontendVersion')->willReturn('0.1.0');
        $instance->method('getPluginApiVersion')->willReturn('0.1.0');
        $instance->method('isSafeMode')->willReturn(false);
        $instance->method('isMaintenanceMode')->willReturn(false);

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('DoctrineMigrations\\Version20260608181148');

        $version = (new SystemVersionService($instance, $plugins, $connection))->getVersion();

        self::assertSame('inst-qa-version', $version['instance_id']);
        self::assertSame('0.1.0', $version['selfhelp_version']);
        self::assertSame('0.1.0', $version['backend_version']);
        self::assertSame('0.1.0', $version['frontend_version']);
        self::assertSame('0.1.0', $version['plugin_api_version']);
        self::assertSame('Version20260608181148', $version['database_migration_version']);
        self::assertSame([], $version['installed_plugins']);
    }
}
