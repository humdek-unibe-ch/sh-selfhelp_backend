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
 * Per-migration round-trip for `Version20260618143216` (re-prefix CMS style
 * fields into the `shared_*` / `web_*` taxonomy + neutral spacing field types).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply without altering the schema snapshot. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260618143216RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFieldPrefixRenameRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260618143216');
    }
}
