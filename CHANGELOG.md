# v0.1.12

## CMS Styles — Error pages

- **The `no-access` (403), `no-access-guest` and `missing` (404) system pages are now styled CMS pages instead of bare screens.** New `noAccess`, `notFound` and `missing` CMS styles (with title/message/button/login-label copy and `mantine_color` / `mantine_radius` / `mantine_shadow` / `mantine_button_variant` / `show_icon` presentation fields) are seeded and wired onto the corresponding system pages, and each page's sections are wrapped in a container so they match the login page's layout. System page keywords were normalized to kebab-case (`reset_password`→`reset-password`, `no_access`→`no-access`, `no_access_guest`→`no-access-guest`) so the CMS keyword matches the public URL segment with no alias map, and the `refContainer` style description was corrected to "structural/transparent container, not a visual wrapper". (migrations `Version20260605134800`, `Version20260608075822`, `Version20260608090032`, `Version20260608124537`)

## CMS Styles — showUserInput

- **A new `showUserInput` style renders a form's collected entries as a table.** It is seeded with `data_table`, `fields_map` (column remapping), `own_entries_only` and `show_timestamp`; the data-table feature-flag fields `dt_sortable` / `dt_searching` / `dt_paginate` / `dt_info` / `dt_default_order_column` / `dt_default_order_dir`; the full `mantine_table_*` styling set; and translatable `delete_modal_title` / `delete_modal_body`. The style always renders as a Mantine table (the temporary `use_mantine_style` link was added and then removed), and the unsupported anchor field was dropped. (migrations `Version20260609130712`, `Version20260611071244`, `Version20260611090106`, `Version20260611115337`, `Version20260611123033`, `Version20260616102744`)
- **`showUserInput` tables now refresh immediately when entries are added or deleted, without a manual cache clear.** A `showUserInput` section references its data table through the `data_table` property field rather than `data_config`, but the page-render cache only declared a dependency on `data_config` tables. The rendered (draft) page was therefore never tagged with the `showUserInput` data table's entity scope, so `DataService`'s write-path invalidation (`invalidateEntityScope(data_table_id, …)` on submit / update / delete) could not bust it and the table kept serving the stale rows until the cache was cleared. `PageService::extractDataTableDependencies()` now also registers each `showUserInput` section's `data_table` (user-scoped when `own_entries_only`, otherwise global), so creating or deleting a record invalidates every page that renders that table. (`PageService`)

## CMS Sections — refContainer & delete

- **Section deletion is split into two distinct operations, with consistent detach-vs-destroy semantics.** "Remove from page" — both the single action *and* the multi-select **bulk** action — only *detaches* a section from that one page (keeping the section record for every other page that references it), while "destroy" (`DELETE /admin/sections/{id}`) performs the page-independent permanent delete. Previously the bulk path destroyed nested sections even though the single path only detached, so the same "remove" verb had opposite outcomes for a shared `refContainer`; bulk remove is now detach-only too. `refContainer` re-usable section references resolve correctly end to end: a reused subtree is detected across the section tree, detaching a shared container on one page never destroys it on the others, and **every** mutating operation (update, detach, destroy) invalidates the page/section cache for *every* page that renders the shared container — not just the one being edited. Crucially the **destroy** path now resolves the set of referencing pages **before** the relationship rows are removed (it previously queried them afterwards, when the rows were already gone, so other pages kept serving the deleted shared container from cache). (migration `Version20260609090611`; `SectionRelationshipService`, `AdminSectionService`, `SectionRepository`, `SectionUtilityService`)

## API — Sections

- **New `GET /admin/sections/pages?ids[]=…` endpoint** (guarded by `admin.page.update`) returns every page that references the given sections — directly through `rel_pages_sections` or nested via the section hierarchy (a recursive ancestor walk) — each as `{ id, keyword, isPublished }`, deduplicated across all requested section ids. The recursive ancestor-walk is a single batched `SectionRepository::getPagesContainingSections()` query (one statement for all ids, replacing the previous per-id N-query) and is the shared source of truth for the section-delete cache-invalidation path too, instead of the CTE being reimplemented in the service. It backs the publish-time "this refContainer is also published on other pages" warning and the delete-impact list, and replaces the earlier single-section `GET /admin/sections/{section_id}/pages` variant. (migrations `Version20260609113717` then `Version20260610123849`; `AdminSectionUtilityService::getPagesBySectionIds()`, `AdminSectionUtilityController`, `SectionRepository`; `responses/admin/sections/section_pages.json` + `section_pages_envelope.json` schemas; `docs/api-usage/README.md`)

## Forms — Delete permissions

- **On a form / `showUserInput` section that shows everyone's records (`own_entries_only=false`), a user may always delete their own record, but deleting another user's record now requires `DELETE` permission on the underlying data table** — otherwise the delete endpoint returns `403 Forbidden`. `FormValidationService::validateFormDeletion()` now also returns the section's `own_entries_only` flag and `data_table` id, and `DataService::getRecordOwnerId()` resolves a record's owner. The own-record-vs-permission rule is centralized in `DataAccessSecurityService::canDeleteOwnedRecord()` and used by **both** the display check (`SectionUtilityService` deciding whether to render a row's delete button) and the API enforcement (`FormController::deleteForm`), so the visible button and the endpoint can never drift out of lockstep. (`FormController`, `FormValidationService`, `DataService`, `DataAccessSecurityService`, `SectionUtilityService`)

## Scheduled Jobs — Actions

- **`clear_existing_jobs_for_record_and_action` now also fires on the `updated` form-submission trigger, not only `finished`.** Re-submitting (updating) a record therefore clears its previously queued action jobs the same way a first/finished submission does, so updated records can't accumulate stale queued jobs. (`ActionOrchestratorService`)

# v0.1.11

## Release pipeline

- **The `docker-release` GitHub Actions release no longer fails at "Download all artifacts".** The `create-release` job downloaded *every* workflow artifact with no filter, which on the 3-image build matrix meant 16 artifacts — including six large `*.dockerbuild` build records auto-uploaded by `docker/build-push-action` and three duplicate SBOMs auto-uploaded by `anchore/sbom-action`. That bloated download intermittently aborted with `Error: Unable to download and extract artifact: Artifact download failed after 5 retries.` and also attached the build records to the public release. The build-record upload is now disabled workflow-wide (`DOCKER_BUILD_RECORD_UPLOAD: 'false'`) and the SBOM action's own upload is turned off (`upload-artifact: false`, since the SBOM is re-uploaded in the `*-supply-chain` artifact), so the release job downloads only the intended supply-chain / digest / license artifacts. No runtime code changed; this is a release-tooling fix (the v0.1.10 images had already been built, pushed and signed before the failing step).

## Authentication

- **Refreshing the session no longer logs the operator out during a plugin install / update / uninstall.** The web client refreshes the access token from two independent runtimes that share no state — the Edge proxy (SSR navigations) and the Node BFF (`/api/*`), possibly across replicas — so when the short-lived access token was near expiry while the backend briefly restarted (exactly what a plugin lifecycle operation does) both could POST the **same** single-use refresh token at once. The first rotated it, the second found nothing, got a `401`, and the BFF wiped a perfectly good session → the operator was bounced to the login page ("uninstalling a plugin logged me out"). `JWTService::processRefreshToken()` now keeps a short (`30 s`) Redis-backed rotation **grace window**: a concurrent refresh of a just-consumed token replays onto the same newly issued refresh token (and mints a fresh access token) instead of being rejected, so all concurrent callers converge on the live token. Single-use semantics are preserved once the window elapses — a genuinely reused token is still rejected — and both the replay and the post-window rejection are pinned by regression + security tests in `JWTServiceTest`.

## System Maintenance

- **Editing the maintenance system message now takes effect immediately.** Toggling maintenance mode interpolates `{{system.maintenance_message}}` into cached page/section payloads, so a changed note kept serving the stale message until the cache TTL elapsed. `MaintenanceModeService::enable()/disable()` now invalidates the `pages` and `sections` cache categories whenever maintenance mode is toggled, so the public maintenance page reflects the current note on the next request. Covered by `MaintenanceModeServiceTest`.
- **The seeded maintenance alert message is stored in the field the renderer reads.** The original seed wrote the operator note into the alert style's `value` field, but the alert renders its `content` field, so the styled message never appeared. A data-only migration (`Version20260616094205`) moves the existing `maintenance-sys-message` translation from `value` to `content`; it is idempotent (`INSERT IGNORE` + scoped `DELETE`) and has a round-trip test.

## Plugins

- **Plugin purge is now an asynchronous, manager-parked operation, like install / uninstall.** `POST /admin/plugins/{plugin}/purge` previously ran synchronously and returned `200`; it now records a `purge` `plugin_operation`, dispatches it onto the `plugin_ops` Messenger transport, and returns `202 Accepted` with the operation envelope so the admin UI can track it on the operations console and the SelfHelp Manager can park it for the operator. `PluginPurger::purge()` is split into `request()` (validate + lock + snapshot the owned tables / manifest / backup flag + dispatch) and `finalize()` (the destructive cleanup: drop plugin-owned tables and tagged rows, foreign keys, migration versions, the plugin row, then regenerate bundles + clean artefacts), mirroring `PluginUninstaller`. The new `PurgePluginMessage` / `PurgePluginHandler` run `finalize()` inline in `development` / `trusted` modes and write a runbook in `managed` mode, where the operator runs `composer remove` and then `selfhelp:plugin:run-operation <id>` (now handles `TYPE_PURGE` via `PluginCliFinalizer::finalizePurge()`). `selfhelp:plugin:purge` reports the parked operation instead of claiming an immediate, synchronous purge.

## System Updates

- **Live update progress is pushed over SSE — the CMS no longer polls for it.** A new Doctrine listener (`App\EventListener\SystemUpdateMercurePublisher`) publishes a `system-update` Mercure event on every insert/update of a `SystemUpdateOperation` row, to the **requester's** per-user topic. It fires both when the CMS creates the `requested` row and on every state / `steps` / `progress_percent` write-back the SelfHelp Manager makes while draining it, so the System Maintenance page repaints its step tracker live over the existing `/auth/events` connection. The topic is minted by a new `MercureTopicResolver::userSystemUpdateTopic()` and multiplexed onto the same single subscriber JWT as the ACL + impersonation topics by `AuthEventsController` (one upstream socket per user). `GET /auth/events` now returns `systemUpdateTopic`; `responses/auth/events.json` requires it. Publish failures are logged and swallowed — the frontend's reconnect-aware fallback poll is the safety net.

## Plugins

- **Plugin operations always reach a terminal status — async worker path included.** When a plugin operation failed **after** marking the row `running` — a missing snapshot payload, an unknown type, a `composer require/remove` non-zero exit, or any thrown orchestrator error — it could be left stuck on `running`. The admin UI then showed progress forever and the per-plugin lock blocked every later install/uninstall until its TTL expired. The terminal guarantee is now centralized in `PluginOperationRecorder::fail()`, which both the manager-driven finalizer (`selfhelp:plugin:run-operation`) **and** the async `plugin_ops` Messenger worker handlers (`InstallPluginHandler` / `UpdatePluginHandler` / `UninstallPluginHandler`) route their catch-all failure through. `fail()` is now terminal-idempotent: it records a terminal `failed` status + the final `plugin-operation-progress` event for a still-running operation, and it never overwrites or re-emits for an operation that already reached a terminal state (so a post-`finalize()` cleanup error can no longer flip a `succeeded` row to `failed`). The recorder's existing raw-DBAL fallback keeps this working even when the EntityManager is poisoned, and the recovery never masks the original error.
- **A single broken plugin no longer 500s the whole plugin list.** `PluginAdminService::listPlugins()` formatted every row eagerly, so one inconsistent bundle (e.g. a half-removed plugin whose `composer remove` ran but whose row/manifest was briefly out of sync during a manager-driven restart) threw and turned the entire admin **Plugins** screen into a dead "Failed to load plugins" error. Rows are now formatted defensively: a bad row is logged and skipped, and the operator still sees — and can repair / uninstall — everything else.

# v0.1.8

## System Maintenance

- **`system.maintenance_message` is offered in the CMS `{{ }}` editor**: the section editor's variable autocomplete (`DataVariableResolver`) now lists `system.maintenance_message` alongside the other `system.*` variables, so an operator designing the maintenance page can insert the live note from the suggestion dropdown instead of typing it from memory. The variable was already resolvable and allow-listed; this surfaces it in the picker and adds guard tests that pin the full chain — `MaintenanceModeService` -> `VariableResolverService` (`maintenance_message`, both the set value and the blank-note default) -> the `{{system.maintenance_message}}` render token — so the seeded maintenance page reliably shows the operator's message.

# v0.1.7

## Plugins

- **Plugin purge no longer returns a 500** (`Class "Symfony\Component\Process\Process" not found`): `symfony/process` was only a transitive **dev** dependency, so a production image built with `--no-dev` had no `Process` class. The synchronous purge / remove-package path (`PackageManagerRunner`, which runs in the web request rather than the Messenger worker) therefore fatal-errored on `POST /admin/plugins/{plugin}/purge`. `symfony/process` is now a direct production `require`, and a regression test asserts it stays in both the production `require` block and the locked `packages` section so the purge/remove flow keeps working on the shipped image.

## System Maintenance

- **Public maintenance page**: a new seeded, open-access `maintenance` CMS page is shown to visitors while the instance is in maintenance, instead of a bare `503`. Its content renders the operator's live note through a new `{{system.maintenance_message}}` interpolation variable (resolved from `MaintenanceModeService`, with a friendly default when the note is blank), so changing the maintenance message from the admin panel updates the page with no content edit. The maintenance `503` gate now exempts the `maintenance` page's own content fetch and the `languages` list the render needs, so the styled page is reachable during the outage. The frontend keeps a hardcoded fallback for when the seeded page is missing or unreachable.

# v0.1.6

## System Health

- **Worker health no longer falsely reports "not configured"**: the aggregated system-health probe (`GET /admin/system/health`, shown on the admin System and maintenance pages) checked a `MESSENGER_TRANSPORT_DSN` env var that the platform never sets, so **every** instance showed the `worker` component as `not_configured` even though the worker runs fine on its real transport. The probe now reads the single, authoritative worker transport env (`MESSENGER_PLUGIN_OPS_DSN`, with the same `doctrine://default` fallback the messenger config uses), so the worker reports `ok`/`configured` as expected. This was always cosmetic — `not_configured` never degraded the overall verdict — but it was alarming on the maintenance page. No parallel transport alias is introduced; the probe simply targets the transport that is actually running.

# v0.1.5

## System Updates

- **Frontend-only updates**: the frontend ships independently of the core, so an instance already on the newest core can now move to a newer compatible frontend without a full-stack update. New admin endpoints (guarded by the existing `admin.system.read` / `admin.system.update` permissions): `GET /admin/system/update/frontend/releases` (registry-published frontend versions, newest first; fails soft to `available: false` offline), `GET /admin/system/update/frontend/preflight?target=…` (a lightweight, stateless verdict — no destructive-migration/backup checks; downgrade + invalid-version are the only blocks, and an `unknown` installed frontend never falsely blocks), and `POST /admin/system/update/frontend/request` (records a `kind = frontend` operation; the request body omits `accepted_migration_risk` — a frontend swap is stateless). `system_update_operations` gains `kind` (`core` default / `frontend`) and `target_frontend_version`; `GET /admin/system/update/status` and the manager-claim payload now carry both fields. The SelfHelp Manager re-resolves the signed frontend release and performs the authoritative compatibility + signature check before swapping only the frontend container (rolling it back on a failed health check).

# v0.1.4

## System Updates

- **Manager-loop visibility**: a CMS-requested update that nobody picks up is no longer a silent black hole. The backend records when an authenticated SelfHelp Manager last polled the manager endpoints (cache key `selfhelp_manager_last_seen_at`), `GET /admin/system/health` gains a `manager_loop` component (`ok` / `not_configured` / `down` / `degraded`), and `GET /admin/system/update/status` gains a `manager` block (`configured`, `last_seen_at`, `requested_stale`) so the UI can warn when an operation sits unclaimed in `requested`.

## Plugins

- **Open-ended core compatibility policy**: plugin manifests should declare a minimum core version without an upper bound (`compatibility.selfhelp: ">=0.1.0"`); `pluginApiVersion` is the breakage contract and registry `blocked` flags/advisories handle retroactive breakage. Documented in `docs/developer/26-plugin-compatibility-rules.md` and the plugin developer guide.

# v0.1.3

## Development Environment

- **Pinned Docker image versions**: All third-party Docker images now use specific version tags instead of `latest` for reproducibility. Mailpit pinned to v1.30.1, Redis to 7-alpine, Mercure to v0.16.
- **Windows line ending fix**: Added `.gitattributes` to enforce LF line endings for shell scripts (`*.sh`), preventing Docker container execution failures on Windows due to CRLF line endings.

# v0.1.2

## Release Automation

- **Automatic core release candidates**: Tagging the backend now hands the new version + the three built image digests to the unified registry's `auto-core-release` workflow, which resolves compatibility against the latest published frontend and stages the signed core release as a reviewed PR. Publishing still requires a human to verify the digests and merge — the candidate is automatic, the publish is not.
- **`release-manifest.json`**: New self-declaration at the repo root with the SemVer ranges of the counterparts this backend supports (`supports.frontend`, `supports.manager`), the direct-upgrade floor, and the plugin API version. The registry resolver reads it at the released tag; widen/bump `supports.frontend` deliberately — every pre-1.0 minor is breaking.
- **Release consistency gate**: `docker-release.yml` now hard-fails when `selfhelp_cms_version_default` in `config/services.yaml` does not equal the tag, or when `release-manifest.json` `pluginApiVersion` drifts from `services.yaml`.

# v0.1.1

## System Maintenance & Updates

- **Registry-fed update picker**: New `GET /cms-api/v1/admin/system/update/releases` endpoint lists the core versions published in the official registry (newest first) so the admin "Request an update" picker offers real versions instead of free-typed guesses. Fails soft to `available: false` when the registry is unreachable.
- **Deployment kind in version summary**: `GET /admin/system/version` now reports `deployment: docker|source` so the admin UI can distinguish a managed Docker image install from a source/dev checkout. The production images bake `SELFHELP_DEPLOYMENT=docker`; everything else reports `source`.
- **Deterministic offline-registry tests**: The test environment now pins the registry base URL to a closed local port (`when@test` in `services.yaml`), so registry-dependent endpoints (advisories, preflight, releases) degrade offline the same way in CI and locally. Fixes the flaky `Advisories is admin only and degrades gracefully offline` security test.

## Security

- **Dependabot alerts resolved** (issue #55): all Symfony/Twig advisories fixed via the 7.4.13 / 3.27.1 patch line (`composer audit` is clean) and `aquasecurity/trivy-action` pinned to the safe `0.35.0` immutable release commit in `docker-release.yml`.

# v0.1.0

## Registration & User Management

- **Multi-group registration**: Users can now be enrolled in multiple groups at once during registration. Admins can select multiple groups in the register section, and new users are automatically added to all selected groups.
- **Open registration**: Admins can enable open registration that allows users to sign up with just their email address, no invitation code required. This is perfect for public-facing registrations.
- **Customizable registration labels**: All registration lifecycle labels (form fields, buttons, status messages) are now fully customizable through the CMS with support for multiple languages.

## Plugin System

- **Plugin registry integration**: Built-in plugin registry browser shows available plugins from configured sources. Browse, discover, and install plugins directly from the admin interface.
- **Official Humdek registry**: Pre-configured with the official Humdek plugin registry for easy access to trusted plugins.
- **System-managed plugin sources**: Core plugin sources are protected and can only be modified by system administrators, preventing accidental changes to critical registry configurations.
- **Improved plugin development**: Better support for local plugin development with automatic stylesheet URL resolution for live-reload during development.

## Cross-Repository CI/CD

- **Coordinated feature branch support**: CI workflows now support coordinated development across multiple repositories using the same branch name. Feature branches automatically validate against matching branches in sibling repos instead of always comparing to main.
- **Same-branch-or-main resolution**: Smart CI resolves sibling repository references to matching feature branches when available, falling back to main for solo branches or after merge.

## User Impersonation

- **Admin user impersonation**: Administrators can now impersonate any user to view the platform from their perspective. Perfect for troubleshooting user issues and providing support.
- **Audit logging**: All actions performed during impersonation are logged with both the original admin and the target user, maintaining a complete audit trail.
- **Real-time impersonation status**: Impersonation status is pushed in real-time via Mercure, so the UI immediately shows when impersonation is active.
- **Stop impersonation**: Dedicated endpoint to stop impersonation with proper JWT blacklisting.

## Real-Time Updates

- **ACL push notifications**: User permission changes are pushed in real-time via Mercure, eliminating the need for polling. When a user's permissions change, the UI updates instantly.
- **Impersonation notifications**: Real-time updates when impersonation starts or stops, providing immediate feedback to administrators and users.

## Security & Authentication

- **OAuth 2.0 compliant tokens**: Impersonation tokens follow RFC 8693 OAuth 2.0 Token Exchange standard for better compatibility and security.
- **Configurable token lifetimes**: All JWT token lifetimes (access, refresh, impersonation) are configurable via environment variables with sensible defaults.
- **Enhanced security**: Updated to latest Symfony security patches and dependency updates for improved security posture.

## Content Management

- **Complete style documentation**: Every CMS style is now fully documented with both administrator and developer perspectives, making it easier to understand and use the style system.
- **SEO improvements**: Page endpoints now return title and description metadata for better search engine optimization.
- **Canonical database schema**: Database tables and columns now follow consistent naming conventions (lowercase_snake_case) for better maintainability.

## Architecture & Performance

- **Doctrine migrations only**: Database bootstrap now uses only Doctrine migrations, eliminating the need for SQL bootstrap scripts and making upgrades more reliable.
- **Improved transaction handling**: All data-changing operations are wrapped in database transactions with comprehensive audit logging.
- **Performance optimizations**: Fixed N+1 query issues, added batch processing, and improved database query efficiency throughout the application.

## API & Integration

- **REST API v1**: Comprehensive REST API with versioning, JWT authentication, and refresh token support for secure third-party integrations.
- **Role-based access control**: Granular permission system with roles and permissions for fine-grained access control.
- **API request logging**: All API requests are logged for security auditing and debugging purposes.

## Documentation

- **Complete developer documentation**: Comprehensive documentation for authentication, authorization, plugin development, and CMS styling.
- **API usage guides**: Detailed guides for API endpoints, authentication flows, and common integration patterns.
- **Cross-repo compatibility**: Documentation for managing version alignment across the SelfHelp ecosystem.
