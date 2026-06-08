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
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgot-password flow ({@see PasswordResetService}).
 *
 * Covers the security contract from issue #32:
 *   - recovery uses dedicated, short-lived token fields (NOT the shared
 *     account-validation `users.token`);
 *   - tokens expire after one hour;
 *   - a still-invited account is nudged back through the validation flow
 *     instead of receiving a reset link;
 * plus the issue #29 behaviour that the recovery mail is `required_system` and
 * therefore delivered even when the recipient disabled platform emails.
 */
#[Group('security')]
final class PasswordResetServiceTest extends QaKernelTestCase
{
    public function testRequestResetStoresDedicatedTokenWithExpiryAndDeliversRecoveryMailEvenWhenEmailsDisabled(): void
    {
        $service = $this->service(PasswordResetService::class);

        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $user->setToken('qa-validation-token-should-not-change');
        $user->setPasswordResetToken(null);
        $user->setPasswordResetExpiresAt(null);
        $this->em->flush();

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $service->requestReset(QaBaselineFixture::QA_USER_EMAIL);

        self::assertNotNull(
            $user->getPasswordResetToken(),
            'Requesting a reset must store a dedicated one-time recovery token.'
        );
        self::assertSame(
            'qa-validation-token-should-not-change',
            $user->getToken(),
            'A reset request must NOT touch the account-validation token.'
        );

        $expiresAt = $user->getPasswordResetExpiresAt();
        self::assertNotNull($expiresAt, 'The recovery token must carry an expiry.');
        self::assertGreaterThan($before, $expiresAt, 'The expiry must be in the future.');
        self::assertLessThanOrEqual(
            $before->modify('+' . (PasswordResetService::RESET_TOKEN_TTL_SECONDS + 5) . ' seconds'),
            $expiresAt,
            'The expiry must be ~1 hour out, not open-ended.'
        );

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
        $user->setPasswordResetToken('valid-reset-token');
        $user->setPasswordResetExpiresAt(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 minutes')
        );
        $this->em->flush();

        $ok = $service->resetPassword((int) $user->getId(), 'valid-reset-token', 'NewQaSecret123');

        self::assertTrue($ok, 'A valid, non-expired token must reset the password.');
        $this->em->refresh($user);
        self::assertNull($user->getPasswordResetToken(), 'The recovery token must be consumed after a successful reset.');
        self::assertNull($user->getPasswordResetExpiresAt(), 'The expiry must be cleared after a successful reset.');
        self::assertTrue(
            $hasher->isPasswordValid($user, 'NewQaSecret123'),
            'The new password must be active after the reset.'
        );
    }

    public function testResetPasswordWithInvalidTokenIsRejected(): void
    {
        $service = $this->service(PasswordResetService::class);

        $user = $this->qaUser();
        $user->setPasswordResetToken('correct-token');
        $user->setPasswordResetExpiresAt(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 minutes')
        );
        $this->em->flush();

        $ok = $service->resetPassword((int) $user->getId(), 'wrong-token', 'WhateverPass123');

        self::assertFalse($ok, 'A wrong token must be rejected.');
        $this->em->refresh($user);
        self::assertSame('correct-token', $user->getPasswordResetToken(), 'A rejected reset must leave the token intact.');
    }

    public function testResetPasswordWithExpiredTokenIsRejected(): void
    {
        $service = $this->service(PasswordResetService::class);

        $user = $this->qaUser();
        $user->setPasswordResetToken('expired-token');
        $user->setPasswordResetExpiresAt(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 minute')
        );
        $this->em->flush();

        $ok = $service->resetPassword((int) $user->getId(), 'expired-token', 'WhateverPass123');

        self::assertFalse($ok, 'An expired token must be rejected even when the value matches.');
    }

    public function testRequestResetForInvitedUserResendsValidationInsteadOfIssuingResetLink(): void
    {
        $service = $this->service(PasswordResetService::class);
        $lookupService = $this->service(LookupService::class);

        $invitedStatus = $lookupService->findByTypeAndCode(
            LookupService::USER_STATUS,
            LookupService::USER_STATUS_INVITED
        );
        self::assertNotNull($invitedStatus, 'The invited user status lookup must exist.');

        // Flip the QA user into the invited state for this test only; the DAMA
        // transaction rolls the change back afterwards.
        $user = $this->qaUser();
        $user->setStatus($invitedStatus);
        $user->setToken('stale-validation-token');
        $user->setPasswordResetToken(null);
        $user->setPasswordResetExpiresAt(null);
        $this->em->flush();

        $service->requestReset(QaBaselineFixture::QA_USER_EMAIL);
        $this->em->refresh($user);

        self::assertNull(
            $user->getPasswordResetToken(),
            'An invited account must NOT receive a password-reset token.'
        );
        self::assertNotNull($user->getToken(), 'A fresh validation token must be issued.');
        self::assertNotSame(
            'stale-validation-token',
            $user->getToken(),
            'Requesting a reset for an invited account must resend (regenerate) the validation token.'
        );
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
