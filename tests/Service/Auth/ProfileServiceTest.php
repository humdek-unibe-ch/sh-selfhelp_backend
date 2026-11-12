<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ApiRequestLog;
use App\Entity\DataRow;
use App\Entity\Transaction;
use App\Entity\User;
use App\Plugin\Event\UserDataCleanupEvent;
use App\Service\Auth\ProfileService;
use App\Service\Auth\UserContextService;
use App\Service\CMS\DataService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Focused service-level coverage for {@see ProfileService} paths that the
 * controller test does not exercise — specifically the system-account deletion
 * guard. Name/timezone/password/normal-delete flows are already covered through
 * {@see \App\Tests\Controller\Api\V1\Auth\ProfileControllerTest}; this test only
 * adds the security guard and the deletion DB side effect.
 *
 * The system-account check keys off the user's name ('admin'/'tpf'); the test
 * transiently renames a QA persona (rolled back by DAMA) rather than touching a
 * real system account.
 */
final class ProfileServiceTest extends QaKernelTestCase
{
    private ProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(ProfileService::class);
    }

    public function testSystemAccountCannotBeDeleted(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);

        // Make the managed entity look like a system account ('tpf'); DAMA rolls
        // this back. deleteAccount() re-reads the managed user and must refuse.
        $user->setName('tpf');
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete system accounts');
        $this->service->deleteAccount($user, (string) $user->getEmail());
    }

    public function testNonSystemAccountWithMatchingEmailIsDeleted(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $userId = (int) $user->getId();
        $email = (string) $user->getEmail();

        $this->service->deleteAccount($user, $email);

        self::assertNull(
            $this->em->getRepository(User::class)->find($userId),
            'A non-system account with matching email confirmation must be removed.'
        );
    }

    public function testDeleteAccountRejectsEmailMismatch(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteAccount($user, 'qa-not-my-email@selfhelp.test');
    }

    /**
     * Self-service deletion goes through the same cleaner as the admin delete
     * (F24): the user's data goes, the audit rows stay without the person, and
     * no row is left pointing at the deleted user.
     */
    public function testDeleteAccountRemovesTheUsersDataAndKeepsTheAuditTrail(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $userId = (int) $user->getId();
        $email = (string) $user->getEmail();

        $tables = new DataTableFactory($this->em, $this->service(DataService::class));
        $recordId = $tables->addRow('qa_profile_delete_table', ['qa_field' => 'qa-profile-delete'], $userId);
        (new ScheduledJobFactory($this->em))->createDueQueuedEmailJob($user, 'qa_profile_delete_job');

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setTransactionLog('qa_profile_delete_log');
        $this->em->persist($transaction);
        $this->em->flush();
        $transactionId = (int) $transaction->getId();

        $this->service->deleteAccount($user, $email);
        $this->em->clear();

        self::assertNull($this->em->getRepository(User::class)->find($userId), 'The account is gone');
        self::assertNull($this->em->getRepository(DataRow::class)->find($recordId), 'The data rows the user owned are gone');
        self::assertSame(
            0,
            (int) $this->em->createQuery('SELECT COUNT(j) FROM App\Entity\ScheduledJob j WHERE j.description = :d')
                ->setParameter('d', 'qa_profile_delete_job')
                ->getSingleScalarResult(),
            'The scheduled jobs the user owned are gone'
        );

        $kept = $this->em->getRepository(Transaction::class)->find($transactionId);
        self::assertInstanceOf(Transaction::class, $kept, 'The audit row is kept');
        self::assertNull($kept->getUser(), 'The audit row no longer points at the deleted user');
    }

    /**
     * A plugin that owns a table with user data is told when the user deletes
     * their own account (F83), like on every other way of removing a user: once
     * inside the transaction, and its after-commit work runs once it committed.
     */
    public function testDeleteAccountTellsPluginsToRemoveTheirRows(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $userId = (int) $user->getId();
        $email = (string) $user->getEmail();

        $heard = [];
        $afterCommit = 0;
        $this->service(EventDispatcherInterface::class)->addListener(
            UserDataCleanupEvent::class,
            static function (UserDataCleanupEvent $event) use (&$heard, &$afterCommit): void {
                $heard[] = $event->getUserId();
                $event->afterCommit(static function () use (&$afterCommit): void {
                    ++$afterCommit;
                });
            },
        );

        $this->service->deleteAccount($user, $email);

        self::assertSame([$userId], $heard);
        self::assertSame(1, $afterCommit);
    }

    /**
     * The real request: the signed-in user deletes their own account, so the
     * deletion log row is attributed to the user who is being deleted. The row
     * is the audit trail of the deletion and must survive it, without a user.
     */
    public function testDeleteAccountWhileSignedInKeepsTheDeletionLogRowWithoutTheUser(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $userId = (int) $user->getId();
        $email = (string) $user->getEmail();

        $tokenStorage = $this->service(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::assertSame(
            $userId,
            $this->service(UserContextService::class)->getCurrentUser()?->getId(),
            'The audit log must see the signed-in user as the acting user'
        );

        $this->service->deleteAccount($user, $email);

        // The API request logger writes a row through the same EntityManager
        // after the response. That flush must not trip over the deletion log
        // row that was attributed to the user who is now gone.
        $requestLog = new ApiRequestLog();
        $requestLog->setPath('/cms-api/v1/auth/user/account')
            ->setMethod('DELETE')
            ->setStatusCode(200)
            ->setRequestTime(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setResponseTime(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDurationMs(1);
        $this->em->persist($requestLog);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->getRepository(User::class)->find($userId), 'The account is gone');
        $logRows = array_values(array_filter(
            $this->em->getRepository(Transaction::class)->findBy(['tableName' => 'users', 'idTableName' => $userId]),
            static fn(Transaction $row): bool => str_contains((string) $row->getTransactionLog(), 'account_deleted')
        ));
        self::assertCount(1, $logRows, 'The deletion itself stays in the audit trail');
        self::assertNull($logRows[0]->getUser(), 'The audit row no longer points at the deleted user');
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
