<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Service\Action\ActionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Guards the action-config key catalog: the keys are the contract between admin
 * JSON payloads, the scheduling runtime, and test fixtures, so a silent rename
 * or accidental duplicate value would break action processing.
 */
final class ActionConfigTest extends TestCase
{
    public function testCoreSchedulingKeysHaveStableValues(): void
    {
        self::assertSame('blocks', ActionConfig::BLOCKS);
        self::assertSame('jobs', ActionConfig::JOBS);
        self::assertSame('job_name', ActionConfig::JOB_NAME);
        self::assertSame('job_schedule_types', ActionConfig::JOB_SCHEDULE_TYPES);
        self::assertSame('condition', ActionConfig::CONDITION);
        self::assertSame('notification', ActionConfig::NOTIFICATION);
    }

    public function testOnlyKnownIntentionalDuplicateValueExists(): void
    {
        $constants = (new \ReflectionClass(ActionConfig::class))->getConstants();
        self::assertNotEmpty($constants);

        $stringValues = array_filter($constants, 'is_string');
        $duplicateValues = array_keys(array_filter(
            array_count_values($stringValues),
            static fn (int $count): bool => $count > 1
        ));

        // 'notification' is intentionally shared by NOTIFICATION (a config
        // section key) and JOB_TYPE_NOTIFICATION (a job-type value); they live
        // in different semantic namespaces. Any OTHER duplicate is an accident.
        self::assertSame(
            ['notification'],
            $duplicateValues,
            'Unexpected duplicate ActionConfig value(s); only "notification" may be shared.'
        );
    }
}
