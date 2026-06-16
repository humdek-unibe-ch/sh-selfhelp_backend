<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Messenger;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Archive\PluginArchivePromoter;
use App\Plugin\Archive\PluginRuntimeArtifactFetcher;
use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Messenger\InstallPluginHandler;
use App\Plugin\Messenger\InstallPluginMessage;
use App\Plugin\Messenger\StandaloneArchiveComposerHelper;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * Regression for the async plugin worker terminal-status guarantee.
 *
 * The `plugin_ops` Messenger worker runs `InstallPluginHandler` with
 * `max_retries: 0`. If the handler bailed out after `markRunning()` without
 * recording a terminal status, the `plugin_operations` row stayed stuck on
 * `running` — the admin UI showed progress forever and the per-plugin lock
 * blocked every later operation until its TTL expired. The handler must always
 * land the operation in a terminal state (and emit the final
 * `plugin-operation-progress` event) via {@see PluginOperationRecorder::fail()}.
 *
 * This drives the REAL handler through its direct-execution failure branch (a
 * resolved source missing composer coordinates), using a real recorder +
 * resolver. The heavy collaborators (installer / package manager / promoters /
 * standalone helper) are never reached on this branch, so unconstructed
 * instances stand in for them.
 */
#[Group('plugin')]
final class InstallPluginHandlerTest extends TestCase
{
    public function testWorkerMarksOperationFailedWhenResolvedSourceHasNoComposerCoordinates(): void
    {
        $operation = new PluginOperation('qa-plugin', PluginOperation::TYPE_INSTALL);

        $operations = $this->createStub(PluginOperationRepository::class);
        $operations->method('find')->willReturn($operation);

        $handler = new InstallPluginHandler(
            $operations,
            $this->realRecorder(),
            $this->unconstructed(PluginInstaller::class),
            $this->unconstructed(PackageManagerRunner::class),
            $this->unconstructed(PluginArchivePromoter::class),
            $this->unconstructed(PluginRuntimeArtifactFetcher::class),
            // trusted mode = direct execution, so the worker runs composer
            // itself rather than emitting a managed runbook and waiting.
            new InstallModeResolver('prod', 'trusted', true),
            $this->unconstructed(StandaloneArchiveComposerHelper::class),
            new NullLogger(),
        );

        $message = new InstallPluginMessage(1, ['id' => 'qa-plugin', 'version' => '1.0.0'], $this->incompleteSource());

        $handler($message);

        self::assertSame(
            PluginOperation::STATUS_FAILED,
            $operation->getStatus(),
            'the worker must record a terminal status, not leave the row stuck running',
        );
        self::assertNotNull($operation->getFinishedAt());
        self::assertStringContainsString('composer', (string) $operation->getErrorSummary());
    }

    private function incompleteSource(): ResolvedSource
    {
        return new ResolvedSource(
            ResolvedSource::KIND_PASTE,
            null,
            null,
            '{}',
            '',
            '',
            [],
            [], // composer: no package / version → handler fails fast
            [],
        );
    }

    /**
     * A real recorder whose `markRunning()`/`fail()` only need a no-op flush, a
     * no-op transaction log and an event sink: they set status on the entity
     * before flushing, so the stubbed EntityManager is enough to observe the
     * terminal status. `markRunning()` logs a transaction, so that service is a
     * no-op stub; the user context is never touched on this path.
     */
    private function realRecorder(): PluginOperationRecorder
    {
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnArgument(0);

        return new PluginOperationRecorder(
            $this->createStub(EntityManagerInterface::class),
            $this->unconstructed(UserContextService::class),
            $this->createStub(TransactionService::class),
            $events,
            $this->createStub(Connection::class),
            new NullLogger(),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function unconstructed(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
