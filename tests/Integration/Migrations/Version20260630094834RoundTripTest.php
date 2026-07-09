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
 * Per-migration round-trip for the "Create list + detail pages" wizard api_route
 * (`Version20260630094834`, issue #30 Phase 6): seeds
 * `POST /admin/pages/cms-app` -> `AdminPageController::createCmsApp`, gated by
 * `admin.page.create`.
 *
 * **Note:** `Version20260706221100` removes this route in favour of first-class
 * `/admin/cms-apps*`. This test only proves the *historical* migration is still
 * reversible on a fresh database chain; it does not assert the legacy route
 * remains registered after the full migration head is applied.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630094834RoundTripTest extends MigrationRoundTripTestCase
{
    public function testCmsAppWizardApiRouteRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630094834');
    }
}
