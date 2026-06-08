<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Repository\Plugin\PluginRepository;
use App\Repository\System\SystemUpdateOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryGatewayInterface;
use App\Service\System\SystemUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression guard for the distribution plan's "Backup And Rollback" policy
 * (CRITICAL 4): automatic rollback is ONLY safe before migrations. The preflight
 * must NEVER advertise automatic rollback after destructive migrations, otherwise
 * the maintenance UI would mislead operators into skipping a verified backup.
 */
final class SystemUpdateServiceRollbackPolicyTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $coreRelease
     */
    private function makeService(?array $coreRelease): SystemUpdateService
    {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn('8.0.0');
        $instance->method('getInstanceId')->willReturn('inst-qa-rollback');

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $registry = $this->createStub(SystemRegistryGatewayInterface::class);
        $registry->method('fetchCoreRelease')->willReturn($coreRelease);

        return new SystemUpdateService(
            $instance,
            $this->createStub(SystemUpdateOperationRepository::class),
            $plugins,
            $registry,
            $this->createStub(UserContextService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    public function testDestructiveUpdateNeverPromisesAutomaticRollbackAfterMigrations(): void
    {
        $service = $this->makeService([
            'database' => [
                'destructive' => true,
                'requiresBackup' => true,
                'manualConfirmationRequired' => true,
            ],
        ]);

        $preflight = $service->getPreflight('8.0.1');

        self::assertIsArray($preflight['rollback']);
        self::assertTrue($preflight['rollback']['automatic_before_migrations']);
        self::assertFalse(
            $preflight['rollback']['automatic_after_destructive_migrations'],
            'Automatic rollback must never be promised after destructive migrations.',
        );

        // Sanity: the destructive-migration path was actually exercised.
        self::assertIsArray($preflight['database']);
        self::assertTrue($preflight['database']['destructive']);
        self::assertContains(
            SystemUpdateService::CHECK_DESTRUCTIVE_MIGRATION,
            array_map(static fn (array $c): string => $c['code'], $preflight['checks']),
        );
    }

    public function testRollbackPolicyHoldsWhenRegistryUnreachable(): void
    {
        $service = $this->makeService(null);

        $preflight = $service->getPreflight('8.0.1');

        self::assertIsArray($preflight['rollback']);
        self::assertFalse($preflight['rollback']['automatic_after_destructive_migrations']);
    }
}
