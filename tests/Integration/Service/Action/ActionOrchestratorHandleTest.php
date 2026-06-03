<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Action;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Action\ActionContextBuilderService;
use App\Service\Action\ActionOrchestratorService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\Notifier\RecordingNotifier;
use App\Tests\Support\QaCleanupVerifier;
use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration coverage for {@see ActionOrchestratorService::handle()} using the
 * REAL action runtime (resolver, config runtime, scheduler, immediate executor,
 * cleanup) against the seeded QA baseline. No domain mocking (plan §1 wording).
 *
 * Trigger matrix:
 *  - started/updated/finished  -> an immediate email action job is scheduled and
 *    executed (status=done) for the resolved recipient.
 *  - deleted                   -> queued jobs for the deleted record are marked
 *    deleted by {@see \App\Service\Action\ActionCleanupService} (the cleanup
 *    service that cannot be unit-tested because LookupService is final).
 *
 * Assertions prefer public/domain effects (plan §5) and verify no real outbound
 * (plan §25): the email is captured by the null transport, no Mercure publish.
 */
final class ActionOrchestratorHandleTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ActionOrchestratorService $orchestrator;
    private MercureTestRecorder $mercure;
    private QaCleanupVerifier $cleanup;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $this->em->getConnection();

        $orchestrator = $container->get(ActionOrchestratorService::class);
        self::assertInstanceOf(ActionOrchestratorService::class, $orchestrator);
        $this->orchestrator = $orchestrator;

        $mercure = $container->get(MercureTestRecorder::class);
        self::assertInstanceOf(MercureTestRecorder::class, $mercure);
        $this->mercure = $mercure;

        $this->cleanup = new QaCleanupVerifier($this->connection);
        $this->cleanup->capture();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function schedulingTriggerProvider(): iterable
    {
        yield 'started' => [LookupService::ACTION_TRIGGER_TYPES_STARTED];
        yield 'updated' => [LookupService::ACTION_TRIGGER_TYPES_UPDATED];
        yield 'finished' => [LookupService::ACTION_TRIGGER_TYPES_FINISHED];
    }

    #[DataProvider('schedulingTriggerProvider')]
    public function testHandleSchedulesAndExecutesImmediateEmailActionForTrigger(string $triggerCode): void
    {
        $qaUserId = $this->qaUserId();

        $factory = new ActionFactory($this->em);
        $table = $factory->createDataTable('qa_orch_' . $triggerCode);
        $action = $factory->createAction(
            $table,
            $triggerCode,
            ActionFactory::immediateEmailConfig('QA ' . $triggerCode, 'QA body ' . $triggerCode),
            'qa_orch_action_' . $triggerCode,
        );
        $row = $this->createDataRow($table, $qaUserId);

        $context = (new ActionContextBuilderService())->build(
            $table,
            $row,
            ['id_users' => $qaUserId, 'qa_answer' => 'qa value'],
            $triggerCode,
            $qaUserId,
            LookupService::TRANSACTION_BY_BY_USER,
        );

        $this->orchestrator->handle($context);

        // Domain effect: exactly one scheduled job for the action, executed now.
        $jobs = $this->em->getRepository(ScheduledJob::class)->findBy(['action' => $action]);
        self::assertCount(1, $jobs, sprintf('Trigger "%s" must schedule exactly one job.', $triggerCode));
        $job = $jobs[0];
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            sprintf('Immediate "%s" action job must execute during handle (status=done).', $triggerCode)
        );
        self::assertSame($qaUserId, (int) $job->getUser()?->getId(), 'Recipient must be the triggering user.');

        // Audit + no real outbound.
        self::assertSame(1, $this->sendMailOkCount((int) $job->getId()), 'Email job must log send_mail_ok.');
        RecordingNotifier::fromMailerMessages(self::getMailerMessages())
            ->assertEmailSentTo(QaBaselineFixture::QA_USER_EMAIL, 'QA ' . $triggerCode);
        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);
        $this->cleanup->verifyNoNonQaLeaks();
    }

    public function testHandleDeletedTriggerMarksQueuedRecordJobsDeleted(): void
    {
        $qaUser = $this->qaUser();
        $factory = new ActionFactory($this->em);
        $table = $factory->createDataTable('qa_orch_deleted');
        $row = $this->createDataRow($table, (int) $qaUser->getId());

        // A queued job tied to the record (future-dated so it stays queued).
        $job = (new ScheduledJobFactory($this->em))->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $qaUser,
            new \DateTime('+10 days', new \DateTimeZone('UTC')),
            'qa_orch_deleted_queued_job',
            ['email' => ['recipient_emails' => (string) $qaUser->getEmail(), 'subject' => 'QA', 'body' => 'QA']],
        );
        $job->setDataRow($row);
        $job->setDataTable($table);
        $this->em->flush();

        $context = (new ActionContextBuilderService())->build(
            $table,
            $row,
            [],
            LookupService::ACTION_TRIGGER_TYPES_DELETED,
            (int) $qaUser->getId(),
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );

        $this->orchestrator->handle($context);

        // Domain effect: the queued job tied to the deleted record is now deleted.
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DELETED,
            $job->getStatus()->getLookupCode(),
            'Deleting a record must mark its queued jobs as deleted (action cleanup).'
        );

        // The cleanup must not have sent anything.
        RecordingNotifier::fromMailerMessages(self::getMailerMessages())->assertNoEmails();
        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);
        $this->cleanup->verifyNoNonQaLeaks();
    }

    private function createDataRow(DataTable $table, int $userId): DataRow
    {
        $row = new DataRow();
        $row->setDataTable($table);
        $row->setIdUsers($userId);
        $row->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }

    private function qaUserId(): int
    {
        return (int) $this->qaUser()->getId();
    }

    private function sendMailOkCount(int $jobId): int
    {
        return $this->coerceInt($this->connection->fetchOne(
            'SELECT COUNT(*) FROM transactions t '
            . 'JOIN lookups l ON l.id = t.id_transaction_types '
            . 'WHERE l.type_code = :type AND l.lookup_code = :code '
            . "AND t.table_name = 'scheduled_jobs' AND t.id_table_name = :jobId",
            [
                'type' => LookupService::TRANSACTION_TYPES,
                'code' => LookupService::TRANSACTION_TYPES_SEND_MAIL_OK,
                'jobId' => $jobId,
            ],
        ));
    }
}
