<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\User;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\CMS\UserPermissionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a caller holds for rule 4.6f: their permission names and, per resource,
 * the OR of grant bits across their roles. Loaded once per user id per request.
 * The superuser holds everything.
 */
final class CallerHoldingsService
{
    /**
     * @var array<int, array{
     *     superuser: bool,
     *     permissions: array<string, true>,
     *     grants: array<string, int>
     * }>
     */
    private array $byUser = [];

    /** @var array<string, true>|null */
    private ?array $allPermissionNames = null;

    /** @var array<int, Permission>|null */
    private ?array $permissionsById = null;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPermissionService $userPermissionService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isSuperuser(int $userId): bool
    {
        return $this->holdings($userId)['superuser'];
    }

    public function holdsPermission(int $userId, string $permissionName): bool
    {
        $holdings = $this->holdings($userId);
        if ($holdings['superuser']) {
            return true;
        }

        return isset($holdings['permissions'][$permissionName]);
    }

    /**
     * @param list<string> $permissionNames
     * @return list<string> names the caller does not hold
     */
    public function missingPermissions(int $userId, array $permissionNames): array
    {
        if ($this->isSuperuser($userId)) {
            return [];
        }

        $held = $this->holdings($userId)['permissions'];
        $missing = [];
        foreach (array_values(array_unique($permissionNames)) as $name) {
            if ($name !== '' && !isset($held[$name])) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Bits the caller holds on a resource (0 if none). Superuser is treated as
     * holding every bit the caller asks about via {@see holdsGrantBits()}.
     */
    public function grantBits(int $userId, int $resourceTypeId, int $resourceId): int
    {
        $holdings = $this->holdings($userId);
        if ($holdings['superuser']) {
            return DataAccessSecurityService::PERMISSION_CREATE
                | DataAccessSecurityService::PERMISSION_READ
                | DataAccessSecurityService::PERMISSION_UPDATE
                | DataAccessSecurityService::PERMISSION_DELETE;
        }

        return $holdings['grants'][$this->grantKey($resourceTypeId, $resourceId)] ?? 0;
    }

    public function holdsGrantBits(int $userId, int $resourceTypeId, int $resourceId, int $requiredBits): bool
    {
        if ($requiredBits === 0) {
            return true;
        }
        if ($this->isSuperuser($userId)) {
            return true;
        }

        $held = $this->grantBits($userId, $resourceTypeId, $resourceId);

        return ($held & $requiredBits) === $requiredBits;
    }

    /**
     * Missing grant descriptions for C4 ("resource type and id").
     *
     * @param list<array{resource_type_id: int, resource_type_name: string, resource_id: int, crud_permissions: int}> $grants
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources resources the caller counts as holding in full
     *        for this check (an asset the caller uploads or manages, task 2026-11-19-role-scope fix 1); their bits are never missing
     * @return list<string>
     */
    public function missingGrantLabels(int $userId, array $grants, array $heldResources = []): array
    {
        if ($this->isSuperuser($userId)) {
            return [];
        }

        $missing = [];
        foreach ($grants as $grant) {
            $bits = $grant['crud_permissions'];
            if ($bits === 0) {
                continue;
            }
            if (self::isHeldResource($grant['resource_type_id'], $grant['resource_id'], $heldResources)) {
                continue;
            }
            if ($this->holdsGrantBits($userId, $grant['resource_type_id'], $grant['resource_id'], $bits)) {
                continue;
            }
            $missing[] = $grant['resource_type_name'] . ' ' . $grant['resource_id'];
        }

        return array_values(array_unique($missing));
    }

    /**
     * Whether the caller holds every permission and every grant bit of the role.
     * The superuser role is never fully held by a non-superuser.
     *
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources see {@see missingGrantLabels()}
     */
    public function fullyHoldsRole(int $userId, Role $role, array $heldResources = []): bool
    {
        if ($this->isSuperuser($userId)) {
            return true;
        }
        if ($role->isSuperuser()) {
            return false;
        }

        $permissionNames = [];
        foreach ($role->getPermissions() as $permission) {
            $permissionNames[] = $permission->getName();
        }
        if ($this->missingPermissions($userId, $permissionNames) !== []) {
            return false;
        }

        $roleId = $role->getId();
        if ($roleId === null) {
            return true;
        }

        return $this->missingGrantLabels($userId, $this->roleGrantSnapshots($roleId), $heldResources) === [];
    }

    /**
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources
     */
    private static function isHeldResource(int $resourceTypeId, int $resourceId, array $heldResources): bool
    {
        foreach ($heldResources as $held) {
            if ($held['resource_type_id'] === $resourceTypeId && $held['resource_id'] === $resourceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{resource_type_id: int, resource_type_name: string, resource_id: int, crud_permissions: int}>
     */
    public function roleGrantSnapshots(int $roleId): array
    {
        $snapshots = [];
        foreach ($this->roleDataAccessRepository->getRolePermissions($roleId) as $row) {
            $snapshots[] = $this->snapshotFromEntity($row);
        }

        return $snapshots;
    }

    /**
     * @param RoleDataAccess $row
     * @return array{resource_type_id: int, resource_type_name: string, resource_id: int, crud_permissions: int}
     */
    public function snapshotFromEntity(RoleDataAccess $row): array
    {
        // F80: read associations, not getIdRoles() / getIdResourceTypes() on a
        // grant created in the same request.
        $resourceType = $row->getResourceType();

        return [
            'resource_type_id' => (int) $resourceType->getId(),
            'resource_type_name' => (string) $resourceType->getLookupValue(),
            'resource_id' => $row->getResourceId(),
            'crud_permissions' => $row->getCrudPermissions(),
        ];
    }

    /**
     * Implied read of a core admin permission (C2), or null.
     */
    public function impliedReadOf(string $permissionName): ?string
    {
        $parts = explode('.', $permissionName);
        if (count($parts) !== 3 || $parts[0] !== 'admin' || $parts[2] === 'read') {
            return null;
        }

        $readName = $parts[0] . '.' . $parts[1] . '.read';
        $all = $this->allPermissionNameSet();

        return isset($all[$readName]) ? $readName : null;
    }

    public function findPermissionById(int $id): ?Permission
    {
        $this->ensurePermissionMaps();

        return $this->permissionsById[$id] ?? null;
    }

    public function findPermissionByName(string $name): ?Permission
    {
        $this->ensurePermissionMaps();
        foreach ($this->permissionsById ?? [] as $permission) {
            if ($permission->getName() === $name) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    public function allPermissionNameSet(): array
    {
        $this->ensurePermissionMaps();

        return $this->allPermissionNames ?? [];
    }

    /**
     * @return array{superuser: bool, permissions: array<string, true>, grants: array<string, int>}
     */
    private function holdings(int $userId): array
    {
        if (isset($this->byUser[$userId])) {
            return $this->byUser[$userId];
        }

        if ($this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return $this->byUser[$userId] = [
                'superuser' => true,
                'permissions' => [],
                'grants' => [],
            ];
        }

        $user = $this->userRepository->find($userId);
        $permissionSet = [];
        if ($user instanceof User) {
            foreach ($this->userPermissionService->getUserPermissions($user) as $name) {
                $permissionSet[$name] = true;
            }
        }

        $grants = [];
        $rows = $this->entityManager->createQueryBuilder()
            ->select('rda.resourceId AS resource_id, IDENTITY(rda.resourceType) AS resource_type_id, rda.crudPermissions AS crud')
            ->from(RoleDataAccess::class, 'rda')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $typeRaw = $row['resource_type_id'] ?? null;
            $resourceRaw = $row['resource_id'] ?? null;
            $crudRaw = $row['crud'] ?? null;
            if (!is_numeric($typeRaw) || !is_numeric($resourceRaw) || !is_numeric($crudRaw)) {
                continue;
            }
            $typeId = (int) $typeRaw;
            $resourceId = (int) $resourceRaw;
            $key = $this->grantKey($typeId, $resourceId);
            $grants[$key] = ($grants[$key] ?? 0) | (int) $crudRaw;
        }

        return $this->byUser[$userId] = [
            'superuser' => false,
            'permissions' => $permissionSet,
            'grants' => $grants,
        ];
    }

    private function grantKey(int $resourceTypeId, int $resourceId): string
    {
        return $resourceTypeId . ':' . $resourceId;
    }

    private function ensurePermissionMaps(): void
    {
        if ($this->permissionsById !== null && $this->allPermissionNames !== null) {
            return;
        }

        /** @var list<Permission> $permissions */
        $permissions = $this->entityManager->getRepository(Permission::class)
            ->createQueryBuilder('p')
            ->getQuery()
            ->getResult();

        $byId = [];
        $names = [];
        foreach ($permissions as $permission) {
            $id = $permission->getId();
            if ($id === null) {
                continue;
            }
            $byId[$id] = $permission;
            $names[$permission->getName()] = true;
        }

        $this->permissionsById = $byId;
        $this->allPermissionNames = $names;
    }
}
