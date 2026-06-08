<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Versioning;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version-scheme reconciliation regression (SelfHelp Manager / Docker
 * Distribution MVP, audit CRITICAL 5).
 *
 * The official SurveyJS plugin declares `compatibility.selfhelp:
 * ">=8.0.0-dev <9.0.0"` and `pluginApiVersion: "1.1"`. These tests pin that
 * the plugin resolves against the CURRENT core version scheme (`8.0.0-dev` +
 * SDK `1.1`) and that the OLD `1.x` core scheme is correctly rejected — i.e.
 * the update/install preflight no longer compares incompatible `8.x` vs `1.x`.
 */
#[Group('plugin')]
final class PluginCompatibilityValidatorTest extends TestCase
{
    /**
     * Mirrors the real `plugins/sh2-shp-survey-js/plugin.json` compatibility
     * block (the reference official plugin).
     */
    private function officialPluginManifest(): PluginManifest
    {
        return new PluginManifest([
            'id' => 'sh2-shp-survey-js',
            'version' => '0.2.20',
            'pluginApiVersion' => '1.1',
            'compatibility' => ['selfhelp' => '>=8.0.0-dev <9.0.0'],
        ]);
    }

    public function testOfficialPluginResolvesAgainstCurrentCoreVersion(): void
    {
        $validator = new PluginCompatibilityValidator('8.0.0-dev', '1.1');

        $report = $validator->check($this->officialPluginManifest());

        self::assertTrue($report['compatible']);
        self::assertSame('ok', $report['severity']);
        self::assertSame([], $report['reasons']);
        self::assertSame('ok', $report['checks']['cms']['status']);
        self::assertSame('ok', $report['checks']['sdk']['status']);
    }

    public function testOfficialPluginResolvesAgainstStableEightZero(): void
    {
        $validator = new PluginCompatibilityValidator('8.0.0', '1.1');

        $report = $validator->check($this->officialPluginManifest());

        self::assertTrue($report['compatible']);
        self::assertSame('ok', $report['severity']);
    }

    public function testLegacyOnePointFiveCoreIsRejectedAsBlocking(): void
    {
        // The exact mismatch CRITICAL 5 fixes: core on the old 1.x scheme can
        // no longer satisfy the plugin's 8.x requirement.
        $validator = new PluginCompatibilityValidator('1.5.0', '1.1');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['cms']['status']);
        self::assertNotSame([], $report['reasons']);
    }

    public function testNextMajorCoreIsRejectedAsBlocking(): void
    {
        $validator = new PluginCompatibilityValidator('9.0.0', '1.1');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['cms']['status']);
    }

    public function testMismatchedSdkContractIsBlocking(): void
    {
        // The backend SDK contract token is matched exactly on major.minor;
        // a host SDK that is not the plugin's declared 1.1 is blocking.
        $validator = new PluginCompatibilityValidator('8.0.0-dev', '1.2');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['sdk']['status']);
    }
}
