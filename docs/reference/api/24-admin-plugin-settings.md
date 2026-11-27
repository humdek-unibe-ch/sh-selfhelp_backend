# Admin plugin settings

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-26.
Source of truth: `AdminPluginSettingsController`, `PluginSettingsService`, `PluginSettingsCrypto`, `PluginSettingsInterface`, schemas `requests/admin/plugins/put_plugin_settings.json` and `responses/admin/plugins/plugin_settings.json`, routes `admin_plugins_settings_get` and `admin_plugins_settings_put`, migration `Version20261126133108`.

## Overview

A plugin declares the settings it needs in its manifest (`settings[]`: `key`, `label`, `type`, `secret`, `required`,
`default`, `envOverride`; contract C14). The host stores the values, encrypts the secrets, lets an administrator edit
them with the two routes below, and hands them to the plugin through `PluginSettingsInterface`. A plugin never keeps a
settings table or an encryption of its own.

Both routes need the permission `admin.plugins.manage`.

## What a read returns

`PluginSettingsInterface::get(string $pluginId, string $key)` and the `value` of the list route use the same order:

1. the environment variable named by `envOverride`, when it is set, not empty and fits the type;
2. the stored value (a secret is decrypted, only for the plugin and only by `get()`);
3. the manifest `default`;
4. `null`.

The value has the type the manifest declares: `string` and `url` are strings, `number` is an int or a float,
`boolean` is a bool.

## List the settings

**Endpoint:** `GET /cms-api/v1/admin/plugins/{pluginId}/settings`

**Route name:** `admin_plugins_settings_get`

**Responses:**

| Status | When |
| --- | --- |
| 200 | One entry per setting the manifest declares, in manifest order (an empty list for a plugin without settings) |
| 401 | Guest |
| 403 | Signed in without `admin.plugins.manage` |
| 404 | The plugin is not installed |

**Success data** (`responses/admin/plugins/plugin_settings`):

```json
[
  {
    "key": "api_url",
    "label": "API URL",
    "type": "url",
    "secret": false,
    "required": false,
    "has_value": true,
    "value": "https://from-the-environment.example.org/",
    "hint": null,
    "overridden_by_env": true
  },
  {
    "key": "api_key",
    "label": "API key",
    "type": "string",
    "secret": true,
    "required": true,
    "has_value": true,
    "value": null,
    "hint": "7890",
    "overridden_by_env": false
  },
  {
    "key": "batch_size",
    "label": "Batch size",
    "type": "number",
    "secret": false,
    "required": false,
    "has_value": false,
    "value": 10,
    "hint": null,
    "overridden_by_env": false
  }
]
```

- `has_value` is true when an environment override or a stored value is in effect. A manifest `default` does not
  count; it shows in `value` (`batch_size` above).
- A **secret is never returned.** `value` is `null`; `has_value` says whether one is set. `hint` is the last 4
  characters of a secret of 12 or more characters (counted in characters, not bytes), otherwise `null`.
- `overridden_by_env` is true when the environment variable named by `envOverride` is set and decides the value. The
  stored value stays in the database and applies again when the variable is unset.
- A secret that was encrypted under another `APP_SECRET` (a database restored as a new installation) or is damaged
  reads as not set: `has_value` false. Enter it again.

## Save settings

**Endpoint:** `PUT /cms-api/v1/admin/plugins/{pluginId}/settings`

**Route name:** `admin_plugins_settings_put`

**Request body** (`requests/admin/plugins/put_plugin_settings`):

```json
{
  "values": {
    "api_url": "https://qa.example.org/v1",
    "api_key": "sk-live-1234567890",
    "batch_size": 25,
    "verbose": true,
    "label": null
  }
}
```

- A value **sets** the setting. A secret is encrypted before it is stored.
- `null` **clears** the setting: its stored value is deleted and the default applies again.
- A key that is **left out stays as it is**. A form that never shows a secret cannot wipe it by saving the other
  fields.
- Saving the same value again changes nothing and is not logged.
- Every entry is checked before anything is written; one bad entry means nothing is saved.

**Validation by declared type:**

| Type | Accepted | Refused (`reason`) |
| --- | --- | --- |
| `string` | a non-empty string of at most 4000 characters | not a string (`wrong_type`), `""` (`empty`; send `null` to clear), longer (`too_long`) |
| `url` | `https://` address of at most 2048 characters; plain `http://` only for `localhost`, `127.0.0.1` and `[::1]` | not a string (`wrong_type`), not a URL (`invalid_url`), any other `http://` or scheme (`url_must_be_https`) |
| `number` | a JSON number (int or float) | a string, boolean or anything else (`wrong_type`) |
| `boolean` | `true` or `false` | `"yes"`, `1`, anything else (`wrong_type`) |

A key the manifest does not declare is refused with `reason` `unknown_setting`.

**Responses:**

| Status | When |
| --- | --- |
| 200 | Saved; the data is the new list, as in the GET route |
| 400 | `error` is `invalid_plugin_setting` with `data` `{ key, reason, detail }`, or the body breaks the request schema (no `values` object) |
| 401 | Guest |
| 403 | Signed in without `admin.plugins.manage` |
| 404 | The plugin is not installed |

## Storage and lifecycle

- Table `plugin_settings` (entity `PluginSetting`): `plugin_id`, `setting_key`, `value` (text), `is_secret`,
  `updated_at`, `id_users_updated`. Unique on `(plugin_id, setting_key)`.
- The rows are keyed by the plugin's **manifest id**, not by a foreign key to `plugins`. **Uninstalling keeps the
  rows** (secrets included, as plugin-owned tables are kept); a reinstall finds them again. **Purging deletes
  them** (`PluginSettingsPurgeListener` on `PluginPurgedEvent`).
- A secret is stored as `enc:v1:<base64>`: libsodium `secretbox`, key = HKDF-SHA256 of `APP_SECRET` with the info
  string `selfhelp-plugin-settings`, a fresh random nonce per value. `v1` names the scheme. Changing `APP_SECRET`
  makes every stored secret unreadable (it reads as not set); plan to enter the secrets again.
- The transaction log gets one line per save with the **keys that changed**, for example
  `Plugin settings of "example-plugin" changed: api_key, label`. A value is never logged.

## Reading settings from a plugin

```php
use App\Plugin\Settings\PluginSettingsInterface;

final class ExampleClient
{
    public function __construct(private readonly PluginSettingsInterface $settings)
    {
    }

    public function endpoint(): string
    {
        $url = $this->settings->get('example-plugin', 'api_url');

        return is_string($url) ? $url : throw new \RuntimeException('Set the API URL in the plugin settings.');
    }
}
```

`get()` throws `\InvalidArgumentException` for a plugin that is not installed or a key the manifest does not
declare. Read a secret when you need it; do not copy it into a job config, a log or a response.
