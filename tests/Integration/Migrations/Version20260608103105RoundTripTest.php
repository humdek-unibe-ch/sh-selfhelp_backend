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
 * Per-migration round-trip for the scheduled-job runner audit retention column
 * (`Version20260608103105`: adds `scheduled_job_runner_settings.retention_max_runs`,
 * issue #34).
 *
 * A broken down()/up() would leave the column behind (or fail to re-add it) and
 * desync the ORM mapping. Release-tier (`#[Group('migration')]`): slow + needs
 * CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608103105RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRunnerRetentionColumnMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608103105');
    }
}
