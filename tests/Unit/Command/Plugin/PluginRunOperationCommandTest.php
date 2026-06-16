<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Command\Plugin;

use App\Command\Plugin\PluginRunOperationCommand;
use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Service\PluginCliFinalizer;
use App\Repository\Plugin\PluginOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression: the managed-mode finalizer command must ALWAYS leave the
 * operation in a terminal state.
 *
 * `selfhelp:plugin:run-operation` is run by the SelfHelp Manager after composer/
 * npm + migrations. If it bailed out (missing snapshot payload, unfinalizable
 * type, or any thrown finalizer error) *after* the operation was marked
 * `running`, the row was left stuck on `running`: the admin UI showed progress
 * forever and the per-plugin lock blocked every later install/uninstall until
 * its 15-minute TTL expired. The command now routes every failure through
 * `failIfNotTerminal()`, which records a terminal `failed` status (and the final
 * `plugin-operation-progress` event) — unless the operation already reached a
 * terminal state, which it must never overwrite.
 *
 * These are fast unit tests: the command runs against a real
 * {@see PluginOperationRecorder} (its `fail()` sets the terminal status on the
 * entity before flushing, so a stubbed EntityManager is enough to observe it)
 * and a stubbed repository. The `final` {@see PluginCliFinalizer} is never
 * reached on the missing-snapshot / unfinalizable-type paths; the finalizer-
 * throws path uses an unconstructed finalizer whose first property access raises
 * a \Throwable, standing in for a finalizer that blows up mid-run.
 */
#[Group('plugin')]
final class PluginRunOperationCommandTest extends TestCase
{
    public function testMissingInstallSnapshotMarksOperationFailedNotStuckRunning(): void
    {
        $operation = $this->runningOperation(PluginOperation::TYPE_INSTALL);

        $exit = $this->runCommand($operation);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(
            PluginOperation::STATUS_FAILED,
            $operation->getStatus(),
            'a missing manifest snapshot must fail the operation, not leave it running',
        );
    }

    public function testMissingUpdateSnapshotMarksOperationFailedNotStuckRunning(): void
    {
        $operation = $this->runningOperation(PluginOperation::TYPE_UPDATE);

        $exit = $this->runCommand($operation);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(PluginOperation::STATUS_FAILED, $operation->getStatus());
    }

    public function testUnfinalizableOperationTypeMarksOperationFailed(): void
    {
        // PURGE is a valid operation type but is not finalized by run-operation,
        // so it hits the switch `default:` branch.
        $operation = $this->runningOperation(PluginOperation::TYPE_PURGE);

        $exit = $this->runCommand($operation);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(PluginOperation::STATUS_FAILED, $operation->getStatus());
    }

    public function testFinalizerThrowingMidRunMarksOperationFailed(): void
    {
        // UNINSTALL goes straight to the finalizer (no snapshot pre-check); the
        // unconstructed finalizer throws on first property access, exercising the
        // command's `catch (\Throwable)` recovery.
        $operation = $this->runningOperation(PluginOperation::TYPE_UNINSTALL);

        $exit = $this->runCommand($operation);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(PluginOperation::STATUS_FAILED, $operation->getStatus());
    }

    public function testAlreadyTerminalOperationIsNotReFailed(): void
    {
        // The orchestrator's own catch may have already finished the operation.
        // run-operation must NOT overwrite a terminal status with `failed`.
        $operation = new PluginOperation('qa-plugin', PluginOperation::TYPE_INSTALL);
        $operation->setStatus(PluginOperation::STATUS_SUCCEEDED);
        $operation->setSnapshotsJson([]); // no manifest → command still can't finalize

        $exit = $this->runCommand($operation);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(
            PluginOperation::STATUS_SUCCEEDED,
            $operation->getStatus(),
            'a terminal operation must never be re-failed by the recovery path',
        );
    }

    private function runningOperation(string $type): PluginOperation
    {
        $operation = new PluginOperation('qa-plugin', $type);
        $operation->setStatus(PluginOperation::STATUS_RUNNING);
        $operation->setSnapshotsJson([]); // empty → no manifest payload

        return $operation;
    }

    private function runCommand(PluginOperation $operation): int
    {
        $repository = $this->createStub(PluginOperationRepository::class);
        $repository->method('find')->willReturn($operation);

        $command = new PluginRunOperationCommand(
            $this->unconstructedFinalizer(),
            $repository,
            $this->realRecorder(),
        );

        $tester = new CommandTester($command);

        return $tester->execute(['operationId' => '1']);
    }

    /**
     * A real recorder whose `fail()` only needs a no-op flush + an event sink:
     * it sets the terminal status on the entity before flushing, so the stubbed
     * EntityManager is enough to observe the guarantee. The two app services are
     * never touched by `fail()`, so an unconstructed instance is fine.
     */
    private function realRecorder(): PluginOperationRecorder
    {
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnArgument(0);

        return new PluginOperationRecorder(
            $this->createStub(EntityManagerInterface::class),
            (new \ReflectionClass(UserContextService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(TransactionService::class))->newInstanceWithoutConstructor(),
            $events,
            $this->createStub(Connection::class),
            new NullLogger(),
        );
    }

    /**
     * The `final` finalizer cannot be stubbed; an unconstructed instance throws
     * on the first property access, standing in for a finalizer that fails
     * mid-run. It is never reached on the snapshot / type paths.
     */
    private function unconstructedFinalizer(): PluginCliFinalizer
    {
        return (new \ReflectionClass(PluginCliFinalizer::class))->newInstanceWithoutConstructor();
    }
}
