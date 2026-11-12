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
 * Per-migration round-trip for the audit-trail foreign keys
 * (`Version20261111083203`): `transactions.id_users` and
 * `data_access_audits.id_users` become ON DELETE SET NULL (the audit column
 * nullable). `down()` restores the old constraints, so reverting and
 * re-applying must leave the schema in sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261111083203RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAuditTrailForeignKeyMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261111083203');
    }
}
