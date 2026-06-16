<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\EventListener;

use App\Entity\System\SystemUpdateOperation;
use App\Service\Mercure\MercureTopicResolver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes `system-update` Mercure updates whenever a
 * {@see SystemUpdateOperation} row is inserted or changed.
 *
 * ## Why a Doctrine listener?
 *
 * A CMS-initiated update is driven by TWO different actors that both persist
 * the same row:
 *
 *   - the **CMS** creates the row (`requested`) when the admin submits, and
 *   - the **SelfHelp Manager** drains it and writes back each lifecycle state
 *     (`accepted` → `backup_running` → … → `succeeded`/`failed`/`rolled_back`)
 *     plus `steps` + `progress_percent` through the manager loop.
 *
 * Hooking the publish on every caller would be brittle. Listening at the
 * persistence boundary (exactly like {@see AclVersionMercurePublisher})
 * guarantees the wire notification fires whenever the database state changes,
 * regardless of which request wrote it — so the System Maintenance page tracks
 * progress live over the existing auth-events SSE connection and never polls.
 *
 * ## Two-phase publish
 *
 * 1. {@see onFlush()} runs before the SQL is sent: collect every
 *    `SystemUpdateOperation` insert/update. We don't publish yet — the flush
 *    could roll back.
 * 2. {@see postFlush()} runs after a successful flush: publish to the
 *    REQUESTER's per-user topic so only that admin's own session receives it.
 *
 * Publish failures (hub down, network blip) are logged and swallowed — the
 * frontend's reconnect-aware fallback poll (only while SSE is disconnected and
 * an operation is active) is the safety net.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SystemUpdateMercurePublisher
{
    /**
     * Pending publishes collected during the flush. Each entry carries the
     * requester user id (topic key) + the wire payload. Cleared in postFlush.
     *
     * @var list<array{userId: int, payload: array<string,mixed>}>
     */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureTopicResolver $topics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $scheduled = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
        );

        foreach ($scheduled as $entity) {
            if (!$entity instanceof SystemUpdateOperation) {
                continue;
            }
            $userId = $entity->getRequestedBy()?->getId();
            if ($userId === null) {
                // No requester (or it was deleted): there is no per-user topic
                // to push to. The fallback poll still surfaces the status.
                continue;
            }
            $this->pending[] = [
                'userId' => (int) $userId,
                'payload' => [
                    'operationId' => $entity->getOperationId(),
                    'status' => $entity->getStatus(),
                    'progressPercent' => $entity->getProgressPercent(),
                ],
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        foreach ($batch as $item) {
            try {
                $payload = json_encode($item['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                $this->hub->publish(new Update(
                    $this->topics->userSystemUpdateTopic($item['userId']),
                    $payload,
                    true,
                    null,
                    'system-update',
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to publish system-update change to Mercure hub', [
                    'user_id' => $item['userId'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
