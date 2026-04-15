<?php

namespace App\Service\Action;

use App\Service\Core\LookupService;

/**
 * Calculates execution dates for action jobs, repeaters, and reminders.
 *
 * Date calculation supports immediate jobs, fixed datetimes, after-period jobs,
 * day/time scheduling, repeating schedules, and diary-style reminder validity windows.
 */
class ActionScheduleCalculatorService
{
    public function __construct(
        private readonly LookupService $lookupService
    ) {
    }

    /**
     * @param array<string, mixed> $actionConfig
     * @param array<string, mixed> $job
     * @return \DateTimeImmutable[]
     *   One or more execution dates for the supplied job configuration.
     */
    public function calculateDates(array $actionConfig, array $job): array
    {
        if (($actionConfig[ActionConfig::REPEAT] ?? false) === true) {
            return $this->calculateRepeaterDates($actionConfig[ActionConfig::REPEATER] ?? [], $job);
        }

        if (($actionConfig[ActionConfig::REPEAT_UNTIL_DATE] ?? false) === true) {
            return $this->calculateRepeaterUntilDates($actionConfig[ActionConfig::REPEATER_UNTIL_DATE] ?? []);
        }

        return [$this->calculateBaseDate($job[ActionConfig::SCHEDULE_TIME] ?? [])];
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section of a job config.
     *
     * @return \DateTimeImmutable
     *   The base execution date for a non-repeating job.
     */
    public function calculateBaseDate(array $schedule): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $scheduleType = $schedule[ActionConfig::JOB_SCHEDULE_TYPES] ?? LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY;

        return match ($scheduleType) {
            LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME => $this->createDateTime($schedule[ActionConfig::CUSTOM_TIME] ?? null, $now),
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD => $this->calculateAfterPeriod($schedule, $now),
            LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD_ON_DAY_AT_TIME => $this->calculateDayAtTime($schedule, $now),
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
        $amount = max(1, (int) ($schedule[ActionConfig::SEND_AFTER] ?? 1));
        $unit = (string) ($schedule[ActionConfig::SEND_AFTER_TYPE] ?? LookupService::TIME_PERIOD_MINUTES);

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

        $validFor = max(1, (int) ($schedule[ActionConfig::VALID] ?? 1));
        $validType = (string) ($schedule[ActionConfig::VALID_TYPE] ?? LookupService::TIME_PERIOD_HOURS);

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
    private function calculateRepeaterDates(array $repeater, array $job): array
    {
        $occurrences = max(1, (int) ($repeater[ActionConfig::OCCURRENCES] ?? 1));
        $frequency = (string) ($repeater[ActionConfig::FREQUENCY] ?? 'day');
        $daysOfWeek = $repeater[ActionConfig::DAYS_OF_WEEK] ?? [];
        $daysOfMonth = $repeater[ActionConfig::DAYS_OF_MONTH] ?? [];

        $dates = [];
        $baseDate = $this->calculateBaseDate($job[ActionConfig::SCHEDULE_TIME] ?? []);
        $cursor = $baseDate;

        while (count($dates) < $occurrences) {
            if (
                $frequency === 'day' ||
                ($frequency === 'week' && $this->matchesWeekday($cursor, $daysOfWeek)) ||
                ($frequency === 'month' && $this->matchesMonthDay($cursor, $daysOfMonth))
            ) {
                $dates[] = $cursor;
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
    private function calculateRepeaterUntilDates(array $repeaterUntil): array
    {
        $deadline = $this->createDateTime($repeaterUntil[ActionConfig::DEADLINE] ?? null, null);
        if ($deadline === null) {
            return [new \DateTimeImmutable('now', new \DateTimeZone('UTC'))];
        }

        $frequency = (string) ($repeaterUntil[ActionConfig::FREQUENCY] ?? 'day');
        $repeatEvery = max(1, (int) ($repeaterUntil[ActionConfig::REPEAT_EVERY] ?? 1));
        $daysOfWeek = $repeaterUntil[ActionConfig::DAYS_OF_WEEK] ?? [];
        $daysOfMonth = $repeaterUntil[ActionConfig::DAYS_OF_MONTH] ?? [];
        $scheduleAt = (string) ($repeaterUntil[ActionConfig::SCHEDULE_AT] ?? '');

        $current = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $current = $this->applyTimeToDate($current, $scheduleAt);
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

        return $dates === [] ? [new \DateTimeImmutable('now', new \DateTimeZone('UTC'))] : $dates;
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section for an after-period job.
     *
     * @return \DateTimeImmutable
     *   The computed date/time after the configured offset.
     */
    private function calculateAfterPeriod(array $schedule, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $amount = max(1, (int) ($schedule[ActionConfig::SEND_AFTER] ?? 1));
        $unit = (string) ($schedule[ActionConfig::SEND_AFTER_TYPE] ?? LookupService::TIME_PERIOD_DAYS);
        $date = $now->modify(sprintf('+%d %s', $amount, $unit));

        return $this->applyTimeToDate($date, (string) ($schedule[ActionConfig::SEND_ON_DAY_AT] ?? ''));
    }

    /**
     * @param array<string, mixed> $schedule
     *   The schedule-time section for an "after period on day at time" job.
     *
     * @return \DateTimeImmutable
     *   The computed date/time matching the requested weekday/time rules.
     */
    private function calculateDayAtTime(array $schedule, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $weeks = max(1, (int) ($schedule[ActionConfig::SEND_ON] ?? 1));
        $weekday = (string) ($schedule[ActionConfig::SEND_ON_DAY] ?? 'Monday');
        $target = $now->modify(sprintf('next %s', $weekday));
        $target = $this->applyTimeToDate($target, (string) ($schedule[ActionConfig::SEND_ON_DAY_AT] ?? '00:00'));

        if ($weeks > 1) {
            $target = $target->modify(sprintf('+%d week', $weeks - 1));
        }

        return $target;
    }

    /**
     * Convert a config value into a UTC datetime, falling back when empty.
     *
     * @param mixed $value
     *   The raw date/time config value.
     * @param \DateTimeImmutable|null $fallback
     *   The fallback value when the config value is empty.
     *
     * @return \DateTimeImmutable|null
     *   The parsed UTC datetime or the fallback.
     */
    private function createDateTime(mixed $value, ?\DateTimeImmutable $fallback): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
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

        $normalizedDays = array_map(static fn(mixed $day): string => strtolower((string) $day), $daysOfWeek);
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

        return in_array((int) $date->format('j'), array_map('intval', $daysOfMonth), true);
    }

    /**
     * Apply an `HH:MM` time string to an existing date.
     *
     * @param \DateTimeImmutable $date
     *   The date whose time component should be adjusted.
     * @param string $time
     *   The `HH:MM` time string from config.
     *
     * @return \DateTimeImmutable
     *   The adjusted datetime, or the original date when parsing fails.
     */
    private function applyTimeToDate(\DateTimeImmutable $date, string $time): \DateTimeImmutable
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
