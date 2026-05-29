# `selfhelp.plugins.lock.json` — the plugin lock file

Single source of truth for what is **actually installed**. Written
atomically by [`PluginLockFileWriter`](../../src/Plugin/Lifecycle/PluginLockFileWriter.php)
after every plugin operation succeeds; read by
[`PluginLockFileReader`](../../src/Plugin/Lifecycle/PluginLockFileReader.php),
the `selfhelp:plugin:doctor` command, and the CI workflows.

## Schema

The canonical JSON Schema lives at
[`docs/plugins/plugin-lock.schema.json`](./plugin-lock.schema.json).

```jsonc
{
  "lockfileVersion": 1,
  "generatedAt": "2026-05-22T08:46:57+00:00",
  "sdk": {
    "version": "1.0.4",
    "pluginApiVersion": "1.0"
  },
  "plugins": {
    "sh2-shp-survey-js": {
      "id": "sh2-shp-survey-js",
      "version": "1.0.0",
      "pluginApiVersion": "1.0",
      "trustLevel": "untrusted",
      "installMode": "managed",
      "backendPackage": "humdek/sh2-shp-survey-js",
      "frontend": {
        "runtimeUrl": "/plugin-artifacts/sh2-shp-survey-js-1.0.0/plugin.esm.js",
        "stylesheetUrl": "/plugin-artifacts/sh2-shp-survey-js-1.0.0/plugin.css",
        "integrity": "sha384-...",
        "format": "esm"
      },
      "mobilePackage": null,
      "mobilePackageVersion": null,
      "checksum": "sha256:...",
      "signing": {
        "keyId": "humdek-2026-01",
        "signature": "base64-ed25519-detached-signature"
      },
      "capabilities": ["plugin.styles.contribute", "plugin.api-routes.contribute"],
      "migrations": [
        {
          "file": "Version20260522063620.php",
          "sha256": "9d3f…"
        }
      ]
    }
  }
}
```

`signing.keyId` and `signing.signature` are populated from the
canonical `signedPayload`: when the install succeeded against an
Ed25519-signed source, they record the keyId/signature that was
verified. `migrations[]` lists every Doctrine migration shipped in
the plugin's bundle directory, each annotated with its SHA-256 — a
host with the same lock can therefore detect that the plugin's
migration set has drifted (e.g. a class was rewritten after publish).

## Drift detection

`doctor` performs two parity checks against the lock file:

1. **ID parity** (`checkLockFile`) — the set of `pluginId`s in
   `plugins` matches the set in the `plugins` table.
2. **Version parity** (`checkLockVersionParity`) — for every plugin
   id, the lock's `version` matches the DB's `version`.

Either drift is **always** an admin-visible warning. The fix is to run
`php bin/console selfhelp:plugin:repair --plugin=<id>` which rewrites
the lock from the DB.

## When is the lock file regenerated?

| Action                          | Rewrites lock? |
|---------------------------------|---------------|
| `install` / `update`            | Yes           |
| `enable` / `disable`            | No (status flips happen in DB only) |
| `uninstall`                     | Yes (entry removed) |
| `purge`                         | Yes (entry removed + tables dropped) |
| `repair`                        | Yes (always)  |
| Rollback of a failed `install`  | Yes (entry removed) |
| Rollback of a failed `update`   | Yes (previous version restored) |

## Atomic writes

The writer uses the classic *write-to-tmp + rename* dance:

```php
file_put_contents($path . '.tmp', $json, LOCK_EX);
rename($path . '.tmp', $path);
```

This guarantees the file is either fully written or untouched — a
crash mid-write never leaves a half-valid lock.

## Editing by hand

Don't. The doctor will report drift, and the next operation will
overwrite your changes. If you need to repair from a broken state,
use:

```bash
php bin/console selfhelp:plugin:repair --plugin=<id>
```

…which is the only supported path for fixing a drifted lock.

## Related docs

- [Install modes](./install-modes.md)
- [Plugin operations & rollback](./plugin-operations-and-rollback.md)
- [Registry & channels](./registry-and-channels.md)
- [Schema parity](../../scripts/check-schema-parity.mjs) (script that
  enforces the TS mirror in `@selfhelp/shared`)
