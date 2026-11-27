<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Round-trip for Version20261126152042 (contract C26): `up()` adds the nullable
 * `record_ref` column (VARCHAR 191) to `scheduled_jobs`; `down()` drops it.
 * Existing jobs stay with a null reference.
 */
#[Group('migration')]
final class Version20261126152042RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126152042';

    public function testTheColumnIsAddedWithoutTouchingExistingJobsAndDropped(): void
    {
        $this->migrate(self::VERSION);
        $this->migrate('prev'); // down()

        self::assertSame(0, $this->recordRefColumns(), 'down(): scheduled_jobs has no record_ref.');

        $this->migrate(self::VERSION); // up()

        self::assertSame(1, $this->recordRefColumns(), 'up(): scheduled_jobs has a nullable record_ref of 191 characters.');

        $this->migrate('prev'); // down() again

        self::assertSame(0, $this->recordRefColumns(), 'down(): the column is dropped.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    /** 1 when scheduled_jobs has a nullable `record_ref` VARCHAR(191), else 0. */
    private function recordRefColumns(): int
    {
        return $this->queryInt(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
            . " AND table_name = 'scheduled_jobs' AND column_name = 'record_ref'"
            . " AND is_nullable = 'YES' AND data_type = 'varchar' AND character_maximum_length = 191"
        );
    }
}
