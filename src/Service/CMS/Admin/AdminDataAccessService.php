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
    public function __construct(
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly UserRepository $userRepository,
        private readonly TransactionService $transactionService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Add or update a custom permission to a role (upsert operation)
     */
    public function addRolePermission(int $roleId, \stdClass $permissionData): RoleDataAccess
    {
        return $this->executeInTransaction(function () use ($roleId, $permissionData) {
            $resourceTypeId = $this->asInt($permissionData->resource_type_id);
            $resourceId = $this->asInt($permissionData->resource_id);
            $crudPermissions = $this->asInt($permissionData->crud_permissions);

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
            $role = $this->entityManager->getReference(Role::class, $roleId);
            assert($role instanceof Role);
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
    public function updateRolePermission(int $roleId, int $permissionId, array $permissionData): RoleDataAccess
    {
        return $this->executeInTransaction(function () use ($roleId, $permissionId, $permissionData) {
            // Find permission
            $permission = $this->roleDataAccessRepository->find($permissionId);

            if (!$permission || $permission->getIdRoles() !== $roleId) {
                throw new ServiceException(
                    'Permission not found or does not belong to this role',
                    Response::HTTP_NOT_FOUND
                );
            }

            $crudPermissions = $this->asInt($permissionData['crud_permissions'] ?? 0);

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
     */
    public function deleteRolePermission(int $roleId, int $permissionId): void
    {
        $this->executeInTransaction(function () use ($roleId, $permissionId) {
            // Find permission
            $permission = $this->roleDataAccessRepository->find($permissionId);

            if (!$permission || $permission->getIdRoles() !== $roleId) {
                throw new ServiceException(
                    'Permission not found or does not belong to this role',
                    Response::HTTP_NOT_FOUND
                );
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
    public function setRolePermissions(int $roleId, array $permissions): array
    {
        return $this->executeInTransaction(function () use ($roleId, $permissions) {
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
     * Execute operation within a database transaction
     */
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
}
