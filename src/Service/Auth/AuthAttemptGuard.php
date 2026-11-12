<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * The failed-attempt counters of ONE login or two-factor try: one for the
 * account, and one for the visitor address when the backend knows it (see
 * {@see AuthThrottleService::visitorAddress()}).
 *
 * Only failures count. A success clears the account counter, never the address
 * counter: otherwise someone who holds one valid account could wipe the
 * address counter between guesses at other accounts.
 */
final class AuthAttemptGuard
{
    public function __construct(
        private readonly LimiterInterface $account,
        private readonly ?LimiterInterface $address,
    ) {
    }

    /**
     * The full window to answer 429 with, or null when the attempt may go on.
     * The address is checked first, so a blocked address does not keep
     * filling the counters of the accounts it tries.
     */
    public function blockedBy(): ?RateLimit
    {
        foreach ([$this->address, $this->account] as $limiter) {
            if ($limiter === null) {
                continue;
            }
            $limit = $limiter->consume(0);
            if ($limit->getRemainingTokens() === 0) {
                return $limit;
            }
        }

        return null;
    }

    /** A wrong password or code. */
    public function failed(): void
    {
        $this->account->consume(1);
        $this->address?->consume(1);
    }

    /** A right password or code: the account starts over, the address does not. */
    public function succeeded(): void
    {
        $this->account->reset();
    }
}
