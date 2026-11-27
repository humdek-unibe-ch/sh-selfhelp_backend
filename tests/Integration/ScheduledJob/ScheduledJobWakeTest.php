<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\ScheduledJob;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\ScheduledJobRunnerRun;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\ScheduledJob\PluginJobQueueInterface;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\ScheduledJobRunnerService;
use App\Service\Core\ScheduledJobWakeSignal;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * C13: a job that is due at once rings the scheduler's doorbell (one entry on the Redis list
 * `selfhelp:scheduled_jobs:wake`) after the commit that creates it, and never on a rollback;
 * `app:scheduled-jobs:wait` returns at once with an entry and after its timeout without one; both
 * scheduler entrypoints use it instead of `sleep`.
 *
 * C13 helper: {@see PluginJobQueueInterface::queueNow()} and `queueAt()` create jobs of a plugin type.
 *
 * The list of a test run is named after its database token, so a parallel run never shares it.
 */
final class ScheduledJobWakeTest extends QaKernelTestCase
{
    private ScheduledJobWakeSignal $wake;
    private JobSchedulerService $scheduler;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wake = $this->service(ScheduledJobWakeSignal::class);
        $this->scheduler = $this->service(JobSchedulerService::class);
        $this->connection = $this->em->getConnection();
        $this->wake->clear();
    }

    protected function tearDown(): void
    {
        $this->wake->clear();
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- the doorbell ---------------------------------------------------------------------

    public function testAnImmediateJobPushesOneEntryAfterTheCommit(): void
    {
        $this->connection->beginTransaction();
        $job = $this->scheduleDueNow('qa_wake_commit');
        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame(0, $this->wake->length(), 'Nothing is pushed while the transaction is open.');

        $this->connection->commit();

        self::assertSame(1, $this->wake->length(), 'One entry is pushed once the transaction has committed.');
    }

    public function testManyJobsInOneTransactionLeaveOneEntry(): void
    {
        $this->connection->beginTransaction();
        $this->scheduleDueNow('qa_wake_many_1');
        $this->scheduleDueNow('qa_wake_many_2');
        $this->scheduleDueNow('qa_wake_many_3');
        $this->connection->commit();

        self::assertSame(1, $this->wake->length(), 'The entry says "look at the queue", not which job: the list holds one.');
    }

    public function testARolledBackJobPushesNothing(): void
    {
        $this->connection->beginTransaction();
        $this->scheduleDueNow('qa_wake_rollback');
        $this->connection->rollBack();
        $this->em->clear();

        self::assertSame(0, $this->wake->length(), 'No job exists, so nobody is woken.');
    }

    public function testAJobWithoutAnOpenTransactionWakesAtOnce(): void
    {
        $this->scheduleDueNow('qa_wake_plain');

        self::assertSame(1, $this->wake->length());
    }

    public function testAJobDueLaterPushesNothing(): void
    {
        $job = $this->scheduler->scheduleJob([
            'type' => LookupService::JOB_TYPES_TASK,
            'description' => 'qa_wake_later',
            'date_to_be_executed' => new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
            'task_config' => [],
        ], LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame(0, $this->wake->length(), 'A job with a due time waits for its time, as before.');
    }

    // -- the wait command -----------------------------------------------------------------

    public function testWaitReturnsAtOnceWhenAnEntryExists(): void
    {
        $this->wake->push();

        [$exitCode, $seconds, $output] = $this->wait(5);

        self::assertSame(0, $exitCode);
        self::assertLessThan(1.0, $seconds, 'An entry ends the wait at once.');
        self::assertStringContainsString('woken', $output);
        self::assertSame(0, $this->wake->length(), 'The wait consumes the entry.');
    }

    public function testWaitReturnsAfterTheTimeoutWithoutAnEntry(): void
    {
        [$exitCode, $seconds, $output] = $this->wait(5);

        self::assertSame(0, $exitCode, 'The wait exits 0 either way.');
        self::assertGreaterThanOrEqual(4.5, $seconds);
        self::assertLessThan(8.0, $seconds);
        self::assertStringContainsString('timeout', $output);
    }

    public function testWaitIsWokenByAnEntryPushedWhileItWaits(): void
    {
        // The same wait in another process: start it, push, and it ends long before its timeout.
        $started = microtime(true);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/console', 'app:scheduled-jobs:wait', '--timeout=30', '--env=test', '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        usleep(2_500_000);
        $this->wake->push();
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $seconds = microtime(true) - $started;

        self::assertStringContainsString('woken', $output);
        self::assertLessThan(15.0, $seconds, 'The pushed entry ended a wait that would have lasted 30 seconds.');
    }

    public function testBothSchedulerEntrypointsWaitInsteadOfSleeping(): void
    {
        foreach (['entrypoint.sh', 'prod-entrypoint.sh'] as $file) {
            $script = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/scheduler/' . $file);

            self::assertStringContainsString('app:scheduled-jobs:wait --timeout="${tick_seconds}"', $script, $file);
            self::assertStringNotContainsString('sleep "${tick_seconds}"', $script, $file);
        }
    }

    // -- the tick after a wake -------------------------------------------------------------

    public function testAWokenTickSkipsTheIntervalGate(): void
    {
        $this->installPlugin();
        $runner = $this->service(ScheduledJobRunnerService::class);
        self::assertSame(ScheduledJobRunnerRun::STATUS_SUCCEEDED, $runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER)->status);

        // A job that is due at once, created right after a run: the tick that follows its wake-up must not wait for the interval.
        $id = $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SWEEP_JOB, [], null);

        $gated = $runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER);
        self::assertSame(ScheduledJobRunnerRun::STATUS_SKIPPED_INTERVAL, $gated->status, 'An ordinary tick right after a run waits for its interval.');
        self::assertSame(0, $gated->attemptedCount);

        $woken = $runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, woken: true);
        self::assertSame(ScheduledJobRunnerRun::STATUS_SUCCEEDED, $woken->status);
        self::assertSame(1, $woken->attemptedCount, 'The woken tick took the job at once.');
        self::assertSame(1, $woken->doneCount);
        self::assertSame(
            'done',
            $this->connection->fetchOne('SELECT st.lookup_code FROM scheduled_jobs sj INNER JOIN lookups st ON st.id = sj.id_job_status WHERE sj.id = :id', ['id' => $id]),
        );
    }

    public function testAWokenTickStillHonoursTheEnabledFlag(): void
    {
        $runner = $this->service(ScheduledJobRunnerService::class);
        $runner->setEnabled(false, null);

        $result = $runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, woken: true);

        self::assertSame(ScheduledJobRunnerRun::STATUS_SKIPPED_DISABLED, $result->status, 'A disabled runner does nothing, woken or not.');
    }

    public function testTheWokenOptionOfExecuteDueIsKnown(): void
    {
        $application = new Application(self::bootedKernel());
        $definition = $application->find('app:scheduled-jobs:execute-due')->getDefinition();

        self::assertTrue($definition->hasOption('woken'));
        self::assertFalse($definition->getOption('woken')->acceptValue(), 'A flag, no value.');
    }

    // -- PluginJobQueueInterface ----------------------------------------------------------

    public function testQueueNowCreatesAJobOfThePluginTypeWithTheConfigAndTheUser(): void
    {
        $this->installPlugin();
        $user = $this->qaUser();

        $id = $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, ['sleep_seconds' => 0, 'label' => 'now'], $user->getId());

        $job = $this->em->getRepository(ScheduledJob::class)->find($id);
        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame(QaPlatformPluginFixture::SLEEP_JOB, $job->getJobType()->getLookupCode());
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());
        self::assertSame($user->getId(), $job->getUser()?->getId());
        self::assertSame(['sleep_seconds' => 0, 'label' => 'now'], $job->getConfig());
        self::assertLessThanOrEqual(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $job->getDateToBeExecuted());
        self::assertSame(1, $this->wake->length(), 'A job queued for now wakes the scheduler.');
    }

    public function testQueueNowWithoutAUserCreatesASystemJob(): void
    {
        $this->installPlugin();

        $id = $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, [], null);

        $job = $this->em->getRepository(ScheduledJob::class)->find($id);
        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertNull($job->getUser());
    }

    public function testQueueAtCreatesAFutureJobAndDoesNotWake(): void
    {
        $this->installPlugin();
        $at = new \DateTimeImmutable('2031-03-04 05:06:00', new \DateTimeZone('UTC'));

        $id = $this->queue()->queueAt(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, ['label' => 'later'], $this->qaUser()->getId(), $at);

        $job = $this->em->getRepository(ScheduledJob::class)->find($id);
        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame('2031-03-04 05:06:00', $job->getDateToBeExecuted()->format('Y-m-d H:i:s'));
        self::assertSame(['label' => 'later'], $job->getConfig());
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());
        self::assertSame(0, $this->wake->length(), 'A job for later waits for its time.');
    }

    public function testAnUnregisteredJobTypeThrows(): void
    {
        $this->installPlugin();

        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, 'qa_unregistered_job', [], null);
    }

    public function testAPluginThatIsNotInstalledThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queueNow('qa-no-such-plugin', QaPlatformPluginFixture::SLEEP_JOB, [], null);
    }

    public function testADisabledPluginThrows(): void
    {
        $this->installPlugin(enabled: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, [], null);
    }

    public function testAConfigKeyOfTheHostIsRefused(): void
    {
        $this->installPlugin();

        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, ['recurring' => 'x'], null);
    }

    public function testAnUnknownUserIsRefused(): void
    {
        $this->installPlugin();

        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queueNow(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::SLEEP_JOB, [], 2_000_000_000);
    }

    // -- helpers --------------------------------------------------------------------------

    private function scheduleDueNow(string $description): ScheduledJob|false
    {
        return $this->scheduler->scheduleJob([
            'type' => LookupService::JOB_TYPES_TASK,
            'description' => $description,
            'date_to_be_executed' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'task_config' => [],
        ], LookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    /**
     * @return array{0: int, 1: float, 2: string} exit code, seconds the command took, its output
     */
    private function wait(int $timeout): array
    {
        $application = new Application(self::bootedKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('app:scheduled-jobs:wait'));

        $started = microtime(true);
        $exitCode = $tester->execute(['--timeout' => (string) $timeout]);

        return [$exitCode, microtime(true) - $started, $tester->getDisplay()];
    }

    private function queue(): PluginJobQueueInterface
    {
        $queue = self::getContainer()->get('test.plugin_job_queue');
        self::assertInstanceOf(PluginJobQueueInterface::class, $queue);

        return $queue;
    }

    private function installPlugin(bool $enabled = true): void
    {
        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            $enabled,
        );
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
