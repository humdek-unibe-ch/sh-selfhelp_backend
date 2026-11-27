<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Action;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Action;
use App\Entity\DataRevision;
use App\Entity\DataRow;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\ScheduledJob\PluginJobQueueInterface;
use App\Service\Action\ActionConfig;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\Versioning\VersionRef;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaKernelTestCase;

/**
 * C26: a job planned from a record save keeps `record_ref` = `record:<id>@v<n>` of the
 * version written by that save. Editing the record later leaves the job's reference and
 * resolved text alone. A plugin job carries the same value in its config. A `queueNow` job
 * and a reminder have it null.
 */
final class ActionJobRecordRefTest extends QaKernelTestCase
{
    private const TABLE = 'qa_c26_record_ref_table';

    private DataService $dataService;
    private ActionFactory $actions;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataService = $this->service(DataService::class);
        $this->actions = new ActionFactory($this->em);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->user = $user;

        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    protected function tearDown(): void
    {
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testACoreEmailJobKeepsTheRecordVersionOfTheSaveThatPlannedIt(): void
    {
        $action = $this->emailAction('Hello {{recipient.email}}', 'Body for {{recipient.email}}');

        $recordId = $this->save('first');
        $job = $this->onlyJob($action);

        $expectedRef = VersionRef::record($recordId, 1);
        self::assertSame($expectedRef, $job->getRecordRef());
        self::assertSame(['kind' => 'record', 'id' => $recordId, 'version' => 1], VersionRef::parse((string) $job->getRecordRef()));
        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        self::assertNotNull(
            $this->em->getRepository(DataRevision::class)->findOneBy(['dataRow' => $row, 'version' => 1]),
            'The matching data_revisions row exists.',
        );

        $resolved = $this->emailTexts($job);
        self::assertStringContainsString((string) $this->user->getEmail(), $resolved['subject'], 'The subject was resolved for the recipient.');

        // Edit the record again: the job's reference and resolved text stay as they were.
        $this->dataService->saveData(
            self::TABLE,
            ['id_users' => (int) $this->user->getId(), 'qa_answer' => 'second', 'trigger_type' => LookupService::ACTION_TRIGGER_TYPES_UPDATED],
            LookupService::TRANSACTION_BY_BY_USER,
            ['record_id' => $recordId],
            false,
            null,
            new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM),
        );
        $this->em->clear();

        $jobAgain = $this->em->getRepository(ScheduledJob::class)->find($job->getId());
        self::assertInstanceOf(ScheduledJob::class, $jobAgain);
        self::assertSame($expectedRef, $jobAgain->getRecordRef(), 'A later edit does not change the job\'s record_ref.');
        self::assertSame($resolved, $this->emailTexts($jobAgain), 'The resolved text is unchanged.');

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        self::assertSame(2, $row->getVersion(), 'The record itself moved on.');
    }

    public function testAPluginJobCarriesRecordRefInConfigAndColumn(): void
    {
        $action = $this->actions->createAction(
            $this->actions->createDataTable(self::TABLE),
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            [ActionConfig::BLOCKS => [[ActionConfig::JOBS => [[
                ActionConfig::JOB_NAME => 'qa_c26_note',
                ActionConfig::JOB_TYPE => QaPlatformPluginFixture::NOTE_JOB,
                ActionConfig::PLUGIN_CONFIG => ['item_id' => 7, 'note' => 'Hi'],
                ActionConfig::SCHEDULE_TIME => [ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY],
            ]]]]],
            'qa_c26_plugin_action',
        );

        $recordId = $this->save('note');
        $job = $this->onlyJob($action);

        $expectedRef = VersionRef::record($recordId, 1);
        self::assertSame($expectedRef, $job->getRecordRef());
        self::assertSame($expectedRef, $job->getConfig()['record_ref'] ?? null);
        self::assertSame($recordId, $job->getConfig()['record_id'] ?? null);
    }

    public function testQueueNowAndReminderJobsHaveNullRecordRef(): void
    {
        $queueId = $this->service(PluginJobQueueInterface::class)->queueNow(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::SWEEP_JOB,
            [],
            null,
        );
        $queued = $this->em->getRepository(ScheduledJob::class)->find($queueId);
        self::assertInstanceOf(ScheduledJob::class, $queued);
        self::assertNull($queued->getRecordRef(), 'queueNow has no record.');

        $action = $this->emailActionWithReminder();
        $this->save('rem');
        $jobs = $this->em->getRepository(ScheduledJob::class)->findBy(['action' => $action->getId()]);
        self::assertGreaterThanOrEqual(2, count($jobs), 'Parent email and at least one reminder.');

        $reminders = array_values(array_filter(
            $jobs,
            static fn (ScheduledJob $job): bool => $job->getReminderMetadata() !== null,
        ));
        self::assertNotSame([], $reminders, 'A reminder job was planned.');
        foreach ($reminders as $reminder) {
            self::assertNull($reminder->getRecordRef(), 'A reminder leaves record_ref null.');
        }
    }

    /**
     * An email due later (so it stays queued and keeps its resolved config for the edit check).
     */
    private function emailAction(string $subject, string $body): Action
    {
        return $this->actions->createAction(
            $this->actions->createDataTable(self::TABLE),
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            [ActionConfig::BLOCKS => [[ActionConfig::JOBS => [$this->emailJob($subject, $body, 1)]]]],
            'qa_c26_email_action',
        );
    }

    private function emailActionWithReminder(): Action
    {
        $job = $this->emailJob('Parent {{recipient.email}}', 'Body', 2);
        $job[ActionConfig::REMINDERS] = [[
            ActionConfig::SCHEDULE_TIME => [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD,
                ActionConfig::SEND_AFTER => 1,
                ActionConfig::SEND_AFTER_TYPE => LookupService::TIME_PERIOD_DAYS,
            ],
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::RECIPIENT => '{{recipient.email}}',
                ActionConfig::SUBJECT => 'Reminder',
                ActionConfig::BODY => 'Remind',
                ActionConfig::FROM_EMAIL => 'qa-noreply@selfhelp.test',
                ActionConfig::FROM_NAME => 'QA',
            ],
        ]];

        return $this->actions->createAction(
            $this->actions->createDataTable(self::TABLE),
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            [ActionConfig::BLOCKS => [[ActionConfig::JOBS => [$job]]]],
            'qa_c26_email_reminder',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emailJob(string $subject, string $body, int $daysLater): array
    {
        return [
            ActionConfig::JOB_NAME => 'qa_c26_email',
            ActionConfig::JOB_TYPE => '',
            ActionConfig::SCHEDULE_TIME => [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD,
                ActionConfig::SEND_AFTER => $daysLater,
                ActionConfig::SEND_AFTER_TYPE => LookupService::TIME_PERIOD_DAYS,
            ],
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::RECIPIENT => '{{recipient.email}}',
                ActionConfig::SUBJECT => $subject,
                ActionConfig::BODY => $body,
                ActionConfig::FROM_EMAIL => 'qa-noreply@selfhelp.test',
                ActionConfig::FROM_NAME => 'QA',
            ],
        ];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function emailTexts(ScheduledJob $job): array
    {
        $config = $job->getConfig() ?? [];
        $email = $config['email'] ?? null;
        self::assertIsArray($email);
        $subject = $email['subject'] ?? null;
        $body = $email['body'] ?? null;
        self::assertIsString($subject);
        self::assertIsString($body);

        return ['subject' => $subject, 'body' => $body];
    }

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

    private function onlyJob(Action $action): ScheduledJob
    {
        $jobs = $this->em->getRepository(ScheduledJob::class)->findBy(['action' => $action->getId()]);
        self::assertCount(1, $jobs);

        return $jobs[0];
    }
}
