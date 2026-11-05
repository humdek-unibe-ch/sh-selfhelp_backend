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
 * Per-migration round-trip for the asset rework (`Version20261103112133`):
 * uuid identity, `rel_assets_groups` and organisation-only folders, replacing
 * folder ACLs. `down()` rebuilds the folder tables, so reverting
 * and re-applying must leave the schema in sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261103112133RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAssetReworkMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261103112133');
    }
}
