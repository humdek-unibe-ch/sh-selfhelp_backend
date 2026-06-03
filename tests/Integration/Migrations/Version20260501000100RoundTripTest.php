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
 * Per-migration round-trip for the reference-data seed
 * (`Version20260501000100`: lookups, languages, field_types, style_groups,
 * permissions, roles, page_types).
 *
 * This is the foundational catalogue every later seed (fields/styles, pages,
 * api_routes) and the QA fixture depend on, so its down()/up() must be exactly
 * reversible. Release-tier (`#[Group('migration')]`): slow + needs CREATE
 * DATABASE.
 */
#[Group('migration')]
final class Version20260501000100RoundTripTest extends MigrationRoundTripTestCase
{
    public function testReferenceDataSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260501000100');
    }
}
