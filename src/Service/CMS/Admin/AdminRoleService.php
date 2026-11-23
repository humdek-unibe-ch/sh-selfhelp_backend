<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Role;
use App\Entity\Permission;
use App\Service\Sync\ChangeNoticeService;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\CallerHoldingsService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleService extends BaseService
{
    private const MSG_GIVE_RIGHTS = 'You can only give a role rights you hold yourself: ';
    private const MSG_CHANGE_OR_DELETE = 'You can only change or delete a role whose rights you all hold.';
    private const MSG_NEEDS_PERMISSIONS = 'Changing a role\'s permissions needs admin.role.permissions.';
    private const PERM_ROLE_PERMISSIONS = 'admin.role.permissions';
    private const PERM_ROLE_UPDATE = 'admin.role.update';
    private const PERM_ROLE_DELETE = 'admin.role.delete';

    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly CallerHoldingsService $callerHoldings,
        private readonly ChangeNoticeService $notices,
    ) {
    }

    /**
     * Get roles with pagination, search, and sorting
     *
     * @return array<string, mixed>
     */
    public function getRoles(
        int $callerId,
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        ?string $sort = null,
        ?string $sortDirection = 'asc'
    ): array {
        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'asc';

        $result = $this->fetchRolesFromDatabase($page, $pageSize, $search, $sort, $sortDirection);
        $roles = [];
        foreach ($this->asArray($result['roles'] ?? null) as $role) {
            $roles[] = $this->withCallerRoleFlags($this->asAssocArray($role), $callerId);
        }
        $result['roles'] = $roles;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRolesFromDatabase(int $page, int $pageSize, ?string $search, ?string $sort, string $sortDirection): array
    {
        $cacheKey = "roles_list_{$page}_{$pageSize}_" . md5(($search ?? '') . ($sort ?? '') . $sortDirection);

        return $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->getList(
                $cacheKey,
                function () use ($page, $pageSize, $search, $sort, $sortDirection) {
                    $qb = $this->entityManager->getRepository(Role::class)->createQueryBuilder('r');

                    $qb->leftJoin('r.permissions', 'p')
                    ->leftJoin('r.users', 'u')
                    ->addSelect('COUNT(DISTINCT p.id) AS HIDDEN permissions_count')
                    ->addSelect('COUNT(DISTINCT u.id) AS HIDDEN users_count')
                    ->groupBy('r.id');

                    if ($search) {
                        $qb->andWhere('(r.name LIKE :search OR r.description LIKE :search)')
                            ->setParameter('search', '%' . $search . '%');
                    }

                    $allowedEntityFields = ['id', 'name', 'description'];
                    $allowedAliasFields = ['permissions_count', 'users_count'];

                    if ($sort && in_array($sort, $allowedEntityFields)) {
                        $qb->orderBy('r.' . $sort, $sortDirection);
                    } elseif ($sort && in_array($sort, $allowedAliasFields)) {
                        $qb->orderBy($sort, $sortDirection);
                    } else {
                        $qb->orderBy('r.name', 'asc');
                    }

                    $countQb = $this->entityManager->getRepository(Role::class)->createQueryBuilder('r');
                    if ($search) {
                        $countQb->andWhere('(r.name LIKE :search OR r.description LIKE :search)')
                            ->setParameter('search', '%' . $search . '%');
                    }
                    $totalCount = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

                    $qb->setFirstResult(($page - 1) * $pageSize)
                        ->setMaxResults($pageSize);

                    /** @var list<Role> $roles */
                    $roles = $qb->getQuery()->getResult();

                    return [
                        'roles' => array_map([$this, 'formatRoleForList'], $roles),
                        'pagination' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                            'totalCount' => $totalCount,
                            'totalPages' => (int) ceil($totalCount / $pageSize),
                            'hasNext' => $page < ceil($totalCount / $pageSize),
                            'hasPrevious' => $page > 1
                        ]
                    ];
                }
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRoleById(int $callerId, int $roleId): array
    {
        $cached = $this->fetchRoleDetailCached($roleId);

        return $this->withCallerRoleFlags($cached, $callerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRoleDetailCached(int $roleId): array
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
     * @param array<string, mixed> $roleData
     * @return array<string, mixed>
     */
    public function createRole(int $callerId, array $roleData): array
    {
        $this->entityManager->beginTransaction();

        try {
            if (array_key_exists('is_superuser', $roleData)) {
                throw new ServiceException(
                    'The is_superuser flag cannot be changed through the API.',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $this->validateRoleData($roleData);

            $permissionIds = null;
            if (isset($roleData['permission_ids']) && is_array($roleData['permission_ids'])) {
                $this->assertMayChangePermissionList($callerId);
                $permissionIds = $this->normalizePermissionIdsForWrite(
                    $this->toIntIdList($roleData['permission_ids']),
                    [],
                    false
                );
                $this->assertMayGivePermissions($callerId, $this->namesOfPermissionIds($permissionIds));
            }

            $role = new Role();
            $role->setName($this->asString($roleData['name']));
            $role->setDescription($this->asStringOrNull($roleData['description'] ?? null));

            $this->entityManager->persist($role);
            $this->entityManager->flush();

            if ($permissionIds !== null) {
                $this->replaceRolePermissionsInternal($role, $permissionIds);
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                $role,
                'Role created: ' . $role->getName()
            );

            $this->entityManager->commit();

            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            return $this->withCallerRoleFlags($this->formatRoleForDetail($role), $callerId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $roleData
     * @return array<string, mixed>
     */
    public function updateRole(int $callerId, int $roleId, array $roleData): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            if (array_key_exists('is_superuser', $roleData)) {
                throw new ServiceException(
                    'The is_superuser flag cannot be changed through the API.',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $this->assertMayMutateRole($callerId, $role);

            $changingPermissions = isset($roleData['permission_ids']) && is_array($roleData['permission_ids']);
            if ($changingPermissions) {
                $this->assertMayChangePermissionList($callerId);
            }

            if (isset($roleData['name'])) {
                $newName = $this->asString($roleData['name']);
                if ($newName !== $role->getName()) {
                    $existing = $this->entityManager->getRepository(Role::class)->findOneBy(['name' => $newName]);
                    if ($existing instanceof Role && $existing->getId() !== $role->getId()) {
                        throw new ServiceException('Role name already exists', Response::HTTP_CONFLICT);
                    }
                    $role->setName($newName);
                }
            }

            if (isset($roleData['description'])) {
                $role->setDescription($this->asStringOrNull($roleData['description']));
            }

            $this->entityManager->flush();

            if ($changingPermissions) {
                $normalized = $this->normalizePermissionIdsForWrite(
                    $this->toIntIdList($roleData['permission_ids']),
                    $this->currentPermissionIds($role),
                    true
                );
                $this->assertMayGivePermissions($callerId, $this->namesOfPermissionIds($normalized));
                $this->assertMayGiveGrants($callerId, $this->callerHoldings->roleGrantSnapshots($roleId));
                $this->replaceRolePermissionsInternal($role, $normalized);
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                $role,
                'Role updated: ' . $role->getName()
            );

            $this->entityManager->commit();

            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();

            if ($changingPermissions) {
                $this->invalidateUsersWithRole($roleId);
            }

            return $this->withCallerRoleFlags($this->formatRoleForDetail($role), $callerId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * After a role's permissions changed (committed): clear the per-user caches
     * of its holders and tell the role (live sync).
     *
     * Each holder's user cache scope is still cleared (a cache rule). What no
     * longer happens is a `users.acl_version` bump and a write per holder: the
     * role's own `acl_version` was bumped in the transaction and one notice goes
     * to the role topic.
     *
     * @param int $roleId
     */
    private function invalidateUsersWithRole(int $roleId): void
    {
        $holderIds = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id_users FROM rel_roles_users WHERE id_roles = :role',
            ['role' => $roleId],
            ['role' => \Doctrine\DBAL\ParameterType::INTEGER],
        ));

        if ($holderIds !== []) {
            $this->cache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_USER, $holderIds);
        }

        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();

        $this->notices->roleAclChanged($roleId);
    }

    public function deleteRole(int $callerId, int $roleId): void
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            if ($role->isSuperuser()) {
                throw new ServiceException(
                    'This role holds full access and cannot be deleted.',
                    Response::HTTP_CONFLICT
                );
            }

            if (!$role->getUsers()->isEmpty()) {
                throw new ServiceException('Cannot delete role with assigned users', Response::HTTP_CONFLICT);
            }

            $this->assertMayMutateRole($callerId, $role);

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

            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
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
     * @param list<int> $permissionIds
     * @return array<int, array<string, mixed>>
     */
    public function addPermissionsToRole(int $callerId, int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            $this->assertMayMutateRole($callerId, $role);

            $merged = array_values(array_unique(array_merge(
                $this->currentPermissionIds($role),
                $permissionIds
            )));
            $normalized = $this->normalizePermissionIdsForWrite($merged, $this->currentPermissionIds($role), false);
            $this->assertMayGivePermissions($callerId, $this->namesOfPermissionIds($normalized));
            $this->assertMayGiveGrants($callerId, $this->callerHoldings->roleGrantSnapshots($roleId));

            $this->replaceRolePermissionsInternal($role, $normalized);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Permissions added to role: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();
            $this->invalidateUsersWithRole($roleId);

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * @param list<int> $permissionIds
     * @return array<int, array<string, mixed>>
     */
    public function removePermissionsFromRole(int $callerId, int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            $this->assertMayMutateRole($callerId, $role);

            $removeIds = $this->expandReadRemovals($role, $permissionIds);
            $remaining = array_values(array_diff($this->currentPermissionIds($role), $removeIds));
            $normalized = $this->normalizePermissionIdsForWrite($remaining, $this->currentPermissionIds($role), false);
            $this->assertMayGivePermissions($callerId, $this->namesOfPermissionIds($normalized));
            $this->assertMayGiveGrants($callerId, $this->callerHoldings->roleGrantSnapshots($roleId));

            $this->replaceRolePermissionsInternal($role, $normalized);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Permissions removed from role: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();
            $this->invalidateUsersWithRole($roleId);

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * @param list<int> $permissionIds
     * @return array<int, array<string, mixed>>
     */
    public function updateRolePermissions(int $callerId, int $roleId, array $permissionIds): array
    {
        $this->entityManager->beginTransaction();

        try {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if (!$role) {
                throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
            }

            $this->assertMayMutateRole($callerId, $role);

            $normalized = $this->normalizePermissionIdsForWrite(
                $permissionIds,
                $this->currentPermissionIds($role),
                true
            );
            $this->assertMayGivePermissions($callerId, $this->namesOfPermissionIds($normalized));
            $this->assertMayGiveGrants($callerId, $this->callerHoldings->roleGrantSnapshots($roleId));

            $this->replaceRolePermissionsInternal($role, $normalized);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'roles',
                $role->getId(),
                false,
                'Role permissions updated: ' . $role->getName() . ' (Permission IDs: ' . implode(', ', $permissionIds) . ')'
            );

            $this->entityManager->commit();

            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_ROLES)
                ->invalidateAllListsInCategory();
            $this->invalidateUsersWithRole($roleId);

            return $this->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllPermissions(int $callerId): array
    {
        $cached = $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->getList('all_permissions', function () {
                /** @var list<Permission> $permissions */
                $permissions = $this->entityManager->getRepository(Permission::class)
                    ->createQueryBuilder('p')
                    ->orderBy('p.name', 'asc')
                    ->getQuery()
                    ->getResult();

                return array_map([$this, 'formatPermissionForResponse'], $permissions);
            });

        $result = [];
        foreach ($cached as $permission) {
            $name = $this->asString($permission['name'] ?? '');
            $result[] = array_merge($permission, [
                'can_assign' => $this->callerHoldings->holdsPermission($callerId, $name),
                'implied_read' => $this->callerHoldings->impliedReadOf($name),
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRoleForList(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'is_superuser' => $role->isSuperuser(),
            'permissions_count' => $role->getPermissions()->count(),
            'users_count' => $role->getUsers()->count()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRoleForDetail(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'is_superuser' => $role->isSuperuser(),
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
     * @return array<string, mixed>
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
     * C3 flags after the shared cache read (rule 3 reading 8).
     *
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private function withCallerRoleFlags(array $role, int $callerId): array
    {
        $roleId = $this->asInt($role['id'] ?? 0);
        $entity = $this->entityManager->getRepository(Role::class)->find($roleId);
        $usersCount = $this->asInt($role['users_count'] ?? 0);
        $isSuperuserRole = (bool) ($role['is_superuser'] ?? false);

        $fullyHeld = $entity instanceof Role
            ? $this->callerHoldings->fullyHoldsRole($callerId, $entity)
            : false;

        $canUpdate = $fullyHeld && $this->callerHoldings->holdsPermission($callerId, self::PERM_ROLE_UPDATE);
        $canDelete = $fullyHeld
            && !$isSuperuserRole
            && $usersCount === 0
            && $this->callerHoldings->holdsPermission($callerId, self::PERM_ROLE_DELETE);

        $role['can_update'] = $canUpdate;
        $role['can_delete'] = $canDelete;
        // F219: lets pickers (asset managing roles) show which roles the caller may hand out.
        $role['fully_held'] = $fullyHeld;

        return $role;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateRoleData(array $data): void
    {
        if (empty($data['name'])) {
            throw new ServiceException('Role name is required', Response::HTTP_BAD_REQUEST);
        }

        $existingRole = $this->entityManager->getRepository(Role::class)
            ->findOneBy(['name' => $data['name']]);
        if ($existingRole) {
            throw new ServiceException('Role name already exists', Response::HTTP_CONFLICT);
        }
    }

    private function assertMayChangePermissionList(int $callerId): void
    {
        if (!$this->callerHoldings->holdsPermission($callerId, self::PERM_ROLE_PERMISSIONS)) {
            throw new ServiceException(self::MSG_NEEDS_PERMISSIONS, Response::HTTP_FORBIDDEN);
        }
    }

    private function assertMayMutateRole(int $callerId, Role $role): void
    {
        if ($this->callerHoldings->isSuperuser($callerId)) {
            return;
        }

        if ($role->isSuperuser() || !$this->callerHoldings->fullyHoldsRole($callerId, $role)) {
            throw new ServiceException(self::MSG_CHANGE_OR_DELETE, Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @param list<string> $permissionNames
     */
    private function assertMayGivePermissions(int $callerId, array $permissionNames): void
    {
        $missing = $this->callerHoldings->missingPermissions($callerId, $permissionNames);
        if ($missing === []) {
            return;
        }

        throw new ServiceException(
            self::MSG_GIVE_RIGHTS . implode(', ', $missing),
            Response::HTTP_FORBIDDEN
        );
    }

    /**
     * @param list<array{resource_type_id: int, resource_type_name: string, resource_id: int, crud_permissions: int}> $grants
     */
    private function assertMayGiveGrants(int $callerId, array $grants): void
    {
        $missing = $this->callerHoldings->missingGrantLabels($callerId, $grants);
        if ($missing === []) {
            return;
        }

        throw new ServiceException(
            self::MSG_GIVE_RIGHTS . implode(', ', $missing),
            Response::HTTP_FORBIDDEN
        );
    }

    /**
     * C2 normaliser: add implied reads; for a replace list that keeps area
     * rights, implied read wins over an omitted `.read`.
     *
     * @param list<int> $permissionIds
     * @param list<int> $previousIds unused for add/create; kept for clarity at call sites
     * @return list<int>
     */
    private function normalizePermissionIdsForWrite(array $permissionIds, array $previousIds, bool $isReplace): array
    {
        unset($previousIds, $isReplace);

        $ids = [];
        foreach ($permissionIds as $id) {
            $permission = $this->callerHoldings->findPermissionById($id);
            if (!$permission instanceof Permission) {
                throw new ServiceException('Permission not found: ' . $id, Response::HTTP_NOT_FOUND);
            }
            $ids[$id] = true;
            $implied = $this->callerHoldings->impliedReadOf($permission->getName());
            if ($implied !== null) {
                $impliedPermission = $this->callerHoldings->findPermissionByName($implied);
                if ($impliedPermission instanceof Permission && $impliedPermission->getId() !== null) {
                    $ids[(int) $impliedPermission->getId()] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * When removing an area's `.read`, also remove every other `admin.<area>.*`
     * right currently on the role.
     *
     * @param list<int> $permissionIds
     * @return list<int>
     */
    private function expandReadRemovals(Role $role, array $permissionIds): array
    {
        $remove = [];
        foreach ($permissionIds as $id) {
            $remove[$id] = true;
        }

        $areasToClear = [];
        foreach ($permissionIds as $id) {
            $permission = $this->callerHoldings->findPermissionById($id);
            if (!$permission instanceof Permission) {
                throw new ServiceException('Permission not found: ' . $id, Response::HTTP_NOT_FOUND);
            }
            $parts = explode('.', $permission->getName());
            if (count($parts) === 3 && $parts[0] === 'admin' && $parts[2] === 'read') {
                $areasToClear[$parts[1]] = true;
            }
        }

        if ($areasToClear !== []) {
            foreach ($role->getPermissions() as $held) {
                $parts = explode('.', $held->getName());
                if (count($parts) === 3 && $parts[0] === 'admin' && isset($areasToClear[$parts[1]]) && $held->getId() !== null) {
                    $remove[(int) $held->getId()] = true;
                }
            }
        }

        return array_map('intval', array_keys($remove));
    }

    /**
     * @return list<int>
     */
    private function currentPermissionIds(Role $role): array
    {
        $ids = [];
        foreach ($role->getPermissions() as $permission) {
            if ($permission->getId() !== null) {
                $ids[] = (int) $permission->getId();
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $permissionIds
     * @return list<string>
     */
    private function namesOfPermissionIds(array $permissionIds): array
    {
        $names = [];
        foreach ($permissionIds as $id) {
            $permission = $this->callerHoldings->findPermissionById($id);
            if ($permission instanceof Permission) {
                $names[] = $permission->getName();
            }
        }

        return $names;
    }

    /**
     * @param list<int> $permissionIds
     */
    private function replaceRolePermissionsInternal(Role $role, array $permissionIds): void
    {
        foreach ($role->getPermissions() as $permission) {
            $role->removePermission($permission);
        }

        foreach ($permissionIds as $permissionId) {
            $permission = $this->callerHoldings->findPermissionById($permissionId);
            if (!$permission instanceof Permission) {
                throw new ServiceException('Permission not found: ' . $permissionId, Response::HTTP_NOT_FOUND);
            }
            $role->addPermission($permission);
        }

        // One bump for the whole role (live sync): holders notice through the
        // access stamp, not through a write per holder. Same transaction as the change.
        $role->bumpAclVersion();

        $this->entityManager->flush();
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private function toIntIdList(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[] = $this->asInt($id);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
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
     * @return list<array<string, mixed>>
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
            LEFT JOIN rel_api_routes_permissions arp ON ar.id = arp.id_api_routes
            LEFT JOIN permissions p ON arp.id_permissions = p.id
            WHERE ar.route_name NOT LIKE 'auth_%'
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
                $permissionNames = explode(',', $this->asString($row['required_permissions']));
                $permissionDescriptions = explode('||', $this->asString($row['permission_descriptions']));

                for ($i = 0; $i < count($permissionNames); $i++) {
                    $permissions[] = [
                        'name' => $permissionNames[$i],
                        'description' => $permissionDescriptions[$i] ?? null
                    ];
                }
            }

            $routes[] = [
                'id' => $this->asInt($row['id']),
                'route_name' => $row['route_name'],
                'version' => $row['version'],
                'path' => $row['path'],
                'controller' => $row['controller'],
                'methods' => $row['methods'],
                'requirements' => $row['requirements'] ? json_decode($this->asString($row['requirements']), true) : null,
                'params' => $row['params'] ? json_decode($this->asString($row['params']), true) : null,
                'required_permissions' => $permissions
            ];
        }

        return $routes;
    }
}
