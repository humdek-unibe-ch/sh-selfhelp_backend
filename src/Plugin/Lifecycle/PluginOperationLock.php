<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\PluginOperation;
use App\Repository\Plugin\PluginOperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Concurrent-operation guard.
 *
 * Rules:
 *   1. Only one operation may be in `running` status per CMS instance.
 *      Concurrent operations are rejected, not queued.
 *   2. A `requested` operation for the same plugin id is treated as
 *      orphaned (the previous web request never reached `finalize()`)
 *      and is silently superseded — the row is marked `cancelled`
 *      with a `superseded` log entry and the new operation proceeds.
 *      Operators recover from "I closed the modal" without having to
 *      run a manual purge first.
 *   3. A `running` operation older than {@see TTL_SECONDS} is treated
 *      as stale (the worker / web request died without finalising) and
 *      is auto-superseded the same way as orphan `requested` rows.
 *      This mirrors the Symfony Lock TTL: any process that legitimately
 *      took longer than 15 minutes would already have lost its
 *      distributed lock, so reclaiming the DB row is safe.
 *
 * The guard enforces these invariants at two layers:
 *
 *   - **DB layer (primary):** reads `plugin_operations` to refuse a
 *     new operation when one is RUNNING, and to supersede stale
 *     `requested` rows for the same plugin. This is the fail-safe
 *     invariant; it stays in place even if the distributed lock is
 *     bypassed.
 *   - **Symfony Lock (optional, recommended):** when a `LockFactory`
 *     is injected via the `framework.lock.plugin_operation` resource,
 *     the guard acquires a non-blocking distributed lock keyed on
 *     `plugin_op:global` plus `plugin_op:<pluginId>` so concurrent
 *     workers across multiple app servers cannot race.
 *
 * The lock is acquired in {@see assertCanStart()} and released in
 * {@see release()} by the orchestrator once the operation has been
 * recorded. Locks have a TTL of 15 minutes; orchestrators that take
 * longer should refresh.
 */
final class PluginOperationLock
{
    private const TTL_SECONDS = 900;

    /** @var array<string, LockInterface> */
    private array $heldLocks = [];

    public function __construct(
        private readonly PluginOperationRepository $operations,
        private readonly EntityManagerInterface $em,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * @throws PluginOperationLockedException when another plugin
     *                                         operation is currently
     *                                         RUNNING (any plugin) or
     *                                         when the distributed
     *                                         lock cannot be acquired.
     */
    public function assertCanStart(string $pluginId): void
    {
        $active = $this->operations->findActive();
        $toSupersede = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleBefore = $now->sub(new \DateInterval('PT' . self::TTL_SECONDS . 'S'));
        foreach ($active as $operation) {
            if ($operation->getStatus() === PluginOperation::STATUS_RUNNING) {
                // A RUNNING row whose startedAt is older than TTL is
                // assumed orphaned (worker / web request died without
                // finalising). The Symfony distributed lock has already
                // expired by then so reclaiming the DB row is safe.
                $startedAt = $operation->getStartedAt() ?? $operation->getCreatedAt();
                if ($startedAt < $staleBefore) {
                    $toSupersede[] = $operation;
                    continue;
                }
                throw new PluginOperationLockedException(sprintf(
                    'Another plugin operation is currently running (id=%d, plugin=%s, type=%s). Wait for it to finish before starting a new one.',
                    (int) $operation->getId(),
                    $operation->getPluginId(),
                    $operation->getType()
                ));
            }
            // STATUS_REQUESTED — orphaned request from a previous web
            // session that never reached finalize(). Supersede so the
            // user can retry. Other plugins' REQUESTED rows are left
            // alone (they coexist; only RUNNING is mutually exclusive).
            if ($operation->getPluginId() === $pluginId) {
                $toSupersede[] = $operation;
            }
        }
        foreach ($toSupersede as $stale) {
            $this->supersede($stale);
        }

        if ($this->lockFactory === null) {
            return;
        }

        $globalKey = 'plugin_op:global';
        $perPluginKey = 'plugin_op:' . $pluginId;
        $global = $this->lockFactory->createLock($globalKey, self::TTL_SECONDS, false);
        $perPlugin = $this->lockFactory->createLock($perPluginKey, self::TTL_SECONDS, false);

        if (!$global->acquire(false)) {
            throw new PluginOperationLockedException('Another plugin operation is currently running (distributed lock held).');
        }
        if (!$perPlugin->acquire(false)) {
            $global->release();
            throw new PluginOperationLockedException(sprintf('Plugin "%s" already has an active operation (distributed lock held).', $pluginId));
        }
        $this->heldLocks[$globalKey] = $global;
        $this->heldLocks[$perPluginKey] = $perPlugin;
    }

    public function release(string $pluginId): void
    {
        foreach (['plugin_op:global', 'plugin_op:' . $pluginId] as $key) {
            if (isset($this->heldLocks[$key])) {
                try {
                    $this->heldLocks[$key]->release();
                } catch (\Throwable) {
                    // Best-effort release; failures here are logged elsewhere.
                }
                unset($this->heldLocks[$key]);
            }
        }
    }

    /**
     * Mark a stale `requested` or stuck `running` operation as
     * cancelled with a `superseded` log entry. Used when:
     *
     *   - A new request comes in for the same plugin id and the previous
     *     request never reached finalize.
     *   - A `running` row is older than {@see TTL_SECONDS} (orphaned by
     *     a crashed worker / web request).
     */
    private function supersede(PluginOperation $operation): void
    {
        $previousStatus = $operation->getStatus();
        $operation->setStatus(PluginOperation::STATUS_CANCELLED);
        $operation->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $reason = $previousStatus === PluginOperation::STATUS_RUNNING
            ? sprintf(
                'Operation auto-superseded: previous %s operation was stuck in "running" state for plugin "%s" (older than %d seconds; worker or web request likely died without finalising).',
                $operation->getType(),
                $operation->getPluginId(),
                self::TTL_SECONDS
            )
            : sprintf(
                'Operation superseded by a new request for plugin "%s" (previous %s operation never reached finalize).',
                $operation->getPluginId(),
                $operation->getType()
            );
        $operation->appendLog([
            'event' => 'superseded',
            'message' => $reason,
        ]);
        $this->em->persist($operation);
        $this->em->flush();
    }
}
