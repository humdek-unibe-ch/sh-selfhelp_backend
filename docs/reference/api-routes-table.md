# API Routes Table and Dynamic Route Loading

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-19.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

The application uses a database-driven routing system instead of traditional Symfony route configuration files. Routes are stored in the `api_routes` table and loaded during application bootstrap.

### How It Works:

1. A custom route loader (`App\Routing\ApiRouteLoader`) is registered as a service in Symfony's container.
2. During application initialization, Symfony calls this loader to fetch routes.
3. The loader queries the `api_routes` table and converts each record into a Symfony Route object.
4. These Route objects are added to Symfony's RouteCollection.
5. The application uses these routes for all API endpoints.

### Benefits:

- Routes can be modified without code deployment (just update the database)
- Route permissions can be managed dynamically through the `rel_api_routes_permissions` table
- API versioning is built into the routing system

### Database Table: `api_routes`

The `api_routes` table stores route definitions that are loaded dynamically into Symfony's routing system. Instead of defining routes in YAML or annotations, this application loads them from the database, allowing for more flexibility and runtime configuration.

#### Columns Explained

| Column        | Description                                                                                 | Example                                                      |
|--------------|---------------------------------------------------------------------------------------------|--------------------------------------------------------------|
| `id`         | Primary key                                                                                | `1`                                                          |
| `route_name` | Unique identifier for the route                                                            | `admin_create_page`                                          |
| `version`    | API version                                                                               | `v1`                                                         |
| `path`       | URL path pattern                                                                          | `/admin/page`                                                |
| `controller` | Controller class and method to handle the request                                         | `App\Controller\Api\V1\Admin\AdminPageController::createPage` |
| `methods`    | HTTP methods allowed                                                                       | `POST`, `GET`, `PUT`                                         |
| `requirements` | JSON object defining path parameter constraints                                          | `{ "page_keyword": "[A-Za-z0-9_-]+" }`                    |
| `params`     | JSON object defining expected request parameters                                           | See below                                                    |

---

#### Requirements Column
The `requirements` column defines constraints for path parameters. For example:

```json
{
  "page_keyword": "[A-Za-z0-9_-]+"
}
```

This means the `{page_keyword}` in a route like `/admin/pages/{page_keyword}/fields` must match the regular expression `[A-Za-z0-9_-]+`.

Equivalent Symfony YAML:
```yaml
requirements:
  page_keyword: '[A-Za-z0-9_-]+'
```

---

#### Params Column
The `params` column defines expected request parameters and their properties:

```json
{
  "keyword": { "in": "body", "required": true },
  "page_access_type_id": { "in": "body", "required": true }
}
```

This specifies that:
- The request should include a `keyword` parameter in the body, and it's required
- The request should include a `page_access_type_id` parameter in the body, and it's required

---

### Generated files

The route rows are the one home of the route rights (rule 1.5 of the workspace concepts). Two files are generated from them and committed:

- [access/api-routes.json](access/api-routes.json): every core route (`id_plugins IS NULL`) with its methods, path, `is_public` flag and permissions, plus every core permission with its description. The frontend's route contract test reads it.
- [permissions.md](permissions.md): the permission catalogue, one section per core permission with its routes and the admin screens that use it. The screens come from the frontend's generated `docs/reference/admin-screens.json`.

After a migration that changes routes or permissions, regenerate both with the test database migrated:

```bash
php bin/console app:access:export --env=test
```

The command reads the frontend file from `SELFHELP_FRONTEND_DIR`, else the sibling checkout `../sh-selfhelp_frontend` (`--frontend-dir=<path>` overrides both). `--check` writes nothing and exits 1 when a file is stale, when the screens file is missing, or when a core permission guards no route and opens no screen. CI runs the same checks in `tests/Integration/Access/AccessCatalogueFreshnessTest.php` (group `security`, workflow `backend-tests`).
