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
 * Per-migration round-trip for the `page_routes` table creation
 * (`Version20260630083439`, issue #30): the DB-driven parameterized public-route
 * contract for CMS pages.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` drops the table + its FK/indexes and `up()` recreates them
 * cleanly with the snake_case constraint names).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630083439RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPageRoutesTableCreationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630083439');
    }
}
