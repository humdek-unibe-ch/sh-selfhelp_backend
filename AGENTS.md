# AGENTS.md

Before returning anything print in chat `❤️AGENTS.md` so that we know the rules are used

## Project Overview
This repository is the Symfony backend for the SelfHelp platform. It provides CMS/admin APIs, frontend page/content APIs, authentication, permissions, dynamic database-backed routing, asset handling, scheduled jobs, caching, and Mercure-based realtime notifications.

## Architecture Snapshot
- Symfony backend API.
- Database-driven API route system under `/cms-api`.
- CMS-driven dynamic frontend pages.
- Recursive page/section rendering through `PageService`.
- JWT authentication plus ACL, route permissions, and admin data-access permissions.
- Redis-backed `CacheService` with categories, list/item caching, and entity-scope invalidation.
- Mercure realtime updates for auth/ACL-related frontend refreshes.
- JSON Schema request/response validation.
- Service-oriented backend structure with thin controllers.

## Tech Stack
- PHP >=8.4, Symfony 7.4, Composer
- Doctrine ORM 3 / DBAL 4, Doctrine Migrations
- MySQL 8
- Redis via Symfony Cache and Predis
- Lexik JWT Authentication
- Mercure for realtime updates
- JSON Schema validation for API requests/responses
- PHPUnit 12, PHPStan level max
- No root `package.json`; JavaScript usage is limited to utility scripts

## Repository Structure
- `src/Controller/Api/V1`: versioned API controllers for admin, auth, frontend, and CSS.
- `src/Service`: main business logic. Keep controllers thin and place behavior here.
- `src/Entity`: Doctrine entities using PHP attributes.
- `src/Repository`: Doctrine repositories and custom queries.
- `src/EventListener`: exception formatting, API security, request logging, versioning, Mercure publishing.
- `src/Routing/ApiRouteLoader.php`: loads database-backed API routes under `/cms-api`.
- `config/schemas/api/v1`: JSON request/response schemas.
- `migrations`: Doctrine migration classes.
- `db`: baseline SQL, structure SQL, stored procedures, and update scripts.
- `docs/developer`: architecture, API, auth, database, CMS, workflow, and testing documentation.
- `tests`: PHPUnit controller, service, unit, and API tests.

## Source of Truth Priority
When code, docs, editor rules, or generated files disagree, use this priority order:
1. Actual runtime code behavior.
2. Active services, controllers, entities, repositories, listeners, and routing/config files.
3. Current `composer.json`, Symfony config, and environment-backed configuration.
4. Existing tests and fixtures.
5. This `AGENTS.md`.
6. README and other documentation files.

If documentation conflicts with implementation, flag the conflict instead of assuming the docs are correct.

## Implementation Principles
- State assumptions explicitly when they affect the change or verification.
- If multiple interpretations exist, note the relevant options briefly and choose the simplest safe path.
- Ask questions only when ambiguity materially changes implementation or creates hidden risk.
- Implement only what was requested. Avoid premature abstractions, single-use helpers, or extra configurability unless the task requires them.
- Prefer the simplest solution that preserves correctness and fits existing repository patterns.
- Change only what is necessary, match the surrounding style, and remove code only when your change makes it unused.
- Mention unrelated issues separately instead of fixing them opportunistically.

## Recommended Workflow For Changes
1. Inspect related controllers, services, schemas, entities, repositories, SQL/update scripts, routes, and tests first.
2. Search for existing patterns before introducing new structures.
3. Check whether the feature or fix already exists in another domain/service.
4. Prefer extending existing services over creating parallel systems.
5. Keep patches minimal and domain-focused.
6. Update JSON schemas, route permissions, SQL route records, migrations, tests, cache invalidation, and docs when applicable.
7. Run focused relevant tests/static analysis when the environment supports it; otherwise state what was not run.
8. Summarize architectural, API, cache, permission, migration, and testing impact in the final response.

## Multi-Repository Changes
When implementing features that affect multiple repositories:
- Read the `AGENTS.md` of every affected repository.
- Follow repository-specific rules even when they differ.
- Keep changes isolated to the repository being modified.
- Do not apply conventions from one repository to another unless explicitly documented.

## Architecture Rules
- Inspect existing controllers, services, schemas, routes, SQL, and docs before changing behavior.
- Keep controllers small: validate input, call services, return `ApiResponseFormatter` responses.
- Put business logic in services with constructor dependency injection.
- Use Doctrine repositories, QueryBuilder, or DQL for application queries. Use raw SQL only when the existing code does so for migrations, setup scripts, stored procedures, or justified performance cases.
- Wrap multi-step create/update/delete operations in transactions.
- Log data-changing operations through `TransactionService` where existing services do so.
- Invalidate relevant caches after writes, especially page, section, user, role, permission, lookup, API route, and frontend caches.
- Preserve the recursive frontend page/section processing order unless intentionally changing frontend rendering behavior.
- The plugin ecosystem is the manifest + Symfony events + tagged services system documented under `docs/plugins/` (`architecture.md`, `developer-guide.md`, `installation.md`, `surveyjs-plugin.md`). The legacy `docs/plugin_hooks.md` proxy/hook proposal is retired — do not extend it.
- Do not introduce new architectural patterns without checking whether the repository already has an established approach.

## Existing Patterns First
Before creating a new service, helper, trait, DTO, exception type, serializer, validator, query abstraction, event system, cache mechanism, or permission mechanism, inspect whether this repository already has an established implementation pattern.

Prefer consistency with surrounding code over a newer or cleaner pattern that is not already used here.

## Performance Rules
- Avoid N+1 Doctrine queries, especially in recursive page/section processing, route loading, permission checks, user/group/role operations, and entity-to-array conversion.
- Reuse existing `CacheService` categories, list/item APIs, and entity-scope invalidation before introducing new queries or cache mechanisms.
- Be careful with recursive section trees and frontend page loading; batch fetch where existing services already batch fetch.
- Check cache invalidation impact for every create/update/delete operation.
- When invalidating entity-scoped caches, also invalidate relevant list caches in the same category where current patterns do so.
- Clear permissions-related caches whenever roles, groups, users, ACLs, or data-access permissions change.

## Coding Style
- Follow PSR-4 autoloading: `App\` maps to `src/`.
- Use strict types where present and modern PHP features such as constructor property promotion and `readonly`.
- Prefer explicit service names and domain-specific subdirectories.
- Name admin services/controllers according to their domain, for example `AdminPageService`, `PageController`, `SectionRelationshipService`.
- Entities should usually model foreign keys as Doctrine associations, following existing entity patterns. Match legacy exceptions only when the existing table/entity requires it.
- Keep changes focused. Do not rewrite broad areas just to modernize style.
- PHP source files should keep the project SPDX/license header. Use the existing Composer header scripts.

## AI Agent Rules
- Read before changing. Check related docs, schemas, routes, services, tests, and SQL.
- Do not invent architecture. Follow the current codebase even when docs are stale.
- Do not introduce dependencies without a clear reason and a Composer lock update.
- Do not change public API routes, response shapes, permissions, or database schemas without calling out impact.
- Do not expose secrets from `.env`, JWT keys, database URLs, Mercure secrets, or credentials.
- Prefer small, reviewable patches.
- Update docs, schemas, SQL route records, migrations, and tests when the code change requires them.
- Do not run destructive git commands. Do not push unless explicitly requested.
- Do not rewrite working legacy patterns only for modernization or architectural purity.
- Large refactors require explicit approval.
- Establish clear verification criteria before changing code.
- For bug fixes: reproduce when possible, identify the minimal cause, implement the smallest reasonable fix, verify it, then stop.
- For features: define success criteria, implement minimally, verify behavior, then stop.

## AI Change Response Expectations
When making changes, explain:
- Why the change follows existing architecture.
- Which services, controllers, routes, schemas, SQL files, or migrations are impacted.
- Cache invalidation implications.
- Permission, auth, ACL, or data-access implications.
- Required tests, static analysis, migrations, docs, or follow-up work.
- Any relevant tests or checks that were not run.

## Security Rules
- API authentication uses JWT bearer tokens for `/cms-api/v1`.
- `JWTTokenAuthenticator` reads bearer tokens and attaches JWT payload data to the request.
- Route permissions are enforced by `ApiSecurityListener` using database route metadata and `UserPermissionService`.
- Frontend/page access uses ACL groups and page access rules.
- Admin data access uses role/group/user permission services and bit flags.
- CSRF is disabled because this is an API backend; do not assume browser form CSRF behavior.
- Validate request bodies with JSON schemas through `RequestValidatorTrait` and `JsonSchemaValidationService`.
- Avoid logging sensitive request data, tokens, passwords, secrets, or private keys.
- File uploads must keep server-side extension, size, and path validation.

## API Rules
- Public API paths are under `/cms-api/{version}`.
- Most API routes are database-backed through `api_routes` and loaded by `ApiRouteLoader`.
- When adding or changing an API route, update the controller, route database/update SQL, permissions, and schemas together.
- Responses should use `ApiResponseFormatter` and the standard envelope: `status`, `message`, `error`, `logged_in`, `meta`, and `data`.
- Request validation errors should flow through `RequestValidationException` and the API exception listener.
- Keep JSON schemas in `config/schemas/api/v1` aligned with controller behavior.
- Use appropriate HTTP status codes through service exceptions and formatter helpers.

## Database Rules

- The canonical schema lives in the Doctrine migrations under `migrations/`. The `Version20260501000000` baseline plus the four `Version20260501000100..000400` seed migrations are the **only** install source — fresh installs do not load `db/legacy/new_create_db.sql` or any other SQL dump.
- `db/legacy/` is deprecated reference / history (`new_create_db.sql`, `structure_db.sql`, `update_scripts/*.sql`). Do not treat it as authoritative; do not edit it for new features. See `db/legacy/README.md`.
- Symfony/Doctrine migration classes in `migrations` are the primary and only migration mechanism. Schema changes require a new Doctrine migration generated after the canonical baseline. Never modify baseline or seed migrations for new work.
- For new API routes, add the route row to `migrations/Version20260501000300.php` only if you are still iterating on the baseline; otherwise add a new follow-up migration that inserts into `api_routes` and `rel_api_routes_permissions`. Do not rely on `db/legacy/update_scripts/api_routes.sql` to populate fresh installs.
- Existing editor rules say not to run Doctrine migrations automatically; generate migration files and let the team execute them manually.
- Store datetimes in UTC. Convert output times to the CMS preference timezone where the existing API does this.
- Be careful with legacy table naming and casing.

### Doctrine Migration Generation (MANDATORY)

- Never manually create Doctrine migration filenames or classes.
- Never invent migration names such as:
  - `Version20260521150000`
  - `Version20260521150100`
  - or any other hand-written timestamp-based migration class.
- Always generate migrations using the repository's official Doctrine migration generation command (for example `php bin/console make:migration`).
- The generated filename and class name are the only allowed migration names.
- After generation, modify the migration contents if required, but do not rename the migration.
- Do not manually create migration files in the `migrations/` directory.
- Do not manually create timestamp-based migration class names.
- Do not guess future migration version numbers.
- If a migration is required, generate it first, then modify the generated migration.
- During code review or auditing, manually-created migration class names must be treated as a violation of repository rules.

### Database Naming Rules

- For all new database objects and all renamed database objects, use `lowercase_snake_case` only. Do not introduce new camelCase, PascalCase, or mixed-case table, column, index, or constraint names.
- Use plural `lowercase_snake_case` table names for normal entity tables, for example `users`, `page_versions`, `scheduled_jobs`, `role_data_access`.
- Use `id` as the primary key column name for normal entity tables. Do not use table-specific primary key names such as `id_users` as the main primary key of a base table.
- If a table references another table, name the foreign key column `id_<target_table_name>`, for example `id_users`, `id_groups`, `id_page_types`.
- Pure relation tables must be named `rel_<table_a>_<table_b>` using a fixed, predictable order rule. Use alphabetical order by final table name unless the repository already has an established exception that must be preserved for compatibility.
- Pure relation tables should normally contain only the two foreign keys plus optional relation metadata such as `created_at`, `position`, `sort_order`, or audit fields. Do not add a surrogate `id` column to a pure relation table unless the relation is intentionally being promoted to a first-class entity.
- Pure relation tables should use a composite primary key or at minimum a unique constraint across their foreign key columns so duplicate links cannot exist.
- If a relation table gains significant business meaning or lifecycle beyond linking two records, promote it to a normal entity table with its own domain name instead of keeping it as a generic relation table.
- Prefer explicit self-reference column names such as `id_parent_page` or `id_child_section` over ambiguous names like `parent` or `child` when introducing new schema.
- Name indexes and constraints consistently in `lowercase_snake_case`: use `pk_<table>`, `fk_<table>_<column>`, `idx_<table>_<column>`, and `uq_<table>_<column_or_columns>` where practical.
- Keep Doctrine mappings aligned with these naming rules. If legacy tables do not follow them yet, preserve compatibility intentionally and document the exception in the migration or related service comments.

## Migration Safety

- Avoid destructive migrations unless explicitly requested and reviewed.
- This project is currently pre-release, so backward compatibility with older internal structures is not required unless explicitly requested.
- Before the first official release, breaking schema/API/structure changes are allowed when they simplify the system or match the requested work, but they must still be deliberate, documented, and covered by the correct migrations/scripts.
- Once the project reaches an official release version (`1.0.0` or higher), backward compatibility becomes required for schema, API, and data changes unless the team approves a breaking change.
- Prefer additive schema changes only when compatibility, staged rollout, or data safety requires it.
- Check existing SQL update scripts, baseline SQL, Doctrine migrations, and route SQL before changing schema behavior.
- Do not edit an already-applied migration for a follow-up behavior change; add a new migration unless the team explicitly decides otherwise.
- For API route changes, add/modify rows via a new Doctrine migration that inserts into `api_routes` and `rel_api_routes_permissions`. Do **not** depend on `db/legacy/update_scripts/api_routes.sql` — that file is no longer wired into install/upgrade.

## Testing Rules
- Main command: `composer test` or `php bin/phpunit --testdox`.
- Run focused tests with `php bin/phpunit tests/path/to/Test.php`.
- Static analysis: `composer phpstan`.
- Database schema validation: `composer validate-db`.
- Some controller/API tests depend on a prepared test database and real login flow.
- Prefer endpoint/integration tests for API behavior. Unit tests with mocks are acceptable for isolated service logic where existing tests already use that pattern.
- Do not run the full DB-dependent suite casually if the environment is not prepared.

## Build / Dev Commands
- Install PHP dependencies: `composer install`.
- Start local PHP server: `composer dev`.
- Clear Symfony cache: `composer clear`.
- Run tests: `composer test`.
- Run PHPStan: `composer phpstan`.
- Validate schema mapping: `composer validate-db`.
- Show pending schema SQL: `composer update-db`.
- Check headers: `composer headers:check`.
- Add headers: `composer headers:add`.
- Clear API route cache: `php bin/console cache:clear-api-routes`.
- Run due scheduled jobs: `php bin/console app:scheduled-jobs:execute-due --limit=50`.
- Generate CSS class asset if needed: `node scripts/generate-css-classes.js`.
- Start the local docker stack (Mercure + Mailpit + Redis): `docker compose up -d`.

## Common Tasks
- Add endpoint: add/update controller action, JSON schemas, a new Doctrine migration that inserts the row into `api_routes` and the matching links into `rel_api_routes_permissions`, permissions, service logic, tests, and route cache notes.
- Add service: place it under the matching `src/Service` domain, inject dependencies via constructor, keep transactions/cache invalidation explicit.
- Add migration: inspect schema first, create the migration with the Symfony/Doctrine generate command so the timestamp-based file/class name is automatic, update relevant SQL scripts if required, and do not run migrations automatically.
- Add frontend page behavior: inspect `PageService`, section/field processing, interpolation, conditions, ACL, and cache effects.
- Add permission-sensitive feature: update route permissions and verify `ApiSecurityListener`, `UserPermissionService`, ACL, and data-access rules.
- Update tests: prefer focused PHPUnit tests and keep fixtures/test database assumptions explicit.

## Do Not Do
- Do not treat stale README/editor-rule versions as authoritative over `composer.json`.
- Do not bypass `ApiResponseFormatter` for API responses.
- Do not add static API routes for normal CMS API endpoints without checking the database route system.
- Do not skip cache invalidation after data writes.
- Do not store local-time datetimes in the database.
- Do not expose or copy secrets into docs, tests, logs, or examples.
- Do not rely on the retired `docs/plugin_hooks.md` proxy/hook proposal — the live extension surface is documented under `docs/plugins/`.
- Do not hand-edit generated files such as `config/reference.php` unless the task specifically requires it.
- Do not create parallel abstractions when an existing service, trait, validator, cache category, or permission pattern already fits.
- Do not modernize broad areas opportunistically; keep legacy-compatible patterns unless a refactor is explicitly requested.
- Do not introduce new camelCase or mixed-case database object names to distinguish relation tables from normal tables; use the explicit relation-table naming rule instead.

## Plugin Ecosystem Rules

This repository hosts the backend half of the SelfHelp plugin ecosystem. Plugins extend the CMS through **documented extension points only**. The full architecture lives in `docs/plugins/`; this section captures the rules that must be followed from inside this repository.

### Multi-Repository AGENTS.md Rule

This project is multi-repository. The AI agent must always obey the `AGENTS.md` of the repository whose files it is editing, regardless of where the agent was started.

For plugin-related tasks, first identify the affected repositories by role:

- Symfony backend repo: `sh-selfhelp_backend`
- Next.js frontend repo: `sh-selfhelp_frontend`
- Shared package repo: `sh-selfhelp_shared`
- Expo mobile app repo: `sh-selfhelp_mobile`
- Old CMS reference repo: `sh-selfhelp` — read-only
- Plugin repositories: repos under a local `plugins/` directory, for example `sh2-shp-survey-js`

Repository locations are environment-specific. Do not assume absolute paths.

When working locally, discover repositories from the current workspace, sibling directories, or explicit user-provided paths.

Required-before-coding checklist for multi-repo work:

- [ ] Identify all repositories affected by role.
- [ ] Locate each repository in the current environment.
- [ ] Read `AGENTS.md` in each affected repository.
- [ ] Summarize relevant rules per repository.
- [ ] Confirm planned file changes per repository.
- [ ] Apply changes repo-by-repo.
- [ ] Run validation commands from the matching repository.
- [ ] Do not mix backend, frontend, shared, mobile, and plugin rules.

Canonical document: `docs/plugins/multi-repo-agents-md.md`. Plugin repo `AGENTS.md` template: `docs/plugins/plugin-repo-agents-md-template.md`.

### Extension points only

Plugins may only extend SelfHelp through these documented extension points:

- Symfony events (the events listed in `docs/plugins/developer-guide.md`).
- Tagged services (`selfhelp.plugin.scheduled_job_type`, `selfhelp.plugin.realtime_topic`, `selfhelp.plugin.backup_hook`, etc.).
- Manifest-declared API routes (under `/cms-api/v1/plugins/{pluginId}/...`).
- Style registry entries.
- Admin pages (mounted under `/admin/plugins-host/{pluginId}/...` on the frontend).
- Permissions declared in the manifest.
- Scheduled jobs declared via tagged services.
- Declared assets (vendored locally — CDNs require explicit `security.externalHosts`).
- Lookup entries via the lookup registry policy.
- Realtime Mercure topics via `PluginRealtimePublisher`.

Plugins must not directly patch core services, modify core routes at runtime, write config files outside the installer, access DB tables they do not own unless declared in the manifest, poll any core endpoint, or touch protected core tables (`users`, `roles`, `permissions`, `groups`, `users_groups`, auth/session/token tables, `plugins`, `plugin_operations`, `plugin_sources`, `data_tables`/`data_rows`/`data_cells` not owned by the plugin).

### Honest event catalog

The new backend currently dispatches zero custom domain events. The plugin layer adds ONLY events that correspond to extension surfaces that exist today:

- `ApiRouteRegistryEvent` (extends `App\Routing\ApiRouteLoader`).
- `StyleRegistryEvent` (extends `AdminStyleController::getStylesSchema`).
- `LookupRegistryEvent` (extends `App\Service\Core\LookupService`).
- `ScheduledJobTypeEvent` (extends the scheduled-job service).
- `PluginRealtimeTopicRegistryEvent` + `PluginRealtimePermissionEvent` (plugin-layer infrastructure).
- Lifecycle: `PluginInstalledEvent` / `PluginEnabledEvent` / `PluginDisabledEvent` / `PluginUninstalledEvent` / `PluginPurgedEvent` / `PluginUpdatedEvent` / `PluginOperationProgressEvent`.

Old PHP-CMS hooks (`SecurityHeadersEvent`, `SensiblePagesEvent`, `CustomFieldRenderEvent`, `RequestPreHandleEvent`, `NavMenuRenderEvent`, `ProfilePageRenderEvent`, `AdminUserDetailRenderEvent`, `UserActivationEvent`, `UserDataCleanupEvent`, `SectionDebugDataEvent`, `InterpolationContextEvent`, `SectionFieldResolveEvent`, `JobConfigSchemaEvent`, `PluginCallbackEvent`, etc.) are deferred. They will be added only when the matching core feature is touched. Do not preemptively add events the CMS does not dispatch yet.

### Plugin version semantics

Codify in every plugin's `plugin.json` and respect when reviewing plugin migrations:

- **patch** (`1.0.0 → 1.0.1`) — code change only. No DB change, no migration.
- **minor** (`1.0.x → 1.1.0`) — always carries a DB change. Always ships a migration.
- **major** (`1.x → 2.0`) — breaking change. Requires `pluginApiVersion` and `compatibility.selfhelp.*` updates.

### Install modes, lock file, generated bundles file

- Default production install mode is `managed`. The admin UI orchestrates, but a CLI/CI worker performs the real install. `SELFHELP_PLUGIN_INSTALL_MODE=development|managed|trusted`. Direct-install via the web UI requires `SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true` and `APP_ENV=dev`.
- Lock file: `selfhelp.plugins.lock.json` at the project root. Single source of truth for installed plugin versions, checksums, signatures, migration hashes, capabilities, owned styles/topics/lookups. Written atomically by the install command.
- Generated bundles file: `config/selfhelp_plugin_bundles.php`. `config/bundles.php` includes it once; it is regenerated atomically by the install/uninstall commands. NEVER edited at runtime from DB.
- Emergency safe mode: `SELFHELP_DISABLE_PLUGINS=true` or `php bin/console selfhelp:plugin:safe-mode --enable` short-circuits `config/bundles.php` and boots with core bundles only.

### plugin_operations table is the audit trail

Every install/update/disable/enable/uninstall/purge/rollback/repair writes a row to `plugin_operations` with `snapshots_json`, `rollback_plan_json`, `logs_json`, status enum. Only one operation may run at a time per CMS instance (Symfony Lock backed by Redis at key `plugin_op:global` + per-plugin key `plugin_op:<pluginId>`).

### Trust levels and deny-by-default capabilities

`security.trustLevel` in the manifest is one of `official`, `reviewed`, `untrusted`. Untrusted plugins cannot ship a Symfony bundle, migrations, event subscribers, scheduled jobs, or direct DB access.

`security.capabilities` is deny-by-default: every privileged capability (`backendBundle`, `databaseMigrations`, `readUsers`, `writeUsers`, `deleteUsers`, `readDataTables`, `writeDataTables`, `deleteDataTables`, `externalNetworkAccess`, `scheduledJobs`, `publicCallbacks`, `adminPages`, `frontendStyles`, `mobileStyles`, `realtimePublish`, `fileUploads`, `secretAccess`) must be explicitly declared. The installer's `PluginCapabilityValidator` refuses operations whose capabilities are not granted by the trust level.

Protected core tables are off-limits to plugins even via Doctrine — a runtime listener (`PluginDataAccessGuard`) blocks queries that violate `dataAccess.read`/`dataAccess.write`.

Plugin migration safety: file SHA-256 hashes are recorded in the lock file; `DROP`/`TRUNCATE`/`DELETE` on protected tables is blocked by default at the migration-runner level (`PluginMigrationGuard`); destructive migrations require `--allow-destructive`; `safeDown=true` must be reviewed, never blindly trusted.

DB user separation is the recommended deployment pattern: web runtime DB user with no `DROP`/`ALTER`/`CREATE TABLE`; separate privileged migration DB user available only to CLI/CI.

### Realtime, no polling

Plugin progress (install operations, dashboards, chat, collaborative editing, file uploads, LLM runs, notifications, form validation) flows over Mercure through `App\Plugin\Realtime\PluginRealtimePublisherInterface`. Plugins never talk to Mercure directly.

Polling is allowed ONLY for:

- Initial bootstrap (one-shot manifest fetch + lookup fetch).
- Offline fallback (mobile when SSE is unavailable).
- Emergency compatibility mode (when Mercure is intentionally disabled per-instance).

The CI workflow (`.github/workflows/plugin-host-check.yml`) flags new polling code paths.

### Lookup registry policy

All enum-like values used by CMS, plugins, admin UI, API schemas, and mobile app must be represented in the central `lookups` table unless they are purely internal PHP implementation constants. The backend may derive PHP globals/constants from the lookup table where needed.

Lookup groups have three extension policies:

- `closed` — core-owned; plugins may read but not extend.
- `plugin_extendable` — core-owned; plugins may add entries through `plugin.json` lookups block or `LookupRegistryEvent`. Added rows are tagged with `id_plugins`.
- `plugin_owned` — fully owned by one plugin and tagged with `id_plugins`.

Plugins must not directly insert/update/delete lookup rows at runtime. Lookup changes happen through plugin install/update migrations or the plugin manager. Every plugin-owned lookup row must carry `id_plugins`.

### Plugin file paths (backend)

- `src/Plugin/` — plugin layer namespace. Subnamespaces: `Bundle/`, `Manifest/`, `Registry/`, `Lifecycle/`, `Discovery/`, `Event/`, `Realtime/`, `Security/`, `Health/`, `FeatureFlag/`, `Lookup/`, `Migration/`.
- `src/Entity/Plugin.php`, `PluginOperation.php`, `PluginSource.php`, `PluginFeatureFlag.php`.
- `src/Controller/Api/V1/Admin/AdminPlugin*Controller.php`.
- `src/Controller/Api/V1/Frontend/PluginManifestController.php`.
- `src/Command/Plugin/*Command.php`.
- `config/selfhelp_plugin_bundles.php` — GENERATED. Never edited by hand.
- `selfhelp.plugins.lock.json` at the project root — GENERATED. Never edited by hand.
- `config/schemas/api/v1/entities/plugin*.json`.
- `docs/plugins/*.md` and `docs/plugins/*.schema.json`.

### Plugin API route convention

Plugin public and admin APIs are versioned under `/cms-api/v1/plugins/{pluginId}/...` (public) and `/cms-api/v1/admin/plugins/{pluginId}/...` (admin). Breaking API changes require either a new route version (`/v2/plugins/{pluginId}/...`) or a major plugin version. Routes contributed by plugins should be added through `ApiRouteRegistryEvent` (not direct DB inserts), so they live in `plugin_operations.snapshots_json` and disappear cleanly on uninstall.

### Plugin lifecycle words

- `disable` — hides plugin, keeps data. Reversible.
- `uninstall` — removes packages, keeps data. Reversible by reinstalling.
- `purge` — destructive. Requires `--confirm` on CLI and "type the plugin id" in the UI plus a backup warning. Only deletes plugin-owned tables, rows tagged with `id_plugins`, plugin-created `data_tables`/`data_rows`/`data_cells`, plugin-created permissions/routes/styles/lookups.

### GDPR & backup hooks

Plugins implement `PluginDataExportInterface` (right of access), `PluginDataCleanupInterface` (right to be forgotten), and `PluginDataRetentionInterface` (retention policy). The admin Plugin detail page exposes these declarations.

Before update/purge, the UI shows a backup recommendation banner and the CLI supports `--backup-before`, which invokes the configured `PluginBackupHookInterface` (default = print suggested `mysqldump` command and warn).

### Plugin signing / checksums

The public registry exposes per-version SHA-256 checksums and Ed25519 signatures. The installer's `PluginSignatureVerifier` checks both before unpacking. Private plugins must pin a specific commit SHA + version (`source.gitSha` in `plugin_sources`) so the same content is installed everywhere.

### Feature flags

Plugins may declare sub-features in `plugin.json` under `featureFlags`. Toggled at runtime via `plugin_feature_flags` (composite key on `id_plugins`, `flag_key`, `scope`, `scope_value`). Flag changes publish a Mercure event so admin sessions refresh immediately.
