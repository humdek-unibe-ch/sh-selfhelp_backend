<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Manifest\CronSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The five-field UTC cron of `recurringJobs[].cron` (contract C25): what it
 * accepts, when it runs next and how close two runs can be.
 */
final class CronScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function expressions(): iterable
    {
        yield 'every minute' => ['* * * * *', true];
        yield 'a step' => ['*/15 * * * *', true];
        yield 'a range with a step' => ['0-30/10 8-18 * * 1-5', true];
        yield 'a list' => ['0,30 6,18 1,15 * *', true];
        yield 'seven is Sunday' => ['0 0 * * 7', true];
        yield 'extra spaces' => ['  5   4 * *   *  ', true];
        yield 'four fields' => ['* * * *', false];
        yield 'six fields' => ['* * * * * *', false];
        yield 'minute 60' => ['60 * * * *', false];
        yield 'hour 24' => ['0 24 * * *', false];
        yield 'day of month 0' => ['0 0 0 * *', false];
        yield 'month 13' => ['0 0 1 13 *', false];
        yield 'day of week 8' => ['0 0 * * 8', false];
        yield 'reversed range' => ['30-10 * * * *', false];
        yield 'zero step' => ['*/0 * * * *', false];
        yield 'step on a single value' => ['5/10 * * * *', false];
        yield 'a name' => ['0 0 * JAN *', false];
        yield 'a macro' => ['@hourly', false];
        yield 'an empty list item' => ['1,,2 * * * *', false];
    }

    #[DataProvider('expressions')]
    public function testParse(string $expression, bool $valid): void
    {
        self::assertSame($valid, CronSchedule::parse($expression) !== null, $expression);
    }

    public function testNextRunIsTheNextMatchingMinuteInUtc(): void
    {
        $schedule = CronSchedule::parse('30 3 * * *');
        self::assertNotNull($schedule);

        $before = new \DateTimeImmutable('2026-11-26 03:29:59', new \DateTimeZone('UTC'));
        self::assertSame('2026-11-26 03:30', $schedule->nextRunAfter($before)?->format('Y-m-d H:i'));

        // Strictly after: at the run minute itself the next run is the day after.
        $at = new \DateTimeImmutable('2026-11-26 03:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-11-27 03:30', $schedule->nextRunAfter($at)?->format('Y-m-d H:i'));

        // A moment given in another zone is read as the same instant in UTC.
        $zoned = new \DateTimeImmutable('2026-11-26 05:00:00', new \DateTimeZone('Europe/Zurich'));
        self::assertSame('2026-11-27 03:30', $schedule->nextRunAfter($zoned)?->format('Y-m-d H:i'));
    }

    public function testNextRunSkipsToTheRightMonthAndWeekday(): void
    {
        // The first of January at 00:00.
        $yearly = CronSchedule::parse('0 0 1 1 *');
        self::assertNotNull($yearly);
        self::assertSame(
            '2027-01-01 00:00',
            $yearly->nextRunAfter(new \DateTimeImmutable('2026-11-26 12:00', new \DateTimeZone('UTC')))?->format('Y-m-d H:i')
        );

        // Mondays to Fridays at 09:00: after a Friday evening comes Monday.
        $weekdays = CronSchedule::parse('0 9 * * 1-5');
        self::assertNotNull($weekdays);
        self::assertSame(
            '2026-11-30 09:00',
            $weekdays->nextRunAfter(new \DateTimeImmutable('2026-11-27 18:00', new \DateTimeZone('UTC')))?->format('Y-m-d H:i')
        );
    }

    public function testDayOfMonthOrDayOfWeekWhenBothAreRestricted(): void
    {
        // The 13th, or any Friday (classic cron).
        $schedule = CronSchedule::parse('0 0 13 * 5');
        self::assertNotNull($schedule);

        // 2026-11-13 is itself a Friday; the next run after it is the Friday on the 20th.
        self::assertSame(
            '2026-11-20 00:00',
            $schedule->nextRunAfter(new \DateTimeImmutable('2026-11-13 00:00', new \DateTimeZone('UTC')))?->format('Y-m-d H:i')
        );
        // 2026-12-13 is a Sunday: the 13th matches although it is no Friday.
        self::assertSame(
            '2026-12-13 00:00',
            $schedule->nextRunAfter(new \DateTimeImmutable('2026-12-12 00:00', new \DateTimeZone('UTC')))?->format('Y-m-d H:i')
        );
    }

    public function testScheduleThatNeverRunsHasNoNextRunAndNoGap(): void
    {
        $schedule = CronSchedule::parse('0 0 31 2 *');
        self::assertNotNull($schedule);

        self::assertNull($schedule->nextRunAfter(new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))));
        self::assertNull($schedule->minimumGapMinutes());
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function gaps(): iterable
    {
        yield 'every minute' => ['* * * * *', 1];
        yield 'every 5 minutes' => ['*/5 * * * *', 5];
        yield 'every 7 minutes restarts each hour' => ['*/7 * * * *', 4]; // 56 -> 00 of the next hour
        yield 'two runs in one hour' => ['10,50 * * * *', 20];
        yield 'across the hour' => ['57,1 * * * *', 4];
        yield 'across midnight' => ['0 0,23 * * *', 60];
        yield 'one run a day' => ['0 3 * * *', 1440];
        yield 'weekdays only' => ['0 9 * * 1-5', 1440];
        yield 'two runs on one day' => ['0 9,10 * * *', 60];
        yield 'the last days of each month' => ['59 23 28-31 * *', 1440];
    }

    #[DataProvider('gaps')]
    public function testMinimumGap(string $expression, int $minutes): void
    {
        $schedule = CronSchedule::parse($expression);
        self::assertNotNull($schedule);

        self::assertSame($minutes, $schedule->minimumGapMinutes(), $expression);
    }

    public function testRunsAcrossMidnightOfConsecutiveDaysAreMeasured(): void
    {
        // 23:59 on one day and 00:00 on the next are one minute apart.
        $schedule = CronSchedule::parse('0,59 0,23 * * *');
        self::assertNotNull($schedule);

        self::assertSame(1, $schedule->minimumGapMinutes());
    }
}
