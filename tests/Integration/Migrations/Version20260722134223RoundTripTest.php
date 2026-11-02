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
 * Per-migration round-trip for the asset-ACL re-scope migration
 * (`Version20260722134223`: swaps the folder-centric `/admin/assets/folder-acls`
 * routes for the group-centric `/admin/groups/{groupId}/asset-acls` routes).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260722134223RoundTripTest extends MigrationRoundTripTestCase
{
    public function testGroupAssetAclRouteRescopeMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260722134223');
    }
}
