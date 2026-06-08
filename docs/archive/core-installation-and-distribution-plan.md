 Please continue the implementation until the full requested scope is completed. Do not stop after partial progress, do not leave TODOs for later, and do not ask me to continue unless you are blocked by a real missing decision or broken repository state that cannot be resolved from the code.

Important testing strategy:

During implementation:
- Run only small, focused tests for the files/features you are currently changing.
- Do not run the full test suite after every small change.
- Use focused tests to keep the feedback loop fast.
- Run phpstan/typecheck/lint only when it makes sense for the current slice or when you need to verify a refactor.

When the implementation is complete:
- Run the full required verification commands once.
- Run the complete backend/frontend/shared/mobile checks that are relevant to the touched repos.
- Fix all errors until the final verification is green.
- Do not stop while tests, phpstan, typecheck, lint, schema validation, or migrations are failing.

Implementation order:
1. Finish the current slice completely.
2. Run focused tests for that slice.
3. Continue with the next slice.
4. Only after all slices are implemented, run the full verification.

Do not waste time running the full suite repeatedly during small implementation steps. Full verification should happen at the end, once the code is ready.

At the end, give me a clear summary:
- what was implemented
- which files/areas were changed
- which migrations were added
- which tests/checks were run
- final status of each check
- any remaining risks or manual follow-up

# SelfHelp Distribution, Registry, Update, And Multi-Instance Plan

Audience: external implementation agent.
Status: active implementation plan.
Applies to: SelfHelp2 backend, frontend, shared package, mobile app, official plugin registry, release tooling, Docker installer, updater, and server operations.
Last verified: 2026-06-08.
Source of truth: Runtime code in each repository, then active Symfony/Next/Expo/shared/plugin contracts, then this handoff. This file replaces the 2026-06-03 installer-only draft with a Docker-only connected distribution architecture.

## How To Use This File

This is a detailed prompt for an implementation agent that has not seen the earlier conversation. It is not only an installer plan. It is the full SelfHelp distribution, registry, update, multi-instance, and operations architecture for the first official production path.

Before implementing, read the `AGENTS.md` in every touched repository. Runtime code is the source of truth. If this document conflicts with actual code, verify the code and update the plan or active docs instead of guessing.

The agent implementing this should keep the following non-negotiable scope decisions:

- Official production installs are Docker-only.
- The installer is always connected. A working internet connection is required.
- Offline, air-gapped, standalone shared-hosting, manual PHP/Node source installs, and Windows Server production installs are out of scope for MVP.
- Windows, macOS, and Linux developer machines can run local testing through Docker Desktop or Docker Engine.
- Production servers must pull ready-built, signed artifacts. They must not run `npm build`, `composer install` from source, frontend compilation, or plugin compilation except in explicit development mode.
- One shared reverse proxy/router is allowed per server. Every SelfHelp instance must otherwise be isolated.
- One official SelfHelp registry is the source of truth for core/backend, frontend, official plugins, compatibility metadata, security advisories, and trusted release keys.
- Every instance must include its own scheduled-jobs runner container.
- The server-level tool is named SelfHelp Manager and lives in a separate repository named `sh-manager`.
- SelfHelp Manager web access is private by default: localhost-only for bootstrap, and VPN/private-network-only when a persistent manager web UI is enabled. Public internet exposure is not part of the MVP.

## One Sentence Goal

SelfHelp should be installable, updateable, reproducible, and maintainable as isolated Docker instances on one server, using one official connected registry for signed core/frontend/plugin artifacts, compatibility resolution, updates, security advisories, and reproducible experiment version pinning.

## Repositories And Runtime Areas To Inspect

Relevant repositories and areas:

- `sh-selfhelp_backend`
  - Symfony backend API, Doctrine migrations, seeded routes/permissions, health route, plugin lifecycle, plugin registry client, safe mode, scheduled jobs, Mercure, cache, JWT, and installer/updater-facing commands.
- `sh-selfhelp_frontend`
  - Next.js frontend/BFF, admin UI, system maintenance UI, public/internal URL config, production Docker artifact.
- `sh-selfhelp_shared`
  - shared API/types, plugin SDK/types, compatibility metadata, registry contracts consumed by frontend/mobile/plugin code.
- `sh-selfhelp_mobile`
  - not installed by the Docker server installer, but consumes shared/plugin/core compatibility contracts and must remain compatible with installed core/plugin API versions.
- `plugins/sh2-shp-survey-js`
  - reference official plugin release flow, plugin manifest, plugin artifact publishing, compatibility metadata.
- `plugins/sh2-plugin-registry`
  - current official plugin registry and the starting point for the unified official SelfHelp registry.
  - implementation must evolve/adapt this repository into the unified registry unless the team explicitly renames the one registry repository later.
  - do not create a second active registry in parallel.
- future installer/updater repository or package
  - should be `sh-manager`, the Docker-only connected SelfHelp Manager.

Implementation agents must not treat this as a backend-only plan. Before implementing the installer, updater, registry, compatibility resolver, or operation UI, inspect these concrete areas:

- `sh-manager`:
  - create this as a separate repository if it does not exist yet;
  - owns Docker access, first server bootstrap, inventory handling, generated compose files, resource preflights, backups, restores, clone, support bundles, proxy orchestration, and the manager CLI/web UI.
- `sh-selfhelp_backend`:
  - `src/Controller/Api/V1/HealthController.php` for the current public health probe;
  - plugin registry, lock, safe-mode, doctor, install/update/rollback code under `src/Plugin/`;
  - scheduled-job runner commands and services under `src/Command/` and `src/Service/Core/`;
  - Doctrine migrations and JSON schemas for any backend-facing contract changes.
- `sh-selfhelp_frontend`:
  - Next.js BFF URL model, admin system maintenance UI, plugin admin UI, and frontend Docker artifact behavior;
  - browser traffic must keep using `/api/*`, while server-side frontend code calls Symfony on the internal Docker network.
- `sh-selfhelp_shared`:
  - registry, manifest, lock, plugin, API, and compatibility types that are consumed by web, mobile, and plugins;
  - schema-parity tooling that must stay aligned with backend JSON schemas.
- `sh-selfhelp_mobile`:
  - mobile consumes backend/shared API contracts but is not installed by the server manager;
  - plugin and API compatibility must remain readable by mobile instance builds.
- `plugins/sh2-shp-survey-js`:
  - reference owned plugin release, manifest, artifact, and compatibility flow.
- `plugins/sh2-plugin-registry`:
  - current static plugin registry and signing/validation workflow;
  - adapt this repository into the unified SelfHelp registry containing core, frontend, scheduler, worker, official plugin, compatibility, advisory, signing, checksum, and schema metadata.
- `sh-selfhelp`:
  - old CMS reference only; do not edit it for new SelfHelp2 installer work.

Repository locations are environment-specific. Future agents should discover sibling repositories from the current workspace, provided paths, or local configuration, read every touched repository's `AGENTS.md`, then follow runtime code over this plan when they disagree.

Backend facts from the 2026-06-05 scan:

- `GET /cms-api/v1/health` exists through `src/Controller/Api/V1/HealthController.php` and migration `Version20260602091045.php`.
- The plugin layer already has:
  - registry sources through `plugin_sources`;
  - default seeded source `humdek-public`;
  - registry client and plugin install/update paths;
  - plugin lock file docs/schema;
  - plugin safe mode through env/config and `selfhelp:plugin:safe-mode`;
  - plugin health/admin routes.
- `config/services.yaml` currently defaults plugin registry URL to `https://humdek-unibe-ch.github.io/sh2-plugin-registry/`.
- The scheduled-jobs runner plan requires a Docker scheduler container per instance and should be treated as part of this distribution plan.

Because nothing has been officially released yet, restructuring is allowed. Prefer the clean target architecture over preserving old draft assumptions.

## SelfHelp Manager

Product/tool name: SelfHelp Manager.

Repository name: `sh-manager`.

Local path for this workspace:

```text
D:\TPF\SelfHelp\sh-manager
```

SelfHelp Manager is the official Docker-only connected installer, updater, and server manager. It is not part of the Symfony backend, not part of the Next.js frontend, and not a plugin. It is a separate server-level tool that manages one server and all SelfHelp Docker instances on that server.

Responsibilities:

- first server bootstrap;
- shared Traefik setup;
- server inventory management;
- single-instance installation;
- multi-instance installation;
- add/remove/list instances;
- update selected instance;
- backup/restore/clone selected instance;
- health checks;
- support bundles;
- registry fetch and signature verification;
- dependency resolution;
- manifest and lock file management.

The same manager is used for:

- simple one-instance SelfHelp installations;
- university/institution servers with multiple isolated SelfHelp instances.

For simple users, the manager provides a guided single-instance flow. For university sysadmins, the manager exposes advanced multi-instance operations.

### sh-manager Repository

Main decisions:

- Repository name: `sh-manager`
- Main language: Node.js / TypeScript
- Distribution: signed Docker image
- Production usage: run as a Docker container
- Development usage: clone the repo and run from source
- Git checkout is development-only, not the production update method
- The manager itself is updated by pulling a newer signed Docker image
- `sh-manager` is connected-only and requires internet access
- `sh-manager` manages one server and all SelfHelp instances on that server

Why Node.js / TypeScript:

- the SelfHelp frontend is already Next.js / TypeScript;
- registry, manifest, lock file, and compatibility schemas can share TypeScript contracts;
- it is well suited for a local graphical installer/manager web UI;
- it can also expose a CLI;
- it can call Docker and Docker Compose commands;
- it can validate JSON schemas;
- it keeps server-management logic separate from the Symfony CMS backend.

The Symfony backend remains responsible for CMS application logic. SelfHelp Manager is responsible for Docker/server installation, update, backup, restore, routing, and instance management.

Recommended repository structure:

```text
sh-manager/
  apps/
    cli/
      # command-line entrypoint
    web/
      # local graphical installer/manager UI
  packages/
    core/
      # shared install/update/orchestration logic
    docker/
      # Docker and Docker Compose operations
    registry/
      # official registry client, signature/checksum verification
    resolver/
      # dependency and compatibility resolver
    traefik/
      # shared Traefik setup and routing generation
    instances/
      # instance inventory, manifest, lock handling
    backup/
      # backup, restore, clone, export logic
    support/
      # support bundle and redaction logic
    schemas/
      # registry, manifest, lock, advisory, preflight schemas
    ui-shared/
      # shared UI/domain types where useful
  Dockerfile
  compose.dev.yaml
  README.md
  AGENTS.md
```

Production distribution uses signed Docker images. Production operators must not be required to install or update the manager with Git.

Example images:

```text
ghcr.io/humdek-unibe-ch/sh-manager:latest
ghcr.io/humdek-unibe-ch/sh-manager:1.0.0
ghcr.io/humdek-unibe-ch/sh-manager:1.1.0
```

Normal production run example:

```bash
docker run --rm -it \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v /opt/selfhelp:/opt/selfhelp \
  ghcr.io/humdek-unibe-ch/sh-manager:latest
```

Updating SelfHelp Manager:

```bash
docker pull ghcr.io/humdek-unibe-ch/sh-manager:latest
```

or later:

```bash
sh-manager self-update
```

The helper must still update by pulling and verifying the signed Docker image.

### Manager Modes

`sh-manager` supports two user-facing modes:

1. Simple mode
   - creates one SelfHelp instance;
   - hides multi-instance complexity;
   - recommended for normal CMS users who only need one installation.
2. Advanced/server mode
   - shows all installed instances;
   - allows adding more instances;
   - allows per-instance update, backup, restore, clone, remove, and health checks;
   - recommended for university/institution servers.

Both modes use the same architecture internally. Even a simple one-instance install still creates:

- server inventory;
- shared Traefik proxy;
- isolated instance folder;
- manifest;
- lock file.

### Manager Access And Authentication

SelfHelp Manager is managed through:

- a CLI for server operators;
- a localhost-only web wizard during bootstrap and repair;
- an optional persistent manager web UI for server administration.

The manager web UI is not the public SelfHelp CMS and must not be exposed to the open internet. Default behavior:

- bind the bootstrap web UI to `127.0.0.1`;
- use SSH port forwarding or a local server session for bootstrap where possible;
- disable/remove the bootstrap web UI after successful install;
- keep the CLI available for update, backup, restore, clone, remove, health, and support-bundle commands.

If a persistent manager web UI is enabled, it must be treated as a server operations console:

- expose it only on localhost, a private management network, or a VPN-only address;
- require TLS whenever it is reachable over a network;
- require manager authentication even when the UI is reachable only over VPN;
- reject public internet exposure in production MVP unless a later hardening decision explicitly changes this;
- record the bind host, public/private manager URL, authentication mode, and allowed operators in manager configuration.

The recommended production model for a university server is:

```text
Public internet
  -> SelfHelp instance domains only

VPN or private management network
  -> SelfHelp Manager web UI
```

VPN access is a network gate, not a replacement for login. The manager still needs user authentication, authorization, sessions, CSRF protection, audit logs, and short-lived admin operations.

Manager operator identities are separate from CMS users. A CMS administrator is not automatically a SelfHelp Manager operator. The manager may show instance/CMS status, and the CMS may request an update, but server-level Docker authority remains with the manager.

Manager authentication rules:

- Bootstrap creates the first manager operator through a one-time setup token, an interactive CLI command, or the first-run web wizard.
- Do not store raw manager passwords in `.env`.
- Environment variables may configure bind addresses, auth mode, external auth endpoints, trusted callback URLs, setup-token file paths, and secret file paths.
- Passwords and signing/session secrets must live in restricted secret files or an encrypted/hashed manager credential store under `/opt/selfhelp/manager`, not in plain environment variables.
- Local password authentication must store password hashes only.
- Manager roles should include at least `server_owner`, `instance_operator`, and `read_only`.
- Admin changes must be possible through CLI commands such as `sh-manager admin create`, `sh-manager admin disable`, `sh-manager admin role grant`, and `sh-manager admin allow-email add` once the command names are finalized.

Suggested manager config file:

```text
/opt/selfhelp/manager/selfhelp.manager.json
```

Suggested non-secret environment variables:

```dotenv
SELFHELP_MANAGER_BIND_HOST=127.0.0.1
SELFHELP_MANAGER_BIND_PORT=9741
SELFHELP_MANAGER_PUBLIC_URL=https://selfhelp-manager.example.ch
SELFHELP_MANAGER_AUTH_MODE=local
SELFHELP_MANAGER_CONFIG_PATH=/opt/selfhelp/manager/selfhelp.manager.json
SELFHELP_MANAGER_SESSION_SECRET_FILE=/opt/selfhelp/manager/secrets/session_secret
SELFHELP_MANAGER_BOOTSTRAP_TOKEN_FILE=/opt/selfhelp/manager/secrets/bootstrap_token
```

### Configurable Campus Login Provider For SelfHelp Manager

SelfHelp Manager may support a configurable campus login provider for installations such as UniBE.

This must not install, reuse, or depend on the old Symfony/PHP SelfHelp plugin at runtime.

The old plugin may only be inspected as a reference implementation to understand:

- redirect flow;
- callback handling;
- tenant/client configuration;
- identity attributes;
- email/domain extraction;
- error handling;
- logout/session behaviour, if relevant.

Reference implementation path:

```text
sh-selfhelp/server/plugins/sh-shp-auth_external
```

SelfHelp Manager must implement its own TypeScript authentication provider for manager operators.

The provider must be configurable so UniBE-specific tenant/client settings are not hard-coded.

Required configurable values should include, where applicable:

- provider enabled/disabled;
- provider display name;
- issuer / authority / tenant URL;
- client id;
- client secret file path, not raw secret in `.env`;
- redirect URI;
- post-logout redirect URI;
- allowed email domains;
- allowed individual emails;
- allowed groups or role claims;
- role mappings to manager roles;
- scopes;
- trusted callback URL;
- session lifetime.

Campus authentication proves identity only.

Authorization to use SelfHelp Manager must still come from explicit manager configuration:

- allowlisted email;
- allowed email domain;
- allowed group/claim;
- configured role mapping.

A campus-authenticated user who is not authorized as a manager operator must be rejected.

Manager roles remain separate from CMS roles.

A CMS admin is not automatically a SelfHelp Manager operator.

The provider must stay generic enough that another institution can configure its own campus/OIDC provider later without inheriting UniBE-specific hard-coding.

UniBE can be provided as one example configuration, not as hard-coded logic.

Example UniBE manager auth configuration shape:

```json
{
  "auth": {
    "mode": "campus",
    "providers": {
      "unibe": {
        "enabled": true,
        "displayName": "UniBE Campus Account",
        "issuer": "https://login.example.unibe.ch/",
        "clientId": "selfhelp-manager",
        "clientSecretFile": "/opt/selfhelp/manager/secrets/unibe_client_secret",
        "redirectUri": "https://selfhelp-manager.example.ch/auth/campus/unibe/callback",
        "postLogoutRedirectUri": "https://selfhelp-manager.example.ch/login",
        "allowedEmailDomains": ["unibe.ch"],
        "allowedEmails": [
          "admin@unibe.ch"
        ],
        "allowedGroups": [
          "selfhelp-manager-admins"
        ],
        "roleClaim": "groups",
        "roleMappings": [
          {
            "match": {
              "email": "admin@unibe.ch"
            },
            "roles": ["server_owner"]
          },
          {
            "match": {
              "group": "selfhelp-manager-admins"
            },
            "roles": ["instance_operator"]
          }
        ],
        "scopes": ["openid", "profile", "email"],
        "trustedCallbackUrl": "https://selfhelp-manager.example.ch/auth/campus/unibe/callback",
        "sessionLifetimeSeconds": 28800
      }
    }
  }
}
```

## First Server Bootstrap Flow

When `/opt/selfhelp/selfhelp.server.json` does not exist and no existing SelfHelp inventory can be read, SelfHelp Manager must decide whether the server is new or needs repair.

Treat the server as a new SelfHelp server only when `/opt/selfhelp` is missing or empty enough that no existing instance, proxy, compose project, Docker volume, or SelfHelp-managed network is detected.

Bootstrap must:

1. validate Docker access;
2. validate internet access and official registry access;
3. create `/opt/selfhelp`;
4. create `/opt/selfhelp/selfhelp.server.json`;
5. create `/opt/selfhelp/proxy`;
6. create the shared Docker proxy network;
7. install/start the SelfHelp-managed Traefik proxy when production routing is used;
8. create the first isolated SelfHelp instance;
9. write the first instance manifest and lock file;
10. run health checks;
11. show the final SelfHelp URL.

Validation details:

- Docker validation must check Docker daemon access, Docker Compose support, socket permissions, supported CPU architecture, and ability to inspect or pull a small image.
- Internet validation must check general outbound HTTPS and the official registry URL.
- Registry validation must verify registry signatures/trusted keys before selecting artifacts.
- Production routing validation must check ports 80/443, DNS for the target domain, and whether an existing SelfHelp-managed Traefik proxy can be reused.
- File creation must be atomic where possible. The manager should write temporary files first, validate them, then rename into place.

If `/opt/selfhelp` exists but the inventory file is missing, unreadable, or invalid, the manager must stop and offer a repair/import flow instead of overwriting existing data. The repair/import flow must inspect:

- existing `/opt/selfhelp/instances/*` folders;
- existing `selfhelp.instance.json` and `selfhelp.lock.json` files;
- existing Docker Compose projects with SelfHelp labels;
- existing Docker volumes and networks with SelfHelp labels;
- existing `/opt/selfhelp/proxy` files;
- the shared proxy network;
- reachable instance health endpoints.

Until repair/import succeeds, destructive operations are forbidden. The operator must explicitly choose whether discovered folders and Docker resources should be imported, ignored, or backed up before removal.

## Product Decisions

### Docker-Only Official Install

Supported MVP modes:

- production Docker install on a Linux server;
- local Docker testing mode on Windows Docker Desktop, macOS Docker Desktop, or Linux developer machines.

Out of scope for MVP:

- offline or air-gapped install;
- standalone `.zip`, `.tar`, PHP, Node, or shared-hosting install;
- production Windows Server install;
- Kubernetes/Helm;
- manually maintained Nginx/Caddy/Traefik config;
- production builds on the server.

The installer may be started by a small command or Docker one-liner, but it is a connected bootstrapper. It does not contain all SelfHelp artifacts.

### Connected Installer Only

A working internet connection is required to:

- fetch official registry metadata;
- pull Docker images;
- download compatible core/backend, frontend, scheduler, worker, Mercure, Redis, MySQL, and plugin artifacts;
- verify signatures and checksums;
- install SelfHelp;
- update SelfHelp;
- install or update plugins;
- fetch security advisories.

Offline/air-gapped installation is out of scope for MVP. Do not add offline bundle logic, local artifact import, or air-gapped documentation except a short "unsupported in MVP" note.

### One Official Registry

There must be exactly one official SelfHelp registry.

The existing `plugins/sh2-plugin-registry` repository is the starting point. The implementation task is to evolve/adapt this repository into the unified SelfHelp registry unless the team explicitly renames the one registry repository later.

Do not create a second active registry in parallel.

Do not keep separate production registries for core and plugins.

The unified registry must contain or generate metadata for:

- SelfHelp core/backend releases;
- frontend releases;
- scheduler releases;
- worker releases;
- official plugin releases;
- compatibility rules;
- dependency rules;
- migration metadata;
- security advisories;
- trusted signing keys;
- checksums and signatures;
- registry/manifest/lock/advisory schemas.

If the repository is renamed later, rename the one existing registry repository and update references. Do not leave the old plugin registry and a new core registry both active in production.

### Reproducible Experiments

SelfHelp is used for studies and experiments, so reproducibility is a product requirement.

The installer/updater must support installing:

- the latest compatible SelfHelp version;
- a specific older SelfHelp version;
- the latest compatible plugin version for the installed SelfHelp version;
- a specific older plugin version;
- a fully pinned instance lock file.

The registry must keep old compatible versions available. It may mark them as stale or security-affected, but it must not remove them from the compatibility graph unless there is a legal/security reason to block installation.

### Multi-Instance Server Model

A server may host up to about 10 SelfHelp instances.

Example:

```text
website1.example.ch -> selfhelp-instance-1
website2.example.ch -> selfhelp-instance-2
website3.example.ch -> selfhelp-instance-3
```

The only shared runtime component should be the reverse proxy/router. Everything else is per-instance.

Each SelfHelp instance must not share:

- database container;
- database volume;
- Redis container;
- Redis volume/config;
- Mercure secrets;
- JWT keys;
- `APP_SECRET`;
- uploaded files volume;
- plugin artifacts;
- scheduled-jobs runner;
- worker container;
- backend image version;
- frontend image version;
- scheduler image version;
- PHP/runtime version;
- MySQL version;
- Redis version;
- `.env` or secrets files;
- manifest;
- lock file.

Instances are security boundaries. One instance must not be able to read another instance's database, uploads, secrets, plugin artifacts, or lock file.

## Resource Planning And Limits

SelfHelp Manager must estimate resource usage before creating a new instance. This matters because one server may host up to about 10 isolated instances, and every instance has its own database, Redis, Mercure, backend, worker, scheduler, frontend, volumes, logs, and backups.

Before install, clone, restore, or add-instance, the manager should show:

- required disk space;
- expected RAM usage;
- expected CPU load class;
- number of containers;
- created Docker networks and volumes;
- backup storage path;
- estimated backup growth;
- warning when the server already hosts multiple instances.

The estimate does not need to be perfect for MVP, but it must be visible and conservative. It should include current server totals and available capacity:

- available disk space under `/opt/selfhelp`;
- available disk space for backup storage;
- total RAM and currently available RAM;
- current number of SelfHelp instances in the server inventory;
- current number of SelfHelp-managed containers;
- largest existing backup size, if available.

Generated compose files should support optional per-instance limits:

- memory limits;
- CPU limits;
- disk warning thresholds;
- log rotation limits.

The manager must warn before installing a new instance if available RAM or disk space is too low. If the warning crosses a hard safety threshold, for example the install root or backup path cannot hold the estimated minimum plus margin, the manager must stop unless an explicit documented override is added later.

Resource and limit settings should be recorded in the instance manifest as operator-facing configuration, and exact generated compose settings should be reproducible from the lock file plus manifest.

## Must-Not-Break Deployment Rules

### Frontend/BFF URL Rule

Browser requests must go through the frontend BFF using `/api/*`.

Server-side frontend code must call Symfony through the internal Docker network URL.

The installer must always generate:

- public frontend URL;
- public browser API prefix, normally `/api/*`;
- internal Symfony backend URL for frontend server-side code;
- Symfony API prefix;
- CORS/trusted-host/trusted-proxy values.

The operator must not be asked to manually guess these values.

This rule prevents many deployment bugs. Treat it as a hard deployment invariant.

### No Public Backend By Default

The backend container is private by default. It is attached to the instance network and reachable by the frontend, worker, scheduler, and updater. Browser clients reach backend APIs through the frontend/BFF path.

Public backend exposure is an advanced/debug mode only and must not be the default production output.

### No Production Builds On The Server

Production installs must pull ready-built, signed artifacts.

The production server must not run:

- `npm install`;
- `npm build`;
- Next.js frontend compilation;
- `composer install` from source;
- plugin frontend compilation;
- plugin backend package building.

Exception: explicit development mode for local testing, clearly separated from production mode.

### Scheduler Per Instance

Every SelfHelp instance must include a scheduled-jobs runner container.

The runner is responsible for:

- recurring CMS jobs;
- notification jobs;
- mail jobs;
- scheduled action jobs;
- plugin scheduled jobs where supported.

The runner is versioned with the backend/core release. It may use the same backend image with a scheduler command or a distinct scheduler image. Either way, the manifest and lock file must record the scheduler artifact separately.

## Target Runtime Architecture

Default production runtime:

```text
Internet
  |
  v
Shared Traefik reverse proxy
  |
  +-- website1.example.ch -> instance-website1 frontend
  |       |
  |       +-- internal /cms-api/* -> instance-website1 backend
  |
  +-- website2.example.ch -> instance-website2 frontend
          |
          +-- internal /cms-api/* -> instance-website2 backend
```

Per-instance containers:

- frontend/BFF;
- backend API;
- worker;
- scheduled-jobs runner;
- MySQL;
- Redis;
- Mercure;
- optional updater/helper container during install/update only.

Per-instance persistent state:

- database volume;
- Redis volume/config if needed;
- uploads/assets volume;
- plugin artifacts volume;
- generated config;
- generated secrets;
- `selfhelp.instance.json`;
- `selfhelp.lock.json`;
- backups;
- update logs;
- support-bundle staging directory.

The shared Traefik proxy is responsible for:

- receiving public HTTP/HTTPS traffic;
- managing TLS certificates;
- routing each domain to the correct instance frontend container;
- keeping backend and internal services private.

The reverse proxy is the only shared runtime component between SelfHelp instances.

## Instance Filesystem Layout

Recommended root:

```text
/opt/selfhelp/
  selfhelp.server.json
  proxy/
    compose.yaml
    traefik/
    letsencrypt/
  instances/
    website1/
      compose.yaml
      .env
      secrets/
      selfhelp.instance.json
      selfhelp.lock.json
      uploads/
      plugins/
      backups/
      logs/
      update-operations/
    website2/
      compose.yaml
      .env
      secrets/
      selfhelp.instance.json
      selfhelp.lock.json
      uploads/
      plugins/
      backups/
      logs/
      update-operations/
```

Rules:

- The installer generates unique Docker project names per instance.
- The installer generates unique networks and volumes per instance.
- The installer attaches only the frontend container to the shared proxy network.
- Backend, DB, Redis, Mercure, worker, and scheduler remain on the private instance network.
- Secret files must have restrictive permissions.
- The instance manifest and lock file must never contain raw secrets.

### Persistent State And Disposable Containers

Each instance has persistent state and disposable runtime containers.

Rules:

- MySQL runs as a separate per-instance Docker service.
- MySQL data is stored in a per-instance persistent Docker volume.
- Uploads/assets are stored in a per-instance persistent volume or bind-mounted folder.
- Uploads/assets must never live only inside the backend or frontend container filesystem.
- Plugin artifacts are stored in a per-instance persistent volume or bind-mounted folder.
- Backend, frontend, worker, and scheduler containers are disposable and may be replaced during install, update, rollback, or repair.
- Redis, Mercure, and MySQL containers may be replaced only when the selected SelfHelp release and runtime compatibility policy allow it.
- Updating containers must never delete DB data, uploads/assets, plugin artifacts, secrets, backups, logs, or operation logs.
- Normal updates must preserve uploads/assets.
- Full delete may delete uploads/assets, plugin artifacts, database volumes, backups, logs, and operation logs only after explicit operator confirmation.
- Logs are available through `docker compose logs`.
- Important manager operation logs are stored under `/opt/selfhelp/instances/<instanceId>/logs/` and `/opt/selfhelp/instances/<instanceId>/update-operations/`.

## Per-Instance Operator README

SelfHelp Manager must generate an operator README for every instance.

Suggested file:

```text
/opt/selfhelp/instances/<instanceId>/README.md
```

The README is for the server operator who needs exact commands without re-reading this architecture plan. It must be regenerated when the instance id, domain, compose project, backup path, manager command syntax, safe-mode command, or update flow changes.

It must include exact commands for:

- start instance;
- stop instance;
- restart instance;
- view logs;
- run health check;
- create backup;
- run update dry-run;
- update instance;
- create support bundle;
- enable safe mode;
- restore from backup.

Command shape for the generated README:

```bash
cd /opt/selfhelp/instances/<instanceId> && docker compose up -d
cd /opt/selfhelp/instances/<instanceId> && docker compose down
cd /opt/selfhelp/instances/<instanceId> && docker compose restart
cd /opt/selfhelp/instances/<instanceId> && docker compose logs -f --tail=200
sh-manager instance health <instanceId>
sh-manager instance backup <instanceId>
sh-manager instance update --dry-run <instanceId>
sh-manager instance update <instanceId>
sh-manager instance support-bundle <instanceId>
sh-manager instance safe-mode enable <instanceId>
sh-manager instance restore <instanceId> <backupId>
```

The final command syntax may change during `sh-manager` implementation, but the generated README must always contain the real commands for that installed manager version, not placeholders from this plan.

The README must also include:

- public SelfHelp URL;
- instance id and display name;
- manifest path;
- lock file path;
- compose file path;
- backup directory;
- support bundle output directory;
- health endpoint summary;
- note that secrets live in restricted secret files and are intentionally not printed.

The README must not contain secrets, tokens, passwords, JWT keys, Mercure secrets, database URLs with credentials, private signing keys, or admin reset links.

## Server Inventory

SelfHelp Manager must maintain one central server inventory file.

Suggested file:

```text
/opt/selfhelp/selfhelp.server.json
```

Example:

```json
{
  "inventoryVersion": 1,
  "serverId": "server-001",
  "manager": {
    "name": "SelfHelp Manager",
    "repository": "sh-manager",
    "version": "1.0.0"
  },
  "proxy": {
    "type": "traefik",
    "network": "selfhelp_proxy",
    "composePath": "/opt/selfhelp/proxy/compose.yaml"
  },
  "instances": [
    {
      "instanceId": "website1",
      "domain": "website1.example.ch",
      "path": "/opt/selfhelp/instances/website1",
      "composeProject": "selfhelp_website1",
      "status": "active"
    }
  ]
}
```

The inventory is used to:

- detect duplicate domains;
- list all instances;
- update one selected instance;
- backup/restore/clone one selected instance;
- remove one selected instance;
- create server-level support bundles;
- avoid accidentally touching the wrong Docker Compose project.

Rules:

- SelfHelp Manager writes the inventory atomically.
- Every add/remove/rename/domain-change operation updates the inventory.
- The inventory must never contain raw secrets.
- The inventory is the server-level index; per-instance manifest and lock files remain the source of truth for exact installed versions and artifact digests.
- If the inventory and instance folders drift, the manager must detect it and require repair or operator confirmation before destructive operations.

## Instance Manifest

Every instance must have a generated manifest file that records what was installed and how the instance is routed.

Suggested file: `selfhelp.instance.json`

Example:

```json
{
  "manifestVersion": 1,
  "instanceId": "website1",
  "displayName": "Website 1 Study",
  "domain": "website1.example.ch",
  "mode": "production",
  "createdAt": "2026-06-05T10:00:00+00:00",
  "updatedAt": "2026-06-05T10:00:00+00:00",
  "registry": {
    "id": "selfhelp-official",
    "url": "https://humdek-unibe-ch.github.io/sh2-plugin-registry/",
    "channel": "stable"
  },
  "versions": {
    "selfhelp": "1.4.2",
    "backend": "1.4.2",
    "frontend": "1.4.2",
    "scheduler": "1.4.2",
    "worker": "1.4.2",
    "pluginApi": "2.1"
  },
  "images": {
    "backend": "ghcr.io/humdek-unibe-ch/selfhelp-backend:1.4.2",
    "frontend": "ghcr.io/humdek-unibe-ch/selfhelp-frontend:1.4.2",
    "scheduler": "ghcr.io/humdek-unibe-ch/selfhelp-scheduler:1.4.2",
    "worker": "ghcr.io/humdek-unibe-ch/selfhelp-worker:1.4.2",
    "mysql": "mysql:8.4",
    "redis": "redis:7.2",
    "mercure": "dunglas/mercure:0.18"
  },
  "routing": {
    "publicFrontendUrl": "https://website1.example.ch",
    "browserApiPrefix": "/api",
    "internalSymfonyUrl": "http://website1-backend:8080",
    "symfonyApiPrefix": "/cms-api/v1"
  },
  "installedPlugins": [
    {
      "id": "survey-js",
      "version": "1.3.0"
    }
  ]
}
```

The manifest is used for:

- support;
- debugging;
- comparing instances;
- update planning;
- rollback display;
- disaster recovery;
- reproducing experiments later.

## Instance Lock File

Every instance must also have a lock file, similar to `composer.lock` or `package-lock.json`.

Suggested file: `selfhelp.lock.json`

The manifest explains what is installed in operator-friendly terms. The lock file pins exact artifacts and integrity data.

The lock file should include:

- exact SelfHelp core version;
- exact backend version;
- exact frontend version;
- exact scheduler version;
- exact worker version;
- exact image digests;
- exact MySQL/Redis/Mercure image digests;
- exact plugin versions;
- exact plugin artifact hashes;
- exact registry URL and registry entry ids;
- exact registry metadata version or ETag/hash used for the operation;
- exact DB migration version/range after install/update;
- exact plugin DB migration versions;
- exact signed release payload hashes;
- update operation id that wrote the lock.

Example:

```json
{
  "lockfileVersion": 1,
  "generatedAt": "2026-06-05T10:00:00+00:00",
  "registry": {
    "id": "selfhelp-official",
    "url": "https://humdek-unibe-ch.github.io/sh2-plugin-registry/",
    "metadataSha256": "sha256:..."
  },
  "core": {
    "version": "1.4.2",
    "backendImageDigest": "sha256:...",
    "frontendImageDigest": "sha256:...",
    "schedulerImageDigest": "sha256:...",
    "workerImageDigest": "sha256:...",
    "migrationVersion": "Version20260605081254",
    "pluginApiVersion": "2.1",
    "signedPayloadSha256": "sha256:..."
  },
  "services": {
    "mysql": {
      "image": "mysql:8.4",
      "digest": "sha256:..."
    },
    "redis": {
      "image": "redis:7.2",
      "digest": "sha256:..."
    },
    "mercure": {
      "image": "dunglas/mercure:0.18",
      "digest": "sha256:..."
    }
  },
  "plugins": {
    "survey-js": {
      "version": "1.3.0",
      "artifactSha256": "sha256:...",
      "signature": "base64-ed25519-detached-signature",
      "keyId": "humdek-2026-01",
      "compatibility": {
        "core": ">=1.2.0 <1.5.0",
        "pluginApi": "^2.0"
      }
    }
  }
}
```

Rules:

- The updater writes the lock atomically.
- The updater refuses to proceed if the lock and running state drift in unsafe ways.
- The registry may show newer versions, but the lock records exactly what this instance is allowed to run.
- A locked experiment should be reinstallable later if the artifacts remain in the registry.

## Official Registry Architecture

There is exactly one official SelfHelp registry.

The existing `plugins/sh2-plugin-registry` repository is the starting point for this registry. Adapt that repository into the unified registry. If the team later chooses the name `sh2-registry`, rename the one existing registry repository and update links; do not create or maintain a second active registry.

Suggested adapted repository/layout:

```text
plugins/sh2-plugin-registry/
  registry.json
  schemas/
    registry.schema.json
    core-release.schema.json
    frontend-release.schema.json
    scheduler-release.schema.json
    worker-release.schema.json
    plugin-release.schema.json
    compatibility.schema.json
    advisory.schema.json
    manifest.schema.json
    lock.schema.json
  core/
    releases/
      selfhelp-core-1.0.0.json
      selfhelp-core-1.1.0.json
    artifacts/
      1.0.0/
      1.1.0/
  frontend/
    releases/
      selfhelp-frontend-1.0.0.json
      selfhelp-frontend-1.1.0.json
    artifacts/
      1.0.0/
      1.1.0/
  scheduler/
    releases/
      selfhelp-scheduler-1.0.0.json
    artifacts/
  worker/
    releases/
      selfhelp-worker-1.0.0.json
    artifacts/
  plugins/
    official/
      survey-js/
        releases/
          survey-js-1.0.0.json
          survey-js-1.3.0.json
        artifacts/
  compatibility/
    rules.json
  advisories/
    advisories.json
    SHSA-2026-0001.json
  keys/
    trusted-keys.json
```

The top-level `registry.json` is an index. It should not duplicate every release payload. It points to versioned release JSON files and includes enough metadata for quick update checks.

Registry rules:

- There is one active production registry.
- The adapted `plugins/sh2-plugin-registry` repository is the source of truth unless the team explicitly renames that same repository later.
- Core, frontend, scheduler, worker, and plugin production metadata must live in the unified registry or be generated into it.
- Do not split core and plugin production metadata into separate active registries.
- Production accepts only the official registry by default.
- Development mode may point to a local/dev registry, but the UI must label it clearly.
- Registry metadata must be signed or verified through a signed release payload model.
- Artifact checksums must be verified before installation.
- The updater must never install unknown-source registry metadata in production.
- Old compatible versions must remain listed.
- Security-blocked versions may remain listed but must be marked as blocked unless an explicit override policy exists.

## Schema Version Compatibility

All registry, manifest, lock, advisory, release, inventory, and preflight files must include schema/version fields.

Examples:

- `schemaVersion`
- `manifestVersion`
- `lockfileVersion`
- `inventoryVersion`
- `preflightVersion`

SelfHelp Manager must:

- reject unknown major schema versions;
- tolerate compatible minor additions;
- ignore unknown optional fields when the schema says they are forward-compatible;
- show a clear error if the registry requires a newer manager version;
- refuse unsafe install/update/restore/clone/remove operations when schema compatibility cannot be verified;
- record the schema versions used in the operation log and support bundle.

Registry metadata should publish minimum manager compatibility:

```json
{
  "schemaVersion": "1.2",
  "requiresManager": ">=1.1.0 <2.0.0"
}
```

If the manager is too old to safely understand the registry, the UI should instruct the operator to update SelfHelp Manager first.

## Manager Self-Update Compatibility

Before install, update, restore, or clone, SelfHelp Manager must check whether its own version supports:

- the registry schema version;
- the server inventory version;
- the manifest version;
- the lock file version;
- the requested operation.

The compatibility check must run before writing files, pulling target artifacts, starting new instance containers, or mutating existing instances.

If the manager is too old, it must stop and instruct the operator to update SelfHelp Manager first. For production Docker usage, that means pulling and verifying a newer signed manager image, or running the supported `sh-manager self-update` helper once it exists.

The manager must record the result of this check in the operation log:

- manager version;
- supported schema versions;
- detected registry schema version;
- detected inventory version;
- detected manifest version;
- detected lock file version;
- requested operation;
- pass/fail result;
- recommended manager update version or range when known.

If the manager cannot verify compatibility because a schema version is missing, malformed, or signed by an unknown key, it must treat the operation as unsafe and stop. It may allow read-only display of the problem and support-bundle collection, but it must not install, update, restore, clone, or remove instances.

## Registry Unavailable Behavior

The installer is connected-only, but existing SelfHelp instances must keep running when the registry is temporarily unavailable.

If the official registry is unavailable:

- fresh install cannot continue;
- update checks fail gracefully;
- plugin install/update is unavailable;
- existing SelfHelp instances must continue running;
- admin UI must show the last successful registry check;
- SelfHelp Manager must show a clear error and retry option;
- health/status should distinguish `registry_unavailable` from local instance failure;
- cached registry metadata may be used only for read-only display and compatibility explanation, not for installing new artifacts unless signatures, checksums, and policy explicitly allow that later.

The manager should record:

- last successful registry check time;
- registry URL;
- metadata hash or ETag;
- error message from the failed check;
- whether the failure blocks install/update/plugin operations.

## Registry Release Metadata

Core release entry example:

```json
{
  "kind": "selfhelp-core-release",
  "id": "selfhelp-core",
  "version": "1.5.0",
  "channel": "stable",
  "releasedAt": "2026-06-05T10:00:00+00:00",
  "minimumDirectUpgradeFrom": "1.3.0",
  "pluginApiVersion": "2.2",
  "backend": {
    "image": "ghcr.io/humdek-unibe-ch/selfhelp-backend:1.5.0",
    "digest": "sha256:...",
    "phpVersion": "8.4"
  },
  "worker": {
    "image": "ghcr.io/humdek-unibe-ch/selfhelp-worker:1.5.0",
    "digest": "sha256:..."
  },
  "scheduler": {
    "image": "ghcr.io/humdek-unibe-ch/selfhelp-scheduler:1.5.0",
    "digest": "sha256:..."
  },
  "frontendCompatibility": {
    "requiredFrontendRange": ">=1.5.0 <1.6.0"
  },
  "database": {
    "migrationRange": "20260601000000-20260605000000",
    "destructive": false,
    "requiresBackup": true,
    "manualConfirmationRequired": false,
    "minimumSafeRollbackPoint": "before_migrations"
  },
  "artifacts": {
    "sbom": {
      "url": "core/artifacts/1.5.0/SBOM.spdx.json",
      "sha256": "sha256:..."
    }
  },
  "security": {
    "signature": "base64-ed25519-detached-signature",
    "keyId": "humdek-2026-01",
    "signedPayloadSha256": "sha256:..."
  }
}
```

Frontend release entry example:

```json
{
  "kind": "selfhelp-frontend-release",
  "id": "selfhelp-frontend",
  "version": "1.5.0",
  "channel": "stable",
  "image": "ghcr.io/humdek-unibe-ch/selfhelp-frontend:1.5.0",
  "digest": "sha256:...",
  "builtFrom": {
    "nextStandalone": true,
    "sharedPackageVersion": "1.5.0"
  },
  "backendCompatibility": {
    "requiredCoreRange": ">=1.5.0 <1.6.0",
    "requiredApiVersion": "v1"
  },
  "security": {
    "signature": "base64-ed25519-detached-signature",
    "keyId": "humdek-2026-01"
  }
}
```

Plugin release entry example:

```json
{
  "kind": "selfhelp-plugin-release",
  "id": "survey-js",
  "version": "1.9.3",
  "channel": "stable",
  "official": true,
  "compatibility": {
    "core": ">=1.2.0 <1.4.0",
    "pluginApi": "^2.0"
  },
  "dependencies": {
    "plugins": []
  },
  "artifacts": {
    "manifestUrl": "plugins/official/survey-js/releases/survey-js-1.9.3.json",
    "archiveUrl": "plugins/official/survey-js/artifacts/survey-js-1.9.3.shplugin",
    "sha256": "sha256:..."
  },
  "security": {
    "signature": "base64-ed25519-detached-signature",
    "keyId": "humdek-2026-01"
  }
}
```

## Compatibility And Dependency Resolver

The installer/updater needs a small dependency resolver.

It must understand:

- installed SelfHelp core version;
- target SelfHelp core version;
- installed frontend version;
- target frontend version;
- plugin API version;
- installed plugin versions;
- latest plugin versions;
- plugin compatibility constraints;
- plugin dependencies;
- security advisories;
- destructive migration metadata.

Example:

```text
Plugin survey-js latest version: 2.4.0
Current SelfHelp version: 1.2.0
Latest compatible survey-js version: 1.9.3

Message:
A newer survey-js version exists, but it requires SelfHelp >= 1.4.0.
You can install survey-js 1.9.3, which is compatible with your current SelfHelp version.
```

Required resolver actions:

- install latest compatible SelfHelp version;
- install specific SelfHelp version;
- update to latest compatible plugin versions;
- install specific older plugin versions;
- explain why a newer version is unavailable;
- block unsafe updates;
- suggest alternatives when possible.

Update blocked example:

```text
Update blocked.
Plugin survey-js 1.3.0 is not compatible with SelfHelp 1.6.0.
Available options:
- update survey-js first;
- install SelfHelp 1.5.2 instead;
- keep current version.
```

## Security Advisories

The official registry must include security advisories.

Advisory metadata should include:

- advisory id;
- affected core versions;
- affected frontend versions if relevant;
- affected plugin versions;
- severity;
- fixed versions;
- whether exploitation is known;
- recommended action;
- whether installation/update should be blocked;
- human-readable details URL.

Example:

```json
{
  "id": "SHSA-2026-0001",
  "severity": "high",
  "affected": [
    {
      "kind": "plugin",
      "id": "survey-js",
      "versions": ">=1.3.0 <1.3.2"
    }
  ],
  "fixed": [
    {
      "kind": "plugin",
      "id": "survey-js",
      "version": "1.3.2"
    }
  ],
  "recommendedAction": "Update survey-js to 1.3.2.",
  "blocked": false
}
```

The system admin UI should show security advisories separately from normal updates.

## Trust And Signing Model

This is non-negotiable from day one.

Production updater must never install:

- unsigned core releases;
- unsigned frontend releases;
- unsigned scheduler/worker artifacts;
- unsigned plugin releases;
- artifacts with wrong checksums;
- registry metadata from an unknown source;
- artifacts signed by untrusted keys.

Required:

- signed release manifests;
- signed plugin manifests or signed canonical payloads;
- checksums for every artifact;
- trusted key list from the official registry bootstrap or installer config;
- key rotation plan;
- SBOMs for backend/frontend/scheduler/worker images;
- vulnerability scan in release CI;
- no secrets in logs.

Development mode may allow unsigned local artifacts, but:

- it must be explicitly enabled;
- the UI/CLI must show a warning;
- production mode must reject the same artifact.

## Frontend Distribution

The frontend is distributed as a versioned production artifact.

Recommended:

- CI builds the Next.js app using standalone output.
- CI packages it into a signed Docker image.
- The official registry points to the image tag, image digest, SBOM, checksum/signature, and compatibility metadata.
- The installer/updater pulls the ready image.

Production installer/updater must not run npm install/build on the server.

The frontend image must support runtime configuration for:

- public frontend URL;
- browser API prefix `/api`;
- internal Symfony URL;
- Symfony API prefix;
- Mercure public URL;
- feature flags/maintenance mode display.

The BFF public/internal URL rule is a must-not-break invariant.

## Backend, Worker, And Scheduler Distribution

The backend/core release should publish:

- backend API image;
- worker image or backend image reused with worker command;
- scheduler image or backend image reused with scheduler command;
- migration metadata;
- health/readiness contract version;
- plugin API version;
- signed release payload;
- SBOM and provenance.

Every generated instance compose stack must include:

- backend container;
- worker container;
- scheduled-jobs runner container;
- Redis container;
- MySQL container;
- Mercure container;
- frontend container.

Scheduler command concept:

```yaml
scheduler:
  image: ghcr.io/humdek-unibe-ch/selfhelp-scheduler:${SELFHELP_VERSION}
  restart: unless-stopped
  env_file:
    - .env
  command: >
    sh -lc "while true; do
      php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction;
      sleep $${SCHEDULED_JOBS_TICK_SECONDS:-60};
    done"
```

If the scheduler image is the backend image with a different command, record it as the scheduler artifact anyway in the manifest and lock file.

## Reverse Proxy And Domain Routing Model

SelfHelp uses one shared reverse proxy per server. The default reverse proxy for Docker installations is Traefik.

The installer must automatically manage the default Traefik reverse proxy.

For normal Docker installations, the operator must not manually write Traefik, Nginx, or Caddy configuration.

The installer must:

- install/start Traefik if missing;
- reuse the existing SelfHelp-managed Traefik proxy if already installed;
- create the shared proxy network;
- configure HTTP/HTTPS entrypoints;
- configure Let's Encrypt when production HTTPS is selected;
- attach each SelfHelp frontend container to the proxy network;
- generate routing labels for the selected domain;
- verify that the domain resolves to the server;
- verify that ports 80 and 443 are reachable;
- prevent duplicate domain assignment.

The installer must generate:

- Traefik route for the instance domain;
- HTTPS/TLS configuration;
- public frontend URL;
- internal Symfony backend URL;
- BFF API prefix;
- unique Docker project name;
- unique networks, volumes, and secrets per instance.

Before production installation, the installer must validate:

- the domain resolves to this server;
- ports 80 and 443 are available;
- the domain is not already assigned to another SelfHelp instance;
- the instance name is unique;
- enough RAM and disk are available.

Production mode requires a real domain pointing to the server.

## Local Docker Testing Mode

The installer must support a local Docker testing mode.

Local mode is intended for:

- Windows Docker Desktop;
- macOS Docker Desktop;
- Linux developer machines.

Local mode must be able to expose each SelfHelp instance on a unique localhost port, for example:

```text
http://localhost:8081 -> local instance 1
http://localhost:8082 -> local instance 2
```

Local mode must not require:

- real DNS;
- public HTTPS;
- Let's Encrypt;
- production TLS validation.

Local mode still uses Docker and still creates isolated per-instance containers, volumes, networks, secrets, manifests, lock files, workers, and scheduled-jobs runners.

## Docker Compose Stack Shape

Each instance gets its own generated compose file.

Conceptual service list:

```yaml
services:
  frontend:
    image: ${SELFHELP_FRONTEND_IMAGE}
    restart: unless-stopped
    env_file:
      - .env
    networks:
      - instance
      - selfhelp_proxy
    labels:
      - traefik.enable=true
      - traefik.http.routers.${INSTANCE_ID}.rule=Host(`${PUBLIC_DOMAIN}`)

  backend:
    image: ${SELFHELP_BACKEND_IMAGE}
    restart: unless-stopped
    env_file:
      - .env
    networks:
      - instance
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy

  worker:
    image: ${SELFHELP_WORKER_IMAGE}
    restart: unless-stopped
    env_file:
      - .env
    networks:
      - instance

  scheduler:
    image: ${SELFHELP_SCHEDULER_IMAGE}
    restart: unless-stopped
    env_file:
      - .env
    networks:
      - instance

  mysql:
    image: ${SELFHELP_MYSQL_IMAGE}
    restart: unless-stopped
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - instance

  redis:
    image: ${SELFHELP_REDIS_IMAGE}
    restart: unless-stopped
    networks:
      - instance

  mercure:
    image: ${SELFHELP_MERCURE_IMAGE}
    restart: unless-stopped
    networks:
      - instance

networks:
  instance:
  selfhelp_proxy:
    external: true

volumes:
  mysql_data:
  uploads:
  plugin_artifacts:
```

This is a concept, not final syntax. The generated compose must use exact image digests from the lock file where possible.

Generated Docker Compose files must configure log rotation for every long-running container.

Example:

```yaml
logging:
  driver: json-file
  options:
    max-size: "10m"
    max-file: "5"
```

This prevents Docker logs from filling the server disk. Apply this to frontend, backend, worker, scheduler, MySQL, Redis, Mercure, Mailpit in local mode, Traefik, and any long-running manager service.

## Docker Host Access Security

SelfHelp Manager requires Docker daemon access to create and manage Docker Compose stacks.

For Docker installations, this normally means mounting:

```text
/var/run/docker.sock
```

Docker socket access is powerful and dangerous. Rules:

- Docker socket access is allowed only for SelfHelp Manager installer/updater containers.
- Normal backend, frontend, worker, scheduler, database, Redis, Mercure, and plugin runtime containers must not mount the Docker socket.
- The running Symfony app must not directly control Docker.
- The CMS admin UI may request or approve an update, but SelfHelp Manager executes Docker/image/config/migration operations.
- The manager container should be short-lived where possible.
- If a long-running manager API exists later, it must be internal-only and strongly authenticated.
- Support bundles must report whether any unexpected SelfHelp runtime container has Docker socket access.

## Installer Security

The installer must bind to localhost only by default.

Remote installer access is disabled by default.

Remote access requires:

- explicit operator confirmation;
- one-time access token;
- short expiration time;
- clear warning in the installer UI;
- CSRF/session protection.
- network restriction to localhost, VPN, or a private management network.

After successful installation, the installer container/service must be stopped and removed or disabled.

If a persistent manager web UI is enabled after installation, it is not considered installer access. It must still remain private, authenticated, and server-operator-only. It must not be routed through the public SelfHelp instance domains.

Additional rules:

- Installer tokens rotate after use.
- Secrets are never printed by default.
- Logs are redacted.
- Review screens are redacted.
- The installer never stores admin DB credentials after setup.
- The installer writes least-privilege application DB credentials only.
- Generated recovery bundles are clearly marked sensitive and should support encryption with an operator passphrase.

## Installer Wizard

The installer UI should be a guided web wizard with resumable progress. Every step validates before the operator can continue.

When the manager starts on a server without a valid `/opt/selfhelp/selfhelp.server.json`, it must run the First Server Bootstrap Flow before the normal instance wizard. If `/opt/selfhelp` exists but cannot be trusted, the wizard must switch to repair/import mode and must not continue as a fresh install.

### Step 1: Welcome And Mode

Collect:

- production or local Docker testing mode;
- new instance or update existing instance;
- release channel, normally stable;
- target version, latest compatible by default or specific version for reproducibility.

Validation:

- Docker is available;
- installer can access the internet;
- installer can reach the official registry;
- installer can pull a tiny test image or inspect Docker daemon access;
- CPU architecture is supported;
- install root is writable.
- manager version supports the registry, inventory, manifest, lock file, and requested operation schema versions.

Do not offer connected/offline selection. The installer is always connected.

### Step 2: Instance And Domain

Collect:

- instance id;
- display name;
- production domain or local port;
- admin email for first admin;
- optional release channel.

Validation:

- instance id is unique;
- domain is unique across installed instances;
- production domain resolves to this server;
- ports 80 and 443 are available in production mode;
- local port is free in local mode.

### Step 3: Registry And Version Selection

Actions:

- fetch official registry metadata;
- verify registry signatures/trusted keys;
- list available SelfHelp versions;
- default to latest compatible stable version;
- allow specific older SelfHelp version selection;
- show security warnings for selected version;
- compute matching backend/frontend/worker/scheduler artifacts.

The installer should show why a version is unavailable, for example incompatible architecture or blocked security advisory.

### Step 4: Reverse Proxy

Actions:

- install/start SelfHelp-managed Traefik if missing;
- reuse existing SelfHelp-managed Traefik if present;
- create shared proxy network if missing;
- configure HTTP/HTTPS entrypoints;
- configure Let's Encrypt in production mode;
- prepare labels for the new instance frontend.

Operator must not manually write reverse proxy config.

### Step 5: Isolated Services

Generate per-instance services:

- MySQL;
- Redis;
- Mercure;
- backend;
- worker;
- scheduled-jobs runner;
- frontend.

Mail transport defaults:

- Local Docker testing mode:
  - default mail transport is Mailpit;
  - expose Mailpit UI on a unique local port;
  - no real outgoing email.
- Production mode:
  - offer institutional SMTP relay without auth;
  - offer authenticated SMTP;
  - offer disabled mail.

Production mail must be explicit. The installer should test SMTP when configured, but it must not send real email in local mode.

Generate per-instance secrets:

- `APP_SECRET`;
- JWT keypair/passphrase;
- Mercure secrets;
- database application password;
- Redis password if used;
- installer handoff token;
- plugin signing/trust config where needed.

Rules:

- No service container is shared between instances except Traefik.
- No database is shared between instances.
- External DB selection is not part of the MVP.

### Step 6: First Admin User

Collect:

- admin email;
- display name;
- password;
- optional enforced 2FA setup after first login.

Installer action:

- use backend command such as `app:create-admin-user`;
- validate password policy before running the command;
- confirm the user belongs to the seeded admin group/role.

### Step 7: Optional Plugins

Offer only official registry plugins compatible with the selected SelfHelp version.

Behavior:

- show latest plugin version;
- show latest compatible plugin version;
- allow specific older compatible plugin version;
- warn if latest plugin requires a newer SelfHelp version;
- install plugins only after core is healthy.

Core install must succeed without plugins.

### Step 8: Review

Show a redacted summary:

- instance id;
- domain/local URL;
- selected SelfHelp version;
- backend/frontend/scheduler/worker image tags and digests;
- MySQL/Redis/Mercure versions;
- plugin selection;
- install directory;
- backup path;
- resource estimate;
- optional resource limits;
- admin email;
- generated public/internal URL model;
- generated operator README path;
- warning that the installer will be removed/disabled after success.

Require explicit confirmation before writing files or starting containers.

### Step 9: Install Progress

Run visible, resumable operations:

1. Write instance directories.
2. Write redacted config and secret files.
3. Write initial manifest and lock draft.
4. Pull images by digest.
5. Verify signatures/checksums.
6. Create Docker networks and volumes.
7. Start MySQL/Redis/Mercure.
8. Start backend in install/maintenance mode.
9. Generate JWT keys if not already generated.
10. Run Doctrine migrations.
11. Run schema validation.
12. Clear and warm caches.
13. Clear/warm dynamic API route cache.
14. Create admin user.
15. Install selected compatible official plugins.
16. Start frontend, worker, and scheduled-jobs runner.
17. Attach frontend to Traefik route.
18. Run health checks.
19. Write final manifest and lock file atomically.
20. Generate the per-instance operator README.
21. Disable/remove installer.
22. Redirect to the new SelfHelp instance.

### Step 10: Finish

Show:

- SelfHelp URL;
- first-login instructions;
- backup location;
- manifest path;
- lock file path;
- operator README path;
- where compose/env files live;
- how to restart/stop/update;
- how to collect a support bundle;
- confirmation that installer access is disabled.

## Sysadmin Domain Installation Flow

1. Sysadmin creates a DNS A/AAAA record pointing the desired domain to the server.
2. Sysadmin starts the Docker-only SelfHelp installer.
3. Installer starts a localhost-only web UI.
4. Sysadmin enters the instance name and public domain.
5. Installer verifies DNS, ports, Docker, registry access, and existing instances.
6. Installer installs or reuses the shared Traefik reverse proxy.
7. Installer creates an isolated Docker Compose stack for the new SelfHelp instance.
8. Installer assigns Traefik labels so the domain routes to the instance frontend.
9. Installer requests/validates HTTPS certificates.
10. Installer runs migrations, creates admin user, starts workers and scheduled jobs.
11. Installer performs health checks.
12. Installer writes manifest and lock file.
13. Installer disables/removes itself.
14. Sysadmin is redirected to the new SelfHelp instance.

## Remove Instance Flow

SelfHelp Manager must support removing one SelfHelp instance without touching other instances.

The UI/CLI must clearly distinguish:

- disable instance;
- remove containers but keep data;
- full delete including volumes/backups.

Removal must:

- identify the selected instance from `selfhelp.server.json`;
- load and display the selected instance manifest and lock file;
- offer backup/export before destructive deletion;
- stop only the selected instance containers;
- remove only that instance Docker Compose project;
- remove only that instance Traefik route/labels;
- keep or delete volumes based on operator choice;
- keep or delete backups based on operator choice;
- remove the instance inventory entry after the operation succeeds;
- never remove the shared Traefik proxy if other instances still exist;
- verify no other instance uses the same Docker networks, volumes, domain, or compose project before destructive actions.

Modes:

- Disable instance:
  - stop frontend/backend/worker/scheduler containers;
  - keep compose files, volumes, backups, manifest, lock, and inventory entry;
  - mark inventory status as `disabled`.
- Remove containers but keep data:
  - stop and remove the selected compose project containers/networks;
  - keep DB/uploads/plugin volumes, backups, manifest, lock, and instance folder;
  - mark inventory status as `removed_keep_data` or similar.
- Full delete:
  - require explicit typed confirmation;
  - require backup/export prompt;
  - stop and remove containers/networks;
  - delete selected instance volumes only if operator confirms;
  - delete selected instance backups only if operator confirms;
  - delete selected instance folder only after backup/export and confirmation;
  - remove inventory entry.

The remove flow must never delete another instance's volumes, secrets, plugin artifacts, routes, backups, or lock files.

## Restore Flow

SelfHelp Manager must support restoring an instance from a backup.

Restore must:

- select source backup;
- verify backup integrity;
- verify compatible SelfHelp artifacts are available;
- verify registry metadata/signatures/checksums for artifacts needed by the restored lock file;
- stop the target instance if restoring over an existing instance;
- restore database;
- restore uploads/assets;
- restore plugin artifacts or reinstall from lock file;
- restore manifest and lock file;
- preserve secrets when restoring the same instance;
- generate new secrets when restoring as a clone;
- run migrations only when required by the restored version;
- update or preserve the server inventory as appropriate;
- run health checks after restore.

Restore modes:

- Restore same instance:
  - keeps instance id, domain/local port, secrets, Docker project name, and inventory identity;
  - restores data/config/artifacts from the selected backup;
  - requires confirmation if the current instance data will be overwritten.
- Restore as clone:
  - behaves like Clone Instance Flow;
  - requires new instance id and domain/local port;
  - generates new secrets and isolated Docker state.

Safety rules:

- The manager must never restore a backup into the wrong instance without explicit confirmation.
- The manager must verify that the backup's manifest and lock match the selected restore mode.
- If the backup references artifacts no longer available in the official registry, restore must stop with a clear error unless all required artifacts are present in the backup and signature/checksum verification passes.
- If the backup contains a security-blocked version, the manager must warn and follow the security policy before restoring.
- Restore logs must record backup id/path, source instance id, target instance id, artifact versions, migration actions, and health-check result.

## Clone Instance Flow

SelfHelp Manager should support cloning an existing instance into a new isolated instance.

Clone must:

- create a new instance id;
- require a new domain or local port;
- copy database, uploads, and plugin artifacts;
- preserve core/plugin versions from the source lock file;
- verify compatible SelfHelp artifacts are available;
- generate new `APP_SECRET`, JWT keys, Mercure secrets, DB password, and Redis password;
- create new Docker project, networks, volumes, manifest, and lock file;
- update the server inventory;
- run health checks.

Clone rules:

- The clone must not share database volume, Redis, Mercure, uploads, plugin artifacts, secrets, Docker project, manifest, or lock file with the source.
- The clone should preserve application content and plugin versions for reproducibility.
- The clone should generate new runtime identity and secrets so it becomes a separate security boundary.
- The clone must not reuse the source domain, local port, or Traefik route.
- The source instance must continue running unless the operator explicitly chooses to stop it.
- The manager must show clear warnings if cloning participant data or sensitive uploads.

Clone use cases:

- reproduce an experiment with the same versions;
- create a staging copy before an update;
- move from local test to production-like isolated instance;
- debug an issue without touching the live instance.

## Update Architecture

## Existing Instance Update With Runtime Service Changes

New SelfHelp installations start with the selected current backend, frontend, worker, scheduler, MySQL, Redis, and Mercure versions.

Existing instances must be upgraded in place.

SelfHelp Manager must preserve existing persistent state during updates.

Persistent state includes:

- MySQL data volume;
- uploads/assets volume;
- plugin artifact volume;
- backup directory;
- secrets directory;
- manifest and lock history;
- logs directory;
- update operation logs.

The updater may replace containers and images, but it must not delete persistent volumes, bind-mounted state, secrets, backups, logs, or operation logs unless the operator explicitly runs the destructive full-delete instance flow.

Updating an existing instance means:

1. read current `selfhelp.instance.json` and `selfhelp.lock.json`;
2. fetch and verify official registry metadata;
3. resolve target SelfHelp version;
4. resolve required backend, frontend, worker, scheduler image digests;
5. resolve required runtime service versions, including MySQL, Redis, Mercure, and Traefik where relevant;
6. check whether the current MySQL version is still supported by the target SelfHelp version;
7. check whether the target SelfHelp version requires a MySQL image update;
8. create and verify a consistent backup;
9. enter maintenance mode;
10. stop frontend, backend, worker, and scheduler;
11. update generated compose image digests;
12. if required, replace the MySQL container image while keeping the same MySQL data volume;
13. start MySQL and wait for health;
14. run database engine compatibility checks;
15. start backend in migration/update mode;
16. run Doctrine migrations against the existing database;
17. clear and warm backend cache and route cache;
18. start frontend, worker, and scheduler;
19. run full health checks and smoke checks;
20. write updated manifest and lock file atomically;
21. exit maintenance mode;
22. record operation logs.

The updater must distinguish between:

- replacing application containers;
- replacing runtime service containers;
- migrating the existing database schema/data with Doctrine migrations;
- deleting persistent state.

Only the last one is destructive and must never happen during a normal update.

## MySQL Volume Preservation Rule

During update, SelfHelp Manager must never remove the MySQL data volume.

A MySQL image update must reuse the existing instance MySQL data volume.

MySQL image updates must be handled by SelfHelp Manager, not by the CMS or manual runtime container edits. The manager must create and verify a backup, check runtime compatibility, replace only the container image, wait for MySQL health, and run post-update health checks before completing the operation.

The updater must not run destructive Docker commands such as:

```bash
docker compose down -v
docker volume rm <instance_mysql_volume>
```

## Runtime Service Version Policy

The official registry must define runtime service compatibility for every SelfHelp release.

Runtime services include:

- MySQL;
- Redis;
- Mercure;
- Traefik;
- PHP runtime inside the backend/worker/scheduler images.

For MySQL, Redis, Mercure, and Traefik, the registry should define:

- minimum supported version;
- maximum supported version range;
- recommended version;
- image tag;
- image digest;
- whether update is required;
- whether update is optional/recommended;
- whether manual approval is required;
- whether rollback is supported;
- notes for major upgrades.

Example:

```json
{
  "selfhelp": "1.5.0",
  "runtime": {
    "php": {
      "backendImagePhpVersion": "8.4"
    },
    "mysql": {
      "supportedVersions": ">=8.0 <9.0",
      "minimumRequired": "8.0",
      "recommendedVersion": "8.4",
      "recommendedImage": "mysql:8.4",
      "recommendedDigest": "sha256:...",
      "updateRequired": false,
      "majorUpgradeRequiresManualApproval": true
    },
    "redis": {
      "supportedVersions": ">=7.0 <8.0",
      "recommendedImage": "redis:7.2",
      "recommendedDigest": "sha256:..."
    },
    "mercure": {
      "supportedVersions": ">=0.18 <0.19",
      "recommendedImage": "dunglas/mercure:0.18",
      "recommendedDigest": "sha256:..."
    }
  }
}
```

SelfHelp Manager must distinguish:

- required runtime service update;
- optional/recommended runtime service update;
- blocked runtime service update.

The manager must never update MySQL, Redis, Mercure, or Traefik outside the supported range for the selected SelfHelp version.

MySQL major upgrades require:

- verified backup;
- explicit manual approval;
- maintenance mode;
- health checks after upgrade;
- operation log entry.

## PHP Runtime Update Rule

The PHP version is part of the backend, worker, and scheduler Docker images.

Updating PHP means replacing the backend, worker, and scheduler images with new signed image digests.

SelfHelp Manager does not install PHP directly on the host server.

Different SelfHelp instances on the same server may run different PHP versions because each instance has its own backend, worker, and scheduler containers.

Example:

- instance A: SelfHelp 1.4, backend image with PHP 8.3, MySQL 8.0;
- instance B: SelfHelp 1.5, backend image with PHP 8.4, MySQL 8.4.

This is allowed and expected.

The lock file must record the exact backend, worker, and scheduler image digests.

The running Symfony app should not be solely responsible for replacing itself.

Use SelfHelp Manager as the external updater:

- manager container;
- manager CLI in update mode;
- or a protected internal manager API for the final target.

The admin UI can request, authorize, and monitor updates. The updater executes Docker/image/config/migration changes.

Update flow:

1. Read instance manifest and lock.
2. Fetch official registry metadata.
3. Verify registry signatures.
4. Resolve compatible target versions.
5. Run update dry-run/preflight.
6. Show update plan, plugin impacts, migration risk, backup requirement, estimated downtime, and rollback level.
7. Require operator approval.
8. Create backup and config/container snapshots.
9. Enter maintenance mode.
10. Pull target images by digest.
11. Verify checksums/signatures.
12. Stop frontend/worker/scheduler as needed.
13. Start backend/updater migration mode.
14. Run migrations.
15. Warm caches and route cache.
16. Start backend/frontend/worker/scheduler.
17. Run health checks and smoke checks.
18. Write manifest and lock atomically.
19. Exit maintenance mode.
20. Record operation logs.

## CMS System Update UI

The SelfHelp CMS admin UI must include a system maintenance/update area.

The CMS UI may show:

- currently installed SelfHelp/core version;
- backend version;
- frontend version;
- scheduler version;
- worker version;
- installed plugin versions;
- latest available compatible plugin versions;
- latest available SelfHelp/core version;
- security advisories;
- whether a newer plugin version is blocked because the current SelfHelp/core version is too old;
- whether the CMS/core must be updated before a plugin can be updated;
- update dry-run result;
- required backup/migration warnings;
- update operation history.

The CMS UI must not directly access Docker, mutate server files, replace its own containers, or update generated compose/manifest/lock files.

Instead, the CMS UI sends an authenticated update request to SelfHelp Manager, or records an update request that SelfHelp Manager can execute.

SelfHelp Manager remains the only component allowed to:

- pull Docker images;
- verify registry signatures and checksums;
- update compose files;
- update manifest and lock files;
- run database migrations;
- update plugins;
- restart containers;
- rollback after failed update.

The CMS UI can be used as the human-friendly control surface, but the actual execution must happen through SelfHelp Manager commands or Manager API calls.

### CMS Admin Instance-Scoped Update Authorization

A CMS admin may only request, approve, monitor, or cancel update operations for the SelfHelp instance they are currently authenticated in.

The CMS update UI is always instance-scoped.

The current instance id must be derived from trusted server-side configuration, not from a browser-provided value.

The frontend must not send arbitrary `instanceId` values for update execution.

The backend must not trust user-provided `instanceId`.

SelfHelp Manager must verify that every CMS-originated update request belongs to the same registered instance before accepting it.

Cross-instance update requests must be denied and logged as security events.

CMS admins are not global server operators.

A CMS admin in `website1` must not see, request, approve, monitor, cancel, or execute updates for `website2`.

Only SelfHelp Manager operators in the private Manager UI/CLI may manage multiple instances.

MVP behavior:

- The CMS may prepare the update and instruct the operator to run a manager command.
- The CMS can show preflight data if it can read manager-generated files or backend-provided status.
- The operator executes the manager CLI/container with Docker access.

Final target:

- The CMS can trigger the manager through a protected internal manager API or update-job channel.
- The manager executes the update and streams progress/status back.

## Manager API Security

If the CMS can trigger updates through the manager, the manager API must:

- be internal-only, not publicly exposed;
- be reachable only through the internal Docker network, localhost, VPN, or a private management network;
- require strong authentication between the instance and manager;
- authorize only the matching instance id;
- derive the CMS request's instance identity from trusted instance registration/configuration, not from browser-provided data;
- reject and log any CMS-originated request whose instance id does not match the registered manifest path, compose project, domain, and server inventory entry;
- require an admin approval token or signed update request;
- log every update request and result;
- reject requests from unknown instances;
- reject requests where the instance id, manifest path, compose project, or domain does not match the server inventory;
- never expose Docker socket access to the CMS backend container.

The manager API should treat the CMS as a requester, not as a trusted host-control process. Docker authority stays inside SelfHelp Manager.

If the manager has a web UI and an API, they must share the same server-operator authorization model. VPN access may allow reaching the UI/API, but it must not bypass login, roles, audit logging, approval checks, or CSRF/session protection.

## Update Dry Run And Preflight

Before updating, the updater must support a dry run that changes nothing.

Dry run checks:

- disk space;
- backup target available and writable;
- DB connection;
- current migration version;
- pending migration path;
- destructive migration flag;
- plugin compatibility;
- Docker image availability;
- image digest match;
- signature/checksum verification;
- registry trust;
- service health before update;
- scheduled job runner state;
- worker state;
- expected downtime;
- rollback level.

Dry-run success example:

```text
Update possible.
Backup will be created.
No destructive migrations.
Estimated downtime: short.
Rollback: automatic before migrations; config/container rollback after migrations.
```

Dry-run blocked example:

```text
Update blocked.
Plugin survey-js 1.3.0 is not compatible with SelfHelp 1.6.0.
Available options:
- update plugin first;
- install SelfHelp 1.5.2 instead;
- keep current version.
```

## Database Migration Metadata

Registry metadata must describe database migration risk.

Example:

```json
{
  "version": "1.5.0",
  "minimumDirectUpgradeFrom": "1.3.0",
  "database": {
    "migrationRange": "20260601000000-20260605000000",
    "destructive": false,
    "requiresBackup": true,
    "manualConfirmationRequired": false,
    "automaticRollback": "before_destructive_migrations_only"
  }
}
```

The updater must answer:

- Can update from 1.2.0 to 1.3.0?
- Can update directly from 1.0.0 to 1.5.0?
- Are DB migrations destructive?
- Is manual action required?
- Can rollback happen automatically?

MVP rollback rule:

- Automatic rollback is only supported before destructive migrations.
- If an update contains destructive database migrations, the updater must require manual confirmation and a verified backup.
- The UI must show a clear warning that automatic rollback may not be possible after destructive migrations.

## Backup And Rollback

Before every update:

- snapshot generated config files;
- snapshot manifest and lock file;
- backup database with routines/triggers where applicable;
- snapshot uploads/assets if local storage is used;
- snapshot plugin lock files and plugin artifacts;
- record installed image digests;
- record current compose file;
- record current migration version.

Rollback levels:

| Level | Use case |
| ----- | -------- |
| Config/container rollback | Update failed before migrations. |
| App rollback with DB unchanged or compatible | Code update failed after non-destructive migrations. |
| Full restore | Migration changed data destructively or health checks fail after schema/data changes. |

The updater must explain which rollback level is available before the operator clicks update.

For MVP, automatic rollback is only supported before destructive migrations.

If destructive migrations are present:

- require manual confirmation;
- require a verified backup;
- warn clearly that automatic rollback may not be possible;
- record the operator decision in the update log.

## Backup Consistency Rules

Backups must be consistent and restorable. A backup that cannot be read, identified, and matched to an instance version is not a valid safety point for update, destructive migration, restore, or clone.

Sysadmins create backups through:

```bash
sh-manager instance backup <instanceId>
```

Backups must be stored under:

```text
/opt/selfhelp/instances/<instanceId>/backups/
```

For update backups:

- the instance must enter maintenance mode; or
- the manager must use a database-consistent snapshot method.

Maintenance-mode backup is the default MVP path because it is easier to reason about and easier to explain to operators. If an online backup method is added, it must document the database consistency mechanism, filesystem consistency limits, and which data areas may still change during capture.

Backup metadata must record:

- backup id;
- instance id;
- backup time;
- SelfHelp version;
- database migration version;
- plugin versions;
- whether the backup was online or maintenance-mode;
- included data areas;
- checksum/integrity result.

Each backup must include:

- MySQL dump, preferably compressed;
- uploads/assets snapshot;
- plugin artifacts snapshot or lock-based reinstall metadata;
- `selfhelp.instance.json`;
- `selfhelp.lock.json`;
- `compose.yaml`;
- redacted env/config metadata;
- migration version;
- backup manifest with checksums.

Before destructive migrations, the manager must verify that the backup can be read and that required metadata exists. The preflight must fail if:

- the backup id is missing;
- the backup belongs to a different instance id;
- the SelfHelp version or migration version is missing;
- plugin version metadata is missing;
- integrity/checksum verification fails;
- required data areas for the planned operation are not included;
- the backup storage path is unavailable or too low on disk space for a restore rehearsal or safe restore.

The update log must record which backup id was accepted as the safety point. Restore and clone flows must refuse backups whose metadata cannot be matched to the selected instance unless the operator enters a documented disaster-recovery import path.

## Safe Mode

Safe mode is required for broken updates or broken plugins.

Safe mode should allow:

- login as admin;
- disable broken plugin;
- view system status;
- run update/repair;
- collect support bundle.

Implementation options:

```bash
docker compose run backend php bin/console selfhelp:safe-mode --enable
docker compose run backend php bin/console selfhelp:plugin:safe-mode --enable
```

or env:

```dotenv
SELFHELP_SAFE_MODE=1
SELFHELP_DISABLE_PLUGINS=true
```

Current backend already has plugin safe-mode concepts. The distribution plan should formalize a unified system safe-mode flow that can boot the instance without plugin runtime side effects.

## Health Checks

Each instance needs per-service health, not only one backend probe.

Health checks:

- frontend health;
- backend health;
- database health;
- Redis health;
- Mercure health;
- worker health;
- scheduled-jobs runner health;
- plugin health;
- registry reachability;
- disk-space warning;
- backup target health.

Admin/system UI example:

```text
Instance website1
Backend: OK
Frontend: OK
Database: OK
Redis: OK
Mercure: OK
Scheduler: OK
Worker: OK
Plugins: 1 warning
```

The public `/cms-api/v1/health` backend route is only one input. The installer/updater should aggregate Docker health states, service probes, and backend admin health APIs.

## Support Bundle

The system should be able to create a redacted support bundle.

It should collect:

- manifest;
- lock file;
- version summary;
- installed plugin list;
- last update logs;
- health check results;
- redacted env/config;
- Docker compose status;
- backend logs;
- frontend logs;
- scheduler logs;
- worker logs;
- plugin health/doctor output;
- recent registry/advisory check result.

It must not include:

- DB passwords;
- JWT private keys;
- `APP_SECRET`;
- Mercure secrets;
- tokens;
- private keys;
- participant data unless an explicit operator-export mode is added.

## System Maintenance Admin UI

Long term, the SelfHelp admin should have a system section.

Suggested structure:

```text
System
  Current version
  Available updates
  Installed plugins
  Plugin updates
  Compatibility warnings
  Security advisories
  Backups
  Health checks
  Logs / support bundle
  Maintenance mode
  Safe mode / repair guidance
```

The actual update should still be executed by the external updater/container. The admin UI can trigger, authorize, and monitor it.

## Backend Work Needed

Add or formalize backend support for distribution:

- authenticated system/version endpoint exposing:
  - backend version;
  - frontend expected version/API contract;
  - DB migration status;
  - plugin API version;
  - installed plugins;
  - safe-mode state;
  - maintenance-mode state;
  - scheduler runner status;
  - lock/manifest drift warnings if backend can read them.
- admin update-management endpoints or service contracts for:
  - reading current version/update state;
  - displaying registry/update data supplied by SelfHelp Manager;
  - requesting preflight;
  - recording admin approval;
  - reading update logs/progress;
  - deriving the current instance id from trusted server-side configuration;
  - refusing browser-provided or user-provided `instanceId` for update execution;
  - logging cross-instance update attempts as security events.
- health/admin system status endpoint aggregating:
  - DB;
  - Redis;
  - Mercure;
  - cache;
  - worker;
  - scheduler;
  - plugin doctor.
- maintenance mode support with a clean API response.
- signed or tokenized update-request model if CMS-to-manager triggering is implemented.
- commands for installer/updater use:
  - create admin user;
  - validate install;
  - validate update;
  - warm caches;
  - clear/warm dynamic API route cache;
  - export backup metadata;
  - safe mode enable/disable;
  - support bundle generation or support data export.
- route permissions for admin system/update views.
- JSON schemas for all new endpoints.

Use existing patterns:

- controllers stay thin;
- services own business logic;
- route rows/permissions come from generated Doctrine migrations;
- fresh install uses Doctrine migrations, not legacy SQL dumps;
- cache invalidation follows existing `CacheService` categories.

Avoid:

- raw SQL bootstrap except inside generated migrations;
- keeping installer routes enabled after install;
- storing secrets in the database unless explicitly needed;
- letting the running app mutate Docker state directly without the external updater boundary.
- mounting Docker socket into backend/frontend/worker/scheduler containers.

## Frontend Work Needed

Build and distribute the frontend as a production artifact:

- Next.js standalone build in CI;
- Docker image with signed digest;
- runtime env for public/internal URL model;
- no build step on production server.

Add/extend admin UI:

- system/current version page;
- update availability page;
- update preflight result view;
- update approval and progress view;
- plugin compatibility warnings;
- security advisories;
- health checks;
- support bundle creation;
- maintenance mode display;
- safe-mode repair guidance.
Update UI behavior:

- CMS update pages are scoped to the current instance only.
- The frontend must not send arbitrary `instanceId` values for update execution.
- The frontend must not expose controls for selecting or managing another SelfHelp instance.
- Multi-instance management belongs only to SelfHelp Manager UI/CLI.

Must preserve:

- browser calls go through `/api/*`;
- server-side frontend calls use internal Symfony URL;
- operator does not manually guess URLs.
- CMS can request/monitor updates, but Docker/image/config/migration execution belongs to SelfHelp Manager.

Current frontend config names to verify during implementation:

- `NEXT_PUBLIC_API_URL`;
- `SYMFONY_INTERNAL_URL`;
- `SYMFONY_API_PREFIX`.

If names differ in the frontend repository, use the runtime code as source of truth and update this plan/active docs.

## Shared Package Work Needed

Shared package should hold cross-repo contracts where appropriate:

- system/version response types;
- update preflight response types;
- registry release metadata types if consumed by UI/mobile;
- plugin/core compatibility types;
- security advisory types;
- permission constants for system maintenance views.

Keep schema parity with backend JSON schemas where frontend/mobile consume response fields.

## Mobile App Impact

The Docker installer does not install the mobile app.

However, the mobile app may consume:

- backend API version;
- plugin API compatibility;
- plugin frontend/mobile metadata;
- security/update notices if exposed to admins.

If shared types change, update mobile to compile against them. Do not make mobile responsible for server installation or updates.

## Plugin Ecosystem Work Needed

Move official plugins into the unified registry model.

Plugin metadata must include:

- plugin id;
- version;
- official/reviewed/untrusted trust level;
- core compatibility range;
- plugin API compatibility range;
- dependencies;
- artifact URL;
- checksum;
- signature;
- security advisory links;
- older compatible versions.

Current plugin source model can remain for installed-app management, but the official default source should point at the unified registry plugin section once the registry exists.

Production install/update from official registry must verify signatures/checksums.

For reproducibility, plugin old versions remain installable unless blocked by security policy.

## License Compliance For Distributed Images

SelfHelp production releases distribute Docker images and frontend/runtime artifacts. Therefore every release pipeline must include license compliance checks.

The release process must generate and review license metadata for:

- frontend npm dependencies;
- shared npm package dependencies;
- mobile/shared dependencies where relevant to published artifacts;
- backend Composer dependencies;
- PHP extensions and system packages included in Docker images;
- base Docker images;
- plugin runtime bundles and plugin backend dependencies;
- fonts, icons, images, CSS libraries, and other static assets.

The release pipeline must generate:

- SBOM for every distributed Docker image;
- dependency license report;
- list of bundled frontend/runtime packages;
- list of Composer packages;
- list of container base images and OS packages.

Production release must fail or require explicit legal/reviewer approval when a dependency has an incompatible or unknown license.

Allowed license policy should be documented, for example:

- allowed: MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0, ISC;
- review required: MPL-2.0, LGPL, EPL, custom licenses;
- blocked unless explicitly approved: GPL/AGPL or unknown/proprietary licenses, especially when bundled into distributed images or browser JavaScript.

The exact policy must be confirmed by the project owner/legal responsible person before the first public release.

## CI And Release Pipeline

Core release pipeline should:

1. Run backend gates: PHPStan, tests, migrations, schema validation.
2. Run frontend gates: typecheck, tests, production build, smoke where possible.
3. Build backend production image.
4. Build worker image or backend-worker command image.
5. Build scheduled-jobs runner image or backend-scheduler command image.
6. Build frontend production image from Next.js standalone output.
7. Generate SBOMs.
8. Generate dependency and asset license reports.
9. Scan images/artifacts.
10. Fail or require explicit legal/reviewer approval for incompatible or unknown licenses.
11. Generate release metadata JSON.
12. Generate migration compatibility metadata.
13. Sign canonical release payloads.
14. Publish images/artifacts to official locations.
15. Update official registry.
16. Publish registry.
17. Smoke-test fresh install from the published registry.
18. Smoke-test update from previous stable release.
19. Smoke-test multi-instance routing on one server.

SelfHelp Manager release pipeline should:

1. Run TypeScript typecheck, lint, unit tests, and integration tests.
2. Validate registry, manifest, lock, advisory, and preflight schemas.
3. Build the manager CLI/web Docker image.
4. Generate SBOM.
5. Generate dependency and asset license reports.
6. Scan the image.
7. Fail or require explicit legal/reviewer approval for incompatible or unknown licenses.
8. Sign the image and release metadata.
9. Publish versioned image tags.
10. Smoke-test fresh install in local mode.
11. Smoke-test production-mode generation without touching real DNS where possible.
12. Smoke-test update, remove, backup, restore, clone, registry-unavailable, schema-compatibility, and support-bundle flows against disposable Docker instances.

Plugin release pipeline should:

1. Run plugin tests.
2. Validate plugin manifest/schema.
3. Build plugin artifacts.
4. Generate plugin SBOM and dependency/asset license reports.
5. Fail or require explicit legal/reviewer approval for incompatible or unknown licenses.
6. Generate plugin release metadata.
7. Sign canonical payload.
8. Publish artifacts.
9. Update the official unified registry's plugin release metadata section.
10. Test install/update against compatible core versions.

## Release Documentation

Each release should include:

- quick Docker install guide;
- multi-instance/domain routing guide;
- backup guide;
- restore guide;
- update guide;
- failed update recovery guide;
- safe-mode/broken-plugin guide;
- support-bundle guide;
- moving an instance to another server;
- security hardening guide;
- registry trust/signature explanation;
- known destructive migration warnings when applicable.

Offline/air-gapped installation docs are not part of MVP.

## Testing And Verification Plan

Installer tests:

- connected registry fetch succeeds and verifies signature;
- unknown registry source is rejected in production;
- missing internet blocks install with clear error;
- production domain duplicate is rejected;
- local mode can create two instances on different localhost ports;
- local mode uses Mailpit by default and sends no real outbound email;
- production mode supports institutional SMTP relay, authenticated SMTP, and disabled mail;
- production mode requires real domain and ports 80/443;
- installer binds localhost by default;
- remote access requires one-time token and expiration;
- installer disables/removes itself after success.
- persistent manager web UI is disabled by default.
- persistent manager web UI can bind only to localhost, VPN, or a private management network in production MVP.
- manager web UI requires login even when reachable only over VPN.
- manager local-auth passwords are stored as hashes and never in `.env`.
- manager bootstrap token is one-time and rotates after use.
- campus login provider is disabled by default and only enabled by explicit manager config.
- campus login provider is implemented by SelfHelp Manager in TypeScript and does not install, reuse, or depend on the old Symfony/PHP plugin at runtime.
- campus login provider allows only configured email/domain/group/claim mappings to become manager operators.
- campus-authenticated but unauthorized users are rejected.
- generated compose files include log rotation for every long-running container.
- registry unavailable blocks fresh install with a clear retryable error.

Multi-instance tests:

- two instances have separate Docker projects;
- two instances have separate DB volumes;
- two instances have separate Redis containers;
- two instances have separate Mercure secrets;
- two instances have separate JWT keys and `APP_SECRET`;
- two instances can run different backend/frontend image versions;
- only the reverse proxy is shared;
- domain routes to the correct frontend;
- backend is not publicly exposed by default.
- server inventory lists every instance and prevents duplicate domains.

Remove-instance tests:

- disable instance stops selected containers and keeps data.
- remove-containers mode removes only the selected compose project and keeps volumes/backups.
- full delete requires explicit confirmation and backup/export prompt.
- full delete removes only selected instance volumes when confirmed.
- shared Traefik is not removed while another instance exists.
- inventory entry is removed or status-updated correctly.
- wrong instance id/path/domain mismatch blocks destructive deletion.

Restore tests:

- restore selects a backup and verifies integrity.
- restore same instance preserves secrets and identity.
- restore same instance restores DB, uploads, plugin artifacts, manifest, and lock.
- restore as clone generates new secrets and isolated Docker state.
- restore refuses to continue when required artifacts are unavailable or fail signature/checksum verification.
- restore runs migrations only when required by the restored version.
- restore runs health checks after completion.

Clone tests:

- clone creates a new instance id and requires a new domain/local port.
- clone copies DB, uploads, and plugin artifacts.
- clone preserves core/plugin versions from the source lock file.
- clone generates new `APP_SECRET`, JWT keys, Mercure secrets, DB password, and Redis password.
- clone creates new Docker project, networks, volumes, manifest, and lock file.
- clone updates server inventory.
- source instance remains untouched and can keep running.

Docker host access tests:

- SelfHelp Manager has Docker daemon access when required.
- backend/frontend/worker/scheduler containers do not mount Docker socket.
- CMS update request cannot directly access Docker socket.
- manager API rejects unknown instance ids.
- manager API rejects mismatched instance id/manifest/compose-project requests.
- manager API rejects and logs CMS-originated cross-instance update requests.
- manager API and web UI are not exposed through public SelfHelp instance routes.
- VPN/private-network reachability does not bypass manager authorization checks.

Registry/resolver tests:

- unknown major schema versions are rejected.
- compatible minor schema additions are tolerated.
- registry requiring a newer manager version produces a clear manager-update instruction.
- latest compatible SelfHelp version is selected;
- specific older SelfHelp version is selected;
- latest compatible plugin version is selected when latest plugin is incompatible;
- specific older plugin version can be installed;
- security-blocked version is refused or requires explicit override if such override exists;
- destructive migration metadata forces backup and manual confirmation.
- registry unavailable makes update/plugin checks fail gracefully without stopping existing instances.

Update tests:

- dry run changes nothing;
- preflight reports plugin incompatibility;
- preflight reports Docker image unavailable;
- preflight verifies signatures/checksums;
- automatic rollback works before migrations;
- destructive migration path requires verified backup and manual confirmation;
- manifest and lock are updated atomically after success;
- lock drift is detected.
- normal updates preserve uploads/assets.
- updating backend/frontend/worker/scheduler containers does not delete DB data, uploads/assets, plugin artifacts, secrets, backups, logs, or operation logs.
- MySQL image update reuses the existing per-instance MySQL data volume.
- MySQL image update requires backup, runtime compatibility check, and health check.
- CMS can request/monitor update status without mutating Docker directly.
- CMS admin in one instance cannot see, request, approve, monitor, cancel, or execute updates for another instance.
- CMS update requests derive the instance id from trusted server-side configuration.
- CMS update endpoints ignore or reject browser-provided `instanceId` values.
- manager CLI/container executes the approved update.

Backup tests:

- `sh-manager instance backup <instanceId>` creates backups under `/opt/selfhelp/instances/<instanceId>/backups/`.
- backups include a compressed or compressible MySQL dump.
- backups include uploads/assets snapshot.
- backups include plugin artifacts snapshot or lock-based reinstall metadata.
- backups include `selfhelp.instance.json`, `selfhelp.lock.json`, `compose.yaml`, redacted env/config metadata, migration version, and backup manifest with checksums.
- backup integrity verification fails when required checksum metadata is missing or invalid.
- backup creation never stores secrets in redacted env/config metadata.

Persistent-state tests:

- MySQL data is stored in a per-instance persistent volume.
- uploads/assets are stored in a per-instance persistent volume or bind mount.
- plugin artifacts are stored in a per-instance persistent volume or bind mount.
- backend/frontend/worker/scheduler containers are disposable.
- uploads/assets do not live only inside backend/frontend container filesystems.
- full delete deletes persistent state only after explicit operator confirmation.
- important operation logs are stored under `/opt/selfhelp/instances/<instanceId>/logs/` and `/opt/selfhelp/instances/<instanceId>/update-operations/`.

Health/support tests:

- health aggregation reports frontend/backend/DB/Redis/Mercure/scheduler/worker/plugin state;
- support bundle redacts secrets;
- safe mode lets admin disable a broken plugin;
- scheduled-jobs runner health is visible.

Frontend/BFF tests:

- browser API calls use `/api/*`;
- server-side frontend code uses internal Symfony URL;
- generated runtime config is enough for both paths;
- admin system page shows update and compatibility warnings.
- admin update UI is scoped to the current instance and does not send arbitrary `instanceId` values for update execution.

Suggested verification commands must be taken from each repository's `AGENTS.md`. Do not invent command names.

## API And Contract Sketches

### System Version Response

```json
{
  "instanceId": "website1",
  "selfhelpVersion": "1.4.2",
  "backendVersion": "1.4.2",
  "frontendVersion": "1.4.2",
  "pluginApiVersion": "2.1",
  "databaseMigrationVersion": "Version20260605081254",
  "safeMode": false,
  "maintenanceMode": false,
  "installedPlugins": [
    {
      "id": "survey-js",
      "version": "1.3.0",
      "compatible": true
    }
  ]
}
```

### Update Preflight Response

```json
{
  "status": "blocked",
  "targetVersion": "1.6.0",
  "checks": [
    {
      "code": "plugin_incompatible",
      "severity": "error",
      "message": "survey-js 1.3.0 is not compatible with SelfHelp 1.6.0."
    }
  ],
  "options": [
    {
      "type": "install_core_version",
      "version": "1.5.2",
      "label": "Install SelfHelp 1.5.2 instead"
    }
  ],
  "database": {
    "destructive": false,
    "requiresBackup": true,
    "manualConfirmationRequired": false
  },
  "rollback": {
    "automaticBeforeMigrations": true,
    "automaticAfterDestructiveMigrations": false
  }
}
```

### Server Inventory Entry

```json
{
  "serverId": "server-001",
  "proxy": {
    "type": "traefik",
    "network": "selfhelp_proxy"
  },
  "instances": [
    {
      "instanceId": "website1",
      "domain": "website1.example.ch",
      "path": "/opt/selfhelp/instances/website1",
      "composeProject": "selfhelp_website1",
      "status": "active"
    }
  ]
}
```

### Remove Instance Request

```json
{
  "instanceId": "website1",
  "mode": "full_delete",
  "createBackupBeforeDelete": true,
  "deleteVolumes": true,
  "deleteBackups": false,
  "typedConfirmation": "delete website1"
}
```

Allowed modes:

- `disable`
- `remove_containers_keep_data`
- `full_delete`

### Restore Instance Request

```json
{
  "targetInstanceId": "website1",
  "backupId": "backup-20260605-website1-001",
  "mode": "same_instance",
  "preserveSecrets": true,
  "runHealthChecks": true,
  "typedConfirmation": "restore website1"
}
```

Allowed modes:

- `same_instance`
- `restore_as_clone`

For `restore_as_clone`, include the new instance id and route:

```json
{
  "targetInstanceId": "website1-copy",
  "backupId": "backup-20260605-website1-001",
  "mode": "restore_as_clone",
  "newDomain": "website1-copy.example.ch",
  "generateNewSecrets": true
}
```

### Clone Instance Request

```json
{
  "sourceInstanceId": "website1",
  "targetInstanceId": "website1-staging",
  "targetDomain": "website1-staging.example.ch",
  "preserveVersionsFromLock": true,
  "generateNewSecrets": true,
  "copyUploads": true,
  "copyPluginArtifacts": true
}
```

### CMS Update Approval Request

`instanceId` is shown here as the server-derived instance identity. The browser must not provide arbitrary `instanceId` values for update execution, and the backend/manager must derive and verify the instance identity from trusted configuration and server inventory.

```json
{
  "instanceId": "website1",
  "targetVersion": "1.5.2",
  "preflightId": "preflight-20260605-001",
  "approvedByUserId": 42,
  "approvalToken": "signed-or-one-time-token",
  "acceptedMigrationRisk": true
}
```

This request authorizes the manager to act. It does not give the CMS backend Docker socket access.

If a request originating from the CMS for `website1` attempts to approve, monitor, cancel, or execute an update for `website2`, SelfHelp Manager must deny it and log it as a security event.

### Security Advisory Display

```json
{
  "id": "SHSA-2026-0001",
  "severity": "high",
  "component": "plugin",
  "componentId": "survey-js",
  "installedVersion": "1.3.0",
  "fixedVersion": "1.3.2",
  "recommendedAction": "Update survey-js to 1.3.2."
}
```

## Deployment Plan For MVP

1. Create `sh-manager` as the SelfHelp Manager repository.
2. Build signed SelfHelp Manager Docker artifact.
3. Build signed backend, frontend, worker, and scheduler Docker artifacts.
4. Adapt `plugins/sh2-plugin-registry` into the official connected registry with core/frontend/scheduler/worker/plugin sections.
5. Implement manager connected registry fetch and signature verification.
6. Implement SelfHelp-managed Traefik setup.
7. Implement server inventory file.
8. Implement isolated per-instance compose generation.
9. Implement local Docker testing mode with Mailpit.
10. Implement production domain validation and TLS.
11. Implement manifest and lock writing.
12. Implement install health checks.
13. Implement scheduled-jobs runner container in every stack.
14. Implement log rotation in generated compose files.
15. Implement update dry run and preflight.
16. Implement CMS-initiated update request/monitoring without Docker socket access.
17. Implement backups and MVP rollback policy.
18. Implement remove/disable/full-delete instance flow.
19. Implement restore and clone flows.
20. Implement schema-version compatibility checks.
21. Implement registry-unavailable behavior.
22. Implement admin system/update UI.
23. Implement support bundle and safe-mode guidance.

## Milestones

### Milestone 1: Registry And Artifact Contract

- Define unified registry schemas.
- Define release metadata for core/backend/frontend/scheduler/plugins.
- Define compatibility metadata.
- Define advisory metadata.
- Define manifest and lock schemas.
- Define signing and trust model.

### Milestone 2: Production Artifacts

- Backend image.
- Frontend image from Next.js standalone.
- Worker image/command.
- Scheduler image/command.
- SelfHelp Manager signed Docker image.
- SBOMs and signatures.
- License reports and license-policy gate for every distributed image/artifact.
- Registry publishing flow.

### Milestone 3: Docker Installer MVP

- Connected-only installer.
- Localhost-only UI by default.
- Remote token flow.
- Traefik management.
- Production domain flow.
- Local Docker testing mode.
- Isolated compose generation.
- Secrets generation.
- Migrations/admin creation.
- Health checks.
- Installer disable/remove after success.

### Milestone 4: Multi-Instance Operations

- Instance inventory.
- Duplicate domain prevention.
- Manifest/lock reading.
- Per-instance health.
- Per-instance support bundle.
- Per-instance start/stop/restart/update.
- Per-instance disable/remove/full-delete flow.
- Per-instance backup/restore/clone flow.
- Restore same-instance and restore-as-clone flows.
- Log rotation in generated compose files.

### Milestone 5: Update MVP

- Update checker.
- Dependency resolver.
- Dry-run/preflight.
- Backup.
- Maintenance mode.
- Pull/verify artifacts.
- Migration execution.
- Health/smoke checks.
- MVP rollback policy.

### Milestone 6: Admin System UI

- Current version.
- Updates.
- Plugin compatibility warnings.
- Security advisories.
- Health.
- Backups.
- Support bundle.
- Maintenance mode.
- Safe-mode instructions.

## Out Of Scope For MVP

- Offline/air-gapped install.
- Standalone PHP/Node/shared-hosting install.
- Production Windows Server support.
- Kubernetes/Helm.
- External database selection.
- Manual reverse proxy editing.
- Building production frontend/backend/plugin artifacts on the server.
- Private registry mirrors unless a later institutional requirement adds them.

## Open Questions

These are the remaining product choices an implementation agent should confirm if they block implementation:

1. What public URL should the adapted `plugins/sh2-plugin-registry` registry publish under? Recommended MVP default: keep `https://humdek-unibe-ch.github.io/sh2-plugin-registry/`; if the team later wants `sh2-registry`, rename the one existing registry repository instead of creating a second active registry.
2. Which release channels are enabled at MVP launch? Recommended: `stable` and `beta`; keep `nightly` dev-only.
3. Should security-blocked old versions be completely uninstallable, or installable only with a signed/explicit override? Recommended: block in production unless maintainers approve an override policy.
4. Should local mode use only ports (`localhost:8081`) or also local hostnames? Recommended: ports first.
5. Should the scheduler be a distinct image or the backend image with a scheduler command? Recommended: record it as a distinct artifact either way; implementation may reuse the backend image initially.

## Definition Of Done

- The plan is implemented as a Docker-only connected installer/updater architecture.
- The installer/updater/server manager is the separate `sh-manager` repository and productized as SelfHelp Manager.
- SelfHelp Manager is distributed for production as a signed Docker image.
- Production installs require internet access and official signed registry metadata.
- Offline/standalone install paths are not offered in MVP.
- The installer binds localhost only by default.
- Remote installer access requires explicit confirmation, one-time token, and expiration.
- The installer disables/removes itself after successful install.
- Any persistent manager web UI is private by default, reachable only through localhost, VPN, or a private management network in production MVP.
- Manager web UI and manager API access require authentication and authorization even when reachable only over VPN.
- Manager operator accounts are separate from CMS users, and raw manager passwords are never stored in `.env`.
- Campus account login is available only as an explicitly configured manager auth provider implemented by SelfHelp Manager in TypeScript; the old Symfony/PHP SelfHelp plugin may be inspected only as a reference implementation and must not be installed, reused, or required at runtime.
- One shared Traefik proxy routes all instances.
- `selfhelp.server.json` tracks server-level proxy and instance inventory.
- Operators do not manually write reverse proxy config in normal Docker installs.
- Each instance has isolated DB, Redis, Mercure, worker, scheduler, uploads, plugin artifacts, secrets, images, manifest, and lock file.
- MySQL runs as a separate per-instance Docker service.
- MySQL data is stored in a per-instance persistent volume.
- Uploads/assets are stored in a per-instance persistent volume or bind mount, never only inside backend/frontend container filesystems.
- Plugin artifacts are stored in a per-instance persistent volume or bind mount.
- Backend/frontend/worker/scheduler containers are disposable.
- Updating containers never deletes DB data, uploads/assets, plugin artifacts, secrets, backups, logs, or operation logs.
- Normal updates preserve uploads/assets.
- Full delete may delete uploads/assets only after explicit operator confirmation.
- MySQL image updates are handled by SelfHelp Manager with backup, compatibility check, and health check.
- Sysadmins create backups through `sh-manager instance backup <instanceId>`.
- Backups are stored under `/opt/selfhelp/instances/<instanceId>/backups/`.
- Backups include MySQL dump, uploads/assets snapshot, plugin artifacts snapshot or lock-based reinstall metadata, instance manifest, lock file, compose file, redacted env/config metadata, migration version, and backup manifest with checksums.
- Logs are available through `docker compose logs`, and important operation logs are stored under `/opt/selfhelp/instances/<instanceId>/logs/` and `/opt/selfhelp/instances/<instanceId>/update-operations/`.
- SelfHelp Manager can list, add, disable, remove, backup, restore, clone, update, health-check, and support-bundle selected instances.
- Remove-instance flow never touches other instances and never removes shared Traefik while another instance exists.
- Restore flow verifies backup integrity, restores DB/uploads/plugin artifacts/manifest/lock, preserves secrets for same-instance restore, generates new secrets for restore-as-clone, and runs health checks.
- Clone flow creates a new isolated instance with copied data/artifacts, source lock versions, new secrets, new Docker state, updated inventory, and health checks.
- Docker socket access is limited to SelfHelp Manager containers; normal runtime containers do not mount it.
- CMS update management can request/approve/monitor updates but does not mutate Docker state directly.
- CMS update management is always scoped to the currently authenticated SelfHelp instance.
- CMS admins are not global server operators and cannot see, request, approve, monitor, cancel, or execute updates for another instance.
- The current CMS instance id is derived from trusted server-side configuration, not from a browser-provided value.
- SelfHelp Manager denies and logs CMS-originated cross-instance update requests as security events.
- Any manager API is internal-only, strongly authenticated, instance-scoped, and never exposes Docker socket access to the CMS backend.
- Browser API calls go through `/api/*`; server-side frontend calls use internal Symfony URL.
- Production installs pull ready-built signed artifacts and never run production builds on the server.
- Every production release pipeline generates SBOMs and license reports for distributed Docker images, npm/shared/mobile-relevant dependencies, Composer dependencies, OS packages, base images, plugin bundles, and static assets.
- Production release fails or requires explicit legal/reviewer approval when a dependency has an incompatible or unknown license.
- Allowed/review-required/blocked license policy is documented and confirmed by the project owner/legal responsible person before first public release.
- Exactly one official registry exists; `plugins/sh2-plugin-registry` is adapted into that unified registry unless the team explicitly renames the same repository later.
- The unified registry contains or generates core/backend, frontend, scheduler, worker, official plugin, compatibility, dependency, migration, advisory, trusted signing key, checksum, signature, and registry/manifest/lock/advisory schema metadata.
- No separate production registries exist for core and plugins.
- The registry keeps old compatible versions available for reproducible experiments.
- The dependency resolver can choose latest compatible and specific older core/plugin versions.
- Every instance includes a scheduled-jobs runner container.
- Every instance has `selfhelp.instance.json` and `selfhelp.lock.json`.
- Generated compose files configure log rotation for every long-running container.
- Registry/manifest/lock/advisory/release/inventory/preflight files include schema/version fields and enforce major/minor compatibility rules.
- If the official registry is unavailable, fresh install/update/plugin operations fail gracefully while existing instances keep running.
- Local Docker testing mode uses Mailpit by default and sends no real outbound email.
- Production mail setup supports institutional SMTP relay, authenticated SMTP, or disabled mail.
- Updates support dry-run/preflight.
- Destructive migrations require verified backup and manual confirmation.
- Automatic rollback MVP support is limited to the period before destructive migrations.
- Admin system UI can show version, updates, plugin compatibility, security advisories, health, backups, logs/support bundle, and maintenance mode.
- Safe mode can boot enough of the system for admin repair and broken-plugin disablement.
- Support bundles are available and redacted.
- Release docs cover install, update, backup, restore, failed update recovery, safe mode, support bundles, and moving an instance to another server.
