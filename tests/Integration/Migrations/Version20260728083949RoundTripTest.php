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
 * Per-migration round-trip for the asset folder open-access migration
 * (`Version20260728083949`: creates the `assets_folders` table with
 * `is_open_access` and repoints `assets_folders_groups.folder` to the FK
 * `id_assets_folders`, backfilling existing grants).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260728083949RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAssetFolderOpenAccessMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260728083949');
    }
}
