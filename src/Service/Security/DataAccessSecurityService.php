<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Security;

use App\Entity\DataAccessAudit;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Data Access Security Service
 *
 * Implements role-based data access control with audit logging
 * Security-first approach with deny-by-default model
 * Advanced caching with CacheService integration
 */
class DataAccessSecurityService
{
    // CRUD Permission Bit Flags
    public const PERMISSION_CREATE = 1;  // 0001
    public const PERMISSION_READ = 2;    // 0010
    public const PERMISSION_UPDATE = 4;  // 0100
    public const PERMISSION_DELETE = 8;  // 1000

    public function __construct(
        private RoleDataAccessRepository $roleDataAccessRepository,
        private UserRepository $userRepository,
        private LookupService $lookupService,
        private EntityManagerInterface $entityManager,
        private CacheService $cache,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {
    }








    /**
     * Check permissions for CREATE/UPDATE/DELETE operations
     *
     * @param int $userId User ID performing the operation
     * @param string $resourceType Resource type (group, data_table, pages)
     * @param int $resourceId Specific resource ID
     * @param int $requiredPermission Required permission bit flag
     * @return bool True if permission granted, false otherwise
     */
    public function hasPermission(int $userId, string $resourceType, int $resourceId, int $requiredPermission): bool
    {
        // Admin role has all permissions
        if ($this->userHasAdminRole($userId)) {
            $this->auditLog($userId, $resourceType, $resourceId, $this->getActionName($requiredPermission), LookupService::PERMISSION_RESULTS_GRANTED, $requiredPermission, 'Admin role override');
            return true;
        }

        return $this->hasStoredPermission($userId, $resourceType, $resourceId, $requiredPermission);
    }

    /**
     * Check permissions only through role_data_access, without role-name overrides.
     */
    public function hasStoredPermission(int $userId, string $resourceType, int $resourceId, int $requiredPermission): bool
    {
        // Get resource type ID
        $resourceTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);

        if (!$resourceTypeId) {
            $this->auditLog($userId, $resourceType, $resourceId, $this->getActionName($requiredPermission), LookupService::PERMISSION_RESULTS_DENIED, $requiredPermission, 'Invalid resource type');
            return false;
        }

        // Check specific resource permissions
        $permissions = $this->roleDataAccessRepository->getUserPermissionsForResource($userId, $resourceTypeId, $resourceId);

        // Check if user has the required permission (bitwise AND)
        $hasPermission = $permissions !== null && ($permissions & $requiredPermission) === $requiredPermission;

        $this->auditLog(
            $userId,
            $resourceType,
            $resourceId,
            $this->getActionName($requiredPermission),
            $hasPermission ? LookupService::PERMISSION_RESULTS_GRANTED : LookupService::PERMISSION_RESULTS_DENIED,
            $requiredPermission,
            $hasPermission ? 'Permission granted' : 'Insufficient permissions'
        );

        return $hasPermission;
    }




    /**
     * Log permission check to audit table
     * Uses its own transaction to ensure audit logs are committed even if main operations fail
     */
    private function auditLog(?int $userId, string $resourceType, int $resourceId, string $action, string $result, ?int $permission, ?string $notes = null): void
    {
        // Skip audit logging if no user ID (not authenticated)
        if ($userId === null) {
            return;
        }

        // Resolve the three mandatory FK lookups (resource type, action, result)
        // BEFORE touching the database. data_access_audits requires
        // id_resource_types / id_audit_actions / id_permission_results to be
        // NOT NULL, so when any of them cannot be resolved there is no valid row
        // to write — for example a denial on an unknown resource type, which has
        // no entry in the resourceTypes lookup. Persisting anyway would raise an
        // "id_resource_types cannot be null" integrity error that the catch below
        // would swallow invisibly. Record a warning and skip instead.
        $resourceTypeLookup = $this->lookupService->findByTypeAndCode(LookupService::RESOURCE_TYPES, $resourceType);
        $actionLookup = $this->lookupService->findByTypeAndCode(LookupService::AUDIT_ACTIONS, $action);
        $resultLookup = $this->lookupService->findByTypeAndCode(LookupService::PERMISSION_RESULTS, $result);

        if ($resourceTypeLookup === null || $actionLookup === null || $resultLookup === null) {
            $this->logger->warning('Skipping data access audit: unresolved lookup(s); the referenced resource type/action/result has no lookup entry.', [
                'resource_type' => $resourceType,
                'resource_type_resolved' => $resourceTypeLookup !== null,
                'action' => $action,
                'action_resolved' => $actionLookup !== null,
                'result' => $result,
                'result_resolved' => $resultLookup !== null,
            ]);

            return;
        }

        // Start audit logging transaction - separate from main operation transactions
        $this->entityManager->beginTransaction();

        try {
            // Create and save audit log entry with all mandatory FKs populated.
            $audit = new DataAccessAudit();

            $user = $this->entityManager->getReference(User::class, $userId);
            assert($user instanceof User);
            $audit->setUser($user);
            $audit->setResourceType($resourceTypeLookup);
            $audit->setResourceId($resourceId);
            $audit->setAction($actionLookup);
            $audit->setPermissionResult($resultLookup);
            $audit->setCrudPermission($permission);
            $audit->setHttpMethod($this->getHttpMethod());
            $audit->setRequestBodyHash($this->getRequestBodyHash());
            $audit->setIpAddress($this->getClientIp());
            $audit->setUserAgent($this->getUserAgent());
            $audit->setRequestUri($this->getRequestUri());
            $audit->setNotes($notes);

            $this->entityManager->persist($audit);
            $this->entityManager->flush(); // Ensure audit is committed immediately

            // Commit the audit transaction
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            // Roll back the audit transaction on an unexpected error, but never
            // fail the main operation because of an audit-write problem.
            try {
                $this->entityManager->rollback();
            } catch (\Throwable $rollbackException) {
                $this->logger->error('Failed to roll back data access audit transaction', ['exception' => $rollbackException]);
            }

            $this->logger->error('Failed to log data access audit', ['exception' => $e]);
        }
    }

    /**
     * Get action name from permission bit
     */
    private function getActionName(int $permission): string
    {
        return match ($permission) {
            self::PERMISSION_CREATE => LookupService::AUDIT_ACTIONS_CREATE,
            self::PERMISSION_READ => LookupService::AUDIT_ACTIONS_READ,
            self::PERMISSION_UPDATE => LookupService::AUDIT_ACTIONS_UPDATE,
            self::PERMISSION_DELETE => LookupService::AUDIT_ACTIONS_DELETE,
            default => 'unknown'
        };
    }

    /**
     * Check if user has admin role
     */
    public function userHasAdminRole(int $userId): bool
    {
        // Check cache first
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem("user_has_admin_role", function () use ($userId) {
                // Use Doctrine QueryBuilder to check if user has admin role
                $qb = $this->entityManager->createQueryBuilder();
                $count = $qb->select('COUNT(r.id)')
                    ->from(User::class, 'u')
                    ->innerJoin('u.roles', 'r')
                    ->where('u.id = :userId')
                    ->andWhere('r.name = :adminRole')
                    ->setParameter('userId', $userId)
                    ->setParameter('adminRole', 'admin')
                    ->getQuery()
                    ->getSingleScalarResult();

                return (int) $count > 0;
            });
    }

    /**
     * Cache invalidation methods using CacheService entity scopes
     */
    public function invalidateUserPermissions(int $userId): void
    {
        // Clear all permission caches for a user using entity scope invalidation (O(1))
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
    }

    public function invalidateRolePermissions(int $roleId): void
    {
        // Clear role permission caches using entity scope invalidation
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);

        // Also invalidate user permissions for all users who have this role
        // since their permissions depend on role permissions
        $usersWithRole = $this->userRepository->findByRole($roleId);
        foreach ($usersWithRole as $user) {
            $this->invalidateUserPermissions((int) $user->getId());
        }
    }



    /**
     * Helper methods for audit logging
     */
    private function getHttpMethod(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request ? $request->getMethod() : null;
    }

    private function getRequestBodyHash(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        $body = $request->getContent();
        return $body ? hash('sha256', $body) : null;
    }

    private function getClientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        return $request->getClientIp();
    }

    private function getUserAgent(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request ? $request->headers->get('User-Agent') : null;
    }

    private function getRequestUri(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request ? $request->getRequestUri() : null;
    }

}
