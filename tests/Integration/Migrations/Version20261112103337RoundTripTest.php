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
 * Per-migration round-trip for the assets library change
 * (`Version20261112103337`): `assets.extension` and `assets.content_hash`
 * (indexed), `assets_folders.id_parent` as RESTRICT, the folder access route
 * and the removal of the admin role's asset grants. Reverting and re-applying
 * must leave the schema in sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261112103337RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAssetsLibraryMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261112103337');
    }
}
