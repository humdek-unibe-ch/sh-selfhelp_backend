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
 * Per-migration round-trip for `Version20260619092612` (style field cleanup
 * slice 2: rename web_color -> shared_color, fix web_checkbox_labelPosition ->
 * web_checkbox_label_position, remove the image web_image_src/web_image_alt
 * duplicates).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply without error and leave the ORM-mapped schema in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260619092612RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleFieldCleanupSlice2RoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260619092612');
    }
}
