<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * The application's database connection (`doctrine.dbal.wrapper_class`), with
 * one addition: work that must run only after the outermost transaction has
 * committed, and never when it rolled back.
 *
 * It exists for {@see \App\Plugin\Event\Domain\PluginDomainEventDispatcher}
 * (contract C24): a domain event raised inside a larger transaction (an
 * import, an action job, a service that wraps several writes) may be told to
 * plugin listeners only once the change is really stored.
 *
 * Callbacks wait with the nesting level (a DBAL savepoint) they were queued
 * at. Releasing a savepoint hands them to the enclosing level; rolling back a
 * savepoint drops the callbacks queued inside it; the outermost commit runs
 * everything that is left, in the order it was queued; the outermost rollback
 * drops everything. With no transaction open a callback runs at once.
 *
 * A callback must not throw: it runs after the commit, so an exception could
 * not undo the work and would only look like a failed commit.
 */
class AfterCommitConnection extends Connection
{
    /** @var list<array{level: int, callback: \Closure(): void}> */
    private array $afterCommit = [];

    /**
     * Run the callback once the current transaction has committed, or at once
     * when none is open. It never runs when the transaction is rolled back.
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void
    {
        $level = $this->getTransactionNestingLevel();
        if ($level === 0) {
            $callback();

            return;
        }

        $this->afterCommit[] = ['level' => $level, 'callback' => $callback];
    }

    public function commit(): void
    {
        $level = $this->getTransactionNestingLevel();
        parent::commit();

        if ($level > 1) {
            // A savepoint was released: what waited in it now waits for the enclosing level.
            foreach ($this->afterCommit as $index => $entry) {
                if ($entry['level'] >= $level) {
                    $this->afterCommit[$index]['level'] = $level - 1;
                }
            }

            return;
        }

        $queued = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($queued as $entry) {
            ($entry['callback'])();
        }
    }

    public function rollBack(): void
    {
        $level = $this->getTransactionNestingLevel();
        try {
            parent::rollBack();
        } finally {
            $this->afterCommit = array_values(array_filter(
                $this->afterCommit,
                static fn (array $entry): bool => $entry['level'] < $level,
            ));
        }
    }

    public function close(): void
    {
        $this->afterCommit = [];
        parent::close();
    }
}
