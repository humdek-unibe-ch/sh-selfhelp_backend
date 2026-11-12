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

    /**
     * A10: applying access to a folder needs `admin.asset.update`: the admin
     * role gets 200, editor, user and guest personas 403, anonymous 401.
     */
    public function testAssetFolderAccessEnforcesTheUpdatePermission(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/asset-folders/2147483600/access', ['group_ids' => []]);

        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => 'qa-permission-folder'], $admin);
        $folderId = null;
        foreach ($this->asList($created['data'] ?? null) as $row) {
            $row = $this->asArray($row);
            if ($row['name'] === 'qa-permission-folder') {
                $folderId = $this->asInt($row['id']);
            }
        }
        self::assertNotNull($folderId);

        $result = $this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $folderId . '/access', ['group_ids' => []], $admin);
        self::assertSame(['updated' => 0], $this->assertEnvelopeSuccess($result));
    }

    /**
     * Export returns a ZIP and import takes a multipart upload, so only the
     * negative half is asserted here; the admin round trip is covered by
     * AdminAssetControllerTest.
     */
    public function testAssetBundleExportAndImportAreForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/export', []);
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/assets/import');
    }
}
