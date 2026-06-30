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
 * Per-migration round-trip for the CMS field-catalog cleanup migration
 * (`Version20260629150730`): de-duplicate the `select` style options, remove
 * the dead `web_combobox_data` / `items` / `labels` field definitions, and
 * enrich a few structured-field help examples.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM
 * mapping (proves `down()` recreates the removed field definitions + the
 * select link cleanly and `up()` is idempotent on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629150730RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFieldCatalogCleanupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629150730');
    }
}
