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
 * Per-migration round-trip for the password-changed confirmation mail fields
 * (`Version20260608083126`: adds `mail_password_changed_subject`/`_body` to the
 * sh-mail-config page type and seeds en-GB/de-CH defaults).
 *
 * A broken down() would orphan the fields / translations or block re-running
 * up(). The round-trip proves up -> down -> up leaves the schema in sync with
 * the ORM. Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608083126RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPasswordChangedMailFieldsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608083126');
    }
}
