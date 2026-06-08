<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Core;

use App\Entity\ScheduledJob;
use App\Repository\ScheduledJobRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\QueuedJobTimezoneAdjustmentService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class QueuedJobTimezoneAdjustmentServiceTest extends TestCase
{
    public function testRelativeQueuedJobIsReanchoredToNewTimezone(): void
    {
        // 10:00 UTC == 11:00 in Europe/Zurich (UTC+1 in January).
        $job = (new ScheduledJob())
            ->setDateToBeExecuted(new \DateTime('2026-01-01 10:00:00', new \DateTimeZone('UTC')))
            ->setConfig([
                'schedule' => [
                    'timezone' => 'Europe/Zurich',
                    'timezone_source' => 'user',
                    'local_datetime' => '2026-01-01T11:00:00',
                    'wall_clock' => false,
                ],
            ]);

        $repository = $this->createStub(ScheduledJobRepository::class);
        $repository->method('findQueuedFutureJobsForUser')->willReturn([$job]);

        $cache = $this->createStub(CacheService::class);
        $cache->method('withCategory')->willReturnSelf();

        $service = new QueuedJobTimezoneAdjustmentService(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            $this->createStub(TransactionService::class),
            $cache,
            $this->createStub(LoggerInterface::class),
        );

        $adjusted = $service->adjustForUser(42, 'Asia/Tokyo');

        self::assertSame(1, $adjusted);

        // Even though this is a relative ("wall_clock = false") job, the intended
        // local time (11:00) is preserved and re-anchored: 11:00 Asia/Tokyo
        // (UTC+9) == 02:00 UTC.
        self::assertSame(
            '2026-01-01 02:00:00',
            \DateTime::createFromInterface($job->getDateToBeExecuted())
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s')
        );

        $config = $job->getConfig();
        self::assertIsArray($config);
        $schedule = $config['schedule'] ?? null;
        self::assertIsArray($schedule);
        self::assertSame('Asia/Tokyo', $schedule['timezone'] ?? null);
        self::assertSame('user', $schedule['timezone_source'] ?? null);
        self::assertSame('2026-01-01T11:00:00', $schedule['local_datetime'] ?? null);
    }
}
