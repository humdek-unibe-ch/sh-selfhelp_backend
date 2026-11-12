<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\Entity\ApiRequestLog;
use App\Entity\DataAccessAudit;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\RefreshToken;
use App\Entity\ScheduledJob;
use App\Entity\ScheduledJobRecipient;
use App\Entity\Transaction;
use App\Entity\User;
use App\Plugin\Event\UserDataCleanupEvent;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Service\Core\UserDataCleanerService;
use App\Service\Core\UserDataRemoval;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Schema\Index;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one function that removes what a user produced (F24): what goes, what is
 * kept with the person cut loose, and that other users are never touched.
 *
 * A control user owns the same kinds of rows as the target; every assertion on
 * the target is paired with "the control user still has theirs".
 *
 * The plugin extension point (F83) is covered here too: a plugin that owns a
 * table listens to {@see UserDataCleanupEvent}. The tests use the target's
 * refresh tokens as the stand-in for such a table: the cleaner itself never
 * touches them (session state, see the service docblock).
 */
#[Group('security')]
final class UserDataCleanerServiceTest extends QaKernelTestCase
{
    private const TABLE = 'qa_cleaner_table';

    private UserDataCleanerService $cleaner;
    private UserFactory $users;
    private DataTableFactory $tables;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleaner = $this->service(UserDataCleanerService::class);
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->tables = new DataTableFactory($this->em, $this->service(DataService::class));
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testRemoveDataErasesWhatTheUserProducedAndLeavesOtherUsersAlone(): void
    {
        $target = $this->users->createUser('qa.cleaner.target@selfhelp.test', 'qa_cleaner_target');
        $control = $this->users->createUser('qa.cleaner.control@selfhelp.test', 'qa_cleaner_control');
        $targetId = (int) $target->getId();
        $controlId = (int) $control->getId();

        $this->seedFor($target);
        $this->seedFor($control);
        // The target was also sent something on a job owned by the control user.
        $controlJob = $this->em->getRepository(ScheduledJob::class)->findOneBy(['user' => $control]);
        self::assertInstanceOf(ScheduledJob::class, $controlJob);
        $recipient = new ScheduledJobRecipient();
        $recipient->setScheduledJob($controlJob);
        $recipient->setUser($target);
        $recipient->setRecipientEmail((string) $target->getEmail());
        $this->em->persist($recipient);
        $this->em->flush();

        $result = $this->cleaner->removeData($targetId);
        $this->em->clear();

        $removed = $result->removed;
        self::assertSame(1, $removed['scheduled_job_recipients'], 'The recipient row on someone else\'s job goes');
        self::assertSame(1, $removed['scheduled_jobs']);
        self::assertSame(1, $removed['data_rows']);
        self::assertSame(1, $removed['data_cells']);
        self::assertSame(1, $removed['api_request_logs']);
        self::assertSame(1, $removed['transactions'], 'Audit rows are detached, and counted as such');
        self::assertSame(1, $removed['data_access_audits']);
        self::assertCount(1, $result->dataTableIds, 'The caller learns which data table changed');

        // What the target produced is gone ...
        self::assertSame(0, $this->countOwned(ScheduledJob::class, 'user', $targetId));
        self::assertSame(0, $this->countOwned(ScheduledJobRecipient::class, 'user', $targetId));
        self::assertSame(0, $this->countOwned(DataRow::class, 'idUsers', $targetId));
        self::assertSame(0, $this->countOwned(ApiRequestLog::class, 'userId', $targetId));
        // ... the audit rows stay but no longer point at the person ...
        self::assertSame(0, $this->countOwned(Transaction::class, 'idUsers', $targetId));
        self::assertSame(0, $this->countOwned(DataAccessAudit::class, 'idUsers', $targetId));
        self::assertSame(1, $this->countByLog('qa_cleaner_log_' . $targetId), 'The audit row itself is kept');
        self::assertSame(1, $this->countAuditByNote('qa_cleaner_note_' . $targetId), 'The data-access audit row itself is kept');
        self::assertInstanceOf(User::class, $this->em->getRepository(User::class)->find($targetId), 'The account itself stays');

        // ... and the control user keeps everything.
        self::assertSame(1, $this->countOwned(ScheduledJob::class, 'user', $controlId));
        self::assertSame(1, $this->countOwned(DataRow::class, 'idUsers', $controlId));
        self::assertSame(1, $this->countOwned(ApiRequestLog::class, 'userId', $controlId));
        self::assertSame(1, $this->countOwned(Transaction::class, 'idUsers', $controlId));
        self::assertSame(1, $this->countOwned(DataAccessAudit::class, 'idUsers', $controlId));
    }

    public function testRemoveDataOnAUserWithNothingToRemoveReturnsZeroCounts(): void
    {
        $user = $this->users->createUser('qa.cleaner.empty@selfhelp.test', 'qa_cleaner_empty');

        $result = $this->cleaner->removeData((int) $user->getId());

        self::assertSame([], $result->dataTableIds);
        self::assertSame([], $result->afterCommit, 'No plugin listens, so nothing waits for the commit');
        foreach ($result->removed as $kind => $count) {
            self::assertSame(0, $count, $kind);
        }
    }

    public function testRequestLogRowsOfAUserAreFoundThroughAnIndex(): void
    {
        // The cleaner deletes request log rows by user. Without an index on
        // id_users that DELETE scans the whole log and, under REPEATABLE READ,
        // locks every row it passes until the transaction ends: every other
        // request then waits to write its own log row (and the test suite saw
        // InnoDB deadlocks from it). Guard the index, not a query plan: plans
        // on tiny test tables are not stable.
        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('api_request_logs');

        $leadingOnUser = array_filter(
            $indexes,
            static fn (Index $index): bool => $index->getColumns()[0] === 'id_users',
        );

        self::assertNotSame([], $leadingOnUser, 'api_request_logs needs an index that starts with id_users');
    }

    public function testFinishAfterCommitDeletesTheUsersFormFilesOnly(): void
    {
        $root = $this->service(FormFileUploadService::class)->storageRoot();
        $targetDir = $root . '/user_999900001';
        $otherDir = $root . '/user_999900002';
        mkdir($targetDir . '/section_1/field_qa', 0755, true);
        mkdir($otherDir . '/section_1/field_qa', 0755, true);
        file_put_contents($targetDir . '/section_1/field_qa/a.txt', 'qa');
        file_put_contents($targetDir . '/section_1/field_qa/b.txt', 'qa');
        file_put_contents($otherDir . '/section_1/field_qa/c.txt', 'qa');

        try {
            $files = $this->cleaner->finishAfterCommit(999900001, new UserDataRemoval([], [], []));

            self::assertSame(2, $files);
            self::assertDirectoryDoesNotExist($targetDir);
            self::assertFileExists($otherDir . '/section_1/field_qa/c.txt', 'Another user\'s files are never touched');
        } finally {
            foreach ([$targetDir, $otherDir] as $dir) {
                if (is_dir($dir)) {
                    @unlink($dir . '/section_1/field_qa/a.txt');
                    @unlink($dir . '/section_1/field_qa/b.txt');
                    @unlink($dir . '/section_1/field_qa/c.txt');
                    @rmdir($dir . '/section_1/field_qa');
                    @rmdir($dir . '/section_1');
                    @rmdir($dir);
                }
            }
        }
    }

    public function testDeletingAUserKeepsTheAuditRowsAndRemovesTheData(): void
    {
        $adminUserService = $this->service(AdminUserService::class);
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => \App\DataFixtures\Test\QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);

        $target = $this->users->createUser('qa.cleaner.delete@selfhelp.test', 'qa_cleaner_delete');
        $targetId = (int) $target->getId();
        $this->seedFor($target);

        $adminUserService->deleteUser((int) $admin->getId(), $targetId);
        $this->em->clear();

        self::assertNull($this->em->getRepository(User::class)->find($targetId), 'The user is gone');
        self::assertSame(0, $this->countOwned(ScheduledJob::class, 'user', $targetId));
        self::assertSame(0, $this->countOwned(DataRow::class, 'idUsers', $targetId));
        self::assertSame(0, $this->countOwned(ApiRequestLog::class, 'userId', $targetId));
        // The audit rows survive the delete without a user (rule: no row points at a missing user).
        self::assertSame(1, $this->countByLog('qa_cleaner_log_' . $targetId), 'The transaction row is kept');
        self::assertSame(1, $this->countAuditByNote('qa_cleaner_note_' . $targetId), 'The data-access audit row is kept');
        self::assertSame(0, $this->countOwned(Transaction::class, 'idUsers', $targetId));
        self::assertSame(0, $this->countOwned(DataAccessAudit::class, 'idUsers', $targetId));
    }

    public function testAPluginListenerHearsTheUserBeforeTheCoreRowsGo(): void
    {
        $target = $this->users->createUser('qa.cleaner.hears@selfhelp.test', 'qa_cleaner_hears');
        $targetId = (int) $target->getId();
        $this->seedFor($target);

        $heard = [];
        $this->listen(function (UserDataCleanupEvent $event) use (&$heard): void {
            $heard[] = [
                'userId' => $event->getUserId(),
                // Still there: a plugin can read core rows to find what it must remove.
                'dataRowsLeft' => $this->countOwned(DataRow::class, 'idUsers', $event->getUserId()),
            ];
        });

        $this->cleaner->removeData($targetId);

        self::assertSame([['userId' => $targetId, 'dataRowsLeft' => 1]], $heard, 'Told once, before the core rows go');
        self::assertSame(0, $this->countOwned(DataRow::class, 'idUsers', $targetId));
    }

    public function testAPluginRemovesItsRowsWithTheRestAndItsAfterCommitWorkWaitsForTheCommit(): void
    {
        $target = $this->users->createUser('qa.cleaner.owned@selfhelp.test', 'qa_cleaner_owned');
        $control = $this->users->createUser('qa.cleaner.owned.control@selfhelp.test', 'qa_cleaner_owned_control');
        $targetId = (int) $target->getId();
        $controlId = (int) $control->getId();
        $this->seedRefreshToken($target);
        $this->seedRefreshToken($control);

        $ran = [];
        $this->listen(function (UserDataCleanupEvent $event) use (&$ran): void {
            $this->em->createQuery('DELETE FROM App\Entity\RefreshToken t WHERE t.user = :userId')
                ->setParameter('userId', $event->getUserId())
                ->execute();
            $event->afterCommit(function () use (&$ran): void {
                $ran[] = 'first';
            });
            $event->afterCommit(static function (): void {
                throw new \RuntimeException('qa plugin failed');
            });
            $event->afterCommit(function () use (&$ran): void {
                $ran[] = 'third';
            });
        });
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'context' => $context];
            }
        };
        $cleaner = new UserDataCleanerService(
            $this->em,
            $this->service(FormFileUploadService::class),
            $this->service(CacheService::class),
            $this->service(EventDispatcherInterface::class),
            $logger,
        );

        $removal = $cleaner->removeData($targetId);

        self::assertSame(0, $this->countRefreshTokens($targetId), 'The plugin removed its rows inside the transaction');
        self::assertSame(1, $this->countRefreshTokens($controlId), 'Only the target\'s rows');
        self::assertSame([], $ran, 'Nothing registered for after the commit has run yet');
        self::assertCount(3, $removal->afterCommit);

        $cleaner->finishAfterCommit($targetId, $removal);

        self::assertSame(['first', 'third'], $ran, 'In the order registered; a failing one does not stop the next');
        self::assertCount(1, $logger->records, 'The failure is logged, not thrown');
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($targetId, $logger->records[0]['context']['userId'] ?? null);
    }

    public function testAPluginIsAlsoToldWhenOnlyTheDataIsCleaned(): void
    {
        $adminUserService = $this->service(AdminUserService::class);
        $target = $this->users->createUser('qa.cleaner.clean@selfhelp.test', 'qa_cleaner_clean');
        $targetId = (int) $target->getId();
        $this->seedFor($target);

        $heard = [];
        $afterCommit = 0;
        $this->listen(function (UserDataCleanupEvent $event) use (&$heard, &$afterCommit): void {
            $heard[] = $event->getUserId();
            $event->afterCommit(function () use (&$afterCommit): void {
                ++$afterCommit;
            });
        });

        $adminUserService->cleanUserData($this->adminId(), $targetId);
        $this->em->clear();

        self::assertSame([$targetId], $heard);
        self::assertSame(1, $afterCommit, 'Run once the transaction committed');
        self::assertInstanceOf(User::class, $this->em->getRepository(User::class)->find($targetId), 'The account stays when only the data is cleaned');
    }

    public function testAPluginIsToldWhenAUserIsDeletedAndAFailingOneRollsEverythingBack(): void
    {
        $adminUserService = $this->service(AdminUserService::class);
        $target = $this->users->createUser('qa.cleaner.rollback@selfhelp.test', 'qa_cleaner_rollback');
        $targetId = (int) $target->getId();
        $this->seedFor($target);
        $this->seedRefreshToken($target);

        $afterCommit = 0;
        $this->listen(function (UserDataCleanupEvent $event) use (&$afterCommit): void {
            // The first plugin removes its rows and wants to delete files later ...
            $this->em->createQuery('DELETE FROM App\Entity\RefreshToken t WHERE t.user = :userId')
                ->setParameter('userId', $event->getUserId())
                ->execute();
            $event->afterCommit(function () use (&$afterCommit): void {
                ++$afterCommit;
            });
        }, 10);
        // ... then a second one fails.
        $this->listen(static function (): void {
            throw new \RuntimeException('qa second plugin failed');
        });

        try {
            $adminUserService->deleteUser($this->adminId(), $targetId);
            self::fail('A failing plugin must stop the deletion');
        } catch (\RuntimeException $e) {
            self::assertSame('qa second plugin failed', $e->getMessage());
        }
        $this->em->clear();

        self::assertInstanceOf(User::class, $this->em->getRepository(User::class)->find($targetId), 'The user is still there');
        self::assertSame(1, $this->countOwned(DataRow::class, 'idUsers', $targetId), 'The core rows are untouched');
        self::assertSame(1, $this->countRefreshTokens($targetId), 'What the first plugin removed came back with the rollback');
        self::assertSame(0, $afterCommit, 'Nothing was committed, so nothing runs after the commit');
    }

    /**
     * @param callable(UserDataCleanupEvent): void $listener
     */
    private function listen(callable $listener, int $priority = 0): void
    {
        $this->service(EventDispatcherInterface::class)->addListener(UserDataCleanupEvent::class, $listener, $priority);
    }

    private function adminId(): int
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => \App\DataFixtures\Test\QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);

        return (int) $admin->getId();
    }

    private function seedRefreshToken(User $user): void
    {
        $token = new RefreshToken();
        $token->setUser($user);
        $token->setTokenHash(hash('sha256', 'qa-cleaner-token-' . $user->getId()));
        $token->setExpiresAt(new \DateTime('+1 day'));
        $this->em->persist($token);
        $this->em->flush();
    }

    private function countRefreshTokens(int $userId): int
    {
        return $this->countOwned(RefreshToken::class, 'user', $userId);
    }

    /**
     * One row of every kind the cleaner knows, owned by the user. The marker
     * text carries the user id so a kept audit row can be found after the link
     * to the user is cleared.
     */
    private function seedFor(User $user): void
    {
        $userId = (int) $user->getId();

        $this->tables->addRow(self::TABLE, ['qa_field' => 'qa_cleaner_value_' . $userId], $userId);
        $this->jobs->createDueQueuedEmailJob($user, 'qa_cleaner_job_' . $userId);

        $log = new ApiRequestLog();
        $log->setPath('/cms-api/v1/qa/cleaner')
            ->setMethod('GET')
            ->setStatusCode(200)
            ->setUserId($userId)
            ->setRequestTime(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setResponseTime(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDurationMs(1);
        $this->em->persist($log);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setTransactionLog('qa_cleaner_log_' . $userId);
        $this->em->persist($transaction);

        $lookups = $this->service(LookupService::class);
        $resourceType = $lookups->findByTypeAndCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_PAGES);
        $action = $lookups->findByTypeAndCode(LookupService::AUDIT_ACTIONS, LookupService::AUDIT_ACTIONS_READ);
        $result = $lookups->findByTypeAndCode(LookupService::PERMISSION_RESULTS, LookupService::PERMISSION_RESULTS_DENIED);
        self::assertNotNull($resourceType);
        self::assertNotNull($action);
        self::assertNotNull($result);
        $audit = new DataAccessAudit();
        $audit->setUser($user);
        $audit->setResourceType($resourceType);
        $audit->setResourceId(1);
        $audit->setAction($action);
        $audit->setPermissionResult($result);
        $audit->setNotes('qa_cleaner_note_' . $userId);
        $this->em->persist($audit);

        $this->em->flush();
    }

    /**
     * @param class-string $entityClass
     */
    private function countOwned(string $entityClass, string $field, int $userId): int
    {
        return (int) $this->em
            ->createQuery('SELECT COUNT(e) FROM ' . $entityClass . ' e WHERE e.' . $field . ' = :userId')
            ->setParameter('userId', $userId)
            ->getSingleScalarResult();
    }

    private function countByLog(string $marker): int
    {
        return (int) $this->em
            ->createQuery('SELECT COUNT(t) FROM App\Entity\Transaction t WHERE t.transactionLog = :marker')
            ->setParameter('marker', $marker)
            ->getSingleScalarResult();
    }

    private function countAuditByNote(string $marker): int
    {
        return (int) $this->em
            ->createQuery('SELECT COUNT(a) FROM App\Entity\DataAccessAudit a WHERE a.notes = :marker')
            ->setParameter('marker', $marker)
            ->getSingleScalarResult();
    }
}
