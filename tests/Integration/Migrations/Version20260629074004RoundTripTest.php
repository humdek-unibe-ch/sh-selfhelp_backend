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
 * Per-migration round-trip for the data-table display-name provenance migration
 * (`Version20260629074004`): add the nullable `id_display_name_source` FK
 * (-> `lookups.id`) + its index to `data_tables`, mirroring the column-level
 * auto/manual provenance.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` drops the FK, index, and column cleanly).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629074004RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataTableDisplayNameSourceFkMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629074004');
    }
}
