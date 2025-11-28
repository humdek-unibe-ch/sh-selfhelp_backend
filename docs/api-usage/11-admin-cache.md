# Admin Cache Management APIs

## Overview

The Admin Cache Management APIs provide comprehensive cache monitoring and management functionality for the SelfHelp CMS. These endpoints allow administrators to monitor cache performance, clear caches, and maintain optimal system performance.

## Core Concepts

### Cache Categories
- **pages**: Page content and metadata
- **sections**: Section content and configurations
- **assets**: File assets and media
- **users**: User data and sessions
- **permissions**: Permission and role data
- **translations**: Language translations
- **api_routes**: API route definitions
- **global**: Global application data

### Cache Statistics
- **Hit Rate**: Percentage of cache requests that are hits
- **Miss Rate**: Percentage of cache requests that are misses
- **Hit Count**: Total number of cache hits
- **Miss Count**: Total number of cache misses
- **Eviction Count**: Number of items evicted from cache

## Cache Monitoring

### Get Cache Statistics

Retrieve comprehensive cache statistics and performance metrics.

**Endpoint:** `GET /cms-api/v1/admin/cache/stats`

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
    "overall_stats": {
      "hit_rate": 0.85,
      "miss_rate": 0.15,
      "total_requests": 10000,
      "hit_count": 8500,
      "miss_count": 1500,
      "eviction_count": 250,
      "memory_usage": "45MB",
      "uptime": "2 days 4 hours"
    },
    "category_stats": {
      "pages": {
        "hit_rate": 0.92,
        "total_items": 500,
        "memory_usage": "12MB"
      },
      "users": {
        "hit_rate": 0.78,
        "total_items": 200,
        "memory_usage": "8MB"
      }
    },
    "top_performing_categories": [
      {
        "category": "pages",
        "hit_rate": 0.92,
        "total_requests": 5000
      },
      {
        "category": "sections",
        "hit_rate": 0.88,
        "total_requests": 3000
      }
    ]
  }
}
```

**Permissions:** `admin.cache.read`

### Get Cache Health

Check the overall health status of the cache system.

**Endpoint:** `GET /cms-api/v1/admin/cache/health`

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
    "status": "healthy",
    "response_time": 15,
    "memory_pressure": "normal",
    "connection_status": "connected",
    "last_error": null,
    "categories_status": {
      "pages": "healthy",
      "users": "healthy",
      "permissions": "healthy"
    }
  }
}
```

**Permissions:** `admin.cache.read`

### Get Category Statistics

Retrieve detailed statistics for a specific cache category.

**Endpoint:** `GET /cms-api/v1/admin/cache/category/{category}/stats`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `category`: Cache category name

**Response:** Detailed statistics for the specified category

**Permissions:** `admin.cache.read`

## Cache Clearing

### Clear All Caches

Clear all cache categories and reset statistics.

**Endpoint:** `POST /cms-api/v1/admin/cache/clear/all`

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
    "cleared": true,
    "timestamp": "2025-01-23T10:30:00Z",
    "cleared_categories": [
      "pages",
      "sections",
      "assets",
      "users",
      "permissions",
      "translations",
      "api_routes",
      "global"
    ]
  }
}
```

**Permissions:** `admin.cache.clear`

### Clear Cache Category

Clear a specific cache category.

**Endpoint:** `POST /cms-api/v1/admin/cache/clear/category`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "category": "pages"
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
    "cleared": true,
    "category": "pages",
    "timestamp": "2025-01-23T10:30:00Z",
    "items_cleared": 150
  }
}
```

**Permissions:** `admin.cache.clear`

### Clear User Cache

Clear cache entries specific to a user.

**Endpoint:** `POST /cms-api/v1/admin/cache/clear/user`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "user_id": 123
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
    "cleared": true,
    "user_id": 123,
    "timestamp": "2025-01-23T10:30:00Z",
    "items_cleared": 25
  }
}
```

**Permissions:** `admin.cache.clear`

### Clear API Routes Cache

Clear the cached API route definitions.

**Endpoint:** `POST /cms-api/v1/admin/cache/api-routes/clear`

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
    "cleared": true,
    "timestamp": "2025-01-23T10:30:00Z",
    "routes_refreshed": 150
  }
}
```

**Permissions:** `admin.cache.clear_api_routes`

## Cache Statistics Management

### Reset Cache Statistics

Reset all cache statistics counters to zero.

**Endpoint:** `POST /cms-api/v1/admin/cache/stats/reset`

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
    "reset": true,
    "timestamp": "2025-01-23T10:30:00Z"
  }
}
```

**Permissions:** `admin.cache.manage`

## Frontend Integration Examples

### Cache Dashboard Component

```javascript
const CacheDashboard = () => {
  const [stats, setStats] = useState(null);
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    loadCacheData();
  }, []);

  const loadCacheData = async () => {
    try {
      const [statsResponse, healthResponse] = await Promise.all([
        apiRequest('/admin/cache/stats'),
        apiRequest('/admin/cache/health')
      ]);

      setStats(statsResponse.data);
      setHealth(healthResponse.data);
    } catch (error) {
      console.error('Failed to load cache data:', error);
    }
  };

  const clearCache = async (category = null) => {
    setLoading(true);
    try {
      let endpoint, method, body;

      if (category === 'all') {
        endpoint = '/admin/cache/clear/all';
        method = 'POST';
      } else if (category) {
        endpoint = '/admin/cache/clear/category';
        method = 'POST';
        body = JSON.stringify({ category });
      } else {
        endpoint = '/admin/cache/clear/all';
        method = 'POST';
      }

      await apiRequest(endpoint, {
        method,
        body
      });

      showSuccess(`Cache${category ? ` (${category})` : ''} cleared successfully`);
      loadCacheData(); // Refresh stats
    } catch (error) {
      showError('Failed to clear cache');
    } finally {
      setLoading(false);
    }
  };

  const resetStats = async () => {
    try {
      await apiRequest('/admin/cache/stats/reset', {
        method: 'POST'
      });
      showSuccess('Cache statistics reset');
      loadCacheData();
    } catch (error) {
      showError('Failed to reset statistics');
    }
  };

  if (!stats || !health) return <div>Loading cache data...</div>;

  return (
    <div className="cache-dashboard">
      <div className="cache-header">
        <h2>Cache Management</h2>
        <div className="cache-actions">
          <button onClick={() => clearCache('all')} disabled={loading}>
            Clear All Caches
          </button>
          <button onClick={resetStats}>
            Reset Statistics
          </button>
        </div>
      </div>

      <div className="cache-health">
        <h3>Cache Health</h3>
        <div className={`health-status ${health.status}`}>
          <span className="status-indicator"></span>
          {health.status.toUpperCase()}
        </div>
        <div className="health-metrics">
          <div>Response Time: {health.response_time}ms</div>
          <div>Memory Pressure: {health.memory_pressure}</div>
          <div>Connection: {health.connection_status}</div>
        </div>
      </div>

      <div className="cache-stats">
        <h3>Overall Statistics</h3>
        <div className="stats-grid">
          <div className="stat-card">
            <div className="stat-value">{(stats.overall_stats.hit_rate * 100).toFixed(1)}%</div>
            <div className="stat-label">Hit Rate</div>
          </div>
          <div className="stat-card">
            <div className="stat-value">{stats.overall_stats.total_requests.toLocaleString()}</div>
            <div className="stat-label">Total Requests</div>
          </div>
          <div className="stat-card">
            <div className="stat-value">{stats.overall_stats.memory_usage}</div>
            <div className="stat-label">Memory Usage</div>
          </div>
          <div className="stat-card">
            <div className="stat-value">{stats.overall_stats.uptime}</div>
            <div className="stat-label">Uptime</div>
          </div>
        </div>
      </div>

      <div className="category-stats">
        <h3>Category Performance</h3>
        <div className="categories-grid">
          {Object.entries(stats.category_stats).map(([category, catStats]) => (
            <div key={category} className="category-card">
              <div className="category-header">
                <h4>{category}</h4>
                <button onClick={() => clearCache(category)} disabled={loading}>
                  Clear
                </button>
              </div>
              <div className="category-metrics">
                <div>Hit Rate: {(catStats.hit_rate * 100).toFixed(1)}%</div>
                <div>Items: {catStats.total_items}</div>
                <div>Memory: {catStats.memory_usage}</div>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="top-categories">
        <h3>Top Performing Categories</h3>
        <div className="top-list">
          {stats.top_performing_categories.map((category, index) => (
            <div key={category.category} className="top-item">
              <span className="rank">#{index + 1}</span>
              <span className="category">{category.category}</span>
              <span className="rate">{(category.hit_rate * 100).toFixed(1)}%</span>
              <span className="requests">{category.total_requests} requests</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};
```

### Cache Performance Chart

```javascript
const CachePerformanceChart = ({ stats }) => {
  const [timeRange, setTimeRange] = useState('24h');

  // Assuming we have historical data
  const chartData = useMemo(() => {
    // Process stats for chart display
    return {
      labels: ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00'],
      datasets: [
        {
          label: 'Hit Rate',
          data: [0.85, 0.87, 0.82, 0.89, 0.91, 0.88],
          borderColor: 'rgb(75, 192, 192)',
          backgroundColor: 'rgba(75, 192, 192, 0.2)',
        },
        {
          label: 'Memory Usage (MB)',
          data: [42, 45, 38, 52, 48, 45],
          borderColor: 'rgb(255, 99, 132)',
          backgroundColor: 'rgba(255, 99, 132, 0.2)',
          yAxisID: 'y1',
        }
      ]
    };
  }, [stats, timeRange]);

  return (
    <div className="cache-performance-chart">
      <div className="chart-header">
        <h3>Cache Performance Over Time</h3>
        <select value={timeRange} onChange={(e) => setTimeRange(e.target.value)}>
          <option value="1h">Last Hour</option>
          <option value="24h">Last 24 Hours</option>
          <option value="7d">Last 7 Days</option>
          <option value="30d">Last 30 Days</option>
        </select>
      </div>

      <div className="chart-container">
        <Line
          data={chartData}
          options={{
            responsive: true,
            scales: {
              y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                  display: true,
                  text: 'Hit Rate (%)'
                },
                ticks: {
                  callback: (value) => `${(value * 100).toFixed(0)}%`
                }
              },
              y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                  display: true,
                  text: 'Memory Usage (MB)'
                },
                grid: {
                  drawOnChartArea: false,
                },
              },
            },
          }}
        />
      </div>
    </div>
  );
};
```

### Cache Category Manager

```javascript
const CacheCategoryManager = ({ categories }) => {
  const [selectedCategories, setSelectedCategories] = useState([]);

  const toggleCategory = (category) => {
    setSelectedCategories(prev =>
      prev.includes(category)
        ? prev.filter(c => c !== category)
        : [...prev, category]
    );
  };

  const clearSelectedCategories = async () => {
    if (selectedCategories.length === 0) return;

    try {
      await Promise.all(
        selectedCategories.map(category =>
          apiRequest('/admin/cache/clear/category', {
            method: 'POST',
            body: JSON.stringify({ category })
          })
        )
      );

      showSuccess(`Cleared ${selectedCategories.length} cache categories`);
      setSelectedCategories([]);
    } catch (error) {
      showError('Failed to clear selected categories');
    }
  };

  return (
    <div className="cache-category-manager">
      <div className="manager-header">
        <h3>Bulk Cache Operations</h3>
        <button
          onClick={clearSelectedCategories}
          disabled={selectedCategories.length === 0}
        >
          Clear Selected ({selectedCategories.length})
        </button>
      </div>

      <div className="categories-list">
        {categories.map(category => (
          <div key={category} className="category-item">
            <label>
              <input
                type="checkbox"
                checked={selectedCategories.includes(category)}
                onChange={() => toggleCategory(category)}
              />
              <span className="category-name">{category}</span>
            </label>
            <button
              onClick={() => clearCache(category)}
              className="clear-single"
            >
              Clear
            </button>
          </div>
        ))}
      </div>
    </div>
  );
};
```

## Best Practices

1. **Monitor Regularly**: Keep an eye on cache hit rates and memory usage
2. **Strategic Clearing**: Clear caches during low-traffic periods
3. **Category-Specific**: Use category-specific clearing for targeted cache management
4. **Performance Tracking**: Monitor the impact of cache operations on system performance
5. **Automated Clearing**: Set up automated cache clearing for maintenance windows
6. **Backup Before Clearing**: Consider the impact on user experience before mass clearing
7. **Statistics Analysis**: Use statistics to identify optimization opportunities
8. **Health Monitoring**: Regularly check cache health and connectivity

## Cache Categories Reference

| Category | Description | When to Clear |
|----------|-------------|---------------|
| `pages` | Page content and metadata | After content updates |
| `sections` | Section configurations and content | After section changes |
| `assets` | File assets and media references | After asset uploads/deletions |
| `users` | User data and permissions | After user/permission changes |
| `permissions` | Role and permission data | After permission updates |
| `translations` | Language translations | After translation updates |
| `api_routes` | API route definitions | After route configuration changes |
| `global` | Global application data | For major system updates |

---

**Next:** [Admin Audit & Data Access](./12-admin-audit.md) | **Previous:** [Admin Users](./07-admin-users.md) | **Back to:** [API Overview](../README.md)
