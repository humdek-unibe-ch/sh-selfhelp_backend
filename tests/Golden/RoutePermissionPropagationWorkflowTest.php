<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Permission;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Golden route-permission propagation workflow:
 *
 *   create a qa_ role + a loginable non-admin member -> the member is 403 on
 *   GET /admin/pages -> admin grants ONLY admin.page.read to the role through
 *   the role-permission API -> the SAME member session now succeeds on
 *   /admin/pages while staying 403 on /admin/users -> admin removes the
 *   permission -> /admin/pages is 403 again -> /auth/user-data reflects the
 *   permission set and a rotated acl_version at each step.
 *
 * This proves the live propagation chain end to end (plan §16, §26, §27):
 * AdminRoleService write -> permission/user cache invalidation + acl_version
 * bump -> ApiSecurityListener recomputing the caller's permissions from the DB
 * (NOT from the stale JWT) -> /auth/user-data exposing the new contract. The
 * member's JWT is issued once and reused throughout, so this also asserts that
 * an acl_version rotation does not invalidate an in-flight session token. All
 * data is qa_-prefixed and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
#[TestGroup('security')]
final class RoutePermissionPropagationWorkflowTest extends QaWebTestCase
{
    private const ROLE = 'qa_perm_propagation_role';
    private const USER_EMAIL = 'qa.perm.propagation@selfhelp.test';
    private const PAGE_READ = 'admin.page.read';

    private EntityManagerInterface $em;
    private RoleFactory $roles;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->roles = new RoleFactory($this->em);
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
    }

    public function testGrantAndRevokeOfRoutePermissionPropagatesToLiveSession(): void
    {
        // 1. A non-admin role with NO permissions + a loginable member. All EM
        //    mutations happen before any HTTP request (the kernel clears the
        //    shared EntityManager mid-request — UserManagementLifecycleTest pattern).
        $role = $this->roles->createRole(self::ROLE);
        $user = $this->users->createUser(self::USER_EMAIL, 'QA Perm User', roles: [$role]);
        $roleId = (int) $role->getId();
        self::assertGreaterThan(0, (int) $user->getId(), 'Factory user must persist.');
        $pageReadPermissionId = $this->permissionId(self::PAGE_READ);

        $userToken = $this->loginAs(self::USER_EMAIL);
        $admin = $this->loginAsQaAdmin();

        // 2. Baseline: the member cannot read pages or users, and its identity
        //    contract carries no admin.page.read.
        $this->assertForbidden($this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $userToken));
        $this->assertForbidden($this->jsonRequest('GET', '/cms-api/v1/admin/users', null, $userToken));

        $baseline = $this->userData($userToken);
        $aclVersionInitial = $this->asString($baseline['acl_version'] ?? null);
        self::assertNotSame('', $aclVersionInitial, 'acl_version must be a non-empty token.');
        self::assertNotContains(self::PAGE_READ, $this->permissionNames($baseline), 'Baseline must not carry admin.page.read.');

        // 3. Grant ONLY admin.page.read to the role through the admin API.
        $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/roles/%d/permissions', $roleId),
                ['permission_ids' => [$pageReadPermissionId]],
                $admin
            )
        );

        // 4. The SAME member session now reads pages (grant propagated to the
        //    DB-recomputed permission set) but is still denied users.
        $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $userToken));
        $this->assertForbidden(
            $this->jsonRequest('GET', '/cms-api/v1/admin/users', null, $userToken),
            'Granting page read must not leak access to the users route.'
        );

        $afterGrant = $this->userData($userToken);
        $aclVersionAfterGrant = $this->asString($afterGrant['acl_version'] ?? null);
        self::assertNotSame($aclVersionInitial, $aclVersionAfterGrant, 'Granting a permission must rotate acl_version.');
        self::assertContains(self::PAGE_READ, $this->permissionNames($afterGrant), 'Identity contract must now expose admin.page.read.');

        // 5. Remove the permission again.
        $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'DELETE',
                sprintf('/cms-api/v1/admin/roles/%d/permissions', $roleId),
                ['permission_ids' => [$pageReadPermissionId]],
                $admin
            )
        );

        // 6. Access is revoked for the live session and the contract reflects it
        //    with another acl_version rotation.
        $this->assertForbidden(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $userToken),
            'Removing the permission must revoke /admin/pages for the live session.'
        );

        $afterRevoke = $this->userData($userToken);
        $aclVersionAfterRevoke = $this->asString($afterRevoke['acl_version'] ?? null);
        self::assertNotSame($aclVersionAfterGrant, $aclVersionAfterRevoke, 'Removing a permission must rotate acl_version again.');
        self::assertNotContains(self::PAGE_READ, $this->permissionNames($afterRevoke), 'admin.page.read must be gone from the contract.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertForbidden(array $envelope, string $message = 'Expected 403'): void
    {
        self::assertSame(Response::HTTP_FORBIDDEN, $envelope['status'] ?? null, $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(string $token): array
    {
        return $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/auth/user-data', null, $token)
        );
    }

    /**
     * @param array<string, mixed> $userData
     * @return list<string>
     */
    private function permissionNames(array $userData): array
    {
        $names = [];
        foreach ($this->asList($userData['permissions'] ?? null) as $permission) {
            if (is_string($permission)) {
                $names[] = $permission;
            }
        }

        return $names;
    }

    private function permissionId(string $name): int
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Permission::class, $permission, sprintf('Seeded permission "%s" must exist.', $name));

        return (int) $permission->getId();
    }
}
