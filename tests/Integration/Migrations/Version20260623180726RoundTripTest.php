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
 * Per-migration round-trip for the mobile-preview-update support migration
 * (adds `system_update_operations.target_mobile_preview_version` + the three
 * `/admin/system/update/mobile-preview/*` routes and their permission links).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260623180726RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMobilePreviewUpdateSupportMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260623180726');
    }
}
