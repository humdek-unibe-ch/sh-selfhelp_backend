# Page Versioning & Publishing System

## Overview
Implement a page versioning and publishing system using a **hybrid approach**:

- **Published versions** store complete JSON structures (all languages, conditions, data table configs) for consistency
- **Dynamic elements** (data retrieval, condition evaluation) are re-run when serving published versions to ensure freshness
- **Drafts** are always loaded fresh from database (no JSON storage) for development

This approach ensures published pages remain consistent while keeping dynamic content current.

## Hybrid Serving Architecture

### Published Version Serving
When serving a published version:
1. **Load stored JSON structure** from `page_versions` table (contains all languages, conditions, data table configs)
2. **Re-run data retrieval** using stored data table configurations to get fresh data
3. **Re-evaluate conditions** using current context (user permissions, time-based conditions, etc.)
4. **Apply translations** using stored language data, but allow fallback to current translations if needed

### What Gets Stored vs. What Gets Re-run
**Stored in published JSON:**
- Page metadata (id, keyword, url, etc.)
- Section structure and hierarchy
- Field configurations and properties
- Translation content for all languages
- Data table configurations
- Condition definitions
- Style configurations

**Re-run dynamically:**
- Data retrieval from data tables (using stored configs)
- Condition evaluation (user permissions, business logic)
- Variable interpolation with fresh data
- Cache invalidation logic

**Why this hybrid approach matters:**
- Prevents stale data in published pages (data tables might be updated)
- Ensures conditions are evaluated with current context (user permissions, time-based logic)
- Maintains published page consistency while allowing dynamic content freshness
- Users won't miss important updates when refreshing published pages

## Core Requirements
- Store complete published page JSON structures as versions (including all languages, conditions, data table configs)
- Drafts are always loaded fresh from database (no JSON storage)
- Published versions serve stored structure but re-run dynamic elements (data retrieval, condition evaluation)
- Publish/unpublish page versions for end users
- Version comparison using php-diff library
- Version restoration functionality

## Database Schema Changes

### 1. New Table: `page_versions`
- **Purpose**: Store complete published page JSON structures as versions
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `id_pages` (FOREIGN KEY to pages.id)
  - `version_number` (INT, incremental per page)
  - `version_name` (VARCHAR, optional user-defined name)
  - `page_json` (JSON, complete JSON structure from getPage())
  - `created_by` (INT, user who created the version)
  - `created_at` (DATETIME)
  - `published_at` (DATETIME, when version was published)
  - `metadata` (JSON, additional info like change summary)

### 2. Update `pages` table
- Add `published_version_id` (INT, points to currently published version in page_versions table)

## API Endpoints

### Publishing Management
- `POST /admin/pages/{page_id}/versions/publish/{version_id}` - Publish specific version
- `POST /admin/pages/{page_id}/versions/unpublish` - Unpublish current version
- `GET /admin/pages/{page_id}/versions` - List all versions for a page
- `GET /admin/pages/{page_id}/versions/{version_id}` - Get specific version details
- `GET /admin/pages/{page_id}/versions/compare/{v1}/{v2}` - Compare two versions

### Frontend Serving
- `GET /pages/{page_keyword}` - Serve published version from page_versions table
- `GET /pages/{page_keyword}/preview` - Serve current draft from database (existing getPage logic)
- `GET /pages/{page_keyword}/version/{version_id}` - Serve specific published version

## Service Layer Changes

### 1. PageVersionService
- `createVersion($pageId, $pageJson, $versionName, $userId)` - Create new published version
- `publishVersion($pageId, $versionId)` - Set specific version as current published version
- `getPublishedVersion($pageId)` - Get published version JSON from page_versions table
- `getVersionById($versionId)` - Get specific version JSON
- `compareVersions($versionId1, $versionId2)` - Compare two versions using php-diff library
- `getPageVersions($pageId)` - List all versions for a page
- `unpublishPage($pageId)` - Remove published version (serve draft to all users)

### 2. Modified PageService
- `getPage()` method updated to:
  - Check if published version exists in page_versions table
  - For published versions: load stored JSON structure and re-run dynamic elements (data retrieval, conditions)
  - For drafts: return fresh data from database for developers/admins (existing getPage logic)
  - Add preview parameter to force draft serving
- `getPageSections()` method updated for hybrid serving:
  - For published versions: use stored section structure but re-run data retrieval and condition evaluation
  - For drafts: use existing database-driven logic
- New `servePublishedVersion()` method to handle hybrid serving logic
- New `hydratePublishedPage()` method to re-run dynamic elements on stored structure
- New `hydratePublishedSections()` method to refresh dynamic content in stored sections

## Version Comparison Features

### 1. Semantic JSON Diff with Multiple Formats
- **JSON Patch (RFC 6902)**: Semantic diff showing add/remove/replace operations
- **JSON Merge Patch**: Simplified patch format for partial updates
- **php-diff library**: For human-readable side-by-side views with normalized, pretty-printed JSON
- **Normalization**: Stable key ordering and consistent formatting to reduce noise
- **Multi-format support**: Unified, side-by-side HTML, and semantic patch outputs
- **Content-aware highlighting**: Changes in page content, sections, and translations

### 2. Visual Diff Interface
- Web interface to compare versions side-by-side
- Display diffs in multiple formats (unified, split view)
- Show changes in page structure and content
- Allow filtering by content sections

## Implementation Strategy

### Fresh Implementation
- First version implementation - no legacy compatibility concerns
- All pages start without published versions (serve drafts by default)
- Users must explicitly publish versions to make them live
- Existing getPage functionality remains unchanged for draft serving

## Security Considerations

### 1. Access Control
- Only authorized users can create/modify versions
- Separate permissions for publishing vs. editing
- Version history access controls

### 2. Data Integrity
- Version numbers must be sequential and unique per page
- Only one published version per page at a time
- Audit trail for all version operations

## Dependencies
- Add `jfcherng/php-diff` package to composer.json for version comparison functionality

## Storage Optimization

### Version Storage Strategy (MySQL 8)
- Use MySQL JSON type for `page_json` column with native JSON operations
- Implement gzip compression for large JSON blobs with length tracking
- Consider sharding large assets (per-section storage) for very large pages
- Implement retention policies (keep last N versions, or all published + GC drafts)

### Cache Security & Draft Exposure Prevention
- **Strict authentication** required for all preview/draft endpoints
- **Cache-Control: no-store** headers on draft/preview responses
- **X-Robots-Tag: noindex** to prevent search engine indexing
- **Return 404 for unpublished pages** to public users (never serve drafts)
- **CDN/proxy-safe** headers to prevent accidental caching of sensitive content

## Implementation Tasks

### Phase 1: Database & Core Infrastructure
1. Create `page_versions` table with MySQL 8 JSON type and proper indexing
2. Update `pages` table to add `published_version_id` column
3. Create PageVersion entity with Doctrine JSON type mappings
4. Create PageVersionRepository with JSON query methods
5. Add jfcherng/php-diff dependency to composer.json
6. Implement JSON normalization utilities for consistent diff comparison

### Phase 2: Version Management Core
1. Implement PageVersionService with basic CRUD operations
2. Add version creation when publishing (snapshot current page state)
3. Implement publish/unpublish functionality
4. Add version listing and retrieval methods
5. Update PageService to check for published versions first

### Phase 3: Hybrid Page Serving Logic
1. Implement `servePublishedVersion()` method to load stored JSON and re-run dynamic elements
2. Implement `hydratePublishedPage()` method for data retrieval and condition evaluation on stored structure
3. Implement `hydratePublishedSections()` method to refresh dynamic content in stored section structures
4. Modify PageService.getPage() and getPageSections() to route between published vs draft serving
5. Add preview parameter support to force draft serving for authorized users
6. **Security**: Implement strict auth + no-cache headers for draft/preview endpoints
7. **Security**: Return 404 for unpublished pages to public users (never expose drafts)
8. Implement fallback logic (serve draft if no published version exists)
9. Add proper error handling for corrupted or missing published versions

### Phase 4: Version Comparison & Semantic Diff
1. Implement JSON Patch (RFC 6902) for semantic diff operations
2. Implement JSON Merge Patch for simplified patch format
3. Add JSON normalization utilities (stable key ordering, consistent formatting)
4. Integrate php-diff library for human-readable side-by-side views
5. Create API endpoints for version comparison (multiple formats)
6. Implement diff caching for performance

### Phase 5: API Endpoints & UI Integration
1. Implement all admin API endpoints for version management
2. Add frontend serving endpoints for published versions
3. Create admin interface for version management
4. Add publish/preview controls to page editor
5. Implement version history browser with diff visualization

### Phase 6: Storage Optimization & Testing
1. Implement MySQL 8 JSON operations and indexing strategies
2. Add version retention policies (configurable keep last N versions)
3. Implement storage monitoring and automated cleanup
4. Unit tests for PageVersionService and JSON operations
5. Integration tests for publish/serve workflow with security validation
6. Performance tests for JSON storage, retrieval, and diff generation
7. Cache optimization for frequently accessed published versions
8. Security tests for draft exposure prevention

## Success Criteria
- **Data Freshness**: End users see published versions with fresh data (stored structure + dynamic elements)
- **Developer Experience**: Developers see live drafts from database (always current)
- **Consistency**: Published pages maintain structure consistency while showing fresh dynamic content
- **Version Comparison**: Semantic diff works with JSON Patch/Merge Patch and php-diff visualization
- **Security**: Draft content never exposed to public users (404 for unpublished pages)
- **Performance**: Minimal impact on page serving with optimized storage and caching
- **Scalability**: Storage growth controlled with retention policies and compression
- **Reliability**: Single source of truth prevents desync issues in version management
