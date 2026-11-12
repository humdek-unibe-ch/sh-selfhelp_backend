<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Group;
use App\Entity\Role;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A scoped admin (a non-admin role that may manage users) must not hand out
 * more than it has: moving people in or out of a group needs the right to update
 * THAT group, and handing out a role needs holding that role. The admin role
 * passes every check.
 *
 * Setup: the manager holds `qa_scope_manager_role` (route permissions to read,
 * create and update users; read/update/create on groups `qa_scope_in` and
 * `qa_scope_in2` only). The target user is in `qa_scope_in` and `qa_scope_out`
 * (no right on it) and holds `qa_scope_other_role`, which the manager does NOT hold.
 */
#[TestGroup('security')]
final class AdminUserAssignmentScopeTest extends QaWebTestCase
{
    private const MANAGER_EMAIL = 'qa.scope.manager@selfhelp.test';
    private const TARGET_EMAIL = 'qa.scope.target@selfhelp.test';
    private const USERS_URI = '/cms-api/v1/admin/users';

    private int $managerId;
    private int $targetId;
    private int $inGroupId;
    private int $inGroup2Id;
    private int $outGroupId;
    private int $out2GroupId;
    private int $managerRoleId;
    private int $otherRoleId;
    private int $adminRoleId;

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
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $inGroup = $groups->createGroup('qa_scope_in');
        $inGroup2 = $groups->createGroup('qa_scope_in2');
        $outGroup = $groups->createGroup('qa_scope_out');
        $out2Group = $groups->createGroup('qa_scope_out2');

        $managerRole = $roles->createRole('qa_scope_manager_role');
        foreach (['admin.user.read', 'admin.user.create', 'admin.user.update'] as $permission) {
            $roles->grantPermission($managerRole, $permission);
        }
        $crud = DataAccessSecurityService::PERMISSION_CREATE
            | DataAccessSecurityService::PERMISSION_READ
            | DataAccessSecurityService::PERMISSION_UPDATE;
        $dataAccess->grantGroupAccess($managerRole, (int) $inGroup->getId(), $crud);
        $dataAccess->grantGroupAccess($managerRole, (int) $inGroup2->getId(), $crud);

        $otherRole = $roles->createRole('qa_scope_other_role');
        $adminRole = $em->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Role::class, $adminRole, 'The seeded admin role must exist');

        $manager = $users->createUser(self::MANAGER_EMAIL, 'QA Scope Manager', groups: [$inGroup], roles: [$managerRole]);
        $target = $users->createUser(self::TARGET_EMAIL, 'QA Scope Target', groups: [$inGroup, $outGroup], roles: [$otherRole]);

        $this->managerId = (int) $manager->getId();
        $this->targetId = (int) $target->getId();
        $this->inGroupId = (int) $inGroup->getId();
        $this->inGroup2Id = (int) $inGroup2->getId();
        $this->outGroupId = (int) $outGroup->getId();
        $this->out2GroupId = (int) $out2Group->getId();
        $this->managerRoleId = (int) $managerRole->getId();
        $this->otherRoleId = (int) $otherRole->getId();
        $this->adminRoleId = (int) $adminRole->getId();
    }

    // -- groups --------------------------------------------------------------

    public function testManagerCannotAddOrRemoveAUserForAGroupItHasNoRightOn(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);

        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            $this->userUri('/groups'),
            ['group_ids' => [$this->out2GroupId]],
            $token
        ));
        $this->assertEnvelope403($this->jsonRequest(
            'DELETE',
            $this->userUri('/groups'),
            ['group_ids' => [$this->outGroupId]],
            $token
        ));

        self::assertSame(
            [$this->inGroupId, $this->outGroupId],
            $this->groupIdsOfTarget(),
            'A refused request changes nothing'
        );
    }

    public function testManagerCanAddAndRemoveAUserForAGroupItMayUpdate(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);
        $aclVersionBefore = $this->aclVersionOfTarget();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            $this->userUri('/groups'),
            ['group_ids' => [$this->inGroup2Id]],
            $token
        ));
        self::assertContains($this->inGroup2Id, $this->groupIdsOfTarget());
        self::assertNotSame(
            $aclVersionBefore,
            $this->aclVersionOfTarget(),
            'A group change rotates the user\'s acl_version so the frontend navigation cache is rebuilt'
        );

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            $this->userUri('/groups'),
            ['group_ids' => [$this->inGroup2Id]],
            $token
        ));
        self::assertNotContains($this->inGroup2Id, $this->groupIdsOfTarget());
    }

    public function testBulkGroupChangeFailsTheWholeRequestForAGroupWithoutRight(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);

        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::USERS_URI . '/bulk-add-to-group',
            ['user_ids' => [$this->targetId], 'group_ids' => [$this->out2GroupId]],
            $token
        ));
        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::USERS_URI . '/bulk-remove-from-group',
            ['user_ids' => [$this->targetId], 'group_ids' => [$this->outGroupId]],
            $token
        ));
        self::assertSame([$this->inGroupId, $this->outGroupId], $this->groupIdsOfTarget());

        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::USERS_URI . '/bulk-add-to-group',
            ['user_ids' => [$this->targetId], 'group_ids' => [$this->inGroup2Id]],
            $token
        ));
        self::assertSame([$this->targetId], $data['succeeded']);
        self::assertContains($this->inGroup2Id, $this->groupIdsOfTarget());
    }

    public function testUpdateNeedsARightOnlyForTheGroupsThatChange(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);

        // Adds a group the manager has no right on: refused, nothing changes.
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            $this->userUri(''),
            ['group_ids' => [$this->inGroupId, $this->outGroupId, $this->out2GroupId]],
            $token
        ));
        self::assertSame([$this->inGroupId, $this->outGroupId], $this->groupIdsOfTarget());

        // Drops a group the manager has no right on: refused too.
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            $this->userUri(''),
            ['group_ids' => [$this->inGroupId]],
            $token
        ));
        self::assertSame([$this->inGroupId, $this->outGroupId], $this->groupIdsOfTarget());

        // Adds a group the manager may update and leaves the out-of-reach
        // membership exactly as it is: allowed.
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            $this->userUri(''),
            ['group_ids' => [$this->inGroupId, $this->outGroupId, $this->inGroup2Id]],
            $token
        ));
        self::assertSame(
            [$this->inGroupId, $this->inGroup2Id, $this->outGroupId],
            $this->groupIdsOfTarget()
        );
    }

    public function testAdminMayChangeAnyGroupMembership(): void
    {
        $token = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            $this->userUri('/groups'),
            ['group_ids' => [$this->out2GroupId]],
            $token
        ));
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            $this->userUri('/groups'),
            ['group_ids' => [$this->outGroupId]],
            $token
        ));
        self::assertSame([$this->inGroupId, $this->out2GroupId], $this->groupIdsOfTarget());
    }

    public function testManagerCannotCreateAUserInAGroupItHasNoCreateRightOn(): void
    {
        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::USERS_URI,
            ['email' => 'qa.scope.created.out@selfhelp.test', 'group_ids' => [$this->outGroupId]],
            $this->loginAs(self::MANAGER_EMAIL)
        ));
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'qa.scope.created.out@selfhelp.test']));
    }

    // -- roles ---------------------------------------------------------------

    public function testManagerCanOnlyHandOutRolesItHoldsItself(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);

        // A role the manager does not hold, and the admin role: refused.
        $this->assertEnvelope403($this->jsonRequest('POST', $this->userUri('/roles'), ['role_ids' => [$this->adminRoleId]], $token));
        $this->assertEnvelope403($this->jsonRequest('POST', $this->userUri('/roles'), ['role_ids' => [$this->managerRoleId, $this->adminRoleId]], $token));
        self::assertSame([$this->otherRoleId], $this->roleIdsOfTarget(), 'A refused request changes nothing');

        // A role the manager holds: allowed.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $this->userUri('/roles'), ['role_ids' => [$this->managerRoleId]], $token));
        self::assertEqualsCanonicalizing([$this->otherRoleId, $this->managerRoleId], $this->roleIdsOfTarget());

        // Taking a role away follows the same rule.
        $this->assertEnvelope403($this->jsonRequest('DELETE', $this->userUri('/roles'), ['role_ids' => [$this->otherRoleId]], $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', $this->userUri('/roles'), ['role_ids' => [$this->managerRoleId]], $token));
        self::assertSame([$this->otherRoleId], $this->roleIdsOfTarget());
    }

    public function testUpdateNeedsToHoldOnlyTheRolesThatChange(): void
    {
        $token = $this->loginAs(self::MANAGER_EMAIL);

        // Adds a role the manager does not hold.
        $this->assertEnvelope403($this->jsonRequest('PUT', $this->userUri(''), ['role_ids' => [$this->otherRoleId, $this->adminRoleId]], $token));
        // Drops a role the manager does not hold.
        $this->assertEnvelope403($this->jsonRequest('PUT', $this->userUri(''), ['role_ids' => [$this->managerRoleId]], $token));
        self::assertSame([$this->otherRoleId], $this->roleIdsOfTarget());

        // Adds a held role and leaves the role out of reach as it is.
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', $this->userUri(''), ['role_ids' => [$this->otherRoleId, $this->managerRoleId]], $token));
        self::assertEqualsCanonicalizing([$this->otherRoleId, $this->managerRoleId], $this->roleIdsOfTarget());
    }

    public function testManagerCannotCreateAUserWithARoleItDoesNotHold(): void
    {
        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::USERS_URI,
            ['email' => 'qa.scope.created.role@selfhelp.test', 'role_ids' => [$this->adminRoleId]],
            $this->loginAs(self::MANAGER_EMAIL)
        ));
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'qa.scope.created.role@selfhelp.test']));
    }

    public function testAdminMayHandOutAnyRole(): void
    {
        $token = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $this->userUri('/roles'), ['role_ids' => [$this->managerRoleId]], $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', $this->userUri('/roles'), ['role_ids' => [$this->otherRoleId]], $token));
        self::assertSame([$this->managerRoleId], $this->roleIdsOfTarget());
    }

    public function testServiceRefusesAndAllowsCreateByTheSameRules(): void
    {
        $service = $this->service(AdminUserService::class);

        try {
            $service->createUser($this->managerId, [
                'email' => 'qa.scope.svc.denied@selfhelp.test',
                'name' => 'qa_scope_svc_denied',
                'password' => 'qa-password-1',
                'enable_validation' => false,
                'group_ids' => [$this->inGroupId],
                'role_ids' => [$this->otherRoleId],
            ]);
            self::fail('The manager does not hold the role, so the create must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(403, $e->getCode());
        }

        $created = $service->createUser($this->managerId, [
            'email' => 'qa.scope.svc.allowed@selfhelp.test',
            'name' => 'qa_scope_svc_allowed',
            'password' => 'qa-password-1',
            'enable_validation' => false,
            'group_ids' => [$this->inGroupId, $this->inGroup2Id],
            'role_ids' => [$this->managerRoleId],
        ]);
        self::assertSame('qa.scope.svc.allowed@selfhelp.test', $created['email']);
    }

    // -- helpers -------------------------------------------------------------

    private function userUri(string $suffix): string
    {
        return self::USERS_URI . '/' . $this->targetId . $suffix;
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    /**
     * @return list<int> sorted
     */
    private function groupIdsOfTarget(): array
    {
        $em = $this->entityManager();
        $em->clear();
        $target = $em->getRepository(User::class)->find($this->targetId);
        self::assertInstanceOf(User::class, $target);

        $ids = array_map(static fn(Group $group): int => (int) $group->getId(), $target->getGroups()->toArray());
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int> sorted
     */
    private function roleIdsOfTarget(): array
    {
        $em = $this->entityManager();
        $em->clear();
        $target = $em->getRepository(User::class)->find($this->targetId);
        self::assertInstanceOf(User::class, $target);

        $ids = array_map(static fn(Role $role): int => (int) $role->getId(), $target->getUserRoles()->toArray());
        sort($ids);

        return $ids;
    }

    private function aclVersionOfTarget(): string
    {
        $em = $this->entityManager();
        $em->clear();
        $target = $em->getRepository(User::class)->find($this->targetId);
        self::assertInstanceOf(User::class, $target);

        return (string) $target->getAclVersion();
    }
}
