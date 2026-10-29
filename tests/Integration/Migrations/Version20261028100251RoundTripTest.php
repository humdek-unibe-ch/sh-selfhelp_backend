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
 * Round-trip for `Version20261028100251`, which adds the `files_public`
 * checkbox to the `file-input` style. Release-tier (`#[Group('migration')]`).
 */
#[Group('migration')]
final class Version20261028100251RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFilesPublicFieldMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20261028100251');
    }
}
