<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Auth\UserValidationService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Service-level coverage for {@see UserValidationService::validateToken()},
 * focused on the side effects the controller test does not assert:
 *   - the validated account is unblocked and its token consumed (DB side effect),
 *   - a welcome-email ScheduledJob is created (scheduled, never sent — the test
 *     mailer is the null/recording transport, so there is no real outbound),
 *   - the negative branches return the documented error result (no exception).
 *
 * The token is written transiently onto qa.guest (rolled back by DAMA).
 */
#[Group('security')]
final class UserValidationServiceTest extends QaKernelTestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789';

    private UserValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(UserValidationService::class);
    }

    public function testValidateTokenUnblocksUserClearsTokenAndSchedulesWelcomeEmail(): void
    {
        $userId = $this->seedTokenOnGuest();

        $result = $this->service->validateToken($userId, self::TOKEN);

        self::assertTrue($result['success'], 'A correct token must validate the account.');
        self::assertSame($userId, $result['user_id']);
        $welcomeJobId = $this->coerceInt($result['welcome_job_id'] ?? 0);
        self::assertGreaterThan(0, $welcomeJobId, 'Validation must schedule a welcome email job.');

        // The scheduled welcome email is persisted (scheduled, not sent).
        self::assertInstanceOf(
            ScheduledJob::class,
            $this->em->getRepository(ScheduledJob::class)->find($welcomeJobId),
            'The welcome-email ScheduledJob must exist.'
        );

        // Public account state after validation.
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getToken(), 'Validation must consume the token.');
        self::assertFalse($user->isBlocked(), 'Validated account must be unblocked.');
    }

    public function testValidateTokenRejectsWrongTokenWithoutMutating(): void
    {
        $userId = $this->seedTokenOnGuest();

        $result = $this->service->validateToken($userId, '0123456789abcdef0123456789abcdef');

        self::assertFalse($result['success']);
        self::assertSame('Invalid validation token', $result['error']);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(self::TOKEN, $user->getToken(), 'A wrong token must not consume the stored token.');
        self::assertTrue($user->isBlocked(), 'A wrong token must leave the account blocked.');
    }

    public function testValidateTokenReturnsErrorForUnknownUser(): void
    {
        $result = $this->service->validateToken(2147483646, self::TOKEN);

        self::assertFalse($result['success']);
        self::assertSame('User not found', $result['error']);
    }

    private function seedTokenOnGuest(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $user->setToken(self::TOKEN);
        $user->setBlocked(true);
        // The seeded personas are ACTIVE; validateToken() (correctly) refuses to
        // re-validate an active account, so seed the realistic pre-validation
        // state (invited) the activation flow actually operates on.
        $user->setStatus($this->invitedStatus());
        $this->em->flush();

        return (int) $user->getId();
    }

    private function invitedStatus(): Lookup
    {
        $status = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => LookupService::USER_STATUS_INVITED,
        ]);
        self::assertInstanceOf(Lookup::class, $status, 'The invited user-status lookup must be seeded.');

        return $status;
    }
}
