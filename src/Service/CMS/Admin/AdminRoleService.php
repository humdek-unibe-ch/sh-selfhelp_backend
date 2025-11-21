<?php

namespace App\Service\CMS\Admin;

use App\Entity\Role;
use App\Entity\Permission;
use App\Repository\UserRepository;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Service\Auth\UserContextService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleService extends BaseService
{

    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly EntityManagerInterface $entityManagerInterface,
        private readonly UserRepository $userRepository,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get roles with pagination, search, and sorting
     */
    public function getRoles(
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        ?string $sort = null,
        ?string $sortDirection = 'asc'
    ): array {
        if ($page < 1)
            $page = 1;
        if ($pageSize < 1 || $pageSize > 100)
            $pageSize = 20;
        if (!in_array($sortDirection, ['asc', 'desc']))
            $sortDirection = 'asc';

        return $this->fetchRolesFromDatabase($page, $pageSize, $search, $sort, $sortDirection);
    }

    private function fetchRolesFromDatabase(int $page, int $pageSize, ?string $search, ?string $sort, string $sortDirection): array
    {
        // Create cache key based on parameters
        $cacheKey = "roles_list_{$page}_{$pageSize}_" . md5(($search ?? '') . ($sort ?? '') . $sortDirection);

        return $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->getList(
                $cacheKey,
                function () use ($page, $pageSize, $search, $sort, $sortDirection) {
                    $qb = $this->entityManager->getRepository(Role::class)->createQueryBuilder('r');

                    // Apply search filter
                    if ($search) {
                        $qb->andWhere('(r.name LIKE :search OR r.description LIKE :search)')
                            ->setParameter('search', '%' . $search . '%');
                    }

                    // Apply sorting
                    $allowedSortFields = ['name', 'description'];
                    if ($sort && in_array($sort, $allowedSortFields)) {
                        $qb->orderBy('r.' . $sort, $sortDirection);
                    } else {
                        $qb->orderBy('r.name', 'asc');
                    }

                    // Get total count for pagination
                    $countQb = clone $qb;
                    $totalCount = $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

                    // Apply pagination
                    $qb->setFirstResult(($page - 1) * $pageSize)
                        ->setMaxResults($pageSize);

                    $roles = $qb->getQuery()->getResult();

                    return [
                        'roles' => array_map([$this, 'formatRoleForList'], $roles),
                        'pagination' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                            'totalCount' => (int) $totalCount,
                            'totalPages' => (int) ceil($totalCount / $pageSize),
                            'hasNext' => $page < ceil($totalCount / $pageSize),
                            'hasPrevious' => $page > 1
                        ]
                    ];
                }
            );
    }

    /**
     * Get single role by ID with full details including permissions and entity scope caching
     */
    public function getRoleById(int $roleId): array
    {
        $cacheKey = "role_{$roleId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId)
            ->getItem($cacheKey, function () use ($roleId) {
                $role = $this->entityManager->getRepository(Role::class)->find($roleId);
                if (!$role) {
                    throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
                }

                return $this->formatRoleForDetail($role);
            });
    }

    /**
     * Create new role
     */
    public function createRole(array $roleData): array
    {
        $this->entityManager->beginTransaction();

        try {
            $this->validateRoleData($roleData);

            $role = new Role();
            $role->setName($roleData['name']);
            $role->setDescription($roleData['description'] ?? null);

            $this->entityManager->persist($role);
            $this->entityManager->flush();

            // Add initial permissions if provided
            if (isset($roleData['permission_ids']) && is_array($roleData['permission_ids'])) {
                $this->addPermissionsToRoleInternal($role, $roleData['permission_ids']);
            }

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                $role,
                'Role created: ' . $role->getName()
            );

            $this->entityManager->commit();

            // Invalidate cache after create
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            return $this->formatRoleForDetail($role);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Update existing role
     */
    public function updateRole(int $roleId, array $roleData): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            if (isset($roleData['description'])) {
                $role->setDescription($roleData['description']);
            }

            $this->entityManager->flush();

            // Update permissions if provided
            if (isset($roleData['permission_ids']) && is_array($roleData['permission_ids'])) {
                $this->updateRolePermissionsInternal($role, $roleData['permission_ids']);
            }

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                $role,
                'Role updated: ' . $role->getName()
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific role
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            // If permissions were updated, also invalidate ALL users who have this role
            if (isset($roleData['permission_ids']) && is_array($roleData['permission_ids'])) {
                $this->invalidateUsersWithRole($roleId);
            }

            return $this->formatRoleForDetail($role);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Invalidate cache for all users who have a specific role
     *
     * When role permissions change, all users with that role need their cached
     * permissions, data access, and other role-dependent data refreshed.
     *
     * @param int $roleId The role ID whose users need cache invalidation
     */
    private function invalidateUsersWithRole(int $roleId): void
    {
        // Find all users who have this role
        $usersWithRole = $this->userRepository->findByRole($roleId);

        // Invalidate each user's cache to ensure they get fresh permissions/data
        foreach ($usersWithRole as $user) {
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $user->getId());
        }
    }

    /**
     * Delete role
     */
    public function deleteRole(int $roleId): void
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            // Check if role has users
            if (!$role->getUsers()->isEmpty()) {
                throw new ServiceException('Cannot delete role with assigned users', Response::HTTP_CONFLICT);
            }

            // Log transaction before deletion
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                $role,
                'Role deleted: ' . $role->getName()
            );

            $this->entityManager->remove($role);
            $this->entityManager->flush();

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific role
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            // CRITICAL: Also invalidate ALL users who HAD this role
            // When a role is deleted, all users who had that role lose those permissions
            $this->invalidateUsersWithRole($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Get role permissions with entity scope caching
     */
    public function getRolePermissions(int $roleId): array
    {
        $cacheKey = "role_permissions_{$roleId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId)
            ->getItem($cacheKey, function () use ($roleId) {
                $role = $this->entityManager->getRepository(Role::class)->find($roleId);
                if (!$role) {
                    throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
                }

                return array_map([$this, 'formatPermissionForResponse'], $role->getPermissions()->toArray());
            });
    }

    /**
     * Add permissions to role (bulk)
     */
    public function addPermissionsToRole(int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            $this->addPermissionsToRoleInternal($role, $permissionIds);

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Permissions added to role: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific role
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Remove permissions from role (bulk)
     */
    public function removePermissionsFromRole(int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            foreach ($permissionIds as $permissionId) {
                $permission = $this->entityManager->getRepository(Permission::class)->find($permissionId);
                if (!$permission) {
                    throw new ServiceException('Permission not found: ' . $permissionId, Response::HTTP_NOT_FOUND);
                }

                if ($role->getPermissions()->contains($permission)) {
                    $role->removePermission($permission);
                }
            }

            $this->entityManager->flush();

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Permissions removed from role: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific role
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Update role permissions (bulk replace)
     */
    public function updateRolePermissions(int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            $this->updateRolePermissionsInternal($role, $permissionIds);

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Role permissions updated: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific role
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            // CRITICAL: Also invalidate ALL users who have this role
            // When role permissions change, all users with this role need their cached permissions refreshed
            $this->invalidateUsersWithRole($roleId);

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Get all available permissions
     */
    public function getAllPermissions(): array
    {
        $cacheKey = "all_permissions";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->getList($cacheKey, function () {
                $permissions = $this->entityManager->getRepository(Permission::class)
                    ->createQueryBuilder('p')
                    ->orderBy('p.name', 'asc')
                    ->getQuery()
                    ->getResult();

                return array_map([$this, 'formatPermissionForResponse'], $permissions);
            });
    }

    /**
     * Format role for list view
     */
    private function formatRoleForList(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'permissions_count' => $role->getPermissions()->count(),
            'users_count' => $role->getUsers()->count()
        ];
    }

    /**
     * Format role for detail view
     */
    private function formatRoleForDetail(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'permissions_count' => $role->getPermissions()->count(),
            'users_count' => $role->getUsers()->count(),
            'permissions' => array_map([$this, 'formatPermissionForResponse'], $role->getPermissions()->toArray()),
            'users' => array_map(function ($user) {
                return [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName()
                ];
            }, $role->getUsers()->toArray())
        ];
    }

    /**
     * Format permission for response
     */
    private function formatPermissionForResponse(Permission $permission): array
    {
        return [
            'id' => $permission->getId(),
            'name' => $permission->getName(),
            'description' => $permission->getDescription()
        ];
    }

    /**
     * Validate role data
     */
    private function validateRoleData(array $data): void
    {
        if (empty($data['name'])) {
            throw new ServiceException('Role name is required', Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['name'])) {
            // Check for duplicate name
            $existingRole = $this->entityManager->getRepository(Role::class)
                ->findOneBy(['name' => $data['name']]);
            if ($existingRole) {
                throw new ServiceException('Role name already exists', Response::HTTP_CONFLICT);
            }
        }
    }

    /**
     * Internal method to add permissions to role (without transaction handling)
     */
    private function addPermissionsToRoleInternal(Role $role, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            $permission = $this->entityManager->getRepository(Permission::class)->find($permissionId);
            if (!$permission) {
                throw new ServiceException('Permission not found: ' . $permissionId, Response::HTTP_NOT_FOUND);
            }

            if (!$role->getPermissions()->contains($permission)) {
                $role->addPermission($permission);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Internal method to update role permissions (without transaction handling)
     */
    private function updateRolePermissionsInternal(Role $role, array $permissionIds): void
    {
        // Remove all existing permissions
        foreach ($role->getPermissions() as $permission) {
            $role->removePermission($permission);
        }

        // Add new permissions
        foreach ($permissionIds as $permissionId) {
            $permission = $this->entityManager->getRepository(Permission::class)->find($permissionId);
            if (!$permission) {
                throw new ServiceException('Permission not found: ' . $permissionId, Response::HTTP_NOT_FOUND);
            }

            $role->addPermission($permission);
        }

        $this->entityManager->flush();
    }

    /**
     * Get all API routes with their required permissions for the current user
     * This allows the frontend to check permissions before making API calls
     */
    public function getApiRoutesWithPermissions(): array
    {
        $cacheKey = "api_routes_with_permissions";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getList($cacheKey, function () {
                return $this->fetchApiRoutesWithPermissionsFromDatabase();
            });
    }

    /**
     * Fetch API routes with permissions from database
     */
    private function fetchApiRoutesWithPermissionsFromDatabase(): array
    {
        $sql = "
            SELECT
                ar.id,
                ar.route_name,
                ar.version,
                ar.path,
                ar.controller,
                ar.methods,
                ar.requirements,
                ar.params,
                GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ',') as required_permissions,
                GROUP_CONCAT(DISTINCT p.description ORDER BY p.name SEPARATOR '||') as permission_descriptions
            FROM api_routes ar
            LEFT JOIN api_routes_permissions arp ON ar.id = arp.id_api_routes
            LEFT JOIN permissions p ON arp.id_permissions = p.id
            WHERE ar.route_name NOT LIKE 'auth_%'  -- Exclude auth routes as they're handled separately
            GROUP BY ar.id, ar.route_name, ar.version, ar.path, ar.controller, ar.methods, ar.requirements, ar.params
            ORDER BY ar.route_name ASC
        ";

        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $result = $stmt->executeQuery();
        $rows = $result->fetchAllAssociative();

        $routes = [];
        foreach ($rows as $row) {
            $permissions = [];
            if (!empty($row['required_permissions'])) {
                $permissionNames = explode(',', $row['required_permissions']);
                $permissionDescriptions = explode('||', $row['permission_descriptions']);

                for ($i = 0; $i < count($permissionNames); $i++) {
                    $permissions[] = [
                        'name' => $permissionNames[$i],
                        'description' => $permissionDescriptions[$i] ?? null
                    ];
                }
            }

            $routes[] = [
                'id' => (int)$row['id'],
                'route_name' => $row['route_name'],
                'version' => $row['version'],
                'path' => $row['path'],
                'controller' => $row['controller'],
                'methods' => $row['methods'],
                'requirements' => $row['requirements'] ? json_decode($row['requirements'], true) : null,
                'params' => $row['params'] ? json_decode($row['params'], true) : null,
                'required_permissions' => $permissions
            ];
        }

        return $routes;
    }
}