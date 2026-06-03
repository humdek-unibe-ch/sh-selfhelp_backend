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
 * Per-migration round-trip for the users.last_login column-type change
 * (`Version20260521123813`: DATE -> DATETIME to match the Doctrine
 * datetime_immutable mapping).
 *
 * This is the canonical "schema must match the ORM mapping" migration, so the
 * round-trip's `doctrine:schema:validate` is exactly the assertion that matters
 * — reverting and re-applying must leave `users.last_login` in sync with the
 * entity. Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260521123813RoundTripTest extends MigrationRoundTripTestCase
{
    public function testUsersLastLoginColumnTypeMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260521123813');
    }
}
