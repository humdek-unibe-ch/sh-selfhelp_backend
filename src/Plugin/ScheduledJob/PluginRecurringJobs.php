<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Entity\Plugin\Plugin;
use App\Plugin\Manifest\CronSchedule;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\ScheduledJobRepository;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Recurring jobs of plugins (contract C25).
 *
 * A plugin declares `recurringJobs`: `{ name, type, cron, description }`, with `type` one of
 * its own `scheduledJobs` types and `cron` a five-field schedule in UTC no closer than five
 * minutes. There is no second queue (rule 6.1). The host keeps **exactly one queued job per
 * entry**, a normal `scheduled_jobs` row of that type whose config is `{"recurring": "<name>"}`
 * and whose due time is the next time the cron matches:
 *
 * - on install, update and enable ({@see sync()}) the row is created, re-aligned when the cron
 *   changed, and rows of entries the manifest no longer declares are removed;
 * - after every run, whatever its result ({@see requeueAfterRun()}, called by
 *   {@see JobSchedulerService}), the next row is queued, so a failed or timed-out run never
 *   ends the series;
 * - on disable and uninstall ({@see remove()}) the queued rows are removed.
 *
 * A scheduler that was down misses no row: the overdue job runs once when it is back, and the
 * next one is queued from that run. Everything that changes the rows runs under one lock, so a
 * lifecycle change and a finished run cannot both queue the next job.
 */
final class PluginRecurringJobs
{
    /** One lock for all recurring-job bookkeeping; the sections are short. */
    private const LOCK_KEY = 'plugin_recurring_jobs';
    private const LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private readonly PluginRegistryService $plugins,
        private readonly ScheduledJobRepository $jobs,
        private readonly JobSchedulerService $scheduler,
        private readonly TransactionService $transactions,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bring the queue in line with the manifest of an installed plugin: one queued job per
     * recurring entry while the plugin is enabled, none otherwise.
     */
    public function sync(Plugin $plugin): void
    {
        $manifest = new PluginManifest($plugin->getManifestJson());
        $types = $this->jobTypesOf($manifest);
        if ($types === []) {
            return;
        }

        $enabled = $plugin->isEnabled();
        $entries = $enabled ? $this->entriesOf($manifest) : [];
        $declared = array_column($entries, 'name');

        $this->locked(function () use ($types, $declared, $entries): void {
            // Entries the manifest no longer has (or all of them while disabled) lose their queued job.
            $stale = [];
            foreach ($this->jobs->findQueuedRecurringJobs($types) as $job) {
                if (!in_array($job['recurring'], $declared, true)) {
                    $stale[] = $job['id'];
                }
            }
            $this->removeJobs($stale, 'the recurring entry is gone or the plugin is not enabled');

            foreach ($entries as $entry) {
                $this->ensureEntry($entry);
            }
        });
    }

    /** Remove every queued recurring job of the plugin (it was disabled or uninstalled). */
    public function remove(Plugin $plugin): void
    {
        $types = $this->jobTypesOf(new PluginManifest($plugin->getManifestJson()));
        if ($types === []) {
            return;
        }

        $this->locked(function () use ($types): void {
            $this->removeJobs(
                array_column($this->jobs->findQueuedRecurringJobs($types), 'id'),
                'the plugin was disabled or uninstalled'
            );
        });
    }

    /**
     * A run of a recurring job has ended: queue the next one. Nothing happens when no enabled
     * plugin declares the entry any more.
     */
    public function requeueAfterRun(string $jobType, string $name): void
    {
        foreach ($this->plugins->getEnabled() as $plugin) {
            foreach ($this->entriesOf(new PluginManifest($plugin->getManifestJson())) as $entry) {
                if ($entry['type'] === $jobType && $entry['name'] === $name) {
                    $this->locked(function () use ($entry): void {
                        $this->ensureEntry($entry);
                    });

                    return;
                }
            }
        }
    }

    /**
     * Make sure exactly one queued job exists for the entry, due at a time its cron matches.
     * Call it inside {@see locked()}.
     *
     * @param array{name: string, type: string, cron: string, description: string} $entry
     */
    private function ensureEntry(array $entry): void
    {
        $schedule = CronSchedule::parse($entry['cron']);
        if ($schedule === null) {
            $this->logger->warning('Recurring plugin job has an invalid cron; nothing queued.', ['entry' => $entry]);

            return;
        }

        $queued = [];
        foreach ($this->jobs->findQueuedRecurringJobs([$entry['type']]) as $job) {
            if ($job['recurring'] === $entry['name']) {
                $queued[] = $job;
            }
        }

        // Keep the earliest queued job when the cron still matches its time; the rest are duplicates.
        $keep = null;
        $remove = [];
        foreach ($queued as $job) {
            if ($keep === null && $this->matchesSchedule($schedule, $job['date_to_be_executed'])) {
                $keep = $job['id'];
            } else {
                $remove[] = $job['id'];
            }
        }
        $this->removeJobs($remove, 'a duplicate, or the cron of the entry changed');
        if ($keep !== null) {
            return;
        }

        $next = $schedule->nextRunAfter(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($next === null) {
            $this->logger->warning('Recurring plugin job never runs; nothing queued.', ['entry' => $entry]);

            return;
        }

        $job = $this->scheduler->scheduleJob([
            'type' => $entry['type'],
            'description' => $entry['description'] !== '' ? $entry['description'] : sprintf('Recurring job "%s"', $entry['name']),
            'date_to_be_executed' => $next,
            'plugin_job_config' => ['recurring' => $entry['name']],
        ], LookupService::TRANSACTION_BY_BY_SYSTEM);
        if ($job === false) {
            $this->logger->error('Could not queue the next run of a recurring plugin job.', ['entry' => $entry]);
        }
    }

    /** Whether the cron has a run at the minute of the stored due time (`Y-m-d H:i:s`, UTC). */
    private function matchesSchedule(CronSchedule $schedule, string $due): bool
    {
        try {
            $dueAt = new \DateTimeImmutable($due, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        $run = $schedule->nextRunAfter($dueAt->modify('-1 minute'));

        return $run !== null && $run->format('Y-m-d H:i') === $dueAt->format('Y-m-d H:i');
    }

    /**
     * @param list<int> $jobIds
     */
    private function removeJobs(array $jobIds, string $reason): void
    {
        if ($jobIds === []) {
            return;
        }

        foreach ($jobIds as $jobId) {
            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'scheduled_jobs',
                $jobId,
                false,
                'Recurring plugin job removed: ' . $reason
            );
        }
        $this->jobs->deleteQueuedJobs($jobIds);
    }

    /**
     * The valid `recurringJobs` entries of a manifest, with a description that is always text.
     *
     * @return list<array{name: string, type: string, cron: string, description: string}>
     */
    private function entriesOf(PluginManifest $manifest): array
    {
        $entries = [];
        foreach ($manifest->getRecurringJobs() as $entry) {
            $name = $entry['name'] ?? null;
            $type = $entry['type'] ?? null;
            $cron = $entry['cron'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($type) || $type === '' || !is_string($cron) || $cron === '') {
                continue;
            }
            $description = $entry['description'] ?? '';
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'cron' => $cron,
                'description' => is_string($description) ? $description : '',
            ];
        }

        return $entries;
    }

    /**
     * All job types the manifest declares, recurring or not: the types a queued recurring job can have.
     *
     * @return list<string>
     */
    private function jobTypesOf(PluginManifest $manifest): array
    {
        $types = [];
        foreach ($manifest->getScheduledJobs() as $job) {
            $type = $job['type'] ?? null;
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param \Closure(): void $work
     */
    private function locked(\Closure $work): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $work();
        } finally {
            $lock->release();
        }
    }
}
