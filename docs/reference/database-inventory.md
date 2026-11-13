# Database inventory

Audience: Backend developers and operators.
Status: active.
Applies to: SelfHelp2 backend schema, dev database `test`, read on 2026-11-12 (before the cleanup migration `Version20261112163406` of the same day; the two data migrations `Version20261112164930` and `Version20261112164932` of that day add no table, column or routine).
Last verified: 2026-11-12.
Source of truth: the database itself (`information_schema`), `src/Entity/`, `migrations/`, `src/Service/Core/LookupService.php`, `src/Plugin/Lookup/LookupPolicyRegistry.php`.

What the database holds, what reads and writes it, and what is left over from
SelfHelp 1 (SH1). One row per table, routine and lookup type. Use it before
adding a table (rule: use an existing concept first) and before dropping one.

How it was checked, on 2026-11-12:

- Tables and routines: `information_schema.tables`, `information_schema.routines`, `information_schema.triggers`, `information_schema.views`.
- Readers and writers: for every entity, a search of `src` for the class name; for tables used only through raw SQL or routines, a search for the table name in `src`, `config`, `templates`.
- Lookup types: `SELECT type_code, COUNT(*) FROM lookups GROUP BY type_code`; for every type its `LookupService` constant, its value constants, the foreign-key columns that hold its ids (`information_schema.key_column_usage`), and the web, mobile and shared sources.
- Statuses: `used`; `removed in this task` (dropped by the cleanup migration of 2026-11-12); `unused, proposed finding DB-n` (found while checking, **not** removed; see [Proposed findings](#proposed-findings)).

Counts on the dev database: **69 base tables** (63 entity tables, 4 join tables
declared only by `JoinTable`, `doctrine_migration_versions`, `messenger_messages`),
**5 stored procedures, 6 stored functions**, 0 triggers, 0 views, 0 events,
**37 lookup types** (210 rows). The task brief counted 68 tables; the dev database
holds 69.

## Tables

### Pages, sections, styles, content

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `pages` | CMS pages: keyword, URL, access type, surface, system flag | `Page` | `PageService`, `AdminPageService`, `PageRepository`, `ACLService`, `NavigationMenuService` | used |
| `page_types` | Page type catalogue | `PageType` | `PageService`, `AdminPageService`, `PageTypeRepository` | used |
| `page_routes` | Public parameterized route contract of a page | `PageRoute` | `PageRouteService`, `SectionAccessibleRouteService`, `PageRouteRepository` | used |
| `page_versions` | Saved and published snapshots of a page | `PageVersion` | `PageVersionService`, `AdminPageService`, `SectionExportImportService`, `PageVersionRepository` | used |
| `page_acl_groups` | Per-group create/read/update/delete rights on one page | `PageAclGroup` | `ACLService`, `AdminGroupService` | used |
| `page_search_index` | Searchable page text for the navigation search | `PageSearchIndex` | `NavigationSearchIndexService`, `PageSearchIndexRepository` | used |
| `pages_fields_translation` | Translated field content of pages | `PagesFieldsTranslation` | `PageFieldService`, `AdminPageService`, `PagesFieldsTranslationRepository`, `SectionExportImportService` | used |
| `rel_fields_page_types` | Which fields a page type has | `PageTypeField` | `PageFieldService`, `AdminPageService`, `FieldValidatorTrait` | used |
| `rel_fields_pages` | SH1 link of a field to one page (4 rows: `home` description and maintenance fields) | `PagesField` | none: no class reads or writes it | unused, proposed finding DB-1 |
| `rel_pages_sections` | Root sections of a page, with position | `PagesSection` | `AdminSectionService`, `PositionManagementService`, `SectionCreationService`, `NavigationMenuService` | used |
| `rel_sections_hierarchy` | Section parent/child tree, with position | `SectionsHierarchy` | `SectionRelationshipService`, `RelationshipManagerTrait`, `SectionCreationService`, `SectionExportImportService` | used |
| `rel_sections_navigation` | SH1 link of a section to a navigation section (0 rows) | `SectionsNavigation` | none | removed in this task |
| `sections` | Section instances: one style placed on a page | `Section` | `SectionUtilityService`, `PageService`, `AdminSectionService`, `CacheService` | used |
| `sections_fields_translation` | Translated field content of sections | `SectionsFieldsTranslation` | `SectionsFieldsTranslationRepository`, `TranslationManagerTrait`, `FormValidationService`, `PageExportImportService` | used |
| `styles` | Style catalogue: name, group, render target, children rule | `Style` | `StyleRepository`, `AdminStyleController`, style seed migrations | used |
| `style_groups` | Style categories (Form, Wrapper, ...) | `StyleGroup` | `StyleGroupRepository`, `StyleRepository` (join) | used |
| `rel_styles_allowed_relationships` | Which style may sit under which | `StylesAllowedRelationship` | `StylesAllowedRelationshipRepository`, `StyleRepository` | used |
| `fields` | Field definitions: name, type, translatable or not | `Field` | `FormValidationService`, `AdminActionService`, `PromptTemplateService`, `CacheService` | used |
| `field_types` | Field type catalogue (text, markdown, select, ...) | `FieldType` | joined in `StyleRepository` (raw SQL); foreign key from `fields` | used |
| `rel_fields_styles` | Which fields a style has, with default value and help | `StylesField` | `StylesFieldRepository`, `SectionFieldService`, `FieldValidatorTrait`, `StyleRepository` | used |
| `languages` | Content languages | `Language` | `LanguageRepository`, `ConditionService`, `CacheService`, translation repositories | used |
| `assets` | Uploaded file records | `Asset` | `AssetStorageService`, `AdminAssetService`, `AssetAccessService`, `AssetExportImportService` | used |
| `assets_folders` | Asset library folders (organisation only, no access) | `AssetFolder` | `AdminAssetService` | used |
| `rel_assets_groups` | Which groups may read an asset | `Asset::$groups` (join table) | `AssetRepository`, `AdminAssetService`, `AssetAccessService` | used |
| `cms_apps` | CMS-in-CMS application shells | `CmsApp` | `CmsAppService`, `CmsAppWizardService`, `CmsAppHubSyncService`, `PageExportImportService` | used |

### Navigation

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `navigation_menus` | Menus per platform and surface (header, footer, drawer, tabs) | `NavigationMenu` | `NavigationMenuService`, `NavigationAssignmentService`, `AdminNavigationService`, `NavigationMenuRepository` | used |
| `navigation_menu_items` | Items of a menu (page, link, group), with position | `NavigationMenuItem` | `NavigationMenuItemRepository`, `NavigationMenuService`, `AdminNavigationService`, `NavigationExportImportService` | used |
| `navigation_menu_item_translations` | Translated item labels | `NavigationMenuItemTranslation` | `NavigationMenuItemTranslationRepository`, `NavigationMenuItemTranslationSupport`, `NavigationExportImportService` | used |
| `navigation_settings` | One row of site-wide navigation settings (search, start mode, route sync) | `NavigationSettings` | `NavigationSettingsRepository`, `NavigationMenuService`, `AdminNavigationService`, `NavigationExportImportService` | used |
| `user_navigation_state` | Last page a user visited, per platform, for the start mode `last_visited_then_fixed_page`. Rows go with the user (`ON DELETE CASCADE`). | `UserNavigationState` | `UserNavigationStateService`, `UserNavigationStateRepository`, `AdminUserService` | used |

### Users, groups, roles, access

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `users` | User accounts | `User` | `LoginService`, `UserContextService`, `AdminUserService`, `ACLService`, `RegistrationService` | used |
| `groups` | User groups (what a user may see) | `Group` | `ACLService`, `AdminGroupService`, `DataAccessSecurityService`, `RegistrationService` | used |
| `roles` | Roles (what a user may edit) | `Role` | `AdminRoleService`, `AdminDataAccessService`, `AssetAccessService`, `UserContextAwareService` | used |
| `permissions` | Named permissions | `Permission` | `ApiRouteLoader`, `PluginApiRouteSynchronizer`, `CacheService`, `DataAccessSecurityService` | used |
| `rel_groups_users` | User membership in groups | `UsersGroup` | `AdminUserService`, `AdminGroupService`, `RegistrationService`, `DataAccessSecurityService` | used |
| `rel_roles_users` | User roles | `User::$roles` (join table) | `UserPermissionService` (raw SQL), `AdminUserService`, `CreateAdminUserCommand` | used |
| `rel_permissions_roles` | Permissions held by a role | `Permission::$roles` (join table) | `UserPermissionService` (raw SQL), `AdminRoleService`, `PluginCacheInvalidator` | used |
| `rel_api_routes_permissions` | Permissions a route requires | `ApiRoute::$permissions` (join table) | `ApiRouteRepository` (raw SQL), `AdminRoleService` (raw SQL), `PluginApiRouteSynchronizer` | used |
| `api_routes` | DB-backed API routes (see [api-routes-table.md](api-routes-table.md)) | `ApiRoute` | `ApiRouteLoader`, `ApiRouteRepository`, `PluginApiRouteSynchronizer` | used |
| `refresh_tokens` | Refresh tokens of sessions | `RefreshToken` | `JWTService`, `AdminUserService`, `AuthController` | used |
| `user_2fa_codes` | Two-factor login codes | `Users2faCode` | `AuthRepository` (through `User2faCodeRepository`), `AdminUserService` | used |
| `validation_codes` | Registration (validation) codes | `ValidationCode` | `RegistrationService`, `RegistrationCodeService`, `AdminUserService` | used |
| `validation_code_groups` | Groups a validation code enrols a user into | `ValidationCodeGroup` | `RegistrationService`, `RegistrationCodeService` (raw SQL) | used |
| `role_data_access` | Which role may read, edit or delete which data table or resource | `RoleDataAccess` | `RoleDataAccessRepository`, `AdminDataAccessService`, `AssetAccessService`, `DataTableAdminAccessListener` | used |
| `data_access_audits` | Audit log of data access decisions | `DataAccessAudit` | `DataAccessSecurityService`, `DataAccessAuditRepository`, `UserDataCleanerService` | used |
| `api_request_logs` | Log of API requests (admin diagnostics) | `ApiRequestLog` | `ApiRequestLoggerService`, `ApiRequestLogRepository`, `UserDataCleanerService` | used |

### User data (the generic data store), actions, scheduling

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `data_tables` | One table per form or data source | `DataTable` | `DataTableService`, `DataService`, `DataTableRepository`, `JobSchedulerService` | used |
| `data_cols` | Columns of a data table | `DataCol` | `DataColumnService`, `DataTableService` | used |
| `data_rows` | Rows (one submission or record) | `DataRow` | `DataService`, `DataTableService`, `UserDataCleanerService`, `JobSchedulerService` | used |
| `data_cells` | Cell values; read through the three `get_data_table_*` procedures | `DataCell` | `DataService`, `UserDataCleanerService`, the data procedures | used |
| `actions` | Actions fired by a trigger (mail, notification, task, job) | `Action` | `ActionResolverService`, `ActionSchedulerService`, `ActionRepository`, `AdminActionService` | used |
| `action_translations` | Translated action texts | `ActionTranslation` | `AdminActionTranslationService`, `ActionTranslationRepository` | used |
| `scheduled_jobs` | Deferred work (mail, notification, task) | `ScheduledJob` | `JobSchedulerService`, `ActionSchedulerService`, `TaskJobExecutorService`, `ActionCleanupService` | used |
| `scheduled_job_recipients` | Delivery targets of a job | `ScheduledJobRecipient` | `JobSchedulerService`, `AdminScheduledJobService`, `ScheduledJobRecipientRepository` | used |
| `scheduled_job_reminders` | Reminder-only data of a job | `ScheduledJobReminder` | `JobSchedulerService` | used |
| `scheduled_job_runner_runs` | History of the scheduled-job runner | `ScheduledJobRunnerRun` | `ScheduledJobRunnerService`, `ScheduledJobsExecuteDueCommand`, `ScheduledJobRunnerRunRepository` | used |
| `scheduled_job_runner_settings` | Operational settings of the runner | `ScheduledJobRunnerSetting` | `ScheduledJobRunnerService`, `ScheduledJobRunnerSettingRepository` | used |
| `transactions` | Audit trail of who changed what | `Transaction` | `TransactionService`, `DataService`, `AdminScheduledJobService`, `UserDataCleanerService` | used |

### Plugins, updates, analytics

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `plugins` | Installed plugin records | `Plugin` | `PluginAdminService`, `SystemVersionService`, `SystemUpdateService`, `MaintenanceModeService` | used |
| `plugin_sources` | Plugin registry sources | `PluginSource` | `PluginAdminService`, `RegistryClient`, `PluginSourceUrlResolver`, `PluginSourceRepository` | used |
| `plugin_operations` | One row per plugin lifecycle event | `PluginOperation` | `PluginAdminService`, `PluginCliFinalizer`, `UpdatePluginHandler`, `UninstallPluginHandler` | used |
| `plugin_feature_flags` | Feature flag toggles of a plugin | `PluginFeatureFlag` | `PluginAdminService`, `PluginFeatureFlagRepository` | used |
| `system_update_operations` | Audit trail of CMS-started SelfHelp updates | `SystemUpdateOperation` | `SystemUpdateService`, `SystemUpdateOperationRepository`, `SystemUpdateMercurePublisher` | used |
| `page_views` | Anonymous page views per day, page, platform and visitor hash (raw SQL, no ORM) | `PageView` | `PageViewTrackerService` (writes), `AdminAnalyticsService` (reads) | used |
| `page_view_referrers` | Views per day and external referrer host, for the admin "Referrers" card. The host comes only from header `X-Referrer-Host`; until 2026-11-12 no client sent it, so the table stayed empty (F126). | `PageViewReferrer` | `PageViewTrackerService` (writes), `AdminAnalyticsService` (reads) | used |

### Platform

| Table | Purpose | Entity | Main readers / writers | Status |
| --- | --- | --- | --- | --- |
| `lookups` | All enum-like values (see [Lookup types](#lookup-types)) | `Lookup` | `LookupService`, `LookupRepository`; 15 tables hold lookup ids through foreign keys | used |
| `doctrine_migration_versions` | Doctrine's list of executed migrations | none (Doctrine) | `doctrine:migrations:*` commands | used |
| `messenger_messages` | Symfony Messenger queue of the `plugin_ops` transport. Empty in dev and test (`sync://`). Hidden from Doctrine by `schema_filter` in `config/packages/doctrine.yaml`; counted by `SystemHealthService`. | none (Messenger) | `messenger:consume plugin_ops` (worker image), `SystemHealthService` | used |
| `libraries` | SH1 list of the libraries of the platform (23 rows, never read by SH2) | `Library` | none | removed in this task |

## Stored routines

Decision of 2026-11-12: the routines are kept and not touched; they are
reviewed for performance with F20 and F36 (many-million-row simulation). Defined
in `migrations/Version20260501000000.php`, replaced by `Version20260710093045.php`
and `Version20260710093048.php`. There are no triggers, views or events.

| Routine | Kind | Called by | Status |
| --- | --- | --- | --- |
| `get_data_table_filtered` | procedure | `DataTableRepository::getDataTableWithFilter`, `DataQueryPreviewService` | used, kept; performance review with F20/F36 |
| `get_data_table_for_user_groups` | procedure | `DataTableRepository::getDataTableWithUserGroupFilter` | used, kept; performance review with F20/F36 |
| `get_data_table_all_languages` | procedure | `DataTableRepository::getDataTableWithAllLanguages` | used, kept; performance review with F20/F36 |
| `get_user_acl` | procedure | `AclRepository::getUserAcl` | used, kept; performance review with F20/F36 |
| `get_page_sections_hierarchical` | procedure | `SectionRepository::fetchSectionsHierarchicalByPageId` | used, kept; performance review with F20/F36 |
| `build_dynamic_columns` | function | the data procedures only (no caller in `src`) | used, kept; performance review with F20/F36 |
| `build_dynamic_columns_with_selection` | function | the data procedures only | used, kept; performance review with F20/F36 |
| `build_exclude_deleted_filter` | function | the data procedures only | used, kept; performance review with F20/F36 |
| `build_language_filter` | function | the data procedures only | used, kept; performance review with F20/F36 |
| `build_time_period_filter` | function | the data procedures only | used, kept; performance review with F20/F36 |
| `convert_entry_date_timezone` | function | the data procedures only | used, kept; performance review with F20/F36 |

## Lookup types

37 type codes, 210 rows. "FK" means a column holds the lookup id. "Code reads"
means `src` uses the `LookupService` constants of the type or its values.
"Client" means the web frontend reads the type list. The mobile app and the
shared package read no lookup type by name.

| Type code | Rows | How it is used | Status |
| --- | --- | --- | --- |
| `actionScheduleJobs` | 4 | nothing: no constant, no foreign key, no client | unused, proposed finding DB-2 |
| `actionScheduleTypes` | 4 | code reads (`ACTION_SCHEDULE_TYPES_*`); client (action builder) | used |
| `actionTriggerTypes` | 4 | FK `actions.id_action_trigger_types`; code reads; client (actions page) | used |
| `assetTypes` | 3 | FK `assets.id_asset_types`; code reads | used |
| `auditActions` | 5 | FK `data_access_audits.id_audit_actions`; code reads | used |
| `dataColDisplayNameSource` | 2 | FK `data_cols.id_display_name_source`, `data_tables.id_display_name_source`; code reads | used |
| `hookTypes` | 2 | nothing: the SH1 `hooks` table is gone; only the constant and a policy entry remained | removed in this task |
| `jobTypes` | 4 | FK `scheduled_jobs.id_job_types`; code reads; client (job filters) | used |
| `navigationChildrenNavModes` | 3 | FK `navigation_menus.id_children_nav`, `navigation_menu_items.id_children_nav`; code reads | used |
| `navigationMenuItemTypes` | 3 | FK `navigation_menu_items.id_item_type`; code reads | used |
| `navigationMenuKeys` | 4 | FK `navigation_menus.id_navigation_menu_key`; code reads | used |
| `navigationMenuPresets` | 8 | FK `navigation_menus.id_preset`; code reads | used |
| `navigationMobileStartSources` | 2 | FK `navigation_settings.id_mobile_start_page_source`; code reads | used |
| `navigationPlatforms` | 2 | FK `navigation_menus.id_platform`, `user_navigation_state.id_platform`; code reads | used |
| `navigationRouteSyncPolicies` | 3 | FK `navigation_settings.id_route_sync_old_route_policy`; code reads | used |
| `navigationSearchFieldPolicies` | 2 | FK `navigation_settings.id_search_field_policy`; code reads | used |
| `navigationSearchModes` | 4 | FK `navigation_settings.id_web_header_search_mode`; code reads | used |
| `navigationSearchVisibility` | 1 | FK `navigation_settings.id_search_default_visibility`; code reads | used |
| `navigationSearchVisibilityOverrides` | 3 | constant only; the page column it was planned for was never created | unused, proposed finding DB-3 |
| `navigationStartModes` | 2 | FK `navigation_settings.id_web_user_start_mode`, `id_mobile_user_start_mode`; code reads | used |
| `navigationSurfaces` | 4 | FK `navigation_menus.id_surface`; read through the entity (`getSurface()->getLookupCode()`), constants not read | used |
| `notificationTypes` | 2 | code reads (`NOTIFICATION_TYPES_*`) | used |
| `pageAccessTypes` | 3 | FK `pages.id_page_access_types`; code reads; client (condition builder) | used |
| `pageSurface` | 2 | FK `pages.id_page_surface`; code reads; client | used |
| `permissionResults` | 2 | FK `data_access_audits.id_permission_results`; code reads | used |
| `resourceTypes` | 4 | FK `data_access_audits.id_resource_types`, `role_data_access.id_resource_types`; code reads; client (audit filters) | used |
| `scheduledJobDeliveryPolicies` | 2 | code reads (`SCHEDULED_JOB_DELIVERY_POLICY_*` in `JobSchedulerService`, `MailTemplateService`, `ActionSchedulerService`) | used |
| `scheduledJobsSearchDateTypes` | 3 | client only (job filters); the repository takes the column name as text | used |
| `scheduledJobsStatus` | 8 | FK `scheduled_jobs.id_job_status`; code reads; client (job filters) | used |
| `styleRenderTargets` | 3 | FK `styles.id_render_target`; read in SQL by `StyleRepository`, constants not read | used |
| `timePeriod` | 6 | code reads (`TIME_PERIOD_*`); client (action builder) | used |
| `timezones` | 75 | FK `users.id_timezones`; read through the user (`getTimezone()->getLookupCode()`); client (profile, field renderer) | used |
| `transactionBy` | 4 | FK `transactions.id_transaction_by`; code reads | used |
| `transactionTypes` | 14 | FK `transactions.id_transaction_types`; code reads | used |
| `userStatus` | 5 | FK `users.id_status`; code reads | used |
| `userTypes` | 1 | FK `users.id_user_types`; code reads | used |
| `weekdays` | 7 | client only (action builder weekday picker); the backend reads weekday names as text from the action config, `WEEKDAYS_*` constants are never read | used |

Constants and policy entries without rows (nothing to count above):
`LookupService::PLUGINS` (policy `PLUGIN_EXTENDABLE`: rows are added by installed
plugins, none locally; used) and `LookupService::STYLE_TYPE` (policy `CLOSED`, no
rows, read by nothing but the policy registry; see DB-4).

## Messenger and environment

| Item | Where | Status |
| --- | --- | --- |
| `MESSENGER_PLUGIN_OPS_DSN` | `config/packages/messenger.yaml`, `config/services.yaml` (health probe) | used: the single worker transport (`plugin_ops`); default `doctrine://default?queue_name=plugin_ops&auto_setup=true` |
| `messenger_messages` table | see Tables | used |
| `MESSENGER_TRANSPORT_DSN` | `.env` (symfony/messenger block), `.env.test`, comment in `config/services.yaml` | removed in this task: no config, code or test read it (`messenger.yaml` uses `MESSENGER_PLUGIN_OPS_DSN`) |
| 14 other `.env` keys | `.env` | unused, proposed finding DB-5 |

## Columns found unused

Only the columns met while doing this inventory (no column audit was done).

| Column | Where | Status |
| --- | --- | --- |
| `users.intern` | `User::$intern` with getter and setter; SH1 flag, no reader | removed in this task |

## Proposed findings

Found while building this inventory. None was removed: the user decision of
2026-11-12 limits the cleanup to the known-dead SH1 items. The master assigns the
finding numbers (`DB-n` here are temporary labels).

| Label | What | Evidence (check) | Suggested fix |
| --- | --- | --- | --- |
| DB-1 | Table `rel_fields_pages` and entity `PagesField` (SH1 `pages_fields`); 4 rows (`home`: `description`, `maintenance`, `maintenance_date`, `maintenance_time`) that no code reads. Which fields a page has comes from `rel_fields_page_types`. | `rg -n "PagesField\b\|rel_fields_pages" src config templates` finds only the entity | One migration drops the table, remove the entity and its mentions in `docs/developer/` |
| DB-2 | Lookup type `actionScheduleJobs` (4 rows: nothing, notification, reminder, task); SH1 leftover | `rg -n "actionScheduleJobs" src config templates ../sh-selfhelp_frontend/src ../sh-selfhelp_mobile` finds nothing; no foreign key points at it | Delete the 4 rows in a migration |
| DB-3 | Lookup type `navigationSearchVisibilityOverrides` (3 rows: inherit, visible, hidden) and `LookupService::NAVIGATION_SEARCH_VISIBILITY_OVERRIDES`; seeded for a page-level override column that was never created (`docs/archive/menus-refactor-plan.md`) | `rg -n "NAVIGATION_SEARCH_VISIBILITY_OVERRIDES\|navigationSearchVisibilityOverrides" src`; no foreign key points at it | Delete rows and constant, or create the column if page-level override is still wanted |
| DB-4 | `LookupService::STYLE_TYPE` and the policy entry for a type with no rows; the web mirror `src/constants/lookups.constants.ts` also lists `STYLE_TYPE`, `PLUGINS` and `PAGE_ACTIONS` (not a lookup type) | `rg -n "STYLE_TYPE\|PAGE_ACTIONS" src ../sh-selfhelp_frontend/src` | Remove the dead constants and the policy entry |
| DB-5 | 14 `.env` keys that nothing in code, config, docker files or tests reads: `API_PLATFORM_DEV_MODE`, `APP_LOCALE`, `APP_TIMEZONE`, `DOCTRINE_LOG_QUERIES`, `DOCTRINE_PROFILING`, `MAX_FILE_UPLOADS`, `SESSION_HANDLER_ID`, `SESSION_SAVE_PATH`, `SYMFONY_TRUSTED_HEADERS`, `SYMFONY_TRUSTED_HOSTS`, `TRUSTED_HOSTS`, `TRUSTED_PROXIES`, `UPLOAD_MAX_SIZE`, `WEB_PROFILER_ENABLED`. `docs/developer/03-authentication-authorization.md` tells operators to set `TRUSTED_PROXIES`, but no config reads it. | `rg -n --hidden -w "<KEY>" . --glob '!vendor' --glob '!var' --glob '!.env*'` per key | Remove the unread keys; wire `TRUSTED_PROXIES` into `framework.trusted_proxies` or correct the doc |

## Count check

Rows of this document against the dev database on 2026-11-12: 69 tables
(25 + 5 + 16 + 12 + 7 + 4 rows in the six table sections), 5 procedures + 6
functions, 37 lookup types. The names in each section were compared with
`information_schema.tables`, `information_schema.routines` and
`SELECT type_code FROM lookups GROUP BY type_code`; the lists are identical.
