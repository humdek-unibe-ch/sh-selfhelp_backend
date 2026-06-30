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
 * Per-migration round-trip for the `data_cols.id_display_name_source` migration
 * (`Version20260626143127`): seed the `dataColDisplayNameSource` lookup group
 * (`auto`|`manual`), replace the free-text `display_name_source` column with the
 * nullable FK `id_display_name_source` -> `lookups.id`, and backfill it.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM
 * mapping (proves `down()` faithfully restores the previous shape and re-derives
 * the free-text value from the FK).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260626143127RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDisplayNameSourceLookupFkMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260626143127');
    }
}
