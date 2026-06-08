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
 * Per-migration round-trip for the reset-password set-password CMS fields
 * (`Version20260608075829`: adds the `resetPassword` set-mode fields, links
 * them to the style, and seeds en-GB/de-CH translations onto `reset-sys-form`).
 *
 * This is a data-seed migration with several dependent inserts; a broken
 * down() would orphan fields / translations or block re-running up(). The
 * round-trip proves up -> down -> up leaves the schema in sync with the ORM.
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608075829RoundTripTest extends MigrationRoundTripTestCase
{
    public function testResetPasswordSetModeFieldsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608075829');
    }
}
