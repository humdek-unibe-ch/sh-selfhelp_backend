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
 * Per-migration round-trip for the `data_cols.field_key` migration
 * (`Version20260626120120`): rename `name` -> `field_key` (immutable opaque
 * key), add `display_name` + `display_name_source`, add the unique key, and
 * rebuild `build_dynamic_columns`.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM
 * mapping (proves `down()` faithfully restores the previous shape). The key
 * uses the table default collation — no per-column override — so there is no
 * collation diff for the comparator to flag.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260626120120RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFieldKeyMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260626120120');
    }
}
