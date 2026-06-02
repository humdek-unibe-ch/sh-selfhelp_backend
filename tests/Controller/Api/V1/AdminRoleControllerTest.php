<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use Symfony\Component\HttpFoundation\Response;
use App\Entity\Role;
use App\Entity\Permission;

class AdminRoleControllerTest extends BaseControllerTest
{
    private ?int $testRoleId = null;
    private string $testRoleName = 'qa_role_api_test';
    /** @var list<int|null> */
    private array $testPermissionIds = [];
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $em);
        $this->entityManager = $em;
        $this->createTestPermissions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRole();
        parent::tearDown();
    }

    private function createTestPermissions(): void
    {
        // Create test permissions if they don't exist
        $permissionNames = ['qa_perm_api_1', 'qa_perm_api_2', 'qa_perm_api_3'];
        
        $permissions = [];
        foreach ($permissionNames as $name) {
            $permission = $this->entityManager->getRepository(Permission::class)
                ->findOneBy(['name' => $name]);
            
            if (!$permission) {
                $permission = new Permission();
                $permission->setName($name);
                $permission->setDescription('Test permission: ' . $name);
                $this->entityManager->persist($permission);
            }
            $permissions[] = $permission;
        }
        
        // Flush first: a newly persisted entity has no id until the insert runs,
        // so collecting getId() before flush would yield nulls.
        $this->entityManager->flush();

        foreach ($permissions as $permission) {
            $this->testPermissionIds[] = $permission->getId();
        }
    }

    private function cleanupTestRole(): void
    {
        if ($this->testRoleId) {
            $role = $this->entityManager->getRepository(Role::class)->find($this->testRoleId);
            if ($role) {
                $this->entityManager->remove($role);
                $this->entityManager->flush();
            }
        }
    }

    /**
     * @group role-management
     */
    public function testGetRolesListSuccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles',
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
        $this->assertArrayHasKey('roles', $payload);
        $this->assertArrayHasKey('pagination', $payload);
        
        // Validate pagination structure
        $pagination = $this->asArray($payload['pagination']);
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('pageSize', $pagination);
        $this->assertArrayHasKey('totalCount', $pagination);
        $this->assertArrayHasKey('totalPages', $pagination);
        $this->assertArrayHasKey('hasNext', $pagination);
        $this->assertArrayHasKey('hasPrevious', $pagination);
        
        // Validate roles array
        $roles = $this->asList($payload['roles']);
        
        if (!empty($roles)) {
            $role = $this->asArray($roles[0]);
            $this->assertArrayHasKey('id', $role);
            $this->assertArrayHasKey('name', $role);
            $this->assertArrayHasKey('description', $role);
            $this->assertArrayHasKey('permissions_count', $role);
            $this->assertArrayHasKey('users_count', $role);
        }
    }

    /**
     * @group role-management
     */
    public function testGetRolesListWithPagination(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles?page=1&pageSize=5',
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
        $this->assertLessThanOrEqual(5, count($this->asList($payload['roles'])));
        $pagination = $this->asArray($payload['pagination']);
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(5, $pagination['pageSize']);
    }

    /**
     * @group role-management
     */
    public function testGetRolesListWithSearch(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles?search=admin',
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
        $this->assertArrayHasKey('roles', $this->asArray($data['data']));
    }

    /**
     * @group role-management
     */
    public function testCreateRoleSuccess(): void
    {
        $roleData = [
            'name' => $this->testRoleName,
            'description' => 'Test role description'
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($roleData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        
        $role = $this->asArray($data['data']);
        $this->assertArrayHasKey('id', $role);
        $this->assertArrayHasKey('name', $role);
        $this->assertArrayHasKey('description', $role);
        $this->assertArrayHasKey('permissions_count', $role);
        $this->assertArrayHasKey('users_count', $role);
        $this->assertArrayHasKey('permissions', $role);
        $this->assertArrayHasKey('users', $role);
        
        $this->assertSame($roleData['name'], $role['name']);
        $this->assertSame($roleData['description'], $role['description']);
        
        // Store the created role ID for other tests
        $this->testRoleId = $this->asInt($role['id']);
    }

    /**
     * @group role-management
     */
    public function testGetRoleByIdSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles/' . $this->testRoleId,
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
        $role = $this->asArray($data['data']);
        
        $this->assertSame($this->testRoleId, $role['id']);
        $this->assertSame($this->testRoleName, $role['name']);
        $this->assertArrayHasKey('permissions', $role);
        $this->assertArrayHasKey('users', $role);
        $this->assertIsArray($role['permissions']);
        $this->assertIsArray($role['users']);
    }

    /**
     * @group role-management
     */
    public function testUpdateRoleSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        // Role name is an immutable identifier; updateRole only mutates description.
        $updateData = [
            'name' => $this->testRoleName . ' Updated',
            'description' => 'Updated description'
        ];

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/roles/' . $this->testRoleId,
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
        $role = $this->asArray($data['data']);
        
        // Name stays the original (immutable); description changes.
        $this->assertSame($this->testRoleName, $role['name']);
        $this->assertSame($updateData['description'], $role['description']);
    }

    /**
     * @group role-management
     */
    public function testGetRolePermissionsSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles/' . $this->testRoleId . '/permissions',
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
        $this->assertArrayHasKey('permissions', $payload);
        $this->assertIsArray($payload['permissions']);
    }

    /**
     * @group role-management
     */
    public function testAddPermissionsToRoleSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        $permissionData = [
            'permission_ids' => array_slice($this->testPermissionIds, 0, 2) // Add first 2 permissions
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/roles/' . $this->testRoleId . '/permissions',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($permissionData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('permissions', $payload);
        $this->assertCount(2, $this->asArray($payload['permissions']));
    }

    /**
     * @group role-management
     */
    public function testRemovePermissionsFromRoleSuccess(): void
    {
        // First create a role and add permissions
        $this->testAddPermissionsToRoleSuccess();
        
        $permissionData = [
            'permission_ids' => array_slice($this->testPermissionIds, 0, 1) // Remove first permission
        ];

        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/roles/' . $this->testRoleId . '/permissions',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($permissionData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('permissions', $payload);
        $this->assertCount(1, $this->asArray($payload['permissions'])); // Should have 1 permission left
    }

    /**
     * @group role-management
     */
    public function testUpdateRolePermissionsSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        $permissionData = [
            'permission_ids' => $this->testPermissionIds // All test permissions
        ];

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/roles/' . $this->testRoleId . '/permissions',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($permissionData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('permissions', $payload);
        $this->assertCount(3, $this->asArray($payload['permissions'])); // Should have all 3 permissions
    }

    /**
     * @group role-management
     */
    public function testCreateRoleWithDuplicateName(): void
    {
        // First create a role
        $this->testCreateRoleSuccess();
        
        // Try to create another role with the same name
        $roleData = [
            'name' => $this->testRoleName, // Same name
            'description' => 'Another test role'
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($roleData)
        );

        // Duplicate role name is a resource conflict (409), not a 400.
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    /**
     * @group role-management
     */
    public function testCreateRoleWithMissingName(): void
    {
        $roleData = [
            'description' => 'Test role without name'
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ],
            (string) json_encode($roleData)
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group role-management
     */
    public function testGetNonExistentRole(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles/99999',
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
     * @group role-management
     */
    public function testDeleteRoleSuccess(): void
    {
        // First create a role for this test
        $this->testCreateRoleSuccess();
        
        $roleIdToDelete = $this->testRoleId;

        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/roles/' . $roleIdToDelete,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        // Verify the role is deleted
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles/' . $roleIdToDelete,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        
        // Clear the testRoleId since it's deleted
        $this->testRoleId = null;
    }

    /**
     * @group role-management
     */
    public function testUnauthorizedAccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/roles'
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
} 