# Database Design

> ⚠️ **Document status.** This file describes the historical legacy schema
> for context. The canonical schema is now defined by Doctrine migrations
> only (`migrations/Version20260601000000.php` baseline + four seed
> migrations). The runtime contract uses **canonical names**:
> `data_tables`, `data_rows`, `data_cols`, `data_cells`, `scheduled_jobs`,
> `scheduled_job_reminders`, `refresh_tokens`, `api_request_logs`,
> `callback_logs`, `data_access_audits`, `page_types`, `field_types`,
> `style_groups`, `log_performance`, `user_2fa_codes`, `user_activities`,
> `rel_groups_users`, `rel_roles_users`, `rel_permissions_roles`,
> `rel_api_routes_permissions`, `rel_pages_sections`, `rel_fields_pages`,
> `rel_fields_styles`, `rel_fields_page_types`, `rel_sections_hierarchy`,
> `rel_sections_navigation`, `rel_styles_allowed_relationships`,
> `page_acl_groups` (was `acl_groups`), `validation_code_groups`
> (was `codes_groups`). All FK columns follow `id_<target_table>` (with
> explicit self-references like `id_parent_page`, `id_child_section`,
> `id_parent_scheduled_job`). Indexes, foreign keys and uniques are
> `idx_*`, `fk_*`, `uq_*` in `lowercase_snake_case`. The mixed-case
> identifiers shown in the legacy CREATE TABLE snippets below
> (`dataTables`, `users_groups`, `id_pageAccessTypes`, …) are **no longer
> used at runtime** — they only appear in `db/legacy/new_create_db.sql`,
> which the four seed migrations consume transitionally through
> `migrations/LegacySeedTrait.php` rename mappings.

## 🗄️ Database Architecture Overview

The SelfHelp Symfony Backend uses a sophisticated MySQL database design that supports dynamic routing, fine-grained permissions, content management, and comprehensive audit trails.

## 📊 Database Schema Overview

```mermaid
erDiagram
    %% Core Authentication & Authorization - Admin Users (Role-based)
    users ||--o{ rel_roles_users : has_roles
    rel_roles_users }o--|| roles : belongs_to
    roles ||--o{ rel_permissions_roles : grants
    rel_permissions_roles }o--|| permissions : permission_type

    %% Frontend User Groups (for page ACL)
    users ||--o{ rel_groups_users : belongs_to
    rel_groups_users }o--|| groups : represents

    %% API Routes & Permissions (Admin Access)
    api_routes ||--o{ rel_api_routes_permissions : requires
    rel_api_routes_permissions }o--|| permissions : grants

    %% CMS Content Structure
    pages ||--o{ rel_pages_sections : contains
    rel_pages_sections }o--|| sections : has
    sections ||--o{ rel_sections_hierarchy : contains
    sections }o--|| styles : styled_by

    %% Page Versioning & Publishing
    pages ||--o{ page_versions : has_versions
    page_versions }o--|| users : created_by
    pages ||--|| page_versions : id_published_page_versions

    %% Fine-grained Access Control (Frontend Users)
    pages ||--o{ page_acl_groups : group_acl
    page_acl_groups }o--|| groups : for_group

    %% Multi-language Support
    fields ||--o{ sections_fields_translation : translations
    sections_fields_translation }o--|| languages : in_language
    pages ||--o{ pages_fields_translation : page_translations
    pages_fields_translation }o--|| languages : in_language

    %% Dynamic Data Tables System
    data_tables ||--o{ data_rows : contains
    data_rows ||--o{ data_cells : has
    data_cells }o--|| data_cols : column
    data_cells }o--|| languages : language

    %% Field Types
    fields }o--|| field_types : field_type

    %% System Components
    users ||--o{ transactions : performed_by
    users ||--o{ api_request_logs : made_requests
    assets }o--|| lookups : asset_type
```

## 🔧 Core Table Groups

### 1. Authentication & Authorization Tables

#### `users` - User Accounts
```sql
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `token` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_1483A5E9F85E0677` (`username`),
  UNIQUE KEY `UNIQ_1483A5E9E7927C74` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `groups` - User Groups
```sql
CREATE TABLE `groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_F06D39705E237E06` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### `permissions` - System Permissions
```sql
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_2DEDCC6F5E237E06` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `roles` - Admin Roles
```sql
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_B63E2EC75E237E06` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin roles for CMS backend access
INSERT INTO `roles` (`name`, `description`) VALUES
('admin', 'Administrator role with full access');
```

#### Junction Tables
- **`rel_roles_users`**: Links users to roles (many-to-many) - Admin role assignments
- **`rel_permissions_roles`**: Links roles to permissions (many-to-many) - Role-based permissions for admin system

#### `permissions` - System Permissions
```sql
-- Permissions for API routes and system operations
INSERT INTO `permissions` (`name`, `description`) VALUES
('admin.dashboard.view', 'View admin dashboard'),
('admin.user.view', 'View users'),
('admin.user.create', 'Create users'),
('admin.user.edit', 'Edit users'),
('admin.user.delete', 'Delete users'),
('admin.page.view', 'View pages'),
('admin.page.create', 'Create pages'),
('admin.page.edit', 'Edit pages'),
('admin.page.delete', 'Delete pages'),
('admin.section.manage', 'Manage sections'),
('admin.asset.manage', 'Manage assets'),
('admin.job.manage', 'Manage scheduled jobs'),
('admin.acl.manage', 'Manage ACL permissions'),
('admin.system.manage', 'System administration');
```

#### Junction Tables
- **`rel_groups_users`**: Links users to groups (many-to-many) - Used for frontend user group memberships (page ACL)
- **`rel_roles_users`**: Links users to roles (many-to-many) - Admin role assignments
- **`rel_permissions_roles`**: Links roles to permissions (many-to-many) - Role-based permissions for admin system

### 2. Dynamic Routing Tables

#### `api_routes` - Dynamic Route Definitions
```sql
CREATE TABLE `api_routes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `route_name` varchar(100) NOT NULL,
  `version` varchar(10) NOT NULL DEFAULT 'v1',
  `path` varchar(255) NOT NULL,
  `controller` varchar(255) NOT NULL,
  `methods` varchar(50) NOT NULL,
  `requirements` json DEFAULT NULL,
  `params` json DEFAULT NULL COMMENT 'Expected parameters: name → {in: body|query, required: bool}',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_route_name_version` (`route_name`,`version`),
  UNIQUE KEY `uniq_version_path_methods` (`version`,`path`,`methods`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key Features:**
- **Dynamic Loading**: Routes loaded from database at runtime
- **Versioning Support**: Multiple API versions per route
- **Parameter Documentation**: JSON schema for expected parameters
- **Method Specification**: HTTP methods (GET, POST, PUT, DELETE)

#### `rel_api_routes_permissions` - Route Permission Requirements
```sql
CREATE TABLE `rel_api_routes_permissions` (
  `id_api_routes` int NOT NULL,
  `id_permissions` int NOT NULL,
  PRIMARY KEY (`id_api_routes`,`id_permissions`),
  FOREIGN KEY (`id_api_routes`) REFERENCES `api_routes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_permissions`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3. Content Management System Tables

#### `pages` - CMS Pages
```sql
CREATE TABLE `pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  `url` varchar(255) NULL,
  `id_parent_page` int DEFAULT NULL,
  `id_page_types` int NOT NULL,
  `id_page_access_types` int DEFAULT NULL,
  `is_headless` tinyint(1) NOT NULL DEFAULT '0',
  `nav_position` int DEFAULT NULL,
  `footer_position` int DEFAULT NULL,
  `is_open_access` tinyint(1) DEFAULT '0',
  `is_system` tinyint(1) DEFAULT '0',
  `id_published_page_versions` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pages_keyword` (`keyword`),
  KEY `idx_pages_id_published_page_versions` (`id_published_page_versions`),
  CONSTRAINT `fk_pages_id_parent_page`                FOREIGN KEY (`id_parent_page`)                REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pages_id_page_types`                 FOREIGN KEY (`id_page_types`)                 REFERENCES `page_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pages_id_page_access_types`          FOREIGN KEY (`id_page_access_types`)          REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pages_id_published_page_versions`    FOREIGN KEY (`id_published_page_versions`)    REFERENCES `page_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `sections` - Content Sections
```sql
CREATE TABLE `sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `id_styles` int NOT NULL,
  `position` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sections_id_styles` FOREIGN KEY (`id_styles`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

Parent/child relationships between sections live in the dedicated relation
tables `rel_sections_hierarchy` and `rel_sections_navigation`, with explicit
`id_parent_section` / `id_child_section` foreign keys.

#### `fields` - Content Fields
```sql
CREATE TABLE `fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `id_type` int NOT NULL,           -- legacy column name retained
  `display` tinyint(1) NOT NULL,
  `config` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fields_name` (`name`),
  CONSTRAINT `fk_fields_id_type` FOREIGN KEY (`id_type`) REFERENCES `field_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### Junction Tables for CMS (canonical `rel_` naming)
- **`rel_pages_sections`**: Links pages to sections with position
- **`rel_fields_pages`**: Page-level field defaults
- **`rel_sections_navigation`**: Navigation-specific section relationships
- **`rel_sections_hierarchy`**: Parent/child relationships between sections

### 4. Access Control Lists (ACL) Tables

#### `page_acl_groups` - Group-Level Page Permissions
```sql
CREATE TABLE `page_acl_groups` (
  `id_groups` int NOT NULL,
  `id_pages` int NOT NULL,
  `acl_select` tinyint(1) NOT NULL DEFAULT '1',
  `acl_insert` tinyint(1) NOT NULL DEFAULT '0',
  `acl_update` tinyint(1) NOT NULL DEFAULT '0',
  `acl_delete` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_groups`,`id_pages`),
  CONSTRAINT `fk_page_acl_groups_id_pages`  FOREIGN KEY (`id_pages`)  REFERENCES `pages` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_page_acl_groups_id_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

**ACL Permission Types:**
- **`acl_select`**: Read access (view page/content)
- **`acl_insert`**: Create access (add new content)
- **`acl_update`**: Update access (modify existing content)
- **`acl_delete`**: Delete access (remove content)

### 5. Multi-language Support Tables

#### `languages` - Supported Languages
```sql
CREATE TABLE `languages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `language` varchar(100) NOT NULL,
  `locale` varchar(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_5D237014D4DB71B5` (`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### `sections_fields_translation` - Field Content Translations
```sql
CREATE TABLE `sections_fields_translation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_fields` int NOT NULL,
  `id_languages` int NOT NULL,
  `content` longtext,
  `meta` longtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_field_language` (`id_fields`,`id_languages`),
  FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

### 6. Data Tables Translation System

#### Overview
The data tables translation system allows for multi-language support in dynamic data tables (`data_tables`, `data_rows`, `data_cols`, `data_cells`). This system enables storing and retrieving translated content for user-generated data in different languages.

#### Core Tables Structure

#### `data_cells` - Data Cell Values with Language Support
```sql
CREATE TABLE `data_cells` (
  `id_data_rows` int NOT NULL,
  `id_data_cols` int NOT NULL,
  `language_id` int NOT NULL DEFAULT 1,
  `value` longtext NOT NULL,
  PRIMARY KEY (`id_data_rows`,`id_data_cols`,`language_id`),
  KEY `idx_data_cells_id_data_rows` (`id_data_rows`),
  KEY `idx_data_cells_id_data_cols` (`id_data_cols`),
  KEY `idx_data_cells_language` (`language_id`),
  CONSTRAINT `fk_data_cells_id_data_cols` FOREIGN KEY (`id_data_cols`) REFERENCES `data_cols` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_cells_id_data_rows` FOREIGN KEY (`id_data_rows`) REFERENCES `data_rows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_cells_languages`    FOREIGN KEY (`language_id`)  REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### Translation Logic Rules

1. **Language ID 1 (Internal/Default)**: Represents the internal language, cannot be translated
2. **Language ID > 1 (Translatable)**: Can have multiple translations
3. **Translation Rule**: If a cell exists with `language_id = 1`, it cannot have translations
4. **Multi-translation Rule**: If a cell exists with `language_id > 1`, it can have multiple translations

#### Data Retrieval with `get_data_table_filtered`

The stored procedure `get_data_table_filtered` (renamed from `get_dataTable_with_filter` as part of the lowercase_snake_case normalization in the canonical baseline migration) supports language filtering and timezone conversion:

```sql
PROCEDURE `get_data_table_filtered`(
    IN table_id_param INT,
    IN user_id_param INT,
    IN filter_param VARCHAR(1000),
    IN exclude_deleted_param BOOLEAN,
    IN language_id_param INT, -- Language filtering
    IN timezone_code_param VARCHAR(100) -- Output timezone for entry_date
)
```

**Language Filtering Behavior:**
- `language_id_param = 1` or `NULL`: Returns only internal language data
- `language_id_param > 1`: Returns internal language (1) + requested language translations
- **Translation Fallback**: Internal language (1) is always included as fallback

#### Usage Examples

```sql
-- Get data in internal language only (default behavior)
CALL get_data_table_filtered(1, 0, '', FALSE, 1, 'UTC');

-- Get data with English translations (includes fallback to internal)
CALL get_data_table_filtered(1, 0, '', FALSE, 2, 'UTC');

-- Get data with German translations (includes fallback to internal)
CALL get_data_table_filtered(1, 0, '', FALSE, 3, 'Europe/Zurich');
```

#### Data Entry Rules

1. **Default Language**: New cells automatically get `language_id = 1`
2. **Adding Translations**: Insert new rows with same `id_data_rows`/`id_data_cols` but different `language_id > 1`
3. **Validation**: Cannot add `language_id > 1` if `language_id = 1` already exists for same cell
4. **Multiple Translations**: Can add multiple `language_id > 1` for same cell (multiple translations)

#### API Payload Format

The `DataService::saveData()` method now supports two payload formats for form data:

##### Simple Format (Backward Compatible)
```json
{
  "page_id": 89,
  "section_id": 229,
  "form_data": {
    "combo": null,
    "rich": null,
    "switch": "0",
    "text": null
  }
}
```
All fields are saved with default language ID 1 (internal language).

##### Multi-Language Format
```json
{
  "page_id": 89,
  "section_id": 229,
  "form_data": {
    "combo": null,
    "rich": null,
    "switch": "0",
    "text": [
      {
        "language_id": 2,
        "value": "german text"
      },
      {
        "language_id": 3,
        "value": "english text"
      },
      {
        "language_id": 4,
        "value": "french text"
      }
    ]
  }
}
```

**Multi-Language Field Rules:**
- Field value must be an array of objects
- Each object must have `language_id` and `value` properties
- Multiple translations can be saved for the same field
- Language ID 1 (internal) cannot have translations (enforced by application logic)

##### Data Retrieval

Use the `language_id` parameter in `getData()` method:

```php
// Get data in default language (1)
$data = $dataService->getData($tableId, '', true, null, false, true, 1);

// Get data with German translations (includes fallback to language 1)
$data = $dataService->getData($tableId, '', true, null, false, true, 3);
```

#### JSON Schema Validation

The API request schemas have been updated to support both simple and multi-language payloads:

**Updated Schemas:**
- `config/schemas/api/v1/requests/frontend/submit_form.json`
- `config/schemas/api/v1/requests/frontend/update_form.json`

**Supported Form Data Formats:**

1. **Simple Values** (Backward Compatible):
```json
{
  "page_id": 89,
  "section_id": 229,
  "form_data": {
    "combo": null,
    "rich": "<p>Simple content</p>",
    "switch": "0",
    "text": null
  }
}
```

2. **Multi-Language Arrays** (New Feature):
```json
{
  "page_id": 89,
  "section_id": 229,
  "form_data": {
    "combo": null,
    "rich": [
      {"language_id": 2, "value": "<p>German content</p>"},
      {"language_id": 3, "value": "<p>English content</p>"},
      {"language_id": 4, "value": "<p>French content</p>"}
    ],
    "switch": "0",
    "text": null
  }
}
```

**Schema Validation Rules:**
- Translation arrays must contain objects with `language_id` (integer ≥ 1) and `value` (string/number/boolean/null)
- Simple values (string, number, boolean, null) remain supported
- File input arrays and other array/object types still supported
- Mixed simple and translation fields allowed in same form

#### Application-Level Validation

The `FormValidationService` has been updated to handle translation arrays:

**Updated Validation Logic:**
- `validateFormData()` now recognizes translation arrays by checking for `language_id` in the first array element
- Translation arrays are validated using `isValidTranslationArray()` method
- Each translation object must have required `language_id` (integer ≥ 1) and `value` (scalar) properties
- String values in translations are limited to 65535 characters (TEXT field limit)

#### Migration Notes

- **Existing Data**: Automatically gets `language_id = 1` (no data loss)
- **Backward Compatibility**: Existing API calls work unchanged (default `language_id = 1`)
- **Performance**: New index on `language_id` for efficient language filtering
- **Data Integrity**: Foreign key constraint ensures valid language references

### 7. System Tables

#### `transactions` - Audit Trail
```sql
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_users` int DEFAULT NULL,
  `id_transaction_types` int DEFAULT NULL,
  `id_transaction_by` int DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `id_table_name` int DEFAULT NULL,
  `transaction_log` longtext,
  `transaction_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_transactions_id_users`             FOREIGN KEY (`id_users`)             REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_id_transaction_types` FOREIGN KEY (`id_transaction_types`) REFERENCES `lookups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_id_transaction_by`    FOREIGN KEY (`id_transaction_by`)    REFERENCES `lookups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### `scheduled_jobs` - Background Tasks
```sql
CREATE TABLE `scheduled_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(1000) DEFAULT NULL,
  `date_create` datetime NOT NULL,
  `date_to_be_executed` datetime DEFAULT NULL,
  `date_executed` datetime DEFAULT NULL,
  `config` varchar(1000) DEFAULT NULL,
  `id_job_status` int NOT NULL,
  `id_job_types` int NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_scheduled_jobs_id_job_status` FOREIGN KEY (`id_job_status`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scheduled_jobs_id_job_types`  FOREIGN KEY (`id_job_types`)  REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

> Note: schema versioning is handled by Doctrine Migrations
> (`doctrine_migration_versions` table). A legacy `version` table is no
> longer maintained.

#### `api_request_logs` - API Request Tracking
```sql
CREATE TABLE `api_request_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_users` int DEFAULT NULL,
  `method` varchar(10) NOT NULL,
  `url` varchar(1000) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `request_headers` json DEFAULT NULL,
  `request_body` longtext,
  `response_status` int DEFAULT NULL,
  `response_body` longtext,
  `execution_time` decimal(10,4) DEFAULT NULL,
  `memory_usage` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`id_users`),
  KEY `idx_method_url` (`method`, `url`(255)),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_response_status` (`response_status`),
  FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `field_types` - Content Field Types
```sql
CREATE TABLE `field_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `input_type` varchar(50) NOT NULL,
  `validation_rules` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Field types for CMS content management
INSERT INTO `field_types` (`name`, `description`, `input_type`) VALUES
('TEXT', 'Single line text input', 'text'),
('TEXTAREA', 'Multi-line text area', 'textarea'),
('HTML', 'Rich text HTML editor', 'html'),
('SELECT', 'Dropdown selection', 'select'),
('CHECKBOX', 'Checkbox input', 'checkbox'),
('RADIO', 'Radio button group', 'radio'),
('IMAGE', 'Image upload field', 'file'),
('FILE', 'File upload field', 'file'),
('DATE', 'Date picker', 'date'),
('NUMBER', 'Numeric input', 'number');
```

### 8. Lookup Tables System

#### `lookups` - Dynamic Lookup Values
```sql
CREATE TABLE `lookups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_code` varchar(100) NOT NULL,
  `code` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_type_code` (`type_code`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

### 🔍 The Lookup System - Critical Performance Component

The lookup system is a **centralized configuration management** approach that reduces database table proliferation while maintaining referential integrity. Instead of creating separate tables for every enumeration, the system uses a single `lookups` table with type codes.

#### Why Use Lookups?
1. **Reduced Table Count**: Instead of 20+ separate tables, one unified lookup table
2. **Dynamic Configuration**: Add new lookup values without schema changes
3. **Consistent Structure**: All lookup-type data follows the same pattern
4. **Performance Optimization**: Single table to maintain, index, and query
5. **Memory Efficiency**: Reduced database schema complexity

#### Lookup Structure
```sql
-- Single table handles all enumeration data
CREATE TABLE `lookups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_code` varchar(100) NOT NULL,  -- Groups related lookups
  `code` varchar(100) NOT NULL,       -- Unique identifier within type
  `description` varchar(255) DEFAULT NULL,  -- Human-readable description
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int DEFAULT NULL,      -- Display ordering
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_type_code` (`type_code`,`code`)  -- Prevents duplicates
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

#### LookupService Integration
The `LookupService` provides constants and methods to interact with lookup data efficiently:

```php
// Type constants - prevent typos and enable IDE autocomplete
public const TRANSACTION_TYPES = 'transactionTypes';
public const JOB_TYPES = 'jobTypes';
public const ASSET_TYPES = 'assetTypes';

// Code constants - specific values within types
public const TRANSACTION_TYPES_INSERT = 'insert';
public const JOB_TYPES_EMAIL = 'email';
public const ASSET_TYPES_IMAGE = 'image';

// Usage in services
$transactionType = $this->lookupService->findByTypeAndCode(
    LookupService::TRANSACTION_TYPES,
    LookupService::TRANSACTION_TYPES_INSERT
);
```

#### Performance Considerations
- **Caching**: Lookup data is cached in memory for frequent access
- **Indexing**: Composite index on `(type_code, code)` for fast lookups
- **Size Management**: Regular cleanup of inactive lookup values
- **Query Optimization**: Use constants instead of string literals

**Common Lookup Types:**
- `TRANSACTION_TYPES`: insert, update, delete, select, status_change
- `TRANSACTION_BY`: by_user, by_system, by_cron_job, by_anonymous_user
- `JOB_TYPES`: email, notification, task
- `JOB_STATUS`: queued, done, failed, deleted
- `ASSET_TYPES`: css, asset, static, image, document
- `PAGE_ACCESS_TYPES`: mobile, web, mobile_and_web
- `USER_STATUS`: invited, active, locked
- `NOTIFICATION_TYPES`: email, push_notification

## 🔄 Stored Procedures

### ACL Permission Check Procedure
```sql
DELIMITER //
CREATE PROCEDURE get_user_acl(IN userId INT, IN pageId INT)
BEGIN
    SELECT
        MAX(ag.acl_select) as acl_select,
        MAX(ag.acl_insert) as acl_insert,
        MAX(ag.acl_update) as acl_update,
        MAX(ag.acl_delete) as acl_delete
    FROM users u
    LEFT JOIN rel_groups_users ug ON u.id = ug.id_users
    LEFT JOIN page_acl_groups ag ON ug.id_groups = ag.id_groups AND ag.id_pages = pageId
    WHERE u.id = userId;
END //
DELIMITER ;
```

**Purpose**: Efficiently calculates user permissions for a specific page by combining user-specific and group-based ACL rules.

### Index Management Procedure
```sql
DELIMITER //
CREATE PROCEDURE add_index(
    param_table VARCHAR(100), 
    param_index_name VARCHAR(100), 
    param_index_column VARCHAR(1000),
    param_is_unique BOOLEAN
)
BEGIN	
    SET @sqlstmt = (SELECT IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS 
         WHERE `table_schema` = DATABASE()
         AND `table_name` = param_table
         AND `index_name` = param_index_name) > 0,
        "SELECT 'The index already exists in the table'",
        CONCAT(
            IF(param_is_unique, "CREATE UNIQUE INDEX ", "CREATE INDEX "),
            param_index_name, " ON ", param_table, " (", param_index_column, ")"
        )
    ));
    PREPARE stmt FROM @sqlstmt;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END //
DELIMITER ;
```

**Purpose**: Safely adds database indexes only if they don't already exist, used in migration scripts.

## 📊 Database Relationships & Constraints

### Foreign Key Relationships
```mermaid
graph TD
    A[users] --> B[rel_groups_users]
    C[groups] --> B
    C --> D[rel_permissions_roles]
    E[permissions] --> D
    E --> F[rel_api_routes_permissions]
    G[api_routes] --> F
    
    H[pages] --> I[rel_pages_sections]
    J[sections] --> I
    J --> K[sections_fields_translation]
    L[fields] --> K
    
    C --> N[page_acl_groups]
    H --> N
    
    A --> O[transactions]
    P[lookups] --> O
```

### Cascade Delete Rules
- **User deletion**: Cascades to `rel_groups_users`, sets NULL in `transactions`
- **Group deletion**: Cascades to `rel_groups_users`, `rel_permissions_roles`, `page_acl_groups`
- **Page deletion**: Cascades to `rel_pages_sections`, `page_acl_groups`
- **Section deletion**: Cascades to `sections_fields_translation`, child rows in `rel_sections_hierarchy`
- **API route deletion**: Cascades to `rel_api_routes_permissions`

## 🔍 Indexing Strategy

### Primary Indexes
- All tables have auto-incrementing primary keys
- Unique constraints on business keys (username, email, keyword, locale)

### Performance Indexes
```sql
-- User lookup optimization
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);

-- ACL performance
CREATE INDEX idx_page_acl_groups_id_groups ON page_acl_groups(id_groups);
CREATE INDEX idx_page_acl_groups_id_pages  ON page_acl_groups(id_pages);

-- CMS navigation
CREATE INDEX idx_pages_id_parent_page ON pages(id_parent_page);
CREATE INDEX idx_pages_nav_position   ON pages(nav_position);
CREATE INDEX idx_rel_sections_hierarchy_id_parent_section ON rel_sections_hierarchy(id_parent_section);
CREATE INDEX idx_sections_position    ON sections(position);

-- API routing
CREATE INDEX idx_api_routes_version ON api_routes(version);
CREATE INDEX idx_api_routes_path ON api_routes(path);

-- Transaction queries
CREATE INDEX idx_transactions_user ON transactions(id_users);
CREATE INDEX idx_transactions_table ON transactions(table_name, id_table_name);
CREATE INDEX idx_transactions_time ON transactions(transaction_time);
```

## 🔧 Entity-Database Mapping

### Doctrine Entity Rules
Based on the codebase analysis, entities must follow these patterns:

#### ✅ Correct Association Mapping
```php
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', onDelete: 'CASCADE')]
private ?User $user = null;

public function setUser(?User $user): static
{
    $this->user = $user;
    return $this;
}
```

#### ❌ Incorrect Primitive Mapping
```php
// Don't use primitive foreign keys
private ?int $idUsers = null;
public function setIdUsers(?int $idUsers): self { }
```

### Entity Synchronization
- All entities must sync with `db/structure_db.sql`
- Column names in entities match database column names
- Proper ORM attributes for relationships
- Generate complete getters and setters
- Add "ENTITY RULE" comment when designing

### DateTime Handling

#### UTC Storage Standard
- **All datetime values stored in UTC** in the database
- **Entity constructors initialize with UTC timezone** using `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))`
- **Database columns use `datetime_immutable` type** for consistency and immutability

#### Timezone Conversion
- **API responses convert UTC to CMS preference timezone** using `CmsPreferenceService->getDefaultTimezoneCode()`
- **Paginated data**: Timezone conversion in PHP loops to avoid SQL overhead
- **Non-paginated data**: Timezone conversion using SQL `CONVERT_TZ()` for better performance

#### Entity Examples
```php
#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
private \DateTimeImmutable $createdAt;

public function __construct()
{
    $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}
```

## 📈 Performance Considerations

### Query Optimization
- Use stored procedures for complex ACL calculations
- Implement proper indexing for frequent queries
- Use eager loading for related entities
- Cache lookup table values

### Connection Management
- Connection pooling for high concurrency
- Read replicas for reporting queries
- Transaction isolation for data consistency

### Storage Optimization
- JSON columns for flexible configuration data
- LONGTEXT for large content fields
- Proper charset (utf8mb4) for international content
- Engine selection (InnoDB for transactions)

## 🔒 Security Considerations

### Data Protection
- Password hashing with BCrypt
- Sensitive data encryption where needed
- Audit trail for all changes
- Secure token storage

### Access Control
- Multi-layer permission system
- Fine-grained ACL for pages
- Role-based access control
- Permission inheritance through groups

### Data Integrity
- Foreign key constraints
- Check constraints where applicable
- Transaction wrapping for complex operations
- Backup and recovery procedures

---

**Next**: [API Design Patterns](./05-api-patterns.md)