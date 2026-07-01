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
 * Per-migration round-trip for the web page-icon picker fix
 * (`Version20260630135801`): switches the existing `icon` page property field to
 * the `select-icon` (Tabler picker) field type and rewrites its inspector copy.
 *
 * It is data-only (idempotent `UPDATE` up/down by field name), so the round-trip
 * proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630135801RoundTripTest extends MigrationRoundTripTestCase
{
    public function testWebIconPickerFieldTypeRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630135801');
    }
}
