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
 * Per-migration round-trip for the request-log index
 * (`Version20261111104125`): `api_request_logs.id_users` gets an index so that
 * removing one user does not lock the whole request log. `down()` drops it, so
 * reverting and re-applying must leave the schema in sync with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261111104125RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRequestLogIndexMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261111104125');
    }
}
