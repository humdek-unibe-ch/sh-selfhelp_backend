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
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $em);
        $this->entityManager = $em;
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
            (string) json_encode($payload)
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_CREATED,
            $response->getStatusCode(),
            'User creation failed: ' . (string) $response->getContent()
        );

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('id', $payload);

        return $payload;
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

        $data = $this->decodeArray();
        
        // Validate response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('users', $payload);
        $this->assertArrayHasKey('pagination', $payload);
        
        // Validate pagination structure
        $pagination = $this->asArray($payload['pagination']);
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('pageSize', $pagination);
        $this->assertArrayHasKey('totalCount', $pagination);
        $this->assertArrayHasKey('totalPages', $pagination);
        $this->assertArrayHasKey('hasNext', $pagination);
        $this->assertArrayHasKey('hasPrevious', $pagination);
        
        // Validate users array
        $users = $this->asList($payload['users']);
        
        if (!empty($users)) {
            $user = $this->asArray($users[0]);
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

        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $this->assertLessThanOrEqual(5, count($this->asList($payload['users'])));
        $pagination = $this->asArray($payload['pagination']);
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(5, $pagination['pageSize']);
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

        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $users = $this->asList($payload['users']);
        
        // Verify search results contain the search term
        foreach ($users as $userRaw) {
            $user = $this->asArray($userRaw);
            $containsSearch = (isset($user['email']) && stripos($this->asString($user['email']), 'admin') !== false) ||
                            (isset($user['name']) && stripos($this->asString($user['name']), 'admin') !== false) ||
                            (isset($user['user_name']) && stripos($this->asString($user['user_name']), 'admin') !== false) ||
                            (isset($user['code']) && stripos($this->asString($user['code']), 'admin') !== false) ||
                            (isset($user['roles']) && stripos($this->asString($user['roles']), 'admin') !== false);
            $this->assertTrue($containsSearch, 'Search result should contain search term in email, name, user_name, code, or roles');
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

        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $users = $this->asList($payload['users']);
        
        // Verify sorting - emails should be in ascending order
        if (count($users) > 1) {
            $emails = array_column($users, 'email');
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
     * Issue #29: a created user defaults both communication preferences to true,
     * the create/detail payloads expose them, and an update can flip them.
     *
     * @group user-management
     */
    public function testCommunicationPreferencesDefaultTrueAndAreEditable(): void
    {
        // Create without explicit preferences -> both default to true.
        $created = $this->createTestUser('commprefs');
        $this->assertArrayHasKey('receives_notifications', $created);
        $this->assertArrayHasKey('receives_emails', $created);
        $this->assertTrue($created['receives_notifications'], 'New users default to receiving notifications.');
        $this->assertTrue($created['receives_emails'], 'New users default to receiving emails.');

        // Create with explicit false values -> honoured.
        $optedOut = $this->createTestUser('commprefs_off', [
            'receives_notifications' => false,
            'receives_emails' => false,
        ]);
        $this->assertFalse($optedOut['receives_notifications']);
        $this->assertFalse($optedOut['receives_emails']);

        // Update flips one preference back on; detail GET reflects it.
        $userId = $this->asInt($optedOut['id']);
        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/users/' . $userId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json',
            ],
            (string) json_encode(['receives_emails' => true])
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $updated = $this->asArray($this->decodeArray()['data']);
        $this->assertFalse($updated['receives_notifications'], 'Untouched preference stays false.');
        $this->assertTrue($updated['receives_emails'], 'Updated preference is now true.');
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
            (string) json_encode($userData)
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
            (string) json_encode($userData)
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
        $userId = $this->asInt($created['id']);

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

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertSame($userId, $payload['id']);
        $this->assertSame($created['email'], $payload['email']);
        
        // Verify detail view includes additional fields
        $this->assertArrayHasKey('groups', $payload);
        $this->assertArrayHasKey('roles', $payload);
        $this->assertIsArray($payload['groups']);
        $this->assertIsArray($payload['roles']);
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
        $userId = $this->asInt($created['id']);

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
            (string) json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertSame($updateData['name'], $payload['name']);
        $this->assertSame($updateData['blocked'], $payload['blocked']);
    }

    /**
     * @group user-management
     */
    public function testToggleUserBlockSuccess(): void
    {
        $created = $this->createTestUser('block');
        $userId = $this->asInt($created['id']);

        $this->client->request(
            'PATCH',
            '/cms-api/v1/admin/users/' . $userId . '/block',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode(['blocked' => false])
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertSame(false, $payload['blocked']);
    }

    /**
     * @group user-management
     */
    public function testGetUserGroupsSuccess(): void
    {
        $created = $this->createTestUser('groups');
        $userId = $this->asInt($created['id']);

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

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    /**
     * @group user-management
     */
    public function testGetUserRolesSuccess(): void
    {
        $created = $this->createTestUser('roles');
        $userId = $this->asInt($created['id']);

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

        $data = $this->decodeArray();
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
        $userId = $this->asInt($created['id']);
        $roleId = $this->coerceInt($this->entityManager->getConnection()
            ->fetchOne('SELECT id FROM roles ORDER BY id ASC LIMIT 1'));
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
        $userId = $this->asInt($created['id']);
        $groupId = $this->coerceInt($this->entityManager->getConnection()
            ->fetchOne('SELECT id FROM `groups` ORDER BY id ASC LIMIT 1'));
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
        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $rows = is_array($payload['roles'] ?? null) ? $payload['roles'] : [];

        return array_map(static fn (mixed $v): int => self::coerceInt($v), array_column($rows, 'id'));
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
        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $rows = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];

        return array_map(static fn (mixed $v): int => self::coerceInt($v), array_column($rows, 'id'));
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
            (string) json_encode(['role_ids' => [$roleId]])
        );
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('%s roles failed: %s', $method, (string) $response->getContent())
        );
        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $rows = is_array($payload['roles'] ?? null) ? $payload['roles'] : [];

        return array_map(static fn (mixed $v): int => self::coerceInt($v), array_column($rows, 'id'));
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
            (string) json_encode(['group_ids' => [$groupId]])
        );
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('%s groups failed: %s', $method, (string) $response->getContent())
        );
        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $rows = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];

        return array_map(static fn (mixed $v): int => self::coerceInt($v), array_column($rows, 'id'));
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
        $userId = $this->asInt($created['id']);

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
        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        $this->assertTrue($payload['deleted']);

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
        $userId = $this->asInt($created['id']);

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
            (string) json_encode([
                'email' => $email,
                'name' => 'qa_user_dup_2',
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
