<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Command\Test\AppTestResetDbCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Certifies the destructive {@see AppTestResetDbCommand} REFUSES to run unless
 * every safety guard holds (plan §32 / canonical Testing Rule 31). This is the
 * environment-isolation contract that protects a real database from a stray
 * `app:test:reset-db`.
 *
 * Each guard is exercised in isolation by constructing the command with
 * controlled dependencies and asserting a clean `FAILURE` exit + the specific
 * refusal message — the command returns BEFORE any drop/create step, so nothing
 * is destroyed. A LAZY DBAL connection (created via {@see DriverManager}, never
 * actually opened) supplies `getParams()` without a mock and without touching a
 * server, keeping the test deterministic under `failOnWarning`.
 *
 * Deliberately there is NO positive (success) case here: a green run would drop
 * and recreate the test DB. The happy path is covered by `composer test:reset-db`
 * / `fresh-test-install` during environment setup, not by this assertion.
 */
#[Group('smoke')]
final class AppTestResetDbSafetyTest extends TestCase
{
    private string $previousDatabaseUrl = '';
    private bool $hadDatabaseUrl = false;

    protected function setUp(): void
    {
        $existing = $_SERVER['DATABASE_URL'] ?? null;
        $this->hadDatabaseUrl = is_string($existing);
        $this->previousDatabaseUrl = is_string($existing) ? $existing : '';
    }

    protected function tearDown(): void
    {
        if ($this->hadDatabaseUrl) {
            $_SERVER['DATABASE_URL'] = $this->previousDatabaseUrl;
            $_ENV['DATABASE_URL'] = $this->previousDatabaseUrl;
        } else {
            unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
        }
    }

    public function testRefusesWhenAppEnvIsNotTest(): void
    {
        $tester = $this->tester('prod', 'selfhelp_test', 'localhost');

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('APP_ENV', $tester->getDisplay());
    }

    public function testRefusesWhenDatabaseNameLacksTestSuffix(): void
    {
        $this->setSafeDatabaseUrl();
        $tester = $this->tester('test', 'selfhelp', 'localhost');

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('_test', $tester->getDisplay());
    }

    public function testRefusesWhenHostNotInAllowList(): void
    {
        $this->setSafeDatabaseUrl();
        $tester = $this->tester('test', 'selfhelp_test', 'database.example.com');

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('allow-list', $tester->getDisplay());
    }

    public function testRefusesWhenDatabaseUrlLooksLikeProduction(): void
    {
        $_SERVER['DATABASE_URL'] = 'mysql://user:pass@localhost:3306/selfhelp_production_test?serverVersion=8.0';
        $tester = $this->tester('test', 'selfhelp_test', 'localhost');

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('looks like a real environment', $tester->getDisplay());
    }

    public function testRefusesWithoutForceFlag(): void
    {
        $this->setSafeDatabaseUrl();
        $tester = $this->tester('test', 'selfhelp_test', 'localhost');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('without --force', $tester->getDisplay());
    }

    private function setSafeDatabaseUrl(): void
    {
        $_SERVER['DATABASE_URL'] = 'mysql://user:pass@localhost:3306/selfhelp_test?serverVersion=8.0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'];
    }

    private function tester(string $appEnv, string $dbName, string $dbHost): CommandTester
    {
        $command = new AppTestResetDbCommand($this->lazyConnection($dbName, $dbHost), $appEnv, sys_get_temp_dir());

        return new CommandTester($command);
    }

    /**
     * A DBAL connection that is never opened — only its `getParams()` is read by
     * the command's guards, so no MySQL server is contacted.
     */
    private function lazyConnection(string $dbName, string $dbHost): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $dbHost,
            'dbname' => $dbName,
            'user' => 'unused',
            'password' => 'unused',
        ]);
    }
}
