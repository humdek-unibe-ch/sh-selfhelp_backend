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
 * Per-migration round-trip for the group model cleanup (`Version20261111082842`):
 * `groups.id_group_types` and the `groupTypes` lookups are removed, the
 * `add_to_new_pages` flag is added and set on `subject`. `down()` restores the
 * column and the lookups, so reverting and re-applying must leave the schema in
 * sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261111082842RoundTripTest extends MigrationRoundTripTestCase
{
    public function testGroupModelCleanupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261111082842');
    }
}
