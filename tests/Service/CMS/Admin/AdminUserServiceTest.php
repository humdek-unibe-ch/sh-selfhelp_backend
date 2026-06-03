<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Repository\UserRepository;
use App\Service\CMS\Admin\AdminUserService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-level coverage for {@see AdminUserService} (plan Phase 5 — users).
 *
 * Migrated off the old developer-data variant: it now extends
 * {@see QaKernelTestCase} (seeded QA baseline + DAMA rollback), acts as the
 * seeded `qa.admin` persona for permission-scoped reads, and writes only
 * `qa.`-prefixed records. Negative permission behaviour for the HTTP surface
 * lives in the controller permission-matrix tests; this class asserts the
 * service contract (shapes, pagination, lifecycle, system-user guards).
 */
class AdminUserServiceTest extends QaKernelTestCase
{
    private AdminUserService $adminUserService;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUserService = $this->service(AdminUserService::class);

        $admin = $this->service(UserRepository::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin, 'QA admin persona must be seeded.');
        $this->adminId = (int) $admin->getId();
    }

    /** Unique qa.-prefixed e-mail for a throwaway user created inside one test. */
    private function qaEmail(string $slug): string
    {
        return 'qa.svc.' . $slug . '.' . uniqid('', false) . '@selfhelp.test';
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createQaUser(string $slug, array $overrides = []): array
    {
        $data = array_merge([
            'email' => $this->qaEmail($slug),
            'name' => 'qa_svc_' . $slug,
            'user_name' => 'qa_svc_' . $slug . '_' . uniqid('', false),
            'password' => QaBaselineFixture::QA_PASSWORD,
            'enable_validation' => false,
        ], $overrides);

        return $this->adminUserService->createUser($data);
    }

    /**
     * @group user-service
     */
    public function testGetFilteredUsersWithDefaultParameters(): void
    {
        $result = $this->adminUserService->getFilteredUsers($this->adminId);

        $this->assertIsArray($result['users']);
        $this->assertArrayHasKey('pagination', $result);

        $pagination = $this->asArray($result['pagination']);
        foreach (['page', 'pageSize', 'totalCount', 'totalPages', 'hasNext', 'hasPrevious'] as $key) {
            $this->assertArrayHasKey($key, $pagination);
        }
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(20, $pagination['pageSize']);
        $this->assertFalse($pagination['hasPrevious']);
        // The seeded QA personas guarantee the admin sees a non-empty list.
        $this->assertGreaterThan(0, $pagination['totalCount']);
    }

    /**
     * @group user-service
     */
    public function testGetFilteredUsersWithPagination(): void
    {
        $result = $this->adminUserService->getFilteredUsers($this->adminId, 1, 2);

        $this->assertLessThanOrEqual(2, count($this->asList($result['users'])));
        $pagination = $this->asArray($result['pagination']);
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(2, $pagination['pageSize']);
    }

    /**
     * @group user-service
     */
    public function testGetFilteredUsersWithSearchFindsTheSeededAdmin(): void
    {
        $result = $this->adminUserService->getFilteredUsers($this->adminId, 1, 50, 'qa.admin');

        $this->assertIsArray($result['users']);
        $emails = array_column($result['users'], 'email');
        $this->assertContains(QaBaselineFixture::QA_ADMIN_EMAIL, $emails);
    }

    /**
     * @group user-service
     */
    public function testGetFilteredUsersWithSorting(): void
    {
        $result = $this->adminUserService->getFilteredUsers($this->adminId, 1, 50, null, 'email', 'asc');

        $this->assertIsArray($result['users']);
        if (count($result['users']) > 1) {
            $emails = array_column($result['users'], 'email');
            $sorted = $emails;
            sort($sorted);
            $this->assertSame($sorted, $emails, 'Users should be sorted by email ascending.');
        }
    }

    /**
     * @group user-service
     */
    public function testCreateUserSuccess(): void
    {
        $email = $this->qaEmail('create');
        $result = $this->createQaUser('create', ['email' => $email]);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame($email, $result['email']);
        $this->assertSame('qa_svc_create', $result['name']);
        $this->assertFalse($result['blocked']);

        $this->adminUserService->deleteUser($this->adminId, $this->asInt($result['id']));
    }

    /**
     * @group user-service
     */
    public function testCreateUserWithMissingEmailThrowsException(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);

        $this->adminUserService->createUser([
            'name' => 'qa_svc_no_email',
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]);
    }

    /**
     * @group user-service
     */
    public function testCreateUserWithDuplicateEmailThrowsException(): void
    {
        $email = $this->qaEmail('dup');
        $first = $this->createQaUser('dup', ['email' => $email]);

        try {
            $this->expectException(ServiceException::class);
            $this->adminUserService->createUser([
                'email' => $email,
                'name' => 'qa_svc_dup_2',
                'password' => QaBaselineFixture::QA_PASSWORD,
                'enable_validation' => false,
            ]);
        } finally {
            $this->adminUserService->deleteUser($this->adminId, $this->asInt($first['id']));
        }
    }

    /**
     * @group user-service
     */
    public function testGetUserByIdReturnsDetailWithGroupsAndRoles(): void
    {
        $created = $this->createQaUser('getbyid');
        $userId = $this->asInt($created['id']);

        $result = $this->adminUserService->getUserById($userId);

        $this->assertSame($userId, $result['id']);
        $this->assertSame($created['email'], $result['email']);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('roles', $result);
        $this->assertIsArray($result['groups']);
        $this->assertIsArray($result['roles']);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testGetUserByIdNotFoundThrowsException(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('User not found');
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);

        $this->adminUserService->getUserById(999999);
    }

    /**
     * @group user-service
     */
    public function testUpdateUserSuccess(): void
    {
        $created = $this->createQaUser('update', ['name' => 'qa_svc_update']);
        $userId = $this->asInt($created['id']);

        $result = $this->adminUserService->updateUser($this->adminId, $userId, [
            'name' => 'qa_svc_update_changed',
            'blocked' => true,
        ]);

        $this->assertSame($userId, $result['id']);
        $this->assertSame('qa_svc_update_changed', $result['name']);
        $this->assertTrue($result['blocked']);
        $this->assertSame($created['email'], $result['email']);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testUpdateUserNotFoundThrowsException(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('User not found');
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);

        $this->adminUserService->updateUser($this->adminId, 999999, ['name' => 'qa_svc_ghost']);
    }

    /**
     * @group user-service
     */
    public function testToggleUserBlockSuccess(): void
    {
        $created = $this->createQaUser('block');
        $userId = $this->asInt($created['id']);

        $blocked = $this->adminUserService->toggleUserBlock($userId, true);
        $this->assertTrue($blocked['blocked']);

        $unblocked = $this->adminUserService->toggleUserBlock($userId, false);
        $this->assertFalse($unblocked['blocked']);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testDeleteUserSuccess(): void
    {
        $created = $this->createQaUser('delete');
        $userId = $this->asInt($created['id']);

        $this->assertTrue($this->adminUserService->deleteUser($this->adminId, $userId));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('User not found');
        $this->adminUserService->getUserById($userId);
    }

    /**
     * @group user-service
     */
    public function testDeleteUserNotFoundThrowsException(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('User not found');
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);

        $this->adminUserService->deleteUser($this->adminId, 999999);
    }

    /**
     * @group user-service
     */
    public function testDeleteSystemUserThrowsException(): void
    {
        // The QA baseline ships no `admin`/`tpf` system account, so promote a
        // throwaway qa user to a protected name at the entity level (DAMA rolls
        // it back) to exercise AdminUserService::SYSTEM_USERS deletion guard.
        $created = $this->createQaUser('sysguard');
        $userId = $this->asInt($created['id']);

        $entity = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $entity);
        $entity->setName('admin');
        $this->em->flush();

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Cannot delete system users');
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testGetUserGroupsAndRolesReturnArrays(): void
    {
        $created = $this->createQaUser('relations');
        $userId = $this->asInt($created['id']);

        $this->assertGreaterThanOrEqual(0, count($this->adminUserService->getUserGroups($userId)));
        $this->assertGreaterThanOrEqual(0, count($this->adminUserService->getUserRoles($userId)));

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testInvalidPageParametersGetNormalized(): void
    {
        // validatePaginationParams clamps via max(1, min(MAX_PAGE_SIZE, n)):
        // page floors at 1, pageSize is clamped into [1, 100].
        $this->assertSame(1, $this->jsonGet($this->adminUserService->getFilteredUsers($this->adminId, 0, 20), 'pagination', 'page'));
        $this->assertSame(1, $this->jsonGet($this->adminUserService->getFilteredUsers($this->adminId, 1, 0), 'pagination', 'pageSize'));
        $this->assertSame(100, $this->jsonGet($this->adminUserService->getFilteredUsers($this->adminId, 1, 150), 'pagination', 'pageSize'));
    }

    /**
     * @group user-service
     */
    public function testInvalidSortDirectionGetsNormalized(): void
    {
        $result = $this->adminUserService->getFilteredUsers($this->adminId, 1, 20, null, 'email', 'invalid');

        $this->assertIsArray($result['users']);
        $this->assertArrayHasKey('pagination', $result);
    }

    /**
     * @group user-service
     */
    public function testSendActivationMailReturnsSuccessWithoutRealOutbound(): void
    {
        $created = $this->createQaUser('activation', ['enable_validation' => false]);
        $userId = $this->asInt($created['id']);

        // Simulate a user awaiting activation.
        $entity = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $entity);
        $entity->setBlocked(true);
        $this->em->flush();

        $result = $this->adminUserService->sendActivationMail($userId);

        $this->assertTrue($result['success']);
        $this->assertSame('Activation email sent successfully', $result['message']);
        $this->assertSame($userId, $result['user_id']);
        $this->assertSame($created['email'], $result['email']);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('job_id', $result);
        $this->assertArrayHasKey('validation_url', $result);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testCleanUserDataReturnsTrue(): void
    {
        $created = $this->createQaUser('clean');
        $userId = $this->asInt($created['id']);

        $this->assertTrue($this->adminUserService->cleanUserData($userId));

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }

    /**
     * @group user-service
     */
    public function testImpersonateUserReturnsToken(): void
    {
        $created = $this->createQaUser('impersonate');
        $userId = $this->asInt($created['id']);

        $result = $this->adminUserService->impersonateUser($this->adminId, $userId);

        $this->assertArrayHasKey('impersonation_token', $result);
        $this->assertNotEmpty($result['impersonation_token']);
        $this->assertSame($created['email'], $result['target_email']);

        $this->adminUserService->deleteUser($this->adminId, $userId);
    }
}
