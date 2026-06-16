<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Lifecycle;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Service\Auth\UserContextService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * Regression: {@see PluginOperationRecorder::fail()} is the single place every
 * async plugin worker handler (install / update / uninstall) and the CLI
 * finalizer route their catch-all failure through. It must guarantee a terminal
 * status + a final `plugin-operation-progress` event for a still-running
 * operation, and it must be idempotent — never overwriting an operation that
 * already reached a terminal state.
 *
 * Why this matters for the worker path: the worker handlers call
 * `recorder->fail()` from `catch (\Throwable)`. If `finalize()` already marked
 * the row `succeeded` and then a later step threw, a second `fail()` would flip
 * the good result to `failed` and broadcast a misleading final event; if a
 * prior `fail()` already ran, a re-fail would emit a duplicate terminal event.
 *
 * These are fast unit tests: the real recorder sets the terminal status on the
 * entity before flushing, so a mocked EntityManager + event dispatcher are
 * enough to observe both the persistence and the broadcast (or their absence).
 * `fail()` never touches the user-context / transaction services, so
 * unconstructed instances are fine.
 */
#[Group('plugin')]
final class PluginOperationRecorderTest extends TestCase
{
    public function testFailMarksRunningOperationFailedAndEmitsFinalEvent(): void
    {
        $operation = new PluginOperation('qa-plugin', PluginOperation::TYPE_INSTALL);
        $operation->setStatus(PluginOperation::STATUS_RUNNING);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())->method('dispatch')->willReturnArgument(0);

        $recorder = $this->recorder($em, $events);

        $recorder->fail($operation, new \RuntimeException('composer require failed'), 'install-worker');

        self::assertSame(PluginOperation::STATUS_FAILED, $operation->getStatus());
        self::assertSame('composer require failed', $operation->getErrorSummary());
        self::assertNotNull($operation->getFinishedAt());
    }

    public function testFailDoesNotOverwriteASucceededOperationOrReEmit(): void
    {
        // Mirrors the worker edge: finalize() already called succeed(); a later
        // step throws and the handler's catch-all calls fail().
        $operation = new PluginOperation('qa-plugin', PluginOperation::TYPE_INSTALL);
        $operation->setStatus(PluginOperation::STATUS_SUCCEEDED);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $recorder = $this->recorder($em, $events);

        $recorder->fail($operation, new \RuntimeException('post-finalize cleanup blew up'), 'install-worker');

        self::assertSame(
            PluginOperation::STATUS_SUCCEEDED,
            $operation->getStatus(),
            'a succeeded operation must never be flipped to failed by a catch-all fail()',
        );
        self::assertNull($operation->getErrorSummary());
    }

    public function testFailIsIdempotentForAnAlreadyFailedOperation(): void
    {
        // Two orchestration layers can both land in fail() for the same throw
        // (worker catch + recorder). The second call must not re-emit.
        $operation = new PluginOperation('qa-plugin', PluginOperation::TYPE_UNINSTALL);
        $operation->setStatus(PluginOperation::STATUS_FAILED);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $recorder = $this->recorder($em, $events);

        $recorder->fail($operation, new \RuntimeException('boom again'), 'uninstall-worker');

        self::assertSame(PluginOperation::STATUS_FAILED, $operation->getStatus());
    }

    private function recorder(
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
    ): PluginOperationRecorder {
        return new PluginOperationRecorder(
            $em,
            (new \ReflectionClass(UserContextService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(TransactionService::class))->newInstanceWithoutConstructor(),
            $events,
            $this->createStub(Connection::class),
            new NullLogger(),
        );
    }
}
