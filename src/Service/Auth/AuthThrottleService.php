<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Brute-force limits of the credential endpoints (login, two-factor, password
 * recovery): one counter per account and one per visitor address.
 *
 * The address counter only works when the address is the visitor's. Browser
 * traffic reaches the backend through the frontend BFF, so a call from a
 * trusted proxy without X-Forwarded-For carries only the proxy's own address,
 * and counting it (or any other infrastructure address) would put every
 * visitor in one bucket and let one attacker lock all of them out.
 * {@see visitorAddress()} therefore answers null then, and only the
 * per-account limits apply.
 *
 * Limits and windows are env-tunable, see config/packages/rate_limiter.yaml.
 */
final class AuthThrottleService
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $authLoginLimiter,
        private readonly RateLimiterFactoryInterface $authLoginIpLimiter,
        private readonly RateLimiterFactoryInterface $authTwoFactorLimiter,
        private readonly RateLimiterFactoryInterface $authTwoFactorIpLimiter,
        private readonly RateLimiterFactoryInterface $authForgotPasswordLimiter,
        private readonly RateLimiterFactoryInterface $authForgotPasswordIpLimiter,
    ) {
    }

    /**
     * The visitor's address, or null when the backend does not really see it:
     * a direct call gives its own peer address, a trusted proxy gives the
     * address it passed in X-Forwarded-For. A trusted proxy that passed none,
     * and any private or reserved address (loopback, LAN, container network),
     * are not a visitor's address: that is infrastructure, shared by everyone
     * behind it. IPv6 is counted per /64 network (one subscriber's block), so
     * rotating inside it does not dodge the limit.
     */
    public function visitorAddress(Request $request): ?string
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $client = $request->getClientIp();
        if (!is_string($peer) || $peer === '' || $client === null) {
            return null;
        }
        if ($request->isFromTrustedProxy() && $client === $peer) {
            return null;
        }
        if (IpUtils::isPrivateIp($client)) {
            return null;
        }

        return IpUtils::anonymize($client, 0, 8);
    }

    /** Counters of one password attempt for this email. */
    public function login(Request $request, string $email): AuthAttemptGuard
    {
        return new AuthAttemptGuard(
            $this->authLoginLimiter->create('login:' . mb_strtolower(trim($email))),
            $this->addressLimiter($this->authLoginIpLimiter, 'login-ip:', $request),
        );
    }

    /** Counters of one two-factor code attempt for this user. */
    public function twoFactor(Request $request, int $userId): AuthAttemptGuard
    {
        return new AuthAttemptGuard(
            $this->authTwoFactorLimiter->create('2fa:' . $userId),
            $this->addressLimiter($this->authTwoFactorIpLimiter, '2fa-ip:', $request),
        );
    }

    /**
     * Counts one password-recovery request and returns the full window to
     * answer 429 with, or null when the request may go on. Every request is
     * counted, known account or not, so the answer reveals nothing about which
     * emails are registered.
     */
    public function recoveryBlockedBy(Request $request, string $email): ?RateLimit
    {
        $addressLimiter = $this->addressLimiter($this->authForgotPasswordIpLimiter, 'forgot-ip:', $request);
        if ($addressLimiter !== null) {
            $limit = $addressLimiter->consume(1);
            if (!$limit->isAccepted()) {
                return $limit;
            }
        }

        $limit = $this->authForgotPasswordLimiter
            ->create('forgot:' . mb_strtolower(trim($email)))
            ->consume(1);

        return $limit->isAccepted() ? null : $limit;
    }

    private function addressLimiter(
        RateLimiterFactoryInterface $factory,
        string $keyPrefix,
        Request $request,
    ): ?LimiterInterface {
        $address = $this->visitorAddress($request);

        return $address === null ? null : $factory->create($keyPrefix . $address);
    }
}
