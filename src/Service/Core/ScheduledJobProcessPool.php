<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs due jobs in child processes, up to a number at once (contract C22).
 *
 * Each job runs in its own `app:scheduled-jobs:execute-one <id> --by-cron-job` child, so a slow
 * or stuck job holds up neither the other jobs nor the scheduler. The claim stays atomic: the
 * child's {@see JobSchedulerService::executeJob()} claims the job in one guarded UPDATE, so two
 * runners can never run the same job (the second one loses the claim and does nothing).
 *
 * A child that is still running after the timeout of its job type
 * ({@see ScheduledJobTimeouts}, 10 to 900 seconds, 120 for core job types) is stopped. A stopped
 * child leaves nothing behind: its transaction, the claim included, is rolled back with the
 * connection. The pool then marks the job as failed with the reason `timeout`
 * ({@see JobSchedulerService::failJob()}), unless the job reached an end state in the meantime.
 * A child that dies by itself (a fatal error) leaves the job queued, as a job that kills an
 * in-process runner does.
 */
class ScheduledJobProcessPool
{
    /** The longest wait between two looks at the running children, in microseconds. */
    private const POLL_MICROSECONDS = 100_000;

    /** How long a stopped child gets to end before it is killed, in seconds. */
    private const STOP_GRACE_SECONDS = 3.0;

    /** The most children at once, whatever is asked for. */
    public const MAX_CONCURRENCY = 32;

    public function __construct(
        private readonly JobSchedulerService $jobScheduler,
        private readonly ScheduledJobTimeouts $timeouts,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    /**
     * Run the jobs, at most `$concurrency` at the same time, and wait until all are over.
     *
     * @param list<array{id: int, type: string}> $jobs the due jobs, in the order they start
     * @param int $concurrency how many children run at once (1 to {@see MAX_CONCURRENCY})
     * @param string $transactionBy the origin recorded for a job the pool ends itself
     * @param (\Closure(): void)|null $onTick called about ten times a second while children run (the runner keeps its lock alive)
     *
     * @return list<array{id: int, status: string}> one entry per job: the status code the job ended with
     *                                              (`done`, `failed`, `skipped...`), or `queued` when it was not touched
     */
    public function run(array $jobs, int $concurrency, string $transactionBy, ?\Closure $onTick = null): array
    {
        $concurrency = max(1, min(self::MAX_CONCURRENCY, $concurrency));

        $pending = $jobs;
        /** @var array<int, array{process: Process, type: string, started: float, timeout: int}> $running */
        $running = [];
        $outcomes = [];

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $concurrency) {
                $job = array_shift($pending);
                $running[$job['id']] = [
                    'process' => $this->start($job['id']),
                    'type' => $job['type'],
                    'started' => microtime(true),
                    'timeout' => $this->timeouts->secondsFor($job['type']),
                ];
            }

            foreach ($running as $jobId => $entry) {
                if (!$entry['process']->isRunning()) {
                    unset($running[$jobId]);
                    $outcomes[] = ['id' => $jobId, 'status' => $this->finished($jobId, $entry['process'])];
                } elseif (microtime(true) - $entry['started'] > $entry['timeout']) {
                    unset($running[$jobId]);
                    $outcomes[] = ['id' => $jobId, 'status' => $this->timedOut($jobId, $entry, $transactionBy)];
                }
            }

            if ($onTick !== null && $running !== []) {
                $onTick();
            }
            if ($running !== []) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        return $outcomes;
    }

    private function start(int $jobId): Process
    {
        $php = (new PhpExecutableFinder())->find(false);
        $command = [
            is_string($php) && $php !== '' ? $php : PHP_BINARY,
            $this->projectDir . '/bin/console',
            'app:scheduled-jobs:execute-one',
            (string) $jobId,
            '--by-cron-job',
            '--env=' . $this->environment,
            '--no-interaction',
        ];
        if (!$this->debug) {
            $command[] = '--no-debug';
        }

        $process = new Process($command, $this->projectDir);
        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    /**
     * A child ended on its own. Exit code 0: the job ran (its status says how it went); 1: the claim
     * was lost or the job could not be executed; any other code: the process died (a fatal error).
     * The job is left as `executeJob` left it; a dead process rolled its claim back, so the job is
     * queued again for the next tick, exactly as when a job kills an in-process runner.
     */
    private function finished(int $jobId, Process $process): string
    {
        $exitCode = $process->getExitCode();
        if ($exitCode !== 0) {
            $this->logger->warning('Scheduled job was not completed by its child process.', [
                'jobId' => $jobId,
                'exitCode' => $exitCode,
                'output' => trim($process->getOutput() . $process->getErrorOutput()),
            ]);
        }

        return $this->statusOf($jobId);
    }

    /**
     * A child ran over the timeout of its job type: stop it, then end the job.
     *
     * @param array{process: Process, type: string, started: float, timeout: int} $entry
     */
    private function timedOut(int $jobId, array $entry, string $transactionBy): string
    {
        $entry['process']->stop(self::STOP_GRACE_SECONDS);

        $this->logger->error('Scheduled job ran over its timeout; its process was stopped.', [
            'jobId' => $jobId,
            'jobType' => $entry['type'],
            'timeoutSeconds' => $entry['timeout'],
        ]);
        $this->jobScheduler->failJob($jobId, 'timeout', $transactionBy);

        return $this->statusOf($jobId);
    }

    /** The status code the job has now, read through the connection (the entity manager would show what it loaded before). */
    private function statusOf(int $jobId): string
    {
        $code = $this->connection->fetchOne(
            'SELECT st.lookup_code
               FROM scheduled_jobs sj
         INNER JOIN lookups st ON st.id = sj.id_job_status
              WHERE sj.id = :id',
            ['id' => $jobId]
        );

        return is_string($code) ? $code : LookupService::SCHEDULED_JOBS_STATUS_FAILED;
    }
}
