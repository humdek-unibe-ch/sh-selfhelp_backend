<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Auth\PasswordResetService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgot-password flow ({@see PasswordResetService}).
 *
 * Covers the behaviour the user reported as broken: a reset request must send a
 * recovery email even when the recipient disabled platform emails (the mail is
 * `required_system`), and the emailed token must let the user set a new
 * password exactly once.
 */
final class PasswordResetServiceTest extends QaKernelTestCase
{
    public function testRequestResetStoresTokenAndDeliversRecoveryMailEvenWhenEmailsDisabled(): void
    {
        $service = $this->service(PasswordResetService::class);

        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $user->setToken(null);
        $this->em->flush();

        $service->requestReset(QaBaselineFixture::QA_USER_EMAIL);

        self::assertNotNull($user->getToken(), 'Requesting a reset must store a one-time recovery token.');

        $job = $this->em->getRepository(ScheduledJob::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
        self::assertInstanceOf(ScheduledJob::class, $job, 'A recovery email job must be created.');
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'Recovery mail is required_system, so it must be delivered even when the user disabled emails.'
        );
    }

    public function testResetPasswordWithValidTokenUpdatesPasswordAndClearsToken(): void
    {
        $service = $this->service(PasswordResetService::class);
        $hasher = $this->service(UserPasswordHasherInterface::class);

        $user = $this->qaUser();
        $user->setToken('valid-reset-token');
        $this->em->flush();

        $ok = $service->resetPassword((int) $user->getId(), 'valid-reset-token', 'NewQaSecret123');

        self::assertTrue($ok, 'A valid token must reset the password.');
        $this->em->refresh($user);
        self::assertNull($user->getToken(), 'The recovery token must be consumed after a successful reset.');
        self::assertTrue(
            $hasher->isPasswordValid($user, 'NewQaSecret123'),
            'The new password must be active after the reset.'
        );
    }

    public function testResetPasswordWithInvalidTokenIsRejected(): void
    {
        $service = $this->service(PasswordResetService::class);

        $user = $this->qaUser();
        $user->setToken('correct-token');
        $this->em->flush();

        $ok = $service->resetPassword((int) $user->getId(), 'wrong-token', 'WhateverPass123');

        self::assertFalse($ok, 'A wrong token must be rejected.');
        $this->em->refresh($user);
        self::assertSame('correct-token', $user->getToken(), 'A rejected reset must leave the token intact.');
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
