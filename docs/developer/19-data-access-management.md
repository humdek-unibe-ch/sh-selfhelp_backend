# Data Access Management System

## 🔍 Overview

The SelfHelp Symfony Backend implements a **Data Access Management (DAM)** system that provides **fine-grained, role-based data access control** with comprehensive auditing capabilities. This system complements the existing ACL system by providing customizable permissions at the data level rather than just page-level access.

## 🏗️ System Architecture

The DAM system consists of three interconnected components:

1. **Role Data Access**: Custom permission definitions per role
2. **Security Service**: Runtime permission enforcement with caching
3. **Audit Logging**: Complete audit trail of all permission checks

```mermaid
graph TD
    A[API Request] --> B[DataAccessSecurityService.filterData()]
    B --> C[Check Role Permissions]
    C --> D{Cache Hit?}
    D -->|Yes| E[Return Cached Permissions]
    D -->|No| F[Query role_data_access Table]
    F --> G[BIT_OR Aggregate Permissions]
    G --> H[Cache Result]
    H --> E
    E --> I[Apply Data Filtering]
    I --> J[Audit Log Access]
    J --> K[Return Filtered Data]

    L[CRUD Operations] --> M[DataAccessSecurityService.hasPermission()]
    M --> N[Check Specific Resource Permission]
    N --> O{Audit & Return Result}

    P[Admin Operations] --> Q[AdminDataAccessController]
    Q --> R[AdminDataAccessService]
    R --> S[RoleDataAccess Entity]
    S --> T[Transaction Logging]
```

## 🔧 How DAM Differs from ACL

| Aspect | ACL System | Data Access Management |
|--------|------------|----------------------|
| **Scope** | Page-level permissions | Data-level filtering & CRUD |
| **Users** | Frontend website users | Admin/CMS users |
| **Granularity** | Page access (select/insert/update/delete) | Resource-specific permissions |
| **Logic** | User > Group permissions | Role aggregation (BIT_OR) |
| **Tables** | `acl_users`, `acl_groups` | `role_data_access`, `dataAccessAudit` |
| **Caching** | Stored procedure based | Advanced CacheService integration |

## 🗄️ Database Schema

### Role Data Access Table
```sql
CREATE TABLE role_data_access (
  id int NOT NULL AUTO_INCREMENT,
  id_roles int NOT NULL,
  id_resourceTypes int NOT NULL,
  resource_id int NOT NULL,
  crud_permissions smallint unsigned NOT NULL DEFAULT '2' COMMENT 'Bitwise: 1=CREATE, 2=READ, 4=UPDATE, 8=DELETE',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_role_resource (id_roles, id_resourceTypes, resource_id),
  KEY IDX_role_data_access_roles (id_roles),
  KEY IDX_role_data_access_resource_types (id_resourceTypes),
  KEY IDX_role_data_access_resource_id (resource_id),
  KEY IDX_role_data_access_permissions (crud_permissions),
  CONSTRAINT FK_role_data_access_roles FOREIGN KEY (id_roles) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT FK_role_data_access_resource_types FOREIGN KEY (id_resourceTypes) REFERENCES lookups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Data Access Audit Table
```sql
CREATE TABLE dataAccessAudit (
  id int NOT NULL AUTO_INCREMENT,
  id_users int NOT NULL,
  id_resourceTypes int NOT NULL,
  resource_id int NOT NULL,
  id_actions int NOT NULL,
  id_permissionResults int NOT NULL,
  crud_permission smallint unsigned DEFAULT NULL,
  http_method varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  request_body_hash varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ip_address varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  user_agent text COLLATE utf8mb4_unicode_ci,
  request_uri text COLLATE utf8mb4_unicode_ci,
  notes text COLLATE utf8mb4_unicode_ci,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY IDX_dataAccessAudit_users (id_users),
  KEY IDX_dataAccessAudit_resource_types (id_resourceTypes),
  KEY IDX_dataAccessAudit_resource_id (resource_id),
  KEY IDX_dataAccessAudit_created_at (created_at),
  KEY IDX_dataAccessAudit_permission_results (id_permissionResults),
  KEY IDX_dataAccessAudit_http_method (http_method),
  KEY IDX_dataAccessAudit_request_body_hash (request_body_hash),
  CONSTRAINT FK_dataAccessAudit_users FOREIGN KEY (id_users) REFERENCES users (id),
  CONSTRAINT FK_dataAccessAudit_resource_types FOREIGN KEY (id_resourceTypes) REFERENCES lookups (id),
  CONSTRAINT FK_dataAccessAudit_actions FOREIGN KEY (id_actions) REFERENCES lookups (id),
  CONSTRAINT FK_dataAccessAudit_permission_results FOREIGN KEY (id_permissionResults) REFERENCES lookups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 🔐 CRUD Permission System

### Bitwise Permission Flags
```php
const PERMISSION_CREATE = 1;  // 0001 - Can create new records
const PERMISSION_READ = 2;    // 0010 - Can read/view data (default)
const PERMISSION_UPDATE = 4;  // 0100 - Can modify existing records
const PERMISSION_DELETE = 8;  // 1000 - Can delete records
```

### Permission Combinations
```php
// Read-only access
$permissions = 2; // PERMISSION_READ

// Full CRUD access
$permissions = 15; // CREATE | READ | UPDATE | DELETE

// Create and Read only
$permissions = 3; // CREATE | READ

// Update and Delete only
$permissions = 12; // UPDATE | DELETE
```

### Permission Checking Logic
```php
// Check if user has specific permission
$hasReadPermission = ($userPermissions & PERMISSION_READ) === PERMISSION_READ;

// Check if user has any of multiple permissions
$hasWritePermission = ($userPermissions & (PERMISSION_CREATE | PERMISSION_UPDATE | PERMISSION_DELETE)) > 0;
```

## 📊 Core Entities

### RoleDataAccess Entity
```php
<?php
namespace App\Entity;

#[ORM\Entity]
#[ORM\Table(name: 'role_data_access')]
class RoleDataAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'id_roles', type: Types::INTEGER)]
    private int $idRoles;

    #[ORM\Column(name: 'id_resourceTypes', type: Types::INTEGER)]
    private int $idResourceTypes;

    #[ORM\Column(name: 'resource_id', type: Types::INTEGER)]
    private int $resourceId;

    #[ORM\Column(name: 'crud_permissions', type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 2])]
    private int $crudPermissions = 2; // Default to read-only

    // Relationships and helper methods...
    public function hasPermission(int $permission): bool
    {
        return ($this->crudPermissions & $permission) === $permission;
    }

    public function addPermission(int $permission): self
    {
        $this->crudPermissions |= $permission;
        return $this;
    }

    public function removePermission(int $permission): self
    {
        $this->crudPermissions &= ~$permission;
        return $this;
    }
}
// ENTITY RULE
```

### DataAccessAudit Entity
```php
<?php
namespace App\Entity;

#[ORM\Entity]
#[ORM\Table(name: 'dataAccessAudit')]
class DataAccessAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    // User, resource, action tracking fields...
    #[ORM\Column(name: 'id_users', type: Types::INTEGER)]
    private int $idUsers;

    #[ORM\Column(name: 'id_resourceTypes', type: Types::INTEGER)]
    private int $idResourceTypes;

    #[ORM\Column(name: 'resource_id', type: Types::INTEGER)]
    private int $resourceId;

    #[ORM\Column(name: 'crud_permission', type: Types::SMALLINT, nullable: true)]
    private ?int $crudPermission = null;

    // HTTP request tracking for security auditing...
    #[ORM\Column(name: 'http_method', type: Types::STRING, length: 10, nullable: true)]
    private ?string $httpMethod = null;

    #[ORM\Column(name: 'request_body_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $requestBodyHash = null;

    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    // Relationships and additional metadata...
}
// ENTITY RULE
```

## 🔧 Service Architecture

### DataAccessSecurityService Hybrid Filtering Approach

The `DataAccessSecurityService` implements a **hybrid filtering strategy** that optimizes performance by using different filtering mechanisms based on resource types and data complexity.

#### Hybrid Filtering Strategy

The `filterData()` method routes to appropriate filtering strategies based on resource type:

```php
public function filterData(?callable $dataFetcher, int $userId, string $resourceType): array
{
    // Check admin role first - no caching needed for admin
    $isAdmin = $this->userHasAdminRole($userId);

    // Route to appropriate filtering strategy based on resource type
    return match ($resourceType) {
        LookupService::RESOURCE_TYPES_PAGES => $this->filterPagesWithSql($userId, $isAdmin),
        LookupService::RESOURCE_TYPES_DATA_TABLE => $this->filterDataTablesWithSql($userId, $isAdmin),
        default => $this->filterWithPhpLogic($dataFetcher, $userId, $resourceType, $isAdmin)
    };
}
```

#### SQL-Based Filtering (Pages & Data Tables)

**When Used:**
- `RESOURCE_TYPES_PAGES` - Page management operations
- `RESOURCE_TYPES_DATA_TABLE` - Data table access operations

**Why SQL-Based:**
- Direct permission resources with simple relationships
- More efficient than fetching all records and filtering in PHP
- Reduces memory usage and improves performance for large datasets
- Leverages database joins for optimal query execution

**Implementation:**
```php
private function filterPagesWithSql(int $userId, bool $isAdmin): array
{
    if ($isAdmin) {
        // Admin gets all pages with full permissions
        $pages = $this->roleDataAccessRepository->getAllPagesWithFullPermissions();
    } else {
        // Regular users get filtered pages based on permissions
        $pages = $this->roleDataAccessRepository->getAccessiblePagesForUser($userId, $resourceTypeId);
    }

    $this->auditLog($userId, LookupService::RESOURCE_TYPES_PAGES, 0, LookupService::AUDIT_ACTIONS_FILTER,
        LookupService::PERMISSION_RESULTS_GRANTED, null, 'SQL-based page filtering applied');
    return $pages;
}
```

**How to Handle Further:**
- Results already include `crud` field with unified permissions
- No additional PHP filtering needed
- Ready for direct API response formatting
- Supports hierarchical page structures (parent/child relationships)

#### PHP-Based Filtering (Groups & Complex Relationships)

**When Used:**
- `RESOURCE_TYPES_GROUP` - User and group management
- Any resource type not optimized for SQL filtering
- Complex relationships requiring PHP logic

**Why PHP-Based:**
- Complex relationships (users belong to groups, nested permissions)
- Dynamic data structures that vary by resource type
- Need for flexible filtering logic and data transformation
- Better suited for business logic that can't be expressed in SQL

**Implementation:**
```php
private function filterWithPhpLogic(?callable $dataFetcher, int $userId, string $resourceType, bool $isAdmin): array
{
    if ($isAdmin) {
        // Admin gets full access - requires dataFetcher for admin case
        $data = $dataFetcher();
        $this->addCrudFieldRecursively($data, 15); // Full permissions
        return $data;
    }

    // Get permissions with advanced caching
    $permissions = $this->cache->withCategory(CacheService::CATEGORY_PERMISSIONS)
        ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
        ->getItem("unified_permissions_{$resourceType}", function () use ($userId, $resourceTypeId) {
            return $this->roleDataAccessRepository->getUserPermissionsForResourceType($userId, $resourceTypeId);
        });

    if (empty($permissions)) {
        return []; // No access
    }

    // Apply PHP-based filtering logic
    return $this->applyFilters($dataFetcher(), $permissions, $resourceType);
}
```

**How to Handle Further:**
- Results need additional processing by `applyFilters()` method
- `crud` field is set based on actual user permissions for each item
- Hierarchical data (with `children` arrays) gets recursive permission setting
- Supports complex data structures and field mappings
- Ready for frontend consumption with proper ACL indicators

#### Permission Field Mapping Strategy

The system dynamically extracts resource IDs and applies permissions based on data structure:

**Resource ID Extraction:**
```php
private function extractResourceId($item, string $resourceType): int
{
    return match ($resourceType) {
        LookupService::RESOURCE_TYPES_GROUP => $item['id_groups'] ?? $item['group_id'] ?? $item['id'] ?? 0,
        LookupService::RESOURCE_TYPES_DATA_TABLE => $item['id_dataTables'] ?? $item['id'] ?? 0,
        LookupService::RESOURCE_TYPES_PAGES => $item['id_pages'] ?? $item['id'] ?? $item['page_id'] ?? 0,
        default => 0
    };
}
```

**Permission Application:**
- READ permission required for item inclusion
- `crud` field set to unified permission value (bitwise combination)
- Recursive application for hierarchical data structures
- Maintains original data structure while adding security metadata

### DataAccessSecurityService Core Methods

```php
<?php
namespace App\Service\Security;

class DataAccessSecurityService
{
    // Permission constants
    public const PERMISSION_CREATE = 1;
    public const PERMISSION_READ = 2;
    public const PERMISSION_UPDATE = 4;
    public const PERMISSION_DELETE = 8;

    /**
     * Filter data for READ operations with hybrid strategy
     */
    public function filterData(?callable $dataFetcher, int $userId, string $resourceType): array
    {
        // Admin override check
        if ($this->userHasAdminRole($userId)) {
            return $dataFetcher(); // Full access
        }

        // Route to appropriate filtering strategy based on resource type
        return match ($resourceType) {
            LookupService::RESOURCE_TYPES_PAGES => $this->filterPagesWithSql($userId, $isAdmin),
            LookupService::RESOURCE_TYPES_DATA_TABLE => $this->filterDataTablesWithSql($userId, $isAdmin),
            default => $this->filterWithPhpLogic($dataFetcher, $userId, $resourceType, $isAdmin)
        };
    }

    /**
     * Check permissions for CREATE/UPDATE/DELETE operations
     */
    public function hasPermission(int $userId, string $resourceType, int $resourceId, int $requiredPermission): bool
    {
        // Admin override
        if ($this->userHasAdminRole($userId)) {
            return true;
        }

        // Get specific resource permissions
        $permissions = $this->roleDataAccessRepository->getUserPermissionsForResource($userId, $resourceTypeId, $resourceId);

        // Check bitwise permission
        $hasPermission = $permissions !== null && ($permissions & $requiredPermission) === $requiredPermission;

        // Audit the check
        $this->auditLog($userId, $resourceType, $resourceId, $this->getActionName($requiredPermission),
            $hasPermission ? 'granted' : 'denied', $requiredPermission);

        return $hasPermission;
    }

```

### Dynamic Data Filtering

The `applyFilters()` method provides **global, dynamic filtering** capability across different resource types with varying data structures:

**Resource Type Field Mapping:**
- **Pages**: `id_pages` → `id` → `page_id`
- **Data Tables**: `id_dataTables` → `id`
- **Groups**: `id_groups` → `group_id` → `id`

**Permission Map Structure:**
```php
$permissionMap = [
    1 => 2,  // Resource ID 1 has READ permission (2)
    2 => 0,  // Resource ID 2 has no permissions
    3 => 6,  // Resource ID 3 has READ+UPDATE (2+4=6)
];
```

**ACL Field Setting:**
The method automatically sets ACL fields based on actual permissions:
```php
// For a resource with permission value 6 (READ+UPDATE)
'acl_select' => 1,  // Has READ permission
'acl_insert' => 0,  // No CREATE permission
'acl_update' => 1,  // Has UPDATE permission
'acl_delete' => 0   // No DELETE permission
```

**Data Structure Compatibility:**
- Automatically detects resource IDs from various field names
- Works with hierarchical data (pages with children)
- Sets ACL fields based on actual user permissions
- Maintains original data structure while filtering
- Supports all resource types through unified interface

### AdminDataAccessService
```php
<?php
namespace App\Service\CMS\Admin;

class AdminDataAccessService extends BaseService
{
    /**
     * Set multiple permissions for a role (bulk operation)
     */
    public function setRolePermissions(int $roleId, array $permissions): array
    {
        return $this->executeInTransaction(function () use ($roleId, $permissions) {
            // Get existing permissions
            $existingPermissions = $this->roleDataAccessRepository->getRolePermissions($roleId);

            // Create permission maps for efficient lookup
            $existingMap = [];
            foreach ($existingPermissions as $existing) {
                $key = $existing->getIdResourceTypes() . '_' . $existing->getResourceId();
                $existingMap[$key] = $existing;
            }

            // Process new permissions: add, update, or remove as needed
            $toKeep = [];
            $added = [];
            $updated = [];
            $removed = [];

            foreach ($permissions as $permissionData) {
                $key = $permissionData->resource_type_id . '_' . $permissionData->resource_id;

                if (isset($existingMap[$key])) {
                    // Update existing permission if changed
                    $existing = $existingMap[$key];
                    if ($existing->getCrudPermissions() !== $permissionData->crud_permissions) {
                        $existing->setCrudPermissions($permissionData->crud_permissions);
                        $existing->setUpdatedAt(new \DateTime());
                        $updated[] = $existing;

                        // Transaction logging for updates
                        $this->transactionService->logTransaction('update', 'by_user', 'role_data_access', $existing->getId(), true, $data);
                    }
                    $toKeep[$key] = true;
                } else {
                    // Create new permission entry
                    $permission = new RoleDataAccess();
                    $role = $this->entityManager->getReference(Role::class, $roleId);
                    $resourceTypeLookup = $this->entityManager->getReference(Lookup::class, $permissionData->resource_type_id);

                    $permission->setRole($role);
                    $permission->setResourceType($resourceTypeLookup);
                    $permission->setResourceId($permissionData->resource_id);
                    $permission->setCrudPermissions($permissionData->crud_permissions);

                    $this->entityManager->persist($permission);
                    $added[] = $permission;

                    // Transaction logging for new permissions
                    $this->transactionService->logTransaction('insert', 'by_user', 'role_data_access', $permission->getId(), true, $data);

                    $toKeep[$key] = true;
                }
            }

            // Remove permissions no longer needed
            foreach ($existingMap as $key => $existing) {
                if (!isset($toKeep[$key])) {
                    $this->entityManager->remove($existing);
                    $removed[] = $existing;

                    // Transaction logging for deletions
                    $this->transactionService->logTransaction('delete', 'by_user', 'role_data_access', $existing->getId(), true, $data);
                }
            }

            $this->entityManager->flush();

            // Invalidate permission caches
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);

            return [
                'added' => count($added),
                'updated' => count($updated),
                'removed' => count($removed),
                'total' => count($permissions)
            ];
        });
    }
}
```

## 🌐 API Endpoints

### Admin Data Access Controller
```php
<?php
namespace App\Controller\Api\V1\Admin;

class AdminDataAccessController extends AbstractController
{
    /**
     * GET /admin/data-access/roles
     * Get all roles with their custom data access permissions
     */
    public function getRolesWithPermissions(): JsonResponse
    {
        $rolesWithPermissions = $this->roleDataAccessRepository->getAllRolesWithPermissions();

        // Group by role for better structure
        $grouped = [];
        foreach ($rolesWithPermissions as $row) {
            $roleId = $row['role_id'];
            if (!isset($grouped[$roleId])) {
                $grouped[$roleId] = [
                    'role_id' => $row['role_id'],
                    'role_name' => $row['role_name'],
                    'permissions' => []
                ];
            }

            if ($row['id_resourceTypes']) {
                $grouped[$roleId]['permissions'][] = [
                    'resource_type_id' => $row['id_resourceTypes'],
                    'resource_type_name' => $row['resource_type_name'],
                    'resource_id' => $row['resource_id'],
                    'crud_permissions' => $row['crud_permissions']
                ];
            }
        }

        return $this->responseFormatter->formatSuccess(array_values($grouped));
    }

    /**
     * PUT /admin/data-access/roles/{roleId}/permissions
     * Set all permissions for a role (bulk operation)
     */
    public function setRolePermissions(Request $request, int $roleId): JsonResponse
    {
        // JSON schema validation
        $validationErrors = $this->jsonSchemaValidationService->validate(
            json_decode($request->getContent(), false),
            'requests/admin/data_access_role_permissions_set'
        );

        if (!empty($validationErrors)) {
            return $this->responseFormatter->formatError(
                'Validation failed: ' . $validationErrors[0],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Process bulk permission update
        $result = $this->adminDataAccessService->setRolePermissions($roleId, $data->permissions);

        return $this->responseFormatter->formatSuccess([
            'role_id' => $roleId,
            'message' => 'Role permissions updated successfully',
            'changes' => $result
        ]);
    }

    /**
     * GET /admin/data-access/roles/{roleId}/effective-permissions
     * Get effective permissions for a role
     */
    public function getRoleEffectivePermissions(int $roleId): JsonResponse
    {
        $result = $this->adminDataAccessService->getRoleEffectivePermissions($roleId);
        return $this->responseFormatter->formatSuccess($result);
    }
}
```

### API Routes Configuration
```sql
-- Data Access Management API Routes
INSERT INTO `api_routes` (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`) VALUES
('admin_data_access_roles_list', 'v1', '/admin/data-access/roles', 'App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getRolesWithPermissions', 'GET', NULL, NULL),
('admin_data_access_role_permission_set', 'v1', '/admin/data-access/roles/{roleId}/permissions', 'App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::setRolePermissions', 'PUT', '{\"roleId\":\"\\d+\"}', '{\"roleId\":{\"in\":\"path\",\"required\":true}}'),
('admin_data_access_role_effective_permissions', 'v1', '/admin/data-access/roles/{roleId}/effective-permissions', 'App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getRoleEffectivePermissions', 'GET', '{\"roleId\":\"\\d+\"}', '{\"roleId\":{\"in\":\"path\",\"required\":true}}');

-- Audit API Routes
INSERT INTO `api_routes` (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`) VALUES
('admin_audit_data_access_list', 'v1', '/admin/audit/data-access', 'App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessAuditLogs', 'GET', NULL, NULL),
('admin_audit_data_access_detail', 'v1', '/admin/audit/data-access/{auditId}', 'App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessAuditDetail', 'GET', '{\"auditId\":\"\\d+\"}', '{\"auditId\":{\"in\":\"path\",\"required\":true}}'),
('admin_audit_data_access_stats', 'v1', '/admin/audit/data-access/stats', 'App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessAuditStats', 'GET', NULL, NULL);
```

## 🔍 Audit Logging System

### Comprehensive Audit Trail
Every permission check is logged with:
- **User ID**: Who performed the action
- **Resource Type**: What type of resource (group, data_table, pages)
- **Resource ID**: Specific resource identifier
- **Action**: CRUD operation attempted (create, read, update, delete, filter)
- **Permission Result**: Granted or denied
- **HTTP Context**: Method, URI, request hash, IP, user agent
- **Timestamps**: When the check occurred

### Audit Log Structure
```php
private function auditLog(?int $userId, string $resourceType, int $resourceId, string $action, string $result, ?int $permission, string $notes = null): void
{
    // Separate transaction to ensure audit logs are committed even if main operations fail
    $this->entityManager->beginTransaction();

    try {
        $audit = new DataAccessAudit();
        $audit->setUser($this->entityManager->getReference(User::class, $userId));
        $audit->setResourceType($this->lookupService->findByTypeAndCode('resourceTypes', $resourceType));
        $audit->setResourceId($resourceId);
        $audit->setAction($this->lookupService->findByTypeAndCode('auditActions', $action));
        $audit->setPermissionResult($this->lookupService->findByTypeAndCode('permissionResults', $result));
        $audit->setCrudPermission($permission);

        // HTTP request context for security analysis
        $audit->setHttpMethod($this->getHttpMethod());
        $audit->setRequestBodyHash($this->getRequestBodyHash());
        $audit->setIpAddress($this->getClientIp());
        $audit->setUserAgent($this->getUserAgent());
        $audit->setRequestUri($this->getRequestUri());
        $audit->setNotes($notes);

        $this->entityManager->persist($audit);
        $this->entityManager->flush();
        $this->entityManager->commit();

    } catch (\Exception $e) {
        // Rollback but don't fail main operation
        try {
            $this->entityManager->rollback();
        } catch (\Exception $rollbackException) {
            error_log('Failed to rollback audit transaction: ' . $rollbackException->getMessage());
        }
        error_log('Failed to log data access audit: ' . $e->getMessage());
    }
}
```

## 🔄 Integration Examples

### Service Integration Pattern
```php
<?php
class AdminUserService extends BaseService
{
    public function getUsersForAdmin(int $adminUserId): array
    {
        // Check if admin has permission to view users
        if (!$this->dataAccessSecurityService->hasPermission(
            $adminUserId,
            LookupService::RESOURCE_TYPES_GROUP,
            0, // Resource ID 0 means "all users in this resource type"
            DataAccessSecurityService::PERMISSION_READ
        )) {
            throw new AccessDeniedException('Insufficient permissions to view users');
        }

        // Get all users (this would normally be filtered by permissions)
        $users = $this->userRepository->findAll();

        // Apply data filtering based on permissions
        return $this->dataAccessSecurityService->filterData(
            fn() => $users,
            $adminUserId,
            LookupService::RESOURCE_TYPES_GROUP
        );
    }

    public function updateUser(int $adminUserId, int $userId, array $updateData): User
    {
        // Check update permission for specific user
        if (!$this->dataAccessSecurityService->hasPermission(
            $adminUserId,
            LookupService::RESOURCE_TYPES_GROUP,
            $userId,
            DataAccessSecurityService::PERMISSION_UPDATE
        )) {
            throw new AccessDeniedException('Insufficient permissions to update this user');
        }

        // Proceed with update...
        $user = $this->userRepository->find($userId);
        // ... update logic
        return $user;
    }
}
```

### Controller Integration Pattern
```php
<?php
class AdminUserController extends AbstractController
{
    public function listUsers(int $adminUserId): JsonResponse
    {
        try {
            $users = $this->adminUserService->getUsersForAdmin($adminUserId);

            return $this->responseFormatter->formatSuccess(
                $users,
                'responses/admin/users/users_list'
            );
        } catch (AccessDeniedException $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_FORBIDDEN
            );
        }
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        try {
            $adminUserId = $this->userContextService->getCurrentUser()->getId();
            $data = json_decode($request->getContent(), true);

            $updatedUser = $this->adminUserService->updateUser($adminUserId, $userId, $data);

            return $this->responseFormatter->formatSuccess(
                $updatedUser,
                'responses/admin/users/user_detail'
            );
        } catch (AccessDeniedException $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_FORBIDDEN
            );
        }
    }
}
```

## 🧪 Testing Guidelines

### Unit Tests for Permission Logic
```php
<?php
class DataAccessSecurityServiceTest extends KernelTestCase
{
    public function testAdminUserHasAllPermissions(): void
    {
        $adminUserId = 1; // Assuming user ID 1 is admin

        // Admin should have all permissions
        $this->assertTrue($this->securityService->hasPermission(
            $adminUserId, 'group', 123, DataAccessSecurityService::PERMISSION_CREATE
        ));
        $this->assertTrue($this->securityService->hasPermission(
            $adminUserId, 'group', 123, DataAccessSecurityService::PERMISSION_READ
        ));
        $this->assertTrue($this->securityService->hasPermission(
            $adminUserId, 'group', 123, DataAccessSecurityService::PERMISSION_UPDATE
        ));
        $this->assertTrue($this->securityService->hasPermission(
            $adminUserId, 'group', 123, DataAccessSecurityService::PERMISSION_DELETE
        ));
    }

    public function testNonAdminUserPermissionCheck(): void
    {
        $userId = 2; // Regular user
        $resourceType = 'group';
        $resourceId = 123;

        // Create role permission: read-only for this specific group
        $roleDataAccess = new RoleDataAccess();
        $roleDataAccess->setIdRoles(2); // Editor role
        $roleDataAccess->setIdResourceTypes(1); // Group resource type
        $roleDataAccess->setResourceId($resourceId);
        $roleDataAccess->setCrudPermissions(DataAccessSecurityService::PERMISSION_READ);

        $this->entityManager->persist($roleDataAccess);
        $this->entityManager->flush();

        // Add user to role
        $this->addUserToRole($userId, 2);

        // Test permissions
        $this->assertTrue($this->securityService->hasPermission(
            $userId, $resourceType, $resourceId, DataAccessSecurityService::PERMISSION_READ
        ));
        $this->assertFalse($this->securityService->hasPermission(
            $userId, $resourceType, $resourceId, DataAccessSecurityService::PERMISSION_UPDATE
        ));
    }

    public function testDataFiltering(): void
    {
        $userId = 2;
        $resourceType = 'data_table';

        // Create test data
        $data = [
            ['id' => 1, 'table_id' => 10, 'name' => 'Table 1'],
            ['id' => 2, 'table_id' => 20, 'name' => 'Table 2'],
            ['id' => 3, 'table_id' => 30, 'name' => 'Table 3']
        ];

        // User only has access to table_id 10 and 30
        $roleDataAccess1 = new RoleDataAccess();
        $roleDataAccess1->setIdRoles(2);
        $roleDataAccess1->setIdResourceTypes(2); // Data table resource type
        $roleDataAccess1->setResourceId(10);
        $roleDataAccess1->setCrudPermissions(DataAccessSecurityService::PERMISSION_READ);

        $roleDataAccess2 = new RoleDataAccess();
        $roleDataAccess2->setIdRoles(2);
        $roleDataAccess2->setIdResourceTypes(2);
        $roleDataAccess2->setResourceId(30);
        $roleDataAccess2->setCrudPermissions(DataAccessSecurityService::PERMISSION_READ);

        $this->entityManager->persist($roleDataAccess1);
        $this->entityManager->persist($roleDataAccess2);
        $this->entityManager->flush();

        $this->addUserToRole($userId, 2);

        // Filter data
        $filteredData = $this->securityService->filterData(
            fn() => $data,
            $userId,
            $resourceType
        );

        // Should only return 2 items (table_id 10 and 30)
        $this->assertCount(2, $filteredData);
        $this->assertEquals(10, $filteredData[0]['table_id']);
        $this->assertEquals(30, $filteredData[1]['table_id']);
    }
}
```

### Integration Tests
```php
public function testDataAccessIntegration(): void
{
    $user = $this->createTestUser();
    $role = $this->createTestRole('Editor');

    // Add user to role
    $this->addUserToRole($user->getId(), $role->getId());

    // Set specific permissions for user management
    $this->adminDataAccessService->setRolePermissions($role->getId(), [
        (object)[
            'resource_type_id' => 1, // Group resource type
            'resource_id' => 0, // All users
            'crud_permissions' => 2 // Read only
        ]
    ]);

    // Test API endpoint
    $this->client->request('GET', '/cms-api/v1/admin/data-access/roles/' . $role->getId() . '/effective-permissions', [
        'headers' => ['Authorization' => 'Bearer ' . $this->getAuthToken($user)]
    ]);

    $this->assertResponseIsSuccessful();

    $response = json_decode($this->client->getResponse()->getContent(), true);
    $this->assertEquals($role->getId(), $response['data']['role_id']);
    $this->assertCount(1, $response['data']['effective_permissions']);
}
```

## 🔒 Security Considerations

### Permission Design Principles
1. **Admin Override**: Admin role users bypass all permission checks
2. **Default Deny**: No access unless explicitly granted
3. **Bitwise Precision**: Granular control over CRUD operations
4. **Resource Scoping**: Permissions are resource-type and resource-ID specific
5. **Role Aggregation**: Users inherit permissions from all their roles (BIT_OR)

### Security Best Practices
- **Comprehensive Auditing**: Every permission check is logged
- **Request Context Logging**: HTTP method, IP, user agent stored for security analysis
- **Transaction Isolation**: Audit logs committed in separate transactions
- **Cache Invalidation**: Permission changes immediately invalidate relevant caches
- **Input Validation**: Strict JSON schema validation for permission updates

### Performance Optimizations
- **Advanced Caching**: CacheService with entity scopes for O(1) cache invalidation
- **Database Indexing**: Optimized indexes on frequently queried columns
- **Batch Operations**: Bulk permission updates with transaction wrapping
- **Lazy Loading**: Efficient entity relationships with proxy loading

## 📊 Monitoring and Analytics

### Audit Log Queries
```sql
-- Recent permission denials (potential security issues)
SELECT
    u.username,
    rt.lookup_value as resource_type,
    da.resource_id,
    a.lookup_value as action,
    da.ip_address,
    da.user_agent,
    da.created_at
FROM dataAccessAudit da
JOIN users u ON da.id_users = u.id
JOIN lookups rt ON da.id_resourceTypes = rt.id
JOIN lookups a ON da.id_actions = a.id
JOIN lookups pr ON da.id_permissionResults = pr.id
WHERE pr.lookup_code = 'denied'
AND da.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY da.created_at DESC;

-- Most active users by permission checks
SELECT
    u.username,
    COUNT(*) as permission_checks,
    SUM(CASE WHEN pr.lookup_code = 'granted' THEN 1 ELSE 0 END) as granted,
    SUM(CASE WHEN pr.lookup_code = 'denied' THEN 1 ELSE 0 END) as denied
FROM dataAccessAudit da
JOIN users u ON da.id_users = u.id
JOIN lookups pr ON da.id_permissionResults = pr.id
WHERE da.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY u.id, u.username
ORDER BY permission_checks DESC;

-- Permission changes over time
SELECT
    DATE(created_at) as date,
    COUNT(*) as total_changes,
    SUM(CASE WHEN crud_permissions & 1 THEN 1 ELSE 0 END) as create_perms,
    SUM(CASE WHEN crud_permissions & 2 THEN 1 ELSE 0 END) as read_perms,
    SUM(CASE WHEN crud_permissions & 4 THEN 1 ELSE 0 END) as update_perms,
    SUM(CASE WHEN crud_permissions & 8 THEN 1 ELSE 0 END) as delete_perms
FROM role_data_access
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at)
ORDER BY date;
```

---

**Next**: [Global Cache System](./17-global-cache-system.md)
