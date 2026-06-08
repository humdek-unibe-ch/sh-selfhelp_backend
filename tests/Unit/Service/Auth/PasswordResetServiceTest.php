<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\Language;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\Auth\MailTemplateService;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\UserValidationService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetServiceTest extends TestCase
{
    public function testResetPasswordSendsPasswordChangedEmail(): void
    {
        $user = new User();
        $user->setEmail('qa.user@selfhelp.test');
        $user->setName('QA User');
        // Reset uses the dedicated recovery fields, not the validation token.
        $user->setPasswordResetToken('token-123');
        $user->setPasswordResetExpiresAt(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 minutes')
        );
        $user->setLanguage((new Language())->setLocale('de-CH'));
        $this->setEntityId($user, 42);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('beginTransaction');
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->once())->method('commit');
        $entityManager->expects($this->never())->method('rollback');
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects($this->once())
            ->method('hashPassword')
            ->with($user, 'NewSecret123')
            ->willReturn('hashed-password');

        $mailTemplateService = $this->createMock(MailTemplateService::class);
        $mailTemplateService->expects($this->once())
            ->method('buildEmailConfig')
            ->with(
                MailTemplateDefaults::TYPE_PASSWORD_CHANGED,
                [
                    'user_name' => 'QA User',
                    'platform_url' => 'http://localhost:3000/',
                ],
                ['recipient_emails' => 'qa.user@selfhelp.test'],
                'de-CH'
            )
            ->willReturn([
                'subject' => 'Password changed',
                'body' => 'Body',
                'recipient_emails' => 'qa.user@selfhelp.test',
            ]);

        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects($this->once())
            ->method('scheduleDirectEmailJob')
            ->with(
                [
                    'subject' => 'Password changed',
                    'body' => 'Body',
                    'recipient_emails' => 'qa.user@selfhelp.test',
                ],
                $this->isInstanceOf(\DateTime::class),
                42
            )
            ->willReturn(77);
        $jobScheduler->expects($this->once())
            ->method('executeJob')
            ->with(77, LookupService::TRANSACTION_BY_BY_SYSTEM)
            ->willReturn($this->createStub(ScheduledJob::class));

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->expects($this->once())
            ->method('logTransaction')
            ->with(
                'update',
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                42,
                false,
                $this->isString()
            );

        $userValidationService = $this->createMock(UserValidationService::class);
        $userValidationService->expects($this->never())->method('resendValidationEmail');

        $service = new PasswordResetService(
            $entityManager,
            $jobScheduler,
            $mailTemplateService,
            $transactionService,
            $passwordHasher,
            $userValidationService,
            $this->createStub(LoggerInterface::class),
            'http://localhost:3000'
        );

        self::assertTrue($service->resetPassword(42, 'token-123', 'NewSecret123'));
        self::assertSame('hashed-password', $user->getPassword());
        self::assertNull($user->getPasswordResetToken(), 'A successful reset must consume the recovery token.');
        self::assertNull($user->getPasswordResetExpiresAt(), 'A successful reset must clear the token expiry.');
    }

    private function setEntityId(User $user, int $id): void
    {
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);
    }
}
