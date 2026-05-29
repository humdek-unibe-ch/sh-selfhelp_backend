# Local Debugging With A Production DB Copy

This guide explains what happens when you restore a production database dump into a local backend and want to debug the CMS, especially the plugin system.

## Short answer

No: a production DB copy alone does **not** fully restore the local plugin runtime.

The database contains plugin rows, versions, manifests, operations, permissions, route rows, and plugin-owned data. But the running backend also depends on generated and local filesystem state that is **not** inside MySQL:

- `var/plugin-composer/vendor/` for installed backend Composer packages
- `config/selfhelp_plugin_bundles.php` for Symfony bundle registration
- `selfhelp.plugins.lock.json` for generated plugin lock metadata
- `public/plugin-artifacts/<plugin>-<version>/` for frontend runtime bundles

So importing production data locally gives you the **record of what should exist**, not a guaranteed runnable local plugin installation.

## What is restored from the DB

After importing the DB, you get:

- the `plugins` table rows, including plugin id, version, trust level, install mode, enabled flag, and stored `manifest_json`
- plugin-owned API routes in `api_routes`
- plugin permissions, lookups, feature flags, and plugin-owned application data
- operation history in `plugin_operations`

This is enough for the backend to know **which plugins are supposed to be installed**.

## What is not restored from the DB

The DB does not recreate:

- PHP packages inside `var/plugin-composer/`
- autoloadable plugin bundle classes
- generated frontend runtime files in `public/plugin-artifacts/`
- generated `config/selfhelp_plugin_bundles.php`
- generated `selfhelp.plugins.lock.json`

`selfhelp:plugin:repair` helps only with the generated metadata. It rebuilds the lock file and bundles file from the `plugins` table, but it does **not** reinstall missing plugin packages and does **not** recreate missing frontend artifacts.

## Safe local workflow

### 1. Restore the DB first

Import the production dump into your local MySQL instance.

Use a local `.env.local` that points to the restored DB and local services. Do not reuse production secrets, Mercure URLs, or external integration credentials unless you intentionally need them for debugging.

### 2. Start with plugin safe mode if you are unsure

If the restored DB says plugins are enabled but your local machine does not yet have the matching plugin packages, boot without plugin bundles first:

```bash
php bin/console selfhelp:plugin:safe-mode --enable
```

This prevents Symfony from trying to load missing plugin bundle classes during boot.

If even that cannot run because the kernel is already broken, set:

```ini
SELFHELP_DISABLE_PLUGINS=true
```

in `.env.local` temporarily.

### 3. Inspect the current state

Run:

```bash
php bin/console selfhelp:plugin:doctor
```

This shows drift such as:

- plugins present in DB but missing from `selfhelp.plugins.lock.json`
- version drift between DB and lock file
- runtime health warnings
- failed plugin operations

### 4. Rebuild generated metadata

Once the backend can boot in safe mode, regenerate the generated plugin metadata:

```bash
php bin/console selfhelp:plugin:repair
```

This rebuilds:

- `config/selfhelp_plugin_bundles.php`
- `selfhelp.plugins.lock.json`

It also invalidates plugin-surface caches.

### 5. Restore missing plugin packages

This is the manual part. If the plugin backend packages are not already present in:

```text
var/plugin-composer/vendor/
```

you must reinstall them locally.

The correct source depends on how the plugin was originally installed:

- registry or URL install: reinstall from the same registry/source
- `.shplugin` archive install: reinstall from the same archive or published release
- local sibling checkout during development: reattach the local plugin repo

If you have the plugin repositories locally, the cleanest dev path is to reinstall each plugin through the normal install flow so Composer, migrations, route sync, lock file, and artifact promotion all happen through supported code paths.

### 6. Restore missing frontend artifacts

If the plugin uses frontend runtime bundles, also make sure the expected files exist under:

```text
public/plugin-artifacts/<plugin-id>-<version>/
```

Without them, the backend may look healthy enough to boot, but the admin/frontend will fail when trying to load the plugin runtime.

### 7. Disable safe mode and retest

After plugin packages and artifacts are back in place:

```bash
php bin/console selfhelp:plugin:safe-mode --disable
php bin/console selfhelp:plugin:doctor
```

At that point the local backend should reflect the production DB state closely enough for debugging.

## Recommended debugging strategy for developers

### Best case: debug core CMS behavior only

If your bug is in core CMS data or API behavior and not inside a plugin bundle:

1. Restore the DB
2. Enable plugin safe mode
3. Run `selfhelp:plugin:repair`
4. Debug the core issue without restoring every plugin first

This is the fastest path when plugins are unrelated to the bug.

### Plugin-specific bug

If the bug involves a plugin's routes, services, migrations, admin pages, or runtime bundle:

1. Restore the DB
2. Boot in safe mode
3. Run `selfhelp:plugin:doctor`
4. Reinstall or reattach the affected plugin locally
5. Run `selfhelp:plugin:repair`
6. Disable safe mode
7. Reproduce the issue

For plugin debugging, a DB copy without the matching plugin package/artifact state is usually not sufficient.

## Important expectations

- The system does **not** automatically resolve plugin versions from the DB into local Composer installs.
- The `plugins` table tells the host what should be installed, but the actual backend package still has to exist under `var/plugin-composer/`.
- The system does **not** automatically recreate missing `public/plugin-artifacts/...` bundles from DB state.
- `selfhelp:plugin:repair` is a metadata repair tool, not a full plugin reinstall tool.

## Practical checklist

- Restore the DB dump locally
- Point `.env.local` to local infrastructure
- Enable plugin safe mode
- Run `php bin/console selfhelp:plugin:doctor`
- Run `php bin/console selfhelp:plugin:repair`
- Reinstall or reattach any missing plugins
- Restore missing frontend artifacts if needed
- Disable safe mode
- Run the doctor again and reproduce the bug

## Related docs

- [System Architecture Overview](./01-system-architecture.md)
- [Development Workflow](./14-development-workflow.md)
- [Plugin Installation Guide](../plugins/installation.md)
- [Plugin Ecosystem - Architecture](../plugins/architecture.md)
- [Plugin Lock File](../plugins/lock-file.md)
