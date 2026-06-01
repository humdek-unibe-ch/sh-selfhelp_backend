<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Auth;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * JWT service for token management
 */
class JWTService
{
    public const BLACKLIST_PREFIX = 'jwt_blacklist_';

    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * Create a JWT token for a user with minimal payload (security best practice)
     */
    public function createToken(User $user): string
    {
        // Create payload with minimal claims - no roles/permissions for security
        $payload = [
            'id_users' => $user->getId()
        ];
        
        // Note: Token TTL is configured in lexik_jwt_authentication.yaml
        // using the JWT_TOKEN_TTL environment variable
        
        // Create token with minimal payload
        $user->setUserName($user->getEmail());
        return $this->jwtManager->createFromPayload($user, $payload);
    }

    /**
     * Create a refresh token for a user
     */
    public function createRefreshToken(User $user): RefreshToken
    {
        $refreshToken = new RefreshToken();
        $refreshToken->setUser($user);
        $refreshToken->setTokenHash(bin2hex(random_bytes(32)));
        
        // Get refresh token TTL from environment (in seconds) and convert to DateInterval
        $refreshTokenTtl = (int) $this->params->get('jwt_refresh_token_ttl');
        $expiresAt = new \DateTime();
        $expiresAt->modify('+' . $refreshTokenTtl . ' seconds');
        
        $refreshToken->setExpiresAt($expiresAt);
        
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();
        
        return $refreshToken;
    }

    /**
     * Verify and decode a JWT access token, optionally checking against the blacklist.
     *
     * @throws AuthenticationException if the token is invalid, expired, or blacklisted
     *
     * @return array<string, mixed>
     */
    public function verifyAndDecodeAccessToken(string $token, bool $checkBlacklist = true): array
    {
        $this->logger->debug('[JWTService] Verifying access token.', ['checkBlacklist' => $checkBlacklist]);
        if ($checkBlacklist) {
            $cacheKey = self::BLACKLIST_PREFIX . md5($token);
            $this->logger->debug('[JWTService] Checking blacklist for cache key.', ['cacheKey' => $cacheKey]);
            
            $cachedValue = $this->cache->get($cacheKey, function(ItemInterface $item) use ($cacheKey) {
                $this->logger->debug('[JWTService] Cache miss for blacklist key.', ['cacheKey' => $cacheKey]);
                return false; // Default value if not found (meaning not blacklisted)
            });

            $this->logger->debug('[JWTService] Value retrieved from blacklist cache.', ['cacheKey' => $cacheKey, 'cachedValue' => $cachedValue, 'type' => gettype($cachedValue)]);

            if ($cachedValue === true) {
                $this->logger->warning('[JWTService] Token is blacklisted.', ['cacheKey' => $cacheKey]);
                throw new AuthenticationException('Token has been blacklisted.');
            }
        }

        try {
            $this->logger->debug('[JWTService] Decoding token with jwtEncoder.');
            /** @var array<string, mixed> $payload */
            $payload = $this->jwtEncoder->decode($token);
            if (!$payload) {
                $this->logger->error('[JWTService] Token decoding returned no payload.');
                throw new AuthenticationException('Invalid token payload.');
            }
            $this->logger->debug('[JWTService] Token decoded successfully.');
            return $payload;
        } catch (JWTDecodeFailureException $e) {
            $this->logger->error('[JWTService] JWTDecodeFailureException during token decoding.', ['exception_message' => $e->getReason()]);
            throw new AuthenticationException('Invalid token: ' . $e->getReason(), 0, $e);
        } catch (\Exception $e) {
            $this->logger->error('[JWTService] Exception during token decoding.', ['exception_class' => get_class($e), 'exception_message' => $e->getMessage()]);
            throw new AuthenticationException('Token validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Blacklist an access token.
     */
    public function blacklistAccessToken(string $accessToken): void
    {
        $this->logger->debug('[JWTService] Attempting to blacklist access token.');
        try {
            $payload = $this->jwtEncoder->decode($accessToken);
            $this->logger->debug('[JWTService] Token decoded for blacklisting.', ['payload' => $payload]);

            $tokenTtl = (int) $this->params->get('jwt_token_ttl');
            $expValue = $payload['exp'] ?? null; // Use configured TTL if 'exp' is not present
            $expiresAt = is_numeric($expValue) ? (int) $expValue : (time() + $tokenTtl);
            $remainingLifetime = $expiresAt - time();
            $this->logger->debug('[JWTService] Calculated remaining lifetime for blacklist entry.', ['expiresAt' => $expiresAt, 'remainingLifetime' => $remainingLifetime]);

            if ($remainingLifetime > 0) {
                $cacheKey = self::BLACKLIST_PREFIX . md5($accessToken);
                $this->logger->debug('[JWTService] Preparing to add token to blacklist cache.', ['cacheKey' => $cacheKey, 'lifetime' => $remainingLifetime]);
                
                // Step 1: Delete any existing entry to ensure clean set/overwrite with new TTL
                $this->cache->delete($cacheKey);
                $this->logger->debug('[JWTService] Attempted to delete existing blacklist cache item (if any).', ['cacheKey' => $cacheKey]);

                // Step 2: Use get() with a callback. Since it was deleted, this will be a cache miss,
                // so the callback will execute, setting the value to true and the new expiry.
                $this->cache->get($cacheKey, function (ItemInterface $item) use ($remainingLifetime, $cacheKey) {
                    $item->expiresAfter($remainingLifetime);
                    $this->logger->info('[JWTService] Setting blacklist cache item via get() callback.', ['cacheKey' => $cacheKey, 'expiresAfter' => $remainingLifetime]);
                    return true; // Store true to mark as blacklisted
                });
                $this->logger->debug('[JWTService] Token blacklist cache operation completed using delete then get.', ['cacheKey' => $cacheKey]);
            } else {
                $this->logger->info('[JWTService] Token not added to blacklist because remaining lifetime is not positive.', ['remainingLifetime' => $remainingLifetime]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[JWTService] Error during token blacklisting process.', ['exception_class' => get_class($e), 'exception_message' => $e->getMessage()]);
            // Not adding to blacklist if it's already invalid might be acceptable.
        }
    }

    /**
     * Process a refresh token string: validate it, issue new tokens, and invalidate the old one.
     *
     * @throws AuthenticationException if the refresh token is invalid or processing fails
     *
     * @return array<string, string|null>
     */
    public function processRefreshToken(string $refreshTokenString): array
    {
        $tokenEntity = $this->entityManager->getRepository(RefreshToken::class)
            ->findOneBy(['tokenHash' => $refreshTokenString]);

        if (!$tokenEntity || $tokenEntity->getExpiresAt() < new \DateTime()) {
            if ($tokenEntity) {
                $this->entityManager->remove($tokenEntity);
                $this->entityManager->flush();
            }
            throw new AuthenticationException('Invalid or expired refresh token.');
        }

        $user = $tokenEntity->getUser();
        if (!$user) {
            $this->entityManager->remove($tokenEntity);
            $this->entityManager->flush();
            throw new AuthenticationException('Refresh token is not associated with a user.');
        }

        $newAccessToken = $this->createToken($user);
        
        $this->entityManager->remove($tokenEntity);
        $newRefreshToken = $this->createRefreshToken($user);

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken->getTokenHash(),
        ];
    }

    /**
     * Extract token from request
     */
    public function getTokenFromRequest(Request $request): ?string
    {
        // Try standard Authorization header first
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Try HTTP_AUTHORIZATION (Apache might pass it this way)
        $httpAuth = $request->server->get('HTTP_AUTHORIZATION');
        if (is_string($httpAuth) && str_starts_with($httpAuth, 'Bearer ')) {
            return substr($httpAuth, 7);
        }

        // Try REDIRECT_HTTP_AUTHORIZATION (some Apache configurations)
        $redirectAuth = $request->server->get('REDIRECT_HTTP_AUTHORIZATION');
        if (is_string($redirectAuth) && str_starts_with($redirectAuth, 'Bearer ')) {
            return substr($redirectAuth, 7);
        }

        return null;
    }

    /**
     * Create a short-lived JWT for impersonation.
     *
     * Claim shape follows RFC 8693 (OAuth 2.0 Token Exchange):
     *   - `sub`          : target user id (effective principal — the
     *                       standard OAuth subject claim)
     *   - `id_users`     : same value, the project-internal alias every
     *                       Symfony service already reads
     *   - `act.sub`      : admin user id  (actual party — the "actor")
     *   - `act.id_users` : same value, kept for in-house consumers
     *   - `purpose`      : the literal string "impersonation" — RFC-compliant
     *                       way of declaring this token is NOT a regular
     *                       access token, useful for token-introspection
     *                       gateways and audit dashboards
     *   - `impersonation`: `true`  (fast-path boolean — cheaper than parsing
     *                       `act` for the hot path of every API request)
     *   - `exp`          : absolute expiry (seconds since epoch)
     *
     * TTL is intentionally much shorter than a regular access token
     * (default 15 min, configurable via IMPERSONATION_TOKEN_TTL).
     *
     * @return array<string, mixed>
     */
    public function createImpersonationToken(User $targetUser, int $adminUserId): array
    {
        $ttl = (int) $this->params->get('jwt_impersonation_token_ttl');
        if ($ttl <= 0) {
            $ttl = 900; // defensive: never issue a token with a non-positive lifetime
        }

        $payload = [
            'sub'           => (string) $targetUser->getId(),
            'id_users'      => $targetUser->getId(),
            'act'           => [
                'sub'      => (string) $adminUserId,
                'id_users' => $adminUserId,
            ],
            'purpose'       => 'impersonation',
            'impersonation' => true,
            'exp'           => time() + $ttl,
        ];

        $targetUser->setUserName($targetUser->getEmail());
        $token = $this->jwtManager->createFromPayload($targetUser, $payload);

        $this->logger->info('[JWTService] Impersonation token created.', [
            'target_user_id' => $targetUser->getId(),
            'admin_user_id'  => $adminUserId,
            'expires_in'     => $ttl,
        ]);

        return [
            'access_token' => $token,
            'expires_in'   => $ttl,
        ];
    }

    /**
     * Whether a decoded JWT payload represents an impersonation session.
     * Cheap O(1) flag check — safe to call on every request.
     *
     * @param array<string, mixed> $payload
     */
    public function isImpersonationPayload(array $payload): bool
    {
        return !empty($payload['impersonation']);
    }

    /**
     * Extract the *original* admin user id from an impersonation payload.
     * Returns `null` for regular tokens.
     *
     * Reads the standard RFC 8693 `act.sub` claim first, falls back to the
     * legacy `act.id_users` shape we emit for in-house consumers, and
     * finally to the deprecated `impersonated_by` claim used by the v1
     * implementation so old tokens still work during a rolling deploy.
     *
     * @param array<string, mixed> $payload
     */
    public function getImpersonatorUserId(array $payload): ?int
    {
        if (isset($payload['act']) && is_array($payload['act'])) {
            $act = $payload['act'];
            if (isset($act['id_users']) && is_scalar($act['id_users'])) {
                return (int) $act['id_users'];
            }
            if (isset($act['sub']) && is_scalar($act['sub'])) {
                return (int) $act['sub'];
            }
        }

        if (isset($payload['impersonated_by']) && is_scalar($payload['impersonated_by'])) {
            return (int) $payload['impersonated_by'];
        }

        return null;
    }
}
