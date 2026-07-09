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
 * Per-migration round-trip for first-class `cms_apps`
 * (`Version20260706221100`: table + page assignment columns, `admin.cms_app.*`
 * permissions/routes, removal of legacy `POST /admin/pages/cms-app`).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706221100RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFirstClassCmsAppsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706221100');
    }
}
