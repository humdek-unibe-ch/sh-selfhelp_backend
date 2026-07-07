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
 * Per-migration round-trip for the CMS-in-CMS catalog cleanup
 * (`Version20260706221024`): the `show-user-input` -> `entry-table` style
 * rename plus the removal of the legacy pre-`data_config` binding fields from
 * the entry holders (`data_table`/`filter`/`scope`/`load_as_table`/
 * `own_entries_only`/`url_param` links, the `entry-record-delete` `type`
 * link, and the orphaned `filter`/`load_as_table`/`url_param`/`type` field
 * definitions).
 *
 * Data-only catalog change (UPDATE + FK-cascading DELETEs up, re-seed down),
 * so the round-trip proves the schema stays ORM-aligned across revert+replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706221024RoundTripTest extends MigrationRoundTripTestCase
{
    public function testEntryTableRenameAndLegacyFieldCleanupRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706221024');
    }
}
