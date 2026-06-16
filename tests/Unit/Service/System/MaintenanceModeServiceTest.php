<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Service\Cache\Core\CacheService;
use App\Service\System\MaintenanceModeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

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

    /**
     * Regression for #4: editing/enabling maintenance must drop the rendered
     * `pages` + `sections` caches, otherwise the public page keeps serving the
     * previously baked `{{system.maintenance_message}}` value.
     */
    public function testEnableInvalidatesRenderedPageAndSectionCaches(): void
    {
        $cache = new CacheService(new TagAwareAdapter(new ArrayAdapter()));
        $this->seedRenderedCaches($cache);

        (new MaintenanceModeService($this->projectDir, false, $cache))->enable('Upgrading to 8.1.0', 'user:42');

        self::assertSame('FRESH', $cache->withCategory(CacheService::CATEGORY_PAGES)->getList('maint_probe', fn() => 'FRESH'));
        self::assertSame('FRESH', $cache->withCategory(CacheService::CATEGORY_SECTIONS)->getList('maint_probe', fn() => 'FRESH'));
    }

    /**
     * Regression for #4: disabling maintenance must likewise re-render, so the
     * "we're back" page does not keep showing the maintenance banner.
     */
    public function testDisableInvalidatesRenderedPageAndSectionCaches(): void
    {
        $cache = new CacheService(new TagAwareAdapter(new ArrayAdapter()));
        $service = new MaintenanceModeService($this->projectDir, false, $cache);
        $service->enable('msg', 'user:1');
        $this->seedRenderedCaches($cache);

        $service->disable();

        self::assertSame('FRESH', $cache->withCategory(CacheService::CATEGORY_PAGES)->getList('maint_probe', fn() => 'FRESH'));
        self::assertSame('FRESH', $cache->withCategory(CacheService::CATEGORY_SECTIONS)->getList('maint_probe', fn() => 'FRESH'));
    }

    /**
     * Seed both rendered-content categories with a stale value and assert the
     * cache actually serves it back (control), so a later "FRESH" read proves
     * invalidation rather than a non-caching no-op.
     */
    private function seedRenderedCaches(CacheService $cache): void
    {
        foreach ([CacheService::CATEGORY_PAGES, CacheService::CATEGORY_SECTIONS] as $category) {
            $cache->withCategory($category)->getList('maint_probe', fn() => 'STALE');
            self::assertSame(
                'STALE',
                $cache->withCategory($category)->getList('maint_probe', fn() => 'FRESH'),
                "Cache category {$category} must serve the cached value before invalidation.",
            );
        }
    }
}
