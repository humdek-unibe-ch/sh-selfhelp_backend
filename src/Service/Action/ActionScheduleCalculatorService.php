<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Action;

use App\Service\Core\BaseService;
use App\Service\Core\LookupService;

/**
 * Calculates execution dates for action jobs, repeaters, and reminders.
 *
 * Date calculation supports immediate jobs, fixed datetimes, after-period jobs,
 * day/time scheduling, repeating schedules, and diary-style reminder validity windows.
 */
class ActionScheduleCalculatorService extends BaseService
{
    /**
     * Coerce a mixed JSON-config section into a string-keyed array.
     *
     * @return array<string, mixed>
     */
    private function toConfigArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $actionConfig
     * @param array<string, mixed> $job
     * @return \DateTimeImmutable[]
     *   One or more execution dates (UTC) for the supplied job configuration.
     */
    public function calculateDates(array $actionConfig, array $job, ?ActionScheduleContext $context = null): array
    {
        $context ??= ActionScheduleContext::forTimezone(null);

        if (($actionConfig[ActionConfig::REPEAT] ?? false) === true) {
            return $this->calculateRepeaterDates($this->toConfigArray($actionConfig[ActionConfig::REPEATER] ?? null), $job, $context);
        }

        if (($actionConfig[ActionConfig::REPEAT_UNTIL_DATE] ?? false) === true) {
            return $this->calculateRepeaterUntilDates($this->toConfigArray($actionConfig[ActionConfig::REPEATER_UNTIL_DATE] ?? null), $context);
        }

        return [$this->calculateBaseDate($this->toConfigArray($job[ActionConfig::SCHEDULE_TIME] ?? null), $context)];
    }

    /**
     * Determine whether a schedule represents a wall-clock (local time) rule.
     *
     * Wall-clock schedules must be recalculated when the recipient changes
     * timezone so the intended local time is preserved. Purely relative offsets
     * ("after N hours") and immediate schedules are not wall-clock.
     *
     * @param array<string, mixed> $schedule
     *   The schedule-time section of a job config.
     */
    public function isWallClockSchedule(array $schedule): bool
    {
        $scheduleType = $schedule[ActionConfig::JOB_SCHEDULE_TYPES] ?? LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY;

        return match ($scheduleType) {
            LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD_ON_DAY_AT_TIME => true,
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD => $this->asString($schedule[ActionConfig::SEND_ON_DAY_AT] ?? '') !== '',
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section of a job config.
     *
     * @return \DateTimeImmutable
     *   The base execution date (UTC) for a non-repeating job.
     */
    public function calculateBaseDate(array $schedule, ?ActionScheduleContext $context = null): \DateTimeImmutable
    {
        $context ??= ActionScheduleContext::forTimezone(null);
        $now = $context->now;
        $scheduleType = $schedule[ActionConfig::JOB_SCHEDULE_TYPES] ?? LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY;

        return match ($scheduleType) {
            LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME => $this->createFixedDateTime($schedule[ActionConfig::CUSTOM_TIME] ?? null, $context) ?? $now,
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD => $this->calculateAfterPeriod($schedule, $context),
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD_ON_DAY_AT_TIME => $this->calculateDayAtTime($schedule, $context),
            default => $now,
        };
    }

    /**
     * @param array<string, mixed> $schedule
     *   The reminder schedule config.
     *
     * @return \DateTimeImmutable
     *   The reminder execution date relative to the parent job execution.
     */
    public function calculateReminderDate(\DateTimeImmutable $parentExecutionDate, array $schedule): \DateTimeImmutable
    {
        $amount = max(1, $this->asInt($schedule[ActionConfig::SEND_AFTER] ?? 1));
        $unit = $this->asString($schedule[ActionConfig::SEND_AFTER_TYPE] ?? LookupService::TIME_PERIOD_MINUTES);

        return $parentExecutionDate->modify(sprintf('+%d %s', $amount, $unit));
    }

    /**
     * @param array<string, mixed> $schedule
     *   The reminder schedule config.
     * @return array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable}
     *   The reminder validity window used for later cleanup, or nulls when not applicable.
     */
    public function calculateReminderSessionWindow(
        \DateTimeImmutable $parentExecutionDate,
        \DateTimeImmutable $reminderExecutionDate,
        array $schedule
    ): array {
        if (($schedule[ActionConfig::PARENT_JOB_TYPE_HIDDEN] ?? null) !== ActionConfig::JOB_TYPE_NOTIFICATION_WITH_REMINDER_FOR_DIARY) {
            return ['start' => null, 'end' => null];
        }

        $validFor = max(1, $this->asInt($schedule[ActionConfig::VALID] ?? 1));
        $validType = $this->asString($schedule[ActionConfig::VALID_TYPE] ?? LookupService::TIME_PERIOD_HOURS);

        return [
            'start' => $parentExecutionDate,
            'end' => $reminderExecutionDate->modify(sprintf('+%d %s', $validFor, $validType)),
        ];
    }

    /**
     * @param array<string, mixed> $repeater
     * @param array<string, mixed> $job
     * @return \DateTimeImmutable[]
     *   Repeated execution dates generated for an occurrences-based repeater.
     */
    private function calculateRepeaterDates(array $repeater, array $job, ActionScheduleContext $context): array
    {
        $occurrences = max(1, $this->asInt($repeater[ActionConfig::OCCURRENCES] ?? 1));
        $frequency = $this->asString($repeater[ActionConfig::FREQUENCY] ?? 'day');
        $daysOfWeek = array_values($this->asArray($repeater[ActionConfig::DAYS_OF_WEEK] ?? null));
        $daysOfMonth = array_values($this->asArray($repeater[ActionConfig::DAYS_OF_MONTH] ?? null));

        $dates = [];
        $baseDate = $this->calculateBaseDate($this->toConfigArray($job[ActionConfig::SCHEDULE_TIME] ?? null), $context);
        // Iterate in the recipient timezone so the wall-clock time is preserved
        // across DST transitions, converting each occurrence back to UTC.
        $utc = new \DateTimeZone('UTC');
        $cursor = $baseDate->setTimezone($context->timezone);

        while (count($dates) < $occurrences) {
            if (
                $frequency === 'day' ||
                ($frequency === 'week' && $this->matchesWeekday($cursor, $daysOfWeek)) ||
                ($frequency === 'month' && $this->matchesMonthDay($cursor, $daysOfMonth))
            ) {
                $dates[] = $cursor->setTimezone($utc);
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * @param array<string, mixed> $repeaterUntil
     * @return \DateTimeImmutable[]
     *   Repeated execution dates generated until the configured deadline is reached.
     */
    private function calculateRepeaterUntilDates(array $repeaterUntil, ActionScheduleContext $context): array
    {
        $deadline = $this->createFixedDateTime($repeaterUntil[ActionConfig::DEADLINE] ?? null, $context);
        if ($deadline === null) {
            return [$context->now];
        }

        $frequency = $this->asString($repeaterUntil[ActionConfig::FREQUENCY] ?? 'day');
        $repeatEvery = max(1, $this->asInt($repeaterUntil[ActionConfig::REPEAT_EVERY] ?? 1));
        $daysOfWeek = array_values($this->asArray($repeaterUntil[ActionConfig::DAYS_OF_WEEK] ?? null));
        $daysOfMonth = array_values($this->asArray($repeaterUntil[ActionConfig::DAYS_OF_MONTH] ?? null));
        $scheduleAt = $this->asString($repeaterUntil[ActionConfig::SCHEDULE_AT] ?? '');

        $current = $this->applyLocalTimeToDate($context->now, $scheduleAt, $context->timezone);
        $dates = [];

        while ($current <= $deadline) {
            if (
                $frequency === 'day' ||
                ($frequency === 'week' && $this->matchesWeekday($current, $daysOfWeek)) ||
                ($frequency === 'month' && $this->matchesMonthDay($current, $daysOfMonth))
            ) {
                $dates[] = $current;
            }

            $current = match ($frequency) {
                'month' => $current->modify(sprintf('+%d month', $repeatEvery)),
                'week' => $current->modify('+1 day'),
                default => $current->modify(sprintf('+%d day', $repeatEvery)),
            };
        }

        return $dates === [] ? [$context->now] : $dates;
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section for an after-period job.
     *
     * @return \DateTimeImmutable
     *   The computed date/time (UTC) after the configured offset.
     */
    private function calculateAfterPeriod(array $schedule, ActionScheduleContext $context): \DateTimeImmutable
    {
        $amount = max(1, $this->asInt($schedule[ActionConfig::SEND_AFTER] ?? 1));
        $unit = $this->asString($schedule[ActionConfig::SEND_AFTER_TYPE] ?? LookupService::TIME_PERIOD_DAYS);
        // The offset is elapsed time (relative); the optional time-of-day is wall-clock local.
        $date = $context->now->modify(sprintf('+%d %s', $amount, $unit));

        return $this->applyLocalTimeToDate($date, $this->asString($schedule[ActionConfig::SEND_ON_DAY_AT] ?? ''), $context->timezone);
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section for an "after period on day at time" job.
     *
     * @return \DateTimeImmutable
     *   The computed date/time (UTC) matching the requested weekday/time rules.
     */
    private function calculateDayAtTime(array $schedule, ActionScheduleContext $context): \DateTimeImmutable
    {
        $weeks = max(1, $this->asInt($schedule[ActionConfig::SEND_ON] ?? 1));
        $weekday = $this->asString($schedule[ActionConfig::SEND_ON_DAY] ?? 'Monday');
        $time = $this->asString($schedule[ActionConfig::SEND_ON_DAY_AT] ?? '00:00');

        // Compute the weekday + wall-clock time in the recipient timezone, then convert to UTC.
        $localTarget = $context->nowInTimezone()->modify(sprintf('next %s', $weekday));
        $localTarget = $this->setLocalTime($localTarget, $time);

        if ($weeks > 1) {
            $localTarget = $localTarget->modify(sprintf('+%d week', $weeks - 1));
        }

        return $localTarget->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Convert a wall-clock config value into a UTC datetime, falling back when empty.
     *
     * When the value carries no timezone offset it is interpreted in the
     * context timezone; an explicit offset is respected as an absolute instant.
     *
     * @param mixed $value
     *   The raw date/time config value.
     *
     * @return \DateTimeImmutable|null
     *   The parsed UTC datetime, or null when the value is empty.
     */
    private function createFixedDateTime(mixed $value, ActionScheduleContext $context): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw) === 1;
        $zone = $hasOffset ? new \DateTimeZone('UTC') : $context->timezone;

        return (new \DateTimeImmutable($raw, $zone))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Check whether a date matches configured weekday constraints.
     *
     * @param \DateTimeImmutable $date
     *   The date being evaluated.
     * @param array<int, mixed> $daysOfWeek
     *   Configured weekday names.
     *
     * @return bool
     *   `true` when the date matches or when no weekday restriction exists.
     */
    private function matchesWeekday(\DateTimeImmutable $date, array $daysOfWeek): bool
    {
        if ($daysOfWeek === []) {
            return true;
        }

        $normalizedDays = array_map(fn (mixed $day): string => strtolower($this->asString($day)), $daysOfWeek);
        return in_array(strtolower($date->format('l')), $normalizedDays, true);
    }

    /**
     * Check whether a date matches configured month-day constraints.
     *
     * @param \DateTimeImmutable $date
     *   The date being evaluated.
     * @param array<int, mixed> $daysOfMonth
     *   Configured day-of-month values.
     *
     * @return bool
     *   `true` when the date matches or when no month-day restriction exists.
     */
    private function matchesMonthDay(\DateTimeImmutable $date, array $daysOfMonth): bool
    {
        if ($daysOfMonth === []) {
            return true;
        }

        return in_array((int) $date->format('j'), array_map(fn (mixed $day): int => $this->asInt($day), $daysOfMonth), true);
    }

    /**
     * Apply a wall-clock `HH:MM` time to a UTC date in the recipient timezone.
     *
     * The date's calendar day in the recipient timezone is preserved; only the
     * time-of-day is set (in local time) before converting back to UTC. An empty
     * time leaves the original UTC datetime untouched (purely relative offset).
     *
     * @param \DateTimeImmutable $utcDate
     *   The UTC date whose local time-of-day should be set.
     * @param string $time
     *   The `HH:MM` time string from config.
     */
    private function applyLocalTimeToDate(\DateTimeImmutable $utcDate, string $time, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if ($time === '') {
            return $utcDate;
        }

        $local = $this->setLocalTime($utcDate->setTimezone($timezone), $time);

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Set the `HH:MM` time component on a datetime, keeping its (local) timezone.
     *
     * @param \DateTimeImmutable $date
     *   The datetime in its target timezone.
     * @param string $time
     *   The `HH:MM` time string from config.
     */
    private function setLocalTime(\DateTimeImmutable $date, string $time): \DateTimeImmutable
    {
        if ($time === '') {
            return $date;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return $date;
        }

        return $date->setTime((int) $parts[0], (int) $parts[1], 0);
    }
}
