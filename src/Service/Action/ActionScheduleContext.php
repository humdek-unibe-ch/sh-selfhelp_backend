<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Action;

/**
 * Carries the contextual inputs needed to calculate action execution dates.
 *
 * Wall-clock schedule fields (fixed datetime, send-on-day-at, repeater
 * schedule_at) are interpreted in {@see self::$timezone} and then converted to
 * UTC for persistence. Purely relative offsets ("after N hours") stay elapsed.
 */
final class ActionScheduleContext
{
    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly \DateTimeZone $timezone,
        public readonly bool $wallClock = true,
    ) {
    }

    /**
     * Build a context for the given timezone identifier with the current UTC time.
     *
     * Falls back to UTC when the identifier is empty or invalid.
     */
    public static function forTimezone(?string $timezoneId, ?\DateTimeImmutable $now = null): self
    {
        $timezone = new \DateTimeZone('UTC');
        if ($timezoneId !== null && $timezoneId !== '') {
            try {
                $timezone = new \DateTimeZone($timezoneId);
            } catch (\Throwable) {
                $timezone = new \DateTimeZone('UTC');
            }
        }

        return new self(
            $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $timezone,
        );
    }

    /**
     * The current time expressed in the context timezone (for wall-clock math).
     */
    public function nowInTimezone(): \DateTimeImmutable
    {
        return $this->now->setTimezone($this->timezone);
    }
}
