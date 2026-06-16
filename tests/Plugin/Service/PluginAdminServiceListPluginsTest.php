<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Service;

use App\Entity\Plugin\Plugin;
use App\Plugin\Service\PluginAdminService;
use App\Repository\Plugin\PluginRepository;
use App\Repository\Plugin\PluginSourceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression: the admin installed-plugins list must survive a single
 * inconsistent / half-removed plugin row.
 *
 * During a manager-driven uninstall/update the backend restarts; for a brief
 * window a plugin row can be out of sync with its on-disk bundle (its
 * `composer remove` ran, or its manifest is momentarily unreadable). Before
 * this fix one bad row made `formatPlugin()` throw and 500'd the WHOLE list,
 * stranding the operator on a dead "Failed to load plugins" screen with no way
 * to repair or uninstall anything. `listPlugins()` now formats each row
 * defensively: the bad row is skipped (and logged) while every healthy plugin
 * still renders.
 *
 * Built with `newInstanceWithoutConstructor()` + reflection injection of only
 * the collaborators this read path touches, so it stays a fast unit test (no
 * kernel, no DB).
 */
#[Group('plugin')]
final class PluginAdminServiceListPluginsTest extends TestCase
{
    public function testListPluginsSkipsAHalfRemovedRowInsteadOfFailingTheWholeList(): void
    {
        $service = $this->makeService([$this->healthyPlugin('qa-good'), $this->brokenPlugin('qa-broken')]);

        $rows = $service->listPlugins();

        self::assertCount(1, $rows, 'the broken row is skipped, the healthy one survives');
        self::assertSame('qa-good', $rows[0]['pluginId']);
        self::assertNull($rows[0]['availableUpdate']);
    }

    public function testListPluginsReturnsEmptyWhenEveryRowIsBroken(): void
    {
        $rows = $this->makeService([$this->brokenPlugin('qa-broken')])->listPlugins();

        self::assertSame([], $rows);
    }

    /**
     * @param list<Plugin> $installed
     */
    private function makeService(array $installed): PluginAdminService
    {
        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn($installed);
        $plugins->method('findAll')->willReturn($installed);

        // No registry sources → `listAvailableUpdates()` is a cheap no-op, so
        // the test isolates the per-row formatting resilience.
        $sources = $this->createStub(PluginSourceRepository::class);
        $sources->method('findEnabled')->willReturn([]);

        $service = (new \ReflectionClass(PluginAdminService::class))->newInstanceWithoutConstructor();
        $this->inject($service, 'plugins', $plugins);
        $this->inject($service, 'sources', $sources);
        $this->inject($service, 'logger', new NullLogger());

        return $service;
    }

    /**
     * A row whose data access blows up mid-format — e.g. its bundle was
     * half-removed by a `composer remove` during a manager-driven restart, so
     * an accessor throws. `getPluginId()` still answers so the skip can be
     * logged. (Not `getInstalledAt()` alone: PHPUnit auto-stubs its
     * `DateTimeImmutable` return, so we make the accessor throw explicitly.)
     */
    private function brokenPlugin(string $pluginId): Plugin
    {
        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getPluginId')->willReturn($pluginId);
        $plugin->method('isPinned')->willReturn(false);
        $plugin->method('getInstalledAt')->willThrowException(new \RuntimeException('bundle is half-removed'));

        return $plugin;
    }

    private function healthyPlugin(string $pluginId): Plugin
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getId')->willReturn(1);
        $plugin->method('getPluginId')->willReturn($pluginId);
        $plugin->method('getName')->willReturn($pluginId);
        $plugin->method('getVersion')->willReturn('1.0.0');
        $plugin->method('getTrustLevel')->willReturn('official');
        $plugin->method('getInstallMode')->willReturn('managed');
        $plugin->method('isEnabled')->willReturn(true);
        $plugin->method('isPinned')->willReturn(false);
        // Non-nullable timestamps `formatPlugin()` dereferences with ->format().
        $plugin->method('getInstalledAt')->willReturn($now);
        $plugin->method('getUpdatedAt')->willReturn($now);

        return $plugin;
    }

    private function inject(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setValue($target, $value);
    }
}
