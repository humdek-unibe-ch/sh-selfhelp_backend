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
 * It is data-only (idempotent `INSERT IGNORE` up, scoped `DELETE` down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
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
