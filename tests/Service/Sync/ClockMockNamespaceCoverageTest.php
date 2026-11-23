<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Sync;

use App\Service\Sync\ClockBoundaries;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClockMock;

/**
 * F267: phpunit.dist.xml lists App\Service\Sync in clock-mock-namespaces, so
 * ClockBoundaries::time() is mocked without a #[TimeSensitive] attribute.
 */
#[Group('time-sensitive')]
final class ClockMockNamespaceCoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
        parent::tearDown();
    }

    public function testClockBoundariesUsesMockedTimeWithoutTimeSensitiveAttribute(): void
    {
        $frozen = (new \DateTimeImmutable('2031-06-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        ClockMock::withClockMock($frozen);

        $boundaries = new ClockBoundaries();
        $result = $boundaries->collect(static function () use ($boundaries): null {
            $boundaries->noteText('{{system.current_time}}');

            return null;
        });

        self::assertSame(
            $frozen + ClockBoundaries::MIN_TEXT_SECONDS,
            $result['valid_until'],
            'ClockBoundaries must see the frozen clock via the Sync namespace registration.',
        );
    }
}
