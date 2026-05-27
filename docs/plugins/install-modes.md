# Plugin Install Modes

Every plugin operation (install, update, uninstall, repair) runs in one
of three install modes. The mode determines **who runs the package
manager** (composer / npm), **where the build artefact lives**, and
**which guard-rails are active**.

| Mode          | Composer / npm runs in… | DB writes | Lock-file source | Default in        |
|---------------|-------------------------|-----------|------------------|-------------------|
| `development` | The web request (PHP `Process`) | Yes | DB → lock | `APP_ENV=dev`     |
| `managed`     | CI (separate pipeline)  | Yes (after CI build is deployed) | CI → lock | `APP_ENV=prod`    |
| `trusted`     | The web request (PHP `Process`) | Yes | DB → lock | Explicit opt-in   |

The active mode for an operation is resolved by
[`InstallModeResolver`](../../src/Plugin/Lifecycle/InstallModeResolver.php):

1. Explicit per-operation override (admin UI).
2. `SELFHELP_PLUGIN_INSTALL_MODE` env var.
3. Env-derived default (dev → `development`, prod → `managed`).

## `development`

- **Who**: a single developer running `composer dev` locally.
- **What happens**: `PluginInstaller` still dispatches the
  `InstallPluginMessage`, but Symfony Messenger is configured with
  the `sync://` transport in development. The handler runs in-process,
  shelling out to `composer require` via
  [`PackageManagerRunner`](../../src/Plugin/PackageManager/PackageManagerRunner.php),
  promoting any `.shplugin` artifacts under `public/plugin-artifacts/`,
  running the plugin's Doctrine migrations, and finalising in a single
  request. **The frontend is never asked to run `npm install` or
  rebuild Next.js** — plugin UI is an ESM runtime bundle loaded from
  `/plugin-artifacts/<id>-<ver>/plugin.esm.js`.
- **Guard-rails**: every capability is granted; the signature verifier
  is set to `lenient`; the operation log includes raw composer stdout.
- **When to use**: local feature work and the test database in CI.
- **When NOT to use**: production. The web request would block until
  composer finished; `managed` mode is the production path.

## `managed`

- **Who**: a Symfony Messenger worker (`plugin_ops` transport),
  optionally fronted by a CI pipeline.
- **What happens**:
  1. Admin clicks **Install** on any source tab → the API endpoint
     persists a `requested` `plugin_operations` row, validates the
     manifest + signature, and **dispatches `InstallPluginMessage`**
     on the `plugin_ops` Messenger transport. The API responds
     `202 Accepted` immediately.
  2. A long-running `php bin/console messenger:consume plugin_ops`
     worker picks up the message. It runs `composer require` against
     the manifest's coordinates, promotes any `.shplugin` artifacts
     under `public/plugin-artifacts/`, runs the plugin's Doctrine
     migrations, flips the row to `succeeded`, and publishes a
     Mercure event on `selfhelp/plugins/state`.
  3. The admin UI re-fetches the plugin list on the Mercure event.
     There is **no browser-side "Finalize" step**; the
     `selfhelp:plugin:run-operation` CLI command is the operator
     escape-hatch if a worker dies mid-install and the operation
     needs manual completion.
- **Guard-rails**: every capability is checked against the granted
  capability set; the signature verifier is `strict`; the web process
  never executes `composer` or `npm`.
- **When to use**: every production environment, every shared staging
  environment.

## `trusted`

- **Who**: an operator with deep trust who wants the "one click
  installs" UX without going through CI.
- **What happens**: behaves like `development` (the messenger handler
  runs composer in-process via the `sync://` transport) but with the
  `strict` signature verifier active and all capabilities enforced.
  Frontend plugin UI is still loaded as an ESM runtime bundle; there
  is never an `npm install` step.
- **When to use**: single-server installs, on-prem appliances, or
  controlled-VM deployments where there is no CI to drive `managed`
  mode and where the operator accepts the risk of running composer
  in the web request.
- **When NOT to use**: multi-tenant SaaS, anywhere with a real
  attacker model.

## Env vars

| Variable                          | Purpose                                  |
|-----------------------------------|------------------------------------------|
| `SELFHELP_PLUGIN_INSTALL_MODE`    | Explicit mode override                   |
| `SELFHELP_ALLOW_WEB_PLUGIN_INSTALL` | Required to allow `development`/`trusted` web-side composer/npm |
| `SELFHELP_PLUGIN_SIGNATURE_MODE`  | `strict` / `lenient`                     |

## Related code

- [`InstallModeResolver`](../../src/Plugin/Lifecycle/InstallModeResolver.php)
- [`PluginInstaller`](../../src/Plugin/Lifecycle/PluginInstaller.php)
- [`PluginUpdater`](../../src/Plugin/Lifecycle/PluginUpdater.php)
- [`PackageManagerRunner`](../../src/Plugin/PackageManager/PackageManagerRunner.php)
