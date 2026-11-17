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
 * Per-migration round-trip for the record versions and the revision history
 * (`Version20261113120906`): `data_rows.version` and the tables
 * `data_revisions`, `data_revision_changes` and `data_revision_values`. The
 * round trip proves `down()` drops them in an order the foreign keys allow
 * and that the re-applied `up()` leaves a schema that matches the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261113120906RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRecordHistoryMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261113120906');
    }
}
