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
 * Per-migration round-trip for the display-name route-name normalisation
 * (`Version20260630070202`): drops the redundant `_v1` suffix from the
 * `admin_data_table_columns_display_name_patch` and
 * `admin_data_table_display_name_patch` `api_routes.route_name` values.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` restores the `_v1` names and `up()` strips them again cleanly).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630070202RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDisplayNameRouteNameNormalisationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630070202');
    }
}
