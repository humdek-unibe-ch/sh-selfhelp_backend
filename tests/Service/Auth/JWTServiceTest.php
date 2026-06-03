<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Service\Auth\JWTService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Integration coverage for {@see JWTService}: access-token issue + decode,
 * blacklist enforcement, refresh-token rotation/expiry, impersonation claims,
 * and bearer extraction from a request. Runs against the real Lexik JWT
 * encoder + Redis-backed blacklist cache + Doctrine refresh-token store inside
 * the DAMA transaction.
 */
final class JWTServiceTest extends QaKernelTestCase
{
    private JWTService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = $this->service(JWTService::class);
    }

    public function testCreatedTokenDecodesToMinimalUserPayload(): void
    {
        $user = $this->qaUser();

        $token = $this->jwt->createToken($user);
        $payload = $this->jwt->verifyAndDecodeAccessToken($token);

        self::assertSame($user->getId(), $this->coerceInt($payload['id_users'] ?? 0));
        // No permission bits are baked into the token (minimal-claims policy).
        self::assertArrayNotHasKey('permissions', $payload);
    }

    public function testBlacklistedTokenIsRejectedButStillDecodableWithoutCheck(): void
    {
        $token = $this->jwt->createToken($this->qaUser());

        $this->jwt->blacklistAccessToken($token);

        // checkBlacklist=false: the signature is still valid.
        $payload = $this->jwt->verifyAndDecodeAccessToken($token, false);
        self::assertArrayHasKey('id_users', $payload);

        // checkBlacklist=true (default): rejected.
        $rejected = false;
        try {
            $this->jwt->verifyAndDecodeAccessToken($token);
        } catch (AuthenticationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'A blacklisted token must be rejected when the blacklist is checked.');

        // CRITICAL: the blacklist lives in Redis, which survives DAMA rollback.
        // A login token minted for the same persona in the same wall-clock second
        // is byte-identical to this one, so a leaked entry would 401 unrelated
        // tests in other files. Remove it from the exact pool the service uses.
        $this->forgetBlacklistEntry($token);
    }

    public function testRefreshTokenIsPersistedWithFutureExpiry(): void
    {
        $user = $this->qaUser();

        $refresh = $this->jwt->createRefreshToken($user);

        self::assertNotSame('', $refresh->getTokenHash());
        self::assertSame($user->getId(), $refresh->getUser()?->getId());
        self::assertGreaterThan(new \DateTime(), $refresh->getExpiresAt());
    }

    public function testProcessRefreshTokenRotatesAndInvalidatesOldToken(): void
    {
        $user = $this->qaUser();
        $original = $this->jwt->createRefreshToken($user);
        $originalHash = $original->getTokenHash();
        self::assertNotNull($originalHash);

        $result = $this->jwt->processRefreshToken($originalHash);

        self::assertNotEmpty($result['access_token']);
        self::assertNotEmpty($result['refresh_token']);
        self::assertNotSame($originalHash, $result['refresh_token'], 'Rotation must mint a new refresh hash.');

        // Old refresh token must be gone (single-use rotation).
        $old = $this->em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $originalHash]);
        self::assertNull($old, 'The consumed refresh token must be deleted.');

        // The new access token is a valid, decodable JWT for the same user.
        $payload = $this->jwt->verifyAndDecodeAccessToken((string) $result['access_token']);
        self::assertSame($user->getId(), $this->coerceInt($payload['id_users'] ?? 0));
    }

    public function testProcessUnknownRefreshTokenThrows(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->jwt->processRefreshToken('qa-nonexistent-refresh-hash');
    }

    public function testProcessExpiredRefreshTokenThrowsAndRemovesIt(): void
    {
        $user = $this->qaUser();
        $expired = new RefreshToken();
        $expired->setUser($user);
        $expired->setTokenHash('qa_expired_refresh_' . bin2hex(random_bytes(8)));
        $expired->setExpiresAt((new \DateTime())->modify('-1 hour'));
        $this->em->persist($expired);
        $this->em->flush();
        $hash = $expired->getTokenHash();
        self::assertNotNull($hash);

        try {
            $this->jwt->processRefreshToken($hash);
            self::fail('Expired refresh token must be rejected.');
        } catch (AuthenticationException) {
            // expected
        }

        self::assertNull(
            $this->em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]),
            'An expired refresh token must be purged on use.'
        );
    }

    public function testImpersonationTokenCarriesActorClaims(): void
    {
        $target = $this->qaUser();
        $adminId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $result = $this->jwt->createImpersonationToken($target, $adminId);
        self::assertGreaterThan(0, $this->coerceInt($result['expires_in']));

        $payload = $this->jwt->verifyAndDecodeAccessToken($this->asString($result['access_token']));

        self::assertTrue($this->jwt->isImpersonationPayload($payload));
        self::assertSame($adminId, $this->jwt->getImpersonatorUserId($payload));
        self::assertSame($target->getId(), $this->coerceInt($payload['id_users'] ?? 0));
    }

    /**
     * Pins the security-critical impersonation-token expiry math
     * (`exp = time() + jwt_impersonation_token_ttl`) deterministically.
     *
     * This is the one place ClockMock earns its keep: the production code uses
     * the *unqualified* `time()` in the `App` namespace (the only now-source the
     * configured `clock-mock-namespaces=App` bridge can intercept — fully
     * qualified `\DateTimeImmutable('now')` is NOT interceptable, see
     * docs/developer/15-testing-guidelines.md). We freeze to a fixed *future*
     * instant so the App-side `exp` is fully deterministic while the real-clock
     * Lexik decoder still sees a not-yet-expired token.
     */
    #[Group('time-sensitive')]
    public function testImpersonationTokenExpiryIsFrozenNowPlusConfiguredTtl(): void
    {
        $target = $this->qaUser();
        $adminId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);
        $ttl = (int) self::getContainer()->getParameter('jwt_impersonation_token_ttl');
        self::assertGreaterThan(0, $ttl);

        $frozen = 1893456000; // 2030-01-01T00:00:00Z — fixed, in the future.
        ClockMock::withClockMock($frozen);
        try {
            $result = $this->jwt->createImpersonationToken($target, $adminId);
            $payload = $this->jwt->verifyAndDecodeAccessToken($this->asString($result['access_token']));
        } finally {
            ClockMock::withClockMock(false);
        }

        self::assertSame(
            $frozen + $ttl,
            $this->coerceInt($payload['exp'] ?? 0),
            'Impersonation token exp must equal the frozen now plus the configured impersonation TTL.'
        );
        self::assertSame($ttl, $this->coerceInt($result['expires_in']));
    }

    public function testRegularTokenIsNotImpersonation(): void
    {
        $payload = $this->jwt->verifyAndDecodeAccessToken($this->jwt->createToken($this->qaUser()));

        self::assertFalse($this->jwt->isImpersonationPayload($payload));
        self::assertNull($this->jwt->getImpersonatorUserId($payload));
    }

    public function testBearerTokenExtractedFromRequest(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer qa.sample.jwt');

        self::assertSame('qa.sample.jwt', $this->jwt->getTokenFromRequest($request));
        self::assertNull($this->jwt->getTokenFromRequest(new Request()));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Delete the blacklist entry for a token from the precise cache pool the
     * service uses (read via reflection), so the Redis state is clean for the
     * next test regardless of which pool `CacheInterface` is wired to.
     */
    private function forgetBlacklistEntry(string $token): void
    {
        $property = new \ReflectionProperty(JWTService::class, 'cache');
        /** @var \Symfony\Contracts\Cache\CacheInterface $cache */
        $cache = $property->getValue($this->jwt);
        $cache->delete(JWTService::BLACKLIST_PREFIX . md5($token));
    }

    private function qaUser(): User
    {
        return $this->user(QaBaselineFixture::QA_USER_EMAIL);
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
