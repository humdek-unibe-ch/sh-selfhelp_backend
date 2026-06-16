<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Plugin\Registry\Unified\CoreImageRef;
use App\Plugin\Registry\Unified\CoreRelease;
use App\Plugin\Registry\Unified\SignatureBlock;
use App\Repository\Plugin\PluginRepository;
use App\Repository\System\SystemUpdateOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryReader;
use App\Service\System\SystemUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Regression guard for the distribution plan's "Backup And Rollback" policy
 * (CRITICAL 4): automatic rollback is ONLY safe before migrations. The preflight
 * must NEVER advertise automatic rollback after destructive migrations, otherwise
 * the maintenance UI would mislead operators into skipping a verified backup.
 */
final class SystemUpdateServiceRollbackPolicyTest extends TestCase
{
    private function makeService(?CoreRelease $coreRelease): SystemUpdateService
    {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn('0.1.0');
        $instance->method('getInstanceId')->willReturn('inst-qa-rollback');

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('getCoreRelease')->willReturn($coreRelease);

        return new SystemUpdateService(
            $instance,
            $this->createStub(SystemUpdateOperationRepository::class),
            $plugins,
            $registry,
            $this->createStub(UserContextService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new ArrayAdapter(),
        );
    }

    private function destructiveRelease(): CoreRelease
    {
        $digest = 'sha256:' . str_repeat('c', 64);

        return new CoreRelease(
            id: 'selfhelp-core',
            version: '0.1.1',
            channel: 'stable',
            minimumDirectUpgradeFrom: '0.1.0',
            pluginApiVersion: '0.1.0',
            backend: new CoreImageRef('ghcr.io/selfhelp/backend', $digest),
            worker: new CoreImageRef('ghcr.io/selfhelp/worker', $digest),
            scheduler: new CoreImageRef('ghcr.io/selfhelp/scheduler', $digest),
            requiredFrontendRange: '>=0.1.0 <0.2.0',
            migrationRange: '>0.1.0 <=0.1.1',
            destructive: true,
            requiresBackup: true,
            manualConfirmationRequired: true,
            security: new SignatureBlock('c2ln', 'selfhelp-dev-fixture'),
            blocked: false,
            raw: [],
        );
    }

    public function testDestructiveUpdateNeverPromisesAutomaticRollbackAfterMigrations(): void
    {
        $service = $this->makeService($this->destructiveRelease());

        $preflight = $service->getPreflight('0.1.1');

        self::assertIsArray($preflight['rollback']);
        self::assertTrue($preflight['rollback']['automatic_before_migrations']);
        self::assertFalse(
            $preflight['rollback']['automatic_after_destructive_migrations'],
            'Automatic rollback must never be promised after destructive migrations.',
        );

        // Sanity: the destructive-migration path was actually exercised.
        self::assertIsArray($preflight['database']);
        self::assertTrue($preflight['database']['destructive']);
        self::assertIsArray($preflight['checks']);
        $codes = [];
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            $code = $check['code'] ?? null;
            self::assertIsString($code);
            $codes[] = $code;
        }
        self::assertContains(SystemUpdateService::CHECK_DESTRUCTIVE_MIGRATION, $codes);
    }

    public function testRollbackPolicyHoldsWhenRegistryUnreachable(): void
    {
        $service = $this->makeService(null);

        $preflight = $service->getPreflight('0.1.1');

        self::assertIsArray($preflight['rollback']);
        self::assertFalse($preflight['rollback']['automatic_after_destructive_migrations']);
    }
}
