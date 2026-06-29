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
 * Round-trip for the field-type cleanup that retypes overloaded `textarea` /
 * `text` fields to json / code / textarea for the type-driven editor mapping.
 * It is a data-only, scoped, idempotent metadata change; the chain must migrate
 * up, revert, re-apply, and keep the ORM schema in sync.
 */
#[Group('migration')]
final class Version20260629143116RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFieldTypeCleanupRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629143116');
    }
}
