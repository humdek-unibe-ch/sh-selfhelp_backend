<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\RoleDataAccess;
use App\Entity\Role;
use App\Entity\Lookup;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Security\CallerHoldingsService;
use App\Service\Security\DataAccessSecurityService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling data access permission operations in the admin panel
 * ENTITY RULE
 */
class AdminDataAccessService extends BaseService
{
    private const MSG_GIVE_RIGHTS = 'You can only give a role rights you hold yourself: ';
    private const MSG_CHANGE_OR_DELETE = 'You can only change or delete a role whose rights you all hold.';

    public function __construct(
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly UserRepository $userRepository,
        private readonly TransactionService $transactionService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly CallerHoldingsService $callerHoldings,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Add or update a custom permission to a role (upsert operation).
     *
     * @param bool $enforceHoldings When false, skips rule 4.6f (F14 creator grant
     *        on a brand-new resource the caller cannot yet hold). Admin UI paths
     *        and {@see setRolePermissions()} always enforce.
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources Resources the
     *        caller counts as holding in full for this write (an asset the caller uploads or
     *        manages). Unlike `$enforceHoldings = false`, the target role is still checked
     *        (rule 4.6f): only the bits on those resources are never missing.
     */
    public function addRolePermission(
        int $callerId,
        int $roleId,
        \stdClass $permissionData,
        bool $enforceHoldings = true,
        array $heldResources = []
    ): RoleDataAccess {
        return $this->executeInTransaction(function () use ($callerId, $roleId, $permissionData, $enforceHoldings, $heldResources) {
            $role = $this->requireRole($roleId);
            if ($enforceHoldings) {
                $this->assertMayMutateRole($callerId, $role, $heldResources);
            }

            $resourceTypeId = $this->asInt($permissionData->resource_type_id);
            $resourceId = $this->asInt($permissionData->resource_id);
            $crudPermissions = $this->asInt($permissionData->crud_permissions);

            if ($enforceHoldings) {
                $afterGrants = $this->mergeGrantIntoSnapshots(
                    $this->callerHoldings->roleGrantSnapshots($roleId),
                    $resourceTypeId,
                    $resourceId,
                    $crudPermissions
                );
                $this->assertMayGiveGrants($callerId, $afterGrants, $heldResources);
                $this->assertMayGiveRolePermissions($callerId, $role);
            }

            // Check if permission already exists
            $existingPermission = $this->roleDataAccessRepository->findPermission(
                $roleId,
                $resourceTypeId,
                $resourceId
            );

            if ($existingPermission) {
                // Update existing permission
                $existingPermission->setCrudPermissions($crudPermissions);
                $existingPermission->setUpdatedAt(new \DateTime());

                $this->entityManager->flush();

                // Invalidate caches
                $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

                // Log transaction as update
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'role_data_access',
                    $existingPermission->getId(),
                    true,
                    json_encode([[
                        'role_id' => $roleId,
                        'resource_type_id' => $resourceTypeId,
                        'resource_id' => $resourceId,
                        'crud_permissions' => $crudPermissions
                    ]]) ?: null,
                );

                return $existingPermission;
            }

            // Create new permission
            $permission = new RoleDataAccess();

            // Get Role and Lookup entities
            $resourceTypeLookup = $this->entityManager->getReference(Lookup::class, $resourceTypeId);
            assert($resourceTypeLookup instanceof Lookup);

            $permission->setRole($role);
            $permission->setResourceType($resourceTypeLookup);
            $permission->setResourceId($resourceId);
            $permission->setCrudPermissions($crudPermissions);

            $this->entityManager->persist($permission);
            $this->entityManager->flush();

            // Invalidate caches
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

            // Log transaction as insert
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'role_data_access',
                $permission->getId(),
                true,
                json_encode([[
                    'role_id' => $roleId,
                    'resource_type_id' => $resourceTypeId,
                    'resource_id' => $resourceId,
                    'crud_permissions' => $crudPermissions
                ]]) ?: null,
            );

            return $permission;
        });
    }

    /**
     * Update an existing permission for a role
     *
     * @param array<string, mixed> $permissionData
     */
    public function updateRolePermission(
        int $callerId,
        int $roleId,
        int $permissionId,
        array $permissionData,
        bool $enforceHoldings = true
    ): RoleDataAccess {
        return $this->executeInTransaction(function () use ($callerId, $roleId, $permissionId, $permissionData, $enforceHoldings) {
            $role = $this->requireRole($roleId);
            if ($enforceHoldings) {
                $this->assertMayMutateRole($callerId, $role);
            }

            // Find permission
            $permission = $this->roleDataAccessRepository->find($permissionId);

            if (!$permission || $permission->getRole()->getId() !== $roleId) {
                throw new ServiceException(
                    'Permission not found or does not belong to this role',
                    Response::HTTP_NOT_FOUND
                );
            }

            $crudPermissions = $this->asInt($permissionData['crud_permissions'] ?? 0);
            if ($enforceHoldings) {
                $snapshot = $this->callerHoldings->snapshotFromEntity($permission);
                $afterGrants = $this->mergeGrantIntoSnapshots(
                    $this->callerHoldings->roleGrantSnapshots($roleId),
                    $snapshot['resource_type_id'],
                    $snapshot['resource_id'],
                    $crudPermissions
                );
                $this->assertMayGiveGrants($callerId, $afterGrants);
                $this->assertMayGiveRolePermissions($callerId, $role);
            }

            // Update permission
            $permission->setCrudPermissions($crudPermissions);
            $permission->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Invalidate caches
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'role_data_access',
                $permission->getId(),
                true,
                json_encode([[
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'crud_permissions' => $crudPermissions
                ]]) ?: null,
            );

            return $permission;
        });
    }

    /**
     * Remove a permission from a role
     *
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources see {@see addRolePermission()}
     */
    public function deleteRolePermission(
        int $callerId,
        int $roleId,
        int $permissionId,
        bool $enforceHoldings = true,
        array $heldResources = []
    ): void {
        $this->executeInTransaction(function () use ($callerId, $roleId, $permissionId, $enforceHoldings, $heldResources) {
            $role = $this->requireRole($roleId);
            if ($enforceHoldings) {
                $this->assertMayMutateRole($callerId, $role, $heldResources);
            }

            // Find permission
            $permission = $this->roleDataAccessRepository->find($permissionId);

            if (!$permission || $permission->getRole()->getId() !== $roleId) {
                throw new ServiceException(
                    'Permission not found or does not belong to this role',
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($enforceHoldings) {
                $afterGrants = [];
                foreach ($this->callerHoldings->roleGrantSnapshots($roleId) as $grant) {
                    if ($grant['resource_type_id'] === (int) $permission->getResourceType()->getId()
                        && $grant['resource_id'] === $permission->getResourceId()
                    ) {
                        continue;
                    }
                    $afterGrants[] = $grant;
                }
                $this->assertMayGiveGrants($callerId, $afterGrants, $heldResources);
                $this->assertMayGiveRolePermissions($callerId, $role);
            }

            $this->entityManager->remove($permission);
            $this->entityManager->flush();

            // Invalidate caches
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'role_data_access',
                $permissionId,
                true,
                json_encode([[
                    'role_id' => $roleId,
                    'permission_id' => $permissionId
                ]]) ?: null,
            );
        });
    }

    /**
     * Set multiple permissions for a role (replaces all existing permissions)
     *
     * @param list<\stdClass> $permissions
     * @return array<string, int>
     */
    public function setRolePermissions(int $callerId, int $roleId, array $permissions): array
    {
        return $this->executeInTransaction(function () use ($callerId, $roleId, $permissions) {
            $role = $this->requireRole($roleId);
            $this->assertMayMutateRole($callerId, $role);

            $afterGrants = [];
            foreach ($permissions as $permissionData) {
                $resourceTypeId = $this->asInt($permissionData->resource_type_id);
                $resourceId = $this->asInt($permissionData->resource_id);
                $crudPermissions = $this->asInt($permissionData->crud_permissions);
                $resourceType = $this->entityManager->getRepository(Lookup::class)->find($resourceTypeId);
                if (!$resourceType instanceof Lookup) {
                    throw new ServiceException('Resource type not found: ' . $resourceTypeId, Response::HTTP_NOT_FOUND);
                }
                $afterGrants[] = [
                    'resource_type_id' => $resourceTypeId,
                    'resource_type_name' => (string) $resourceType->getLookupValue(),
                    'resource_id' => $resourceId,
                    'crud_permissions' => $crudPermissions,
                ];
            }
            $this->assertMayGiveGrants($callerId, $afterGrants);
            $this->assertMayGiveRolePermissions($callerId, $role);

            // Get existing permissions for this role
            $existingPermissions = $this->roleDataAccessRepository->getRolePermissions($roleId);

            // Create a map of existing permissions for easy lookup
            $existingMap = [];
            foreach ($existingPermissions as $existing) {
                $key = $existing->getIdResourceTypes() . '_' . $existing->getResourceId();
                $existingMap[$key] = $existing;
            }

            // Track what we want to keep/update
            $toKeep = [];
            $added = [];
            $updated = [];

            // Process new permissions
            foreach ($permissions as $permissionData) {
                $resourceTypeId = $this->asInt($permissionData->resource_type_id);
                $resourceId = $this->asInt($permissionData->resource_id);
                $crudPermissions = $this->asInt($permissionData->crud_permissions);
                $key = $resourceTypeId . '_' . $resourceId;

                if (isset($existingMap[$key])) {
                    // Permission exists, check if it needs updating
                    $existing = $existingMap[$key];
                    if ($existing->getCrudPermissions() !== $crudPermissions) {
                        $existing->setCrudPermissions($crudPermissions);
                        $existing->setUpdatedAt(new \DateTime());
                        $updated[] = $existing;

                        // Log update
                        $this->transactionService->logTransaction(
                            LookupService::TRANSACTION_TYPES_UPDATE,
                            LookupService::TRANSACTION_BY_BY_USER,
                            'role_data_access',
                            $existing->getId(),
                            true,
                            json_encode([[
                                'role_id' => $roleId,
                                'resource_type_id' => $resourceTypeId,
                                'resource_id' => $resourceId,
                                'crud_permissions' => $crudPermissions
                            ]]) ?: null
                        );
                    }
                    $toKeep[$key] = true;
                } else {
                    // Create new permission
                    $permission = new RoleDataAccess();
                    $role = $this->entityManager->getReference(Role::class, $roleId);
                    assert($role instanceof Role);
                    $resourceTypeLookup = $this->entityManager->getReference(Lookup::class, $resourceTypeId);
                    assert($resourceTypeLookup instanceof Lookup);

                    $permission->setRole($role);
                    $permission->setResourceType($resourceTypeLookup);
                    $permission->setResourceId($resourceId);
                    $permission->setCrudPermissions($crudPermissions);

                    $this->entityManager->persist($permission);
                    $added[] = $permission;

                    // Log creation
                    $this->transactionService->logTransaction(
                        LookupService::TRANSACTION_TYPES_INSERT,
                        LookupService::TRANSACTION_BY_BY_USER,
                        'role_data_access',
                        $permission->getId(),
                        true,
                        json_encode([[
                            'role_id' => $roleId,
                            'resource_type_id' => $resourceTypeId,
                            'resource_id' => $resourceId,
                            'crud_permissions' => $crudPermissions
                        ]]) ?: null
                    );

                    $toKeep[$key] = true;
                }
            }

            // Remove permissions that are no longer needed
            $removed = [];
            foreach ($existingMap as $key => $existing) {
                if (!isset($toKeep[$key])) {
                    $this->entityManager->remove($existing);
                    $removed[] = $existing;

                    // Log deletion
                    $this->transactionService->logTransaction(
                        LookupService::TRANSACTION_TYPES_DELETE,
                        LookupService::TRANSACTION_BY_BY_USER,
                        'role_data_access',
                        $existing->getId(),
                        true,
                        json_encode([[
                            'role_id' => $roleId,
                            'resource_type_id' => $existing->getIdResourceTypes(),
                            'resource_id' => $existing->getResourceId(),
                            'crud_permissions' => $existing->getCrudPermissions()
                        ]]) ?: null
                    );
                }
            }

            $this->entityManager->flush();

            // Invalidate caches
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

            return [
                'added' => count($added),
                'updated' => count($updated),
                'removed' => count($removed),
                'total' => count($permissions)
            ];
        });
    }

    /**
     * Get effective permissions for a role (permissions defined for the role)
     *
     * @return array<string, mixed>
     */
    public function getRoleEffectivePermissions(int $roleId): array
    {
        // Get users who have this role
        $users = $this->userRepository->findByRole($roleId);

        // Get permissions directly defined for this role
        $rolePermissions = $this->roleDataAccessRepository->getRolePermissions($roleId);

        // Format permissions for response
        $formattedPermissions = [];
        foreach ($rolePermissions as $permission) {
            $formattedPermissions[] = [
                'id_roles' => $permission->getIdRoles(),
                'role_name' => $permission->getRole()->getName(),
                'id_resource_types' => $permission->getIdResourceTypes(),
                'resource_id' => $permission->getResourceId(),
                'resource_type_name' => $permission->getResourceType()->getLookupValue(),
                'unified_permissions' => $permission->getCrudPermissions(),
                'individual_permissions' => (string)$permission->getCrudPermissions()
            ];
        }

        return [
            'role_id' => $roleId,
            'users_with_role' => count($users),
            'effective_permissions' => $formattedPermissions
        ];
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function executeInTransaction(callable $operation): mixed
    {
        $this->entityManager->beginTransaction();

        try {
            $result = $operation();
            $this->entityManager->commit();
            return $result;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    private function requireRole(int $roleId): Role
    {
        $role = $this->entityManager->getRepository(Role::class)->find($roleId);
        if (!$role instanceof Role) {
            throw new ServiceException('Role not found', Response::HTTP_NOT_FOUND);
        }

        return $role;
    }

    /**
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources
     */
    private function assertMayMutateRole(int $callerId, Role $role, array $heldResources = []): void
    {
        if ($this->callerHoldings->isSuperuser($callerId)) {
            return;
        }

        if ($role->isSuperuser() || !$this->callerHoldings->fullyHoldsRole($callerId, $role, $heldResources)) {
            throw new ServiceException(self::MSG_CHANGE_OR_DELETE, Response::HTTP_FORBIDDEN);
        }
    }

    private function assertMayGiveRolePermissions(int $callerId, Role $role): void
    {
        $names = [];
        foreach ($role->getPermissions() as $permission) {
            $names[] = $permission->getName();
        }
        $missing = $this->callerHoldings->missingPermissions($callerId, $names);
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
     * @param list<array{resource_type_id: int, resource_id: int}> $heldResources
     */
    private function assertMayGiveGrants(int $callerId, array $grants, array $heldResources = []): void
    {
        $missing = $this->callerHoldings->missingGrantLabels($callerId, $grants, $heldResources);
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
     * @return list<array{resource_type_id: int, resource_type_name: string, resource_id: int, crud_permissions: int}>
     */
    private function mergeGrantIntoSnapshots(
        array $grants,
        int $resourceTypeId,
        int $resourceId,
        int $crudPermissions
    ): array {
        $resourceType = $this->entityManager->getRepository(Lookup::class)->find($resourceTypeId);
        $typeName = $resourceType instanceof Lookup
            ? (string) $resourceType->getLookupValue()
            : (string) $resourceTypeId;

        $replaced = false;
        $result = [];
        foreach ($grants as $grant) {
            if ($grant['resource_type_id'] === $resourceTypeId && $grant['resource_id'] === $resourceId) {
                $result[] = [
                    'resource_type_id' => $resourceTypeId,
                    'resource_type_name' => $typeName,
                    'resource_id' => $resourceId,
                    'crud_permissions' => $crudPermissions,
                ];
                $replaced = true;
                continue;
            }
            $result[] = $grant;
        }
        if (!$replaced) {
            $result[] = [
                'resource_type_id' => $resourceTypeId,
                'resource_type_name' => $typeName,
                'resource_id' => $resourceId,
                'crud_permissions' => $crudPermissions,
            ];
        }

        return $result;
    }
}
