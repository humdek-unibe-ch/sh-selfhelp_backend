<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Auth;

use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Request-scoped helper around the Symfony security context.
 *
 * Exposes the current authenticated user and — when the caller is using
 * an impersonation JWT — the original admin id. The decoded JWT payload
 * is read from the request attribute `_jwt_payload` that
 * {@see \App\Security\JWTTokenAuthenticator} populates after a successful
 * decode, so this service does NO extra parsing or DB work.
 *
 * Naming follows the OAuth 2.0 Token Exchange (RFC 8693) terminology:
 *
 *   - **Effective user**: who the request runs as (the JWT `sub`/`id_users`).
 *     In a regular session that is the admin themselves; in an
 *     impersonation session that is the target user.
 *   - **Actual user**: the human being who authenticated (the JWT `act.sub`
 *     when impersonating, otherwise the same as effective).
 *
 * Use `isImpersonating()` for fast-path branches, `getActualUserId()` for
 * audit trails and "who really did this?" decisions, `getEffectiveUserId()`
 * for authorisation decisions ("can THIS principal do THIS thing?").
 */
class UserContextService
{
    /**
     * Sentinel id used for anonymous (unauthenticated) visitors at the ACL
     * and cache boundary. It is deliberately NOT a real user row: the
     * `get_user_acl` stored procedure joins `rel_groups_users` on this id,
     * finds no group memberships, and therefore returns ONLY pages flagged
     * `is_open_access = 1`. Using a dedicated id (not the admin's id 1) also
     * keeps anonymous page/section render caches in their own entity scope so
     * they can never collide with a real user's personalised content.
     *
     * It must never be written to `*.id_users` columns — anonymous-owned
     * rows store `NULL` there (the columns are nullable and carry no FK).
     */
    public const GUEST_USER_ID = 0;

    private ?User $cachedUser = null;
    private bool $userResolved = false;

    public function __construct(
        private Security $security,
        private CacheService $cache,
        private RequestStack $requestStack,
    ) {}

    /**
     * Returns the current authenticated User entity or null if not authenticated.
     * Uses request-scoped caching to avoid multiple security context lookups.
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User
    {
        // Use request-scoped cache to avoid multiple security context lookups
        if (!$this->userResolved) {
            $user = $this->security->getUser();
            $this->cachedUser = $user instanceof User ? $user : null;
            $this->userResolved = true;
        }

        return $this->cachedUser;
    }

    public function getCache(): CacheService
    {
        return $this->cache;
    }

    /**
     * Returns the JWT payload that {@see JWTTokenAuthenticator::authenticate}
     * stashed on the current request, or `null` for unauthenticated /
     * non-API requests where the listener never ran.
     *
     * Internal helper — domain code should call the typed accessors below.
     *
     * @return array<array-key, mixed>|null
     */
    private function getJwtPayload(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $payload = $request->attributes->get('_jwt_payload');
        return is_array($payload) ? $payload : null;
    }

    /**
     * Whether the current request authenticates with an impersonation JWT.
     * Cheap O(1) flag check — safe on every API call.
     */
    public function isImpersonating(): bool
    {
        $payload = $this->getJwtPayload();
        return $payload !== null && !empty($payload['impersonation']);
    }

    /**
     * The id of the *actual* admin behind an impersonation session, or
     * `null` if the caller is not impersonating. Reads RFC 8693 `act.sub`
     * (with the legacy `impersonated_by` fallback for tokens minted before
     * the v8 rewrite).
     */
    public function getImpersonatedByUserId(): ?int
    {
        $payload = $this->getJwtPayload();
        if ($payload === null || empty($payload['impersonation'])) {
            return null;
        }

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

    /**
     * The id of the *actual* user who authenticated. When impersonating,
     * this is the original admin (`act.sub`); otherwise it is the same
     * as the effective user. Returns `null` for anonymous requests.
     *
     * Use this for audit trails — "who really did this?" — never for
     * authorisation decisions.
     */
    public function getActualUserId(): ?int
    {
        if ($this->isImpersonating()) {
            return $this->getImpersonatedByUserId();
        }

        return $this->getCurrentUser()?->getId();
    }

    /**
     * The id under which the current request is being authorised. When
     * impersonating, this is the *target* user; otherwise the same as
     * the actual user. Returns `null` for anonymous requests.
     *
     * Use this for authorisation decisions — every CRUD/permission check
     * should run as the effective principal.
     */
    public function getEffectiveUserId(): ?int
    {
        return $this->getCurrentUser()?->getId();
    }
}
