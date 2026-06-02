<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Command;

use App\Command\Plugin\PluginDoctorCommand;
use PHPUnit\Framework\TestCase;

/**
 * Locks the `selfhelp:plugin:doctor --ci` exit-code contract:
 *
 *   - a fresh host (no plugins, infra probes skipped) is NOT fatal,
 *   - `warning` and informational statuses are NOT fatal,
 *   - only `error`-severity site checks or plugin compatibility errors
 *     are fatal.
 *
 * The doctor command shells the live `PluginHealthService` (a final
 * class with many collaborators) so the decision logic is kept in a
 * pure static method that we can assert directly without booting the
 * kernel.
 */
final class PluginDoctorExitContractTest extends TestCase
{
    public function testFreshHostIsNotFatal(): void
    {
        $report = [
            'siteChecks' => [
                'safeMode' => ['name' => 'Safe mode', 'status' => 'ok', 'message' => 'disabled'],
                'lockFile' => ['name' => 'Lock file', 'status' => 'ok', 'message' => 'no plugins'],
                'mercure' => ['name' => 'Mercure hub', 'status' => 'ok', 'message' => 'probe skipped'],
            ],
            'plugins' => [],
        ];

        self::assertFalse(PluginDoctorCommand::reportHasFatalError($report));
    }

    public function testWarningsAreNotFatal(): void
    {
        $report = [
            'siteChecks' => [
                'lockFile' => ['name' => 'Lock file', 'status' => 'warning', 'message' => 'drift'],
                'failedOperations' => ['name' => 'Recent failed operations', 'status' => 'warning', 'message' => '1 failed'],
            ],
            'plugins' => [
                ['pluginId' => 'demo', 'version' => '1.0.0', 'compatibility' => ['severity' => 'warning']],
            ],
        ];

        self::assertFalse(PluginDoctorCommand::reportHasFatalError($report));
    }

    public function testSiteCheckErrorIsFatal(): void
    {
        $report = [
            'siteChecks' => [
                'mercure' => ['name' => 'Mercure hub', 'status' => 'error', 'message' => 'unreachable'],
            ],
            'plugins' => [],
        ];

        self::assertTrue(PluginDoctorCommand::reportHasFatalError($report));
    }

    public function testPluginCompatibilityErrorIsFatal(): void
    {
        $report = [
            'siteChecks' => [
                'safeMode' => ['name' => 'Safe mode', 'status' => 'ok', 'message' => 'disabled'],
            ],
            'plugins' => [
                ['pluginId' => 'demo', 'version' => '2.0.0', 'compatibility' => ['severity' => 'error']],
            ],
        ];

        self::assertTrue(PluginDoctorCommand::reportHasFatalError($report));
    }

    public function testMissingKeysAreTreatedAsOk(): void
    {
        self::assertFalse(PluginDoctorCommand::reportHasFatalError([]));
    }
}
