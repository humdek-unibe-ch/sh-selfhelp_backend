<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for the admin Users API (plan Phase 5 — users).
 *
 * Migrated off the old developer-data variant: every test now creates its own
 * qa.-prefixed throwaway user instead of chaining on @depends + shared instance
 * state (which never survives PHPUnit's per-test isolation, so the old detail/
 * update/delete tests silently skipped). It logs in as the seeded QA admin
 * persona (no developer credentials) and asserts the standard response
 * envelope. Negative-permission behaviour lives in the permission-matrix
 * tests; this class asserts the admin-facing CRUD + lifecycle contract.
 */
class AdminUserControllerTest extends BaseControllerTest
{
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
    }

    /** Unique qa.-prefixed e-mail for a throwaway user created inside one test. */
    private function qaEmail(string $slug): string
    {
        return 'qa.user.' . $slug . '.' . uniqid('', false) . '@selfhelp.test';
    }

    /**
     * Create a throwaway qa user through the admin API and return its `data`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createTestUser(string $slug = 'api', array $overrides = []): array
    {
        $payload = array_merge([
            'email' => $this->qaEmail($slug),
            'name' => 'qa_user_' . $slug,
            'user_name' => 'qa_user_' . $slug . '_' . uniqid('', false),
            'password' => QaBaselineFixture::QA_PASSWORD,
            'blocked' => false,
        ], $overrides);

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload)
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_CREATED,
            $response->getStatusCode(),
            'User creation failed: ' . (string) $response->getContent()
        );

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);

        return $data['data'];
    }

    /**
     * @group user-management
     */
    public function testGetUsersListSuccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        // Validate response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('users', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        
        // Validate pagination structure
        $pagination = $data['data']['pagination'];
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('pageSize', $pagination);
        $this->assertArrayHasKey('totalCount', $pagination);
        $this->assertArrayHasKey('totalPages', $pagination);
        $this->assertArrayHasKey('hasNext', $pagination);
        $this->assertArrayHasKey('hasPrevious', $pagination);
        
        // Validate users array
        $this->assertIsArray($data['data']['users']);
        
        if (!empty($data['data']['users'])) {
            $user = $data['data']['users'][0];
            $this->assertArrayHasKey('id', $user);
            $this->assertArrayHasKey('email', $user);
            $this->assertArrayHasKey('name', $user);
            $this->assertArrayHasKey('last_login', $user);
            $this->assertArrayHasKey('status', $user);
            $this->assertArrayHasKey('blocked', $user);
            $this->assertArrayHasKey('code', $user);
            $this->assertArrayHasKey('groups', $user);
            $this->assertArrayHasKey('user_activity', $user);
            $this->assertArrayHasKey('user_type_code', $user);
            $this->assertArrayHasKey('user_type', $user);
        }
    }

    /**
     * @group user-management
     */
    public function testGetUsersListWithPagination(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users?page=1&pageSize=5',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertLessThanOrEqual(5, count($data['data']['users']));
        $this->assertSame(1, $data['data']['pagination']['page']);
        $this->assertSame(5, $data['data']['pagination']['pageSize']);
    }

    /**
     * @group user-management
     */
    public function testGetUsersListWithSearch(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users?search=admin',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data['data']['users']);
        
        // Verify search results contain the search term
        if (!empty($data['data']['users'])) {
            foreach ($data['data']['users'] as $user) {
                $containsSearch = (isset($user['email']) && stripos($user['email'], 'admin') !== false) ||
                                (isset($user['name']) && stripos($user['name'], 'admin') !== false) ||
                                (isset($user['user_name']) && stripos($user['user_name'], 'admin') !== false) ||
                                (isset($user['code']) && stripos($user['code'], 'admin') !== false) ||
                                (isset($user['roles']) && stripos($user['roles'], 'admin') !== false);
                $this->assertTrue($containsSearch, 'Search result should contain search term in email, name, user_name, code, or roles');
            }
        }
    }

    /**
     * @group user-management
     */
    public function testGetUsersListWithSorting(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users?sort=email&sortDirection=asc',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data['data']['users']);
        
        // Verify sorting - emails should be in ascending order
        if (count($data['data']['users']) > 1) {
            $emails = array_column($data['data']['users'], 'email');
            $sortedEmails = $emails;
            sort($sortedEmails);
            $this->assertSame($sortedEmails, $emails, 'Users should be sorted by email in ascending order');
        }
    }

    /**
     * @group user-management
     */
    public function testCreateUserSuccess(): void
    {
        $email = $this->qaEmail('create');
        $user = $this->createTestUser('create', ['email' => $email, 'name' => 'qa_user_create']);

        $this->assertArrayHasKey('id', $user);
        $this->assertSame($email, $user['email']);
        $this->assertSame('qa_user_create', $user['name']);
        $this->assertFalse($user['blocked']);
    }

    /**
     * @group user-management
     */
    public function testCreateUserWithInvalidEmail(): void
    {
        $userData = [
            'email' => 'invalid-email',
            'name' => 'qa_user_invalid',
            'password' => QaBaselineFixture::QA_PASSWORD,
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group user-management
     */
    public function testCreateUserWithMissingEmail(): void
    {
        $userData = [
            'name' => 'qa_user_no_email',
            'password' => QaBaselineFixture::QA_PASSWORD,
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group user-management
     */
    public function testGetUserByIdSuccess(): void
    {
        $created = $this->createTestUser('getbyid');
        $userId = (int) $created['id'];

        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame($userId, $data['data']['id']);
        $this->assertSame($created['email'], $data['data']['email']);
        
        // Verify detail view includes additional fields
        $this->assertArrayHasKey('groups', $data['data']);
        $this->assertArrayHasKey('roles', $data['data']);
        $this->assertIsArray($data['data']['groups']);
        $this->assertIsArray($data['data']['roles']);
    }

    /**
     * @group user-management
     */
    public function testGetUserByIdNotFound(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/999999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @group user-management
     */
    public function testUpdateUserSuccess(): void
    {
        $created = $this->createTestUser('update');
        $userId = (int) $created['id'];

        $updateData = [
            'name' => 'qa_user_update_changed',
            'blocked' => true
        ];

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame($updateData['name'], $data['data']['name']);
        $this->assertSame($updateData['blocked'], $data['data']['blocked']);
    }

    /**
     * @group user-management
     */
    public function testToggleUserBlockSuccess(): void
    {
        $created = $this->createTestUser('block');
        $userId = (int) $created['id'];

        $this->client->request(
            'PATCH',
            '/cms-api/v1/admin/users/' . $userId . '/block',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['blocked' => false])
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame(false, $data['data']['blocked']);
    }

    /**
     * @group user-management
     */
    public function testGetUserGroupsSuccess(): void
    {
        $created = $this->createTestUser('groups');
        $userId = (int) $created['id'];

        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId . '/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    /**
     * @group user-management
     */
    public function testGetUserRolesSuccess(): void
    {
        $created = $this->createTestUser('roles');
        $userId = (int) $created['id'];

        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId . '/roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    /**
     * Assigning then removing a role must be reflected by a subsequent GET.
     *
     * This proves the write path invalidates the user/permissions cache: a
     * stale cache would make the second GET still return the pre-write role
     * set. Uses the first seeded role purely for the assignment mechanics.
     *
     * @group user-management
     */
    public function testAssignAndRemoveUserRoleReflectsInResponse(): void
    {
        $created = $this->createTestUser('roleassign');
        $userId = (int) $created['id'];
        $roleId = (int) $this->entityManager->getConnection()
            ->fetchOne('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $this->assertGreaterThan(0, $roleId, 'A seeded role is required for the assignment test');

        // Baseline: the fresh user does not yet hold the role.
        $this->assertNotContains($roleId, $this->fetchUserRoleIds($userId), 'New user should start without the role');

        // Assign -> the write response and a fresh read must both show the role.
        $assigned = $this->mutateUserRoles('POST', $userId, $roleId);
        $this->assertContains($roleId, $assigned, 'Assign response must include the new role');
        $this->assertContains($roleId, $this->fetchUserRoleIds($userId), 'Fresh GET must reflect the assigned role (cache invalidated)');

        // Remove -> the write response and a fresh read must both drop the role.
        $removed = $this->mutateUserRoles('DELETE', $userId, $roleId);
        $this->assertNotContains($roleId, $removed, 'Remove response must drop the role');
        $this->assertNotContains($roleId, $this->fetchUserRoleIds($userId), 'Fresh GET must reflect the removed role (cache invalidated)');
    }

    /**
     * Same invalidation contract for group membership.
     *
     * @group user-management
     */
    public function testAssignAndRemoveUserGroupReflectsInResponse(): void
    {
        $created = $this->createTestUser('groupassign');
        $userId = (int) $created['id'];
        $groupId = (int) $this->entityManager->getConnection()
            ->fetchOne('SELECT id FROM `groups` ORDER BY id ASC LIMIT 1');
        $this->assertGreaterThan(0, $groupId, 'A seeded group is required for the assignment test');

        $this->assertNotContains($groupId, $this->fetchUserGroupIds($userId), 'New user should start without the group');

        $assigned = $this->mutateUserGroups('POST', $userId, $groupId);
        $this->assertContains($groupId, $assigned, 'Assign response must include the new group');
        $this->assertContains($groupId, $this->fetchUserGroupIds($userId), 'Fresh GET must reflect the assigned group (cache invalidated)');

        $removed = $this->mutateUserGroups('DELETE', $userId, $groupId);
        $this->assertNotContains($groupId, $removed, 'Remove response must drop the group');
        $this->assertNotContains($groupId, $this->fetchUserGroupIds($userId), 'Fresh GET must reflect the removed group (cache invalidated)');
    }

    /**
     * @return list<int>
     */
    private function fetchUserRoleIds(int $userId): array
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId . '/roles',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()]
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return array_map('intval', array_column($data['data']['roles'] ?? [], 'id'));
    }

    /**
     * @return list<int>
     */
    private function fetchUserGroupIds(int $userId): array
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId . '/groups',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()]
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return array_map('intval', array_column($data['data']['groups'] ?? [], 'id'));
    }

    /**
     * @return list<int> role ids present in the write response
     */
    private function mutateUserRoles(string $method, int $userId, int $roleId): array
    {
        $this->client->request(
            $method,
            '/cms-api/v1/admin/users/' . $userId . '/roles',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(), 'CONTENT_TYPE' => 'application/json'],
            json_encode(['role_ids' => [$roleId]])
        );
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('%s roles failed: %s', $method, (string) $response->getContent())
        );
        $data = json_decode($response->getContent(), true);

        return array_map('intval', array_column($data['data']['roles'] ?? [], 'id'));
    }

    /**
     * @return list<int> group ids present in the write response
     */
    private function mutateUserGroups(string $method, int $userId, int $groupId): array
    {
        $this->client->request(
            $method,
            '/cms-api/v1/admin/users/' . $userId . '/groups',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(), 'CONTENT_TYPE' => 'application/json'],
            json_encode(['group_ids' => [$groupId]])
        );
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('%s groups failed: %s', $method, (string) $response->getContent())
        );
        $data = json_decode($response->getContent(), true);

        return array_map('intval', array_column($data['data']['groups'] ?? [], 'id'));
    }

    /**
     * @group user-management
     */
    public function testUnauthorizedAccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * @group user-management
     */
    public function testDeleteUserSuccess(): void
    {
        $created = $this->createTestUser('delete');
        $userId = (int) $created['id'];

        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['data']['deleted']);

        // Verify user is deleted
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * The CMS protects "system" accounts (name in {admin, tpf}) from deletion.
     * The QA baseline contains no such account, so we promote a throwaway qa
     * user to a protected name at the entity level (DAMA rolls the change back)
     * and assert the API refuses to delete it with 403.
     *
     * @group user-management
     */
    public function testDeleteSystemUserForbidden(): void
    {
        $created = $this->createTestUser('sysguard');
        $userId = (int) $created['id'];

        $entity = $this->entityManager->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $entity);
        $entity->setName('admin'); // AdminUserService::SYSTEM_USERS guard trigger
        $this->entityManager->flush();

        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        // The protected account must still exist after the refused delete.
        $stillThere = $this->entityManager->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $stillThere);
    }

    /**
     * @group user-management
     */
    public function testCreateUserWithDuplicateEmail(): void
    {
        $email = $this->qaEmail('dup');

        // First create a user
        $this->createTestUser('dup', ['email' => $email, 'name' => 'qa_user_dup']);

        // Try to create another user with the same email
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'email' => $email,
                'name' => 'qa_user_dup_2',
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
