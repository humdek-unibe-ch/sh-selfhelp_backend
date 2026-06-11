# SelfHelp Backend API Reference

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Controllers in `src/Controller/Api/V1`, JSON schemas in `config/schemas/api/v1`, and the `api_routes` rows seeded by the migrations.

This is the endpoint-by-endpoint usage reference for the public and admin API. For the architectural patterns behind these endpoints, see [../../developer/05-api-patterns.md](../../developer/05-api-patterns.md).

## API basics

### Base URL

```
https://<your-domain>/cms-api/{version}/
```

Versioning is URL-based; `v1` is the current version.

### Response envelope

Every response is built by `ApiResponseFormatter` and uses this envelope:

```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-06-03T10:30:00+00:00"
  },
  "data": null
}
```

Error responses set `error` to a message and add a `validation` array when field
validation fails. Response-schema validation is opt-in through the
`VALIDATE_RESPONSE_SCHEMA` environment variable (off in production); see
[../../developer/05-api-patterns.md](../../developer/05-api-patterns.md).

### Authentication

Most endpoints require a JWT bearer token:

```
Authorization: Bearer <jwt-token>
```

See [01-authentication.md](01-authentication.md) for the login, refresh, 2FA, and logout flows.

## Endpoint guides

### Authentication and user management

- [01-authentication.md](01-authentication.md) - Login, logout, token refresh, 2FA.
- [02-user-profile.md](02-user-profile.md) - Profile, name, password, timezone, account deletion.
- [03-user-validation.md](03-user-validation.md) - Email validation and account activation.
- [user-management-api.md](user-management-api.md) - Consolidated user-management endpoint reference.

### Admin - content management

- [04-admin-pages-sections.md](04-admin-pages-sections.md) - Page and section CRUD.
- [05-admin-languages.md](05-admin-languages.md) - Language management.
- [06-admin-assets.md](06-admin-assets.md) - File upload and asset management.
- [18-admin-data-tables.md](18-admin-data-tables.md) - Read, delete, and export form-submission data tables.

### Admin - users and access

- [07-admin-users.md](07-admin-users.md) - User CRUD.
- [19-admin-registration-codes.md](19-admin-registration-codes.md) - Single-use self-registration codes.

### Admin - system management

- [11-admin-cache.md](11-admin-cache.md) - Cache operations and monitoring.
- [12-admin-audit.md](12-admin-audit.md) - Security audit logging.
- [13-admin-scheduled-jobs.md](13-admin-scheduled-jobs.md) - Background job management.
- [14-admin-actions.md](14-admin-actions.md) - Automated action system.
- [20-admin-system-maintenance.md](20-admin-system-maintenance.md) - Instance-scoped version, update preflight, request, and status (SelfHelp Manager flow).

### Public and forms

- [15-frontend-pages.md](15-frontend-pages.md) - Public page content access.
- [17-form-submissions.md](17-form-submissions.md) - Form input submission and storage.

## JSON schemas

Requests and responses validate against schemas under `config/schemas/api/v1/`:

- Request schemas: `config/schemas/api/v1/requests/`
- Response schemas: `config/schemas/api/v1/responses/`

Each endpoint guide links the exact schema files for its operations.

## Route and permission discovery

Routes are database-backed (`api_routes`) and loaded by `ApiRouteLoader`. The
admin API exposes the route catalog with required permissions; see
[../api-routes-table.md](../api-routes-table.md) for the `api_routes` columns and
[../../developer/02-dynamic-routing.md](../../developer/02-dynamic-routing.md) for
how routes are loaded.
