<?php

namespace App\Tests\Service\Action;

use App\Service\Action\ActionConfig;
use App\Service\Action\ActionScheduleCalculatorService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Repository\LookupRepository;
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
        $service = new ActionScheduleCalculatorService($this->createLookupService());

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
        $service = new ActionScheduleCalculatorService($this->createLookupService());
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
     * Create a lightweight lookup service suitable for calculator unit tests.
     *
     * @return LookupService
     *   A lookup service with mocked dependencies.
     */
    private function createLookupService(): LookupService
    {
        return new LookupService(
            $this->createMock(LookupRepository::class),
            $this->createMock(CacheService::class)
        );
    }
}
