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
 * Per-migration round-trip for the admin page export/import api_routes
 * (`Version20260630093155`, issue #30 Phase 5): seeds the four endpoints that
 * power the portable page-bundle flow (`POST /admin/pages/export`,
 * `GET /admin/pages/{page_id}/export/suggest`, `POST /admin/pages/import/validate`,
 * `POST /admin/pages/import`) plus their permission links.
 *
 * It is data-only (idempotent `INSERT IGNORE` up, scoped `DELETE` down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630093155RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPageExportImportApiRoutesRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630093155');
    }
}
