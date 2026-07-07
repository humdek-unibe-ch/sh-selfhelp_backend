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
 * Per-migration round-trip for the analytics/branding/headless migration
 * (`Version20260706220759`: `page_views` + `page_view_referrers` aggregate
 * tables, the `admin.analytics.read` permission with the two admin analytics
 * API routes, `navigation_settings.logo_size` + `logo_variant`, and headless
 * `reset-password` / `validate` / `maintenance` system pages).
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706220759RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAnalyticsAndBrandingMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706220759');
    }
}
