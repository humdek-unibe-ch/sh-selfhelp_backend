# Page Versioning & Publishing System

## 📋 Overview

The Page Versioning & Publishing System provides a robust, hybrid approach to managing page versions and publishing workflows. This system stores complete page structures while dynamically refreshing data to ensure users always see the most current information. The system supports multi-language content storage, real-time draft comparison, and fast unpublished changes detection.

## 🎯 Key Features

- **Hybrid Versioning**: Store page structure while re-running dynamic elements (data retrieval, conditions)
- **Multi-Language Support**: Store all language translations in a single version
- **Complete JSON Storage**: Store all languages, conditions, data table configs in published versions
- **Fresh Data**: Data tables are re-queried when serving published versions
- **Version Comparison**: Multiple diff formats (unified, side-by-side, JSON Patch, summary)
- **Draft Comparison**: Real-time comparison between current draft and published version
- **Fast Change Detection**: Hash-based detection of unpublished changes (< 50ms)
- **Retention Policies**: Automated cleanup of old versions
- **Security**: Draft exposure prevention with proper headers

## 🏗️ Architecture

### Hybrid Serving Approach

```mermaid
graph TD
    A[User Requests Page] --> B{Preview Mode?}
    B -->|Yes| C[Serve Draft from DB]
    B -->|No| D{Published Version Exists?}
    D -->|Yes| E[Load Stored JSON]
    D -->|No| F[Return 404 or Draft]
    E --> G[Re-run Data Retrieval]
    G --> H[Re-evaluate Conditions]
    H --> I[Apply Fresh Interpolation]
    I --> J[Serve Hydrated Page]
    C --> K[Apply ACL]
    K --> L[Serve Fresh Draft]
```

### Multi-Language Version Storage

Published versions store complete page structures with all language translations:

```json
{
  "page": {
    "id": 87,
    "url": "/forms",
    "keyword": "forms",
    "sections": [
      {
        "id": 78,
        "data_config": {...},
        "condition": "{...}",
        "translations": {
          "2": {
            "text": {"content": "English text", "meta": null},
            "label": {"content": "Label", "meta": null}
          },
          "3": {
            "text": {"content": "French text", "meta": null},
            "label": {"content": "Étiquette", "meta": null}
          }
        }
      }
    ]
  }
}
```

When serving, the system extracts the requested language and re-runs dynamic elements.

### Fast Unpublished Changes Detection

A hash-based system provides real-time status indicators:

```php
// Ultra-fast check (< 50ms)
$hasChanges = $pageVersionService->hasUnpublishedChanges($pageId);

// Returns: true if draft differs from published version
```

**Algorithm:**
1. Generate MD5 hash of normalized draft JSON
2. Generate MD5 hash of normalized published version JSON
3. Compare hashes (different = changes exist)

**Use Cases:**
- Real-time UI status indicators
- "Unpublished Changes" badges
- Navigation warnings
- Dashboard overviews

### What Gets Stored vs. What Gets Re-run

**Stored in Published JSON:**
- Page metadata (id, keyword, url, etc.)
- Section structure and hierarchy
- Field configurations and properties
- Translation content for all languages
- Data table configurations
- Condition definitions
- Style configurations

**Re-run Dynamically:**
- Data retrieval from data tables (using stored configs)
- Condition evaluation (user permissions, business logic)
- Variable interpolation with fresh data
- Cache invalidation logic

## 🗄️ Database Schema

### page_versions Table

```sql
CREATE TABLE `page_versions` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `id_pages` INT NOT NULL,
  `version_number` INT NOT NULL,
  `version_name` VARCHAR(255) DEFAULT NULL,
  `page_json` JSON NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` DATETIME DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_version_number` (`id_pages`, `version_number`),
  KEY `idx_id_pages` (`id_pages`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_published_at` (`published_at`),
  CONSTRAINT `fk_page_versions_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_page_versions_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### pages Table Update

```sql
ALTER TABLE `pages` 
  ADD COLUMN `published_version_id` INT DEFAULT NULL,
  ADD KEY `idx_published_version_id` (`published_version_id`),
  ADD CONSTRAINT `fk_pages_published_version` FOREIGN KEY (`published_version_id`) REFERENCES `page_versions` (`id`) ON DELETE SET NULL;
```

## 🔌 API Endpoints

### Admin Endpoints

#### Publish New Version
```http
POST /cms-api/v1/admin/pages/{page_id}/versions/publish
Content-Type: application/json

{
  "version_name": "Release v1.2",
  "metadata": {
    "description": "Added new feature X",
    "tags": ["release", "feature-x"]
  }
}
```

#### Publish Specific Version
```http
POST /cms-api/v1/admin/pages/{page_id}/versions/{version_id}/publish
```

#### Unpublish Page
```http
POST /cms-api/v1/admin/pages/{page_id}/versions/unpublish
```

#### List Versions
```http
GET /cms-api/v1/admin/pages/{page_id}/versions?limit=10&offset=0
```

**Enhanced Response (includes unpublished changes status):**
```json
{
  "success": true,
  "data": {
    "versions": [
      {
        "id": 45,
        "version_number": 3,
        "version_name": "Homepage v3",
        "created_by": {"id": 1, "name": "Admin User"},
        "created_at": "2025-10-22T10:00:00+00:00",
        "published_at": "2025-10-22T10:00:00+00:00",
        "is_published": true,
        "metadata": null
      }
    ],
    "pagination": {
      "total_count": 3,
      "limit": 10,
      "offset": 0
    },
    "current_published_version_id": 45,
    "has_unpublished_changes": true
  }
}
```

The `has_unpublished_changes` flag is automatically calculated using fast hash comparison.

#### Get Version Details
```http
GET /cms-api/v1/admin/pages/{page_id}/versions/{version_id}?include_page_json=true
```

#### Compare Versions
```http
GET /cms-api/v1/admin/pages/{page_id}/versions/compare/{v1}/{v2}?format=unified
```

Supported formats:
- `unified`: Unified diff format (default)
- `side_by_side`: Side-by-side HTML comparison
- `json_patch`: JSON Patch (RFC 6902) format
- `summary`: High-level summary of changes

#### Delete Version
```http
DELETE /cms-api/v1/admin/pages/{page_id}/versions/{version_id}
```

#### Compare Draft with Published Version
```http
GET /cms-api/v1/admin/pages/{page_id}/versions/compare-draft/{version_id}?format=side_by_side
```

**Formats:**
- `unified`: Standard unified diff format
- `side_by_side`: HTML side-by-side comparison (default)
- `json_patch`: JSON Patch (RFC 6902) operations
- `summary`: High-level change summary

**Response:**
```json
{
  "success": true,
  "data": {
    "draft": {
      "id_pages": 123,
      "keyword": "homepage",
      "updated_at": "2025-10-23T14:30:00Z"
    },
    "published_version": {
      "id": 45,
      "version_number": 3,
      "published_at": "2025-10-22T10:00:00Z"
    },
    "diff": "<html>... side-by-side diff ...</html>",
    "format": "side_by_side"
  }
}
```

#### Fast Unpublished Changes Check
```http
GET /cms-api/v1/admin/pages/{page_id}/versions/has-changes
```

**Response:**
```json
{
  "success": true,
  "data": {
    "page_id": 123,
    "has_unpublished_changes": true,
    "current_published_version_id": 45,
    "current_published_version_number": 3
  }
}
```

**Performance:** < 50ms typical, 100% accurate binary detection.

### Frontend Endpoints

#### Get Page (with Versioning Support)
```http
GET /cms-api/v1/pages/{page_id}?preview=false&language_id=1
```

Parameters:
- `preview`: Set to `true` to force draft serving (requires authentication)
- `language_id`: Optional language ID for translations

**Security Headers for Preview Mode:**
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`
- `X-Robots-Tag: noindex, nofollow`

## 💻 Service Layer

### PageVersionService

```php
use App\Service\CMS\Admin\PageVersionService;

// Create a new version
$version = $pageVersionService->createVersion(
    $pageId,
    'Version Name',
    ['description' => 'Change summary'],
    $languageId
);

// Publish a version
$publishedVersion = $pageVersionService->publishVersion($pageId, $versionId);

// Create and publish in one step
$version = $pageVersionService->createAndPublishVersion(
    $pageId,
    'Release v1.0',
    ['tags' => ['release']]
);

// Unpublish
$pageVersionService->unpublishPage($pageId);

// Get published version
$publishedVersion = $pageVersionService->getPublishedVersion($pageId);

// Get version history
$history = $pageVersionService->getVersionHistory($pageId, $limit, $offset);

// Compare versions
$comparison = $pageVersionService->compareVersions($v1Id, $v2Id, 'unified');

// Compare draft with published version
$draftComparison = $pageVersionService->compareDraftWithVersion($pageId, $versionId, 'side_by_side');

// Fast unpublished changes check
$hasChanges = $pageVersionService->hasUnpublishedChanges($pageId);

// Generate structure hash for comparison
$hash = $pageVersionService->generateStructureHash($pageStructure);

// Apply retention policy
$deletedCount = $pageVersionService->applyRetentionPolicy($pageId, $keepCount = 10);
```

### PageService (Modified)

```php
use App\Service\CMS\Frontend\PageService;

// Get page (respects published version)
$page = $pageService->getPage($pageId, $languageId, $preview = false);

// Force draft serving
$draft = $pageService->getPage($pageId, $languageId, $preview = true);
```

## 🔧 Utilities

### JsonNormalizer

```php
use App\Util\JsonNormalizer;

// Normalize JSON for consistent comparison
$normalized = JsonNormalizer::normalize($data);

// Get difference summary
$summary = JsonNormalizer::getDifferenceSummary($data1, $data2);

// Create diff-friendly structure
$grouped = JsonNormalizer::createDiffFriendlyStructure($pageData);
```

## 🎨 Console Commands

### Retention Policy Command

```bash
# Apply retention policy to all pages (keep last 10 versions)
php bin/console app:page-version:retention --keep=10

# Apply to specific page
php bin/console app:page-version:retention --keep=20 --page=5

# Dry run (show what would be deleted)
php bin/console app:page-version:retention --keep=10 --dry-run
```

## 🔒 Security Considerations

### Access Control
- Admin endpoints require `admin.page_version.*` permissions
- Preview/draft mode requires proper page ACL
- Published pages use standard page ACL

### Draft Exposure Prevention
- Draft content NEVER exposed to public users
- Preview mode sets `no-cache` headers
- `X-Robots-Tag: noindex` prevents search engine indexing
- 404 returned for unpublished pages (no draft fallback for public)

### Data Integrity
- Version numbers are sequential and unique per page
- Only one published version per page at a time
- Published versions cannot be deleted (must unpublish first)
- All operations logged via TransactionService

## 📊 Version Comparison Formats

### Unified Diff
Standard diff format showing line-by-line changes.

### Side-by-Side
HTML diff with side-by-side comparison, highlighting word-level changes.

### JSON Patch (RFC 6902)
Structured patch operations:
```json
[
  {"op": "add", "path": "/sections/0/title", "value": "New Title"},
  {"op": "remove", "path": "/sections/1"},
  {"op": "replace", "path": "/sections/2/content", "value": "Updated"}
]
```

### Summary
High-level change summary:
```json
{
  "are_equal": false,
  "changes": [
    {"path": "sections.0.title", "type": "value_change", "old_value": "Old", "new_value": "New"},
    {"path": "sections.1", "type": "removal"},
    {"path": "sections.3", "type": "addition", "value": {...}}
  ]
}
```

## 📊 Performance Characteristics

### Hash-Based vs Full Diff Comparison

| Aspect | Hash Check | Full Diff |
|--------|------------|-----------|
| **Speed** | < 50ms | 500ms - 3s |
| **Memory** | Low | High |
| **Accuracy** | 100% (binary) | 100% (detailed) |
| **Use Case** | Status checks | Detailed review |
| **Scalability** | Excellent | Good |

### Performance by Page Size

| Page Size | Hash Check | Full Diff |
|-----------|-----------|-----------|
| Small (10 sections) | 10-20ms | 200-500ms |
| Medium (100 sections) | 30-50ms | 500ms-1s |
| Large (500 sections) | 40-80ms | 1-3s |
| Very Large (1000+ sections) | 60-100ms | 3-10s |

**Conclusion:** Hash check is **10-100x faster** than full diff comparison.

## 🧪 Testing

### Running Tests

```bash
# Run all versioning tests
vendor/bin/phpunit --group versioning

# Run service tests
vendor/bin/phpunit tests/Service/CMS/Admin/PageVersionServiceTest.php

# Run controller tests
vendor/bin/phpunit tests/Controller/Api/V1/Admin/PageVersionControllerTest.php
```

### Test Coverage
- ✅ Version creation (multi-language support)
- ✅ Version publishing/unpublishing
- ✅ Version comparison (unified, side-by-side, JSON Patch, summary)
- ✅ Draft vs published comparison
- ✅ Fast unpublished changes detection
- ✅ Hash-based change detection accuracy
- ✅ Retention policies
- ✅ Security validation
- ✅ API endpoints (all CRUD operations + new endpoints)
- ✅ Hybrid serving logic
- ✅ Multi-language extraction and serving

## 📈 Performance Optimization

### Caching Strategy
- Published versions cached with proper entity scopes
- Draft versions cached separately with user/language scopes
- Data table entity scopes for cache invalidation

### Storage Optimization
- MySQL 8 JSON type with native operations
- Indexed columns for fast queries
- Automated retention policies

### Query Optimization
- Batch operations for version history
- Efficient JSON comparisons
- Indexed foreign keys

## 🚀 Best Practices

1. **Version Names**: Use descriptive names and semantic versioning
2. **Metadata**: Include change summaries, tags, and relevant context
3. **Retention**: Set reasonable retention policies (10-20 versions)
4. **Testing**: Always test in preview mode before publishing
5. **Monitoring**: Track version creation frequency and storage usage

## 📝 Example Workflow

```php
// 1. Create and publish a new version
$version = $pageVersionService->createAndPublishVersion(
    $pageId,
    'Homepage Update - Feb 2025',
    [
        'description' => 'Updated hero section and added testimonials',
        'tags' => ['design-update', 'content-refresh'],
        'author' => 'John Doe'
    ]
);

// 2. Verify it's published
$publishedVersion = $pageVersionService->getPublishedVersion($pageId);
assert($publishedVersion->getId() === $version->getId());

// 3. Users now see published version
$page = $pageService->getPage($pageId); // Serves published version

// 4. Developers can preview changes
$draft = $pageService->getPage($pageId, null, true); // Serves draft

// 5. Compare with previous version
$comparison = $pageVersionService->compareVersions(
    $previousVersionId,
    $version->getId(),
    'side_by_side'
);

// 6. Apply retention policy
$deletedCount = $pageVersionService->applyRetentionPolicy($pageId, 10);
```

## 🔍 Troubleshooting

### Version Not Serving
- Check if version is published: `$version->isPublished()`
- Verify page has `published_version_id` set
- Check cache invalidation

### Draft Exposed to Public
- Verify security headers are set
- Check preview parameter handling
- Validate ACL permissions

### High Storage Usage
- Run retention policy command
- Review version creation frequency
- Consider compression for large pages

## 🎨 Frontend Integration

### TypeScript API Client Updates

**File:** `src/api/admin/page-version.api.ts`

```typescript
/**
 * Compare current draft with a specific version
 */
async compareDraftWithVersion(
  pageId: number,
  versionId: number,
  format: 'unified' | 'side_by_side' | 'json_patch' | 'summary' = 'side_by_side'
): Promise<IVersionComparisonResponse> {
  const response = await apiClient.get<IBaseApiResponse<IVersionComparisonResponse>>(
    `${API_CONFIG.ENDPOINTS.ADMIN_PAGE_VERSIONS_COMPARE_DRAFT(pageId, versionId)}?format=${format}`
  );
  return response.data.data;
}

/**
 * Check if page has unpublished changes (fast check)
 */
async hasUnpublishedChanges(pageId: number): Promise<{
  page_id: number;
  has_unpublished_changes: boolean;
  current_published_version_id: number | null;
  current_published_version_number: number | null;
}> {
  const response = await apiClient.get<IBaseApiResponse<any>>(
    API_CONFIG.ENDPOINTS.ADMIN_PAGE_VERSIONS_HAS_CHANGES(pageId)
  );
  return response.data.data;
}
```

**File:** `src/config/api.config.ts`

```typescript
export const API_CONFIG = {
  ENDPOINTS: {
    // ... existing endpoints ...
    ADMIN_PAGE_VERSIONS_COMPARE_DRAFT: (pageId: number, versionId: number) =>
      `/admin/pages/${pageId}/versions/compare-draft/${versionId}`,
    ADMIN_PAGE_VERSIONS_HAS_CHANGES: (pageId: number) =>
      `/admin/pages/${pageId}/versions/has-changes`,
  }
};
```

### React Hook Examples

```typescript
import { useQuery } from '@tanstack/react-query';
import { PageVersionApi } from '@/api/admin/page-version.api';

// Hook for draft comparison
export function useDraftComparison(pageId: number, publishedVersionId: number | null) {
  return useQuery({
    queryKey: ['draft-comparison', pageId, publishedVersionId],
    queryFn: () => PageVersionApi.compareDraftWithVersion(pageId, publishedVersionId!),
    enabled: !!pageId && !!publishedVersionId
  });
}

// Hook for change status (polls every 10 seconds)
export function usePageChangeStatus(pageId: number) {
  return useQuery({
    queryKey: ['page-change-status', pageId],
    queryFn: () => PageVersionApi.hasUnpublishedChanges(pageId),
    refetchInterval: 10000,
    keepPreviousData: true
  });
}

// Usage in component
function PageEditor({ pageId }) {
  const { data: status } = usePageChangeStatus(pageId);
  const { data: publishedVersion } = usePublishedVersion(pageId);
  const { data: comparison } = useDraftComparison(pageId, publishedVersion?.id);

  return (
    <div>
      {status?.has_unpublished_changes && (
        <Badge color="yellow">Unpublished Changes</Badge>
      )}

      {comparison && (
        <DiffViewer
          diff={comparison.diff}
          format={comparison.format}
        />
      )}
    </div>
  );
}
```

## 📚 Related Documentation

- [Transaction Logging](./12-transaction-logging.md)
- [API Security Architecture](../api-security-architecture.md)
- [CMS Architecture](./08-cms-architecture.md)
- [Development Workflow](./14-development-workflow.md)

