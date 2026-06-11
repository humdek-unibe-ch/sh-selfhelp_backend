<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Service\System\MaintenanceModeService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the persistent maintenance-mode switch (MEDIUM 4).
 *
 * Asserts the observable contract: the file-backed toggle survives across
 * service instances, the env hard switch forces it on and cannot be cleared by
 * disable(), the state carries the operator note + audit fields, and a corrupt
 * state file still reports enabled with empty fields (never throws).
 */
final class MaintenanceModeServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/shqa-maint-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $lock = $this->projectDir . '/var/maintenance_mode.lock';
        if (is_file($lock)) {
            @unlink($lock);
        }
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    public function testDefaultsToDisabledWhenNoFileAndNoEnv(): void
    {
        $service = new MaintenanceModeService($this->projectDir, false);

        self::assertFalse($service->isEnabled());
        self::assertFalse($service->isForcedByEnv());
        $state = $service->getState();
        self::assertFalse($state['enabled']);
        self::assertSame('', $state['message']);
        self::assertSame('', $state['since']);
        self::assertSame('', $state['updated_by']);
    }

    public function testEnablePersistsAcrossInstances(): void
    {
        (new MaintenanceModeService($this->projectDir, false))->enable('Upgrading to 8.1.0', 'user:42');

        // A fresh instance (simulating a later request/restart) sees the state.
        $reloaded = new MaintenanceModeService($this->projectDir, false);
        self::assertTrue($reloaded->isEnabled());

        $state = $reloaded->getState();
        self::assertTrue($state['enabled']);
        self::assertFalse($state['forced_by_env']);
        self::assertSame('Upgrading to 8.1.0', $state['message']);
        self::assertSame('user:42', $state['updated_by']);
        self::assertNotSame('', $state['since']);
    }

    public function testDisableRemovesFileBackedState(): void
    {
        $service = new MaintenanceModeService($this->projectDir, false);
        $service->enable('msg', 'user:1');
        self::assertTrue($service->isEnabled());

        $state = $service->disable();
        self::assertFalse($state['enabled']);
        self::assertFalse((new MaintenanceModeService($this->projectDir, false))->isEnabled());
    }

    public function testEnvForcedIsEnabledAndNotClearableByDisable(): void
    {
        $service = new MaintenanceModeService($this->projectDir, true);

        self::assertTrue($service->isEnabled());
        self::assertTrue($service->isForcedByEnv());

        // disable() removes any file but the env switch keeps it on.
        $state = $service->disable();
        self::assertTrue($state['enabled']);
        self::assertTrue($state['forced_by_env']);
        self::assertSame('server-config', $state['updated_by']);
    }

    public function testCorruptStateFileReportsEnabledWithEmptyFields(): void
    {
        $lock = $this->projectDir . '/var/maintenance_mode.lock';
        @mkdir(dirname($lock), 0o775, true);
        file_put_contents($lock, 'this is not json{');

        $service = new MaintenanceModeService($this->projectDir, false);
        self::assertTrue($service->isEnabled(), 'A present (even corrupt) lock file means maintenance is on.');
        $state = $service->getState();
        self::assertTrue($state['enabled']);
        self::assertSame('', $state['message']);
    }
}
