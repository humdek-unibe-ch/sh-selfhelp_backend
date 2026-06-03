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
 * Per-migration round-trip for the system-pages seed
 * (`Version20260501000400`: system pages, sections, page field translations,
 * page ACLs).
 *
 * Page ACL rows gate frontend access, so a broken down()/up() here is a
 * security-relevant schema-state regression. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260501000400RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSystemPagesAndAclSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260501000400');
    }
}
