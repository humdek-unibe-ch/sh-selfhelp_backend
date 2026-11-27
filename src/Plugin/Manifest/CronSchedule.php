<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Manifest;

/**
 * A five-field cron expression in UTC (`recurringJobs[].cron`, contract C25).
 *
 * Fields: minute (0-59), hour (0-23), day of month (1-31), month (1-12),
 * day of week (0-7, 0 and 7 are Sunday). Each field is `*`, a number, a
 * range `a-b`, a step `*` or `a-b` followed by `/n`, or a comma list of
 * those. Names (`MON`) and macros (`@daily`) are not accepted.
 *
 * Like classic cron, a day matches when the day-of-month **or** the
 * day-of-week matches if both fields are restricted; a field that starts with
 * `*` does not restrict.
 *
 * The class answers the two questions the host asks: the next run after a
 * moment, and the shortest distance between two runs (the manifest validator
 * refuses a schedule closer than five minutes).
 */
final class CronSchedule
{
    /** Days looked at when the shortest gap is measured: two year ends, one of them in a leap year. */
    private const GAP_SCAN_DAYS = 800;

    /** How far {@see nextRunAfter()} looks before it decides that the schedule never runs. */
    private const NEXT_RUN_SCAN = '+8 years';

    /**
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $daysOfMonth
     * @param array<int, true> $months
     * @param array<int, true> $daysOfWeek 0 = Sunday
     */
    private function __construct(
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $daysOfMonth,
        private readonly array $months,
        private readonly array $daysOfWeek,
        private readonly bool $dayOfMonthIsStar,
        private readonly bool $dayOfWeekIsStar,
    ) {
    }

    /** The parsed schedule, or null when the text is not a valid five-field expression. */
    public static function parse(string $expression): ?self
    {
        $fields = preg_split('/\s+/', trim($expression));
        if (!is_array($fields) || count($fields) !== 5) {
            return null;
        }

        $minutes = self::parseField($fields[0], 0, 59);
        $hours = self::parseField($fields[1], 0, 23);
        $daysOfMonth = self::parseField($fields[2], 1, 31);
        $months = self::parseField($fields[3], 1, 12);
        $weekdays = self::parseField($fields[4], 0, 7);
        if ($minutes === null || $hours === null || $daysOfMonth === null || $months === null || $weekdays === null) {
            return null;
        }

        $daysOfWeek = [];
        foreach (array_keys($weekdays) as $day) {
            $daysOfWeek[$day === 7 ? 0 : $day] = true;
        }

        return new self(
            $minutes,
            $hours,
            $daysOfMonth,
            $months,
            $daysOfWeek,
            str_starts_with($fields[2], '*'),
            str_starts_with($fields[4], '*'),
        );
    }

    /**
     * The first run strictly after `$after`, in UTC, or null when the
     * schedule has no run within eight years (for example `0 0 31 2 *`).
     */
    public function nextRunAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $time = $after->setTimezone(new \DateTimeZone('UTC'));
        $time = $time->setTime((int) $time->format('G'), (int) $time->format('i'), 0)->modify('+1 minute');
        $limit = $time->modify(self::NEXT_RUN_SCAN);

        while ($time < $limit) {
            if (!isset($this->months[(int) $time->format('n')])) {
                $time = $time->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            if (!$this->dayMatches($time)) {
                $time = $time->modify('+1 day')->setTime(0, 0);
                continue;
            }
            $hour = (int) $time->format('G');
            if (!isset($this->hours[$hour])) {
                $time = $time->setTime($hour + 1, 0);
                continue;
            }
            if (!isset($this->minutes[(int) $time->format('i')])) {
                $time = $time->modify('+1 minute');
                continue;
            }

            return $time;
        }

        return null;
    }

    /**
     * Minutes between the two closest runs, or null when the schedule never
     * runs. A schedule with a single run in the measured span answers a very
     * large number.
     */
    public function minimumGapMinutes(): ?int
    {
        $dayMinutes = [];
        foreach (array_keys($this->hours) as $hour) {
            foreach (array_keys($this->minutes) as $minute) {
                $dayMinutes[] = $hour * 60 + $minute;
            }
        }
        sort($dayMinutes);

        $gap = PHP_INT_MAX;
        for ($i = 1, $count = count($dayMinutes); $i < $count; $i++) {
            $gap = min($gap, $dayMinutes[$i] - $dayMinutes[$i - 1]);
        }
        $first = $dayMinutes[0];
        $last = $dayMinutes[count($dayMinutes) - 1];

        $day = new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC'));
        $previous = null;
        for ($index = 0; $index < self::GAP_SCAN_DAYS; $index++, $day = $day->modify('+1 day')) {
            if (!$this->dayMatches($day)) {
                continue;
            }
            if ($previous !== null) {
                $gap = min($gap, ($index - $previous) * 1440 - $last + $first);
            }
            $previous = $index;
        }

        return $previous === null ? null : $gap;
    }

    private function dayMatches(\DateTimeImmutable $day): bool
    {
        if (!isset($this->months[(int) $day->format('n')])) {
            return false;
        }

        $ofMonth = isset($this->daysOfMonth[(int) $day->format('j')]);
        $ofWeek = isset($this->daysOfWeek[(int) $day->format('w')]);

        if ($this->dayOfMonthIsStar || $this->dayOfWeekIsStar) {
            return $ofMonth && $ofWeek;
        }

        return $ofMonth || $ofWeek;
    }

    /**
     * @return array<int, true>|null the allowed values in order, or null for an invalid field
     */
    private static function parseField(string $field, int $min, int $max): ?array
    {
        $values = [];
        foreach (explode(',', $field) as $part) {
            $step = 1;
            $range = $part;
            if (str_contains($part, '/')) {
                [$range, $stepText] = explode('/', $part, 2);
                if (preg_match('/^[1-9]\d*$/', $stepText) !== 1 || ($range !== '*' && !str_contains($range, '-'))) {
                    return null;
                }
                $step = (int) $stepText;
            }

            if ($range === '*') {
                $from = $min;
                $to = $max;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $range, $match) === 1) {
                $from = (int) $match[1];
                $to = (int) $match[2];
            } elseif (preg_match('/^\d+$/', $range) === 1) {
                $from = (int) $range;
                $to = $from;
            } else {
                return null;
            }

            if ($from < $min || $to > $max || $from > $to) {
                return null;
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        ksort($values);

        return $values;
    }
}
