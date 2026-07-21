<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GET /admin/roles/{id}/users — the "View users" modal on the Roles page.
 *
 * Mirror of AdminGroupMembersTest for roles (rel_roles_users). Same contract:
 * a missing role 404s, an empty role is [], internal users are excluded to
 * match the Users page, and the route is admin.role.read-only.
 */
#[TestGroup('security')]
final class AdminRoleUsersTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/roles';

    protected function setUp(): void
    {
        parent::setUp();

        // DAMA rolls the DB back per test, but the cache pool is filesystem and
        // survives — drop the role caches so a user list cached by an earlier
        // test cannot outlive the rows it described.
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->invalidateAllListsInCategory();
    }

    public function testReturnsUsersHoldingTheRoleWithTheContractFields(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->adminRoleId();
        $userId = $this->createQaUserWithRole('qa.roleuser.one@selfhelp.test', $roleId, LookupService::USER_STATUS_ACTIVE);

        $users = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $roleId . '/users', null, $token)
        );

        $mine = null;
        foreach ($users as $user) {
            foreach (['id', 'email', 'name', 'user_name', 'status', 'blocked'] as $field) {
                self::assertArrayHasKey($field, $user, sprintf('User row must expose "%s"', $field));
            }
            if (($user['email'] ?? null) === 'qa.roleuser.one@selfhelp.test') {
                $mine = $user;
            }
        }

        self::assertNotNull($mine, 'The seeded role-holder must appear in the list');
        self::assertSame($userId, $mine['id']);
        self::assertSame('active', $mine['status']);
        self::assertFalse($mine['blocked']);
    }

    public function testRoleWithNoUsersReturnsEmptyArrayNotError(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->createQaRole('qa_role_users_empty');

        $envelope = $this->jsonRequest('GET', self::BASE . '/' . $roleId . '/users', null, $token);

        self::assertSame(Response::HTTP_OK, $envelope['status'] ?? null);
        self::assertSame([], $envelope['data'], 'A role with no users is [], not a 404');
    }

    public function testNonExistentRoleReturns404(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '/2147483600/users', null, $this->loginAsQaAdmin());

        self::assertSame(Response::HTTP_NOT_FOUND, $envelope['status'] ?? null);
    }

    public function testInternalUsersAreExcludedToMatchTheUsersList(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->createQaRole('qa_role_users_visibility');

        $visible = $this->createQaUserWithRole('qa.roleuser.visible@selfhelp.test', $roleId, LookupService::USER_STATUS_ACTIVE);
        $intern = $this->createQaUserWithRole('qa.roleuser.intern@selfhelp.test', $roleId, LookupService::USER_STATUS_ACTIVE, intern: true);

        $users = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $roleId . '/users', null, $token)
        );

        $ids = [];
        foreach ($users as $user) {
            $id = $user['id'] ?? null;
            self::assertIsInt($id);
            $ids[] = $id;
        }
        self::assertContains($visible, $ids, 'A normal role-holder is listed');
        self::assertNotContains($intern, $ids, 'An intern user must be filtered out, matching the users list');
    }

    public function testBlockedUserIsReportedAsBlocked(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->createQaRole('qa_role_users_blocked');
        $blockedId = $this->createQaUserWithRole('qa.roleuser.blocked@selfhelp.test', $roleId, LookupService::USER_STATUS_INVITED, blocked: true);

        $users = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $roleId . '/users', null, $token)
        );

        $blocked = null;
        foreach ($users as $user) {
            if (($user['id'] ?? null) === $blockedId) {
                $blocked = $user;
            }
        }

        self::assertNotNull($blocked);
        self::assertTrue($blocked['blocked']);
        self::assertSame('invited', $blocked['status']);
    }

    public function testRoleUsersEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/' . $this->adminRoleId() . '/users');
    }

    // -- Helpers ------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    private function assertEnvelopeSuccessList(array $envelope): array
    {
        self::assertSame(Response::HTTP_OK, $envelope['status'] ?? null, 'Expected a 200 success envelope');
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);

        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private function adminRoleId(): int
    {
        $role = $this->entityManager()->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Role::class, $role);

        return (int) $role->getId();
    }

    private function createQaRole(string $name): int
    {
        $role = new Role();
        $role->setName($name);
        $role->setDescription('qa role users test role');

        $this->entityManager()->persist($role);
        $this->entityManager()->flush();

        return (int) $role->getId();
    }

    /**
     * Seed a QA user holding a role through the production entity model
     * (Testing Rule 8: no raw SQL, real lookups + rel_roles_users).
     */
    private function createQaUserWithRole(
        string $email,
        int $roleId,
        string $statusCode,
        bool $blocked = false,
        bool $intern = false,
    ): int {
        $em = $this->entityManager();

        $status = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => $statusCode,
        ]);
        $userType = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);
        self::assertInstanceOf(Lookup::class, $status);
        self::assertInstanceOf(Lookup::class, $userType);

        $role = $em->getRepository(Role::class)->find($roleId);
        self::assertInstanceOf(Role::class, $role);

        $user = new User();
        $user->setEmail($email);
        $user->setName('QA ' . $email);
        $user->setUserName(str_replace(['@', '.'], '_', $email));
        $user->setStatus($status);
        $user->setUserType($userType);
        $user->setBlocked($blocked);
        $user->setIntern($intern);
        $user->setPassword(
            $this->service(UserPasswordHasherInterface::class)->hashPassword($user, QaBaselineFixture::QA_PASSWORD)
        );
        $user->addRole($role);

        $em->persist($user);
        $em->flush();

        return (int) $user->getId();
    }
}
