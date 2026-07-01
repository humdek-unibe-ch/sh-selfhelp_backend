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
 * Per-migration round-trip for the modal-size editor-type re-point
 * (`Version20260630181203`): adds the `select-modal-size` field type and
 * re-points `modal_width` + `modal_height` to it (down reverts to `text` and
 * drops the type).
 *
 * Data-only catalog change, so the round-trip proves the schema stays
 * ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630181203RoundTripTest extends MigrationRoundTripTestCase
{
    public function testModalSizeEditorTypeRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630181203');
    }
}
