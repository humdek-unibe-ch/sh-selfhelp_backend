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
 * Permission matrix for GET /admin/scheduled-jobs/stats (plan §26 / §29).
 *
 * The route is linked to admin.scheduled_job.read (the same permission as the
 * list endpoint). The QA baseline grants the admin role only to qa.admin, so the
 * matrix is: qa.admin allowed (200), qa.editor/qa.user/qa.guest forbidden (403),
 * anonymous unauthorized (401). Its success path is a non-mutating GET, so the
 * full {@see assertAdminOnlyMatrix} applies.
 */
#[Group('security')]
final class AdminScheduledJobStatsPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const URI = '/cms-api/v1/admin/scheduled-jobs/stats';

    public function testStatsEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::URI);
    }
}
