# Data Management Access Control

## Overview
Extremely simple custom CRUD access for custom data types (groups, dataTables, surveys in future). Permissions are attached to roles, not individual users. Uses lookup table for resource types.

## 🔒 Security-First Approach
**Important**: We use a secure "deny by default" model where users get NO access unless they have explicit permissions. This prevents security vulnerabilities from forgotten filtering logic.

## Integration with Existing Permission System

### Secure Global Permission Model

**Core Logic:**
1. **Check User Role**: Is user admin? → **Full Access** (skip custom checks)
2. **Check Custom Permissions**: Apply custom restrictions, **deny by default**
3. **Apply Filters**: Only return data explicitly permitted

#### Permission Flow:
```
User Request → Security Layer
├── User has ADMIN role? → Return ALL data ✅
└── User has NON-ADMIN role?
    ├── Has custom permissions for resource? → Apply filters ✅
    └── No custom permissions? → Return EMPTY (deny by default) 🛡️
```

### Security Model Overview:

1. **Admin Role Check**: Users with admin role get full access to all data
2. **Custom Permissions Check**: Non-admin users get filtered access based on role_data_access table
3. **Deny by Default**: Users without explicit permissions get no access
4. **Bitwise Permissions**: CRUD operations stored as bit flags (1=Create, 2=Read, 4=Update, 8=Delete)
5. **Multiple Roles**: User permissions unified using BIT_OR across all their roles

#### Integration Points:
- **Security Layer**: Centralized permission enforcement
- **Repository Layer**: Provide data fetchers to security layer
- **Controller Layer**: Use security layer for all data access

### Key Concepts:

1. **🔒 Security First**: Deny by default prevents data leaks
2. **🎯 Role-Based**: Admin role = full access, others = custom restrictions
3. **🔄 Consistent**: Same security pattern everywhere
4. **🛠️ Simple**: One table, lookup integration, bitwise permissions
5. **📈 Scalable**: Easy to add new resource types and permissions

## Functionality
- When role has "group" read permission → users with that role only see data for users in that group
- When role has "dataTable" read permission → users with that role only see data for that table(s)
- Can combine both: see dataTable id 5 data but only for people in "test" group
- Applies to all CRUD operations (read, create, update, delete)
- Users inherit permissions through their assigned roles

## Table Structure

```sql
CREATE TABLE role_data_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_roles INT NOT NULL,
    id_resourceTypes INT NOT NULL, -- References lookups table (type_code = 'resourceTypes')
    resource_id INT NOT NULL,
    crud_permissions TINYINT UNSIGNED NOT NULL DEFAULT 2, -- 2 = Read only
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_roles) REFERENCES role(id),
    FOREIGN KEY (id_resourceTypes) REFERENCES lookups(id),
    UNIQUE KEY unique_role_resource (id_roles, id_resourceTypes, resource_id)
);

-- Audit table for custom data access checks
CREATE TABLE data_access_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_users INT NOT NULL,                    -- Who performed the action
    id_resourceTypes INT NOT NULL, -- References lookups table (type_code = 'resourceTypes')
    resource_id INT NOT NULL,                -- ID of the resource accessed
    id_actions INT NOT NULL,                 -- References lookups table (type_code = 'auditActions')
    id_permissionResults INT NOT NULL,       -- References lookups table (type_code = 'permissionResults')
    crud_permission TINYINT UNSIGNED NULL,   -- Bit flags for the permission checked
    http_method VARCHAR(10) NULL,            -- HTTP method (GET, POST, PUT, DELETE)
    request_body_hash VARCHAR(64) NULL,      -- SHA-256 hash of request body (forensic tracking)
    ip_address VARCHAR(45) NULL,             -- Client IP
    user_agent TEXT NULL,                    -- Browser/client info
    request_uri TEXT NULL,                   -- API endpoint accessed
    notes TEXT NULL,                         -- Additional notes about the action
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_users) REFERENCES users(id),
    FOREIGN KEY (id_resourceTypes) REFERENCES lookups(id),
    FOREIGN KEY (id_actions) REFERENCES lookups(id),
    FOREIGN KEY (id_permissionResults) REFERENCES lookups(id),

    INDEX IDX_9DCB3F7E5FE6C863 (id_users),                    -- user index
    INDEX IDX_9DCB3F7EDE12AB56 (id_resourceTypes),            -- resource type index
    INDEX IDX_9DCB3F7E12345678 (resource_id),                 -- resource id index
    INDEX IDX_9DCB3F7E87654321 (created_at),                  -- timestamp index
    INDEX IDX_9DCB3F7E11223344 (id_permissionResults),        -- permission result index
    INDEX IDX_9DCB3F7E99887766 (http_method),                 -- HTTP method index
    INDEX IDX_9DCB3F7E55443322 (request_body_hash)            -- request body hash index
);
```

## Lookup Service Integration

### Lookup Table Integration
- **Uses existing `lookups` table**: Follows your established pattern
- **Type codes**:
  - `resourceTypes`: `group`, `data_table`, `pages`
  - `auditActions`: `filter`, `create`, `read`, `update`, `delete`
  - `permissionResults`: `granted`, `denied`
- **Constants to add**:
  - `RESOURCE_TYPES_GROUP`, `RESOURCE_TYPES_DATA_TABLE`, `RESOURCE_TYPES_PAGES`
  - `AUDIT_ACTIONS_FILTER`, `AUDIT_ACTIONS_CREATE`, `AUDIT_ACTIONS_READ`, `AUDIT_ACTIONS_UPDATE`, `AUDIT_ACTIONS_DELETE`
  - `PERMISSION_RESULTS_GRANTED`, `PERMISSION_RESULTS_DENIED`

## Audit System

### What Gets Logged

Every permission check performed by the `DataAccessSecurityService` is automatically logged:

#### **Filter Operations (READ access):**
```sql
-- When filtering user lists, data tables, pages, etc.
INSERT INTO data_access_audit (
    id_users, id_resourceTypes, resource_id, id_actions, id_permissionResults,
    crud_permission, http_method, request_body_hash, ip_address, notes
) VALUES (
    123, 1, 0, 1, 1,
    NULL, 'GET', NULL, '192.168.1.100', 'Custom filter applied'
);
-- Where: id_resourceTypes=1 (group), id_actions=1 (filter), id_permissionResults=1 (granted)
```

#### **Permission Checks (CREATE/UPDATE/DELETE):**
```sql
-- When checking permissions for specific operations
INSERT INTO data_access_audit (
    id_users, id_resourceTypes, resource_id, id_actions, id_permissionResults,
    crud_permission, http_method, request_body_hash, ip_address, notes
) VALUES (
    123, 2, 25, 4, 1,
    4, 'PUT', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', '192.168.1.100', 'Permission granted'
);
-- Where: id_resourceTypes=2 (data_table), id_actions=4 (update), id_permissionResults=1 (granted), crud_permission=4 (UPDATE bit)
```

#### **Denied Access Attempts:**
```sql
-- When users try to access resources they don't have permission for
INSERT INTO data_access_audit (
    id_users, id_resourceTypes, resource_id, id_actions, id_permissionResults,
    crud_permission, http_method, request_body_hash, ip_address, notes
) VALUES (
    123, 3, 10, 5, 2,
    8, 'DELETE', NULL, '192.168.1.100', 'Insufficient permissions'
);
-- Where: id_resourceTypes=3 (pages), id_actions=5 (delete), id_permissionResults=2 (denied), crud_permission=8 (DELETE bit)
```

### Audit Actions Logged (via Lookups):
- **`filter`** - Data filtering applied to READ operations
- **`create`** - Permission check for CREATE operations
- **`read`** - Permission check for specific READ operations
- **`update`** - Permission check for UPDATE operations
- **`delete`** - Permission check for DELETE operations

### Audit Benefits:
- **Complete visibility** into who accesses what data and when
- **Security monitoring** - Track denied access attempts
- **Compliance** - Full audit trail for data access
- **Debugging** - Understand permission decisions
- **Performance monitoring** - Track access patterns
- **Transaction reliability** - Audit logs are guaranteed to persist even during system failures
- **Forensic tracking** - HTTP methods and request body hashes for incident investigation
- **Proxy-aware logging** - Proper IP detection considering load balancers and proxies

**CRUD Bit Flags:**
- 1 = Create (0001)
- 2 = Read (0010)
- 4 = Update (0100)
- 8 = Delete (1000)

**Common Combinations:**
- 2 = Read only (0010)
- 3 = Read + Create (0011)
- 6 = Read + Update (0110)
- 7 = Read + Update + Create (0111)
- 10 = Read + Delete (1010)
- 15 = Full CRUD (1111)

**Lookup Table Setup:**
```sql
-- Insert resource types into lookups table
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('resourceTypes', 'group', 'Group', 'User groups for data access control'),
('resourceTypes', 'data_table', 'Data Table', 'Custom data tables'),
('resourceTypes', 'pages', 'Pages', 'Admin pages access control'),

-- Insert audit actions into lookups table
('auditActions', 'filter', 'Filter', 'Data filtering applied to READ operations'),
('auditActions', 'create', 'Create', 'Permission check for CREATE operations'),
('auditActions', 'read', 'Read', 'Permission check for specific READ operations'),
('auditActions', 'update', 'Update', 'Permission check for UPDATE operations'),
('auditActions', 'delete', 'Delete', 'Permission check for DELETE operations'),

-- Insert permission results into lookups table
('permissionResults', 'granted', 'Granted', 'Permission was granted'),
('permissionResults', 'denied', 'Denied', 'Permission was denied');
```

## Examples

#### Single Group Access (Read Only)
```sql
-- Assuming group resource_type has id = 1 in lookups table
INSERT INTO role_data_access (id_roles, id_resourceTypes, resource_id, crud_permissions)
VALUES (5, 1, 10, 2); -- 2 = Read only
```
→ Users with id_roles 5 can only read data for users in group 10

#### Single DataTable Access (Read + Write)
```sql
-- Assuming data_table resource_type has id = 2 in lookups table
INSERT INTO role_data_access (id_roles, id_resourceTypes, resource_id, crud_permissions)
VALUES (5, 2, 25, 6); -- 6 = Read (2) + Update (4)
```
→ Users with id_roles 5 can read and update data in dataTable 25

#### Combined Access (Read + Update for specific table and group)
```sql
INSERT INTO role_data_access (id_roles, id_resourceTypes, resource_id, crud_permissions)
VALUES (5, 1, 10, 2),        -- Read only for group 10 (id_resourceTypes = 1)
       (5, 2, 25, 6);        -- Read + Update for dataTable 25 (id_resourceTypes = 2)
```
→ Users with id_roles 5 can read data from dataTable 25 AND only for users in group 10

#### Multiple Tables (Read + Create + Update)
```sql
INSERT INTO role_data_access (id_roles, id_resourceTypes, resource_id, crud_permissions)
VALUES (5, 2, 25, 7),    -- Read + Create + Update for dataTable 25
       (5, 2, 30, 7);    -- Read + Create + Update for dataTable 30
```
→ Users with id_roles 5 can read, create, and update data in dataTables 25 AND 30

#### Full CRUD Access to Survey Data (Future)
```sql
-- Assuming survey resource_type has id = 3 in lookups table
INSERT INTO role_data_access (id_roles, id_resourceTypes, resource_id, crud_permissions)
VALUES (5, 3, 100, 15); -- 15 = Full CRUD for survey 100
```
→ Users with id_roles 5 have full CRUD access to survey 100

## Multiple Roles - Permission Unification

When a user has multiple roles, permissions are **unified using most-permissive logic** (OR operation):

### How It Works:
1. **Collect all permissions** for the user across their roles
2. **Group by resource** (same `id_resourceTypes` + `resource_id`)
3. **Bitwise OR** the `crud_permissions` for each resource group
4. **Result**: User gets the combined permissions from all their roles

### Examples:

#### User with Multiple Roles on Same Resource
```
Role A: dataTable 25, permissions = 2 (Read)
Role B: dataTable 25, permissions = 4 (Update)
Role C: dataTable 25, permissions = 1 (Create)
---
Result: dataTable 25, permissions = 7 (Read + Update + Create)
```

#### User with Different Resources Across Roles
```
Role Manager: group 10, permissions = 2 (Read)
Role Analyst: dataTable 25, permissions = 6 (Read + Update)
Role Auditor: dataTable 30, permissions = 2 (Read)
---
Result:
- group 10: permissions = 2 (Read)
- dataTable 25: permissions = 6 (Read + Update)
- dataTable 30: permissions = 2 (Read)
```

### SQL Query for Unified Permissions
```sql
SELECT
    rda.id_resourceTypes,
    rda.resource_id,
    BIT_OR(rda.crud_permissions) as unified_permissions
FROM role_data_access rda
INNER JOIN users_roles ur ON ur.id_roles = rda.id_roles
WHERE ur.id_users = :user_id
GROUP BY rda.id_resourceTypes, rda.resource_id;
```

## Implementation Notes

### Lookup Table Integration
- **Uses existing `lookups` table**: Follows your established pattern
- **Type code**: `resourceTypes` (following existing RESOURCE_TYPES constant)
- **Lookup codes**: `group`, `data_table`, `survey` (following existing naming patterns)
- **Constants added to LookupService**: Follows existing constant pattern for types and codes

### Transaction-Wrapped Audit Logging
- **Critical Security Requirement**: All audit logs must be wrapped in database transactions
- **Reliability**: Audit logs should commit even if main operations fail (for security tracking)
- **Atomicity**: Use `EntityManager::transactional()` to ensure audit persistence
- **Error Handling**: Log lookup errors but don't fail the audit operation
- **Immediate Commit**: Use `flush()` to ensure audit entries are written immediately

### Advanced Caching System (CacheService Integration)

- **Permission Cache**: Cache user role permissions using CacheService with entity-scoped invalidation
- **Multi-Level Cache**: User roles → Role permissions → Unified permissions per resource type
- **Generation-Based Invalidation**: O(1) invalidation using CacheService's generation counters
- **Entity-Scoped Cache**: Automatic invalidation when users, roles, or permissions change
- **Category-Based Organization**: Uses `CATEGORY_PERMISSIONS` for permission data

#### Cache Structure (Using CacheService):
```php
// 1. User roles cache with entity scope dependencies
$userRoles = $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)
                   ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
                   ->getItem("users_roles", fn() => $this->getUserRoleIds($userId));

// 2. Role permissions cache with role dependencies
$rolePerms = $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)
                   ->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId)
                   ->withEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId)
                   ->getItem("role_permissions_{$resourceType}", fn() => $this->getRolePermissions($roleId, $resourceType));

// 3. Unified permissions cache with multiple dependencies
$unifiedPerms = $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)
                      ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
                      ->withEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId)
                      ->getItem("unified_permissions_{$resourceType}", fn() => $this->unifyPermissions($userId, $resourceType));
```

#### Cache Invalidation Triggers (O(1) Generation-Based):
- **Role Assignment Changes**: `$cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)`
- **Permission Changes**: `$cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId)`
- **Resource Type Changes**: `$cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId)`
- **User Session Changes**: `$cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)`
- **Admin Operations**: `$cache->invalidateCategory()` for complete permission cache clearing

### CRUD Bit Flags with Lookup Table
- **Bit flag system**: TINYINT allows granular CRUD combinations (1=Create, 2=Read, 4=Update, 8=Delete)
- **Flexible resource types**: Easy to add new types without schema changes
- **Future-proof**: Surveys and other resource types need only lookup table inserts
- **Bitwise operations**: Code needs bitwise checks (e.g., `permissions & 2` for read access)
- **Permission unification**: BIT_OR aggregation makes multiple roles easy to handle

## Global Implementation Across Admin Controllers

### Controllers Requiring Custom Filtering

#### AdminUserController
- **READ**: `getUsers()`, `getUserById()`, `getUserGroups()`, `getUserRoles()` - Filter based on group access
- **UPDATE**: `updateUser()`, `toggleUserBlock()`, `addGroupsToUser()`, `removeGroupsFromUser()`, `addRolesToUser()`, `removeRolesFromUser()`, `sendActivationMail()`, `cleanUserData()` - Check update/delete permissions on specific users
- **DELETE**: `deleteUser()` - Check delete permissions on specific users
- **CREATE**: `createUser()` - Check if can create users in specific groups

#### AdminDataController
- **READ**: `getDataTables()`, `getData()`, `getColumns()`, `getColumnNames()` - Filter based on table access
- **UPDATE/DELETE**: `deleteRecord()`, `deleteColumns()`, `deleteDataTable` - Check permissions on specific data/tables
- **CREATE**: N/A (data creation handled elsewhere)

#### AdminPageController
- **READ**: `getPages()`, `getPage()`, `getPageSections()` - Filter based on page access
- **UPDATE**: `updatePage()`, `addSectionToPage()` - Check permissions on specific pages
- **DELETE**: `deletePage()`, `removeSectionFromPage()` - Check delete permissions on specific pages
- **CREATE**: `createPage()` - Check if can create pages

#### Other Admin Controllers
- **AdminGroupController**: Group management data
- **AdminRoleController**: Role management data
- **AdminAssetController**: Asset management data
- **AdminActionController**: Action management data
- **AdminSectionController**: Section management data

### Implementation Strategy

#### 1. Create Global Security Service with Audit Logging
```php
// src/Service/Security/DataAccessSecurityService.php
class DataAccessSecurityService
{
    public function __construct(
        private DataAccessAuditRepository $auditRepository,
        private LookupService $lookupService,
        private EntityManagerInterface $entityManager,
        private CacheService $cache,
        // ... other dependencies
    ) {}

    // Filter data for READ operations with advanced caching
    public function filterData(callable $dataFetcher, int $userId, string $resourceType): array
    {
        // Check admin role first - no caching needed for admin
        if ($this->userHasAdminRole($userId)) {
            $this->auditLog($userId, $resourceType, 0, 'filter', 'granted', null, 'Admin role override');
            return $dataFetcher(); // Full access
        }

        // Get resource type ID for entity scoping
        $resourceTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);

        // Check unified permissions cache with entity scopes
        $permissions = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PERMISSION, $resourceTypeId)
            ->getItem("unified_permissions", function() use ($userId, $resourceType) {
                return $this->getUserPermissions($userId, $resourceType);
            });

        if (empty($permissions)) {
            $this->auditLog($userId, $resourceType, 0, 'filter', 'denied', null, 'No permissions found');
            return []; // No access
        }

        $this->auditLog($userId, $resourceType, 0, 'filter', 'granted', $permissions, 'Custom filter applied');
        return $this->applyFilters($dataFetcher(), $permissions);
    }

    // Check permissions for CREATE/UPDATE/DELETE operations
    public function hasPermission(int $userId, string $resourceType, int $resourceId, int $requiredPermission): bool
    {
        // Admin role has all permissions
        if ($this->userHasAdminRole($userId)) {
            $this->auditLog($userId, $resourceType, $resourceId, $this->getActionName($requiredPermission), 'GRANTED', $requiredPermission, 'Admin role override');
            return true;
        }

        // Check custom permissions for this specific resource
        $permissions = $this->getUserPermissionsForResource($userId, $resourceType, $resourceId);

        // Check if user has the required permission (bitwise AND)
        $hasPermission = ($permissions & $requiredPermission) === $requiredPermission;

        $this->auditLog(
            $userId,
            $resourceType,
            $resourceId,
            $this->getActionName($requiredPermission),
            $hasPermission ? 'GRANTED' : 'DENIED',
            $requiredPermission,
            $hasPermission ? 'Permission granted' : 'Insufficient permissions'
        );

        return $hasPermission;
    }

    private function auditLog(int $userId, string $resourceType, int $resourceId, string $action, string $result, ?int $permission, string $notes = null): void
    {
        // CRITICAL: Audit logging must be wrapped in transaction for reliability
        // The audit log should be committed even if the main operation fails (for security tracking)
        // OR participate in the same transaction as the main operation

        $this->entityManager->transactional(function ($em) use ($userId, $resourceType, $resourceId, $action, $result, $permission, $notes) {
            // Get lookup IDs for action and result
            $actionId = $this->lookupService->getLookupIdByCode(LookupService::AUDIT_ACTIONS, $action);
            $resultId = $this->lookupService->getLookupIdByCode(LookupService::PERMISSION_RESULTS, $result);
            $resourceTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);

            if (!$actionId || !$resultId || !$resourceTypeId) {
                // Fallback if lookups not found - still log with error note
                $notes = ($notes ? $notes . ' | ' : '') . 'LOOKUP_ERROR: Missing lookup IDs';
            }

            // Create and save audit log entry
            $audit = new DataAccessAudit();
            $audit->setIdUsers($userId);
            $audit->setIdResourceTypes($resourceTypeId ?: 0); // Use 0 for missing lookups
            $audit->setResourceId($resourceId);
            $audit->setIdActions($actionId ?: 0);
            $audit->setIdPermissionResults($resultId ?: 0);
            $audit->setCrudPermission($permission);
            $audit->setHttpMethod($this->getHttpMethod());              // HTTP method for forensic tracking
            $audit->setRequestBodyHash($this->getRequestBodyHash());    // SHA-256 hash of request body
            $audit->setIpAddress($this->getClientIp());
            $audit->setUserAgent($this->getUserAgent());
            $audit->setRequestUri($this->getRequestUri());
            $audit->setNotes($notes);

            $em->persist($audit);
            $em->flush(); // Ensure audit is committed immediately
        });
    }

    // Cache invalidation methods using CacheService entity scopes
    public function invalidateUserPermissions(int $userId): void
    {
        // Clear all permission caches for a user using entity scope invalidation (O(1))
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
    }

    public function invalidateRolePermissions(int $roleId): void
    {
        // Clear role permission caches using entity scope invalidation
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
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

    // Helper methods for audit logging
    private function getHttpMethod(): ?string
    {
        // Get HTTP method from current request for audit logging
        return $_SERVER['REQUEST_METHOD'] ?? null;
    }

    private function getRequestBodyHash(): ?string
    {
        // Generate SHA-256 hash of request body for forensic tracking
        // IMPORTANT: Store hash only, never the actual content for security/privacy compliance
        // This allows detecting if the same request was made multiple times without storing sensitive data
        $body = file_get_contents('php://input');
        return $body ? hash('sha256', $body) : null;
    }

    private function getClientIp(): ?string
    {
        // Get client IP address considering proxies
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ??
               $_SERVER['HTTP_X_REAL_IP'] ??
               $_SERVER['REMOTE_ADDR'] ??
               null;
    }

    private function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    private function getRequestUri(): ?string
    {
        return $_SERVER['REQUEST_URI'] ?? null;
    }

    // Constants for permissions
    public const PERMISSION_CREATE = 1;  // 0001
    public const PERMISSION_READ = 2;    // 0010
    public const PERMISSION_UPDATE = 4;  // 0100
    public const PERMISSION_DELETE = 8;  // 1000
}
```

#### 2. Update Controllers Globally
Replace direct data access with filtered access:

**BEFORE:**
```php
public function getUsers(): JsonResponse
{
    $result = $this->adminUserService->getUsers(/* params */);
    return $this->responseFormatter->formatSuccess($result);
}
```

**AFTER:**
```php
public function getUsers(Request $request): JsonResponse
{
    try {
        $userId = $this->getUser()->getId();

        $result = $this->customSecurityService->filterData(
            fn() => $this->adminUserService->getUsers(/* params */),
            $userId,
            LookupService::RESOURCE_TYPES_GROUP // Filter by group access
        );

        return $this->responseFormatter->formatSuccess($result);
    } catch (\Exception $e) {
        return $this->responseFormatter->formatError($e->getMessage());
    }
}
```

#### 3. Permission Checking for UPDATE/DELETE Operations
For update and delete operations, check specific permissions before allowing the action:

```php
// Example: Update user
public function updateUser(int $userId, Request $request): JsonResponse
{
    $currentUserId = $this->getUser()->getId();

    // Check if user has UPDATE permission for this specific user
    if (!$this->customSecurityService->hasPermission(
        $currentUserId,
        LookupService::RESOURCE_TYPES_GROUP, // Check group access
        $this->getUserGroupId($userId), // Get the group of the user being updated
        DataAccessSecurityService::PERMISSION_UPDATE
    )) {
        return $this->responseFormatter->formatError('Access denied', 403);
    }

    // Proceed with update...
    $user = $this->adminUserService->updateUser($userId, $data);
    return $this->responseFormatter->formatSuccess($user);
}

// Example: Delete data record
public function deleteRecord(Request $request, int $recordId): JsonResponse
{
    $currentUserId = $this->getUser()->getId();
    $tableName = $request->query->get('table_name');

    // Check if user has DELETE permission for this data table
    if (!$this->customSecurityService->hasPermission(
        $currentUserId,
        LookupService::RESOURCE_TYPES_DATA_TABLE,
        $this->getDataTableId($tableName),
        DataAccessSecurityService::PERMISSION_DELETE
    )) {
        return $this->responseFormatter->formatError('Access denied', 403);
    }

    // Proceed with deletion...
    $success = $this->dataService->deleteData($recordId);
    return $this->responseFormatter->formatSuccess(['deleted' => $success]);
}
```

### Migration Plan

1. **Create data_access_audit table** for logging all permission checks
2. **Add lookup entries** for auditActions and permissionResults type codes
3. **Create DataAccessSecurityService** with `filterData()`, `hasPermission()`, audit logging, and caching
4. **Implement cache invalidation system** with proper triggers for role/permission changes
5. **Update LookupService constants** for all new lookup types
6. **Update AdminUserController** - Add permission checks to all CREATE/UPDATE/DELETE operations + READ filtering
7. **Update AdminDataController** - Add permission checks to UPDATE/DELETE operations + READ filtering
8. **Update AdminPageController** - Add permission checks to all CREATE/UPDATE/DELETE operations + READ filtering
9. **Update remaining admin controllers** - Add permission checks to all data modification operations + READ filtering
10. **Create audit management APIs** - Add routes and controllers for viewing audit logs (admin access required)
11. **Create data access management APIs** - Add routes and controllers for managing role_data_access permissions with cache invalidation
12. **Test caching performance** - Verify permission checks use cache and manual invalidation works
13. **Test audit logging** - Verify all permission checks are properly logged
14. **Test thoroughly** - Verify admin users have full CRUD access, custom users have restricted access for all operations

### Separation from Frontend ACL

**Frontend ACL (existing - group-based):**
- Controls which pages/groups can access frontend content
- Stored in separate ACL tables
- Based on user groups

**Backend Custom Access (new - role-based):**
- Controls admin data access within backend
- Uses `role_data_access` table
- Based on user roles
- Completely separate from frontend ACL

## API Requirements

### Audit Management APIs (Admin Only)

**Purpose:** Allow administrators to view and analyze data access audit logs for security monitoring and compliance.

#### Required Endpoints:
- `GET /admin/audit/data-access` - List audit logs with filtering and pagination
  - Query params: `user_id`, `resource_type`, `action`, `permission_result`, `date_from`, `date_to`, `page`, `pageSize`
  - **Permission Required:** `admin.audit.view` or admin role
- `GET /admin/audit/data-access/{id}` - Get specific audit log details
  - **Permission Required:** `admin.audit.view` or admin role
- `GET /admin/audit/data-access/stats` - Get audit statistics and summaries
  - Returns: total logs, denied attempts, most accessed resources, etc.
  - **Permission Required:** `admin.audit.view` or admin role

#### Security Considerations:
- Only admin users can access audit logs
- All audit API calls are themselves logged
- **Transaction-wrapped audit logging** ensures reliability even during failures
- Rate limiting to prevent abuse
- Audit logs cannot be modified or deleted (append-only)

### Data Access Management APIs (Admin Only)

**Purpose:** Allow administrators to manage custom data access permissions for roles.

#### Required Endpoints:
- `GET /admin/data-access/roles` - List all roles with their custom data access permissions
  - **Permission Required:** `admin.role.read` or admin role
- `POST /admin/data-access/roles/{roleId}/permissions` - Add custom permission to a role
  - Body: `{ "resource_type_id": 1, "resource_id": 123, "crud_permissions": 6 }`
  - **Permission Required:** `admin.role.update` or admin role
  - **Cache Impact:** Invalidates role and affected user permission caches
- `PUT /admin/data-access/roles/{roleId}/permissions/{permissionId}` - Update existing permission
  - Body: `{ "crud_permissions": 15 }`
  - **Permission Required:** `admin.role.update` or admin role
  - **Cache Impact:** Invalidates role and affected user permission caches
- `DELETE /admin/data-access/roles/{roleId}/permissions/{permissionId}` - Remove permission from role
  - **Permission Required:** `admin.role.update` or admin role
  - **Cache Impact:** Invalidates role and affected user permission caches
- `GET /admin/data-access/roles/{roleId}/effective-permissions` - Show combined permissions for a role (including multiple roles if user has them)
  - **Permission Required:** `admin.role.read` or admin role

#### Security Considerations:
- Access controlled by existing `admin.role.read`, `admin.role.update` permissions or admin role
- All permission changes are audited in the data_access_audit table
- **Admin roles are immutable** - cannot be modified through APIs
- Cannot modify permissions for admin roles (enforced in business logic)
- Validation ensures permission combinations are valid
- **Cache invalidation** occurs immediately after permission changes to ensure security

#### Cache Management (CacheService Integration):
- **Entity-Scoped Invalidation**: O(1) invalidation using generation counters
- **Category-Based Organization**: Uses `CATEGORY_PERMISSIONS` for all permission data
- **Multi-Level Dependencies**: Cache entries depend on users, roles, and resource types
- **Automatic Invalidation**: Changes to entities automatically invalidate dependent caches
- **Performance Optimized**: Builder pattern with entity scopes prevents redundant queries

### Admin Role Protection

**Critical Security Rule:** Admin roles cannot be modified through the API system.

#### What Cannot Be Changed for Admin Roles:
- Role name
- Role permissions (both standard and custom data access)
- Role status
- Any other role attributes

#### How Admin Roles Are Modified:
- **Only through database scripts** during deployments/upgrades
- Manual DB updates for emergency changes
- Never through API endpoints (enforced in controllers)

#### Why This Protection Exists:
- Prevents accidental lockout of administrators
- Ensures admin access is always available
- Maintains system integrity
- Compliance with security best practices

### Key Benefits:
- **Security-First Design**: Fail-safe approach prevents accidental data leaks
- **Global Security Layer**: Centralized permission enforcement
- **Advanced CacheService Integration**: Entity-scoped O(1) invalidation with generation counters
- No complex relationships or inheritance
- **Extremely simple**: Single table with lookup integration
- Role-based: permissions attached to roles, users inherit through roles
- **Multiple roles supported**: BIT_OR unification handles complex permission scenarios
- **Flexible**: Add new resource types without schema changes
- **Integrated with existing permissions**: Works alongside admin.data.read, etc.
- **Secure by default**: No access without explicit permissions
- **Manual cache invalidation**: Changes propagate immediately when data actually changes
- Unique constraint prevents duplicate permissions per role-resource combination
- Granular CRUD control with efficient bit flags
- **Follows existing patterns**: Uses your established LookupService constants
