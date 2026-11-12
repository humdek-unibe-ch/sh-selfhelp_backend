<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched while a user's data is removed: by the admin action "clean user
 * data", by every way a user is deleted (one, many, one's own account) (F83).
 * A plugin that keeps user data in tables it owns itself listens to this event
 * and deletes its rows for {@see getUserId()}. (Plugin data kept in the generic
 * data store under the plugin's prefix needs no listener: the core removes it.)
 *
 * The event is dispatched INSIDE the database transaction of the removal and
 * before the core removes its own rows. So whatever the listener deletes
 * commits or rolls back together with the rest, and if a listener throws
 * nothing is removed. Use the default database connection (that transaction is
 * the one that rolls back) and touch only tables the plugin owns.
 *
 * Files and caches cannot roll back. A listener that has some collects what it
 * needs while its rows still exist and hands the deletion to
 * {@see afterCommit()}; the core runs it once the transaction has committed.
 *
 * Only enabled plugins hear the event, because a disabled plugin is not
 * loaded. A plugin should therefore also give its user tables a foreign key to
 * `users` with `ON DELETE CASCADE`: deleting the account then removes the rows
 * even while the plugin is disabled.
 */
final class UserDataCleanupEvent extends Event
{
    /** @var list<\Closure(): void> */
    private array $afterCommit = [];

    public function __construct(private readonly int $userId)
    {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Run $callback once the removal has committed, for example to delete
     * files. Callbacks run in the order they were registered. If one throws,
     * the failure is logged and the others still run: the data is already gone,
     * so the removal is not reported as failed.
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void
    {
        $this->afterCommit[] = $callback;
    }

    /**
     * @return list<\Closure(): void>
     */
    public function getAfterCommitCallbacks(): array
    {
        return $this->afterCommit;
    }
}
