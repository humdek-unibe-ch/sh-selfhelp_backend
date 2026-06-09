<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Coverage for the canonical `selfhelp:safe-mode` command (plan §9 "Unified
 * safe-mode"): it must be DI-wired, report the effective state (including the
 * `SELFHELP_DISABLE_PLUGINS` env hard switch), and refuse contradictory flags.
 *
 * These assertions are deliberately side-effect-free: they exercise the status
 * (no-flag) path, the env-forced reporting branch, and the mutual-exclusion
 * guard — none of which write the safe-mode lock or regenerate the plugin
 * bundles file. Enable/disable execution is the same mechanism already covered
 * by the plugin safe-mode service.
 */
final class SafeModeCommandTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
        $this->application->setAutoExit(false);
    }

    protected function tearDown(): void
    {
        unset($_ENV['SELFHELP_DISABLE_PLUGINS'], $_SERVER['SELFHELP_DISABLE_PLUGINS']);
        parent::tearDown();
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runConsole(array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find('selfhelp:safe-mode'));
        $tester->execute($input);

        return $tester;
    }

    public function testStatusPathReportsStateWithoutMutating(): void
    {
        unset($_ENV['SELFHELP_DISABLE_PLUGINS'], $_SERVER['SELFHELP_DISABLE_PLUGINS']);

        $tester = $this->runConsole();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Safe mode is currently', $tester->getDisplay());
    }

    public function testStatusReportsEnvForcedSafeMode(): void
    {
        $_ENV['SELFHELP_DISABLE_PLUGINS'] = 'true';

        $tester = $this->runConsole();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('ENABLED', $output);
        self::assertStringContainsString('forced by SELFHELP_DISABLE_PLUGINS', $output);
    }

    public function testContradictoryFlagsAreRefusedBeforeAnyMutation(): void
    {
        $tester = $this->runConsole(['--enable' => true, '--disable' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Pass only one of --enable / --disable.', $tester->getDisplay());
    }
}
