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
 * Per-migration round-trip for the communication-preferences + Docker
 * scheduled-job runner schema (`Version20260605081254`):
 *
 *   - `users.receives_notifications` / `users.receives_emails` columns;
 *   - `scheduled_jobs.date_started`;
 *   - the `scheduled_job_recipients` table;
 *   - the `scheduled_job_runner_settings` + `scheduled_job_runner_runs` tables;
 *   - skipped status / transaction-type / delivery-policy lookups;
 *   - the `admin.scheduled_job.manage` permission;
 *   - the runner admin API routes + permission links.
 *
 * A broken down() would leave orphaned columns/tables/lookups/routes, so the
 * up()/down() pair must be reversible. Release-tier (`#[Group('migration')]`):
 * slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260605081254RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRunnerAndCommunicationPreferencesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260605081254');
    }
}
