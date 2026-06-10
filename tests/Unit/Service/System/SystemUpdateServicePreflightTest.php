<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\Plugin\Plugin;
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

/**
 * Core-update preflight regression for the reconciled `0.1.0` ecosystem
 * (distribution plan: "Core update preflight must check installed plugins").
 *
 * Also pins the SIGNED preflight path (audit fix #2): the service now reads the
 * SAME signature-verified {@see CoreRelease} the plugin flow uses (via
 * {@see SystemRegistryReader}) instead of an unsigned array gateway. The
 * destructive-migration + registry-unreachable + never-updated behaviours are
 * asserted on that typed contract.
 *
 * Pre-1.0 every MINOR is breaking, so a plugin pinned to `>=0.1.0 <0.2.0`:
 *   - stays compatible across a core PATCH (`0.1.0 -> 0.1.1`) -> update allowed;
 *   - blocks a core MINOR (`0.1.0 -> 0.2.0`) -> update blocked with a visible,
 *     standardized compatibility-error object naming the plugin + required range.
 */
final class SystemUpdateServicePreflightTest extends TestCase
{
    /**
     * A signed, signature-verified core release as the reader would return it.
     */
    private function coreRelease(bool $destructive = false, string $minimumDirectUpgradeFrom = '0.1.0'): CoreRelease
    {
        $digest = 'sha256:' . str_repeat('a', 64);

        return new CoreRelease(
            id: 'selfhelp-core',
            version: '0.1.1',
            channel: 'stable',
            minimumDirectUpgradeFrom: $minimumDirectUpgradeFrom,
            pluginApiVersion: '0.1.0',
            backend: new CoreImageRef('ghcr.io/selfhelp/backend', $digest),
            worker: new CoreImageRef('ghcr.io/selfhelp/worker', $digest),
            scheduler: new CoreImageRef('ghcr.io/selfhelp/scheduler', $digest),
            requiredFrontendRange: '>=0.1.0 <0.2.0',
            migrationRange: '>0.1.0 <=0.1.1',
            destructive: $destructive,
            requiresBackup: true,
            manualConfirmationRequired: false,
            security: new SignatureBlock('c2ln', 'selfhelp-official-2026'),
            blocked: false,
            raw: [],
        );
    }

    private function makeService(
        bool $pinned = false,
        ?CoreRelease $release = null,
        ?SystemUpdateOperationRepository $operations = null,
    ): SystemUpdateService {
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

        $registry = $this->createStub(SystemRegistryReader::class);
        // Default: a published, signed core release with non-destructive migrations.
        $registry->method('getCoreRelease')->willReturn($release ?? $this->coreRelease());

        return new SystemUpdateService(
            $instance,
            $operations ?? $this->createStub(SystemUpdateOperationRepository::class),
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

    public function testDestructiveSignedReleaseRaisesDestructiveMigrationWarning(): void
    {
        $preflight = $this->makeService(release: $this->coreRelease(destructive: true))->getPreflight('0.1.1');

        self::assertContains(
            SystemUpdateService::CHECK_DESTRUCTIVE_MIGRATION,
            $this->checkCodes($preflight),
            'A destructive signed core release must surface the destructive_migration warning.',
        );
        self::assertIsArray($preflight['database']);
        self::assertTrue($preflight['database']['destructive'] ?? null);
    }

    public function testUnreachableRegistryDegradesToWarningNotBlock(): void
    {
        // getCoreRelease() returns null both when the registry is offline AND when
        // a tampered/unsigned core release fails verification: either way the
        // preflight must degrade to a warning (the Manager re-validates), never a
        // silent trust of unsigned metadata.
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn('0.1.0');
        $instance->method('getInstanceId')->willReturn('inst-qa-preflight');

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('getCoreRelease')->willReturn(null);

        $service = new SystemUpdateService(
            $instance,
            $this->createStub(SystemUpdateOperationRepository::class),
            $plugins,
            $registry,
            $this->createStub(UserContextService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        $preflight = $service->getPreflight('0.1.1');

        self::assertContains(SystemUpdateService::CHECK_REGISTRY_UNREACHABLE, $this->checkCodes($preflight));
        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
    }

    public function testNeverUpdatedInstanceReportsIdleStatusNotFakeSuccess(): void
    {
        // Audit fix #7: an instance that has NEVER had an update operation must
        // report an honest "idle" state at progress 0, not a phantom
        // "succeeded / 100%".
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestForInstance')->willReturn(null);

        $status = $this->makeService(operations: $operations)->getStatus();

        self::assertSame(SystemUpdateService::STATUS_IDLE, $status['status']);
        self::assertSame(0, $status['progress_percent']);
        self::assertSame('', $status['operation_id']);
        self::assertSame('0.1.0', $status['target_version']);
        self::assertIsString($status['message']);
        self::assertStringContainsString('0.1.0', (string) $status['message']);
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
