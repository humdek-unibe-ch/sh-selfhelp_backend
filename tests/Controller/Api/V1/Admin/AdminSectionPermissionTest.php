<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Permission matrix for the admin sections API (Slice 3 / plan §29).
 *
 * Section routes are nested under a page id and have no top-level list, so every
 * route asserts the negative half only (non-admins 403, anonymous 401). Route
 * permission is enforced before the page/section is looked up, so non-existent
 * ids are safe and never mutate data. The admin-allowed section CRUD path is
 * covered by PageVersioningWorkflowTest.
 *
 * P1-A5 (role-scope): unused-sections GET opens with `admin.section.read`.
 */
#[Group('security')]
final class AdminSectionPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const SECTION_READ_EMAIL = 'qa.section.read@selfhelp.test';

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

    public function testSectionRemoveFromPageIsForbiddenForNonAdmins(): void
    {
        // DELETE on the page-scoped path detaches the section from the page.
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/pages/2147483600/sections/2147483601');
    }

    public function testSectionDestroyIsForbiddenForNonAdmins(): void
    {
        // Page-independent permanent delete.
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/sections/2147483601');
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

    public function testUnusedSectionsReadPermissionAllowsGet(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );
        $role = $roles->createRole('qa_section_read_role');
        $roles->grantPermission($role, 'admin.access');
        $roles->grantPermission($role, 'admin.section.read');
        $users->createUser(self::SECTION_READ_EMAIL, 'QA Section Read', roles: [$role]);

        $token = $this->loginAs(self::SECTION_READ_EMAIL);
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/sections/unused', null, $token)
        );
    }
}
