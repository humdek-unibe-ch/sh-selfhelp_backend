<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Versioning;

use App\Plugin\Versioning\PluginCompatibility;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the documented semantics of the single backend compatibility helper
 * (audit fix #5) so the rule can never drift between the version summary, the
 * core-update preflight, the registry resolver, and the manifest validator. The
 * exact contract is documented in
 * `docs/developer/26-plugin-compatibility-rules.md`.
 */
#[Group('plugin')]
final class PluginCompatibilityTest extends TestCase
{
    public function testCoreSatisfiedTreatsEmptyOrNullRangeAsUnconstrained(): void
    {
        self::assertTrue(PluginCompatibility::coreSatisfied('0.1.0', null));
        self::assertTrue(PluginCompatibility::coreSatisfied('0.1.0', ''));
        self::assertTrue(PluginCompatibility::coreSatisfied('0.1.0', '  '));
        self::assertTrue(PluginCompatibility::coreSatisfied('99.9.9', '*'));
    }

    public function testCoreSatisfiedHonoursPre1xMinorBoundary(): void
    {
        // Pre-1.0 every MINOR is breaking: >=0.1.0 <0.2.0 admits patches but not 0.2.0.
        self::assertTrue(PluginCompatibility::coreSatisfied('0.1.5', '>=0.1.0 <0.2.0'));
        self::assertFalse(PluginCompatibility::coreSatisfied('0.2.0', '>=0.1.0 <0.2.0'));
    }

    public function testPluginApiSatisfiedMirrorsCoreAxis(): void
    {
        self::assertTrue(PluginCompatibility::pluginApiSatisfied('0.1.0', null));
        self::assertTrue(PluginCompatibility::pluginApiSatisfied('0.1.0', '>=0.1.0 <0.2.0'));
        self::assertFalse(PluginCompatibility::pluginApiSatisfied('0.2.0', '>=0.1.0 <0.2.0'));
    }

    public function testManifestCoreRangePrefersSelfhelpThenFallsBackToCore(): void
    {
        self::assertSame(
            '>=0.1.0 <0.2.0',
            PluginCompatibility::manifestCoreRange(['compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0']]),
            'author manifest field compatibility.selfhelp wins',
        );
        self::assertSame(
            '>=0.1.0 <0.3.0',
            PluginCompatibility::manifestCoreRange(['compatibility' => ['core' => '>=0.1.0 <0.3.0']]),
            'registry release field compatibility.core is the fallback',
        );
        self::assertSame(
            '>=0.1.0 <0.2.0',
            PluginCompatibility::manifestCoreRange([
                'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'core' => '>=0.1.0 <0.9.0'],
            ]),
            'selfhelp takes precedence when both are present',
        );
        self::assertNull(PluginCompatibility::manifestCoreRange([]));
        self::assertNull(PluginCompatibility::manifestCoreRange(['compatibility' => ['pluginApi' => '>=0.1.0']]));
    }

    public function testManifestPluginApiRangePrefersTopLevelThenCompatibility(): void
    {
        self::assertSame(
            '>=0.1.0 <0.2.0',
            PluginCompatibility::manifestPluginApiRange(['pluginApiVersion' => '>=0.1.0 <0.2.0']),
            'author manifest top-level pluginApiVersion wins',
        );
        self::assertSame(
            '>=0.1.0 <0.5.0',
            PluginCompatibility::manifestPluginApiRange(['compatibility' => ['pluginApi' => '>=0.1.0 <0.5.0']]),
            'registry release field compatibility.pluginApi is the fallback',
        );
        self::assertNull(PluginCompatibility::manifestPluginApiRange(['compatibility' => ['selfhelp' => '>=0.1.0']]));
    }

    public function testIsManifestCoreCompatibleCombinesResolutionAndRangeCheck(): void
    {
        $manifest = ['compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0']];

        self::assertTrue(PluginCompatibility::isManifestCoreCompatible($manifest, '0.1.9'));
        self::assertFalse(PluginCompatibility::isManifestCoreCompatible($manifest, '0.2.0'));
        // A manifest that declares no core range opts out of the gate.
        self::assertTrue(PluginCompatibility::isManifestCoreCompatible([], '0.2.0'));
    }
}
