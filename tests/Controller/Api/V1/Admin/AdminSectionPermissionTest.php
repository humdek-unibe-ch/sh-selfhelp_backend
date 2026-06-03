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
 * Permission matrix for the admin sections API (Slice 3 / plan §29).
 *
 * Section routes are nested under a page id and have no top-level list, so every
 * route asserts the negative half only (non-admins 403, anonymous 401). Route
 * permission is enforced before the page/section is looked up, so non-existent
 * ids are safe and never mutate data. The admin-allowed section CRUD path is
 * covered by PageVersioningWorkflowTest.
 */
#[Group('security')]
final class AdminSectionPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testSectionReadIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('GET', '/cms-api/v1/admin/pages/2147483600/sections/2147483601');
    }

    public function testSectionCreateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/pages/2147483600/sections/create', [
            'styleId' => 1,
            'position' => 0,
        ]);
    }

    public function testSectionDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/pages/2147483600/sections/2147483601');
    }

    public function testSectionForceDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins(
            'DELETE',
            '/cms-api/v1/admin/pages/2147483600/sections/2147483601/force-delete',
        );
    }

    public function testSectionRestoreFromVersionIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins(
            'POST',
            '/cms-api/v1/admin/pages/2147483600/sections/restore-from-version/2147483601',
        );
    }

    public function testBulkRemoveSectionsIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins(
            'DELETE',
            '/cms-api/v1/admin/pages/2147483600/sections',
            ['sectionIds' => [2147483601]],
        );
    }
}
