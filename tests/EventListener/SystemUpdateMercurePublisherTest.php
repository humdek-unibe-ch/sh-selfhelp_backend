<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\System\SystemUpdateOperation;
use App\Entity\User;
use App\EventListener\SystemUpdateMercurePublisher;
use App\Service\Mercure\MercureTopicResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Regression for the SSE push that makes the System Maintenance page track an
 * update WITHOUT polling.
 *
 * A CMS-initiated update is persisted twice: the CMS creates the row
 * (`requested`) and the SelfHelp Manager writes back each lifecycle state +
 * progress. This Doctrine listener must fire a `system-update` Mercure event to
 * the REQUESTER's per-user topic on every such insert/update so the admin's
 * open auth-events SSE connection refetches the status live. No requester ⇒ no
 * per-user topic ⇒ nothing published (the fallback poll covers that).
 */
final class SystemUpdateMercurePublisherTest extends TestCase
{
    public function testPublishesSystemUpdateToTheRequesterTopicOnFlush(): void
    {
        /** @var list<Update> $captured */
        $captured = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $u) use (&$captured): string {
            $captured[] = $u;
            return 'mercure-id';
        });

        $publisher = new SystemUpdateMercurePublisher(
            $hub,
            new MercureTopicResolver('https://selfhelp.app'),
            new NullLogger(),
        );

        $requester = $this->createStub(User::class);
        $requester->method('getId')->willReturn(7);

        $op = (new SystemUpdateOperation('inst-1', 'op-42', '0.2.0'))
            ->setRequestedBy($requester)
            ->setStatus(SystemUpdateOperation::STATUS_BACKUP_RUNNING)
            ->setProgressPercent(40);

        $em = $this->emWith(insertions: [$op], updates: []);
        $publisher->onFlush(new OnFlushEventArgs($em));
        $publisher->postFlush(new PostFlushEventArgs($em));

        self::assertCount(1, $captured);
        $update = $captured[0];
        self::assertSame(['https://selfhelp.app/users/7/system-update'], $update->getTopics());
        self::assertSame('system-update', $update->getType());
        self::assertTrue($update->isPrivate());

        $payload = json_decode($update->getData(), true);
        self::assertIsArray($payload);
        self::assertSame('op-42', $payload['operationId']);
        self::assertSame(SystemUpdateOperation::STATUS_BACKUP_RUNNING, $payload['status']);
        self::assertSame(40, $payload['progressPercent']);
    }

    public function testDoesNotPublishWhenTheOperationHasNoRequester(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $publisher = new SystemUpdateMercurePublisher(
            $hub,
            new MercureTopicResolver('https://selfhelp.app'),
            new NullLogger(),
        );

        // No `setRequestedBy()` → getRequestedBy() is null → nothing to scope to.
        $op = (new SystemUpdateOperation('inst-1', 'op-43', '0.2.0'))
            ->setStatus(SystemUpdateOperation::STATUS_SUCCEEDED);

        $em = $this->emWith(insertions: [], updates: [$op]);
        $publisher->onFlush(new OnFlushEventArgs($em));
        $publisher->postFlush(new PostFlushEventArgs($em));
    }

    public function testIgnoresNonUpdateOperationEntities(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $publisher = new SystemUpdateMercurePublisher(
            $hub,
            new MercureTopicResolver('https://selfhelp.app'),
            new NullLogger(),
        );

        $em = $this->emWith(insertions: [new \stdClass()], updates: [$this->createStub(User::class)]);
        $publisher->onFlush(new OnFlushEventArgs($em));
        $publisher->postFlush(new PostFlushEventArgs($em));
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     */
    private function emWith(array $insertions, array $updates): EntityManagerInterface
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return $em;
    }
}
