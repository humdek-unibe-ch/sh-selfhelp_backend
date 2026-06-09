<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\CompatibilityError;
use App\Plugin\Registry\Unified\PluginRelease;
use App\Plugin\Registry\Unified\PluginReleaseResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Multi-version plugin resolution for the unified registry.
 *
 * Covers the four ecosystem scenarios from the plan (mirroring the Manager
 * `@shm/resolver` `plugins.test.ts`) plus the standardized compatibility-error
 * shape:
 *
 *   1. a compatible plugin SURVIVES a core PATCH update;
 *   2. an incompatible plugin BLOCKS a core MINOR update (clear error);
 *   3. the NEWEST compatible version is selected by default;
 *   4. an OLDER compatible (e.g. pinned) version stays valid.
 */
#[Group('plugin')]
final class PluginReleaseResolverTest extends TestCase
{
    private PluginReleaseResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PluginReleaseResolver();
    }

    private function release(string $version, string $coreRange, string $apiRange = '>=0.1.0 <0.2.0', bool $blocked = false): PluginRelease
    {
        return PluginRelease::fromArray([
            'kind' => 'selfhelp-plugin-release',
            'id' => 'sh2-shp-survey-js',
            'version' => $version,
            'channel' => 'stable',
            'official' => true,
            'compatibility' => ['core' => $coreRange, 'pluginApi' => $apiRange],
            'artifacts' => [
                'manifestUrl' => 'https://r.test/m.json',
                'archiveUrl' => 'https://r.test/a.shplugin',
                'sha256' => 'sha256:' . str_repeat('0', 64),
            ],
            'security' => ['signature' => 'x', 'keyId' => 'k'],
            'blocked' => $blocked,
        ], 'test');
    }

    public function testCompatiblePluginSurvivesCorePatchUpdate(): void
    {
        $releases = [$this->release('0.1.0', '>=0.1.0 <0.2.0')];

        $resolution = $this->resolver->resolveLatestCompatible($releases, '0.1.1', '0.1.0', '0.1.0');

        self::assertNull($resolution->error);
        self::assertNotNull($resolution->selected);
        self::assertSame('0.1.0', $resolution->selected->version);
        self::assertSame(['0.1.0'], $resolution->compatibleVersions());
    }

    public function testIncompatiblePluginBlocksCoreMinorUpdateWithClearError(): void
    {
        $releases = [$this->release('0.1.0', '>=0.1.0 <0.2.0')];

        $resolution = $this->resolver->resolveLatestCompatible($releases, '0.2.0', '0.1.0', '0.1.0');

        self::assertNull($resolution->selected);
        self::assertFalse($resolution->hasCompatibleVersion());
        self::assertNotNull($resolution->error);
        self::assertTrue($resolution->error->blocking);
        self::assertSame(CompatibilityError::COMPONENT_PLUGIN, $resolution->error->component);
        self::assertSame('sh2-shp-survey-js', $resolution->error->componentId);
        self::assertSame('0.1.0', $resolution->error->currentVersion);
        self::assertStringContainsString('no published version compatible', $resolution->error->message);
    }

    public function testNewestCompatibleVersionIsSelectedByDefault(): void
    {
        $releases = [
            $this->release('0.1.0', '>=0.1.0 <0.2.0'),
            $this->release('0.2.0', '>=0.2.0 <0.3.0'),
        ];

        $resolution = $this->resolver->resolveLatestCompatible($releases, '0.2.0', '0.1.0');

        self::assertNotNull($resolution->selected);
        self::assertSame('0.2.0', $resolution->selected->version);
        self::assertSame(['0.2.0'], $resolution->compatibleVersions());
        self::assertSame(['0.1.0'], $resolution->incompatibleVersions());
    }

    public function testOlderCompatibleVersionStaysValidWhileNewerExistsButIsIncompatible(): void
    {
        $releases = [
            $this->release('0.1.0', '>=0.1.0 <0.2.0'),
            $this->release('0.2.0', '>=0.2.0 <0.3.0'),
        ];

        // Host still on core 0.1.x: only 0.1.0 is compatible, and the resolver
        // must report that a newer (incompatible) version exists.
        $resolution = $this->resolver->resolveLatestCompatible($releases, '0.1.0', '0.1.0', '0.1.0');

        self::assertNotNull($resolution->selected);
        self::assertSame('0.1.0', $resolution->selected->version);
        self::assertNotNull($resolution->latestOverall);
        self::assertSame('0.2.0', $resolution->latestOverall->version);
        self::assertTrue($resolution->newerExistsButIncompatible());
    }

    public function testPinnedOlderCompatibleVersionResolvesExplicitly(): void
    {
        $releases = [
            $this->release('0.1.0', '>=0.1.0 <0.2.0'),
            $this->release('0.2.0', '>=0.1.0 <0.2.0'), // also compatible on 0.1.x
        ];

        // A pin asks for 0.1.0 explicitly even though 0.2.0 is also compatible.
        $resolution = $this->resolver->resolveVersion($releases, '0.1.0', '0.1.5', '0.1.0', '0.1.0');

        self::assertNull($resolution->error);
        self::assertNotNull($resolution->selected);
        self::assertSame('0.1.0', $resolution->selected->version);
        // The newest compatible is still surfaced for the UI.
        self::assertNotNull($resolution->latestCompatible);
        self::assertSame('0.2.0', $resolution->latestCompatible->version);
    }

    public function testRequestedIncompatibleVersionIsBlockedWithStandardizedError(): void
    {
        $releases = [
            $this->release('0.1.0', '>=0.1.0 <0.2.0'),
            $this->release('0.2.0', '>=0.2.0 <0.3.0'),
        ];

        $resolution = $this->resolver->resolveVersion($releases, '0.2.0', '0.1.0', '0.1.0');

        self::assertNull($resolution->selected);
        self::assertNotNull($resolution->error);
        self::assertTrue($resolution->error->blocking);
        self::assertSame('0.2.0', $resolution->error->targetVersion);
        self::assertSame('>=0.2.0 <0.3.0', $resolution->error->requiredRange);
    }

    public function testRequestedUnknownVersionReturnsClearError(): void
    {
        $releases = [$this->release('0.1.0', '>=0.1.0 <0.2.0')];

        $resolution = $this->resolver->resolveVersion($releases, '9.9.9', '0.1.0', '0.1.0');

        self::assertNull($resolution->selected);
        self::assertNotNull($resolution->error);
        self::assertStringContainsString('no published version 9.9.9', $resolution->error->message);
    }

    public function testBlockedReleaseIsNeverCompatible(): void
    {
        $blocked = $this->release('0.1.0', '>=0.1.0 <0.2.0', '>=0.1.0 <0.2.0', true);

        self::assertFalse($this->resolver->isCompatible($blocked, '0.1.0', '0.1.0'));
    }

    public function testPluginApiAxisIsEnforced(): void
    {
        // Core matches, plugin API does not.
        $release = $this->release('0.1.0', '>=0.1.0 <0.2.0', '>=0.2.0 <0.3.0');

        $error = $this->resolver->compatibilityErrorFor($release, '0.1.0', '0.1.0', '0.1.0');

        self::assertNotNull($error);
        self::assertSame('>=0.2.0 <0.3.0', $error->requiredRange);
        self::assertStringContainsString('plugin API', $error->message);
    }
}
