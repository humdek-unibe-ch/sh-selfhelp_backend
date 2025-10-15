# Page Versioning & Publishing System

## Overview
Implement a page versioning and publishing system where published page versions are stored as complete JSON structures, while drafts are always loaded fresh from the database. This allows users to publish specific versions for end users while developers continue working on drafts.

## Core Requirements
- Store complete published page JSON structures as versions
- Drafts are always loaded fresh from database (no JSON storage)
- Publish/unpublish page versions for end users
- Version comparison using php-diff library
- Version restoration functionality

## Database Schema Changes

### 1. New Table: `page_versions`
- **Purpose**: Store complete published page JSON structures as versions
- **Structure**:
  - `id` (PRIMARY KEY)
  - `id_pages` (FOREIGN KEY to pages.id)
  - `version_number` (INT, incremental per page)
  - `version_name` (VARCHAR, optional user-defined name)
  - `page_json` (LONGTEXT, complete JSON structure from getPage())
  - `is_current` (BOOLEAN, marks the currently published version)
  - `created_by` (INT, user who created the version)
  - `created_at` (DATETIME)
  - `published_at` (DATETIME, when version was published)
  - `metadata` (JSON, additional info like change summary)

### 2. Update `pages` table
- Add `id_page_versions` (published_version_id) (INT, points to currently published version in page_versions table)

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
  - Return published version JSON for regular users
  - Return fresh draft from database for developers/admins (existing getPage logic)
  - Add preview parameter to force draft serving

## Version Comparison Features

### 1. JSON Diff Using php-diff Library
- Use [jfcherng/php-diff](https://github.com/jfcherng/php-diff) library for text diff functionality
- Convert page JSON to formatted strings for comparison
- Support multiple diff formats (unified, side-by-side HTML, JSON)
- Highlight changes in page content, sections, and translations

### 2. Visual Diff Interface
- Web interface to compare versions side-by-side
- Display diffs in multiple formats (unified, split view)
- Show changes in page structure and content
- Allow filtering by content sections

## Migration Strategy

### 1. Fresh Implementation
- This is the first version - no backward compatibility needed
- All pages start without published versions (serve drafts by default)
- Users must explicitly publish versions to make them live

### 2. Clean Implementation
- No legacy data migration concerns
- Direct implementation of new versioning system
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

## Implementation Tasks

### Phase 1: Database & Core Infrastructure
1. Create `page_versions` table structure with SQL migration
2. Update `pages` table to add `published_version_id` column
3. Create PageVersion entity with proper Doctrine mappings
4. Create PageVersionRepository with basic query methods
5. Add jfcherng/php-diff dependency to composer.json

### Phase 2: Version Management Core
1. Implement PageVersionService with basic CRUD operations
2. Add version creation when publishing (snapshot current page state)
3. Implement publish/unpublish functionality
4. Add version listing and retrieval methods
5. Update PageService to check for published versions first

### Phase 3: Page Serving Logic
1. Modify PageService.getPage() to serve published versions for regular users
2. Keep existing draft serving for developers/admins
3. Add preview parameter support
4. Implement fallback logic (serve draft if no published version)

### Phase 4: Version Comparison & Diff
1. Implement version comparison using php-diff library
2. Create API endpoint for version comparison
3. Format page JSON for readable diff display
4. Add support for different diff formats (unified, side-by-side)

### Phase 5: API Endpoints & UI Integration
1. Implement all admin API endpoints for version management
2. Add frontend serving endpoints for published versions
3. Create admin interface for version management
4. Add publish/preview controls to page editor
5. Implement version history browser with diff visualization

### Phase 6: Testing & Optimization
1. Unit tests for PageVersionService and version logic
2. Integration tests for publish/serve workflow
3. Performance tests for version storage and retrieval
4. Cache optimization for frequently accessed published versions

## Success Criteria
- End users see published versions from stored JSON snapshots
- Developers see live drafts from database (always current)
- Version comparison works with php-diff library
- Publishing workflow is intuitive and reliable
- Performance impact is minimal on page serving
- Security and access controls work correctly
