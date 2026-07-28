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
 * Per-migration round-trip for the asset folder access-level lookup migration
 * (`Version20260728133415`: seeds the `assetFolderAccessLevels` lookup type and
 * converts `assets_folders_groups.access_level` from a free-text varchar to the
 * FK `id_access_level`).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260728133415RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAssetFolderAccessLevelLookupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260728133415');
    }
}
