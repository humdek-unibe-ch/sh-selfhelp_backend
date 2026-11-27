<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use App\Doctrine\AfterCommitConnection;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The wake signal of the scheduler (contract C13).
 *
 * `scheduled_jobs` stays the one queue (rule 6.1); this is only a doorbell. When
 * a job is created that is due now or earlier, {@see JobSchedulerService} pushes
 * one entry onto a Redis list after the commit, and the scheduler's
 * `app:scheduled-jobs:wait` returns at once instead of at the end of its tick.
 * The wait is a server-side block on Redis, not polling by a client (rule 6.3).
 *
 * The list never holds more than one entry (`LPUSH`, then `LTRIM 0 0`): the
 * entry says "look at the queue", not which job. A lost or missing signal costs
 * nothing but time: the job starts at the next tick. So nothing here may break
 * the caller: a push that fails is logged and ignored, and a wait that cannot
 * reach Redis sleeps instead.
 *
 * The list name is `%selfhelp.scheduled_jobs.wake_key%` (`selfhelp:scheduled_jobs:wake`;
 * the test environment adds the database token).
 */
final class ScheduledJobWakeSignal
{
    /** The longest one Redis call blocks; a longer wait is made of several calls (a client read timeout is about a minute). */
    private const WAIT_CHUNK_SECONDS = 20;

    /**
     * @param object|null $redis the Redis connection the cache already uses (`\Redis`, a Predis client, ...)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'selfhelp.scheduled_jobs.wake_key')]
        private readonly string $key,
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly ?object $redis = null,
    ) {
    }

    /** The name of the Redis list. */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Push one entry once the current transaction has committed (at once when none is open).
     * Nothing is pushed when the transaction rolls back, because then no job exists.
     */
    public function pushAfterCommit(): void
    {
        $push = function (): void {
            $this->push();
        };

        if ($this->connection instanceof AfterCommitConnection) {
            $this->connection->afterCommit($push);

            return;
        }
        $push();
    }

    /**
     * Push one entry now, and keep the list at one entry.
     *
     * @return bool false when Redis could not be reached (the job then starts at the next tick)
     */
    public function push(): bool
    {
        try {
            $this->call('lpush', $this->key, '1');
            $this->call('ltrim', $this->key, 0, 0);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not push the scheduled-job wake signal; the job starts at the next scheduler tick.', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Block until an entry arrives or the timeout is over, and consume the entry.
     *
     * A timeout of 0 or less only looks at the list. When Redis cannot be reached the call
     * sleeps for the time instead, so the scheduler keeps its pace.
     *
     * @return bool true when an entry woke the wait, false after the timeout
     */
    public function wait(int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSeconds);

        try {
            if ($timeoutSeconds <= 0) {
                return $this->isEntry($this->call('lpop', $this->key));
            }

            do {
                $chunk = (int) min(self::WAIT_CHUNK_SECONDS, max(1.0, ceil($deadline - microtime(true))));
                if ($this->isEntry($this->call('blpop', $this->key, $chunk))) {
                    return true;
                }
            } while (microtime(true) < $deadline - 0.05);

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not wait on the scheduled-job wake signal; sleeping for the rest of the time.', ['exception' => $e]);
            $remaining = $deadline - microtime(true);
            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }

            return false;
        }
    }

    /** How many entries wait in the list (0 or 1; for diagnostics and tests). */
    public function length(): int
    {
        $length = $this->call('llen', $this->key);

        return is_numeric($length) ? (int) $length : 0;
    }

    /** Empty the list (for tests, and after a restore). */
    public function clear(): void
    {
        $this->call('del', $this->key);
    }

    /**
     * What `BLPOP` and `LPOP` answer when they got an entry: Predis and phpredis differ in
     * what they return for "nothing" (null, false or an empty list).
     */
    private function isEntry(mixed $popped): bool
    {
        if (is_array($popped)) {
            return $popped !== [];
        }

        return $popped !== null && $popped !== false;
    }

    /**
     * Run one Redis command on whichever client the cache uses (both name the commands alike, case-insensitively).
     */
    private function call(string $command, mixed ...$arguments): mixed
    {
        if ($this->redis === null) {
            throw new \RuntimeException('No Redis connection is configured.');
        }

        $callable = [$this->redis, $command];
        if (!is_callable($callable)) {
            throw new \RuntimeException(sprintf('The Redis client has no "%s" command.', $command));
        }

        return $callable(...$arguments);
    }
}
