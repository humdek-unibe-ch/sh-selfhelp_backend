<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Service\Action;

use App\Service\Action\ActionConfig;
use App\Service\Action\ActionScheduleCalculatorService;
use App\Service\Action\ActionScheduleContext;
use App\Service\Core\LookupService;
use PHPUnit\Framework\TestCase;

/**
 * Covers action date calculation helpers used by the scheduler.
 */
class ActionScheduleCalculatorServiceTest extends TestCase
{
    /**
     * Ensure after-period schedules preserve the configured execution time.
     */
    public function testCalculateBaseDateAfterPeriodUsesConfiguredTime(): void
    {
        $service = new ActionScheduleCalculatorService();

        $date = $service->calculateBaseDate([
            ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD,
            ActionConfig::SEND_AFTER => 2,
            ActionConfig::SEND_AFTER_TYPE => LookupService::TIME_PERIOD_DAYS,
            ActionConfig::SEND_ON_DAY_AT => '08:15',
        ]);

        $this->assertSame('08:15', $date->format('H:i'));
    }

    /**
     * Ensure diary reminders return the expected cleanup validity window.
     */
    public function testCalculateReminderSessionWindowForDiaryReturnsWindow(): void
    {
        $service = new ActionScheduleCalculatorService();
        $parentDate = new \DateTimeImmutable('2026-04-13 08:00:00', new \DateTimeZone('UTC'));
        $reminderDate = new \DateTimeImmutable('2026-04-13 10:00:00', new \DateTimeZone('UTC'));

        $window = $service->calculateReminderSessionWindow($parentDate, $reminderDate, [
            ActionConfig::PARENT_JOB_TYPE_HIDDEN => ActionConfig::JOB_TYPE_NOTIFICATION_WITH_REMINDER_FOR_DIARY,
            ActionConfig::VALID => 4,
            ActionConfig::VALID_TYPE => LookupService::TIME_PERIOD_HOURS,
        ]);

        $this->assertEquals('2026-04-13 08:00:00', $window['start']?->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-13 14:00:00', $window['end']?->format('Y-m-d H:i:s'));
    }

    /**
     * A fixed wall-clock datetime is interpreted in the recipient timezone and
     * persisted as UTC. Europe/Zurich 07:00 in winter (CET, +01:00) is 06:00 UTC.
     */
    public function testFixedWallClockDatetimeInZurichWinterPersistsAsUtc(): void
    {
        $service = new ActionScheduleCalculatorService();

        $date = $service->calculateBaseDate(
            [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
                ActionConfig::CUSTOM_TIME => '2026-01-15 07:00:00',
            ],
            ActionScheduleContext::forTimezone('Europe/Zurich'),
        );

        $this->assertSame('2026-01-15 06:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    /**
     * Europe/Zurich 07:00 in summer (CEST, +02:00) is 05:00 UTC.
     */
    public function testFixedWallClockDatetimeInZurichSummerPersistsAsUtc(): void
    {
        $service = new ActionScheduleCalculatorService();

        $date = $service->calculateBaseDate(
            [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
                ActionConfig::CUSTOM_TIME => '2026-07-15 07:00:00',
            ],
            ActionScheduleContext::forTimezone('Europe/Zurich'),
        );

        $this->assertSame('2026-07-15 05:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * America/New_York 07:00 in winter (EST, -05:00) is 12:00 UTC.
     */
    public function testFixedWallClockDatetimeInNewYorkUsesDateOffset(): void
    {
        $service = new ActionScheduleCalculatorService();

        $date = $service->calculateBaseDate(
            [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
                ActionConfig::CUSTOM_TIME => '2026-01-15 07:00:00',
            ],
            ActionScheduleContext::forTimezone('America/New_York'),
        );

        $this->assertSame('2026-01-15 12:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * A datetime carrying an explicit offset is treated as an absolute instant,
     * not reinterpreted in the recipient timezone.
     */
    public function testFixedDatetimeWithExplicitOffsetIsAbsolute(): void
    {
        $service = new ActionScheduleCalculatorService();

        $date = $service->calculateBaseDate(
            [
                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
                ActionConfig::CUSTOM_TIME => '2026-01-15T07:00:00+05:00',
            ],
            ActionScheduleContext::forTimezone('Europe/Zurich'),
        );

        $this->assertSame('2026-01-15 02:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * Purely relative "after N hours" schedules are elapsed-time based and stay
     * the same instant regardless of recipient timezone (not wall-clock shifted).
     */
    public function testRelativeAfterPeriodIsTimezoneIndependent(): void
    {
        $service = new ActionScheduleCalculatorService();
        $now = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        $schedule = [
            ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD,
            ActionConfig::SEND_AFTER => 2,
            ActionConfig::SEND_AFTER_TYPE => LookupService::TIME_PERIOD_HOURS,
        ];

        $zurich = $service->calculateBaseDate($schedule, new ActionScheduleContext($now, new \DateTimeZone('Europe/Zurich')));
        $newYork = $service->calculateBaseDate($schedule, new ActionScheduleContext($now, new \DateTimeZone('America/New_York')));

        $this->assertSame('2026-01-15 12:00:00', $zurich->format('Y-m-d H:i:s'));
        $this->assertSame($zurich->format('Y-m-d H:i:s'), $newYork->format('Y-m-d H:i:s'));
        $this->assertFalse($service->isWallClockSchedule($schedule));
    }

    /**
     * Fixed-datetime and day-at-time schedules are flagged as wall-clock so the
     * timezone-adjustment service knows to recalculate them on timezone change.
     */
    public function testIsWallClockScheduleDetection(): void
    {
        $service = new ActionScheduleCalculatorService();

        $this->assertTrue($service->isWallClockSchedule([
            ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_ON_FIXED_DATETIME,
        ]));
        $this->assertTrue($service->isWallClockSchedule([
            ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_AFTER_PERIOD_ON_DAY_AT_TIME,
        ]));
        $this->assertFalse($service->isWallClockSchedule([
            ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY,
        ]));
    }
}
