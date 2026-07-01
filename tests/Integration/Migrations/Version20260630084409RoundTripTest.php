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
 * Per-migration round-trip for the open-access `GET /pages/resolve` api_route
 * seed (`Version20260630084409`, issue #30): the DB-driven public path-resolution
 * endpoint (no `rel_api_routes_permissions` row, exactly like
 * `pages_get_by_keyword`).
 *
 * It is data-only (idempotent `INSERT IGNORE` up, scoped `DELETE` down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630084409RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPagesResolveApiRouteSeedRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630084409');
    }
}
