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
 * Per-migration round-trip for `Version20260619094535` (style field cleanup
 * slice 4: seed the profile timezone-change fields (RF-22) and link
 * two-factor-auth title/label_submit/label_code (RF-23)).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply without error (create + link, then delete + unlink) and leave the
 * ORM-mapped schema in sync. Release-tier (`#[Group('migration')]`): needs
 * CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260619094535RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleFieldReconciliationSlice4RoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260619094535');
    }
}
