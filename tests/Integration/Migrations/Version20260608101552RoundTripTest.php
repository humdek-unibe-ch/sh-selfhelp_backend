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
 * Per-migration round-trip for the dedicated password-reset token columns
 * (`Version20260608101552`: adds `users.password_reset_token` +
 * `users.password_reset_expires_at`, issue #32).
 *
 * A broken down() would leave the columns behind (or fail to re-add them) and
 * desync the ORM mapping. Release-tier (`#[Group('migration')]`): slow + needs
 * CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608101552RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPasswordResetTokenColumnsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608101552');
    }
}
