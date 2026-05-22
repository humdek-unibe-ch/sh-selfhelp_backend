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
- **What happens**: `PluginInstaller` invokes
  [`PackageManagerRunner`](../../src/Plugin/PackageManager/PackageManagerRunner.php)
  which shells out to `composer require` / `npm install` directly in
  the web request. The orchestrator then writes the migration, the
  `plugins` row, and the lock file.
- **Guard-rails**: every capability is granted; the signature verifier
  is set to `lenient`; the operation log includes raw composer/npm
  stdout.
- **When to use**: local feature work, integration tests on a developer
  machine, and the test database in CI.
- **When NOT to use**: production. Web-process composer installs are
  slow and lose stdout on timeout; CI-driven `managed` mode is the
  production path.

## `managed`

- **Who**: a CI pipeline (GitHub Actions, GitLab CI, etc.).
- **What happens**:
  1. Admin clicks "Request install" → backend creates a `requested`
     `plugin_operations` row, validates the manifest, and writes the
     intended package set.
  2. CI watches the operation row (poll or webhook), runs
     `composer install` + `npm install --workspaces` on a build host,
     builds the artefact, and deploys it.
  3. Admin clicks "Finalize" → backend re-reads the deployed lock
     file, runs migrations, and flips the operation to `succeeded`.
- **Guard-rails**: every capability is checked against the granted
  capability set; the signature verifier is `strict`; the web process
  never executes `composer` or `npm`.
- **When to use**: every production environment, every shared
  staging environment.

## `trusted`

- **Who**: an operator with deep trust who wants the "one click
  installs" UX without going through CI.
- **What happens**: behaves like `development` (web process runs
  composer/npm) but with the `strict` signature verifier active and
  all capabilities enforced.
- **When to use**: single-server installs, on-prem appliances, or
  controlled-VM deployments where there is no CI to drive `managed`
  mode and where the operator accepts the risk of running package
  managers in the web request.
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
