# Legacy SQL Bootstrap Files (DEPRECATED)

> **DO NOT USE FOR FRESH INSTALLS.** These files are kept here for historical
> reference only. They are not authoritative and are not consumed by either the
> runtime application or the install/upgrade flow.

## Why this folder exists

Up until the `db_naming_cutover_migrations_*` plan landed, fresh installs of
the SelfHelp backend depended on a two-step bootstrap:

1. Load `db/new_create_db.sql` (mixed-case schema + seed data dump).
2. Then run `php bin/console doctrine:migrations:migrate` to apply incremental
   schema changes.

That dual-bootstrap contract is **gone**. A brand-new database now boots the
backend by running Doctrine migrations only — the canonical baseline
(`migrations/Version20260601000000.php`) plus the four `Version20260601000100`
… `Version20260601000400` seed migrations create the full schema, all stored
procedures, and every seed row from scratch under the canonical
`lowercase_snake_case` naming conventions.

## How the new install works

```sh
# 1) Create an empty database
mysql -u root -p -e "CREATE DATABASE selfhelp CHARACTER SET utf8mb4"

# 2) Configure DATABASE_URL in .env / .env.local, then:
php bin/console doctrine:migrations:migrate --no-interaction

# 3) (optional) Validate the schema is in sync with the entity mappings
php bin/console doctrine:schema:validate

# 4) (optional) Warm the dynamic api_routes cache
php bin/console cache:clear-api-routes
```

No file in this `db/legacy/` directory is read by that flow.

## What lives here

* `new_create_db.sql` — the legacy schema + seed-data dump that used to be the
  install entry point. Tables are mixed-case (`dataTables`, `scheduledJobs`,
  `pageType`, …) and seed `INSERT` rows are positional. The
  `migrations/LegacySeedTrait.php` helper reads this file to extract seed rows
  during the four seed migrations, applying a rename map to project legacy
  table/column names onto canonical names.
* `structure_db.sql` — historical schema-only dump matching the legacy
  install. Useful for diffing the old vs. canonical schema; not consumed by
  the install flow.
* `update_scripts/` — every incremental SQL script that was added on top of
  `new_create_db.sql` between releases (including `api_routes.sql` and the
  `39_update_v7.6.0_v8.0.0.sql` family). All of these patches are folded into
  the canonical baseline + seed migrations; they are kept here purely as a
  changelog of what shipped under the legacy bootstrap.

## Canonical naming rules (for the new schema)

* Tables: plural `lowercase_snake_case`, e.g. `pages`, `scheduled_jobs`.
* Primary keys: `id`.
* Foreign keys: `id_<target_table_name>`, e.g. `id_users`, `id_page_types`.
* Self-references: explicit, e.g. `id_parent_page`, `id_child_section`.
* Pure relation tables: `rel_<a>_<b>` in alphabetical order, e.g.
  `rel_groups_users`, `rel_fields_styles`.
* Join tables with business columns are promoted to first-class entities,
  e.g. `page_acl_groups` (was `acl_groups`), `validation_code_groups` (was
  `codes_groups`).
* Indexes / constraints: `pk_*`, `fk_*`, `idx_*`, `uq_*` in
  `lowercase_snake_case`.

The new seed migrations rely on `LegacySeedTrait::tableRenames()` /
`columnRenames()` / `columnOverrides()` to translate the legacy dump into
canonical INSERT statements; if you change either side, update those maps
accordingly.

## What to do if you used to edit these files

* New API routes — add them to `db/legacy/update_scripts/api_routes.sql`
  **and** to the corresponding `Version20260601000300_SeedApiRoutes.php`
  migration (or, going forward, a new migration after the baseline). The
  legacy file is no longer enough on its own.
* New tables / columns — create a new Doctrine migration after the
  canonical baseline. Do not edit the baseline migration; pre-release breaking
  changes that need to live in the install go in a follow-up migration.
* Schema dumps for human review — re-run `php bin/console doctrine:schema:create --dump-sql`
  against an empty database after migrations and diff that, not the legacy
  files.

## Removal plan

These files will be deleted once the canonical baseline has been verified on
real installs across the team and the seed migrations no longer need to read
`new_create_db.sql` as a transitional source. Until then, treat them as
read-only reference.
