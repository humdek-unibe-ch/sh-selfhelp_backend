<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\MobilePreview;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\Auth\JWTService;
use App\Service\Auth\UserDataService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mints + consumes the one-time codes that bootstrap a CMS mobile preview.
 *
 * The auth surface deliberately mirrors the existing token-exchange patterns:
 *   - The one-time code is stored HASHED in Redis via {@see CacheInterface}
 *     (same pool + delete-then-get-callback pattern as
 *     {@see JWTService::blacklistAccessToken()} / the refresh-replay map), so
 *     it survives the DAMA test transaction and expires on its own.
 *   - Exchange consumes the code (single-use: delete-on-read) and mints a
 *     short-lived scoped JWT via {@see JWTService::createMobilePreviewToken()}
 *     (mirrors `createImpersonationToken()` — custom claim, short TTL, no
 *     refresh token).
 *
 * The admin JWT is never exposed to the preview iframe; the iframe only ever
 * holds the one-time code (in the URL) and, after exchange, the scoped token
 * (in memory).
 */
class MobilePreviewSessionService
{
    /** Cache-key prefix for the hashed one-time preview codes. */
    public const CODE_PREFIX = 'mobile_preview_code_';

    /** One-time code lifetime (seconds). Short by design (single-use). */
    public const CODE_TTL_SECONDS = 600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTService $jwtService,
        private readonly UserDataService $userDataService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mint a one-time preview code bound to the admin + an optional scope.
     *
     * @param array<string, mixed> $scope keyword/page_id/language_id/draft
     *
     * @return array{code: string, expires_at: string}
     */
    public function createCode(int $adminUserId, array $scope): array
    {
        $code = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::CODE_TTL_SECONDS . ' seconds');

        $payload = json_encode([
            'id_users'   => $adminUserId,
            'scope'      => $scope,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR);

        $cacheKey = $this->cacheKey($code);
        // Delete-then-set so a (statistically impossible) collision overwrites
        // with a fresh TTL — mirrors JWTService::blacklistAccessToken().
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($payload): string {
            $item->expiresAfter(self::CODE_TTL_SECONDS);
            return $payload;
        });

        $this->logger->info('[MobilePreview] Minted one-time preview code.', [
            'admin_user_id' => $adminUserId,
        ]);

        return [
            'code'       => $code,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Consume a one-time code and mint a scoped preview JWT.
     *
     * Single-use: the code is deleted on read regardless of validity, so a
     * replay (or a guess) always fails with the same generic 401 — the error
     * never reveals whether a code existed.
     *
     * @return array{access_token: string, expires_in: int, user: array<string, mixed>}
     */
    public function exchange(string $code): array
    {
        if ($code === '') {
            throw new ServiceException('Invalid or expired preview code.', Response::HTTP_UNAUTHORIZED);
        }

        $cacheKey = $this->cacheKey($code);
        /** @var mixed $stored */
        $stored = $this->cache->get($cacheKey, function (ItemInterface $item): ?string {
            // Cache miss: nothing to consume. Don't pin a long negative entry.
            $item->expiresAfter(1);
            return null;
        });
        // Single-use: consume immediately, valid or not.
        $this->cache->delete($cacheKey);

        if (!is_string($stored) || $stored === '') {
            throw new ServiceException('Invalid or expired preview code.', Response::HTTP_UNAUTHORIZED);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stored, true);
        $adminUserId = (is_array($decoded) && isset($decoded['id_users']) && is_numeric($decoded['id_users']))
            ? (int) $decoded['id_users']
            : 0;
        if ($adminUserId <= 0) {
            throw new ServiceException('Invalid or expired preview code.', Response::HTTP_UNAUTHORIZED);
        }
        $scope = [];
        if (isset($decoded['scope']) && is_array($decoded['scope'])) {
            foreach ($decoded['scope'] as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $scope[(string) $key] = $value;
                }
            }
        }

        $user = $this->entityManager->getRepository(User::class)->find($adminUserId);
        if (!$user instanceof User) {
            throw new ServiceException('Preview user no longer exists.', Response::HTTP_UNAUTHORIZED);
        }

        $tokenData = $this->jwtService->createMobilePreviewToken($user, $scope);

        $this->logger->info('[MobilePreview] Exchanged preview code for scoped token.', [
            'user_id' => $adminUserId,
        ]);

        return [
            'access_token' => (string) $tokenData['access_token'],
            'expires_in'   => (int) $tokenData['expires_in'],
            'user'         => $this->userDataService->getUserData($user),
        ];
    }

    private function cacheKey(string $code): string
    {
        return self::CODE_PREFIX . hash('sha256', $code);
    }
}
