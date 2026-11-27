<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Entity\ScheduledJob;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Lifecycle\PluginDisabledEvent;
use App\Plugin\Event\Lifecycle\PluginEnabledEvent;
use App\Plugin\Event\Lifecycle\PluginInstalledEvent;
use App\Plugin\Event\Lifecycle\PluginUninstalledEvent;
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\ScheduledJob\PluginJobQueueInterface;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * C25: recurring jobs of plugins.
 *
 * A plugin's manifest lists `recurringJobs` (`name`, `type`, `cron`, `description`). The host keeps
 * exactly one queued job per entry, a normal `scheduled_jobs` row of that type with the config
 * `{"recurring": "<name>"}`, due at the next time the cron matches:
 *
 *  - install, update and enable queue it (once, however often they run) and realign it when the
 *    cron changed, or remove it when the entry is gone;
 *  - after a run, whatever its result (done, failed, timed out), the next one is queued at the next
 *    cron time counted from now;
 *  - disable and uninstall remove the queued job and keep the ones that ran.
 *
 * The lifecycle orchestrators write the plugin lock file and the bundle list of the working tree, so
 * the success path is not driven here (see `PluginDependencyTest`); the test does what they do for
 * the queue: change the plugin row, reset the plugin caches and dispatch the lifecycle event.
 *
 * The QA platform plugin ({@see QaPlatformPluginFixture}) declares `qa_sweep`: type `qa_sweep_job`,
 * every five minutes.
 */
final class PluginRecurringJobTest extends QaKernelTestCase
{
    private const NAME = QaPlatformPluginFixture::RECURRING_NAME;

    private Connection $db;
    private EventDispatcherInterface $events;
    private JobSchedulerService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->em->getConnection();
        $this->events = $this->service(EventDispatcherInterface::class);
        $this->scheduler = $this->service(JobSchedulerService::class);
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- enable, install, update ----------------------------------------------------------

    public function testEnablingThePluginQueuesOneFutureJobForTheEntry(): void
    {
        $plugin = $this->install(enabled: false);
        self::assertSame([], $this->jobs(), 'A disabled plugin has no recurring job.');

        $this->enable($plugin);

        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame('queued', $jobs[0]['status']);
        self::assertSame(['recurring' => self::NAME], $jobs[0]['config']);
        self::assertSame('QA recurring sweep', $jobs[0]['description']);
        $this->assertDueAtTheNextFiveMinuteMark($jobs[0]['due']);
    }

    public function testInstallingAnEnabledPluginQueuesTheJobAndInstallingADisabledOneDoesNot(): void
    {
        $plugin = $this->install(enabled: true);
        $this->events->dispatch(new PluginInstalledEvent($plugin, null));
        self::assertCount(1, $this->jobs('queued'));

        $this->removeAllJobs();
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->events->dispatch(new PluginInstalledEvent($plugin, null));

        self::assertSame([], $this->jobs(), 'A plugin that is installed but not enabled queues nothing.');
    }

    public function testEnablingAgainLeavesOneJobAndKeepsTheSame(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];

        $this->events->dispatch(new PluginEnabledEvent($plugin, null));
        $this->events->dispatch(new PluginEnabledEvent($plugin, null));
        $this->events->dispatch(new PluginInstalledEvent($plugin, null));
        $this->events->dispatch(new PluginUpdatedEvent($plugin, null, '1.0.0', '1.0.0'));

        $jobs = $this->jobs();
        self::assertCount(1, $jobs, 'However often the lifecycle runs, there is one queued job per entry.');
        self::assertSame($first['id'], $jobs[0]['id'], 'The queued job stays; it is not replaced.');
        self::assertEquals($first['due'], $jobs[0]['due']);
    }

    public function testAJobOverdueBecauseTheSchedulerWasDownIsKeptNotDuplicated(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];
        $overdue = $first['due']->modify('-2 days');
        $this->setDue($first['id'], $overdue);

        $this->events->dispatch(new PluginEnabledEvent($plugin, null));

        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame($first['id'], $jobs[0]['id'], 'The overdue job still runs once when the scheduler is back.');
        self::assertEquals($overdue, $jobs[0]['due']);
    }

    public function testAChangedCronMovesTheQueuedJob(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $before = $this->jobs()[0];

        $this->setRecurring($plugin, [['name' => self::NAME, 'type' => QaPlatformPluginFixture::SWEEP_JOB, 'cron' => '0 3 * * *', 'description' => 'QA recurring sweep']]);
        $this->events->dispatch(new PluginUpdatedEvent($plugin, null, '1.0.0', '1.0.1'));

        $jobs = $this->jobs();
        self::assertCount(1, $jobs, 'The job of the old schedule is replaced, not kept next to the new one.');
        self::assertNotSame($before['id'], $jobs[0]['id']);
        $due = $jobs[0]['due'];
        self::assertSame('03:00:00', $due->format('H:i:s'), 'Due at 03:00 UTC, the next time `0 3 * * *` matches.');
        self::assertGreaterThan($this->now(), $due);
        self::assertLessThanOrEqual($this->now()->modify('+1 day +5 seconds'), $due);
    }

    public function testAnEntryThatTheNewManifestDropsLosesItsJobAndOneItAddsGetsOne(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        self::assertCount(1, $this->jobs('queued'));

        $this->setRecurring($plugin, []);
        $this->events->dispatch(new PluginUpdatedEvent($plugin, null, '1.0.0', '1.0.1'));
        self::assertSame([], $this->jobs('queued'), 'An entry the manifest no longer declares loses its queued job.');

        $this->setRecurring($plugin, [['name' => 'qa_hourly', 'type' => QaPlatformPluginFixture::SWEEP_JOB, 'cron' => '0 * * * *', 'description' => 'QA hourly']]);
        $this->events->dispatch(new PluginUpdatedEvent($plugin, null, '1.0.1', '1.0.2'));

        $jobs = $this->jobs('queued');
        self::assertCount(1, $jobs);
        self::assertSame(['recurring' => 'qa_hourly'], $jobs[0]['config']);
        self::assertSame('00:00', $jobs[0]['due']->format('i:s'), 'Due on the full hour.');
    }

    public function testEveryEntryHasItsOwnJobAndARunRequeuesOnlyItsOwn(): void
    {
        $plugin = $this->install(enabled: false);
        $this->setRecurring($plugin, [
            ['name' => self::NAME, 'type' => QaPlatformPluginFixture::SWEEP_JOB, 'cron' => '*/5 * * * *', 'description' => 'every five minutes'],
            ['name' => 'qa_hourly', 'type' => QaPlatformPluginFixture::SWEEP_JOB, 'cron' => '0 * * * *', 'description' => 'every hour'],
        ]);
        $this->enable($plugin);

        $queued = $this->jobs('queued');
        self::assertCount(2, $queued);
        $byName = array_column(array_map(static fn (array $j): array => ['name' => $j['config']['recurring'] ?? null, 'job' => $j], $queued), 'job', 'name');
        self::assertSame(['qa_hourly', self::NAME], $this->sorted(array_keys($byName)));
        $hourly = $byName['qa_hourly'];
        $sweep = $byName[self::NAME];

        $this->scheduler->executeJob($sweep['id'], LookupService::TRANSACTION_BY_BY_SYSTEM);

        $after = $this->jobs('queued');
        self::assertCount(2, $after, 'The run of one entry queued its next job; the other entry is untouched.');
        self::assertContains($hourly['id'], array_column($after, 'id'));
        self::assertNotContains($sweep['id'], array_column($after, 'id'));
    }

    // -- after a run ------------------------------------------------------------------------

    public function testRunningTheJobQueuesTheNextOneAtTheNextCronTimeFromNow(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];
        // The scheduler was away for days: the next run is counted from now, not from the old time.
        $this->setDue($first['id'], $this->now()->modify('-3 days'));

        $result = $this->scheduler->executeJob($first['id'], LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        self::assertSame('done', $result->getStatus()->getLookupCode());
        $queued = $this->jobs('queued');
        self::assertCount(1, $queued, 'Exactly one next job is queued.');
        self::assertNotSame($first['id'], $queued[0]['id']);
        self::assertSame(['recurring' => self::NAME], $queued[0]['config']);
        $this->assertDueAtTheNextFiveMinuteMark($queued[0]['due']);
        self::assertSame(['done'], array_column($this->jobs('done'), 'status'), 'The run itself stays in the history.');
    }

    public function testAFailedRunStillQueuesTheNextOne(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];
        // The QA handler returns false for a job whose config says `fail`.
        $this->db->executeStatement("UPDATE scheduled_jobs SET config = JSON_SET(config, '$.fail', TRUE) WHERE id = :id", ['id' => $first['id']]);

        $result = $this->scheduler->executeJob($first['id'], LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        self::assertSame('failed', $result->getStatus()->getLookupCode());
        $queued = $this->jobs('queued');
        self::assertCount(1, $queued, 'A failed run does not end the series.');
        self::assertSame(['recurring' => self::NAME], $queued[0]['config'], 'The next job does not inherit the failure marker.');
        $this->assertDueAtTheNextFiveMinuteMark($queued[0]['due']);
    }

    public function testATimedOutRunStillQueuesTheNextOne(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];

        $marked = $this->scheduler->failJob($first['id'], 'timeout', LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertTrue($marked);
        self::assertSame(['failed'], array_column($this->jobs('failed'), 'status'));
        $queued = $this->jobs('queued');
        self::assertCount(1, $queued, 'A run stopped for taking too long does not end the series.');
        self::assertNotSame($first['id'], $queued[0]['id']);
        $this->assertDueAtTheNextFiveMinuteMark($queued[0]['due']);
    }

    public function testAJobThatIsNotRecurringQueuesNoNextOne(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $this->removeAllJobs();
        $id = $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SWEEP_JOB, [], null);
        $this->scheduler->executeJob($id, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertSame([], $this->jobs('queued'), 'Only a job with a `recurring` name is requeued.');
    }

    public function testNoNextRunIsQueuedWhenThePluginWasDisabledMeanwhile(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];
        // The administrator disabled the plugin; the removal has not run yet (no event).
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        $this->scheduler->executeJob($first['id'], LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertSame([], $this->jobs('queued'));
    }

    // -- disable, uninstall -----------------------------------------------------------------

    public function testDisablingRemovesTheQueuedJobAndKeepsTheOnesThatRan(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        $first = $this->jobs()[0];
        $this->scheduler->executeJob($first['id'], LookupService::TRANSACTION_BY_BY_SYSTEM);
        self::assertCount(1, $this->jobs('queued'));

        $this->disable($plugin);

        self::assertSame([], $this->jobs('queued'), 'The queued job is gone with the plugin.');
        self::assertCount(1, $this->jobs('done'), 'What already ran stays in the history.');

        $this->enable($plugin);
        self::assertCount(1, $this->jobs('queued'), 'Enabling again queues one job again.');
    }

    public function testUninstallingRemovesTheQueuedJob(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        self::assertCount(1, $this->jobs('queued'));

        $this->events->dispatch(new PluginUninstalledEvent($plugin, null));

        self::assertSame([], $this->jobs('queued'));
    }

    public function testDisablingKeepsQueuedJobsThatAreNotRecurring(): void
    {
        $plugin = $this->install(enabled: false);
        $this->enable($plugin);
        // A job of the same type that an action or the plugin queued once: it has no `recurring` name.
        $plain = $this->queue()->queueAt(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::SWEEP_JOB,
            ['batch' => 1],
            null,
            $this->now()->modify('+1 day'),
        );

        $this->disable($plugin);

        $queued = $this->jobs('queued');
        self::assertSame([$plain], array_column($queued, 'id'), 'Only the recurring job is removed.');
    }

    // -- helpers ----------------------------------------------------------------------------

    private function queue(): PluginJobQueueInterface
    {
        $queue = self::getContainer()->get('test.plugin_job_queue');
        self::assertInstanceOf(PluginJobQueueInterface::class, $queue);

        return $queue;
    }

    private function install(bool $enabled): Plugin
    {
        return QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            $enabled,
        );
    }

    /** What `PluginEnabler` does for the queue: change the row, reset the caches, dispatch the event. */
    private function enable(Plugin $plugin): void
    {
        $plugin->setEnabled(true);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
        $this->events->dispatch(new PluginEnabledEvent($plugin, null));
    }

    private function disable(Plugin $plugin): void
    {
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
        $this->events->dispatch(new PluginDisabledEvent($plugin, null));
    }

    /**
     * Replace the `recurringJobs` of the stored manifest, as a plugin update would.
     *
     * @param list<array<string, string>> $entries
     */
    private function setRecurring(Plugin $plugin, array $entries): void
    {
        $manifest = $plugin->getManifestJson();
        $manifest['recurringJobs'] = $entries;
        $plugin->setManifestJson($manifest);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
    }

    /**
     * The jobs of the sweep type, oldest first.
     *
     * @return list<array{id: int, status: string, due: \DateTimeImmutable, config: array<string, mixed>, description: string}>
     */
    private function jobs(?string $status = null): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT sj.id AS id, st.lookup_code AS status, sj.date_to_be_executed AS due, sj.config AS config, sj.description AS description
               FROM scheduled_jobs sj
         INNER JOIN lookups jt ON jt.id = sj.id_job_types AND jt.type_code = 'jobTypes' AND jt.lookup_code = :type
         INNER JOIN lookups st ON st.id = sj.id_job_status AND st.type_code = 'scheduledJobsStatus'
           ORDER BY sj.id",
            ['type' => QaPlatformPluginFixture::SWEEP_JOB],
        );

        $jobs = [];
        foreach ($rows as $row) {
            $job = [
                'id' => self::coerceInt($row['id']),
                'status' => self::coerceString($row['status']),
                'due' => new \DateTimeImmutable(self::coerceString($row['due']), new \DateTimeZone('UTC')),
                'config' => self::asArray(json_decode(self::coerceString($row['config']), true)),
                'description' => self::coerceString($row['description']),
            ];
            if ($status === null || $job['status'] === $status) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    private function removeAllJobs(): void
    {
        $this->db->executeStatement(
            "DELETE sj FROM scheduled_jobs sj INNER JOIN lookups jt ON jt.id = sj.id_job_types AND jt.lookup_code = :type",
            ['type' => QaPlatformPluginFixture::SWEEP_JOB],
        );
    }

    private function setDue(int $jobId, \DateTimeImmutable $due): void
    {
        $this->db->executeStatement(
            'UPDATE scheduled_jobs SET date_to_be_executed = :due WHERE id = :id',
            ['due' => $due->format('Y-m-d H:i:s'), 'id' => $jobId],
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** The job is due on a full five-minute mark in the future, no later than five minutes from now. */
    private function assertDueAtTheNextFiveMinuteMark(\DateTimeImmutable $due): void
    {
        self::assertSame('00', $due->format('s'));
        self::assertSame(0, ((int) $due->format('i')) % 5, 'Due on a minute `*/5` matches.');
        self::assertGreaterThan($this->now()->modify('-1 second'), $due, 'Due in the future.');
        self::assertLessThanOrEqual($this->now()->modify('+5 minutes +5 seconds'), $due, 'Due at the next mark, not a later one.');
    }

    /**
     * @param list<int|string> $values
     *
     * @return list<int|string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
