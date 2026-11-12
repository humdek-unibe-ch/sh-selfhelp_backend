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
 * Machine-readable maintenance state for the SelfHelp Manager backup probe.
 *
 * `--format=json` must print exactly one JSON line of getState() and leave the
 * default text path unchanged. The lock file is restored so later tests see
 * the same maintenance state this run started with.
 */
final class MaintenanceModeCommandTest extends KernelTestCase
{
    private Application $application;

    private string $lockPath;

    private ?string $lockBackup = null;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->application = new Application($kernel);
        $this->application->setAutoExit(false);
        $this->lockPath = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'maintenance_mode.lock';
        $this->lockBackup = is_file($this->lockPath) ? (string) file_get_contents($this->lockPath) : null;
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->lockBackup === null) {
            if (is_file($this->lockPath)) {
                unlink($this->lockPath);
            }
        } else {
            @mkdir(dirname($this->lockPath), 0o775, true);
            file_put_contents($this->lockPath, $this->lockBackup);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runConsole(array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find('selfhelp:maintenance'));
        $tester->execute($input);

        return $tester;
    }

    public function testJsonStatusWhenOff(): void
    {
        $tester = $this->runConsole(['--format' => 'json']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $state = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertFalse($state['enabled']);
        self::assertArrayHasKey('forced_by_env', $state);
        self::assertArrayHasKey('message', $state);
        self::assertArrayHasKey('since', $state);
        self::assertArrayHasKey('updated_by', $state);
        self::assertStringNotContainsString("\n", trim($tester->getDisplay()));
    }

    public function testJsonEnableEchoesMessageAndActorThenDisable(): void
    {
        $tester = $this->runConsole([
            '--enable' => true,
            '--message' => 'Backup in progress',
            '--actor' => 'qa-test',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $on = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($on['enabled']);
        self::assertSame('Backup in progress', $on['message']);
        self::assertSame('qa-test', $on['updated_by']);
        self::assertNotSame('', $on['since']);

        $tester = $this->runConsole(['--disable' => true, '--format' => 'json']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $off = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($off['enabled']);
    }

    public function testTextStatusUnchanged(): void
    {
        $tester = $this->runConsole();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Maintenance mode is currently disabled.', $tester->getDisplay());
    }

    public function testUnknownFormatIsRefused(): void
    {
        $tester = $this->runConsole(['--format' => 'xml']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
