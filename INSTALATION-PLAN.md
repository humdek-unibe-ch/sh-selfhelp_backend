# SelfHelp Core Installation And Distribution Plan

Status: draft for discussion.

This document describes how SelfHelp should distribute the core CMS as an
industry-level installable product. "Core CMS" means the Symfony backend from
`sh-selfhelp_backend`, the Next.js frontend from `sh-selfhelp_frontend`, and the
published `@selfhelp/shared` package versions those two runtimes are built
against.

The target outcome is:

- One simple release artifact for operators.
- A guided web installer where an operator can select or create the database,
  create the first admin user, configure URLs/secrets/services, and click
  through to a working CMS.
- A signed core update registry similar in spirit to the plugin registry.
- Safe upgrades with backups, migration checks, health checks, and rollback
  metadata.

## Recommended Direction

Use a Docker-first distribution for the first production-grade installer.

The current backend already expects MySQL, Redis, Mercure, Messenger workers,
JWT keys, cache warmup, Doctrine migrations, and a frontend BFF. A Docker-first
installer gives us the most predictable "next, next, done" experience because
we can control PHP, Node, extensions, web server config, worker processes, and
service networking.

Support these install profiles:

| Profile | Purpose | What the installer owns |
| ------- | ------- | ----------------------- |
| `single-server` | Recommended first release for VPS/on-prem installs. | Reverse proxy, backend, frontend, workers, Redis, Mercure, optional MySQL. |
| `external-db` | Institutions with managed MySQL. | App stack, Redis/Mercure unless externalized, DB connection only. |
| `advanced-compose` | Operators who want editable generated compose files. | Generates files, then operator runs/maintains them. |
| `kubernetes` | Later phase for larger deployments. | Helm chart or manifests, not first priority. |
| `shared-hosting` | Later phase only if required. | Harder because Next.js needs a Node runtime and workers. |

The installer should not be part of the normal public application after setup.
It should be a separate bootstrap service or binary that is disabled and
removed once installation finishes.

## Release Artifact Strategy

There are two useful artifact types. We should support the connected artifact
first, then add the offline artifact once the release flow is stable.

### 1. Connected Installer

File name example:

```text
selfhelp-installer-linux-amd64
selfhelp-installer-windows-amd64.exe
selfhelp-installer-macos-arm64
```

What it does:

1. Starts a local web UI on a random localhost port.
2. Lets the operator choose install profile and target version/channel.
3. Downloads signed release metadata from the core registry.
4. Generates `.env`, `compose.yaml`, persistent volume names, service secrets,
   JWT keys, and reverse-proxy configuration.
5. Pulls the approved container images.
6. Runs migrations, cache warmup, admin-user creation, health checks, and
   post-install validation.

This keeps the first download small while still feeling like "one file".

### 2. Offline Bundle

File name example:

```text
selfhelp-core-1.0.0-linux-amd64.shcore
```

The `.shcore` archive should contain:

- `release.json` signed manifest.
- Backend image or backend production build.
- Frontend image or Next.js standalone build.
- Installer binary/service.
- `compose.yaml` templates.
- Doctrine migration metadata.
- JSON schemas and compatibility data.
- Checksums/SBOM/provenance files.
- Optional MySQL, Redis, and Mercure image tarballs for air-gapped installs.

This is the true "all bundled in one file" option, but it will be much larger.

## Runtime Architecture

The installed stack should look like this for the Docker-first profile:

```text
Browser
  |
  v
Reverse proxy / TLS
  |
  +-- Next.js frontend/BFF
  |     - renders public CMS pages and admin UI
  |     - owns browser cookies
  |     - proxies /api/* to Symfony
  |
  +-- Symfony backend API
  |     - serves /cms-api/v1/*
  |     - serves uploaded assets and plugin artifacts
  |     - runs Doctrine migrations
  |
  +-- Mercure hub
        - realtime auth/ACL/plugin updates

Internal services:
  - MySQL 8, unless external DB is selected
  - Redis for cache and distributed locks
  - Messenger worker for async/plugin operations
  - Scheduler/cron worker for scheduled jobs
  - SMTP provider, configured but not bundled for production
```

Important frontend detail: the Next.js frontend uses a BFF. Browser API calls
should go through `/api/*`, while server-side frontend code can call Symfony via
an internal URL. The installer must therefore configure both:

- public URL seen by browsers
- internal backend URL used by the frontend container

## Installer Wizard

The installer UI should be a guided wizard with resumable progress. Every step
should validate before the operator can continue.

### Step 1: Welcome And Install Mode

Collect:

- New install or update existing install.
- Connected install or offline bundle.
- Release channel: stable, beta, security, development.
- Install profile: single-server, external-db, advanced-compose.

Validation:

- Docker/Podman available for Docker profiles.
- Ports available.
- Enough RAM/disk.
- Supported CPU architecture.
- Installer can write to the selected install directory.

### Step 2: Domain, URLs, And TLS

Collect:

- Primary public domain.
- Optional separate admin/backend domain.
- HTTPS mode: automatic Let's Encrypt, existing certificate, reverse proxy
  already handles TLS, or local HTTP only.

Generate:

- Frontend public URL.
- Symfony public URL.
- Symfony internal URL for the frontend BFF.
- `CORS_ALLOW_ORIGIN`.
- `MERCURE_PUBLIC_URL`.
- trusted hosts/proxies.

Validation:

- Domain resolves to this host when using automatic TLS.
- HTTP/HTTPS ports are reachable.
- Certificate paths exist if using existing certificates.

### Step 3: Database

Offer two paths:

1. Use existing database/user.
2. Create database and application user from an admin DB account.

Collect for existing DB:

- Host, port, database name.
- Application DB username/password.
- SSL mode and CA certificate if needed.

Collect for create DB:

- MySQL admin host/port/user/password.
- New database name.
- New application username/password.
- Charset/collation, default `utf8mb4` / `utf8mb4_unicode_ci`.

Rules:

- Never store the DB admin password after database creation.
- Store only the least-privileged app DB account in the generated env.
- Refuse obvious production mistakes when the operator chooses test/dev mode.
- Check the database is empty before a fresh install unless the user chooses
  an explicit recovery/import mode.

Installer actions:

- Create database if requested.
- Create/grant app user if requested.
- Write `DATABASE_URL`.
- Run a connection test.
- Run `doctrine:migrations:status`.

### Step 4: Services

Configure:

- Redis: bundled container or external Redis URL.
- Mercure: bundled hub or external hub.
- Mailer/SMTP: provider, host, port, username, password, sender address.
- Upload storage: local volume first; S3/object storage later.
- Backups: local path first; S3/SFTP later.

Generate:

- `REDIS_URL`.
- `LOCK_DSN` / `PLUGIN_LOCK_DSN`.
- `MERCURE_URL`.
- `MERCURE_PUBLIC_URL`.
- publisher/subscriber secrets or one shared secret for simple installs.
- `MAILER_DSN`.
- upload limits.

### Step 5: Secrets And Keys

Generate automatically:

- `APP_SECRET`.
- JWT private/public keypair and passphrase.
- Mercure JWT secret(s).
- database application password when creating DB.
- admin one-time setup token for the installer handoff.

Rules:

- Secrets are never printed by default.
- Offer a "download recovery bundle" containing generated secrets, clearly
  marked as sensitive.
- File permissions should be restrictive.
- Rotate installer token after installation completes.

### Step 6: First Admin User

Collect:

- Admin email.
- Display name.
- Password.
- Optional enforced 2FA setup after first login.

Installer action:

- Use the existing backend command `app:create-admin-user` rather than raw SQL.
- Validate password policy before running the command.
- Confirm the user belongs to the seeded admin group/role.

### Step 7: Optional Initial Content

Offer:

- Empty CMS.
- Demo/sample CMS content.
- Import from backup.
- Install selected official plugins after core is healthy.

This should be optional. Core install must succeed without plugins.

### Step 8: Review

Show a redacted summary:

- Version/channel.
- Install directory.
- Public URL.
- Database host/database/app user.
- Redis/Mercure modes.
- Mail mode.
- Backup path.
- Admin email.
- Services that will be created.

Require explicit confirmation before writing files or starting containers.

### Step 9: Install Progress

Run these operations with visible logs and resumable checkpoints:

1. Write configuration files.
2. Create directories and volumes.
3. Pull/load images.
4. Start database/Redis/Mercure if bundled.
5. Start backend in maintenance/install mode.
6. Generate JWT keys if not already present.
7. Run Doctrine migrations.
8. Run `doctrine:schema:validate`.
9. Clear and warm caches.
10. Clear/warm dynamic API route cache.
11. Create the admin user.
12. Start frontend and workers.
13. Run health checks.
14. Disable installer mode.
15. Open the final URL.

### Step 10: Finish

Show:

- Admin URL.
- First-login instructions.
- Backup location.
- Where generated compose/env files live.
- How to restart, stop, update, and collect logs.
- Warning that installer access is now disabled.

## Core Update Registry

Yes, a registry like plugins is possible and recommended. It should be a
separate "core release registry", not the same table/UI path as plugins,
because a core update changes the running host itself.

Suggested default source:

```text
selfhelp-core-public
https://humdek-unibe-ch.github.io/sh2-core-registry/
```

Registry layout:

```text
sh2-core-registry/
  registry.json
  core-release.schema.json
  releases/
    selfhelp-core-1.0.0.json
    selfhelp-core-1.0.1.json
  artifacts/
    1.0.0/
      selfhelp-core-1.0.0-linux-amd64.shcore
      SBOM.spdx.json
      checksums.txt
```

Release entry fields:

| Field | Purpose |
| ----- | ------- |
| `id` | Always `selfhelp-core`. |
| `version` | Semver core version. |
| `channel` | stable, beta, security, nightly. |
| `releasedAt` | UTC timestamp. |
| `minimumInstalledVersion` | Oldest version that can upgrade directly. |
| `requiresManualStep` | Whether the UI can one-click or must hand off. |
| `breaking` | Whether the update is breaking. |
| `backend` | Backend image/package URL, digest, PHP/Symfony requirements. |
| `frontend` | Frontend image/package URL, digest, Node/Next requirements. |
| `shared` | Required `@selfhelp/shared` version. |
| `database` | Migration range, destructive flag, estimated runtime. |
| `pluginApi` | Plugin API compatibility range. |
| `checksums` | SHA-256 for every artifact. |
| `sbom` | SBOM URL and checksum. |
| `signature` | Signature over canonical payload. |
| `keyId` | Public key id used for verification. |
| `releaseNotesUrl` | Human-readable notes. |

Update flow:

1. The installed app or updater sidecar checks enabled core sources.
2. It compares installed version, channel, plugin API compatibility, and
   database migration path.
3. It shows available updates in an admin "Core Updates" page.
4. Operator clicks "Prepare update".
5. Updater downloads artifacts, verifies signatures/checksums, and creates a
   backup.
6. Updater runs a preflight: disk space, DB connection, migration status,
   plugin compatibility, health endpoint, worker state.
7. Operator approves maintenance window.
8. Updater switches to maintenance mode.
9. Updater pulls/loads new backend and frontend artifacts.
10. Updater runs Doctrine migrations.
11. Updater warms caches and route cache.
12. Updater starts the new services.
13. Updater runs health checks and smoke checks.
14. If checks pass, maintenance mode is removed.
15. If checks fail, updater rolls back containers/config and optionally
    restores DB backup depending on migration safety.

Important implementation rule: the running Symfony app should not be solely
responsible for replacing itself. Use a small external updater service, CLI, or
installer binary for the actual file/container changes. The admin UI can request
and monitor the update, but the updater should execute it.

## Backend Work Needed

Add or formalize:

- A `GET /cms-api/v1/health` readiness contract for installer/update checks
  if not already available in the target branch.
- A core version endpoint exposing backend version, DB migration status,
  plugin API version, installed plugins, and safe-mode state.
- A core update source model, separate from plugin sources, or a small
  installer-owned config file for v1.
- Admin API endpoints for listing core updates and reading update preflight
  results.
- Maintenance mode support that returns a clean API response and lets health
  checks distinguish "installing/updating" from "broken".
- Commands for installer use:
  - generate/validate env
  - generate JWT keys
  - run install preflight
  - run post-install validation
  - create admin user
  - export backup
  - restore backup
- Structured logs for installer/updater progress.

Avoid:

- Raw SQL bootstrap. Fresh install must continue to use Doctrine migrations.
- Keeping installer routes enabled after install.
- Writing secrets to the database unless explicitly needed.
- Running destructive migrations without backup and confirmation.

## Frontend Work Needed

Add or formalize:

- A production build artifact suitable for container deployment. Ideally use
  Next.js standalone output or a dedicated frontend image.
- Runtime configuration for public URL and internal Symfony URL.
- A small admin "System" or "Core Updates" UI after install.
- Clear handling of maintenance/update mode.
- BFF compatibility checks against backend API version and plugin API version.

Current frontend config already separates:

- `NEXT_PUBLIC_API_URL`
- `SYMFONY_INTERNAL_URL`
- `SYMFONY_API_PREFIX`

The installer should generate these values rather than asking users to edit
files manually.

## Installer Implementation Options

Recommended v1: standalone binary plus web UI.

Options:

| Option | Pros | Cons |
| ------ | ---- | ---- |
| Go/Rust binary with embedded UI | True one-file installer, easy system checks, no PHP/Node prerequisite. | New codebase/tooling. |
| Node-based installer | Easier for frontend team, good UI tooling. | Requires Node before install unless bundled. |
| PHP PHAR installer | Familiar to backend team. | Requires PHP/extensions before install. |
| Docker-only installer container | Very predictable once Docker exists. | First step still requires Docker command knowledge. |

Best compromise:

- Ship a small native installer binary for Linux/Windows/macOS.
- Also publish a Docker one-liner for advanced operators.
- Keep installer logic declarative through `release.json` and templates so it
  can be tested without clicking the UI.

## Security Requirements

Installer/update security is as important as plugin security.

Must have:

- Signed release manifests.
- Artifact checksums.
- Trusted public keys configured by default for official Humdek releases.
- Key rotation plan.
- SBOM for backend/frontend images.
- Vulnerability scan in release CI.
- No secrets in logs.
- Redacted review screen.
- Least-privilege DB user.
- Installer disabled after completion.
- CSRF/session protection inside the installer UI.
- Localhost-only installer by default unless an explicit remote-install token is
  generated.
- Audit log entry for core updates after the app is installed.

Should have:

- SLSA/provenance attestations.
- Cosign-signed container images.
- Optional offline public-key verification.
- Recovery bundle encryption with operator-supplied passphrase.

## Backup And Rollback Requirements

Before every update:

- Snapshot generated config files.
- Backup database with routines/triggers.
- Snapshot uploads/assets if local storage is used.
- Snapshot plugin lock files and plugin artifacts.
- Record installed image digests.

Rollback levels:

| Level | Use case |
| ----- | -------- |
| Config/container rollback | Update failed before migrations. |
| App rollback with DB unchanged | Code update failed after non-destructive migrations. |
| Full restore | Migration changed data destructively or health checks fail after schema changes. |

The updater must explain which rollback level is available before the operator
clicks update.

## CI And Release Pipeline

Core release pipeline should:

1. Run backend gates: PHPStan, tests, migrations, schema validation.
2. Run frontend gates: typecheck, tests, build, e2e smoke where possible.
3. Build backend production image/artifact.
4. Build frontend production image/artifact.
5. Generate SBOMs.
6. Scan images/artifacts.
7. Generate `release.json`.
8. Sign canonical payload.
9. Publish artifacts to GitHub Releases or package registry.
10. Update `sh2-core-registry/registry.json`.
11. Publish registry to GitHub Pages or an internal mirror.
12. Smoke-test a fresh install from the published artifact.
13. Smoke-test update from previous stable release.

## Compatibility Policy

Core releases should publish compatibility metadata:

- backend API version
- frontend BFF expected API version
- DB migration range
- plugin API version range
- minimum plugin versions if a core update needs them
- minimum Docker/Podman version
- minimum MySQL version

The updater should refuse or warn when installed plugins declare incompatible
core/plugin API ranges.

## Documentation To Ship With Installer

Every release should include:

- quick install guide
- ports/services diagram
- environment variable reference
- backup/restore guide
- update guide
- troubleshooting guide
- security hardening guide
- air-gapped installation guide once offline bundle exists

## Proposed Milestones

### Milestone 1: Distribution Decision

- Decide supported first target: Docker single-server is recommended.
- Decide connected installer vs offline bundle first.
- Decide whether MySQL is bundled by default.
- Define official release channels.

### Milestone 2: Production Runtime Packaging

- Add backend production image/build.
- Add frontend production image/build.
- Add worker/scheduler containers.
- Add reverse proxy/TLS template.
- Add generated compose templates.

### Milestone 3: Installer MVP

- Native or container installer.
- Web wizard.
- DB create/select.
- Secret generation.
- Doctrine migrations.
- Admin user creation.
- Health checks.
- Disable installer after success.

### Milestone 4: Core Registry MVP

- `sh2-core-registry` repository.
- `core-release.schema.json`.
- Signed `registry.json`.
- Release publishing workflow.
- Installer can select stable/beta release.

### Milestone 5: Update MVP

- Core update checker.
- Preflight checks.
- Backup before update.
- Maintenance mode.
- Pull/load new artifacts.
- Run migrations.
- Health checks.
- Rollback metadata.

### Milestone 6: Hardening

- Offline `.shcore` bundle.
- SBOM/provenance/cosign.
- Air-gapped docs.
- Advanced backup providers.
- Kubernetes/Helm support if needed.

## Open Questions

These decisions will shape the implementation:

1. Should the first supported install target be Docker on a VPS/on-prem server,
   or do we also need shared hosting from day one?
2. When you say "one file", do you mean a small connected installer that
   downloads signed artifacts, or a fully offline bundle containing everything?
3. Should MySQL be bundled by default, or should production installs prefer an
   external/managed MySQL database?
4. Should Redis and Mercure always be bundled for single-server installs?
5. Should the frontend and backend live on the same public domain, or do you
   want separate domains/subdomains supported in the simple wizard?
6. Should the installer manage TLS automatically with Let's Encrypt, or should
   it assume an existing reverse proxy in university/institution environments?
7. Should updates be one-click from the admin UI, or should the UI only prepare
   an update and ask an operator to run a CLI command?
8. Which release channels do we want at launch: stable only, or stable plus beta?
9. Do we need a private core registry/mirror for institutions that cannot pull
   from GitHub Pages or public container registries?
10. Should the installer offer demo content, or should fresh installs always be
    empty except for seeded system data?
11. What backup target is required first: local filesystem, S3-compatible
    storage, SFTP, or institution-managed backup outside SelfHelp?
12. Do we need Windows Server support for production, or only Windows as a local
    machine running the installer against a Linux host?
13. Should plugin installation be offered during first install, or only after
    the core CMS is healthy?
14. Do we need multi-instance management, where one installer/updater manages
    multiple SelfHelp CMS installations on the same server?

## Initial Recommendation

Build this in the following order:

1. Docker single-server connected installer.
2. Signed core registry with stable/beta channels.
3. Admin-visible update checker with CLI/updater execution.
4. Full one-file offline `.shcore` bundle.
5. Kubernetes and shared-hosting support only after the Docker path is stable.

This gives users the "easy deployment" experience quickly while keeping the
architecture safe enough for production: signed releases, reproducible builds,
clear rollback, and no permanent installer attack surface.
