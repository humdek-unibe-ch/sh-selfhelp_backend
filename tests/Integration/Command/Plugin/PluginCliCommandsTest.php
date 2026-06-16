<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command\Plugin;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Repository\Plugin\PluginOperationRepository;
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
        $this->application = new Application(self::bootKernel());
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

    /**
     * The destructive purge guard (plan §"plugin lifecycle words": purge is
     * irreversible and requires `--confirm`) must refuse BEFORE touching the
     * service, so this stays a safe, side-effect-free assertion.
     */
    public function testPurgeRefusesWithoutConfirmFlag(): void
    {
        $tester = $this->runConsole('selfhelp:plugin:purge', ['pluginId' => 'qa_nonexistent_plugin']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Refusing to purge without --confirm', $tester->getDisplay());
    }

    /**
     * Regression for #8: purge is now parked like uninstall, so a managed-mode
     * `purge` operation is finalized by `selfhelp:plugin:run-operation`. Before
     * the wiring this command had no `TYPE_PURGE` case and fell through to the
     * "cannot be finalized" failure branch. Driving it against a `purge` row
     * whose plugin is absent exercises the new case end to end through the real
     * container and lands on the idempotent "already purged" success path — no
     * real plugin, no DDL. Writes roll back under DAMA.
     */
    public function testRunOperationFinalizesPurgeForMissingPluginIdempotently(): void
    {
        $container = self::getContainer();
        /** @var PluginOperationRecorder $recorder */
        $recorder = $container->get(PluginOperationRecorder::class);
        $operation = $recorder->start('qa_nonexistent_purge', PluginOperation::TYPE_PURGE, 'managed');
        $operationId = $operation->getId();
        self::assertIsInt($operationId);

        $tester = $this->runConsole('selfhelp:plugin:run-operation', ['operationId' => (string) $operationId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        /** @var PluginOperationRepository $operations */
        $operations = $container->get(PluginOperationRepository::class);
        $reloaded = $operations->find($operationId);
        self::assertInstanceOf(PluginOperation::class, $reloaded);
        self::assertSame(
            PluginOperation::STATUS_SUCCEEDED,
            $reloaded->getStatus(),
            'run-operation must finalize a purge operation (idempotent success when the plugin row is already gone).',
        );
    }
}
