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
 * Per-migration round-trip for the `version` style heading fields
 * (`Version20261112164930`): `title` plus the new `label_frameworks`,
 * `label_plugins`, `label_libraries`, `label_web` and `label_app` fields are
 * linked to the style. Reverting must remove the five new fields and the
 * style's links; re-applying must leave the schema in sync with the mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261112164930RoundTripTest extends MigrationRoundTripTestCase
{
    public function testVersionStyleFieldsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261112164930');
    }
}
