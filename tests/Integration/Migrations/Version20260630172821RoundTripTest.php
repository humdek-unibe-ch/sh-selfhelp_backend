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
 * Per-migration round-trip for the CMS-in-CMS modal-size field seeds
 * (`Version20260630172821`): the `modal_width` + `modal_height` page PROPERTY
 * fields linked to the navigable content page types (core+experiment).
 *
 * Data-only field seeds (FK-safe create/link up, FK-safe drop down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630172821RoundTripTest extends MigrationRoundTripTestCase
{
    public function testModalSizePagePropertiesRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630172821');
    }
}
