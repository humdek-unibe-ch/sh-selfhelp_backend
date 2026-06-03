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
 * Permission matrix for the admin groups API (Slice 3 / plan §29).
 *
 * The list route asserts the full matrix; create/delete assert only the negative
 * half (qa_-prefixed body, non-existent id) so the matrix test never mutates a
 * real group.
 */
#[Group('security')]
final class AdminGroupPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGroupsListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/groups');
    }

    public function testGroupCreateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/groups', [
            'name' => 'qa_perm_should_not_create',
            'description' => 'qa permission matrix probe',
        ]);
    }

    public function testGroupDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/groups/2147483600');
    }
}
