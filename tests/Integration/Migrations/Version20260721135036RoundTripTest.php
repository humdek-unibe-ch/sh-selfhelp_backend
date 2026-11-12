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
 * Per-migration round-trip for the admin scheduled-jobs stats route
 * (`Version20260721135036`: adds the `admin_scheduled_jobs_stats` api_routes
 * row and links it to `admin.scheduled_job.read`).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260721135036RoundTripTest extends MigrationRoundTripTestCase
{
    public function testScheduledJobsStatsRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260721135036');
    }
}
