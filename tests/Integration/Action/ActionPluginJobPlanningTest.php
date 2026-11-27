<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Action;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Action\ActionConfig;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\ScheduledJobWakeSignal;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Plugin\QaPlatformJobHandler;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaKernelTestCase;

/**
 * C12: an action job whose type an enabled plugin offers is planned as a `scheduled_jobs` row of that
 * type when a record is saved (finished trigger), exactly like the jobs of the core types:
 *
 *  - the row's config holds the `plugin_config` with the `{{variables}}` of the properties marked
 *    `variable-text` resolved, plus `record_id`, `data_table_id` and `trigger`;
 *  - the conditions of block and job decide whether it is planned, the condition on execute decides
 *    whether the run happens;
 *  - the job is due at once, wakes the scheduler (C13) and leaves the run to it: the immediate
 *    executor does not run a plugin job;
 *  - running the job calls the plugin's handler;
 *  - a type of a disabled plugin, or one the plugin does not offer to actions, plans nothing.
 *
 * It uses the real saving path (`DataService::saveData`, the call of the form controller).
 */
final class ActionPluginJobPlanningTest extends QaKernelTestCase
{
    private const TABLE = 'qa_c12_plan_table';

    private DataService $dataService;
    private JobSchedulerService $scheduler;
    private ScheduledJobWakeSignal $wake;
    private ActionFactory $actions;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataService = $this->service(DataService::class);
        $this->scheduler = $this->service(JobSchedulerService::class);
        $this->wake = $this->service(ScheduledJobWakeSignal::class);
        $this->actions = new ActionFactory($this->em);
        $this->wake->clear();
        QaPlatformJobHandler::$calls = [];

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->user = $user;

        $this->installPlugin();
    }

    protected function tearDown(): void
    {
        $this->wake->clear();
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testARecordSavePlansAJobOfThePluginTypeWithTheResolvedConfig(): void
    {
        $action = $this->action([$this->noteJob(['item_id' => 7, 'note' => 'Hi {{recipient.name}}: {{record.qa_answer}}', 'priority' => 3])]);

        $recordId = $this->save('blue');

        $job = $this->onlyJob($action);
        self::assertSame(QaPlatformPluginFixture::NOTE_JOB, $job->getJobType()->getLookupCode());
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());
        self::assertSame($this->user->getId(), $job->getUser()?->getId());
        self::assertSame($recordId, $job->getDataRow()?->getId());

        $config = $job->getConfig() ?? [];
        self::assertSame(
            $this->sorted(['item_id' => 7, 'note' => 'Hi ' . $this->user->getName() . ': blue', 'priority' => 3]),
            $this->sorted($config['plugin_config'] ?? null),
            'The variable-text property is resolved for the recipient and the record; the others are as saved.',
        );
        self::assertSame($recordId, $config['record_id'] ?? null);
        self::assertSame($action->getDataTable()?->getId(), $config['data_table_id'] ?? null);
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_FINISHED, $config['trigger'] ?? null);
        self::assertSame(QaPlatformPluginFixture::NOTE_JOB, $config['action_job_type'] ?? null);
    }

    public function testThePlannedJobIsDueAtOnceWakesTheSchedulerAndIsNotRunByTheImmediateExecutor(): void
    {
        $action = $this->action([$this->noteJob(['item_id' => 7])]);

        $this->save('green');

        $job = $this->onlyJob($action);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode(), 'The scheduler runs a plugin job, not the request.');
        self::assertSame([], QaPlatformJobHandler::$calls);
        self::assertLessThanOrEqual(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $job->getDateToBeExecuted());
        self::assertSame(1, $this->wake->length(), 'A job that is due now rings the scheduler.');
    }

    public function testRunningThePlannedJobCallsTheHandlerOfThePlugin(): void
    {
        $action = $this->action([$this->noteJob(['item_id' => 7, 'note' => 'Run {{record.qa_answer}}'])]);
        $recordId = $this->save('red');
        $job = $this->onlyJob($action);

        $result = $this->scheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_CRON_JOB);

        self::assertInstanceOf(ScheduledJob::class, $result);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $result->getStatus()->getLookupCode());
        self::assertCount(1, QaPlatformJobHandler::$calls);
        $call = QaPlatformJobHandler::$calls[0];
        self::assertSame(QaPlatformPluginFixture::NOTE_JOB, $call['type']);
        self::assertSame($job->getId(), $call['id']);
        self::assertSame($this->sorted(['item_id' => 7, 'note' => 'Run red']), $this->sorted($call['config']['plugin_config'] ?? null));
        self::assertSame($recordId, $call['config']['record_id'] ?? null);
    }

    public function testAJobConditionThatFailsPlansNothingAndOneThatPassesPlansTheJob(): void
    {
        $blocked = $this->action(
            [$this->noteJob(['item_id' => 7]) + [ActionConfig::CONDITION => [ActionConfig::JSON_LOGIC => ['==' => [1, 2]]]]],
            'qa_c12_plan_job_cond_no',
        );
        $allowed = $this->action(
            [$this->noteJob(['item_id' => 8]) + [ActionConfig::CONDITION => [ActionConfig::JSON_LOGIC => ['==' => [1, 1]]]]],
            'qa_c12_plan_job_cond_yes',
        );

        $this->save('x');

        self::assertSame([], $this->jobsOf($blocked), 'A job whose condition fails is not planned.');
        self::assertCount(1, $this->jobsOf($allowed));
    }

    public function testABlockConditionThatFailsPlansNothing(): void
    {
        $action = $this->action(
            [$this->noteJob(['item_id' => 7])],
            'qa_c12_plan_block_cond',
            [ActionConfig::CONDITION => [ActionConfig::JSON_LOGIC => ['==' => [1, 2]]]],
        );

        $this->save('x');

        self::assertSame([], $this->jobsOf($action));
    }

    public function testTheConditionOnExecuteDecidesWhetherTheRunHappens(): void
    {
        $action = $this->action([$this->noteJob(['item_id' => 7]) + [
            ActionConfig::ON_JOB_EXECUTE => [ActionConfig::CONDITION => [ActionConfig::JSON_LOGIC => ['==' => [1, 2]]]],
        ]]);
        $this->save('x');
        $job = $this->onlyJob($action);
        self::assertArrayHasKey('condition', $job->getConfig() ?? [], 'The condition on execute is stored with the job.');

        $result = $this->scheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_CRON_JOB);

        self::assertInstanceOf(ScheduledJob::class, $result);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_FAILED, $result->getStatus()->getLookupCode(), 'As for a core job: the condition is not met.');
        self::assertSame([], QaPlatformJobHandler::$calls, 'The handler is not called.');
    }

    public function testAPluginJobAndACoreJobOfOneActionAreBothPlanned(): void
    {
        $coreJob = [
            ActionConfig::JOB_NAME => 'qa_c12_core',
            ActionConfig::JOB_TYPE => ActionConfig::JOB_TYPE_ADD_GROUP,
            ActionConfig::SCHEDULE_TIME => [ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY],
            ActionConfig::JOB_ADD_REMOVE_GROUPS => [],
        ];
        $action = $this->action([$this->noteJob(['item_id' => 7]), $coreJob]);

        $this->save('x');

        $types = array_map($this->jobTypeOf(...), $this->jobsOf($action));
        sort($types);
        self::assertSame([QaPlatformPluginFixture::NOTE_JOB, LookupService::JOB_TYPES_TASK], $types);
    }

    public function testAJobOfADisabledPluginIsNotPlanned(): void
    {
        $action = $this->action([$this->noteJob(['item_id' => 7])]);
        $plugin = $this->em->getRepository(\App\Entity\Plugin\Plugin::class)->findOneBy(['pluginId' => QaPlatformPluginFixture::PLUGIN_ID]);
        self::assertNotNull($plugin);
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        $this->save('x');

        self::assertSame([], $this->jobsOf($action), 'The plugin was disabled after the action was saved: nothing is planned.');
        self::assertSame(0, $this->wake->length());
    }

    public function testATypeThePluginDoesNotOfferToActionsIsNotPlanned(): void
    {
        // The type exists for jobs the plugin queues itself, but is not `actionPlannable`.
        $job = $this->noteJob(['item_id' => 7]);
        $job[ActionConfig::JOB_TYPE] = QaPlatformPluginFixture::SLEEP_JOB;
        $action = $this->action([$job]);

        $this->save('x');

        self::assertSame([], $this->jobsOf($action));
    }

    // -- helpers -------------------------------------------------------------------------

    /**
     * A config with its keys in order: MySQL keeps a JSON column in its own key order.
     *
     * @return array<mixed>
     */
    private function sorted(mixed $config): array
    {
        self::assertIsArray($config);
        ksort($config);

        return $config;
    }

    private function installPlugin(): void
    {
        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    /**
     * A job of the QA note type, due at once.
     *
     * @param array<string, mixed> $pluginConfig
     *
     * @return array<string, mixed>
     */
    private function noteJob(array $pluginConfig): array
    {
        return [
            ActionConfig::JOB_NAME => 'qa_c12_note',
            ActionConfig::JOB_TYPE => QaPlatformPluginFixture::NOTE_JOB,
            ActionConfig::PLUGIN_CONFIG => $pluginConfig,
            ActionConfig::SCHEDULE_TIME => [ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY],
        ];
    }

    /**
     * A finished-trigger action on the QA table with one block that holds the jobs.
     *
     * @param list<array<string, mixed>> $jobs
     * @param array<string, mixed> $blockExtra
     */
    private function action(array $jobs, string $name = 'qa_c12_plan_action', array $blockExtra = []): Action
    {
        $table = $this->actions->createDataTable(self::TABLE);

        return $this->actions->createAction(
            $table,
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            [ActionConfig::BLOCKS => [[ActionConfig::JOBS => $jobs] + $blockExtra]],
            $name,
        );
    }

    /**
     * Save a record of the QA table for the QA user: what the form controller does.
     */
    private function save(string $answer): int
    {
        $recordId = $this->dataService->saveData(
            self::TABLE,
            ['id_users' => (int) $this->user->getId(), 'qa_answer' => $answer],
            LookupService::TRANSACTION_BY_BY_USER,
        );
        self::assertIsInt($recordId);
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $this->user = $user;

        return $recordId;
    }

    /**
     * @return list<ScheduledJob>
     */
    private function jobsOf(Action $action): array
    {
        return $this->em->getRepository(ScheduledJob::class)->findBy(['action' => $action->getId()]);
    }

    private function jobTypeOf(ScheduledJob $job): string
    {
        $code = $job->getJobType()->getLookupCode();
        self::assertNotNull($code, 'Every job has a job type.');

        return $code;
    }

    private function onlyJob(Action $action): ScheduledJob
    {
        $jobs = $this->jobsOf($action);
        self::assertCount(1, $jobs, 'The record save plans exactly one job for the action.');

        return $jobs[0];
    }
}
