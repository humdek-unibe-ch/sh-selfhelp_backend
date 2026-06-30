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
 * Per-migration round-trip for the data-table display-name endpoint migration
 * (`Version20260629074552`): seed the `admin.data.update_tables` permission +
 * admin grant, and register the
 * `PATCH /admin/data/tables/{tableName}/display-name` route.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` removes the route + permission rows cleanly and `up()` is
 * idempotent on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629074552RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataTableDisplayNameRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629074552');
    }
}
