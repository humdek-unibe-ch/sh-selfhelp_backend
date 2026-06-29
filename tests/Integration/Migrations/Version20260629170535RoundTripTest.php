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
 * Per-migration round-trip for the section data-variables route removal
 * (`Version20260629170535`): drops the now-unused
 * `GET /admin/sections/{section_id}/data-variables` `api_routes` row + its
 * `admin.page.read` link (superseded by the unified
 * `GET /admin/interpolation/variables` endpoint).
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` re-inserts the route + permission link and `up()` removes it
 * cleanly on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629170535RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSectionDataVariablesRouteRemovalRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629170535');
    }
}
