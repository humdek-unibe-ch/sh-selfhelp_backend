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
 * Per-migration round-trip for the two composite `data_rows` indexes of the
 * data query engine (`Version20261113135247`): one for ordered pages and counts
 * of a table, one for the same scoped to the owner. The round trip proves
 * `down()` drops exactly those indexes and that the re-applied `up()` leaves a
 * schema that matches the ORM mapping (the mapping declares both indexes).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261113135247RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataRowIndexesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261113135247');
    }
}
