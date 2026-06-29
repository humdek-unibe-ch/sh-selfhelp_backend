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
 * Per-migration round-trip for the section data-variables endpoint migration
 * (`Version20260629063147`): register the
 * `GET /admin/sections/{section_id}/data-variables` route under the existing
 * `admin.page.read` permission so the interpolation picker can refresh without a
 * full section reload.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` removes the route + permission link cleanly and `up()` is
 * idempotent on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629063147RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSectionDataVariablesRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629063147');
    }
}
