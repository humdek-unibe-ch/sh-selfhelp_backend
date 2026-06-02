<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\Users2faCode;
use App\Entity\UsersGroup;
use App\Service\Auth\JWTService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Auth endpoint behaviour exercised against the seeded QA personas — no
 * developer credentials (plan §22 DoD #7 / canonical Testing Rule 7). Covers
 * the login / 2FA / refresh / logout / set-language surface including the
 * security-regression negatives (wrong password, invalid 2FA code, invalid
 * refresh token, post-logout token invalidation — plan §29), so the class is
 * tagged `security` for the CI `--group=security` tier.
 *
 * 2FA is group-driven (`User::isTwoFactorRequired()` checks
 * `Group::isRequires2fa()`), so the 2FA tests opt a QA persona into a
 * `qa_`-prefixed 2FA group created in-test. DAMA rolls the write back, and
 * the null mailer (config/packages/test/mailer.yaml) keeps the 2FA email
 * in-process.
 */
#[TestGroup('security')]
final class AuthControllerTest extends QaWebTestCase
{
    /**
     * A token blacklisted by a logout test escapes the DAMA transaction (the
     * blacklist lives in the Redis-backed cache.app pool). Because two logins
     * for the same persona within the same second yield an IDENTICAL JWT,
     * leaving it blacklisted would poison any sibling test that reuses that
     * persona's token (order-dependence — plan §10). The logout test records
     * its token here and tearDown clears the blacklist entry (plan §8:
     * explicitly clean up effects that escape the transaction).
     */
    private ?string $tokenToUnblacklist = null;

    protected function tearDown(): void
    {
        if ($this->tokenToUnblacklist !== null) {
            $cache = self::getContainer()->get('cache.app');
            if ($cache instanceof CacheInterface) {
                $cache->delete(JWTService::BLACKLIST_PREFIX . md5($this->tokenToUnblacklist));
            }
            $this->tokenToUnblacklist = null;
        }

        parent::tearDown();
    }

    public function testLoginRouteIsRegistered(): void
    {
        $this->client->request('GET', '/cms-api/v1/auth/login');

        self::assertNotSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'The login route must be registered.'
        );
    }

    public function testLoginSucceedsForSeededQaAdmin(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_ADMIN_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertArrayHasKey('access_token', $data, 'Login must return data.access_token');
        self::assertNotSame('', $this->asString($data['access_token']), 'access_token must be non-empty');
        self::assertTrue($envelope['logged_in'] ?? false, 'logged_in must be true on success');
    }

    public function testLoginFailsForWrongPassword(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_ADMIN_EMAIL,
            'password' => 'definitely-not-the-qa-password',
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        $this->assertEnvelope401($envelope);
        self::assertNotSame('', $this->coerceString($envelope['message'] ?? ''), 'A failed login must carry a message');
    }

    public function testTwoFactorRequiredGroupTriggersChallengeAndVerifySucceeds(): void
    {
        $userId = $this->optPersonaIntoTwoFactorGroup(QaBaselineFixture::QA_USER_EMAIL);

        $login = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_USER_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $loginData = $this->assertEnvelopeSuccess($login);
        self::assertTrue($loginData['requires_2fa'] ?? false, '2FA-group member must be challenged for 2FA');
        self::assertArrayNotHasKey('access_token', $loginData, 'No access token must be issued before 2FA verify');
        self::assertSame($userId, $this->coerceInt($loginData['id_users'] ?? 0), 'Challenge must reference the same user');

        $code = $this->latestUnusedTwoFactorCode($userId);

        $verify = $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
            'id_users' => $userId,
            'code' => $code,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $verifyData = $this->assertEnvelopeSuccess($verify);
        self::assertArrayHasKey('access_token', $verifyData, 'A valid 2FA code must yield an access token');
        self::assertNotSame('', $this->asString($verifyData['access_token']));
        self::assertTrue($verify['logged_in'] ?? false, 'logged_in must be true after a valid 2FA verify');
    }

    public function testTwoFactorVerifyFailsForInvalidCode(): void
    {
        $userId = $this->optPersonaIntoTwoFactorGroup(QaBaselineFixture::QA_USER_EMAIL);

        $login = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_USER_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);
        $loginData = $this->assertEnvelopeSuccess($login);
        self::assertTrue($loginData['requires_2fa'] ?? false);

        $verify = $this->jsonRequest('POST', '/cms-api/v1/auth/two-factor-verify', [
            'id_users' => $userId,
            'code' => '000000',
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        $this->assertEnvelope401($verify);
        self::assertSame('Invalid or expired verification code', $this->coerceString($verify['error'] ?? ''));
    }

    #[TestGroup('refresh_token')]
    public function testRefreshTokenRotatesForSeededQaUser(): void
    {
        $login = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_USER_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);
        $loginData = $this->assertEnvelopeSuccess($login);
        self::assertArrayHasKey('refresh_token', $loginData, 'Login must return a refresh token');
        $oldRefresh = $this->asString($loginData['refresh_token']);

        $refresh = $this->jsonRequest('POST', '/cms-api/v1/auth/refresh-token', [
            'refresh_token' => $oldRefresh,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $refreshData = $this->assertEnvelopeSuccess($refresh);
        self::assertNotSame('', $this->coerceString($refreshData['access_token'] ?? ''), 'Refresh must mint a new access token');
        self::assertNotSame(
            $oldRefresh,
            $this->coerceString($refreshData['refresh_token'] ?? ''),
            'Refresh must rotate the refresh token'
        );
    }

    #[TestGroup('refresh_token')]
    public function testRefreshTokenFailsForInvalidToken(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/refresh-token', [
            'refresh_token' => 'invalid.refresh.token.string',
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        $this->assertEnvelope401($envelope);
    }

    #[TestGroup('logout')]
    public function testLogoutInvalidatesAccessToken(): void
    {
        $token = $this->loginAsQaAdmin();
        // Record so tearDown can clear the Redis blacklist entry this test creates.
        $this->tokenToUnblacklist = $token;

        $logout = $this->jsonRequest('POST', '/cms-api/v1/auth/logout', null, $token);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(Response::HTTP_OK, $logout['status'] ?? null, 'Logout envelope status must be 200');

        // Public effect: the blacklisted token can no longer reach a protected route.
        $afterLogout = $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $token);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            'A blacklisted access token must be rejected after logout.'
        );
        $this->assertEnvelope401($afterLogout);
    }

    public function testLoginIncludesLanguageInfoForSeededQaAdmin(): void
    {
        $login = $this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_ADMIN_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);

        $data = $this->assertEnvelopeSuccess($login);
        self::assertArrayHasKey('user', $data, 'Login data must contain the user object');
        self::assertIsArray($data['user']);
        self::assertIsInt($data['user']['language_id'] ?? null, 'user.language_id must be an int');
        self::assertIsString($data['user']['language_locale'] ?? null, 'user.language_locale must be a string');
    }

    public function testSetUserLanguageUpdatesThePreference(): void
    {
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/set-language', ['language_id' => 2], $token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame(2, $data['language_id'] ?? null, 'The updated language_id must be echoed back');
        self::assertArrayHasKey('language_locale', $data);
        self::assertArrayHasKey('language_name', $data);
    }

    public function testSetInvalidLanguageIdIsRejected(): void
    {
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/set-language', ['language_id' => 999999], $token);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertEnvelope400($envelope);
        self::assertStringContainsString('Invalid language ID', $this->coerceString($envelope['error'] ?? ''));
    }

    /**
     * Opt a seeded QA persona into a `qa_`-prefixed 2FA-required group so the
     * real login flow challenges for 2FA. The write is DAMA-rolled-back and
     * never touches a non-QA group (canonical Testing Rule 9).
     */
    private function optPersonaIntoTwoFactorGroup(string $email): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, sprintf('QA persona %s must be seeded', $email));

        $group = new Group();
        $group->setName('qa_2fa_group');
        $group->setDescription('qa 2fa-required group (test-only, DAMA rolled back)');
        $group->setRequires2fa(true);
        $em->persist($group);

        $membership = new UsersGroup();
        $membership->setUser($user);
        $membership->setGroup($group);
        $em->persist($membership);

        $em->flush();

        return (int) $user->getId();
    }

    private function latestUnusedTwoFactorCode(int $userId): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $codeEntity = $em->getRepository(Users2faCode::class)->findOneBy(
            ['user' => $userId, 'isUsed' => false],
            ['createdAt' => 'DESC']
        );

        self::assertInstanceOf(
            Users2faCode::class,
            $codeEntity,
            sprintf('A 2FA code must have been stored for user %d', $userId)
        );
        $code = (string) $codeEntity->getCode();
        self::assertNotSame('', $code, 'The stored 2FA code must be non-empty');

        return $code;
    }
}
