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
