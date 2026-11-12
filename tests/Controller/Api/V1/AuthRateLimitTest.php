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
 * 15 failures / 15 min, 3 reset mails / hour per account, then 429), and the
 * same three per visitor address over all accounts (100 failures / 15 min,
 * 20 reset requests / hour). The limiter state lives in the cache pool and
 * escapes the DAMA rollback, so every key used here is reset in setUp and
 * tearDown.
 *
 * The address counters only count a public address the backend really sees.
 * The test client calls from 127.0.0.1, which is infrastructure and never
 * counted; the address tests call "from" a public documentation address
 * instead (a direct call, so the result does not depend on TRUSTED_PROXIES).
 * Which address counts in which proxy setup is covered by
 * {@see \App\Tests\Unit\Service\Auth\AuthThrottleServiceTest}.
 */
#[TestGroup('security')]
final class AuthRateLimitTest extends QaWebTestCase
{
    private const LOGIN_LIMIT = 15;
    private const FORGOT_LIMIT = 3;
    private const UNKNOWN_EMAIL = 'qa.ratelimit.unknown@selfhelp.test';
    private const VISITOR = '198.51.100.23';
    private const OTHER_VISITOR = '198.51.100.24';
    private const CLIENT_DEFAULT_ADDRESS = '127.0.0.1';

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

    // -- per visitor address ---------------------------------------------------

    public function testFailedLoginsAreCountedPerVisitorAddressOverAllAccounts(): void
    {
        foreach (['one', 'two', 'three'] as $name) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => 'qa.ratelimit.addr.' . $name . '@selfhelp.test',
                'password' => 'qa-wrong-password',
            ], null, $this->from(self::VISITOR));
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }

        $key = 'login-ip:' . self::VISITOR;
        self::assertSame($this->limitOf('limiter.auth_login_ip', $key) - 3, $this->remaining('limiter.auth_login_ip', $key));
    }

    public function testLoginIsBlockedWith429WhenTheVisitorAddressWindowIsFull(): void
    {
        $this->fillWindow('limiter.auth_login_ip', 'login-ip:' . self::VISITOR);

        // Even the correct password is refused, and nothing is issued.
        $blocked = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ], null, $this->from(self::VISITOR));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertArrayNotHasKey('access_token', $this->asArray($blocked['data'] ?? []));
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertGreaterThan(0, $this->coerceInt($this->asArray($blocked['data'] ?? [])['retry_after'] ?? 0));

        // The account itself is not blocked: another address gets in.
        $other = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ], null, $this->from(self::OTHER_VISITOR));
        self::assertArrayHasKey('access_token', $this->assertEnvelopeSuccess($other));
    }

    public function testASuccessfulLoginClearsTheAccountCounterButNotTheAddressCounter(): void
    {
        $addressKey = 'login-ip:' . self::VISITOR;
        $accountKey = 'login:' . QaBaselineFixture::QA_GUEST_EMAIL;

        for ($i = 0; $i < 2; $i++) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => QaBaselineFixture::QA_GUEST_EMAIL,
                'password' => 'qa-wrong-password',
            ], null, $this->from(self::VISITOR));
        }
        self::assertSame($this->limitOf('limiter.auth_login', $accountKey) - 2, $this->remaining('limiter.auth_login', $accountKey));

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_GUEST_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ], null, $this->from(self::VISITOR)));

        self::assertSame($this->limitOf('limiter.auth_login', $accountKey), $this->remaining('limiter.auth_login', $accountKey));
        self::assertSame(
            $this->limitOf('limiter.auth_login_ip', $addressKey) - 2,
            $this->remaining('limiter.auth_login_ip', $addressKey),
            'A valid account must not wipe the address counter'
        );
    }

    public function testAForwardedForHeaderFromAnUntrustedCallerCannotMoveTheCount(): void
    {
        $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => self::UNKNOWN_EMAIL,
            'password' => 'qa-wrong-password',
        ], null, $this->from(self::VISITOR) + ['HTTP_X_FORWARDED_FOR' => self::OTHER_VISITOR]);

        $visitorKey = 'login-ip:' . self::VISITOR;
        $otherKey = 'login-ip:' . self::OTHER_VISITOR;
        self::assertSame($this->limitOf('limiter.auth_login_ip', $visitorKey) - 1, $this->remaining('limiter.auth_login_ip', $visitorKey));
        self::assertSame($this->limitOf('limiter.auth_login_ip', $otherKey), $this->remaining('limiter.auth_login_ip', $otherKey));
    }

    public function testACallFromInfrastructureIsNotCountedPerAddressSoItCannotLockEveryoneOut(): void
    {
        foreach (['one', 'two', 'three'] as $name) {
            $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
                'email' => 'qa.ratelimit.addr.' . $name . '@selfhelp.test',
                'password' => 'qa-wrong-password',
            ]);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }

        $key = 'login-ip:' . self::CLIENT_DEFAULT_ADDRESS;
        self::assertSame($this->limitOf('limiter.auth_login_ip', $key), $this->remaining('limiter.auth_login_ip', $key));
    }

    public function testTwoFactorFailuresAreCountedPerVisitorAddressAndBlockedWhenItIsFull(): void
    {
        $userId = $this->guestId();
        $addressKey = '2fa-ip:' . self::VISITOR;

        $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
            'id_users' => $userId,
            'code' => '000000',
        ], null, $this->from(self::VISITOR));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame($this->limitOf('limiter.auth_two_factor_ip', $addressKey) - 1, $this->remaining('limiter.auth_two_factor_ip', $addressKey));
        self::assertSame(
            $this->limitOf('limiter.auth_two_factor', '2fa:' . $userId) - 1,
            $this->remaining('limiter.auth_two_factor', '2fa:' . $userId)
        );

        $this->fillWindow('limiter.auth_two_factor_ip', $addressKey);
        $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
            'id_users' => $userId,
            'code' => '000000',
        ], null, $this->from(self::VISITOR));
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testForgotPasswordIsLimitedPerVisitorAddressOverAllEmails(): void
    {
        $key = 'forgot-ip:' . self::VISITOR;

        foreach (['one', 'two'] as $name) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
                'email' => 'qa.ratelimit.recovery.' . $name . '@selfhelp.test',
            ], null, $this->from(self::VISITOR)));
        }
        self::assertSame($this->limitOf('limiter.auth_forgot_password_ip', $key) - 2, $this->remaining('limiter.auth_forgot_password_ip', $key));

        $this->fillWindow('limiter.auth_forgot_password_ip', $key);
        $blocked = $this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
            'email' => 'qa.ratelimit.recovery.three@selfhelp.test',
        ], null, $this->from(self::VISITOR));
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $this->coerceInt($this->asArray($blocked['data'] ?? [])['retry_after'] ?? 0));
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));

        // Another address still gets the generic answer.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/auth/forgot-password', [
            'email' => 'qa.ratelimit.recovery.three@selfhelp.test',
        ], null, $this->from(self::OTHER_VISITOR)));
    }

    public function testTheAddressLimitsDefaultToTheDocumentedValues(): void
    {
        $container = self::getContainer();

        self::assertSame(100, $container->getParameter('auth_ip_max_attempts_default'));
        self::assertSame('15minutes', $container->getParameter('auth_ip_window_default'));
        self::assertSame(20, $container->getParameter('auth_recovery_ip_max_requests_default'));
        self::assertSame('1hour', $container->getParameter('auth_recovery_ip_window_default'));
    }

    /**
     * The call "comes from" a public address the backend sees directly.
     *
     * @return array<string, string>
     */
    private function from(string $address): array
    {
        return ['REMOTE_ADDR' => $address];
    }

    private function limiterFactory(string $service): RateLimiterFactoryInterface
    {
        $factory = self::getContainer()->get($service);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    private function limitOf(string $service, string $key): int
    {
        return $this->limiterFactory($service)->create($key)->consume(0)->getLimit();
    }

    private function remaining(string $service, string $key): int
    {
        return $this->limiterFactory($service)->create($key)->consume(0)->getRemainingTokens();
    }

    /** Uses up what is left of the window of one counter, as if the limit had been reached. */
    private function fillWindow(string $service, string $key): void
    {
        $limiter = $this->limiterFactory($service)->create($key);
        $limiter->consume($limiter->consume(0)->getRemainingTokens());
        self::assertSame(0, $this->remaining($service, $key), 'The window must be full');
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
        $addresses = [self::VISITOR, self::OTHER_VISITOR, self::CLIENT_DEFAULT_ADDRESS];
        $addrEmails = array_map(
            static fn(string $name): string => 'qa.ratelimit.addr.' . $name . '@selfhelp.test',
            ['one', 'two', 'three']
        );
        $recoveryEmails = array_map(
            static fn(string $name): string => 'qa.ratelimit.recovery.' . $name . '@selfhelp.test',
            ['one', 'two', 'three']
        );
        $keys = [
            'limiter.auth_login' => [
                'login:' . QaBaselineFixture::QA_GUEST_EMAIL,
                'login:' . self::UNKNOWN_EMAIL,
                ...array_map(static fn(string $email): string => 'login:' . $email, $addrEmails),
            ],
            'limiter.auth_login_ip' => array_map(static fn(string $address): string => 'login-ip:' . $address, $addresses),
            'limiter.auth_two_factor' => ['2fa:' . $this->guestId()],
            'limiter.auth_two_factor_ip' => array_map(static fn(string $address): string => '2fa-ip:' . $address, $addresses),
            'limiter.auth_forgot_password' => [
                'forgot:' . QaBaselineFixture::QA_GUEST_EMAIL,
                'forgot:' . self::UNKNOWN_EMAIL,
                ...array_map(static fn(string $email): string => 'forgot:' . $email, $recoveryEmails),
            ],
            'limiter.auth_forgot_password_ip' => array_map(static fn(string $address): string => 'forgot-ip:' . $address, $addresses),
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
