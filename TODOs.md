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

## Timezone-Aware Scheduled Jobs & Configurable Cronjobs

### Overview
Implement timezone-aware scheduling for user notifications with a single configurable CMS-managed cronjob for queue processing.

### Current Issues
- **Timezone Problem**: Scheduled jobs execute at server timezone regardless of user location (e.g., 8:00 AM notifications sent at wrong local time when user changes timezone)
- **Hardcoded Cronjobs**: PHP cronjob runs every minute, not configurable through CMS
- **No User Timezone**: Users cannot set their preferred timezone for scheduling

### Core Requirements
- Add timezone table and link to user profiles by ID
- Default new users to server timezone, allow manual timezone changes
- Convert scheduled job execution times based on user timezone
- Single configurable CMS-managed cronjob for queue processing (enabled by default, 1 minute frequency)
- Users can only enable/disable and configure frequency of the queue processing cronjob
- No custom cronjob creation - only configuration of the existing queue processor
- Admin interface for cronjob management (enable/disable, frequency)

### Database Schema Changes

#### 1. New Table: `timezones`
- **Purpose**: Store supported timezone identifiers with metadata
- **Structure**:
  - `id` (PRIMARY KEY)
  - `timezone_identifier` VARCHAR(100) UNIQUE - PHP timezone identifier (e.g., "America/New_York")
  - `display_name` VARCHAR(100) - Human readable name (e.g., "Eastern Time")
  - `utc_offset` VARCHAR(10) - Current UTC offset (e.g., "-05:00")
  - `is_active` BOOLEAN DEFAULT TRUE - Enable/disable timezone
  - `sort_order` INT - Display order in UI

#### 2. Update `users` table
- Add `timezone_id` INT NULL - Foreign key to timezones.id
- Add index on timezone_id for performance
- Default to NULL (will be set to server timezone during user creation)

#### 3. New Table: `system_cronjob_config`
- **Purpose**: Single configurable cronjob for queue processing
- **Structure**:
  - `id` (PRIMARY KEY, single row expected)
  - `is_enabled` BOOLEAN DEFAULT TRUE - Enable/disable cronjob
  - `frequency_minutes` INT DEFAULT 1 - Execution frequency in minutes
  - `last_run` DATETIME NULL - Last execution time
  - `next_run` DATETIME NULL - Calculated next execution time
  - `updated_by` INT - User who last modified config
  - `updated_at` DATETIME

### Service Layer Changes

#### 1. TimezoneService
- `detectServerTimezone(): string` - Detect server's current timezone
- `convertToUserTimezone(DateTime $utcDate, int $userTimezoneId): DateTime`
- `convertToUtc(DateTime $localDate, int $userTimezoneId): DateTime`
- `getValidTimezones(): array` - Return supported timezone objects from database
- `validateTimezoneId(int $timezoneId): bool`
- `getCurrentUserTimezone(User $user): Timezone`
- `setUserDefaultTimezone(User $user): void` - Set user timezone to server timezone on creation

#### 2. SystemCronjobService
- `getConfig(): SystemCronjobConfig` - Get current cronjob configuration
- `updateConfig(array $config): SystemCronjobConfig` - Update cronjob settings
- `enableCronjob(): bool` - Enable the cronjob
- `disableCronjob(): bool` - Disable the cronjob
- `setFrequency(int $minutes): bool` - Set execution frequency
- `calculateNextRun(): DateTime` - Calculate next execution time based on frequency
- `shouldExecute(): bool` - Check if cronjob should run now
- `executeCronjob(): bool` - Execute the queue processing logic

#### 3. Modified JobSchedulerService
- Add timezone conversion when checking job execution times
- `shouldExecuteJobForUser(ScheduledJob $job, User $user): bool`
- `getJobsDueForUser(User $user, DateTime $currentTime): array`
- Update `scheduleJob()` to store UTC times internally

### Console Commands

#### 1. ProcessQueueCommand
- Symfony console command for processing the system queue
- Processes the system queue (notifications, scheduled jobs, etc.)
- Checks system_cronjob_config for execution settings
- Command: `php bin/console queue:process`
- Respects enable/disable and frequency settings from database

### API Endpoints

#### User Profile
- `GET /api/v1/user/profile` - Include timezone information in response
- `PUT /api/v1/user/profile` - Allow timezone updates
- `GET /api/v1/timezones` - List available timezones from timezones table

#### Admin System Cronjob Management
- `GET /admin/system-cronjob/config` - Get current cronjob configuration
- `PUT /admin/system-cronjob/config` - Update cronjob settings (enable/disable, frequency)
- `POST /admin/system-cronjob/enable` - Enable the cronjob
- `POST /admin/system-cronjob/disable` - Disable the cronjob
- `POST /admin/system-cronjob/execute` - Manually execute cronjob

### Implementation Strategy

#### Phase 1: Timezone Infrastructure
1. Create timezones table and populate with supported timezone identifiers
2. Add timezone_id field to User entity and database migration
3. Create TimezoneService with server timezone detection and conversion utilities
4. Implement server timezone detection and user default assignment
5. Update User profile API to support timezone settings
6. Add timezone validation and supported timezone list

#### Phase 2: Timezone-Aware Scheduling
1. Implement JobSchedulerService with timezone awareness
2. Create scheduled job execution logic with timezone conversion
3. Implement AdminScheduledJobService to display times in user timezone
4. Add timezone conversion to job scheduling APIs

#### Phase 3: System Cronjob Configuration
1. Create SystemCronjobConfig entity and repository
2. Implement SystemCronjobService for cronjob configuration management
3. Create ProcessQueueCommand console command
4. Add admin API endpoints for cronjob configuration
5. Set default configuration (enabled, 1 minute frequency)

#### Phase 4: Database Setup & Initialization
1. Create database migration scripts for all new tables
2. Populate timezones table with standard timezone data
3. Initialize system_cronjob_config with default settings (enabled, 1 minute frequency)
4. Update deployment scripts for new tables

#### Phase 5: Admin Interface & Testing
1. Create admin UI for system cronjob configuration
2. Add timezone selector to user profile forms
3. Unit tests for timezone conversions and server detection
4. Integration tests for timezone-aware job execution
5. Performance tests for cronjob execution system

### Security Considerations

#### 1. Timezone Validation
- Strict validation of timezone IDs from timezones table
- Prevent timezone injection attacks by using ID references
- Default to server timezone for invalid timezone selections

#### 2. System Cronjob Security
- Single system cronjob with restricted configuration options
- No custom command execution - only predefined queue processing
- Log all cronjob executions with user context and results
- Restrict cronjob configuration to admin users only

#### 3. User Timezone Privacy
- Timezone data treated as sensitive user information
- Proper access controls for timezone updates
- Audit trail for timezone changes
- Server timezone detection with fallback to UTC
