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


## Docker Deployment & Installation System

### Overview
Implement a complete Docker-based deployment system that enables one-click installation and setup of the entire CMS stack (Symfony backend, React frontend, MySQL, Redis). Users should be able to deploy on any server with Docker support and access a web-based installer for initial configuration.

### Critical Security & Race Condition Fixes

#### 1. Update Race Condition Prevention
**Risk**: Multiple admins clicking "Install/Update" simultaneously causes double execution, corrupted state, or resource conflicts.

**Solution**: Database advisory locks with UI prevention
- Use MySQL `GET_LOCK('cms_update_operation', 300)` before any update/installation
- UI shows "Update in progress by [user]" with real-time status
- Queue concurrent requests or reject with clear error message
- Lock automatically releases on completion or timeout

#### 2. Docker Socket Security
**Risk**: Direct app access to `/var/run/docker.sock` is privilege escalation - app can control host Docker daemon.

**Solution**: Sidecar orchestrator pattern with restricted API
- Run minimal `cms-orchestrator` sidecar container with Docker socket access
- App communicates via HTTP API with allowlist: `pull`, `restart`, `logs` only
- mTLS authentication between app and orchestrator
- Shared secret validation for all API calls
- Orchestrator validates container names against allowlist

#### 3. Secrets Management
**Risk**: Plain `.env` files contain production secrets, backups may leak credentials.

**Solution**: Docker secrets with split configuration
- **Docker Secrets**: Use Docker secrets for sensitive data (DB passwords, JWT keys)
- **Split Config**: `.env` (non-secret config) vs `.env.secrets` (mounted secrets)
- **Never in Images**: Secrets never baked into container images
- **Vault Integration**: Consider HashiCorp Vault for enterprise deployments
- **Backup Safety**: Secrets excluded from backup archives

### Core Requirements
- **Multi-stage Docker build** for optimized production images (separate dev/prod stages)
- **Docker Compose orchestration** for complete stack (backend, frontend, database, cache, reverse proxy)
- **Web-based installation wizard** accessible at first startup for initial setup
- **Automatic SSL certificate generation** using Let's Encrypt or self-signed certificates
- **Environment-based configuration** with .env file generation
- **Database initialization scripts** that run automatically on first startup
- **Volume management** for persistent data (database, uploads, logs, certificates)
- **Health checks** for all services with automatic restart policies

### What Gets Deployed vs. What Persists

#### What You Push/Update
- **Backend Image**: `your-registry/cms-backend:1.2.3` (Symfony app with new code/features)
- **Frontend Image**: `your-registry/cms-frontend:1.2.3` (React/Next.js built assets)
- **Optional**: Reverse proxy image updates (nginx/caddy with new config)

#### What Persists (Never Overwritten)
- **Database Data**: Lives in persistent Docker volume or external MySQL service
- **User Uploads**: Files, images, documents in dedicated volumes
- **Configuration**: Custom settings, SSL certificates, environment configs
- **Database Schema**: Evolves through migrations, not replacement

#### Database Strategies (Both Safe)

**Option A - MySQL in Docker (Recommended for simplicity):**
```yaml
services:
  db:
    image: mysql:8
    volumes:
      - db_data:/var/lib/mysql  # Persistent - survives image updates
    environment:
      MYSQL_ROOT_PASSWORD_FILE: /run/secrets/db_root_password  # Docker secret
    secrets:
      - db_root_password

  backend:
    image: your-registry/cms-backend:1.2.3
    volumes:
      - plugins_data:/var/www/html/plugins  # ← Plugin files persist
      - app_uploads:/var/www/html/public/uploads
    environment:
      DOCKER_ORCHESTRATOR_URL: http://orchestrator:8080
      DOCKER_ORCHESTRATOR_SECRET: ${DOCKER_SECRET}
    depends_on:
      - orchestrator

  orchestrator:  # ← Security sidecar for Docker operations
    image: your-registry/cms-orchestrator:latest
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock  # Only orchestrator has socket access
    environment:
      SHARED_SECRET: ${DOCKER_SECRET}
    ports:
      - "8080"  # Internal API for app communication

volumes:
  db_data:      # Database data persists
  plugins_data: # Plugin files persist across updates
  app_uploads:  # User uploads persist

secrets:
  db_root_password:
    file: ./secrets/db_root_password.txt  # Never in git
```

**Option B - External MySQL (Cloud/VM):**
- RDS, Aurora, Cloud SQL, or dedicated MySQL server
- App connects via `DATABASE_URL` environment variable
- Database persists independently of Docker deployments

### Database Update Strategy (No Overwrites)

#### Doctrine Migrations Approach (Recommended)
- **What**: Each version gets Doctrine migration classes (e.g., `Version20251010AddSystemUpdates`)
- **Tracking**: `doctrine_migration_versions` table tracks applied migrations
- **Execution**: Only pending migrations run - nothing overwritten
- **Safety**: Transactional execution with rollback capability

**Example Migration:**
```php
final class Version20251010AddSystemUpdates extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add system_updates tables for auto-update system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE system_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  version_from VARCHAR(20),
  version_to VARCHAR(20),
  update_type ENUM('minor','major','patch','security'),
  status ENUM('pending','downloading','installing','migrating','completed','failed','rolled_back'),
  started_at DATETIME,
  completed_at DATETIME NULL,
  rollback_available BOOLEAN DEFAULT FALSE,
  backup_path VARCHAR(500) NULL,
  error_message TEXT NULL,
  initiated_by INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_updates');
    }
}
```

#### How Updates Work
1. **Version v1.0.0**: Initial schema created
2. **Version v1.1.0**: Migration adds `system_updates` table
3. **Version v1.2.0**: Migration adds `system_versions` table
4. **User at v1.1.0**: Downloads v1.2.0 → Only migration for v1.2.0 runs
5. **Result**: Database evolves incrementally, data preserved

#### Alternative: Raw SQL Scripts
- **Tracking Table**: `schema_applied_scripts` (script_name, applied_at)
- **Execution**: Scan directory, run only unapplied scripts in order
- **Same Safety**: Only needed scripts run, transactional execution

### Update Process Flow

#### Automated Update Sequence with Race Condition Prevention
1. **Admin clicks "Install Update" in CMS UI**
2. **Acquire Update Lock**: Get MySQL advisory lock `GET_LOCK('cms_update_operation', 300)`
   - If lock fails: Show "Update in progress by [other admin]" message
   - UI prevents multiple concurrent update attempts
3. **Enable maintenance mode** (503 for non-admins)
4. **Create backup** (database + uploads, excluding secrets)
5. **Pull new images** via orchestrator sidecar:
   ```bash
   # App calls orchestrator API (not direct Docker)
   POST /orchestrator/pull?service=backend,frontend
   ```
6. **Restart containers** via orchestrator:
   ```bash
   POST /orchestrator/restart?service=backend,frontend
   ```
7. **Run migrations** inside backend container via orchestrator:
   ```bash
   POST /orchestrator/exec?service=backend&command=php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
   ```
8. **Health checks** verify system works
9. **Release Update Lock**: `RELEASE_LOCK('cms_update_operation')`
10. **Disable maintenance mode**

#### Lock Management Implementation
```php
// In UpdateService
public function acquireUpdateLock(string $userId): bool
{
    $lockAcquired = $this->connection->executeQuery(
        "SELECT GET_LOCK('cms_update_operation', 300) as lock_acquired"
    )->fetchOne();

    if ($lockAcquired) {
        // Store lock holder info
        $this->connection->executeStatement(
            "INSERT INTO system_update_locks (lock_name, user_id, acquired_at)
             VALUES ('cms_update_operation', ?, NOW())
             ON DUPLICATE KEY UPDATE user_id = ?, acquired_at = NOW()",
            [$userId, $userId]
        );
        return true;
    }

    // Return who holds the lock
    $holder = $this->connection->executeQuery(
        "SELECT u.name FROM system_update_locks l JOIN users u ON l.user_id = u.id
         WHERE l.lock_name = 'cms_update_operation'"
    )->fetchOne();

    throw new UpdateInProgressException("Update in progress by: " . $holder);
}
```

#### Rollback Process with Non-Reversible Migration Handling
1. **Admin clicks "Rollback"**
2. **Acquire Update Lock**: Same locking mechanism prevents concurrent operations
3. **Enable maintenance mode**
4. **Assess Rollback Options**:
   - **Check Migration Reversibility**: Query migration classes for `isTransactional()` and `down()` methods
   - **Mark Non-Reversible**: Flag migrations that cannot be safely reversed
   - **Prioritize Backup Restore**: For non-reversible migrations, use backup restoration
5. **Execute Rollback Strategy**:
   - **Option A - Reversible Migrations**: Run `down()` methods in reverse order
   - **Option B - Non-Reversible**: Restore from pre-update backup (database + plugins)
   - **Hybrid**: Run reversible migrations, then restore non-reversible via backup
6. **Switch to previous image tags** via orchestrator
7. **Restart containers** and verify system health

#### Migration Reversibility Tracking
```php
// In MigrationService
public function assessMigrationReversibility(string $version): array
{
    $migrations = $this->getMigrationsForVersion($version);
    $assessment = [];

    foreach ($migrations as $migration) {
        $reflection = new ReflectionClass($migration);
        $hasDownMethod = $reflection->hasMethod('down');
        $isTransactional = $reflection->hasMethod('isTransactional') ?
            $migration->isTransactional() : true;

        $assessment[] = [
            'class' => $migration::class,
            'reversible' => $hasDownMethod,
            'transactional' => $isTransactional,
            'risk_level' => $this->calculateRiskLevel($migration)
        ];
    }

    return $assessment;
}

public function calculateRiskLevel(AbstractMigration $migration): string
{
    // Analyze migration for DROP TABLE, data deletion, etc.
    $sql = $migration->getSql(); // Hypothetical method
    if (str_contains($sql, 'DROP TABLE') || str_contains($sql, 'DELETE FROM')) {
        return 'high'; // Requires backup restore
    }
    return 'low'; // Safe to reverse with down() method
}
```

### Key Safety Features

#### Data Persistence Guarantee
- **Docker volumes**: Survive container/image updates
- **External databases**: Independent of application deployment
- **Incremental schema changes**: Only additions/modifications, never full replacement

#### Update Safety
- **Pre-update backup**: Full database and file backup before any changes
- **Transactional migrations**: All-or-nothing execution
- **Health verification**: Automated checks after updates
- **Rollback capability**: Multiple rollback strategies available

#### No Overwrite Scenarios
- ✅ **App code**: Updates via new images
- ✅ **Database schema**: Evolves via migrations
- ✅ **User data**: Persists in volumes/external storage
- ✅ **Configuration**: Environment files and volumes
- ❌ **No**: Full database replacement or data loss

### Database Schema Changes

#### 1. New Table: `system_installation`
- **Purpose**: Track installation state and configuration
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY, single row expected)
  - `installation_completed` BOOLEAN DEFAULT FALSE
  - `installed_version` VARCHAR(20) - Current installed version
  - `installed_at` DATETIME
  - `admin_user_created` BOOLEAN DEFAULT FALSE
  - `database_initialized` BOOLEAN DEFAULT FALSE
  - `ssl_configured` BOOLEAN DEFAULT FALSE
  - `installation_token` VARCHAR(64) - One-time token for installation security

#### 2. New Table: `system_versions`
- **Purpose**: Track available versions for auto-updates
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `version` VARCHAR(20) UNIQUE - Version number (e.g., "1.0.0")
  - `release_date` DATETIME
  - `is_current` BOOLEAN DEFAULT FALSE
  - `update_available` BOOLEAN DEFAULT FALSE
  - `changelog` TEXT - Release notes
  - `download_url` VARCHAR(500) - Docker image or update package URL
  - `migration_scripts` JSON - List of SQL scripts to run for this version

### API Endpoints

#### Installation Wizard
- `GET /install` - Serve installation wizard UI (only accessible if not installed)
- `POST /install/database` - Configure database connection and initialize
- `POST /install/admin-user` - Create initial admin user
- `POST /install/complete` - Mark installation as complete
- `GET /install/status` - Check installation progress

#### System Management
- `GET /admin/system/status` - Get system health and version info
- `GET /admin/system/info` - Get system information (Docker, versions, etc.)
- `POST /admin/system/restart` - Restart services (admin only)
- `GET /admin/system/locks` - Check active update locks and holders

#### Orchestrator API (Internal - Sidecar Communication)
- `POST /orchestrator/pull` - Pull Docker images (allowlist: backend, frontend)
- `POST /orchestrator/restart` - Restart containers (allowlist validation)
- `POST /orchestrator/exec` - Execute commands in containers (restricted)
- `GET /orchestrator/status` - Get container status
- `GET /orchestrator/logs` - Get container logs (authenticated)

### Service Layer Changes

#### 1. InstallationService
- `isInstalled(): bool` - Check if system is already installed
- `initializeDatabase(): bool` - Run initial database setup
- `createAdminUser(array $userData): User` - Create first admin user
- `completeInstallation(): bool` - Mark installation as complete
- `generateSecureToken(): string` - Generate installation security token
- `validateInstallationToken(string $token): bool` - Validate token

#### 2. SystemService
- `getSystemInfo(): array` - Get system status, versions, Docker info
- `checkHealth(): array` - Health check all services
- `restartServices(): bool` - Restart Docker services
- `getDockerInfo(): array` - Get Docker container information

#### 3. DockerService (Updated with Orchestrator)
- `pullImages(array $services): bool` - Pull new images via orchestrator sidecar
- `restartContainers(array $services): bool` - Restart containers via orchestrator
- `executeInContainer(string $service, string $command): string` - Execute commands safely via orchestrator
- `getContainerStatus(): array` - Get status via orchestrator API
- `getLogs(string $service): array` - Get container logs via orchestrator

#### 4. OrchestratorService (New)
- **Purpose**: Secure communication with Docker orchestrator sidecar
- `callOrchestrator(string $endpoint, array $params): array` - Make authenticated API calls
- `validateResponse(array $response): bool` - Verify orchestrator responses
- `handleOrchestratorError(array $error): void` - Process orchestrator errors

### Implementation Strategy

#### Phase 1: Docker Infrastructure Setup
1. Create multi-stage Dockerfile for Symfony backend with PHP 8.3, optimized for production
2. Create Dockerfile for React Next.js frontend with Node.js build process
3. Create docker-compose.yml with all services (backend, frontend, mysql:8, redis, nginx)
4. Implement environment variable management with .env template
5. Add Docker health checks and restart policies
6. Create volume configuration for persistent data

#### Phase 2: Installation Wizard Backend
1. Create InstallationController with web-based wizard endpoints
2. Implement InstallationService for database setup and admin user creation
3. Add installation middleware to protect system until setup is complete
4. Create installation templates using Twig (responsive design)
5. Implement secure token-based installation process
6. Add database migration handling for initial setup

#### Phase 3: System Management & Monitoring
1. Create SystemController for admin system management
2. Implement DockerService for container management
3. Add system health check endpoints
4. Create system info dashboard for admins
5. Implement service restart functionality
6. Add logging and monitoring integration

#### Phase 4: Production Optimization & Security
1. Implement SSL certificate management (Let's Encrypt integration)
2. Add security headers and Docker hardening
3. Create backup/restore scripts for Docker volumes
4. Implement log rotation and monitoring
5. Add rate limiting and security middleware
6. Create production deployment documentation

### Security Considerations

#### 1. Installation Security
- **One-time installation token** required for setup access
- **Secure admin password requirements** (complexity, length)
- **Installation lockout** after completion to prevent re-installation
- **Secure defaults** for all configuration options

#### 2. Docker Security
- **Non-root user execution** in all containers
- **Minimal base images** to reduce attack surface
- **Secret management** for database passwords and API keys
- **Network isolation** between services
- **Regular security updates** for base images

#### 3. Runtime Security
- **Container vulnerability scanning** in CI/CD
- **Intrusion detection** and monitoring
- **Secure defaults** for all services
- **Regular security audits** and updates

### Dependencies
- Add Docker and Docker Compose to deployment requirements
- Add `symfony/twig-bundle` for installation templates (if not already present)
- Consider adding monitoring tools (Prometheus, Grafana) for production deployments

### Success Criteria
- **One-command deployment**: `docker-compose up -d` starts entire system
- **Web-based setup**: Users complete installation through browser wizard
- **Production ready**: Optimized images, security hardening, monitoring
- **Easy maintenance**: Update commands, backup/restore, health monitoring
- **Scalable**: Support for multiple environments (dev/staging/prod)

## Modern CMS Installation Wizard

### Overview
Create a professional, user-friendly web-based installation wizard that guides users through initial CMS setup, similar to WordPress installation. Users access the site URL after Docker deployment and are automatically redirected to the installer if the system isn't configured yet.

### Current Issues
- **Manual setup complexity**: Requires command-line database setup and manual configuration
- **No guided experience**: Users must understand Symfony/PHP/MySQL configuration
- **Security risks**: Default credentials and exposed configuration during setup
- **No validation**: Users can create insecure installations

### Core Requirements
- **Automatic installer detection** - Redirect to installer if system not configured
- **Step-by-step wizard** - Database config → Admin user → Site settings → Complete
- **Input validation** - Real-time validation with helpful error messages
- **Secure defaults** - Automatically generate secure passwords and tokens
- **Progress tracking** - Visual progress indicator and ability to resume
- **Responsive design** - Works on mobile and desktop
- **Multi-language support** - Installation wizard respects language settings

### Database Schema Changes

#### 1. Update `system_installation` table (from Docker section)
- Add installation step tracking columns
- Store installation progress and user inputs
- Track installation attempts and security

#### 2. New Table: `installation_steps`
- **Purpose**: Track individual installation steps and their status
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `step_key` VARCHAR(50) UNIQUE - e.g., 'database', 'admin_user', 'site_config'
  - `step_name` VARCHAR(100) - Human readable name
  - `step_order` INT - Execution order
  - `completed` BOOLEAN DEFAULT FALSE
  - `completed_at` DATETIME NULL
  - `error_message` TEXT NULL - Any errors during this step

### API Endpoints

#### Installation Steps
- `GET /install/steps` - Get available installation steps and current progress
- `POST /install/step/{step_key}` - Execute specific installation step
- `GET /install/validate/{step_key}` - Validate data for specific step
- `POST /install/skip/{step_key}` - Skip optional installation step

#### Data Validation
- `POST /install/validate/database` - Test database connection
- `POST /install/validate/admin-user` - Validate admin user data
- `POST /install/validate/site` - Validate site configuration

### Service Layer Changes

#### 1. InstallationWizardService
- `getInstallationSteps(): array` - Get all available steps with status
- `executeStep(string $stepKey, array $data): array` - Execute specific step
- `validateStepData(string $stepKey, array $data): array` - Validate step input
- `getCurrentStep(): string` - Get current active step
- `canSkipStep(string $stepKey): bool` - Check if step can be skipped
- `resetInstallation(): void` - Reset installation (admin only, dangerous)

#### 2. DatabaseSetupService
- `testConnection(array $config): bool` - Test database connectivity
- `initializeDatabase(): bool` - Run schema creation and initial data
- `createTables(): bool` - Execute CREATE TABLE statements
- `runInitialMigrations(): bool` - Run initial data migrations
- `validateDatabaseVersion(): bool` - Ensure MySQL 8 compatibility

#### 3. SiteConfigurationService
- `createAdminUser(array $userData): User` - Create first admin user
- `setSiteSettings(array $settings): bool` - Configure initial site settings
- `generateSecurityKeys(): array` - Generate JWT keys, app secrets
- `setupDefaultContent(): bool` - Create default pages, sections, content

### Implementation Strategy

#### Phase 1: Installation Framework
1. Create InstallationWizardController with step management
2. Implement InstallationWizardService for step orchestration
3. Add installation routing and middleware
4. Create step validation system with JSON Schema
5. Implement progress tracking and persistence

#### Phase 2: Database Setup Step
1. Create database configuration form with real-time validation
2. Implement DatabaseSetupService for connection testing
3. Add automatic schema creation from SQL files
4. Create initial data seeding system
5. Implement rollback on database setup failure

#### Phase 3: Admin User Creation Step
1. Create admin user form with password strength validation
2. Implement secure password generation and validation
3. Add email verification if SMTP is configured
4. Create initial admin role and permissions
5. Implement user creation with transaction safety

#### Phase 4: Site Configuration Step
1. Create site settings form (site name, description, timezone, etc.)
2. Implement default language and locale setup
3. Add initial content creation (welcome page, default sections)
4. Configure system preferences and defaults
5. Generate and store security keys (JWT, app secret)

#### Phase 5: Completion & Finalization
1. Create installation completion page with summary
2. Implement system lockdown after installation
3. Add post-installation cleanup (remove installer routes)
4. Create admin dashboard redirect
5. Send completion notifications if email configured

### Security Considerations

#### 1. Installation Access Control
- **Secure token required** for all installation endpoints
- **IP-based restrictions** for installation access
- **Time-limited access** to prevent prolonged exposure
- **Installation lockout** after completion

#### 2. Input Validation & Sanitization
- **Strict validation** for all user inputs using JSON Schema
- **SQL injection prevention** in database configuration
- **Password complexity requirements** for admin user
- **XSS prevention** in site name and configuration inputs

#### 3. Secure Defaults
- **Auto-generated secrets** for JWT keys and app secrets
- **Secure password requirements** with strength validation
- **Safe database defaults** with minimal privileges
- **HTTPS enforcement** for production installations

### Dependencies
- Add `symfony/form` for installation forms (optional, can use JSON API)
- Add `symfony/validator` for input validation (likely already present)
- Add `symfony/twig-bundle` for installation templates
- Consider adding `symfony/translation` for multi-language installer

### Success Criteria
- **Guided experience**: Step-by-step wizard with clear instructions
- **Error handling**: Helpful error messages and recovery options
- **Security**: Secure installation with proper validation and defaults
- **User-friendly**: Responsive design, progress tracking, input validation
- **Complete setup**: Fully configured system ready for use after installation

## Auto-Update & Version Management System

### Overview
Implement an automatic update system that can detect new versions, download and install updates, run database migrations, and provide rollback capabilities. The system should work seamlessly with Docker deployments and provide administrators with full control over update timing and rollback options.

### Current Issues
- **Manual updates**: No automated way to update the system
- **Version tracking**: No system to track installed versions and available updates
- **Migration management**: Database schema updates require manual intervention
- **Rollback capability**: No way to revert problematic updates
- **Update security**: No verification of update authenticity

### Core Requirements
- **Version checking service** that polls for new releases
- **Automated update downloads** from trusted sources
- **Database migration handling** with transaction safety
- **Rollback capabilities** with backup restoration
- **Update scheduling** and approval workflow
- **Health checks** before and after updates
- **Security verification** for downloaded updates
- **Update logging** and audit trail

### Database Schema Changes

#### 1. Update `system_versions` table (from Docker section)
- Add update status tracking columns
- Store update metadata and verification hashes
- Track update attempts and success/failure

#### 2. New Table: `system_updates`
- **Purpose**: Track update attempts, status, and rollback information
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `version_from` VARCHAR(20) - Version being updated from
  - `version_to` VARCHAR(20) - Target version
  - `update_type` ENUM('minor', 'major', 'patch', 'security') - Update classification
  - `status` ENUM('pending', 'downloading', 'installing', 'migrating', 'completed', 'failed', 'rolled_back')
  - `started_at` DATETIME
  - `completed_at` DATETIME NULL
  - `rollback_available` BOOLEAN DEFAULT FALSE
  - `backup_path` VARCHAR(500) NULL - Path to backup for rollback
  - `error_message` TEXT NULL
  - `initiated_by` INT - User who initiated update (FK to users)

#### 3. New Table: `system_update_logs`
- **Purpose**: Detailed logging of update process steps
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `update_id` INT (FK to system_updates)
  - `step` VARCHAR(100) - Update step (e.g., 'download', 'backup', 'migration')
  - `status` ENUM('started', 'completed', 'failed')
  - `message` TEXT - Log message
  - `timestamp` DATETIME
  - `duration_ms` INT NULL - Step execution time

### API Endpoints

#### Version Management
- `GET /admin/system/version/current` - Get current installed version
- `GET /admin/system/version/available` - Check for available updates
- `GET /admin/system/version/changelog/{version}` - Get changelog for specific version

#### Update Management
- `POST /admin/system/updates/check` - Manually check for updates
- `POST /admin/system/updates/download/{version}` - Download specific version
- `POST /admin/system/updates/install/{version}` - Install downloaded update
- `GET /admin/system/updates/status` - Get update status and progress
- `POST /admin/system/updates/cancel` - Cancel in-progress update

#### Rollback Management
- `GET /admin/system/updates/rollback/available` - List available rollback points
- `POST /admin/system/updates/rollback/{update_id}` - Rollback to specific update
- `GET /admin/system/updates/backup/status` - Check backup status

### Service Layer Changes

#### 1. VersionManagementService
- `getCurrentVersion(): string` - Get currently installed version
- `checkForUpdates(): array` - Check remote server for new versions
- `downloadVersion(string $version): bool` - Download update package
- `validateUpdatePackage(string $path): bool` - Verify package integrity
- `getVersionChangelog(string $version): string` - Get release notes

#### 2. UpdateService
- `startUpdate(string $version): Update` - Initiate update process
- `executeUpdate(Update $update): bool` - Execute update steps
- `rollbackUpdate(int $updateId): bool` - Rollback to previous version
- `getUpdateStatus(int $updateId): array` - Get detailed update progress
- `cancelUpdate(int $updateId): bool` - Cancel in-progress update
- `cleanupFailedUpdate(int $updateId): bool` - Clean up after failed update

#### 3. BackupService
- `createBackup(): string` - Create system backup before update
- `validateBackup(string $path): bool` - Verify backup integrity
- `restoreBackup(string $path): bool` - Restore from backup
- `cleanupOldBackups(): bool` - Remove old backup files
- `getBackupInfo(): array` - Get backup status and size

#### 4. MigrationService
- `runMigrations(string $version): bool` - Execute database migrations for version
- `validateMigrations(string $version): array` - Pre-validate migration scripts
- `rollbackMigrations(string $version): bool` - Rollback database changes
- `getMigrationStatus(): array` - Check migration execution status

### Implementation Strategy

#### Phase 1: Version Tracking Infrastructure
1. Create VersionManagementService for version checking and tracking
2. Implement system version detection from composer.json or version file
3. Add version endpoint for remote update server communication
4. Create version comparison and dependency checking
5. Implement security verification for version packages (checksums, signatures)
6. **Docker Integration**: Store version info in system_versions table for tracking

#### Phase 2: Update Download & Validation
1. Create UpdateService for managing update lifecycle
2. Implement secure download from trusted update servers
3. Add package integrity verification (SHA256, GPG signatures)
4. Create update staging area for downloaded packages
5. Implement update package extraction and validation

#### Phase 3: Database Migration System
1. Create MigrationService for handling database schema updates
2. Implement migration script discovery and ordering
3. Add transaction-based migration execution with rollback capability
4. Create migration pre-validation (syntax, permissions, conflicts)
5. Implement migration logging and error recovery

#### Phase 4: Backup & Rollback System
1. Create BackupService for system state preservation
2. Implement database backup (mysqldump) and file system backup
3. Add backup compression and encryption
4. Create rollback procedures with validation
5. Implement backup cleanup and retention policies

#### Phase 5: Update Orchestration & UI
1. Create update orchestration with step-by-step execution
2. Implement health checks before/after updates
3. Add update progress tracking and real-time status
4. Create admin UI for update management
5. Implement update scheduling and approval workflows
6. **Docker Integration**: Implement maintenance mode, image pulling, container restarts
7. **Migration Integration**: Automatic Doctrine migration execution in containers

#### Phase 6: Monitoring & Maintenance
1. Add comprehensive update logging and audit trails
2. Implement update success/failure notifications
3. Create system health monitoring post-update
4. Add automated testing for updates in staging environments
5. Implement update analytics and success rate tracking

### Docker Update Process Integration

#### Automated Update Workflow
1. **Version Detection**: CMS checks remote update server for new versions
2. **Admin Approval**: Admin sees available updates in system dashboard
3. **Maintenance Mode**: System enables maintenance mode (503 responses)
4. **Backup Creation**: Automatic database + file backup before changes
5. **Image Download**: Pull new backend/frontend Docker images
6. **Container Restart**: `docker compose up -d` with new images
7. **Migration Execution**:
   ```bash
   # Inside backend container
   php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
   ```
8. **Health Verification**: Automated health checks on all services
9. **Maintenance Off**: Disable maintenance mode, system live

#### Rollback Workflow
1. **Rollback Trigger**: Admin clicks rollback in emergency
2. **Maintenance Mode**: Enable maintenance mode
3. **Strategy Selection**:
   - **Option A**: Run Doctrine down migrations (if available)
   - **Option B**: Restore from pre-update backup
4. **Image Revert**: Switch Docker Compose to previous image tags
5. **Container Restart**: `docker compose up -d` with old images
6. **Verification**: Health checks and manual testing
7. **Maintenance Off**: Return to normal operation

#### Migration Safety Features
- **Transactional Execution**: All migrations run in single transaction
- **Version Tracking**: `doctrine_migration_versions` table prevents re-runs
- **Incremental Updates**: Only pending migrations execute (e.g., update3 if at update2)
- **Down Migrations**: Reversible changes with rollback capability
- **Backup Integration**: Full backup before any schema changes

### Security Considerations

#### 1. Update Source Verification
- **Signed packages** with GPG verification
- **Checksum validation** for all downloaded files
- **Trusted update servers** with certificate pinning
- **Package integrity checks** before installation

#### 2. Database Migration Security
- **Transaction safety** for all migrations
- **Backup verification** before destructive operations
- **Permission validation** for database user
- **Migration rollback testing** in development

#### 3. Runtime Security During Updates
- **Maintenance mode** during updates to prevent user access
- **Service isolation** during update process
- **Timeout handling** to prevent hanging updates
- **Resource limits** to prevent update process from consuming all resources

#### 4. Rollback Security
- **Backup encryption** and secure storage
- **Rollback validation** before execution
- **Access controls** for rollback operations
- **Audit logging** for all rollback activities

### Dependencies
- Add `guzzlehttp/guzzle` for HTTP client (likely already present)
- Add `symfony/process` for executing system commands (likely already present)
- Add `symfony/filesystem` for file operations (likely already present)
- Consider adding `paragonie/halite` for encryption/signing operations

### Success Criteria
- **Automated updates**: System can detect, download, and install updates automatically
- **Safe migrations**: Database changes are transactional with full rollback capability
- **Admin control**: Administrators can schedule, approve, and monitor updates
- **Reliability**: Comprehensive error handling and recovery mechanisms
- **Security**: All updates are verified and validated before installation
- **Monitoring**: Full audit trail and health monitoring for all update operations

## Plugin Management System

### Overview
Implement a comprehensive plugin system that allows runtime installation, updates, and management of CMS extensions. Plugins must survive core system updates, automatically resolve compatible versions, and execute database migrations safely within the Docker deployment architecture.

### Core Requirements
- **Runtime Plugin Installation**: Download and install plugins from a registry at runtime
- **Persistent Plugin Storage**: Plugins survive Docker image updates via persistent volumes
- **Version Compatibility**: Automatic resolution of plugin versions compatible with core versions
- **Plugin Migrations**: Safe execution of plugin database migrations with rollback capability
- **Plugin Lifecycle Management**: Enable/disable, update, and remove plugins through admin UI
- **Security Validation**: Signed packages with integrity verification
- **Dependency Resolution**: Handle plugin dependencies and conflicts

### Plugin Architecture (Plan A - Runtime-Installed Plugins)

**Chosen Approach**: Runtime-installed plugins with Doctrine migrations for maximum flexibility and safety.

- **Storage**: Plugins downloaded to persistent volume (`/var/www/html/plugins`)
- **Persistence**: Plugins survive Docker image updates via named volumes
- **Updates**: Dynamic downloads from plugin registry with automatic compatibility resolution
- **Migrations**: Doctrine migrations per plugin with full rollback capability
- **Security**: GPG signature verification and SHA256 integrity checks

### Docker Integration

#### Persistent Plugin Storage
```yaml
services:
  backend:
    volumes:
      - plugins_data:/var/www/html/plugins        # ← Plugin files persist
      - app_uploads:/var/www/html/public/uploads
      - app_logs:/var/www/html/var/log

volumes:
  plugins_data:  # Survives all deployments
  app_uploads:
  app_logs:
```

#### Plugin Directory Structure
```
/var/www/html/plugins/
├── _staging/          # Temporary download area
├── acme/
│   ├── seo/
│   │   ├── plugin.json
│   │   ├── migrations/
│   │   ├── src/
│   │   └── assets/
│   └── forms/
└── vendor/
```

### Plugin Package Format

#### Plugin Manifest (plugin.json)
```json
{
  "name": "acme/seo",
  "displayName": "ACME SEO Plugin",
  "version": "2.3.1",
  "description": "SEO optimization tools",
  "engines": {
    "core": ">=1.6.0 <2.0.0",
    "php": ">=8.2",
    "mysql": ">=8.0"
  },
  "dependencies": {
    "acme/core": ">=1.0.0"
  },
  "migrations": [
    "migrations/2025_01_10_init.sql",
    "migrations/2025_03_01_add_idx.sql"
  ],
  "symfonyBundles": [
    "Acme\\Seo\\AcmeSeoBundle"
  ],
  "frontend": {
    "assets": [
      "dist/seo-widget.js",
      "dist/seo-widget.css"
    ]
  },
  "checksums": {
    "archiveSha256": "...",
    "filesSha256": {...}
  },
  "signature": "BASE64_GPG_SIGNATURE"
}
```

### Database Schema Changes

#### 1. New Table: `installed_plugins`
- **Purpose**: Track installed plugins and their state
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `name` VARCHAR(150) UNIQUE - e.g., 'acme/seo'
  - `display_name` VARCHAR(200)
  - `version` VARCHAR(50)
  - `state` ENUM('enabled','disabled','installing','error','incompatible') DEFAULT 'disabled'
  - `installed_at` DATETIME
  - `updated_at` DATETIME
  - `integrity_sha256` CHAR(64) NULL
  - `source_url` VARCHAR(500) NULL
  - `engines_core` VARCHAR(100) - Compatibility range
  - `dependencies` JSON - Plugin dependencies

#### 2. New Table: `plugin_migrations`
- **Purpose**: Track applied plugin database migrations
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `plugin_name` VARCHAR(150)
  - `migration_key` VARCHAR(255) - e.g., '2025_03_01_add_idx.sql'
  - `applied_at` DATETIME
  - `checksum` VARCHAR(64) - Migration file integrity
  - UNIQUE KEY `plugin_migration` (`plugin_name`, `migration_key`)

#### 3. New Table: `system_update_locks`
- **Purpose**: Track active update operations and prevent race conditions
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `lock_name` VARCHAR(100) UNIQUE - e.g., 'cms_update_operation', 'plugin_install'
  - `user_id` INT (FK to users) - Admin who acquired the lock
  - `acquired_at` DATETIME - When lock was acquired
  - `expires_at` DATETIME - Lock timeout (300 seconds from acquired_at)
  - `operation_type` ENUM('core_update', 'plugin_install', 'plugin_update', 'rollback') - Type of operation

#### 4. Update `system_updates` table
- Add `plugin_updates` JSON field to track plugin changes in updates

### API Endpoints

#### Plugin Management
- `GET /admin/plugins` - List installed plugins with status
- `GET /admin/plugins/available` - List available plugins from registry
- `POST /admin/plugins/install` - Install plugin from registry
- `POST /admin/plugins/{name}/update` - Update specific plugin
- `POST /admin/plugins/{name}/enable` - Enable plugin
- `POST /admin/plugins/{name}/disable` - Disable plugin
- `DELETE /admin/plugins/{name}` - Remove plugin
- `GET /admin/plugins/{name}/status` - Get plugin health status
- `GET /admin/plugins/updates/plan` - Plan updates for core upgrade

#### Plugin Registry
- `GET /api/plugins/registry/search` - Search plugin registry
- `GET /api/plugins/registry/{name}` - Get plugin details
- `GET /api/plugins/registry/{name}/versions` - Get available versions

### Service Layer Changes

#### 1. PluginManagerService
- `installPlugin(string $name, string $version): bool` - Install plugin from registry
- `updatePlugin(string $name, string $version): bool` - Update existing plugin
- `removePlugin(string $name): bool` - Safely remove plugin
- `enablePlugin(string $name): bool` - Enable plugin
- `disablePlugin(string $name): bool` - Disable plugin
- `checkPluginHealth(string $name): array` - Verify plugin integrity

#### 2. PluginRegistryService
- `searchPlugins(string $query): array` - Search available plugins
- `getPluginInfo(string $name): array` - Get plugin metadata
- `getCompatibleVersions(string $name, string $coreVersion): array` - Find compatible versions
- `downloadPlugin(string $name, string $version): string` - Download plugin package
- `verifyPluginPackage(string $path, array $manifest): bool` - Verify package integrity

#### 3. PluginMigrationService
- `runPluginMigrations(string $pluginName): bool` - Execute pending Doctrine migrations for plugin
- `rollbackPluginMigrations(string $pluginName, string $toVersion): bool` - Rollback plugin migrations
- `getPendingMigrations(string $pluginName): array` - List unapplied Doctrine migrations
- `validateMigrations(string $pluginName): array` - Pre-validate migration classes
- `loadPluginMigrations(string $pluginName): void` - Dynamically load plugin migration classes

#### 4. PluginCompatibilityService
- `resolveCompatibleVersions(array $plugins, string $targetCoreVersion): array` - Resolve version conflicts
- `checkPluginCompatibility(string $pluginName, string $version, string $coreVersion): bool`
- `findUpgradePath(string $pluginName, string $currentVersion, string $targetVersion): array`

### Plugin Update Workflows

#### How Plugins Survive Core Updates
1. **Persistent Storage**: Plugins stored in `plugins_data` Docker volume
2. **Volume Persistence**: Docker volumes survive container/image updates
3. **Compatibility Check**: On core update, system checks plugin compatibility
4. **Auto-Resolution**: Incompatible plugins automatically updated to compatible versions
5. **Fallback**: If no compatible version exists, plugin disabled (not removed)

#### Core Update with Plugin Compatibility Resolution
1. **Version Detection**: Admin initiates core update to version X.X.X
2. **Compatibility Analysis**: For each installed plugin:
   - Check `engines.core` range in plugin.json against target core version
   - Query plugin registry for compatible versions
   - Select highest compatible version (or disable if none found)
3. **Update Planning**: Generate plan showing:
   - ✅ **Keep**: Plugin compatible, no changes needed
   - 🔄 **Update**: Plugin needs newer version for compatibility
   - ❌ **Disable**: No compatible version available (temporary)
4. **Execution** (maintenance mode with backups):
   - Create DB backup + plugin directory snapshot
   - Pull new core Docker images and restart containers
   - Download updated plugins to `/plugins/_staging`
   - Verify GPG signatures and SHA256 checksums
   - Atomically swap plugin directories (`_staging` → plugin folder)
   - Run **only pending Doctrine migrations** per plugin
   - Clear/warmup Symfony cache (loads new plugin bundles)
   - Health checks and re-enable site

#### Plugin-Only Updates
1. **Manual Update**: Admin selects plugin for update from CMS UI
2. **Version Resolution**: System finds latest compatible version
3. **Download & Verify**: Download to staging, verify integrity
4. **Migration Preview**: Show which Doctrine migrations will run
5. **Atomic Update**: Swap plugin files, run pending migrations
6. **Cache Rebuild**: Clear Symfony cache to load new plugin code
7. **Health Checks**: Verify plugin functionality

#### Plugin Registry Integration
- **Registry API**: JSON endpoint returning available plugin versions
- **Compatibility Data**: Each version includes `engines.core` range
- **Download URLs**: Secure links with checksums and signatures
- **Version Resolution**: Semantic version comparison with compatibility constraints

#### Example Compatibility Resolution
```php
// Target core: 1.8.0
// Plugin: acme/seo current: 2.2.0

$compatibleVersions = $registry->getVersions('acme/seo', [
    'engines.core' => '>=1.6.0 <2.0.0'  // From plugin.json
]);

// Available: 2.2.0, 2.3.1, 3.0.0
// Compatible with core 1.8.0: 2.2.0, 2.3.1
// Select: 2.3.1 (highest compatible)

// Result: Update acme/seo from 2.2.0 → 2.3.1
```

#### Plugin Persistence Guarantee
- ✅ **Files**: Stored in Docker volume, survive image updates
- ✅ **Database**: Plugin migrations tracked in Doctrine tables
- ✅ **Configuration**: Plugin settings persist across updates
- ✅ **User Data**: Plugin-generated content preserved
- ❌ **No Loss**: Plugins never deleted during core updates

### Doctrine Migration Handling for Plugins

**Chosen Strategy**: Doctrine migrations per plugin for consistency with core system and full rollback capability.

#### Plugin Migration Structure
- Each plugin includes namespaced Doctrine migration classes
- Example: `Acme\Seo\Migrations\Version20250110Init`
- Migrations follow standard Doctrine patterns with `up()` and `down()` methods
- Migration execution scoped to plugin namespace to avoid conflicts

#### Migration Execution Process
1. **Plugin Installation/Update**: Load plugin migration classes dynamically
2. **Namespace Isolation**: Execute migrations within plugin-specific namespace
3. **Version Tracking**: Doctrine's `doctrine_migration_versions` table tracks applied migrations
4. **Transactional Safety**: All migrations run transactionally with rollback capability

#### Plugin Migration Example
```php
<?php
declare(strict_types=1);

namespace Acme\Seo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250110Init extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialize SEO plugin tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE acme_seo_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    title VARCHAR(255),
    description TEXT,
    keywords VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE acme_seo_metadata');
    }
}
```

### Implementation Strategy

#### Phase 1: Plugin Infrastructure
1. Create persistent volume structure for plugin storage
2. Implement plugin manifest validation and parsing
3. Create installed_plugins and plugin_migrations tables
4. Build PluginManagerService foundation
5. Add plugin directory management utilities

#### Phase 2: Plugin Registry Integration
1. Create PluginRegistryService for external plugin discovery
2. Implement plugin download and signature verification
3. Add plugin package extraction and integrity checking
4. Create plugin manifest caching system
5. Build plugin search and browsing interface

#### Phase 3: Plugin Lifecycle Management
1. Implement install/update/remove operations
2. Create enable/disable functionality with dependency checking
3. Add plugin health monitoring and self-checks
4. Implement plugin configuration management
5. Create plugin permission and security controls

#### Phase 4: Migration System Integration
1. Choose migration strategy (Doctrine vs SQL files)
2. Implement PluginMigrationService
3. Create migration dependency resolution
4. Add rollback capability for plugin updates
5. Integrate with core update process

#### Phase 5: Compatibility & Auto-Resolution
1. Implement PluginCompatibilityService
2. Create version conflict resolution algorithms
3. Add automatic plugin updates during core upgrades
4. Implement compatibility testing framework
5. Create admin interface for compatibility management

#### Phase 6: Advanced Features & Security
1. Add plugin marketplace with ratings/reviews
2. Implement plugin dependency management
3. Create sandboxed plugin execution environment
4. Add comprehensive security scanning
5. Implement plugin update scheduling and automation

### Security Considerations

#### 1. Plugin Package Security
- **GPG Signature Verification**: All plugins must be signed by trusted developers
- **SHA256 Integrity Checks**: Verify package and file integrity
- **Sandbox Execution**: Plugins run in restricted environment
- **File System Isolation**: Plugins limited to designated directories

#### 2. Runtime Security
- **Permission System**: Plugins request specific permissions
- **Database Access Control**: Limited database operations per plugin
- **Network Restrictions**: Control external API access
- **Resource Limits**: CPU/memory limits per plugin

#### 3. Update Security
- **Trusted Registry**: Only install from verified plugin registry
- **Version Validation**: Prevent downgrade attacks
- **Migration Auditing**: Log all database changes by plugins
- **Rollback Verification**: Ensure rollback operations are safe

### Dependencies
- Add `guzzlehttp/guzzle` for registry API calls (if not present)
- Add `symfony/finder` for plugin file discovery
- Add `paragonie/halite` or similar for signature verification
- Consider adding `composer/semver` for version compatibility checking

### Dynamic Plugin Loading in Symfony

#### PSR-4 Autoloading for Plugins
- **Runtime Autoloading**: Plugins register PSR-4 namespaces on enable
- **ClassLoader Integration**: Modify Composer autoloader to include plugin paths
- **Cache Management**: Clear/warmup Symfony cache after plugin changes

#### Symfony Bundle Registration
- **Dynamic Bundles**: Plugins can include Symfony bundles in manifest
- **Kernel Integration**: Register plugin bundles with Symfony kernel
- **Compiler Passes**: Allow plugins to register custom compiler passes

#### Plugin Loading Process
1. **Boot Time**: Scan enabled plugins in `installed_plugins` table
2. **Autoloader Setup**: Register PSR-4 paths for each plugin
3. **Bundle Registration**: Load and register Symfony bundles
4. **Cache Warmup**: Rebuild container with new plugin classes

#### Symfony Container Cache Management (Critical)
**Risk**: Runtime bundle changes require proper cache management; incorrect handling causes odd bugs, class loading issues, or stale container definitions.

**Solution**: Comprehensive cache rebuild with PHP-FPM bounce
```php
// In PluginLoaderService - Critical for runtime bundle changes
public function loadEnabledPlugins(): void
{
    $enabledPlugins = $this->pluginRepository->findEnabledPlugins();

    foreach ($enabledPlugins as $plugin) {
        // Register PSR-4 autoloading
        $this->autoloader->addPsr4(
            $plugin->getPsr4Namespace(),
            "/var/www/html/plugins/{$plugin->getName()}/src"
        );

        // Register Symfony bundles
        foreach ($plugin->getSymfonyBundles() as $bundleClass) {
            $this->kernel->registerBundle(new $bundleClass());
        }
    }

    // CRITICAL: Full cache rebuild sequence
    $this->rebuildSymfonyCache();
}

// Critical cache rebuild sequence after plugin changes
public function rebuildSymfonyCache(): void
{
    // Step 1: Clear cache without warmup (invalidate old container)
    $this->process->run('php bin/console cache:clear --no-warmup --env=prod');

    // Step 2: Clear OPcache if available (prevent stale bytecode)
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    // Step 3: Full cache warmup with new bundle definitions
    $this->process->run('php bin/console cache:warmup --env=prod');

    // Step 4: Graceful PHP-FPM reload (load new code without killing connections)
    $this->orchestrator->exec('backend', 'kill -USR2 $(pidof php-fpm)');

    // Step 5: Health check to verify plugin loading
    $this->healthChecker->verifyPluginLoading();
}
```

#### Cache Rebuild Triggers
- **Plugin Enable/Disable**: Full cache rebuild required
- **Plugin Update**: Cache rebuild after migration execution
- **Core Update**: Cache rebuild after image restart
- **Emergency**: Manual cache rebuild available in admin UI

#### OPcache Invalidation
```php
// In PluginLoaderService
public function invalidateOpcodeCache(): void
{
    // Reset OPcache to load new plugin classes
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    // Invalidate specific plugin files if possible
    if (function_exists('opcache_invalidate')) {
        foreach ($this->getPluginFiles() as $file) {
            opcache_invalidate($file, true);
        }
    }
}
```

### Success Criteria
- **Seamless Updates**: Plugins survive core updates with automatic compatibility resolution
- **Safe Migrations**: Plugin database changes are transactional with rollback
- **User Experience**: Easy plugin discovery, installation, and management
- **Security**: All plugins verified and sandboxed
- **Performance**: Plugin loading and execution optimized
- **Compatibility**: Automatic version resolution prevents conflicts

## Group-Based User Data Access Control

### Overview
Implement role-based and group-based data access control system where users can only see and manage data (users, user data) from groups they are authorized to access. This prevents data leakage and ensures proper data segregation in multi-tenant environments.

### Core Requirements
- **Role-Based Access**: Three distinct roles for different access levels
- **Group-Based Filtering**: Users only see data from their authorized groups
- **Strict Referential Integrity**: All database relationships properly enforced
- **Audit Trail**: All data access attempts logged with full context
- **Automatic Filtering**: API endpoints automatically filter data based on permissions

### Role Structure

#### 1. `data_admin` Role
- **Purpose**: Full access to all users and user data across all groups
- **Permissions**:
  - View all users regardless of group
  - Edit all users regardless of group
  - View all user data regardless of group
  - Edit all user data regardless of group
  - Bypass all group-based restrictions
- **Use Case**: System administrators, compliance officers

#### 2. `user_manager` Role
- **Purpose**: Manage users within selected groups
- **Permissions**:
  - View users only in assigned groups
  - Edit users only in assigned groups
  - Create users in assigned groups
  - Delete users in assigned groups
  - Cannot access user data (financial, personal info)
- **Use Case**: HR managers, team leads

#### 3. `user_data_manager` Role
- **Purpose**: Manage user data within selected groups
- **Permissions**:
  - View user data only for users in assigned groups
  - Edit user data only for users in assigned groups
  - Cannot view or edit user accounts themselves
  - Cannot create/delete users
- **Use Case**: Customer service, data processors

### Database Schema Changes

#### 1. Unified Table: `data_access_permissions`
- **Purpose**: Single comprehensive permissions table with typed nullable FKs for referential integrity
- **Structure** (MySQL 8, Strict Referential Integrity):
  - `id` INT PRIMARY KEY AUTO_INCREMENT
  - `role_id` INT NOT NULL (FK to roles.id, CASCADE DELETE)
  - `group_id` INT NULL (FK to groups.id, CASCADE DELETE) - Whose data: NULL means "all groups/users"
  - `data_type` ENUM('users','user_data','pages','assets','all') NOT NULL - What type: users, user_data, pages, etc.
  - **Typed Resource FKs (enforced referential integrity):**
    - `data_table_id` INT NULL (FK to data_tables.id, CASCADE DELETE) - Specific data table
    - `page_id` INT NULL (FK to pages.id, CASCADE DELETE) - Specific page
    - `asset_id` INT NULL (FK to assets.id, CASCADE DELETE) - Specific asset
  - `crud_mask` TINYINT UNSIGNED NOT NULL DEFAULT 2 - Bitmask: 1=C, 2=R, 4=U, 8=D
  - `rule_type` ENUM('allowed','excluded','masked','readonly','conditional') DEFAULT 'allowed'
  - `conditions` JSON NULL - Future: Time-based, context-based conditions
  - `field_permissions` JSON NULL - Future: Field-level access control
  - `metadata` JSON NULL - Future: Extensible configuration options
  - `priority` INT NOT NULL DEFAULT 0 - Resolution priority for conflicts
  - `is_active` BOOLEAN NOT NULL DEFAULT TRUE
  - `created_by` INT NOT NULL (FK to users.id, RESTRICT)
  - `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  - `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  - **Integrity Constraint**: Only one resource FK can be set per row
  - KEY `idx_role_group` (`role_id`, `group_id`, `data_type`)
  - KEY `idx_data_table` (`role_id`, `data_type`, `data_table_id`, `is_active`)
  - KEY `idx_page` (`role_id`, `data_type`, `page_id`, `is_active`)
  - KEY `idx_asset` (`role_id`, `data_type`, `asset_id`, `is_active`)
  - KEY `idx_priority` (`priority`, `is_active`)

**Permission Logic Clarification:**
- **`group_id`**: Controls WHOSE data the user can access (which users' data)
- **`Typed Resource FKs`**: Controls WHICH specific resource within that data type
- **Example**: "User Manager can READ users in Sales group" vs "Data Manager can READ data for ALL users but only from dataTable #5"

**Design Benefits:**
- ✅ **Referential Integrity**: Real FKs prevent orphaned resource IDs
- ✅ **Performance**: Targeted indexes per resource family
- ✅ **Type Safety**: No polymorphic ambiguity in queries
- ✅ **Extensible**: Easy to add new resource families with new nullable columns
- ✅ **Future-Ready**: JSON fields for advanced features

#### 3. New Table: `data_access_audit`
- **Purpose**: Comprehensive audit log for data access operations
- **Structure** (MySQL 8, Strict Referential Integrity):
  - `id` INT PRIMARY KEY AUTO_INCREMENT
  - `user_id` INT NOT NULL (FK to users.id, RESTRICT)
  - `action` INT NOT NULL (FK to lookups.id) - References lookup table for actions
  - `data_type` INT NOT NULL (FK to lookups.id) - References lookup table for data types
  - `data_record_id` INT NULL
  - `data_table_id` INT NULL (FK to data_tables.id, SET NULL) - For user_data, which specific data table
  - `user_groups_at_time` JSON NOT NULL
  - `users_roles_at_time` JSON NOT NULL
  - `access_granted` BOOLEAN NOT NULL
  - `filter_criteria` JSON NULL
  - `records_affected_count` INT NULL
  - `ip_address` VARCHAR(45) NULL
  - `user_agent` VARCHAR(500) NULL
  - `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  - KEY `idx_user_timestamp` (`user_id`, `timestamp`)
  - KEY `idx_action` (`action`, `timestamp`)
  - KEY `idx_data_type_audit` (`data_type`, `timestamp`)
  - KEY `idx_access_granted` (`access_granted`, `timestamp`)
  - KEY `idx_data_table_audit` (`data_table_id`, `timestamp`)

#### 4. Enhanced Configuration Options for User Data

**Data Table Selection Modes:**
- **All Tables**: Access to all data tables for users in assigned groups (default)
- **Specific Tables**: Access only to explicitly selected data tables
- **Exclude Tables**: Access to all tables except explicitly excluded ones

**Custom Data Table Control:**
- **Individual Table Permissions**: Configure different CRUD permissions for each data table
- **Field-Level Access**: Control access to specific fields within data tables
- **Conditional Access**: Time-based or context-based access to data tables
- **Data Masking**: Automatically mask sensitive fields based on user role
- **Audit Trail**: Track all data table access with detailed field-level logging

**Configuration Structure in role_group_permissions:**
- `user_data_table_mode` INT NOT NULL (FK to lookups.id) - References lookup table for modes
- `created_by` INT NOT NULL (FK to users.id, RESTRICT)
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

**Required Lookup Values:**
```sql
-- Data Types
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('DATA_TYPES', 'users', 'Users', 1),
('DATA_TYPES', 'user_data', 'User Data', 2),
('DATA_TYPES', 'pages', 'Pages', 3),
('DATA_TYPES', 'content', 'Content', 4),
('DATA_TYPES', 'assets', 'Assets', 5),
('DATA_TYPES', 'all', 'All Data Types', 6);

-- Permission Scopes
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('PERMISSION_SCOPES', 'assigned_groups', 'Assigned Groups Only', 1),
('PERMISSION_SCOPES', 'all_groups', 'All Groups', 2);

-- Permission Modes (General)
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('PERMISSION_MODES', 'all', 'All Resources', 1),
('PERMISSION_MODES', 'specific', 'Specific Resources Only', 2),
('PERMISSION_MODES', 'exclude', 'Exclude Specific Resources', 3);

-- Rule Types (Specific rules for resources)
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('RULE_TYPES', 'allowed', 'Allowed Access', 1),
('RULE_TYPES', 'excluded', 'Excluded Access', 2),
('RULE_TYPES', 'masked', 'Masked Data', 3),
('RULE_TYPES', 'readonly', 'Read Only', 4),
('RULE_TYPES', 'conditional', 'Conditional Access', 5);

-- Resource Types
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('RESOURCE_TYPES', 'data_table', 'Data Table', 1),
('RESOURCE_TYPES', 'page', 'Page', 2),
('RESOURCE_TYPES', 'section', 'Section', 3),
('RESOURCE_TYPES', 'asset', 'Asset', 4),
('RESOURCE_TYPES', 'field', 'Field', 5);

-- Audit Actions
INSERT INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `sort_order`) VALUES
('AUDIT_ACTIONS', 'create', 'Create', 1),
('AUDIT_ACTIONS', 'read', 'Read', 2),
('AUDIT_ACTIONS', 'update', 'Update', 3),
('AUDIT_ACTIONS', 'delete', 'Delete', 4),
('AUDIT_ACTIONS', 'list', 'List', 5),
('AUDIT_ACTIONS', 'filter', 'Filter', 6),
('AUDIT_ACTIONS', 'export', 'Export', 7),
('AUDIT_ACTIONS', 'import', 'Import', 8);
```

**Example Configurations:**

```sql
-- 1. User Manager: Can READ users in Sales group only
INSERT INTO data_access_permissions (
    role_id, group_id, data_type, crud_mask, rule_type
) VALUES (
    (SELECT id FROM roles WHERE name = 'user_manager'),
    (SELECT id FROM groups WHERE name = 'sales'),
    'users',
    2, -- READ (binary: 0010)
    'allowed'
);

-- 2. Data Manager: Can access ALL user data for ALL users, but only from dataTable #5
INSERT INTO data_access_permissions (
    role_id, group_id, data_type, data_table_id, crud_mask, rule_type
) VALUES (
    (SELECT id FROM roles WHERE name = 'user_data_manager'),
    NULL, -- ALL groups/users
    'user_data',
    5, -- Specific data table ID
    6, -- READ + UPDATE (binary: 0110)
    'allowed'
);

-- 3. Customer Service: Can READ contact data for ALL users, but EXCLUDE sensitive financial data
INSERT INTO data_access_permissions (
    role_id, group_id, data_type, data_table_id, crud_mask, rule_type
) VALUES (
    (SELECT id FROM roles WHERE name = 'user_data_manager'),
    NULL, -- ALL groups/users
    'user_data',
    2, -- Financial data table ID
    2, -- READ allowed
    'excluded' -- But this table is excluded
);
```

**Permission Logic Examples:**
- **Example 1**: User Manager can see user accounts only from Sales group
- **Example 2**: Data Manager can see ALL user data, but only from dataTable #5 (specific table restriction)
- **Example 3**: Customer Service can see contact data for all users, but financial data table #2 is excluded

### Effective Access Logic

#### Effective Groups Calculation
```
effective_groups = union(
  groups where (user has membership) ∩ (role grants assigned_groups),
  all groups where (role grants all_groups)
)
```

#### Conflict Resolution Rule
- **Deny-by-default**: Access is denied unless explicitly granted
- **Allow if any role grants**: If any role grants permission for the specific data_type & action within effective groups, allow access
- **Most restrictive wins**: For conflicting permissions, the most restrictive setting applies

### JSON Group Assignment Integrity Problem

#### Issue: Orphaned Group References
When groups are deleted, JSON arrays containing group IDs (`allowed_data_table_ids`, `excluded_data_table_ids`) won't be automatically updated, leading to:
- **Stale references**: JSON contains IDs of deleted groups
- **Inconsistent permissions**: Access rules reference non-existent groups
- **Security risks**: Unexpected access patterns due to orphaned references
- **Maintenance burden**: Manual cleanup required after group deletions

#### Solution: Proper Relational Design
**Replaced JSON approach with junction tables** to maintain referential integrity:

**permission_data_table_rules table** (instead of JSON arrays):
- `role_group_permission_id` → `role_group_permissions.id` (CASCADE DELETE)
- `data_table_id` → `data_tables.id` (CASCADE DELETE)
- `rule_type` → `lookups.id` ('allowed' or 'excluded')

**Benefits:**
- ✅ **Automatic cleanup**: Deleting groups automatically removes related permissions
- ✅ **Referential integrity**: Foreign key constraints prevent orphaned records
- ✅ **Performance**: Indexed joins instead of JSON parsing
- ✅ **Maintainability**: Standard SQL queries instead of JSON operations
- ✅ **Auditability**: Clear relationship tracking

#### Migration Strategy for Existing JSON Data
1. **Extract JSON arrays** from existing `role_group_permissions` records
2. **Create junction table records** for each group ID in JSON arrays
3. **Remove JSON columns** after data migration
4. **Update application code** to use junction table queries

#### Example Migration Query
```sql
-- Migrate allowed_data_table_ids JSON to junction table
INSERT INTO permission_data_table_rules (
    role_group_permission_id,
    data_table_id,
    rule_type,
    created_by,
    created_at
)
SELECT
    rgp.id,
    JSON_UNQUOTE(JSON_EXTRACT(allowed_table.value, '$')) as data_table_id,
    (SELECT id FROM lookups WHERE lookup_code = 'allowed' AND type_code = 'DATA_TABLE_RULE_TYPES'),
    rgp.created_by,
    rgp.created_at
FROM role_group_permissions rgp
CROSS JOIN JSON_TABLE(rgp.allowed_data_table_ids, '$[*]' COLUMNS (value INT PATH '$')) allowed_table
WHERE rgp.allowed_data_table_ids IS NOT NULL;
```

### SQL-Based Filtering Strategy

#### Core Filtering Pattern (MySQL 8)
```sql
-- List users I can READ (whose user accounts I can see):
SELECT DISTINCT u.*
FROM users u
JOIN user_group_members ugm ON ugm.user_id = u.id
JOIN data_access_permissions dap
  ON dap.data_type IN ('users', 'all')
 AND (dap.crud_mask & 2) = 2  -- Check READ bit (0010)
 AND dap.is_active = 1
 AND dap.rule_type = 'allowed'
 AND (dap.group_id IS NULL OR dap.group_id = ugm.group_id) -- User's group matches permission
JOIN users_roles ur ON ur.user_id = :caller_id AND ur.role_id = dap.role_id
WHERE :caller_is_data_admin = 1 OR dap.id IS NOT NULL;

-- Read user_data for specific user (Example: "all data for all users but only dataTable=5"):
SELECT ud.*
FROM user_data ud
JOIN users u ON u.id = ud.user_id
JOIN user_group_members ugm ON ugm.user_id = u.id
JOIN data_access_permissions dap
  ON dap.data_type IN ('user_data', 'all')
 AND (dap.crud_mask & 2) = 2  -- Check READ bit
 AND dap.is_active = 1
 AND (dap.group_id IS NULL OR dap.group_id = ugm.group_id) -- Can access this user's data
 AND (dap.data_table_id IS NULL OR dap.data_table_id = ud.data_table_id) -- Specific table check
 AND dap.rule_type = 'allowed'
JOIN users_roles ur ON ur.user_id = :caller_id AND ur.role_id = dap.role_id
WHERE u.id = :target_user
  AND (:caller_is_data_admin = 1 OR dap.id IS NOT NULL);

-- Check if user can EXCLUDE access to financial data table:
SELECT 1
FROM data_access_permissions dap
JOIN users_roles ur ON ur.user_id = :caller_id AND ur.role_id = dap.role_id
WHERE dap.data_type IN ('user_data', 'all')
  AND (dap.crud_mask & 2) = 2  -- Has READ permission
  AND dap.is_active = 1
  AND dap.data_table_id = :financial_table_id  -- Specific table
  AND dap.rule_type = 'excluded'  -- But it's excluded
LIMIT 1;

-- List available data tables for a user (which tables I can access for this user):
SELECT DISTINCT dt.*
FROM data_tables dt
CROSS JOIN users target_user
JOIN user_group_members target_ugm ON target_ugm.user_id = target_user.id
JOIN data_access_permissions dap
  ON dap.data_type IN ('user_data', 'all')
 AND (dap.crud_mask & 2) = 2  -- Has READ permission
 AND dap.is_active = 1
 AND (dap.group_id IS NULL OR dap.group_id = target_ugm.group_id) -- Can access this user's data
 AND (dap.data_table_id IS NULL OR dap.data_table_id = dt.id) -- Table is allowed
 AND dap.rule_type = 'allowed'
LEFT JOIN data_access_permissions dap_exclude
  ON dap_exclude.data_type IN ('user_data', 'all')
 AND dap_exclude.data_table_id = dt.id
 AND dap_exclude.rule_type = 'excluded'
 AND dap_exclude.role_id = dap.role_id
 AND (dap_exclude.group_id IS NULL OR dap_exclude.group_id = target_ugm.group_id)
JOIN users_roles ur ON ur.user_id = :caller_id AND ur.role_id = dap.role_id
WHERE target_user.id = :target_user_id
  AND (:caller_is_data_admin = 1 OR dap.id IS NOT NULL)
  AND dap_exclude.id IS NULL; -- Not explicitly excluded
```

#### Required Indexes
- `user_group_members` (`user_id`, `group_id`)
- `users_roles` (`user_id`, `role_id`)
- `data_access_permissions` (`role_id`, `group_id`, `data_type`, `is_active`)
- `data_access_permissions` (`role_id`, `data_type`, `data_table_id`, `is_active`)
- `data_access_permissions` (`role_id`, `data_type`, `page_id`, `is_active`)
- `data_access_permissions` (`role_id`, `data_type`, `asset_id`, `is_active`)
- `data_access_permissions` (`priority`, `is_active`)

### Service Layer Architecture

#### 1. DataAccessControlService (Core Service)
- `hasPermission(int $userId, string $dataType, string $action, ?int $dataTableId = null, ?int $pageId = null, ?int $assetId = null): bool`
- `getAccessibleGroups(int $userId, string $dataType): array`
- `filterDataByPermissions(array $data, int $userId, string $dataType, callable $resourceExtractor): array`
- `auditAccess(int $userId, string $action, string $dataType, ?int $dataTableId, ?int $pageId, ?int $assetId, bool $granted, array $context): void`
- `getUserEffectivePermissions(int $userId, string $dataType): array`
- `resolvePermissionConflicts(array $permissions): array` - Priority-based conflict resolution

#### 2. RoleBasedAccessControlService
- `getUserRoles(int $userId): array`
- `hasRole(int $userId, string $roleName): bool`
- `isDataAdmin(int $userId): bool`
- `isUserManager(int $userId): bool`
- `isUserDataManager(int $userId): bool`

#### 3. PermissionManagementService
- `createPermission(int $roleId, ?int $groupId, string $dataType, array $config): DataAccessPermission`
- `updatePermission(int $permissionId, array $config): DataAccessPermission`
- `configureDataTableAccess(int $permissionId, ?int $dataTableId, string $ruleType): void`
- `configurePageAccess(int $permissionId, ?int $pageId, string $ruleType): void`
- `configureAssetAccess(int $permissionId, ?int $assetId, string $ruleType): void`
- `setPermissionPriority(int $permissionId, int $priority): void`
- `activatePermission(int $permissionId): void`
- `deactivatePermission(int $permissionId): void`

#### 4. ResourceAccessService
- `canAccessDataTable(int $userId, int $dataTableId, string $action): bool`
- `canAccessPage(int $userId, int $pageId, string $action): bool`
- `canAccessAsset(int $userId, int $assetId, string $action): bool`
- `getAccessibleDataTables(int $userId, string $action): array`
- `getAccessiblePages(int $userId, string $action): array`
- `getAccessibleAssets(int $userId, string $action): array`

#### 5. Modified AdminUserService
- Integrate with DataAccessControlService for automatic filtering
- Add role-based permission checks
- Implement group-based data segregation

### API Endpoints Structure

#### Role & Permission Management
- `GET /admin/roles/data-access` - List data access roles
- `POST /admin/roles/{roleId}/data-access/{groupId}` - Assign group access to role
- `DELETE /admin/roles/{roleId}/data-access/{groupId}` - Remove group access from role

#### Data Access Configuration
- `GET /admin/data-access/roles/{roleId}/permissions` - Get role data permissions
- `PUT /admin/data-access/roles/{roleId}/permissions` - Update role data permissions
- `GET /admin/data-access/groups/{groupId}/assignments` - Get group access assignments
- `PUT /admin/data-access/roles/{roleId}/groups/{groupId}/user-data-tables` - Configure data table access for user data
- `GET /admin/data-access/roles/{roleId}/groups/{groupId}/user-data-tables` - Get data table configuration

#### User Data Permissions
- `GET /admin/users/{userId}/data-permissions` - Get user data access permissions
- `POST /admin/users/{userId}/data-permissions` - Grant user data access
- `DELETE /admin/users/{userId}/data-permissions/{category}` - Revoke user data access

#### Filtered Data Access (Modified Endpoints)
- `GET /admin/users` - Returns users based on caller's permissions
- `GET /admin/users/{id}` - Returns user if caller has permission
- `GET /admin/users/{id}/data` - Returns user data if caller has permission
- `GET /admin/users/{id}/data/tables` - List data tables accessible for this user
- `GET /admin/users/{id}/data/tables/{tableId}` - Get specific data table data if permitted
- `POST /admin/users/{id}/data/tables/{tableId}` - Create data in table if permitted
- `PUT /admin/users/{id}/data/tables/{tableId}` - Update data in table if permitted
- `DELETE /admin/users/{id}/data/tables/{tableId}` - Delete data from table if permitted

#### Audit & Monitoring
- `GET /admin/data-access/audit` - Get data access audit logs
- `GET /admin/data-access/audit/users/{userId}` - Get audit for specific user
- `GET /admin/data-access/audit/summary` - Get access summary reports

### Implementation Strategy

#### Phase 1: Unified Database Schema
1. Create unified `data_access_permissions` table with extensible design
2. Create the three data access roles (`data_admin`, `user_manager`, `user_data_manager`)
3. Populate all required lookup values (data types, scopes, modes, rule types, resource types)
4. Create comprehensive database migration scripts with proper indexes

#### Phase 2: Core Access Control Foundation
1. Implement RoleBasedAccessControlService for role management
2. Create DataAccessControlService with unified permission checking
3. Implement PermissionManagementService for CRUD operations on permissions
4. Add comprehensive audit logging infrastructure with new audit actions

#### Phase 3: Resource-Based Access Control
1. Implement ResourceAccessService for flexible resource permission checking
2. Create permission conflict resolution with priority system
3. Add support for conditional access rules (time-based, context-based)
4. Implement field-level permissions preparation in metadata JSON

#### Phase 4: Data Table Customization
1. Implement data table selection modes (all, specific, exclude)
2. Create resource-specific permission configuration
3. Add data masking and field-level access control preparation
4. Implement custom permission rule engine for complex scenarios

#### Phase 5: Service Layer Integration
1. Modify AdminUserService with unified data access control
2. Update all data services to integrate with DataAccessControlService
3. Implement automatic SQL-based filtering for all data operations
4. Add comprehensive error handling and access denied responses

#### Phase 6: Advanced Features
1. Implement time-based and context-based conditional permissions
2. Add data masking capabilities for sensitive fields
3. Create permission inheritance and cascading rules
4. Implement bulk permission operations for efficiency

#### Phase 7: Administration & Monitoring
1. Create unified admin UI for permission management
2. Add real-time permission conflict detection and resolution
3. Implement comprehensive audit log viewer with filtering
4. Create permission analytics and usage reporting

#### Phase 8: Performance Optimization & Testing
1. Optimize SQL queries with proper indexing strategy
2. Implement permission caching with invalidation logic
3. Create comprehensive unit and integration test suites
4. Performance testing with large datasets and complex permission scenarios
5. Security testing for data leakage prevention and audit validation

### Security Considerations

#### 1. Data Leakage Prevention
- **Strict Access Control**: All data access validated against permissions
- **No Circumvention**: Direct database access blocked by application layer
- **Audit Everything**: Every data access attempt logged
- **Fail-Safe Defaults**: Deny access when permissions unclear

#### 2. Referential Integrity
- **Cascade Deletes**: Clean up permissions when groups/roles/users deleted
- **Restrict Deletes**: Prevent deletion of referenced entities
- **Foreign Key Constraints**: Enforce all relationships at database level
- **Transaction Safety**: All permission changes in transactions

#### 3. Performance Security
- **Efficient Queries**: Optimized permission checking
- **Caching Strategy**: Cache permissions with proper invalidation
- **Rate Limiting**: Prevent brute force permission checking
- **Resource Protection**: Limit expensive operations

### Success Criteria
- **Zero Data Leakage**: Users can only access data they're authorized for
- **Role-Based Access**: Three distinct roles with appropriate permissions
- **Strict Integrity**: All database relationships properly enforced
- **Comprehensive Audit**: All access attempts logged and auditable
- **Performance**: Efficient permission checking without performance impact
- **Scalability**: Support for large numbers of users and groups

Data Access Matrix:
┌─────────────────┬──────────────────────┐
│    WHOSE data   │    WHAT data         │
├─────────────────┼──────────────────────┤
│ group_id        │ resource_id          │
│ (user groups)   │ (specific resources) │
│                 │                      │
│ • NULL = all    │ • NULL = all         │
│ • Group ID =    │ • Resource ID =      │
│   specific group│   specific resource  │
└─────────────────┴──────────────────────┘

## Professional Deployment Integration

### Overview
Create a comprehensive deployment and operations system that provides enterprise-grade deployment capabilities, monitoring, backup/restore, and maintenance tools. This system should integrate seamlessly with modern DevOps practices while maintaining the simplicity of Docker-based deployment.

### Current Issues
- **No deployment automation**: Manual Docker commands and configuration
- **Limited monitoring**: No system health monitoring or alerting
- **Backup complexity**: No automated backup/restore procedures
- **Environment management**: Difficult to manage multiple environments
- **Maintenance burden**: Manual maintenance tasks and troubleshooting

### Core Requirements
- **CI/CD integration** with automated testing and deployment
- **Multi-environment support** (development, staging, production)
- **Comprehensive monitoring** with dashboards and alerting
- **Automated backup/restore** with retention policies
- **Log aggregation** and analysis
- **Performance monitoring** and optimization
- **Security scanning** and compliance checking
- **Documentation generation** and deployment guides

### Database Schema Changes

#### 1. New Table: `system_environments`
- **Purpose**: Track different deployment environments
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `name` VARCHAR(50) UNIQUE - e.g., 'production', 'staging', 'development'
  - `description` TEXT
  - `is_active` BOOLEAN DEFAULT TRUE
  - `config` JSON - Environment-specific configuration
  - `created_at` DATETIME
  - `updated_at` DATETIME

#### 2. New Table: `system_backups`
- **Purpose**: Track backup operations and status
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `backup_type` ENUM('full', 'incremental', 'database', 'files')
  - `environment_id` INT (FK to system_environments)
  - `status` ENUM('pending', 'running', 'completed', 'failed')
  - `file_path` VARCHAR(500)
  - `file_size` BIGINT
  - `checksum` VARCHAR(128) - SHA256 hash
  - `created_at` DATETIME
  - `completed_at` DATETIME NULL
  - `retention_days` INT DEFAULT 30

#### 3. New Table: `system_monitoring`
- **Purpose**: Store monitoring metrics and alerts
- **Structure** (MySQL 8):
  - `id` (PRIMARY KEY)
  - `metric_type` VARCHAR(100) - e.g., 'cpu_usage', 'memory_usage', 'response_time'
  - `metric_value` DECIMAL(10,2)
  - `environment_id` INT (FK to system_environments)
  - `service_name` VARCHAR(100) - e.g., 'backend', 'frontend', 'database'
  - `timestamp` DATETIME
  - `alert_triggered` BOOLEAN DEFAULT FALSE
  - `alert_message` TEXT NULL

### API Endpoints

#### Environment Management
- `GET /admin/system/environments` - List all environments
- `POST /admin/system/environments` - Create new environment
- `PUT /admin/system/environments/{id}` - Update environment configuration
- `DELETE /admin/system/environments/{id}` - Remove environment

#### Backup & Restore
- `POST /admin/system/backup/create` - Create system backup
- `GET /admin/system/backup/list` - List available backups
- `POST /admin/system/backup/restore/{backup_id}` - Restore from backup
- `DELETE /admin/system/backup/{backup_id}` - Delete backup
- `GET /admin/system/backup/status` - Get backup operation status

#### Monitoring & Health
- `GET /admin/system/health` - System health check
- `GET /admin/system/metrics` - Get system metrics
- `GET /admin/system/logs` - Get system logs
- `POST /admin/system/alerts/test` - Test alert system
- `GET /admin/system/performance` - Get performance metrics

### Service Layer Changes

#### 1. DeploymentService
- `deployToEnvironment(string $environment, string $version): bool` - Deploy specific version to environment
- `rollbackEnvironment(string $environment, string $version): bool` - Rollback environment to previous version
- `getDeploymentStatus(string $environment): array` - Get deployment status
- `validateDeployment(string $environment): array` - Pre-deployment validation

#### 2. MonitoringService
- `collectMetrics(): array` - Collect system metrics
- `checkHealth(): array` - Perform health checks
- `sendAlerts(array $alerts): bool` - Send monitoring alerts
- `getPerformanceData(): array` - Get performance statistics
- `analyzeLogs(): array` - Analyze system logs for issues

#### 3. BackupRestoreService
- `createBackup(array $options): Backup` - Create system backup
- `restoreBackup(int $backupId): bool` - Restore from backup
- `validateBackup(int $backupId): bool` - Verify backup integrity
- `cleanupOldBackups(): bool` - Remove expired backups
- `getBackupSchedule(): array` - Get backup scheduling information

#### 4. EnvironmentService
- `createEnvironment(array $config): Environment` - Create new environment
- `updateEnvironment(int $id, array $config): bool` - Update environment
- `syncEnvironments(): bool` - Sync environment configurations
- `validateEnvironment(int $id): array` - Validate environment configuration

### Implementation Strategy

#### Phase 1: Infrastructure Automation
1. Create Docker Compose templates for different environments
2. Implement environment configuration management
3. Add automated SSL certificate management
4. Create deployment scripts and CI/CD pipelines
5. Implement blue-green deployment strategy

#### Phase 2: Monitoring & Observability
1. Integrate Prometheus/Grafana for metrics collection
2. Implement health check endpoints for all services
3. Add log aggregation with ELK stack or similar
4. Create alerting system for critical issues
5. Implement performance monitoring and profiling

#### Phase 3: Backup & Disaster Recovery
1. Create automated backup system for database and files
2. Implement backup encryption and secure storage
3. Add backup validation and integrity checking
4. Create disaster recovery procedures and testing
5. Implement backup retention and cleanup policies

#### Phase 4: Security & Compliance
1. Add security scanning for containers and dependencies
2. Implement compliance checking (GDPR, HIPAA, etc.)
3. Create security audit logging and reporting
4. Add intrusion detection and prevention
5. Implement secure configuration management

#### Phase 5: Operations & Maintenance
1. Create automated maintenance scripts (log rotation, cleanup)
2. Implement capacity planning and scaling
3. Add performance optimization tools
4. Create operational runbooks and documentation
5. Implement change management and approval workflows

#### Phase 6: CI/CD Integration
1. Create GitHub Actions or similar CI/CD pipelines
2. Implement automated testing (unit, integration, e2e)
3. Add deployment approval and rollback workflows
4. Create staging environment automation
5. Implement canary deployment strategies

### Security Considerations

#### 1. Access Control
- **Role-based access** for deployment and monitoring operations
- **Audit logging** for all administrative actions
- **Multi-factor authentication** for critical operations
- **API key management** for external integrations

#### 2. Data Protection
- **Encryption at rest** for backups and sensitive data
- **Secure communication** between all services
- **Data classification** and appropriate protection levels
- **Compliance frameworks** for different regulations

#### 3. Infrastructure Security
- **Container hardening** and vulnerability scanning
- **Network segmentation** and firewall rules
- **Secret management** and credential rotation
- **Regular security updates** and patch management

### Dependencies
- Add monitoring stack (Prometheus, Grafana, Alertmanager)
- Add logging stack (ELK or Loki)
- Add backup tools (restic, borgbackup)
- Add security scanning tools (Trivy, Clair)
- Consider cloud provider integrations (AWS, Azure, GCP)

### Success Criteria
- **Automated deployment**: One-click deployment to any environment
- **Full observability**: Complete monitoring and alerting system
- **Reliable backups**: Automated, tested backup/restore procedures
- **Security compliance**: Enterprise-grade security and compliance
- **Operational excellence**: Automated maintenance and optimization
- **Scalability**: Support for high-traffic production deployments