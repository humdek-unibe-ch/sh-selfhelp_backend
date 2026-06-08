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
 * Per-migration round-trip for the admin data-export routes
 * (`Version20260603092955`: inserts the GET single-table export and POST
 * bulk-export `api_routes` rows and links them to `admin.data.read`).
 *
 * Data-only migration: proves the INSERT IGNORE / INSERT...SELECT statements
 * apply and the down() DELETEs revert them without error and the schema stays
 * in sync. Release-tier (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260603092955RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAdminDataExportRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260603092955');
    }
}
