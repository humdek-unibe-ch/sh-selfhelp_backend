# Admin Users, Groups & Roles APIs

## Overview

The User Management APIs provide comprehensive functionality for managing users, groups, and roles within the SelfHelp CMS. This includes user CRUD operations, group membership management, role assignment, and fine-grained access control through permissions and data access rules.

## Core Concepts

### Users
- **User Accounts**: System users with authentication credentials
- **User Types**: Different categories of users (admin, regular, etc.)
- **User Status**: Active, blocked, or validation pending
- **Profile Information**: Name, email, language preferences

### Groups
- **User Groups**: Collections of users with shared permissions
- **Group Types**: Different categories of groups
- **Group Membership**: Users can belong to multiple groups
- **Access Control**: Group-based permission inheritance

### Roles
- **System Roles**: Named sets of permissions (admin, editor, etc.)
- **Role Permissions**: Specific capabilities granted by roles
- **Role Assignment**: Users can have multiple roles
- **Permission Inheritance**: Users inherit permissions from all their roles

### Data Access Control
- **Custom Permissions**: Fine-grained access rules per resource
- **Resource Types**: Different types of system resources
- **CRUD Permissions**: Create, Read, Update, Delete access levels

## User Management

### Get Users

Retrieve a paginated list of users with filtering and sorting options.

**Endpoint:** `GET /cms-api/v1/admin/users`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page`: Page number (default: 1)
- `pageSize`: Items per page (default: 20)
- `search`: Search term for name/email filtering
- `sort`: Sort field ('id', 'email', 'name', 'created_at', etc.)
- `sortDirection`: Sort direction ('asc' or 'desc')

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "users": [
      {
        "id": 1,
        "email": "admin@example.com",
        "name": "System Administrator",
        "user_name": "admin",
        "user_type_id": 1,
        "blocked": false,
        "id_languages": 1,
        "language_locale": "en",
        "last_login": "2025-01-23T09:15:00Z",
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2025-01-20T14:45:00Z",
        "groups": [
          {
            "id": 1,
            "name": "admin",
            "description": "Administrator group"
          }
        ],
        "roles": [
          {
            "id": 1,
            "name": "admin",
            "description": "Administrator role"
          }
        ]
      }
    ],
    "total": 150,
    "page": 1,
    "pageSize": 20,
    "totalPages": 8
  }
}
```

**Permissions:** `admin.user.read`

### Get Single User

Retrieve detailed information about a specific user.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:** Same format as user list but single user object

**Permissions:** `admin.user.read` (with group-based access control)

### Create User

Create a new user account.

**Endpoint:** `POST /cms-api/v1/admin/users`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/admin/create_user.json)
```json
{
  "email": "newuser@example.com",
  "name": "New User",
  "user_name": "newuser",
  "password": "SecurePassword123!",
  "user_type_id": 2,
  "blocked": false,
  "id_languages": 1,
  "validation_code": "abc123def456",
  "group_ids": [2, 3],
  "role_ids": [2]
}
```

**Field Descriptions:**
- `email`: User's email address (required, unique)
- `name`: Display name (optional)
- `user_name`: Username (optional, unique)
- `password`: Plain text password (optional, will be hashed)
- `user_type_id`: User type identifier (optional)
- `blocked`: Account blocked status (default: false)
- `id_languages`: Preferred language ID (optional)
- `validation_code`: Email validation code (required)
- `group_ids`: Array of group IDs to assign (optional)
- `role_ids`: Array of role IDs to assign (optional)

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user": {
      "id": 3,
      "email": "newuser@example.com",
      "name": "New User",
      "user_name": "newuser",
      "user_type_id": 2,
      "blocked": false,
      "id_languages": 1,
      "language_locale": "en",
      "created_at": "2025-01-23T10:30:00Z",
      "updated_at": "2025-01-23T10:30:00Z",
      "groups": [...],
      "roles": [...]
    }
  }
}
```

**Permissions:** `admin.user.create`

### Update User

Modify an existing user's information.

**Endpoint:** `PUT /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID to update

**Request Body:** Same as create, but all fields optional
[View JSON Schema](../../config/schemas/api/v1/requests/admin/update_user.json)

**Response:** Updated user data

**Permissions:** `admin.user.update`

### Delete User

Remove a user account from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID to delete

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "deleted": true
  }
}
```

**Permissions:** `admin.user.delete`

### Block/Unblock User

Toggle a user's blocked status.

**Endpoint:** `PATCH /cms-api/v1/admin/users/{userId}/block`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "blocked": true
}
```

**Response:** Updated user data with new blocked status

**Permissions:** `admin.user.block` or `admin.user.unblock`

## User Group Management

### Get User Groups

Retrieve all groups a user belongs to.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user_id": 1,
    "groups": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator group",
        "id_group_types": 1,
        "requires_2fa": true,
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ]
  }
}
```

**Permissions:** `admin.user.read`

### Add Groups to User

Assign additional groups to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "group_ids": [2, 3, 4]
}
```

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user_id": 1,
    "added_groups": [2, 3, 4],
    "groups": [...]
  }
}
```

**Permissions:** `admin.user.update`

### Remove Groups from User

Remove group assignments from a user.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "group_ids": [2, 3]
}
```

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user_id": 1,
    "removed_groups": [2, 3],
    "groups": [...]
  }
}
```

**Permissions:** `admin.user.update`

## User Role Management

### Get User Roles

Retrieve all roles assigned to a user.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user_id": 1,
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator role",
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ]
  }
}
```

**Permissions:** `admin.user.read`

### Add Roles to User

Assign additional roles to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "role_ids": [2, 3]
}
```

**Response:** Similar to add groups response

**Permissions:** `admin.user.update`

### Remove Roles from User

Remove role assignments from a user.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "role_ids": [2]
}
```

**Response:** Similar to remove groups response

**Permissions:** `admin.user.update`

## Additional User Operations

### Send Activation Email

Resend account activation email to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/send-activation-mail`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "message": "Activation email sent successfully"
  }
}
```

**Permissions:** `admin.user.update`

### Clean User Data

Remove all user-generated content and data.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/clean-data`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "message": "User data cleaned successfully",
    "removed_records": 45
  }
}
```

**Permissions:** `admin.user.update`

### Impersonate User

Temporarily assume another user's identity for debugging.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/impersonate`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID to impersonate

**Response:** New JWT tokens for the impersonated user

**Permissions:** `admin.user.impersonate`

## Group Management

### Get Groups

Retrieve a list of all user groups.

**Endpoint:** `GET /cms-api/v1/admin/groups`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:** Same as users (page, pageSize, search, sort, sortDirection)

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator group",
        "id_group_types": 1,
        "requires_2fa": true,
        "acls": [...],
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z",
        "user_count": 5
      }
    ],
    "total": 10,
    "page": 1,
    "pageSize": 20,
    "totalPages": 1
  }
}
```

**Permissions:** `admin.group.read`

### Create Group

Create a new user group.

**Endpoint:** `POST /cms-api/v1/admin/groups`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "name": "editors",
  "description": "Content editors",
  "id_group_types": 2,
  "requires_2fa": false,
  "acls": [
    {
      "resource_type": "page",
      "resource_id": null,
      "permissions": 6  // Read + Update
    }
  ]
}
```

**Permissions:** `admin.group.create`

### Update Group

Modify group properties and ACLs.

**Endpoint:** `PUT /cms-api/v1/admin/groups/{groupId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.update`

### Delete Group

Remove a group (users must be reassigned).

**Endpoint:** `DELETE /cms-api/v1/admin/groups/{groupId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.delete`

### Get Group ACLs

Retrieve access control lists for a group.

**Endpoint:** `GET /cms-api/v1/admin/groups/{groupId}/acls`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.read`

### Update Group ACLs

Modify group access control permissions.

**Endpoint:** `PUT /cms-api/v1/admin/groups/{groupId}/acls`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "acls": [
    {
      "resource_type": "page",
      "resource_id": 123,
      "permissions": 15  // All permissions
    }
  ]
}
```

**Permissions:** `admin.group.acl`

## Role Management

### Get Roles

Retrieve all available roles.

**Endpoint:** `GET /cms-api/v1/admin/roles`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:** Same pagination parameters as users

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Full system access",
        "permissions": [
          {
            "id": 1,
            "name": "admin.access",
            "description": "Can access admin area"
          }
        ],
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ],
    "total": 5,
    "page": 1,
    "pageSize": 20,
    "totalPages": 1
  }
}
```

**Permissions:** `admin.role.read`

### Create Role

Create a new role with assigned permissions.

**Endpoint:** `POST /cms-api/v1/admin/roles`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "name": "editor",
  "description": "Content editor",
  "permission_ids": [2, 3, 4, 5]
}
```

**Permissions:** `admin.role.create`

### Update Role

Modify role properties and permissions.

**Endpoint:** `PUT /cms-api/v1/admin/roles/{roleId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.update`

### Delete Role

Remove a role from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/roles/{roleId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.delete`

### Get Role Permissions

Retrieve permissions assigned to a specific role.

**Endpoint:** `GET /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.read`

### Add Permissions to Role

Assign additional permissions to a role.

**Endpoint:** `POST /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [6, 7, 8]
}
```

**Permissions:** `admin.role.permissions`

### Remove Permissions from Role

Remove permissions from a role.

**Endpoint:** `DELETE /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [6, 7]
}
```

**Permissions:** `admin.role.permissions`

### Update Role Permissions (Bulk)

Replace all permissions for a role.

**Endpoint:** `PUT /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [2, 3, 4, 5, 9, 10]
}
```

**Permissions:** `admin.role.permissions`

## Permission Management

### Get All Permissions

Retrieve the complete list of available permissions.

**Endpoint:** `GET /cms-api/v1/admin/permissions`

**Authentication:** Required (JWT Bearer token)

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "permissions": [
      {
        "id": 1,
        "name": "admin.access",
        "description": "Can view and enter the admin/backend area"
      },
      {
        "id": 2,
        "name": "admin.page.read",
        "description": "Can read existing pages"
      }
    ]
  }
}
```

**Permissions:** `admin.permission.read`

## Data Access Management

### Get Roles with Permissions

Retrieve roles and their custom data access permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles`

**Authentication:** Required (JWT Bearer token)

**Response:** Roles with their effective permissions

**Permissions:** `admin.role.read`

### Set Role Permissions

Set custom data access permissions for a role.

**Endpoint:** `POST /cms-api/v1/admin/data-access/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permissions": [
    {
      "resource_type": "page",
      "resource_id": 123,
      "crud_permissions": 15
    }
  ]
}
```

**Permissions:** `admin.role.update`

### Get Role Effective Permissions

Retrieve effective permissions for a role including inherited permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles/{roleId}/effective-permissions`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.read`

## Frontend Integration Examples

### User Management Dashboard

```javascript
const UserManagement = () => {
  const [users, setUsers] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [isEditing, setIsEditing] = useState(false);

  useEffect(() => {
    loadUsers();
  }, []);

  const loadUsers = async (page = 1, search = '') => {
    try {
      const params = new URLSearchParams({ page, pageSize: 20, search });
      const response = await apiRequest(`/admin/users?${params}`);
      setUsers(response.data.users);
    } catch (error) {
      console.error('Failed to load users:', error);
    }
  };

  const createUser = async (userData) => {
    try {
      const response = await apiRequest('/admin/users', {
        method: 'POST',
        body: JSON.stringify(userData)
      });
      setUsers([...users, response.data.user]);
      showSuccess('User created successfully');
    } catch (error) {
      showError('Failed to create user');
    }
  };

  const updateUser = async (userId, userData) => {
    try {
      const response = await apiRequest(`/admin/users/${userId}`, {
        method: 'PUT',
        body: JSON.stringify(userData)
      });
      setUsers(users.map(u => u.id === userId ? response.data.user : u));
      setIsEditing(false);
      showSuccess('User updated successfully');
    } catch (error) {
      showError('Failed to update user');
    }
  };

  const deleteUser = async (userId) => {
    if (!confirm('Are you sure you want to delete this user?')) return;

    try {
      await apiRequest(`/admin/users/${userId}`, {
        method: 'DELETE'
      });
      setUsers(users.filter(u => u.id !== userId));
      showSuccess('User deleted successfully');
    } catch (error) {
      showError('Failed to delete user');
    }
  };

  const toggleUserBlock = async (userId, blocked) => {
    try {
      await apiRequest(`/admin/users/${userId}/block`, {
        method: 'PATCH',
        body: JSON.stringify({ blocked })
      });
      loadUsers(); // Refresh list
      showSuccess(`User ${blocked ? 'blocked' : 'unblocked'}`);
    } catch (error) {
      showError('Failed to update user status');
    }
  };

  return (
    <div className="user-management">
      <div className="user-controls">
        <button onClick={() => setIsEditing(true)}>
          Add New User
        </button>
        <input
          type="search"
          placeholder="Search users..."
          onChange={(e) => loadUsers(1, e.target.value)}
        />
      </div>

      <div className="users-table">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Groups</th>
              <th>Roles</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {users.map(user => (
              <tr key={user.id}>
                <td>{user.name}</td>
                <td>{user.email}</td>
                <td>
                  <span className={user.blocked ? 'blocked' : 'active'}>
                    {user.blocked ? 'Blocked' : 'Active'}
                  </span>
                </td>
                <td>{user.groups.map(g => g.name).join(', ')}</td>
                <td>{user.roles.map(r => r.name).join(', ')}</td>
                <td>
                  <button onClick={() => setSelectedUser(user)}>
                    Edit
                  </button>
                  <button onClick={() => toggleUserBlock(user.id, !user.blocked)}>
                    {user.blocked ? 'Unblock' : 'Block'}
                  </button>
                  <button onClick={() => deleteUser(user.id)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {(isEditing || selectedUser) && (
        <UserForm
          user={selectedUser}
          onSubmit={selectedUser ? updateUser : createUser}
          onCancel={() => {
            setSelectedUser(null);
            setIsEditing(false);
          }}
        />
      )}
    </div>
  );
};
```

### Permission Matrix Component

```javascript
const PermissionMatrix = ({ roles, permissions }) => {
  const [rolePermissions, setRolePermissions] = useState({});

  useEffect(() => {
    loadRolePermissions();
  }, [roles]);

  const loadRolePermissions = async () => {
    const permissions = {};
    for (const role of roles) {
      try {
        const response = await apiRequest(`/admin/roles/${role.id}/permissions`);
        permissions[role.id] = response.data.permissions.map(p => p.id);
      } catch (error) {
        console.error(`Failed to load permissions for role ${role.id}:`, error);
      }
    }
    setRolePermissions(permissions);
  };

  const togglePermission = async (roleId, permissionId, hasPermission) => {
    try {
      const endpoint = `/admin/roles/${roleId}/permissions`;
      const method = hasPermission ? 'DELETE' : 'POST';

      await apiRequest(endpoint, {
        method,
        body: JSON.stringify({ permission_ids: [permissionId] })
      });

      // Update local state
      setRolePermissions(prev => ({
        ...prev,
        [roleId]: hasPermission
          ? prev[roleId].filter(id => id !== permissionId)
          : [...prev[roleId], permissionId]
      }));
    } catch (error) {
      console.error('Failed to update permission:', error);
    }
  };

  return (
    <div className="permission-matrix">
      <table>
        <thead>
          <tr>
            <th>Permission</th>
            {roles.map(role => (
              <th key={role.id}>{role.name}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {permissions.map(permission => (
            <tr key={permission.id}>
              <td>
                <div className="permission-info">
                  <strong>{permission.name}</strong>
                  <small>{permission.description}</small>
                </div>
              </td>
              {roles.map(role => {
                const hasPermission = rolePermissions[role.id]?.includes(permission.id);
                return (
                  <td key={role.id}>
                    <input
                      type="checkbox"
                      checked={hasPermission || false}
                      onChange={() => togglePermission(role.id, permission.id, hasPermission)}
                    />
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
```

## Security Considerations

1. **Password Policies**: Enforce strong password requirements
2. **Account Locking**: Implement account lockout after failed attempts
3. **Session Management**: Proper session timeout and invalidation
4. **Audit Logging**: Log all user management operations
5. **Permission Checks**: Always verify permissions before operations
6. **Data Validation**: Validate all user input thoroughly
7. **CSRF Protection**: Protect against cross-site request forgery
8. **Rate Limiting**: Implement rate limiting on authentication endpoints

## Best Practices

1. **Role-Based Access**: Use roles for broad permissions, groups for fine-grained access
2. **Principle of Least Privilege**: Grant minimum required permissions
3. **Regular Audits**: Regularly review user permissions and access
4. **Secure Passwords**: Enforce password complexity requirements
5. **Account Lifecycle**: Proper onboarding and offboarding procedures
6. **Documentation**: Keep permission schemes well-documented
7. **Testing**: Thoroughly test permission changes before deployment
8. **Monitoring**: Monitor for suspicious account activity

---

**Next:** [Admin Cache Management](./11-admin-cache.md) | **Previous:** [Admin Assets](./06-admin-assets.md) | **Back to:** [API Overview](../README.md)
