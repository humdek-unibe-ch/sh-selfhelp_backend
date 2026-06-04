# Plugin Feature Flags

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

Plugins ship feature flags that the host can flip at runtime without
re-installing the plugin. Stored in `plugin_feature_flags`:

| Column            | Notes                                                |
|-------------------|------------------------------------------------------|
| `id_plugins`      | Owning plugin.                                       |
| `code`            | Per-plugin unique flag code (`tiptap_editor`, …).    |
| `enabled`         | Effective value (boolean).                            |
| `scope`           | `global` / `role` / `user` / `group`.                 |
| `scope_value`     | When `scope != global`, the id (role / user / group). |
| `metadata_json`   | Optional payload (e.g. a percentage rollout).         |

Flags are **runtime** — no migration, no operation row, no version
bump. The doctor exposes them under each plugin's "Feature flags"
tab.

## Declaring flags in `plugin.json`

```jsonc
{
  "featureFlags": [
    {
      "code": "tiptap_editor",
      "defaultEnabled": false,
      "description": "Use the Tiptap rich-text editor in SurveyJS question descriptions.",
      "scopes": ["global", "role"]
    }
  ]
}
```

The host inserts a `(plugin, code, scope='global')` row at install
with `enabled = defaultEnabled`. Plugins can add more flags in
later updates — new flags are simply inserted at update time.

## Checking a flag

In PHP:

```php
if ($this->featureFlags->isEnabled($plugin, 'tiptap_editor', $user)) {
    // …
}
```

The service walks the precedence:

1. `scope='user'` row matching `$user->getId()`.
2. `scope='group'` rows for any group the user is in.
3. `scope='role'` rows for the user's role.
4. `scope='global'` row.

The first match wins.

In TS (frontend & mobile):

```ts
const enabled = host.featureFlags.useFlag('sh2-shp-survey-js', 'tiptap_editor');
```

The hook subscribes to `plugin/feature-flag/{flagId}` Mercure events
so flips are reflected in the UI instantly.

## Editing a flag

The admin UI's plugin detail page → **Feature flags tab** allows in-
place edit of `enabled` and `metadata_json`. Each edit:

1. Updates the row.
2. Bumps `updated_at` and `id_updated_by_users`.
3. Publishes `plugin/feature-flag/{flagId}` Mercure event.
4. Invalidates the per-plugin feature-flag cache category.

There is **no audit table** for flag changes — the `updated_at` and
`id_updated_by_users` columns capture the "who and when" but not the
"what was the previous value". If you need the latter, ship a
`plugin.audit-log` capability and write your own append-only table.

## Uninstall behaviour

All flag rows for the plugin are deleted on uninstall. The doctor
warns if a plugin row exists without any flag rows (might indicate a
partial uninstall).

## Doctor checks

- Per-plugin flag count → informational row.
- Orphaned flag rows (`id_plugins` references missing plugin) →
  `error`.

## Related docs

- [Capabilities](./capabilities.md) (`plugin.feature-flags.contribute`)
- [Realtime & no polling](./realtime-and-no-polling.md)
