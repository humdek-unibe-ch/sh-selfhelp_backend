# GDPR & Data Ownership

The plugin system is built so that **every byte of personal data is
attributable to a single plugin** (or to the host). That guarantee
makes the GDPR endpoints — export ("right to access"), erasure
("right to be forgotten"), and audit — trivial to implement.

## The ownership rule

Every row in every table is either:

1. **Host-owned** — `id_plugins IS NULL`. Owned by the SelfHelp core.
2. **Plugin-owned** — `id_plugins = <plugin id>`. Owned by exactly one
   installed plugin.

The rule applies to:

- `styles`, `fields`, `permissions`, `lookups`, `api_routes`,
  `data_tables`, and every "contributed" host table.
- Every plugin-created table declared in `plugin.json.tables.owned`.

There is **no** shared table where some rows are host-owned and
some are plugin-owned without the `id_plugins` discriminator.

## User-level data

Personal data is stored in `dataTables` (per-section storage). The
ownership chain is:

```
dataTables.id_plugins → plugins.id
dataRows.id_dataTables → dataTables.id
dataCells.id_dataRows  → dataRows.id
```

To enumerate all personal data owned by a given plugin for a given
user, the GDPR exporter walks:

```sql
SELECT dt.name, dr.id, dc.value
FROM data_tables dt
JOIN data_rows dr   ON dr.id_data_tables = dt.id
JOIN data_cells dc  ON dc.id_data_rows = dr.id
WHERE dt.id_plugins = :pluginId
  AND dr.id_users   = :userId
```

## Export endpoint

```
GET /cms-api/v1/gdpr/export?plugin=sh2-shp-survey-js
Authorization: Bearer <user-jwt>

200 OK
{
  "user": { "id": 42, "email": "..." },
  "plugins": {
    "sh2-shp-survey-js": {
      "dataTables": [ ... ],
      "lookups": [],                 // user-specific lookup edits, rare
      "featureFlags": [ ... ]        // per-user flag overrides
    }
  }
}
```

When `?plugin=…` is omitted, the response includes the host + every
installed plugin. Plugins **cannot** opt out of GDPR export.

## Erasure endpoint

```
DELETE /cms-api/v1/gdpr/erase?plugin=sh2-shp-survey-js
Authorization: Bearer <admin-jwt>
```

The admin endpoint requires the `admin.gdpr.erase` permission. The
service:

1. Reads the exported snapshot first (audit trail).
2. Deletes all `dataRows` / `dataCells` for the user in plugin-owned
   tables.
3. Anonymises any plugin-owned references in non-deletable rows (e.g.
   replaces `id_users` with `0`).
4. Logs the erasure as a `plugin_operations` row with `type='purge'`
   and `id_requested_by_users = $admin->getId()`.

## Uninstall vs. purge

- **`uninstall`** retains plugin-owned data. Rows are tagged so that
  re-installing the plugin restores them. Personal data is **not**
  deleted.
- **`purge`** deletes plugin-owned tables and all rows. Use this when
  the operator wants to delete the data, not just the plugin code.
  A `purge` is logged as a `plugin_operations` row and is irreversible.

The admin UI's plugin detail page → **Danger zone** shows both
actions side by side and refuses `purge` unless the admin explicitly
opts in.

## Doctor checks

- Orphaned plugin-owned rows (`id_plugins` references missing plugin)
  → `error` with the table name and row count.
- Tables declared in `plugin.json.tables.owned` that don't exist in
  the DB → `error`.
- Tables in the DB whose name pattern matches the plugin's prefix but
  that are **not** declared in `tables.owned` → `warning`.

## Plugin author responsibilities

When you ship a plugin that stores personal data:

- Tag **every** row you create with `id_plugins`.
- Declare every table you own in `plugin.json.tables.owned`.
- Implement a `gdpr.export($user)` method on your plugin's service if
  you store data in a shape the host's auto-exporter cannot
  introspect (e.g. binary blobs, encrypted columns).
- Document **what** you store in your plugin's README.

The schema-parity script refuses to publish a plugin whose
`plugin.json.tables.owned` includes a table that does not have an
`id_plugins` column.

## Related docs

- [Capabilities](./capabilities.md) (`plugin.data-tables.*`)
- [Security model](./security-model.md)
- [Lookups](./lookups.md)
