<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\Support\QaKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage for {@see \App\Command\PageVersionRetentionCommand}
 * (plan Phase 9: command tests). Uses a very high --keep so the policy is a
 * no-op on the seeded baseline — the command's exit/output contract and
 * idempotency are verified without destroying any seeded page versions.
 */
final class PageVersionRetentionCommandTest extends QaKernelTestCase
{
    private const COMMAND = 'app:page-version:retention';

    /** High enough that no seeded page exceeds it -> zero deletions. */
    private const SAFE_KEEP = '9999';

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(self::bootedKernel());
        $this->application->setAutoExit(false);
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $tester = $this->runCommand(['--keep' => self::SAFE_KEEP, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $display);
        self::assertStringContainsString('Retention policy applied', $display);
    }

    public function testRealRunIsSuccessfulAndIdempotent(): void
    {
        $first = $this->runCommand(['--keep' => self::SAFE_KEEP]);
        self::assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getDisplay());
        self::assertStringContainsString('Versions Deleted', $first->getDisplay());

        // Second run with the same (no-op) policy must also succeed: idempotent.
        $second = $this->runCommand(['--keep' => self::SAFE_KEEP]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $tester = new CommandTester($this->application->find(self::COMMAND));
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }
}
