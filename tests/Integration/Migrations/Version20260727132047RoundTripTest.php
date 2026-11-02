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
 * Per-migration round-trip for the `imported` userStatus lookup
 * (`Version20260727132047`). A broken down() would leave an orphaned lookup
 * row, so the up()/down() pair must be reversible. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260727132047RoundTripTest extends MigrationRoundTripTestCase
{
    public function testImportedUserStatusLookupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260727132047');
    }
}
