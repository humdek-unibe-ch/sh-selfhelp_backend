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
 * Per-migration round-trip for the group/role "view members/users" route seed
 * (`Version20260720132523`), which inserts the
 * GET /admin/groups/{id}/users and GET /admin/roles/{id}/users rows into
 * `api_routes` and links them to admin.group.read / admin.role.read so
 * ApiSecurityListener enforces them.
 *
 * `down()` removes both routes and their permission links, so reverting and
 * re-applying must leave the schema in sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260720132523RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMemberRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260720132523');
    }
}
