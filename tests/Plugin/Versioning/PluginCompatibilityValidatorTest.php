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
 * Version-scheme reconciliation regression for the pre-release `0.1.0`
 * ecosystem (SelfHelp Manager / Docker Distribution MVP).
 *
 * Nothing has shipped yet, so every axis (core CMS, plugin SDK API) starts at
 * `0.1.0`. Per SemVer, a pre-1.0 MINOR bump (`0.1.x -> 0.2.0`) is breaking, so
 * the reference official plugin declares `compatibility.selfhelp:
 * ">=0.1.0 <0.2.0"` and `pluginApiVersion: "0.1.0"`. These tests pin that the
 * plugin resolves against the CURRENT `0.1.x` core + SDK scheme and that the
 * next MINOR/MAJOR core (and a mismatched SDK contract) are correctly blocked.
 */
#[Group('plugin')]
final class PluginCompatibilityValidatorTest extends TestCase
{
    /**
     * Mirrors the real `plugins/sh2-shp-survey-js/plugin.json` compatibility
     * block (the reference official plugin). The plugin keeps its own product
     * version (`0.2.20`); only the host-facing compatibility axes track the
     * reconciled `0.1.x` scheme.
     */
    private function officialPluginManifest(): PluginManifest
    {
        return new PluginManifest([
            'id' => 'sh2-shp-survey-js',
            'version' => '0.2.20',
            'pluginApiVersion' => '0.1.0',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0'],
        ]);
    }

    public function testOfficialPluginResolvesAgainstCurrentCoreVersion(): void
    {
        $validator = new PluginCompatibilityValidator('0.1.0', '0.1.0');

        $report = $validator->check($this->officialPluginManifest());

        self::assertTrue($report['compatible']);
        self::assertSame('ok', $report['severity']);
        self::assertSame([], $report['reasons']);
        self::assertSame('ok', $report['checks']['cms']['status']);
        self::assertSame('ok', $report['checks']['sdk']['status']);
    }

    public function testOfficialPluginResolvesAgainstCorePatchRelease(): void
    {
        // A core PATCH bump (0.1.0 -> 0.1.5) stays inside the plugin's
        // `>=0.1.0 <0.2.0` range and keeps the same SDK, so it remains ok.
        $validator = new PluginCompatibilityValidator('0.1.5', '0.1.0');

        $report = $validator->check($this->officialPluginManifest());

        self::assertTrue($report['compatible']);
        self::assertSame('ok', $report['severity']);
    }

    public function testNextMinorCoreIsRejectedAsBlocking(): void
    {
        // Pre-1.0 semantics: a MINOR bump (0.1.x -> 0.2.0) is breaking, so the
        // plugin's `<0.2.0` upper bound makes the update blocking.
        $validator = new PluginCompatibilityValidator('0.2.0', '0.1.0');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['cms']['status']);
        self::assertNotSame([], $report['reasons']);
    }

    public function testFirstStableCoreIsRejectedAsBlocking(): void
    {
        $validator = new PluginCompatibilityValidator('1.0.0', '0.1.0');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['cms']['status']);
    }

    public function testMismatchedSdkContractIsBlocking(): void
    {
        // The backend SDK contract token is matched against the plugin's
        // declared `pluginApiVersion`; a host SDK that is not `0.1.0` is
        // blocking under the pre-1.0 "every minor is breaking" rule.
        $validator = new PluginCompatibilityValidator('0.1.0', '0.2.0');

        $report = $validator->check($this->officialPluginManifest());

        self::assertFalse($report['compatible']);
        self::assertSame('blocking', $report['severity']);
        self::assertSame('blocking', $report['checks']['sdk']['status']);
    }
}
