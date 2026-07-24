<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Group;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Permission matrix for the group-scoped asset-folder ACL routes
 * (`/admin/groups/{groupId}/asset-acls`), which are gated by `admin.group.acl`
 * (the same permission as the sibling page-ACL routes).
 *
 * The read route asserts the full admin-only matrix; the write route asserts
 * only the negative half so the matrix never mutates real ACL data.
 */
#[TestGroup('security')]
final class AdminGroupAssetAclPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGroupAssetAclsReadEnforcesAdminOnlyMatrix(): void
    {
        $groupId = $this->adminGroupId();
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/groups/' . $groupId . '/asset-acls');
    }

    public function testGroupAssetAclsUpdateIsForbiddenForNonAdmins(): void
    {
        $groupId = $this->adminGroupId();
        $this->assertForbiddenForNonAdmins(
            'PUT',
            '/cms-api/v1/admin/groups/' . $groupId . '/asset-acls',
            ['acls' => []]
        );
    }

    private function adminGroupId(): int
    {
        $em = $this->service(EntityManagerInterface::class);
        $group = $em->getRepository(Group::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Group::class, $group, 'Seeded "admin" group is required for this test');

        return (int) $group->getId();
    }
}
