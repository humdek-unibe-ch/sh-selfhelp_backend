<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional coverage for the Docker scheduled-job runner admin API
 * ({@see \App\Controller\Api\V1\Admin\AdminScheduledJobRunnerController}, plan
 * Slice B6).
 *
 * Asserts the operational contract the admin UI consumes: a complete status
 * payload, a validated settings update (incl. the `interval_seconds >= 60`
 * guard), the enable/disable toggle, and a "run due jobs now" that returns both
 * the run summary and the refreshed status. Runs as the seeded qa.admin persona;
 * DAMA rolls back the settings/run-history rows after each test.
 */
#[Group('security')]
final class AdminScheduledJobRunnerControllerTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/scheduled-jobs/runner';

    public function testStatusReturnsOperationalPayload(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/status', null, $this->loginAsQaAdmin())
        );

        foreach (['settings', 'last_run', 'queue', 'health'] as $key) {
            self::assertArrayHasKey($key, $data, "Runner status must expose '{$key}'.");
        }
        self::assertIsArray($data['settings']);
        self::assertArrayHasKey('enabled', $data['settings']);
        self::assertArrayHasKey('interval_seconds', $data['settings']);
        self::assertIsArray($data['queue']);
        self::assertArrayHasKey('due_queued_count', $data['queue']);
        self::assertIsArray($data['health']);
        self::assertArrayHasKey('scheduler_appears_stale', $data['health']);
    }

    public function testUpdateSettingsPersistsAndReturnsStatus(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . '/settings', [
                'enabled' => true,
                'interval_seconds' => 120,
                'max_jobs_per_run' => 25,
                'lock_ttl_seconds' => 180,
                'stale_running_after_seconds' => 600,
                'retention_max_runs' => 1000,
            ], $this->loginAsQaAdmin())
        );

        self::assertIsArray($data['settings']);
        self::assertSame(120, $data['settings']['interval_seconds']);
        self::assertSame(25, $data['settings']['max_jobs_per_run']);
        self::assertSame(180, $data['settings']['lock_ttl_seconds']);
        self::assertSame(1000, $data['settings']['retention_max_runs'], 'Issue #34: retention is a persisted runner setting.');
        self::assertTrue($data['settings']['enabled']);
    }

    public function testUpdateSettingsRejectsTooSmallInterval(): void
    {
        // interval_seconds minimum is 60 (scheduler mode).
        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::BASE . '/settings', ['interval_seconds' => 5], $this->loginAsQaAdmin())
        );
    }

    public function testEnableDisableTogglesEnabledFlag(): void
    {
        $token = $this->loginAsQaAdmin();

        $disabled = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . '/disable', null, $token)
        );
        self::assertIsArray($disabled['settings']);
        self::assertFalse($disabled['settings']['enabled'], 'Disable must turn the runner off.');

        $enabled = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . '/enable', null, $token)
        );
        self::assertIsArray($enabled['settings']);
        self::assertTrue($enabled['settings']['enabled'], 'Enable must turn the runner back on.');
    }

    public function testRunNowReturnsRunSummaryAndStatus(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . '/run-now', null, $this->loginAsQaAdmin())
        );

        self::assertArrayHasKey('run', $data, 'run-now must return the run summary.');
        self::assertArrayHasKey('status', $data, 'run-now must return the refreshed status.');
        self::assertIsArray($data['run']);
        self::assertArrayHasKey('status', $data['run']);
        self::assertArrayHasKey('due_count', $data['run']);
    }
}
