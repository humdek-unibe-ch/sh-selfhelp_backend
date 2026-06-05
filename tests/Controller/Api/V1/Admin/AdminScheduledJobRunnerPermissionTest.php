<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission matrix for the Docker scheduled-job runner admin API (plan Slice B6).
 *
 * The read status route asserts the full matrix (admin allowed, non-admins 403,
 * anonymous 401). The mutating/manage routes assert only the negative half so
 * the matrix never changes runner settings or executes due jobs.
 */
#[Group('security')]
final class AdminScheduledJobRunnerPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/scheduled-jobs/runner';

    public function testRunnerStatusEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/status');
    }

    public function testRunnerSettingsIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('PUT', self::BASE . '/settings', ['interval_seconds' => 120]);
    }

    public function testRunnerEnableIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BASE . '/enable');
    }

    public function testRunnerDisableIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BASE . '/disable');
    }

    public function testRunnerRunNowIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BASE . '/run-now');
    }
}
