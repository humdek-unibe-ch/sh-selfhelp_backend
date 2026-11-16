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
 * Per-migration round-trip for `entry-record.fixed_filter`
 * (`Version20261114191938`). The migration adds a style field; the round trip
 * proves `down()` takes it out again and re-applied `up()` leaves the schema
 * in step with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261114191938RoundTripTest extends MigrationRoundTripTestCase
{
    public function testEntryRecordFixedFilterMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261114191938');
    }
}
