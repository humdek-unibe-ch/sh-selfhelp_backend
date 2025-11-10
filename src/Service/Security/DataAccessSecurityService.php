<?php

namespace App\Service\Security;

use App\Entity\DataAccessAudit;
use App\Entity\User;
use App\Repository\DataAccessAuditRepository;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
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
        private DataAccessAuditRepository $auditRepository,
        private RoleDataAccessRepository $roleDataAccessRepository,
        private UserRepository $userRepository,
        private LookupService $lookupService,
        private EntityManagerInterface $entityManager,
        private CacheService $cache,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Filter data for READ operations with advanced caching
     *
     * @param callable $dataFetcher Function that returns the full dataset
     * @param int $userId User ID performing the operation
     * @param string $resourceType Resource type (group, data_table, pages)
     * @return array Filtered data or empty array if no permissions
     */
    public function filterData(callable $dataFetcher, int $userId, string $resourceType): array
    {
        // Check admin role first - no caching needed for admin
        if ($this->userHasAdminRole($userId)) {
            $this->auditLog($userId, $resourceType, 0, LookupService::AUDIT_ACTIONS_FILTER, LookupService::PERMISSION_RESULTS_GRANTED, null, 'Admin role override');
            return $dataFetcher(); // Full access
        }

        // Get resource type ID for entity scoping
        $resourceTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);

        if (!$resourceTypeId) {
            $this->auditLog($userId, $resourceType, 0, LookupService::AUDIT_ACTIONS_FILTER, LookupService::PERMISSION_RESULTS_DENIED, null, 'Invalid resource type');
            return []; // Invalid resource type
        }

        // Check unified permissions cache with entity scopes
        $permissions = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId)
            ->getItem("unified_permissions_{$resourceType}", function() use ($userId, $resourceTypeId) {
                return $this->roleDataAccessRepository->getUserPermissionsForResourceType($userId, $resourceTypeId);
            });

        if (empty($permissions)) {
            $this->auditLog($userId, $resourceType, 0, LookupService::AUDIT_ACTIONS_FILTER, LookupService::PERMISSION_RESULTS_DENIED, null, 'No permissions found');
            return []; // No access
        }

        $this->auditLog($userId, $resourceType, 0, LookupService::AUDIT_ACTIONS_FILTER, LookupService::PERMISSION_RESULTS_GRANTED, null, 'Custom filter applied');
        return $this->applyFilters($dataFetcher(), $permissions, $resourceType);
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
     * Apply permission-based filtering to data collections dynamically
     *
     * This method provides global filtering capability for different resource types:
     * - pages: filters by 'id_pages', 'id', or 'page_id' fields
     * - data_tables: filters by 'table_id' or 'id' fields
     * - groups: filters by 'id_groups', 'group_id', or 'id' fields
     *
     * Also sets ACL fields (acl_select, acl_insert, acl_update, acl_delete) based on actual permissions
     *
     * @param array $data Raw data collection to filter
     * @param array $permissions User permissions array with resource_id and unified_permissions
     * @param string $resourceType Resource type (pages, data_table, group)
     * @return array Filtered data collection containing only accessible items with proper ACL fields
     */
    private function applyFilters(array $data, array $permissions, string $resourceType): array
    {
        if (empty($permissions)) {
            return [];
        }

        // Convert permissions array to resource ID => permissions map
        $permissionMap = [];
        foreach ($permissions as $permission) {
            $permissionMap[$permission['resource_id']] = $permission['unified_permissions'];
        }

        $filteredData = [];
        foreach ($data as $item) {
            $resourceId = $this->extractResourceId($item, $resourceType);

            // Check if user has READ permission for this resource
            if (isset($permissionMap[$resourceId]) && ($permissionMap[$resourceId] & self::PERMISSION_READ) === self::PERMISSION_READ) {
                // Set ACL fields based on actual permissions
                $item['acl_select'] = ($permissionMap[$resourceId] & self::PERMISSION_READ) ? 1 : 0;
                $item['acl_insert'] = ($permissionMap[$resourceId] & self::PERMISSION_CREATE) ? 1 : 0;
                $item['acl_update'] = ($permissionMap[$resourceId] & self::PERMISSION_UPDATE) ? 1 : 0;
                $item['acl_delete'] = ($permissionMap[$resourceId] & self::PERMISSION_DELETE) ? 1 : 0;

                $filteredData[] = $item;
            }
        }

        return $filteredData;
    }

    /**
     * Check if a data item matches user permissions
     *
     * @param mixed $item Data item to check
     * @param array $permissionMap Resource ID to permissions mapping
     * @param string $resourceType Resource type for filtering logic
     * @return bool True if item should be included
     */
    private function itemMatchesPermissions($item, array $permissionMap, string $resourceType): bool
    {
        // Extract resource ID based on resource type
        $resourceId = $this->extractResourceId($item, $resourceType);

        // If resource ID is in permission map and has READ permission, include it
        return isset($permissionMap[$resourceId]) && ($permissionMap[$resourceId] & self::PERMISSION_READ) === self::PERMISSION_READ;
    }

    /**
     * Extract resource ID from data item based on resource type
     *
     * Dynamically handles different data structures for various resource types:
     * - pages: 'id_pages', 'id', 'page_id' fields
     * - data_tables: 'table_id', 'id' fields
     * - groups: 'id_groups', 'group_id', 'id' fields
     *
     * @param mixed $item Data item with varying field structures
     * @param string $resourceType Resource type (pages, data_table, group)
     * @return int Resource ID, 0 if not found
     */
    private function extractResourceId($item, string $resourceType): int
    {
        // Handle different data structures based on resource type
        switch ($resourceType) {
            case LookupService::RESOURCE_TYPES_GROUP:
                // For users, extract group ID from user data
                return $item['id_groups'] ?? $item['group_id'] ?? $item['id'] ?? 0;

            case LookupService::RESOURCE_TYPES_DATA_TABLE:
                // For data tables, the resource ID is the table ID
                return $item['id_dataTables'] ?? $item['id'] ?? 0;

            case LookupService::RESOURCE_TYPES_PAGES:
                // For pages, the resource ID can be in different fields depending on data source
                return $item['id_pages'] ?? $item['id'] ?? $item['page_id'] ?? 0;

            default:
                return 0;
        }
    }

    /**
     * Log permission check to audit table
     * Uses its own transaction to ensure audit logs are committed even if main operations fail
     */
    private function auditLog(?int $userId, string $resourceType, int $resourceId, string $action, string $result, ?int $permission, string $notes = null): void
    {
        // Skip audit logging if no user ID (not authenticated)
        if ($userId === null) {
            return;
        }

        // Start audit logging transaction - separate from main operation transactions
        $this->entityManager->beginTransaction();

        try {
            // Get lookup objects for action, result, and resource type
            $actionLookup = $this->lookupService->findByTypeAndCode(LookupService::AUDIT_ACTIONS, $action);
            $resultLookup = $this->lookupService->findByTypeAndCode(LookupService::PERMISSION_RESULTS, $result);
            $resourceTypeLookup = $this->lookupService->findByTypeAndCode(LookupService::RESOURCE_TYPES, $resourceType);

            if (!$actionLookup || !$resultLookup || !$resourceTypeLookup) {
                // Fallback if lookups not found - still log with error note
                $notes = ($notes ? $notes . ' | ' : '') . 'LOOKUP_ERROR: Missing lookup objects';
            }

            // Create and save audit log entry
            $audit = new DataAccessAudit();

            // Set user entity reference
            $user = $this->entityManager->getReference(User::class, $userId);
            $audit->setUser($user);

            // Set lookup relationships
            if ($resourceTypeLookup) {
                $audit->setResourceType($resourceTypeLookup);
            }
            $audit->setResourceId($resourceId);

            if ($actionLookup) {
                $audit->setAction($actionLookup);
            }

            if ($resultLookup) {
                $audit->setPermissionResult($resultLookup);
            }
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
        } catch (\Exception $e) {
            // Rollback audit transaction on error, but don't fail the main operation
            try {
                $this->entityManager->rollback();
            } catch (\Exception $rollbackException) {
                // Log rollback error but continue
                error_log('Failed to rollback audit transaction: ' . $rollbackException->getMessage());
            }

            // Log the original error
            error_log('Failed to log data access audit: ' . $e->getMessage());
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
    private function userHasAdminRole(int $userId): bool
    {
        // Check cache first
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem("user_has_admin_role", function() use ($userId) {
                // Query to check if user has admin role
                $conn = $this->entityManager->getConnection();
                $sql = "
                    SELECT COUNT(*) as count
                    FROM users_roles ur
                    INNER JOIN roles r ON ur.id_roles = r.id
                    WHERE ur.id_users = :user_id AND r.name = 'admin'
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
                $result = $stmt->executeQuery();
                $row = $result->fetchAssociative();

                return ($row['count'] ?? 0) > 0;
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
            $this->invalidateUserPermissions($user->getId());
        }
    }

    public function invalidateResourceTypePermissions(int $resourceTypeId): void
    {
        // Clear all caches for a specific resource type
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId);
    }

    public function invalidateAllPermissions(): void
    {
        // Nuclear option - clear entire permissions category
        $this->cache->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateCategory();
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
