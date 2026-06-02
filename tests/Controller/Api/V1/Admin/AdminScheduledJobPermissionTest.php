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
 * Permission matrix for the admin scheduled-jobs API (Slice 3 / plan §29).
 *
 * The list route asserts the full matrix; execute/delete assert only the negative
 * half (non-existent id) so the matrix test never runs or removes a real job. The
 * admin-allowed execute path is covered by FormActionJobChainTest.
 */
#[Group('security')]
final class AdminScheduledJobPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testScheduledJobsListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/scheduled-jobs');
    }

    public function testScheduledJobExecuteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/scheduled-jobs/2147483600/execute');
    }

    public function testScheduledJobDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/scheduled-jobs/2147483600');
    }

    public function testScheduledJobCancelIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/scheduled-jobs/2147483600/cancel');
    }
}
