# Plugin Lookups

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

The host's `lookups` table is the universal enum store. Plugins
extend it by contributing rows tagged with their `id_plugins`.

This isolates plugin-specific enums from the host's own enums and
makes uninstall trivially clean.

## The contract

| Column            | Notes                                                 |
|-------------------|-------------------------------------------------------|
| `type_code`       | Plugin **owns** this prefix: `<pluginId>_<type>`.     |
| `lookup_code`     | Free-form code, unique within `(id_plugins, type_code)`. |
| `lookup_value`    | Display value (translatable via i18n if needed).      |
| `lookup_description` | Optional admin-facing help text.                   |
| `id_plugins`      | Set to the plugin's row id. Null = host-owned.         |

The host enforces the type-code prefix at insert time: a row from
plugin `sh2-shp-survey-js` **must** have a `type_code` starting with
`sh2-shp-survey-js_`. The doctor warns when this rule is violated.

## How to contribute rows

Plugins contribute lookups in their manifest:

```jsonc
{
  "lookups": [
    {
      "typeCode": "sh2-shp-survey-js_question_type",
      "rows": [
        { "code": "single",   "value": "Single Choice",   "description": "..." },
        { "code": "multiple", "value": "Multiple Choice", "description": "..." }
      ]
    }
  ]
}
```

On install, the lookup contributor reads `lookups` from the manifest
and inserts the rows with `id_plugins = $plugin->getId()`. The
operation log shows one entry per inserted row.

## Reading lookups

In PHP:

```php
$rows = $this->lookupRepository->findBy([
    'typeCode' => 'sh2-shp-survey-js_question_type',
    'plugin'   => $plugin,
]);
```

Through the API (plugin-contributed route):

```http
GET /cms-api/v1/plugin/sh2-shp-survey-js/lookups/question-type
Authorization: Bearer <jwt>

200 OK
{
  "data": [
    { "code": "single",   "value": "Single Choice" },
    { "code": "multiple", "value": "Multiple Choice" }
  ]
}
```

## Editing at runtime

The admin UI's plugin detail page → **Lookups tab** allows in-place
editing of lookup values (e.g. translation updates). Edits are
written **without** a new operation row — they're treated as runtime
configuration, not a structural change.

A `plugin/lookup/{pluginId}/{typeCode}` Mercure topic fires on every
edit so subscribed UIs re-fetch.

## Uninstall behaviour

On `uninstall`, the host deletes all rows where
`id_plugins = $plugin->getId()`. The `lookups.id_plugins` foreign key
is `ON DELETE SET NULL`, so the rows are not lost — they just become
host-owned orphans, which is the safe choice in case other parts of
the host accidentally reference them.

On `purge`, the rows are **hard-deleted**.

## Doctor checks

- Type-code prefix mismatch (`type_code` not starting with the plugin
  id) → `warning`.
- Lookup row references a non-existent plugin (`id_plugins` not
  null but no matching plugin row) → `error` ("orphaned lookup
  rows").

## Related docs

- [Capabilities](./capabilities.md) (`plugin.lookups.contribute`)
- [Plugin operations & rollback](./plugin-operations-and-rollback.md)
- [GDPR & data ownership](./gdpr-and-data-ownership.md)
