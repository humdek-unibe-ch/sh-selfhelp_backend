# SelfHelp Symfony Backend - Developer Documentation

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

Technical documentation for the SelfHelp Symfony backend: architecture, patterns, and conventions. This is the developer-area index; see [../README.md](../README.md) for the whole-repo docs map.

## Overviews

- [architecture-overview.md](architecture-overview.md) - End-to-end backend architecture reference.
- [development-guide.md](development-guide.md) - Core patterns and day-to-day development practices.
- [01-system-architecture.md](01-system-architecture.md) - High-level system design and components.

## Core architecture

- [02-dynamic-routing.md](02-dynamic-routing.md) - Database-driven API routes loaded by `ApiRouteLoader`.
- [27-db-driven-public-routing.md](27-db-driven-public-routing.md) - DB-driven public page URLs (`page_routes`), `{{route.*}}` params, page surfaces, the list/detail wizard, and page export/import.
- [28-navigation-pages-and-page-icons.md](28-navigation-pages-and-page-icons.md) - Navigation pages (pages-as-navigation), the configurable web/mobile navigation rendering model, auto-created public routes on page create, and the web/mobile page-icon fields.
- [29-navigation-menu-builder.md](29-navigation-menu-builder.md) - Menu builder architecture: menus, exclusions, presets, search, startup, last visited, and page tree vs menu tree.
- [03-authentication-authorization.md](03-authentication-authorization.md) - JWT authentication and the permission model.
- [04-database-design.md](04-database-design.md) - Schema and entity relationships.
- [api-security-architecture.md](api-security-architecture.md) - Request security pipeline and route permission enforcement.

## API development

- [05-api-patterns.md](05-api-patterns.md) - REST conventions and the response envelope.
- [06-json-schema-validation.md](06-json-schema-validation.md) - Request/response schema validation.
- [07-versioning-strategy.md](07-versioning-strategy.md) - API versioning approach.

## Content management system

- [08-cms-architecture.md](08-cms-architecture.md) - Page, section, and field model.
- [section.md](section.md) - Section internals and rendering.
- [section-export-import.md](section-export-import.md) - Section export/import format.
- [09-asset-management.md](09-asset-management.md) - File upload and asset handling.
- [10-interpolation-system.md](10-interpolation-system.md) - Variable interpolation and templating.
- [cms-translation.md](cms-translation.md) - Translation handling in the CMS.
- [18-page-versioning-publishing.md](18-page-versioning-publishing.md) - Page version, publish, and draft comparison.
- [20-cms-preferences-timezones.md](20-cms-preferences-timezones.md) - CMS preferences and timezone handling.
- [css-class-json.md](css-class-json.md) - CSS class catalog JSON.
- [style-schema-endpoint.md](style-schema-endpoint.md) - Style schema endpoint and the AI prompt catalog.

## System services

- [11-scheduled-jobs.md](11-scheduled-jobs.md) - Background task scheduling and execution.
- [12-transaction-logging.md](12-transaction-logging.md) - Audit trail and change tracking.
- [13-acl-system.md](13-acl-system.md) - Fine-grained ACL permission system.
- [19-data-access-management.md](19-data-access-management.md) - Role-based data access control with auditing.
- [permission-system-guide.md](permission-system-guide.md) - Permission model implementation guide.
- [17-global-cache-system.md](17-global-cache-system.md) - Redis-backed cache categories and invalidation.
- [25-instance-scoped-system-layer.md](25-instance-scoped-system-layer.md) - Instance-scoped maintenance/update layer and the SelfHelp Manager loop.

## Performance

- [performance-n1-queries.md](performance-n1-queries.md) - N+1 query optimizations across the codebase.

## Development guidelines

- [14-development-workflow.md](14-development-workflow.md) - Adding features and maintaining code.
- [15-testing-guidelines.md](15-testing-guidelines.md) - Testing strategy and commands.
- [testing-known-slow.md](testing-known-slow.md) - Known slow tests.
- [testing-troubleshooting.md](testing-troubleshooting.md) - Test environment troubleshooting.
- [16-deployment-process.md](16-deployment-process.md) - Version management and deployment.
- [21-seeding-system-pages.md](21-seeding-system-pages.md) - How `is_system` CMS pages are shipped and extended.
- [22-local-debugging-with-production-db.md](22-local-debugging-with-production-db.md) - Restoring a production DB copy locally and rebuilding plugin state.
- [23-ci-quality-gate.md](23-ci-quality-gate.md) - The `plugin-host-check` workflow.
- [24-core-phpstan-gate.md](24-core-phpstan-gate.md) - The strict, no-baseline `core-backend-check` PHPStan gate.
- [cross-repo-compatibility-matrix.md](cross-repo-compatibility-matrix.md) - How `@selfhelp/shared` semver anchors cross-repo compatibility.

## Key principles

1. Database-driven configuration: API routes and their permissions live in the database (`api_routes`, `rel_api_routes_permissions`), loaded at runtime by `ApiRouteLoader`.
2. Schema changes go through Doctrine migrations only. The baseline plus seed migrations are the canonical schema; `db/legacy/*.sql` is deprecated reference only.
3. Comprehensive validation: requests validate against JSON schemas; response-envelope validation is opt-in via `VALIDATE_RESPONSE_SCHEMA`.
4. Transaction integrity: multi-step create/update/delete operations are wrapped in transactions and logged through `TransactionService`.
5. Consistent responses: all endpoints return the `ApiResponseFormatter` envelope (`status`, `message`, `error`, `logged_in`, `meta`, `data`).

## Technology stack

- Framework: Symfony 7.4, PHP >= 8.4.
- ORM: Doctrine ORM 3 / DBAL 4 with Doctrine Migrations.
- Authentication: Lexik JWT Authentication.
- Database: MySQL 8; stored procedure `get_user_acl` for ACL checks; Redis for caching.

## Related references

- [../operations/platform-and-plugin-ecosystem.md](../operations/platform-and-plugin-ecosystem.md) - The big map: one registry, two installers (Manager-owned Docker core + CMS-owned plugins), and the install/update/maintain paths.
- [../reference/api/index.md](../reference/api/index.md) - Endpoint usage reference.
- [../reference/api-routes-table.md](../reference/api-routes-table.md) - `api_routes` table columns.
- [../../migrations](../../migrations) - Doctrine migrations (canonical schema).
- [../../config/schemas/api/v1](../../config/schemas/api/v1) - Request/response JSON schemas.
- [../plugins/architecture.md](../plugins/architecture.md) - Plugin ecosystem architecture.
