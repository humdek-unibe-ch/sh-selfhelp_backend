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
 * Per-migration round-trip for the data-column display-name endpoint migration
 * (`Version20260626121351`): seed the `admin.data.update_columns` permission +
 * admin grant, and register the
 * `PATCH /admin/data/tables/{tableName}/columns/display-name` route.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` removes the route + permission rows cleanly and `up()` is
 * idempotent on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260626121351RoundTripTest extends MigrationRoundTripTestCase
{
    public function testColumnDisplayNameRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260626121351');
    }
}
