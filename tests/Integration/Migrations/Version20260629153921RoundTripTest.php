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
 * Per-migration round-trip for the rich-content field retype
 * (`Version20260629153921`): switches the `text` and `blockquote_content` fields
 * from `markdown-inline` to `textarea`.
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (this is a data-only `fields.id_field_types` change, so `down()` must restore
 * the original type and `up()` must stay idempotent on re-apply).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629153921RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRichContentFieldRetypeMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629153921');
    }
}
