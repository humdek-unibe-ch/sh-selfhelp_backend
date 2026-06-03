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
 * Permission matrix for the CMS page admin API (Slice 3 / plan §29).
 *
 * Reference pattern for CMS CRUD permission coverage: read routes assert the
 * full matrix (admin allowed, non-admins 403, anonymous 401); write/destructive
 * routes assert only the negative half so the matrix test never mutates data.
 */
#[Group('security')]
final class AdminPagePermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testPageListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/pages');
    }

    public function testPageCreateIsForbiddenForNonAdmins(): void
    {
        // Negative-only: a qa_-prefixed body so that IF a non-admin were wrongly
        // allowed, the created page would still be qa-prefixed (cleanup-safe).
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/pages', [
            'keyword' => 'qa_perm_should_not_create',
            'url' => '/qa-perm-should-not-create',
        ]);
    }

    public function testPageDeleteIsForbiddenForNonAdmins(): void
    {
        // Uses a non-existent id: permission is enforced before existence, so
        // non-admins get 403 and anonymous gets 401 regardless of the id.
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/pages/2147483600');
    }
}
