<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command\Plugin;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage for the plugin CLI BEYOND `--help` (Slice 4 / plan §"plugin CLI").
 *
 * Exercises the read/diagnostic plugin commands against the real (empty) QA
 * baseline through the actual DI-wired console application: they must boot,
 * wire their services, and handle the "no plugins installed" + "unknown
 * plugin" cases gracefully (no uncaught exceptions / 0 or clean non-zero exit).
 *
 * The destructive lifecycle commands (install/uninstall/purge/rollback/...)
 * are covered end to end by
 * {@see \App\Tests\Controller\Api\V1\Admin\Plugin\ManagedModeInstallTest} and
 * the Slice 8 certification suite; this class deliberately stays on the safe,
 * side-effect-free read paths. Runs under DAMA (kernel test DB), so any
 * incidental writes roll back.
 */
final class PluginCliCommandsTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runConsole(string $commandName, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($commandName));
        $tester->execute($input);

        return $tester;
    }

    public function testStatusListsNoPluginsOnFreshBaseline(): void
    {
        $tester = $this->runConsole('selfhelp:plugin:status');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('No plugins installed', $output);
        self::assertStringContainsString('Install mode', $output);
    }

    public function testStatusFailsGracefullyForUnknownPlugin(): void
    {
        $tester = $this->runConsole('selfhelp:plugin:status', ['pluginId' => 'qa_nonexistent_plugin']);

        // Graceful failure: clean non-zero exit + error message, NOT a crash.
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testDoctorReportsHealthyFreshHostAsJson(): void
    {
        $tester = $this->runConsole('selfhelp:plugin:doctor', ['--json' => true, '--ci' => true]);

        // A fresh host (no plugins, infra probes skipped) is not a fatal error.
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $report = json_decode($tester->getDisplay(), true);
        self::assertIsArray($report, 'doctor --json must emit a JSON object');
        self::assertArrayHasKey('siteChecks', $report);
        self::assertArrayHasKey('plugins', $report);
        self::assertSame([], $report['plugins'], 'No plugins installed on the QA baseline');
    }
}
