<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Permission;
use App\Entity\Role;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rule 4.6f + C2 + C3 + C4: a scoped role manager may only create/change/delete
 * roles whose every permission and grant they hold, before and after; implied
 * reads are added on write; list items carry per-caller can_update/can_delete.
 */
#[TestGroup('security')]
final class AdminRoleHoldScopeTest extends QaWebTestCase
{
    private const SCOPED_EMAIL = 'qa.role.hold.scoped@selfhelp.test';
    private const ROLES_URI = '/cms-api/v1/admin/roles';
    private const PERMS_URI = '/cms-api/v1/admin/permissions';
    private const DATA_ACCESS_URI = '/cms-api/v1/admin/data-access/roles';

    private int $scopedRoleId;
    private int $heldRoleId;
    private int $aboveRoleId;
    private int $groupGId;
    private int $pagePId;
    private int $groupReadId;
    private int $groupAclId;
    private int $groupUpdateId;
    private int $impersonateId;
    private int $pagesResourceTypeId;
    private int $groupResourceTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $dataAccess = new RoleDataAccessFactory(
            $em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class)
        );
        $groups = new GroupFactory($em);
        $pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $groupG = $groups->createGroup('qa_role_hold_g');
        $pageP = $pages->createPage('qa_role_hold_p', openAccess: false);

        $scoped = $roles->createRole('qa_role_hold_scoped');
        foreach ([
            'admin.access',
            'admin.role.read',
            'admin.role.create',
            'admin.role.update',
            'admin.role.delete',
            'admin.role.permissions',
            'admin.permission.read',
            'admin.group.read',
            'admin.group.update',
        ] as $permission) {
            $roles->grantPermission($scoped, $permission);
        }

        $readUpdate = DataAccessSecurityService::PERMISSION_READ
            | DataAccessSecurityService::PERMISSION_UPDATE;
        $dataAccess->grantGroupAccess($scoped, (int) $groupG->getId(), $readUpdate);

        $held = $roles->createRole('qa_role_hold_held');
        $roles->grantPermission($held, 'admin.group.read');

        $above = $roles->createRole('qa_role_hold_above');
        $roles->grantPermission($above, 'admin.group.read');
        $roles->grantPermission($above, 'admin.user.impersonate');

        $users->createUser(self::SCOPED_EMAIL, 'QA Role Hold Scoped', roles: [$scoped]);

        $this->scopedRoleId = (int) $scoped->getId();
        $this->heldRoleId = (int) $held->getId();
        $this->aboveRoleId = (int) $above->getId();
        $this->groupGId = (int) $groupG->getId();
        $this->pagePId = (int) $pageP->getId();
        $this->groupReadId = $this->permissionId('admin.group.read');
        $this->groupAclId = $this->permissionId('admin.group.acl');
        $this->groupUpdateId = $this->permissionId('admin.group.update');
        $this->impersonateId = $this->permissionId('admin.user.impersonate');
        $this->pagesResourceTypeId = (int) $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES
        );
        $this->groupResourceTypeId = (int) $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_GROUP
        );
    }

    // -- P2-A1 ---------------------------------------------------------------

    public function testScopedCallerMayCreateHeldPermissionButNotUnheld(): void
    {
        $token = $this->loginAs(self::SCOPED_EMAIL);

        $ok = $this->jsonRequest(
            'POST',
            self::ROLES_URI,
            [
                'name' => 'qa_role_hold_created_ok',
                'description' => 'held',
                'permission_ids' => [$this->groupReadId],
            ],
            $token
        );
        $created = $this->assertEnvelopeSuccess($ok, 201);
        self::assertSame('qa_role_hold_created_ok', $created['name']);
        self::assertContains('admin.group.read', $this->permissionNamesOf($created));

        $denied = $this->jsonRequest(
            'POST',
            self::ROLES_URI,
            [
                'name' => 'qa_role_hold_created_bad',
                'permission_ids' => [$this->impersonateId],
            ],
            $token
        );
        $this->assertEnvelope403($denied);
        self::assertStringContainsString(
            'You can only give a role rights you hold yourself:',
            self::coerceString($denied['error'] ?? null)
        );
        self::assertStringContainsString('admin.user.impersonate', self::coerceString($denied['error'] ?? null));
        self::assertNull(
            $this->entityManager()->getRepository(Role::class)->findOneBy(['name' => 'qa_role_hold_created_bad'])
        );
    }

    // -- P2-A2 ---------------------------------------------------------------

    public function testScopedCallerCannotRaiseOwnRoleViaAnyPermissionPath(): void
    {
        $token = $this->loginAs(self::SCOPED_EMAIL);
        $before = $this->rolePermissionNames($this->scopedRoleId);

        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::ROLES_URI . '/' . $this->scopedRoleId . '/permissions',
            ['permission_ids' => [$this->impersonateId]],
            $token
        ));
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $this->scopedRoleId . '/permissions',
            ['permission_ids' => array_merge(
                $this->rolePermissionIds($this->scopedRoleId),
                [$this->impersonateId]
            )],
            $token
        ));
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $this->scopedRoleId,
            ['permission_ids' => array_merge(
                $this->rolePermissionIds($this->scopedRoleId),
                [$this->impersonateId]
            )],
            $token
        ));

        self::assertSame($before, $this->rolePermissionNames($this->scopedRoleId), 'Own role unchanged');
    }

    // -- P2-A3 ---------------------------------------------------------------

    public function testDeleteAndRenameRespectHoldingsAndSuperuserRole(): void
    {
        $token = $this->loginAs(self::SCOPED_EMAIL);

        $this->assertEnvelope403($this->jsonRequest(
            'DELETE',
            self::ROLES_URI . '/' . $this->aboveRoleId,
            null,
            $token
        ));
        self::assertNotNull($this->entityManager()->getRepository(Role::class)->find($this->aboveRoleId));

        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $this->aboveRoleId,
            ['name' => 'qa_role_hold_above_renamed'],
            $token
        ));

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $this->heldRoleId,
            ['name' => 'qa_role_hold_held_renamed'],
            $token
        ));
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            self::ROLES_URI . '/' . $this->heldRoleId,
            null,
            $token
        ));
        self::assertNull($this->entityManager()->getRepository(Role::class)->find($this->heldRoleId));

        $adminRole = $this->entityManager()->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $adminRole);
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $adminRole->getId(),
            ['description' => 'qa_should_not_change'],
            $token
        ));
    }

    // -- P2-A4 ---------------------------------------------------------------

    public function testGrantReplaceRequiresHeldBitsAndRoleUpdatePermission(): void
    {
        $token = $this->loginAs(self::SCOPED_EMAIL);

        $deniedPage = $this->jsonRequest(
            'POST',
            self::DATA_ACCESS_URI . '/' . $this->heldRoleId . '/permissions',
            ['permissions' => [[
                'resource_type_id' => $this->pagesResourceTypeId,
                'resource_id' => $this->pagePId,
                'crud_permissions' => DataAccessSecurityService::PERMISSION_UPDATE,
            ]]],
            $token
        );
        $this->assertEnvelope403($deniedPage);
        self::assertStringContainsString(
            'You can only give a role rights you hold yourself:',
            self::coerceString($deniedPage['error'] ?? null)
        );

        $ok = $this->jsonRequest(
            'POST',
            self::DATA_ACCESS_URI . '/' . $this->heldRoleId . '/permissions',
            ['permissions' => [[
                'resource_type_id' => $this->groupResourceTypeId,
                'resource_id' => $this->groupGId,
                'crud_permissions' => DataAccessSecurityService::PERMISSION_UPDATE,
            ]]],
            $token
        );
        $this->assertEnvelopeSuccess($ok);

        // Caller with only admin.role.read: route must refuse grant writes (C1).
        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );
        $readOnly = $roles->createRole('qa_role_hold_readonly');
        $roles->grantPermission($readOnly, 'admin.access');
        $roles->grantPermission($readOnly, 'admin.role.read');
        $users->createUser('qa.role.hold.readonly@selfhelp.test', 'QA Role Hold ReadOnly', roles: [$readOnly]);

        $readToken = $this->loginAs('qa.role.hold.readonly@selfhelp.test');
        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::DATA_ACCESS_URI . '/' . $this->heldRoleId . '/permissions',
            ['permissions' => []],
            $readToken
        ));
    }

    // -- P2-A5 ---------------------------------------------------------------

    public function testPermissionIdsOnUpdateNeedAdminRolePermissions(): void
    {
        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $noPermsRight = $roles->createRole('qa_role_hold_no_perms_right');
        foreach ([
            'admin.access',
            'admin.role.read',
            'admin.role.update',
            'admin.group.read',
        ] as $permission) {
            $roles->grantPermission($noPermsRight, $permission);
        }
        $users->createUser('qa.role.hold.noperms@selfhelp.test', 'QA No Perms Right', roles: [$noPermsRight]);

        $target = $roles->createRole('qa_role_hold_noperms_target');
        $roles->grantPermission($target, 'admin.group.read');

        $token = $this->loginAs('qa.role.hold.noperms@selfhelp.test');

        $denied = $this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $target->getId(),
            ['permission_ids' => [$this->groupReadId]],
            $token
        );
        $this->assertEnvelope403($denied);
        self::assertSame(
            'Changing a role\'s permissions needs admin.role.permissions.',
            $denied['error']
        );

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $target->getId(),
            ['description' => 'name-only style update'],
            $token
        ));
    }

    // -- P2-A6 ---------------------------------------------------------------

    public function testImpliedReadAddedAndRemovingReadClearsArea(): void
    {
        $token = $this->loginAs(self::SCOPED_EMAIL);

        // Scoped does not hold admin.group.acl — grant it first via admin so we
        // can prove C2 on a role the scoped caller fully holds after we also
        // give the scoped role acl temporarily... Better: use admin to set up
        // a held role with only group.read, then as admin add acl via replace
        // while logged in as admin for the C2 shape; then as scoped prove
        // remove-read cascade on a role they fully hold.
        //
        // Simpler: run C2 as qa.admin (superuser) on a fresh role — C2 is
        // independent of holdings — then prove remove-read cascade the same way.
        $adminToken = $this->loginAsQaAdmin();

        $created = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ROLES_URI,
            [
                'name' => 'qa_role_hold_c2',
                'permission_ids' => [$this->groupAclId],
            ],
            $adminToken
        ), 201);
        $roleId = self::coerceInt($created['id'] ?? null);
        $names = $this->rolePermissionNames($roleId);
        self::assertContains('admin.group.acl', $names);
        self::assertContains('admin.group.read', $names, 'C2: adding acl implies read');

        // Replace with acl only (no read in request) still ends with both.
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->groupAclId]],
            $adminToken
        ));
        $names = $this->rolePermissionNames($roleId);
        self::assertContains('admin.group.acl', $names);
        self::assertContains('admin.group.read', $names);

        // Also add update so remove-read can clear the area.
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->groupUpdateId]],
            $adminToken
        ));

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->groupReadId]],
            $adminToken
        ));
        $names = $this->rolePermissionNames($roleId);
        self::assertNotContains('admin.group.read', $names);
        self::assertNotContains('admin.group.acl', $names);
        self::assertNotContains('admin.group.update', $names);

        // Keep $token referenced so scoped session stays valid for later tests in the class.
        unset($token);
    }

    // -- P2-A7 ---------------------------------------------------------------

    public function testCallerFlagsDifferPerCallerOnCachedRoleList(): void
    {
        $scopedToken = $this->loginAs(self::SCOPED_EMAIL);
        $adminToken = $this->loginAsQaAdmin();

        // Warm the shared cache as admin first, then read as scoped.
        // Large pageSize so qa_ roles are not clipped off page 1.
        $listUri = self::ROLES_URI . '?pageSize=100';
        $adminList = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $listUri, null, $adminToken));
        $scopedList = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $listUri, null, $scopedToken));

        $adminAbove = $this->findRoleInList($adminList, $this->aboveRoleId);
        $scopedAbove = $this->findRoleInList($scopedList, $this->aboveRoleId);
        self::assertTrue((bool) $adminAbove['can_update']);
        self::assertTrue((bool) $adminAbove['can_delete'], 'superuser may delete a role with no users');
        self::assertFalse((bool) $scopedAbove['can_update']);
        self::assertFalse((bool) $scopedAbove['can_delete']);

        $scopedHeld = $this->findRoleInList($scopedList, $this->heldRoleId);
        self::assertTrue($scopedHeld['can_update']);
        self::assertTrue($scopedHeld['can_delete']);

        $adminRole = $this->entityManager()->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $adminRole);
        $adminSuper = $this->findRoleInList($adminList, (int) $adminRole->getId());
        self::assertTrue($adminSuper['can_update']);
        self::assertFalse($adminSuper['can_delete']);

        $perms = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::PERMS_URI, null, $scopedToken));
        $items = $this->asList($perms['permissions'] ?? null);
        self::assertNotEmpty($items);
        $byName = [];
        foreach ($items as $item) {
            $row = $this->asArray($item);
            $byName[self::coerceString($row['name'] ?? null)] = $row;
            self::assertArrayHasKey('can_assign', $row);
            self::assertArrayHasKey('implied_read', $row);
        }
        self::assertTrue($byName['admin.group.read']['can_assign']);
        self::assertFalse($byName['admin.user.impersonate']['can_assign']);
        self::assertSame('admin.group.read', $byName['admin.group.acl']['implied_read']);
        self::assertNull($byName['admin.group.read']['implied_read']);
    }

    // -- helpers -------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function permissionId(string $name): int
    {
        $permission = $this->entityManager()->getRepository(Permission::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Permission::class, $permission, 'Missing permission ' . $name);

        return (int) $permission->getId();
    }

    /**
     * @param array<string, mixed> $role
     * @return list<string>
     */
    private function permissionNamesOf(array $role): array
    {
        $names = [];
        foreach ($this->asList($role['permissions'] ?? null) as $item) {
            $names[] = self::coerceString($this->asArray($item)['name'] ?? null);
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function rolePermissionNames(int $roleId): array
    {
        $em = $this->entityManager();
        $em->clear();
        $role = $em->getRepository(Role::class)->find($roleId);
        self::assertInstanceOf(Role::class, $role);
        $names = [];
        foreach ($role->getPermissions() as $permission) {
            $names[] = $permission->getName();
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<int>
     */
    private function rolePermissionIds(int $roleId): array
    {
        $em = $this->entityManager();
        $em->clear();
        $role = $em->getRepository(Role::class)->find($roleId);
        self::assertInstanceOf(Role::class, $role);
        $ids = [];
        foreach ($role->getPermissions() as $permission) {
            $ids[] = (int) $permission->getId();
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $listPayload
     * @return array<string, mixed>
     */
    private function findRoleInList(array $listPayload, int $roleId): array
    {
        foreach ($this->asList($listPayload['roles'] ?? null) as $item) {
            $role = $this->asArray($item);
            if (self::coerceInt($role['id'] ?? null) === $roleId) {
                return $role;
            }
        }
        self::fail('Role ' . $roleId . ' not found in list');
    }
}
