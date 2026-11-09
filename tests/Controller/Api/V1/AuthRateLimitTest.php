<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Brute-force limits on login, 2FA verify and forgot-password (defaults:
 * 15 failures / 15 min, 3 reset mails / hour, then 429). The limiter state lives in the
 * cache pool and escapes the DAMA rollback, so every key used here is reset in
 * setUp and tearDown.
 */
#[TestGroup('security')]
final class AuthRateLimitTest extends QaWebTestCase
{
    private const LOGIN_LIMIT = 15;
    private const FORGOT_LIMIT = 3;
    private const UNKNOWN_EMAIL = 'qa.ratelimit.unknown@selfhelp.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetLimiters();
    }

    protected function tearDown(): void
    {
        $this->resetLimiters();
        parent::tearDown();
    }

    public function testLoginIsBlockedWith429AfterTooManyFailedAttempts(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => QaBaselineFixture::QA_GUEST_EMAIL,
                'password' => 'qa-wrong-password',
            ]);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }

        // Even the correct password is refused while the window is full.
        $blocked = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $blocked['status'] ?? null);
        self::assertArrayNotHasKey('access_token', $this->asArray($blocked['data'] ?? []));
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertGreaterThan(0, $this->coerceInt($this->asArray($blocked['data'] ?? [])['retry_after'] ?? 0));
    }

    public function testSuccessfulLoginResetsTheFailedAttemptCounter(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT - 1; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => QaBaselineFixture::QA_GUEST_EMAIL,
                'password' => 'qa-wrong-password',
            ]);
        }
        $this->loginAs(QaBaselineFixture::QA_GUEST_EMAIL);

        // A full new window of failures is allowed again after the reset.
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => QaBaselineFixture::QA_GUEST_EMAIL,
                'password' => 'qa-wrong-password',
            ]);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testBlockingOneAccountDoesNotAffectOtherAccounts(): void
    {
        for ($i = 0; $i <= self::LOGIN_LIMIT; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => self::UNKNOWN_EMAIL,
                'password' => 'qa-wrong-password',
            ]);
        }
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());

        $other = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);
        self::assertArrayHasKey('access_token', $this->assertEnvelopeSuccess($other));
    }

    public function testTwoFactorVerifyIsBlockedWith429AfterTooManyWrongCodes(): void
    {
        $userId = $this->guestId();

        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
                'id_users' => $userId,
                'code' => '000000',
            ]);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }

        $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
            'id_users' => $userId,
            'code' => '000000',
        ]);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testForgotPasswordAnswers429WithTheWaitOnceTheAddressHitTheLimit(): void
    {
        for ($i = 0; $i < self::FORGOT_LIMIT; $i++) {
            $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
                'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            ]);
            $this->assertEnvelopeSuccess($envelope);
        }
        $tokenAtLimit = $this->guest()->getPasswordResetToken();
        self::assertNotNull($tokenAtLimit, 'Accepted requests must issue a reset token');

        $blocked = $this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertGreaterThan(0, $this->coerceInt($this->asArray($blocked['data'] ?? [])['retry_after'] ?? 0));
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        // No new reset was issued.
        self::assertSame($tokenAtLimit, $this->guest()->getPasswordResetToken());
    }

    public function testForgotPasswordLimitTreatsUnknownAddressesTheSameSoItRevealsNoAccounts(): void
    {
        for ($i = 0; $i < self::FORGOT_LIMIT; $i++) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
                'email' => self::UNKNOWN_EMAIL,
            ]));
        }

        $this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', ['email' => self::UNKNOWN_EMAIL]);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
    }

    private function guest(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function guestId(): int
    {
        return (int) $this->guest()->getId();
    }

    private function resetLimiters(): void
    {
        $container = self::getContainer();
        $keys = [
            'limiter.auth_login' => [
                'login:' . QaBaselineFixture::QA_GUEST_EMAIL,
                'login:' . self::UNKNOWN_EMAIL,
            ],
            'limiter.auth_two_factor' => ['2fa:' . $this->guestId()],
            'limiter.auth_forgot_password' => [
                'forgot:' . QaBaselineFixture::QA_GUEST_EMAIL,
                'forgot:' . self::UNKNOWN_EMAIL,
            ],
        ];
        foreach ($keys as $service => $ids) {
            $factory = $container->get($service);
            self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
            foreach ($ids as $id) {
                $factory->create($id)->reset();
            }
        }
    }
}
