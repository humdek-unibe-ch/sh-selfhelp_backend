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
 * Per-migration round-trip for `Version20260619093723` (style field cleanup
 * slice 3: promote web_button_variant -> shared_variant (RF-14) and un-prefix
 * every translatable web_* content field (RF-35)).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply without error and leave the ORM-mapped schema in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260619093723RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleFieldCleanupSlice3RoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260619093723');
    }
}
