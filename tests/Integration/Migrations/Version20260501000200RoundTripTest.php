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
 * Per-migration round-trip for the fields/styles catalogue seed
 * (`Version20260501000200`: fields, styles, rel_fields_styles,
 * rel_styles_allowed_relationships, rel_fields_page_types).
 *
 * The CMS renderer and the admin style schema resolve against these rows, so a
 * non-reversible down() would leave dangling relation rows that break a
 * re-apply. Release-tier (`#[Group('migration')]`): slow + needs CREATE
 * DATABASE.
 */
#[Group('migration')]
final class Version20260501000200RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFieldsAndStylesCatalogMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260501000200');
    }
}
