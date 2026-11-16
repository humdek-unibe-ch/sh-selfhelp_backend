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
 * Per-migration round-trip for the two list styles (`Version20261113120844`):
 * the `entry-form-list` and `data-view` styles with their fields, defaults and
 * the list state routes in `api_routes`. The migration changes no table shape;
 * the round trip proves `down()` can take every seeded row out again (no
 * foreign key keeps a style, field or route behind; `down()` itself refuses
 * only while a section still uses one of the styles, which a fresh database
 * does not have) and that the re-applied `up()` leaves the schema in step with
 * the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261113120844RoundTripTest extends MigrationRoundTripTestCase
{
    public function testListStylesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261113120844');
    }
}
