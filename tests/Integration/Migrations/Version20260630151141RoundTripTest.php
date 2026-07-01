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
 * Per-migration round-trip for the CMS-in-CMS modal UX field seeds
 * (`Version20260630151141`): the `open_in_modal` page property
 * (core+experiment), the `close_modal_on_save` / `redirect_on_save` form-style
 * fields, and the `add_url` / `edit_url` show-user-input fields.
 *
 * Data-only field seeds (FK-safe create/link up, FK-safe drop down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630151141RoundTripTest extends MigrationRoundTripTestCase
{
    public function testOpenInModalAndFormSaveFieldsRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630151141');
    }
}
