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
 * Per-migration round-trip for the SH1 cleanup (`Version20261112163406`):
 * `libraries`, `rel_sections_navigation`, `users.intern` and the `hookTypes`
 * lookup rows are removed. Reverting must bring the structure back and
 * re-applying must leave the schema in sync with the ORM mapping (no `Library`
 * or `SectionsNavigation` entity, no `User::$intern`).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261112163406RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSh1CleanupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261112163406');
    }
}
