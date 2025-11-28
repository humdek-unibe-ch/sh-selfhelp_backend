# Admin Audit & Data Access APIs

## Overview

The Admin Audit & Data Access APIs provide comprehensive security monitoring and audit trail functionality for the SelfHelp CMS. These endpoints allow administrators to track all data access operations, monitor security events, and analyze system usage patterns.

## Core Concepts

### Audit Logs
- **Data Access Tracking**: Every data access operation is logged
- **Permission Checks**: Record of permission evaluation results
- **User Activity**: Complete trail of user actions
- **Security Monitoring**: Detection of suspicious activities

### Audit Data
- **Resource Types**: Type of resource being accessed (users, pages, etc.)
- **Action Types**: Type of operation (read, create, update, delete)
- **Permission Results**: Whether access was granted or denied
- **Context Information**: IP address, user agent, timestamps

## Audit Log Retrieval

### Get Data Access Logs

Retrieve paginated audit logs with comprehensive filtering options.

**Endpoint:** `GET /cms-api/v1/admin/audit/data-access`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `user_id`: Filter by specific user ID
- `resource_type`: Filter by resource type (e.g., 'users', 'pages')
- `action`: Filter by action type (e.g., 'read', 'create')
- `permission_result`: Filter by permission result ('granted', 'denied')
- `date_from`: Start date for filtering (YYYY-MM-DD)
- `date_to`: End date for filtering (YYYY-MM-DD)
- `page`: Page number (default: 1)
- `pageSize`: Items per page (default: 20)

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
    "logs": [
      {
        "id": 12345,
        "user_id": 456,
        "user_email": "admin@example.com",
        "resource_type": "pages",
        "resource_id": 789,
        "action": "read",
        "permission_result": "granted",
        "crud_permission": 4,
        "http_method": "GET",
        "request_uri": "/cms-api/v1/admin/pages/789",
        "ip_address": "192.168.1.100",
        "user_agent": "Mozilla/5.0...",
        "created_at": "2025-01-23T10:25:00Z"
      }
    ],
    "total": 1250,
    "page": 1,
    "pageSize": 20,
    "totalPages": 63
  }
}
```

**Permissions:** `admin.audit.view`

### Get Specific Audit Log

Retrieve detailed information about a single audit log entry.

**Endpoint:** `GET /cms-api/v1/admin/audit/data-access/{id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `id`: Audit log ID

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
    "audit_log": {
      "id": 12345,
      "user": {
        "id": 456,
        "email": "admin@example.com",
        "name": "System Administrator"
      },
      "resource_type": "pages",
      "resource_id": 789,
      "action": "update",
      "permission_result": "granted",
      "crud_permission": 6,
      "http_method": "PUT",
      "request_uri": "/cms-api/v1/admin/pages/789",
      "request_body_hash": "abc123...",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
      "notes": "Page content updated",
      "created_at": "2025-01-23T10:25:00Z"
    }
  }
}
```

**Permissions:** `admin.audit.view`

### Get Audit Statistics

Retrieve summary statistics and analytics from audit data.

**Endpoint:** `GET /cms-api/v1/admin/audit/data-access/stats`

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
    "total_logs": 15420,
    "time_range": {
      "from": "2025-01-01T00:00:00Z",
      "to": "2025-01-23T10:30:00Z"
    },
    "access_patterns": {
      "by_resource_type": {
        "users": 4520,
        "pages": 6890,
        "assets": 2340,
        "permissions": 1670
      },
      "by_action": {
        "read": 12050,
        "create": 1450,
        "update": 1520,
        "delete": 400
      },
      "by_result": {
        "granted": 14980,
        "denied": 440
      }
    },
    "security_metrics": {
      "failed_access_attempts": 440,
      "top_blocked_resources": [
        {
          "resource_type": "users",
          "resource_id": 999,
          "attempts": 25
        }
      ],
      "suspicious_patterns": [
        {
          "pattern": "rapid_access_attempts",
          "count": 15,
          "severity": "medium"
        }
      ]
    },
    "user_activity": {
      "most_active_users": [
        {
          "user_id": 456,
          "user_email": "admin@example.com",
          "total_actions": 1250
        }
      ],
      "recent_activity": [
        {
          "user_id": 456,
          "last_action": "2025-01-23T10:25:00Z",
          "actions_last_24h": 45
        }
      ]
    }
  }
}
```

**Permissions:** `admin.audit.view`

## Data Access Management

### Get Roles with Permissions

Retrieve all roles and their associated data access permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles`

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
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Full system access",
        "data_access_permissions": [
          {
            "id": 100,
            "resource_type": "users",
            "resource_id": null,
            "crud_permissions": 15,
            "created_at": "2024-01-15T10:30:00Z"
          }
        ]
      }
    ]
  }
}
```

**Permissions:** `admin.role.read`

### Set Role Permissions

Configure custom data access permissions for a role.

**Endpoint:** `POST /cms-api/v1/admin/data-access/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `roleId`: Role ID

**Request Body:**
```json
{
  "permissions": [
    {
      "resource_type": "pages",
      "resource_id": 123,
      "crud_permissions": 6
    },
    {
      "resource_type": "users",
      "resource_id": null,
      "crud_permissions": 4
    }
  ]
}
```

**Permissions:** `admin.role.update`

### Get Role Effective Permissions

Retrieve the complete set of effective permissions for a role, including inherited permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles/{roleId}/effective-permissions`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `roleId`: Role ID

**Response:** Complete permission set including base permissions and custom overrides

**Permissions:** `admin.role.read`

## Frontend Integration Examples

### Audit Log Viewer

```javascript
const AuditLogViewer = () => {
  const [logs, setLogs] = useState([]);
  const [filters, setFilters] = useState({
    user_id: '',
    resource_type: '',
    action: '',
    date_from: '',
    date_to: ''
  });
  const [pagination, setPagination] = useState({
    page: 1,
    pageSize: 50
  });

  const loadAuditLogs = async () => {
    try {
      const params = new URLSearchParams({
        ...filters,
        ...pagination
      });

      const response = await apiRequest(`/admin/audit/data-access?${params}`);
      setLogs(response.data.logs);
      setPagination(prev => ({
        ...prev,
        total: response.data.total,
        totalPages: response.data.totalPages
      }));
    } catch (error) {
      console.error('Failed to load audit logs:', error);
    }
  };

  useEffect(() => {
    loadAuditLogs();
  }, [filters, pagination.page]);

  const exportLogs = async () => {
    try {
      const params = new URLSearchParams(filters);
      const response = await apiRequest(`/admin/audit/data-access?${params}&export=csv`);

      // Download CSV file
      const blob = new Blob([response.data], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'audit-logs.csv';
      a.click();
    } catch (error) {
      console.error('Failed to export logs:', error);
    }
  };

  return (
    <div className="audit-log-viewer">
      <div className="viewer-header">
        <h2>Security Audit Logs</h2>
        <button onClick={exportLogs}>Export CSV</button>
      </div>

      <div className="filters">
        <select
          value={filters.resource_type}
          onChange={(e) => setFilters({...filters, resource_type: e.target.value})}
        >
          <option value="">All Resource Types</option>
          <option value="users">Users</option>
          <option value="pages">Pages</option>
          <option value="assets">Assets</option>
        </select>

        <select
          value={filters.action}
          onChange={(e) => setFilters({...filters, action: e.target.value})}
        >
          <option value="">All Actions</option>
          <option value="read">Read</option>
          <option value="create">Create</option>
          <option value="update">Update</option>
          <option value="delete">Delete</option>
        </select>

        <input
          type="date"
          value={filters.date_from}
          onChange={(e) => setFilters({...filters, date_from: e.target.value})}
          placeholder="From Date"
        />

        <input
          type="date"
          value={filters.date_to}
          onChange={(e) => setFilters({...filters, date_to: e.target.value})}
          placeholder="To Date"
        />
      </div>

      <div className="logs-table">
        <table>
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>Resource</th>
              <th>Result</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            {logs.map(log => (
              <tr key={log.id} className={log.permission_result === 'denied' ? 'denied' : 'granted'}>
                <td>{new Date(log.created_at).toLocaleString()}</td>
                <td>{log.user_email}</td>
                <td>{log.action}</td>
                <td>{`${log.resource_type}:${log.resource_id}`}</td>
                <td>
                  <span className={`result-${log.permission_result}`}>
                    {log.permission_result}
                  </span>
                </td>
                <td>{log.ip_address}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="pagination">
        <button
          disabled={pagination.page <= 1}
          onClick={() => setPagination({...pagination, page: pagination.page - 1})}
        >
          Previous
        </button>
        <span>Page {pagination.page} of {pagination.totalPages}</span>
        <button
          disabled={pagination.page >= pagination.totalPages}
          onClick={() => setPagination({...pagination, page: pagination.page + 1})}
        >
          Next
        </button>
      </div>
    </div>
  );
};
```

### Security Dashboard

```javascript
const SecurityDashboard = () => {
  const [stats, setStats] = useState(null);
  const [alerts, setAlerts] = useState([]);

  useEffect(() => {
    loadSecurityData();
  }, []);

  const loadSecurityData = async () => {
    try {
      const response = await apiRequest('/admin/audit/data-access/stats');
      setStats(response.data);

      // Generate alerts based on statistics
      const newAlerts = [];
      if (response.data.security_metrics.failed_access_attempts > 100) {
        newAlerts.push({
          type: 'warning',
          message: 'High number of failed access attempts detected',
          count: response.data.security_metrics.failed_access_attempts
        });
      }

      setAlerts(newAlerts);
    } catch (error) {
      console.error('Failed to load security data:', error);
    }
  };

  return (
    <div className="security-dashboard">
      <h2>Security Monitoring</h2>

      {alerts.length > 0 && (
        <div className="security-alerts">
          {alerts.map((alert, index) => (
            <div key={index} className={`alert alert-${alert.type}`}>
              {alert.message} ({alert.count})
            </div>
          ))}
        </div>
      )}

      {stats && (
        <div className="security-metrics">
          <div className="metric-grid">
            <div className="metric-card">
              <h3>Total Logs</h3>
              <div className="metric-value">{stats.total_logs.toLocaleString()}</div>
            </div>

            <div className="metric-card">
              <h3>Access Success Rate</h3>
              <div className="metric-value">
                {((stats.access_patterns.by_result.granted /
                   (stats.access_patterns.by_result.granted +
                    stats.access_patterns.by_result.denied)) * 100).toFixed(1)}%
              </div>
            </div>

            <div className="metric-card">
              <h3>Failed Attempts</h3>
              <div className="metric-value warning">
                {stats.security_metrics.failed_access_attempts}
              </div>
            </div>
          </div>

          <div className="activity-chart">
            <h3>Access Patterns by Resource Type</h3>
            {/* Chart component would go here */}
            <div className="resource-breakdown">
              {Object.entries(stats.access_patterns.by_resource_type).map(([type, count]) => (
                <div key={type} className="resource-item">
                  <span className="resource-type">{type}</span>
                  <span className="resource-count">{count}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
```

## Best Practices

1. **Regular Monitoring**: Check audit logs regularly for suspicious activity
2. **Alert Configuration**: Set up alerts for unusual access patterns
3. **Data Retention**: Implement appropriate log retention policies
4. **Performance Impact**: Monitor the performance impact of audit logging
5. **Privacy Compliance**: Ensure audit data handling complies with privacy regulations
6. **Log Analysis**: Use automated tools to analyze audit logs for patterns
7. **Incident Response**: Use audit logs for security incident investigation
8. **Access Review**: Regularly review who has access to audit logs

## Audit Data Reference

### Resource Types
- `users` - User accounts and profiles
- `pages` - CMS pages and content
- `sections` - Page sections and components
- `assets` - File assets and media
- `groups` - User groups
- `roles` - System roles and permissions

### Action Types
- `read` - Data retrieval operations
- `create` - New resource creation
- `update` - Resource modification
- `delete` - Resource removal

### Permission Results
- `granted` - Access was allowed
- `denied` - Access was blocked

### CRUD Permissions (Bit Flags)
- `1` - Create
- `2` - Read
- `4` - Update
- `8` - Delete
- `15` - Full access (Create + Read + Update + Delete)

---

**Next:** [Admin Scheduled Jobs](./13-admin-scheduled-jobs.md) | **Previous:** [Admin Cache](./11-admin-cache.md) | **Back to:** [API Overview](../README.md)
