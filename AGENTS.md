# AGENTS.md

## Project Overview
This repository is the Symfony backend for the SelfHelp platform. It provides CMS/admin APIs, frontend page/content APIs, authentication, permissions, dynamic database-backed routing, asset handling, scheduled jobs, caching, and Mercure-based realtime notifications.

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

## Architecture Rules
- Inspect existing controllers, services, schemas, routes, SQL, and docs before changing behavior.
- Keep controllers small: validate input, call services, return `ApiResponseFormatter` responses.
- Put business logic in services with constructor dependency injection.
- Use Doctrine repositories, QueryBuilder, or DQL for application queries. Use raw SQL only when the existing code does so for migrations, setup scripts, stored procedures, or justified performance cases.
- Wrap multi-step create/update/delete operations in transactions.
- Log data-changing operations through `TransactionService` where existing services do so.
- Invalidate relevant caches after writes, especially page, section, user, role, permission, lookup, API route, and frontend caches.
- Preserve the recursive frontend page/section processing order unless intentionally changing frontend rendering behavior.
- Treat `docs/plugin_hooks.md` as a proposal until the actual proxy/hook implementation exists.

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
- Check `db/structure_db.sql`, existing entities, migrations, and update scripts before changing schema.
- Schema changes need a Doctrine migration class in `migrations`.
- Mirror required install/update SQL in the appropriate `db/update_scripts` or baseline SQL file when the existing workflow requires it.
- Existing editor rules say not to run Doctrine migrations automatically; create migration files and let the team run them.
- Store datetimes in UTC. Convert output times to the CMS preference timezone where the existing API does this.
- Be careful with legacy table naming and casing.

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
- Start Mercure locally: `docker compose -f docker-compose.mercure.yml up -d`.

## Common Tasks
- Add endpoint: add/update controller action, JSON schemas, `api_routes` SQL, permissions, service logic, tests, and route cache notes.
- Add service: place it under the matching `src/Service` domain, inject dependencies via constructor, keep transactions/cache invalidation explicit.
- Add migration: inspect schema first, create a Doctrine migration, update relevant SQL scripts if required, and do not run migrations automatically.
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
- Do not rely on the planned plugin proxy hook docs as implemented behavior.
- Do not hand-edit generated files such as `config/reference.php` unless the task specifically requires it.