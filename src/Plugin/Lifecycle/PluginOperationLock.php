<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\PluginOperation;
use App\Repository\Plugin\PluginOperationRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Concurrent-operation guard.
 *
 * Rules:
 *   1. Only one operation may be in `running` status per CMS instance.
 *      Concurrent operations are rejected, not queued.
 *   2. Only one operation per plugin id may exist in `requested` or
 *      `running` status at the same time.
 *
 * The guard enforces these invariants at two layers:
 *
 *   - **DB layer (primary):** reads `plugin_operations` to refuse a
 *     new operation when an active one already exists. This is the
 *     fail-safe invariant; it stays in place even if the distributed
 *     lock is bypassed.
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
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * @throws PluginOperationLockedException when an operation is
     *                                         already running.
     */
    public function assertCanStart(string $pluginId): void
    {
        $active = $this->operations->findActive();
        foreach ($active as $operation) {
            if ($operation->getStatus() === PluginOperation::STATUS_RUNNING) {
                throw new PluginOperationLockedException(sprintf(
                    'Another plugin operation is currently running (id=%d, plugin=%s, type=%s). Wait for it to finish before starting a new one.',
                    (int) $operation->getId(),
                    $operation->getPluginId(),
                    $operation->getType()
                ));
            }
            if ($operation->getPluginId() === $pluginId) {
                throw new PluginOperationLockedException(sprintf(
                    'Plugin "%s" already has an active operation (id=%d, status=%s, type=%s).',
                    $pluginId,
                    (int) $operation->getId(),
                    $operation->getStatus(),
                    $operation->getType()
                ));
            }
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
}
