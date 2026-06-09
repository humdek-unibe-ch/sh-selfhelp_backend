<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\Plugin\Plugin;
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
 * Core-update preflight regression for the reconciled `0.1.0` ecosystem
 * (distribution plan: "Core update preflight must check installed plugins").
 *
 * Pre-1.0 every MINOR is breaking, so a plugin pinned to `>=0.1.0 <0.2.0`:
 *   - stays compatible across a core PATCH (`0.1.0 -> 0.1.1`) -> update allowed;
 *   - blocks a core MINOR (`0.1.0 -> 0.2.0`) -> update blocked with a visible,
 *     standardized compatibility-error object naming the plugin + required range.
 */
final class SystemUpdateServicePreflightTest extends TestCase
{
    private function makeService(bool $pinned = false): SystemUpdateService
    {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn('0.1.0');
        $instance->method('getInstanceId')->willReturn('inst-qa-preflight');

        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getPluginId')->willReturn('sh2-shp-survey-js');
        $plugin->method('getVersion')->willReturn('0.1.0');
        $plugin->method('isPinned')->willReturn($pinned);
        $plugin->method('getManifestJson')->willReturn([
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0'],
        ]);

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([$plugin]);

        $registry = $this->createStub(SystemRegistryGatewayInterface::class);
        // A published core release with non-destructive migrations for the target.
        $registry->method('fetchCoreRelease')->willReturn([
            'database' => ['destructive' => false, 'requiresBackup' => true, 'manualConfirmationRequired' => false],
        ]);

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

    public function testCompatiblePluginAllowsCorePatchUpdate(): void
    {
        $preflight = $this->makeService()->getPreflight('0.1.1');

        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertNotContains(
            SystemUpdateService::CHECK_PLUGIN_COMPATIBILITY,
            $this->checkCodes($preflight),
            'A compatible plugin must not raise a plugin_compatibility check.',
        );
    }

    public function testIncompatiblePluginBlocksCoreMinorUpdate(): void
    {
        $preflight = $this->makeService()->getPreflight('0.2.0');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);

        self::assertIsArray($preflight['checks']);
        $compat = null;
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            if (($check['code'] ?? null) === SystemUpdateService::CHECK_PLUGIN_COMPATIBILITY) {
                $compat = $check;
            }
        }

        self::assertNotNull($compat, 'An incompatible plugin must raise a plugin_compatibility check.');
        self::assertSame('error', $compat['severity'] ?? null);
        self::assertSame('plugin', $compat['component'] ?? null);
        self::assertSame('sh2-shp-survey-js', $compat['component_id'] ?? null);
        self::assertSame('0.1.0', $compat['current_version'] ?? null);
        self::assertSame('0.2.0', $compat['target_version'] ?? null);
        self::assertSame('>=0.1.0 <0.2.0', $compat['required_range'] ?? null);
        // Standardized compatibility-error shape: the same `blocking` flag the
        // plugin install/update flow emits.
        self::assertTrue($compat['blocking'] ?? null);
        self::assertFalse($compat['pinned'] ?? null, 'An unpinned plugin reports pinned=false.');
        self::assertIsString($compat['message'] ?? null);
        self::assertStringContainsString('sh2-shp-survey-js', (string) $compat['message']);
    }

    public function testPinnedIncompatiblePluginBlocksCoreUpdateWithUnpinHint(): void
    {
        // A pinned plugin is never auto-updated (audit #52), so a pinned plugin
        // that is incompatible with the target core version is a hard block whose
        // reason tells the operator to unpin first.
        $preflight = $this->makeService(pinned: true)->getPreflight('0.2.0');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertIsArray($preflight['checks']);
        $compat = null;
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            if (($check['code'] ?? null) === SystemUpdateService::CHECK_PLUGIN_COMPATIBILITY) {
                $compat = $check;
            }
        }

        self::assertNotNull($compat, 'A pinned incompatible plugin must raise a plugin_compatibility check.');
        self::assertTrue($compat['pinned'] ?? null, 'The blocking plugin is reported as pinned.');
        self::assertTrue($compat['blocking'] ?? null);
        self::assertIsString($compat['message'] ?? null);
        self::assertStringContainsString('pinned', (string) $compat['message']);
    }

    /**
     * @param array<string,mixed> $preflight
     * @return list<string>
     */
    private function checkCodes(array $preflight): array
    {
        $codes = [];
        self::assertIsArray($preflight['checks']);
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            $code = $check['code'] ?? null;
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
