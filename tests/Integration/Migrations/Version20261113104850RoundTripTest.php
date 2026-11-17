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
 * Per-migration round-trip for the column type of generic-store data columns
 * (`Version20261113104850`): `data_cols.id_value_type`, a nullable lookup
 * reference (lookup type `dataColValueTypes`: text, number, date, datetime,
 * boolean; NULL reads as text). The round trip proves `down()` removes the
 * column and its lookups again and that the re-applied `up()` leaves a schema
 * that matches the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261113104850RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataColumnValueTypeMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261113104850');
    }
}
