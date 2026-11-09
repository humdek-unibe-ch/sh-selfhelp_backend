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
 * Permission matrix for the admin assets API (Slice 3 / plan §29).
 *
 * The list route asserts the full matrix; delete asserts only the negative half
 * (non-existent id) so the matrix test never removes a real asset. Asset upload
 * is multipart and is covered by the asset service tests, not this matrix.
 */
#[Group('security')]
final class AdminAssetPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testAssetsListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/assets');
    }

    public function testAssetDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/assets/2147483600');
    }

    public function testAssetEditIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/2147483600');
    }

    public function testAssetBulkGroupsIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/bulk-groups');
    }

    public function testAssetBulkMoveIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/bulk-folder');
    }

    public function testAssetBulkDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/bulk-delete');
    }

    public function testAssetFoldersListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/asset-folders');
    }

    public function testAssetFolderWritesAreForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/asset-folders');
        $this->assertForbiddenForNonAdmins('PUT', '/cms-api/v1/admin/asset-folders/2147483600');
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/asset-folders/2147483600');
    }
}
