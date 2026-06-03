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
 * Permission matrix for the admin users API (Slice 3 / plan §29).
 *
 * Read list route asserts the full matrix (admin allowed, non-admins 403,
 * anonymous 401); write/destructive routes assert only the negative half so the
 * matrix test never mutates a real user. Destructive routes use a non-existent
 * id because permission is enforced before existence (403/401 regardless).
 */
#[Group('security')]
final class AdminUserPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testUsersListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/users');
    }

    public function testUserCreateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/users', [
            'email' => 'qa.perm-should-not-create@selfhelp.test',
            'name' => 'qa_perm_should_not_create',
        ]);
    }

    public function testUserDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/users/2147483600');
    }
}
