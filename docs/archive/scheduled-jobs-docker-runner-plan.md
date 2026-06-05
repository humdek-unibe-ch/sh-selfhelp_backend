# Scheduled Jobs Docker Runner and Communication Preferences Plan

Audience: Maintainers and future implementation agents.
Status: planning handoff in archive.
Applies to: SelfHelp2 backend, shared package, web frontend, mobile app, and owned plugins.
Last verified: 2026-06-05.
Source of truth: Runtime code in each repository, then active Symfony/Next/Expo/shared contracts, then this handoff. This file supersedes the 2026-06-03 draft for this combined scheduler and user-preference work.

## How To Use This File

This is a detailed prompt for a new model or agent that has not seen the earlier conversation. It combines:

- the Docker scheduled-job runner plan;
- backend issue #29, "Expand user profile notification/mail preferences";
- the migration from legacy `@...` placeholders to double-curly `{{...}}` interpolation;
- user-timezone-aware wall-clock action scheduling;
- the cross-repository scan performed on 2026-06-05.

The agent implementing this should read the `AGENTS.md` in every touched repository first, then verify the referenced files because runtime code is the source of truth. Do not implement only the Docker runner and leave issue #29 out: the delivery preference guard must be part of the scheduled-job execution story.

## One Sentence Goal

SelfHelp should have a reliable Docker-safe scheduled-job runner; every scheduled email or notification delivery must respect recipient profile preferences; all new interpolation must use `{{...}}`; and wall-clock action schedules must execute at the recipient user's local intended time.

## Repositories Scanned On 2026-06-05

Relevant repositories:

- `sh-selfhelp_backend`: Symfony backend. Owns user records, profile APIs, scheduled jobs, lookup/status seeds, migrations, JSON schemas, permissions, caching, audit transactions, and the future Docker scheduler command/service.
- `sh-selfhelp_shared`: Shared TypeScript package consumed by web and mobile. Owns mobile-facing endpoints, auth/user-data types, permission constants, CMS style interfaces, and schema parity checks.
- `sh-selfhelp_frontend`: Next.js admin/public web frontend. Owns web profile UI, admin user UI, admin scheduled-jobs UI, BFF API config, React Query hooks, and frontend-local admin response types.
- `sh-selfhelp_mobile`: Expo mobile app. Owns mobile profile screens, auth store/session persistence, push-token reporting, and consumes shared user-data types/endpoints.
- `plugins/sh2-shp-survey-js`: Owned SurveyJS plugin. It currently declares `"scheduledJobs": []`; no direct issue #29 implementation is needed unless a plugin later schedules jobs.
- `plugins/sh2-plugin-registry`: Public plugin registry. No direct implementation needed for this issue.
- `sh-selfhelp`: legacy CMS reference only. Do not edit it for new SelfHelp2 work.

Repository-specific rules that matter:

- Backend migrations must be generated with the repository's Doctrine migration command. Do not invent migration class names.
- Do not edit `db/legacy` for new backend behavior.
- Backend code changes require focused tests and `composer phpstan` with 0 errors.
- Backend API response/request changes require JSON schemas.
- Shared user-data schema drift is checked by `scripts/check-schema-parity.mjs`.
- Frontend admin endpoints are mostly frontend-local, while mobile-facing auth/profile endpoints belong in `@selfhelp/shared`.
- CMS style field changes require shared style types, frontend/mobile renderers when consumed, backend seed migrations, and `docs/reference/styles/` updates.

## Existing Backend Facts

Current scheduled-job execution already has the right central entrypoint:

- `src/Service/Core/JobSchedulerService.php`
  - `executeJob(int $jobId, string $transactionBy): ScheduledJob|false` is used by admin manual execution, one-off CLI execution, user validation execution, immediate action execution, and the due-job command.
  - `executeByType()` calls plugin scheduled-job handlers first, then core `email`, `notification`, and `task` handlers.
  - `executeEmailJob()` currently sends via Symfony Mailer without checking user preferences.
  - `executeNotificationJob()` currently sends via Firebase HTTP v1 without checking user preferences.
- `src/Repository/ScheduledJobRepository.php`
  - `findJobsToExecute()` finds all due queued jobs, but does not apply a DB-level limit.
- `src/Command/ScheduledJobsExecuteDueCommand.php`
  - Existing command: `php bin/console app:scheduled-jobs:execute-due`.
  - Currently fetches all due jobs and slices in PHP when `--limit` is used.
- `src/Command/ScheduledJobExecuteOneCommand.php`
  - Existing command: `php bin/console app:scheduled-jobs:execute-one <jobId>`.
- `src/Service/Action/ActionImmediateExecutorService.php`
  - Executes immediately-due action jobs through `JobSchedulerService::executeJob()`.
- `src/Service/CMS/Admin/AdminScheduledJobService.php`
  - Admin "execute" calls `JobSchedulerService::executeJob()`.

Current user/profile facts:

- `src/Entity/User.php` has no persisted mail or notification preference fields.
- `src/Service/Auth/ProfileService.php` handles user self-service name, timezone, password, and delete operations.
- `src/Controller/Api/V1/Auth/ProfileController.php` exposes:
  - `PUT /auth/user/name`
  - `PUT /auth/user/timezone`
  - `PUT /auth/user/password`
  - `DELETE /auth/user/account`
- `src/Service/Auth/UserDataService.php` builds the `/auth/user-data` response.
- `config/schemas/api/v1/responses/auth/user_data.json` is the backend schema for the current-user payload.
- `src/Service/CMS/Admin/AdminUserService.php` builds admin user create/update/list/detail payloads.

Current lookup/status facts:

- `src/Service/Core/LookupService.php` defines scheduled-job statuses:
  - `queued`
  - `running`
  - `done`
  - `failed`
  - `cancelled`
  - `deleted`
- The delivery transaction types currently include:
  - `send_mail_ok`
  - `send_mail_fail`
  - `send_notification_ok`
  - `send_notification_fail`
- There are no skipped delivery statuses or skipped delivery transaction types yet.

Current plugin facts:

- `src/Plugin/ScheduledJob/PluginScheduledJobHandlerInterface.php` returns `bool`.
- `src/Plugin/ScheduledJob/PluginScheduledJobRegistry.php` returns `bool|null`.
- Keep this interface compatible for now. A typed scheduled-job result can wrap plugin booleans inside the host.

## Existing Shared, Frontend, Mobile Facts

Shared package:

- `src/types/auth.ts` defines `IUserData`.
- `src/api/endpoints.ts` defines mobile-facing auth/user endpoints.
- `src/types/styles/auth.ts` defines `IProfileStyle`.
- `scripts/check-schema-parity.mjs` checks backend `responses/auth/user_data.json` required fields against shared auth types.
- Shared permission constants include `admin.scheduled_job.read`, `execute`, and `delete`, but not `admin.scheduled_job.manage`.

Web frontend:

- `src/types/auth/jwt-payload.types.ts` defines frontend-local `IUserData` and `IAuthUser`.
- `src/hooks/useUserData.ts` transforms backend `IUserData` into `IAuthUser`.
- `src/api/auth.api.ts` wraps profile/user-data calls.
- `src/hooks/mutations/useProfileMutations.ts` owns profile mutations.
- `src/app/components/frontend/styles/ProfileStyle.tsx` renders the web profile UI.
- `src/config/api.config.ts` owns BFF route config and admin endpoint permissions.
- `src/api/admin/scheduled-jobs.api.ts`, `src/hooks/useScheduledJobs.ts`, and `src/types/responses/admin/scheduled-jobs.types.ts` own admin scheduled-job client code.
- `src/app/components/cms/scheduled-jobs/` owns the admin scheduled-jobs pages/list/calendar/detail UI.
- `src/api/admin/user.api.ts`, `src/hooks/useUsers.ts`, `src/types/requests/admin/users.types.ts`, `src/types/responses/admin/users.types.ts`, and `src/app/components/cms/users/user-form-modal/UserFormModal.tsx` own admin user management.

Mobile app:

- `services/userService.ts` fetches current user via shared `ENDPOINTS.AUTH.USER_DATA`.
- `stores/authStore.ts` stores `IUserData`.
- `services/authService.ts` refreshes user data after login.
- `app/(app)/profile.tsx` renders a fallback profile screen if the CMS profile page is unavailable.
- `components/styles/auth/Profile.tsx` renders the CMS `profile` style on mobile, currently only user info plus logout.
- `native/notifications.ts` handles OS notification permission and Expo push token creation.
- `native/devicesService.ts` reports push tokens to `/auth/devices` if the backend supports it.

## Product Decisions And Current Proposal

Issue #29 says scheduled jobs must not send emails or notifications to users who disabled that delivery type. At the same time, account/security/admin-required mail can be dangerous to block. The plan therefore uses an explicit delivery policy instead of treating every email the same.

Recommended delivery policies:

- `respect_user_preferences`
  - Default for normal scheduled emails and notifications.
  - If the delivery target is a known user and the matching user preference is disabled, the job is skipped and audited.
- `required_system`
  - For account/security or admin-required mail that must be sent even when `receives_emails` is false.
  - Examples likely include validation mail, password reset mail, two-factor login codes, account deletion confirmations, and admin activation/resend mail.
  - This policy must be visible in job config/admin detail and audited; do not silently bypass preferences.

Recommended rule:

- The preference applies at delivery time to every scheduled `email` and `notification` job that targets a known user and has `delivery_policy = respect_user_preferences`.
- The preference is checked only when the job is executed. All mails and notifications are still queued so the final delivery decision is auditable.
- `required_system` applies only to email jobs unless the product later defines mandatory push notifications.
- External recipient emails that do not map to a user account have no stored SelfHelp preference, so they can be sent if the email address is valid and the job policy allows sending.

Open design questions for maintainers:

1. Which exact email types are `required_system`? Recommended initial list: validation, password reset, 2FA, account deletion confirmation, admin activation/resend. Is welcome mail required or preference-controlled?
2. Who may create `required_system` jobs? Recommended: backend-owned auth/admin workflows only at first. If custom admin actions can mark mail as required, gate it behind a stronger permission and show an audit warning.
3. Should `required_system` ignore only `receives_emails`, or should it also ignore blocked/locked user status? Recommended: it ignores only the communication preference; blocked/locked account policy remains separate.
4. Should external/shared mailboxes be sent by default when no user exists? Recommended: yes, because no user preference exists; show a UI warning that preferences cannot be applied.
5. Should CC/BCC be allowed for user-preference-controlled action mail? Recommended: avoid CC/BCC for normal user-targeted action jobs, or resolve/filter every known CC/BCC user before send. For privacy and audit, prefer one To-recipient job with no user CC/BCC.

## Target Architecture Decisions

Keep one per-job execution function:

```php
JobSchedulerService::executeJob(int $jobId, string $transactionBy): ScheduledJob|false
```

Add one due-runner function:

```php
ScheduledJobRunnerService::runDueJobs(
    RunnerTrigger $trigger,
    ?int $limit = null,
    bool $force = false,
    bool $dryRun = false
): ScheduledJobRunResult
```

Callers:

- Docker scheduler container calls `app:scheduled-jobs:execute-due`, which calls `runDueJobs(trigger: scheduler)`.
- Admin "Run due jobs now" endpoint calls `runDueJobs(trigger: manual, force: true)`.
- Existing single-job admin endpoint keeps calling `executeJob()`.
- Existing one-off CLI command keeps calling `executeJob()`.
- Existing immediate action execution keeps calling `executeJob()`.
- Plugin handlers are called only from inside `executeJob()` through `executeByType()`.

Terminology:

- Use `scheduler` or `runner` in code and API.
- Use `cron` only in legacy references.
- In Docker v1, the tick source is the scheduler container, not the host crontab.

## Implementation Slice 0: Canonical Interpolation Syntax

The old action/mail code still contains legacy `@placeholder` interpolation such as `@user`, `@user_name`, and `@user_code`. New SelfHelp2 code should use Mustache-style double curly placeholders everywhere: `{{...}}`.

Current scan on 2026-06-05:

- Backend CMS/page interpolation already uses `App\Service\Core\InterpolationService` and Mustache `{{variable}}` syntax.
- Shared/frontend/mobile CMS rendering already uses `{{variable}}` through `replaceCalcedValues()` and renderer helpers.
- Backend action email/notification scheduling still manually replaces:
  - `@user`
  - `@user_name`
  - `@user_code`
- Seeded reset-password copy still contains legacy `@project` and `@link` in `migrations/Version20260501000600.php`.
- Active docs such as `docs/reference/api/14-admin-actions.md` still show `@user` and `@user_name`.
- Active action config schema help text in `config/schemas/api/v1/requests/admin/action_config.json` still teaches `@user` and `@user_name`.
- CSS `@media`, scoped package names such as `@selfhelp/shared`, PHPDoc annotations, and email addresses are not interpolation placeholders and must not be mechanically rewritten.

Canonical placeholder scopes:

- CMS/page content keeps the existing namespaced Mustache scopes:
  - `{{system.user_name}}`
  - `{{globals.variable_name}}`
  - `{{parent.field_name}}`
  - `{{record.field_name}}` where record data is explicitly supplied.
- Action email/notification templates should use:
  - `{{recipient.email}}`
  - `{{recipient.name}}`
  - `{{recipient.user_name}}`
  - `{{recipient.code}}`
  - `{{recipient.timezone}}`
  - `{{record.field_name}}` for submitted or source-row values
  - `{{system.project_name}}`
  - `{{system.platform_url}}`
- Auth/account mail should use:
  - `{{user.email}}`
  - `{{user.name}}`
  - `{{user.user_name}}`
  - `{{mail.link}}` for one-off links such as reset/validation links
  - `{{mail.code}}` for 2FA or validation codes
  - `{{system.project_name}}`

Migration map:

- `@user` -> `{{recipient.email}}` in action recipient fields.
- `@user_name` -> `{{recipient.name}}`.
- `@user_code` -> `{{recipient.code}}`.
- `@project` -> `{{system.project_name}}`.
- `@link` -> `{{mail.link}}`.

Repository-wide migration workflow:

1. Search for known legacy placeholders, not every `@` character. Safe starting command:

```bash
rg -n "@user|@user_name|@user_code|@project|@link" .
```

2. Classify each hit as runtime code, schema/help text, seeded CMS copy, active docs, tests, or archived docs. Do not touch CSS at-rules, TypeScript package imports, PHPDoc annotations, email addresses, or social handles unless they are clearly template placeholders.
3. Replace runtime `str_replace()` logic with `InterpolationService`.
4. Replace schema descriptions and frontend/mobile mention suggestions so newly-created configs use only `{{...}}`.
5. Migrate seeded/persisted template content with a generated Doctrine migration or an approved seed rewrite path. Because baseline migrations are the install source, ask maintainers before editing an already-applied baseline; otherwise add a follow-up migration that updates the affected CMS fields and lookup/template rows.
6. Add a regression check in tests or CI that fails if new active source/schema/docs examples reintroduce the known legacy placeholders outside the migration compatibility test fixtures.

Implementation requirements:

- Add or extend a backend template-context builder, for example `ActionTemplateContextBuilder`, so action scheduling can pass one structured context to `InterpolationService`.
- Remove direct `str_replace('@...', ...)` calls from `ActionSchedulerService`.
- Action runtime should interpolate recipient-specific fields after the recipient is known. The existing `ActionConfigRuntimeService::buildRuntimeConfig()` can still interpolate form/submitted values first, but recipient variables must be rendered per recipient during fan-out.
- Keep all new interpolation Mustache-based. Do not introduce a second parser.
- Add a temporary legacy detector for persisted configs that still contain `@user`, `@user_name`, `@user_code`, `@project`, or `@link`. For pre-release, prefer migrating seed/config data and rejecting new legacy placeholders in admin validation. If a compatibility fallback is needed, log a deprecation warning transaction and remove it before release.
- Update `config/schemas/api/v1/requests/admin/action_config.json` descriptions so admin forms no longer teach `@user` or `@user_name`.
- Update frontend mention/autocomplete helpers so admins insert `{{recipient.email}}`, `{{recipient.name}}`, `{{recipient.code}}`, `{{record.field_name}}`, and `{{system.project_name}}`, not legacy `@...` tokens.
- Update shared docs/types only where they expose placeholder examples.
- Update active backend docs, especially `docs/developer/10-interpolation-system.md`, `docs/developer/11-scheduled-jobs.md`, and `docs/reference/api/14-admin-actions.md`.
- If other ad hoc non-Mustache placeholders are found during implementation, such as single-brace UI countdown placeholders, document whether they are display-format tokens or real interpolation variables. Real interpolation variables should move to `{{...}}`.

Required tests:

- Unit tests for the backend template-context builder:
  - renders `{{recipient.email}}`, `{{recipient.name}}`, `{{recipient.code}}`, `{{record.field_name}}`, and `{{system.project_name}}`;
  - leaves unknown placeholders visible instead of deleting them silently if that is the current interpolation contract;
  - rejects or flags legacy `@...` placeholders in new action config.
- `ActionSchedulerServiceTest` should use `{{recipient.email}}` instead of `@user`.
- `MailTemplateServiceTest` should cover `{{mail.link}}` and `{{system.project_name}}`.
- Add a lightweight active-source/static regression test or script that allows archived historical references but fails if runtime code, active schemas, active docs, or frontend/mobile examples reintroduce the known legacy placeholders.
- Shared/frontend/mobile interpolation tests should remain green and add examples only if their public helper docs change.

## Implementation Slice T: User-Timezone-Aware Scheduling

Scheduled action times that represent a wall-clock time must be calculated in the target user's timezone, then persisted in UTC. The user-visible intent is: if an action says "send at 07:00", the user receives it at 07:00 in their configured timezone.

Current scan on 2026-06-05:

- `src/Service/Action/ActionScheduleCalculatorService.php` creates `now` in UTC.
- `send_on_day_at`, repeater `schedule_at`, and fixed datetimes are currently applied in UTC.
- `ActionScheduleCalculatorService::calculateDates()` does not receive the recipient user or timezone.
- `ActionSchedulerService` receives the recipient user id before creating the job, so it can calculate per-recipient dates once the service signature supports it.

Required behavior:

- Resolve the recipient timezone from `User::getTimezone()` and fall back to the CMS default timezone, then to `Europe/Zurich` only if the existing fallback path still does that.
- Interpret wall-clock schedule fields in the recipient timezone:
  - `custom_time` when the UI means local date/time;
  - `send_on_day_at`;
  - repeater `schedule_at`;
  - "after period on day at time";
  - reminder day/time rules when they represent local wall-clock delivery.
- Convert the calculated local datetime to UTC before persisting `scheduled_jobs.date_to_be_executed`.
- Keep purely relative elapsed schedules absolute:
  - immediate jobs;
  - "after 2 hours" without a specific local time;
  - retry/backoff windows if added later.
- Store enough schedule metadata to preserve wall-clock intent:
  - original local datetime, for example `config.schedule.local_datetime`;
  - timezone used for calculation, for example `config.schedule.timezone`;
  - boolean `config.schedule.wall_clock = true`;
  - original schedule rule when available.
- When a user changes timezone, recalculate that user's queued future wall-clock jobs so `07:00` remains `07:00` in the new timezone. Do not recalculate jobs that are already `running`, `done`, `failed`, skipped, `cancelled`, or `deleted`.
- Do not recalculate relative elapsed jobs unless product explicitly requests it.
- Log a transaction when timezone preference changes cause queued job execution times to be recalculated.
- Invalidate scheduled-job caches after recalculation.

Implementation approach:

- Update `ActionScheduleCalculatorService` to accept a schedule context, for example:

```php
ActionScheduleCalculatorService::calculateDates(
    array $actionConfig,
    array $job,
    ActionScheduleContext $context
): array
```

- `ActionScheduleContext` should carry:
  - current time provider value, for testability;
  - recipient timezone;
  - source/user id;
  - whether the schedule should be interpreted as wall-clock.
- In `ActionSchedulerService`, calculate execution dates after resolving the recipient user, not once globally for all recipients.
- Add a service such as `QueuedJobTimezoneAdjustmentService` and call it from `ProfileService::updateTimezone()` after persisting the user's new timezone.
- For fan-out email jobs, each recipient job stores its own timezone metadata.

Open design questions:

1. When a local time is skipped by DST spring-forward, should the job send at the next valid local time or fail validation? Recommended: next valid local time with an audit note.
2. When a local time is ambiguous during DST fall-back, should the first or second occurrence be used? Recommended: first occurrence unless product wants a different rule.
3. Should fixed `custom_time` values entered with an explicit timezone offset be respected as absolute instants, or normalized to recipient-local wall-clock time? Recommended: if an offset is present, treat it as absolute; if no offset is present, treat it as recipient-local.

Required tests:

- Unit tests for `ActionScheduleCalculatorService`:
  - Europe/Zurich 07:00 in winter persists as 06:00 UTC.
  - Europe/Zurich 07:00 in summer persists as 05:00 UTC.
  - America/New_York 07:00 persists with the correct UTC offset for the date.
  - relative "after 2 hours" remains elapsed-time based, not wall-clock shifted.
  - DST skipped/ambiguous times follow the chosen policy.
- Integration tests for action scheduling:
  - two users in different timezones receive separate queued jobs with different UTC `date_to_be_executed` values for the same local 07:00 rule.
  - changing a user's timezone recalculates only that user's queued future wall-clock jobs.
- Golden tests:
  - form submission -> action scheduling -> queued job at recipient-local 07:00 -> due runner executes at the matching UTC time.
  - same workflow with `receives_emails = false` ends in skipped status and logs the skip.
  - same workflow with multi-recipient fan-out creates separate jobs and statuses.

## Implementation Slice A: Issue #29 Communication Preferences

Implement this slice before or alongside the Docker runner. It is independently valuable and all scheduled-job callers benefit because they already converge on `JobSchedulerService::executeJob()`.

### A1. Database and Lookups

Generate a Doctrine migration with the backend command. Do not manually create the migration filename or class.

Add two non-null boolean columns to `users`:

- `receives_notifications` boolean, default `1`
- `receives_emails` boolean, default `1`

Why default true:

- It preserves existing behavior for all current users and QA fixtures.
- It prevents surprise loss of account communication on upgrade.

Add two scheduled-job status lookups under `scheduledJobsStatus`:

- `skipped_user_disabled_notifications`
  - value suggestion: `Skipped: notifications disabled by user`
  - description: `The notification was intentionally not sent because the target user disabled notifications.`
- `skipped_user_disabled_emails`
  - value suggestion: `Skipped: emails disabled by user`
  - description: `The email was intentionally not sent because the target user disabled emails.`

Add skipped delivery transaction types under `transactionTypes`:

- `send_notification_skipped`
- `send_mail_skipped`

These transaction types make audit/history clear without treating the delivery as a failure.

Add an explicit email delivery policy in scheduled-job config and schemas. The policy catalog must be lookup-backed so the admin API/UI can show stable labels and so future policy values are not hard-coded in multiple clients:

- Field name: `delivery_policy`
- Lookup type: `scheduledJobDeliveryPolicies`
- Values:
  - `respect_user_preferences`
  - `required_system`
- Default: `respect_user_preferences`

Store the stable lookup code in `scheduled_jobs.config.email.delivery_policy` for the first implementation. Seed the lookup rows in the generated Doctrine migration, expose labels/descriptions through the existing lookup APIs where useful, and update `config/schemas/jobs/email_job.json` plus any API schemas that expose scheduled-job config. Add constants in `LookupService` or a small domain class so policy codes are not repeated.

Add recipient snapshot persistence so a scheduled email job can be linked to a user when one exists, while still supporting external/shared mailboxes:

Proposed table: `scheduled_job_recipients`

- `id`
- `id_scheduled_jobs` FK to `scheduled_jobs`, not null
- `channel` string, initially `email`
- `recipient_type` string: `to`, `cc`, `bcc`
- `recipient_email` string, nullable only for non-email channels
- `id_users` nullable FK to `users`
- `delivery_policy` string, default `respect_user_preferences`
- `resolved_from` nullable string: `user`, `external_email`, `action_config`, `admin_input`, `system`
- `created_at`

Rules:

- `scheduled_jobs.id_users` is already nullable in the current schema and entity; keep it nullable.
- For user-targeted single-recipient jobs, keep `scheduled_jobs.id_users` set for compatibility and also write one recipient snapshot.
- For external/shared mailbox jobs, keep `scheduled_jobs.id_users = null` and write a recipient snapshot with `recipient_email`.
- For the initial fan-out design, enforce one primary `to` recipient per scheduled email job. This avoids partial job status and makes skipped/done/failed status meaningful.
- Keep `scheduled_jobs.config.email.recipient_emails` as a compatibility snapshot containing exactly the primary recipient email for new fan-out jobs. The executor should prefer `scheduled_job_recipients` when present and fall back to config for legacy rows.

If the same implementation also adds runner admin settings, add `admin.scheduled_job.manage` in the generated migration. Otherwise leave that permission to Slice B.

Do not edit old baseline migrations unless the team explicitly says the baseline is still being rewritten. Do not edit `db/legacy`.

### A2. Backend User Entity and Profile API

Update `src/Entity/User.php`:

- Add properties mapped to `users.receives_notifications` and `users.receives_emails`.
- Add getters that read naturally:
  - `receivesNotifications(): bool`
  - `setReceivesNotifications(bool $receivesNotifications): static`
  - `receivesEmails(): bool`
  - `setReceivesEmails(bool $receivesEmails): static`

Update `src/Service/Auth/UserDataService.php`:

- Add `receives_notifications` and `receives_emails` to the `/auth/user-data` payload.
- Keep snake_case in API responses to match existing backend wire format.
- Include the fields in the cache payload; profile updates must invalidate user caches.

Update `config/schemas/api/v1/responses/auth/user_data.json`:

- Add both fields as required booleans in `data.properties`.
- Add them to `data.required`.

Add `config/schemas/api/v1/requests/auth/update_communication_preferences.json`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "Update Communication Preferences Request",
  "description": "Schema for updating user email and notification delivery preferences",
  "type": "object",
  "properties": {
    "receives_notifications": { "type": "boolean" },
    "receives_emails": { "type": "boolean" }
  },
  "required": ["receives_notifications", "receives_emails"],
  "additionalProperties": false
}
```

Update `src/Service/Auth/ProfileService.php`:

- Add `updateCommunicationPreferences(User $user, bool $receivesNotifications, bool $receivesEmails): User`.
- Fetch the managed user inside the transaction, matching existing name/timezone patterns.
- Persist both booleans.
- Log an update transaction on `users` with old and new values.
- Invalidate user caches with the existing `invalidateUserCaches()` helper.

Update `src/Controller/Api/V1/Auth/ProfileController.php`:

- Add `PUT /auth/user/communication-preferences`.
- Validate against `requests/auth/update_communication_preferences`.
- Return the refreshed `responses/auth/user_data` envelope with `logged_in = true`.

Update route metadata:

- Add the route using the same mechanism existing profile routes use.
- If the route is database-backed, add it in a generated migration.
- It should require an authenticated JWT user but no admin permission.

### A3. Backend Admin User API

Update `src/Service/CMS/Admin/AdminUserService.php`:

- `buildUserFromData()` should set both preferences, defaulting to true.
- `updateUserFromData()` should update either field when present.
- `formatUserForList()` and `formatUserForDetail()` should include:
  - `receives_notifications`
  - `receives_emails`
- User cache invalidation already happens after create/update; keep it.

Update backend schemas:

- `config/schemas/api/v1/requests/admin/create_user.json`
- `config/schemas/api/v1/requests/admin/update_user.json`
- `config/schemas/api/v1/responses/admin/users/user.json`

Add the same boolean fields. They should be optional in create/update requests and default true in backend code.

Update tests:

- `tests/Controller/Api/V1/AdminUserControllerTest.php`
  - create user defaults both true;
  - create/update accepts false;
  - list/detail payloads include both fields.

### A4. Scheduled-Job Delivery Enforcement

The preference check must be inside `JobSchedulerService` execution, not only scheduling.

Add a typed internal result for job handlers. A simple value object is enough:

- `src/Service/Core/ScheduledJobExecutionResult.php`
- suggested factories:
  - `done(string $message = '')`
  - `failed(string $message = '')`
  - `skipped(string $finalStatusCode, string $message = '')`
- Include helpers such as `isSuccessfulForRunnerMetrics()`, `getFinalStatusCode()`, and `getMessage()` if useful.

Then update `JobSchedulerService` internally:

- `executeByType()` returns `ScheduledJobExecutionResult`.
- `executeEmailJob()` returns a result instead of bool.
- `executeNotificationJob()` returns a result instead of bool.
- `executeTaskJob()` can wrap the existing task bool result.
- Plugin handlers keep the existing bool interface; the registry bool result is wrapped into `done()` or `failed()`.

Do not force plugins to update for this slice.

Scheduling persistence changes:

- Add a `ScheduledJobRecipient` entity/repository for `scheduled_job_recipients`.
- Update `JobSchedulerService::scheduleJob()` or `storeJobConfig()` to persist recipient snapshots when `jobData['recipients']` is supplied.
- Add a compatibility path that derives one recipient snapshot from `jobData['email_config']['recipient_emails']` when a new email job is scheduled without explicit `recipients`.
- Keep `scheduleDirectEmailJob()` able to schedule external emails by accepting `userId = null` and a valid `recipient_emails` value.
- Add a clearer helper such as `scheduleEmailDeliveryJob(array $emailConfig, ScheduledEmailRecipient $recipient, DateTimeInterface $date, string $deliveryPolicy)` if it removes repeated recipient normalization logic.

Email preference logic:

- Resolve the job's delivery policy. Default missing legacy values to `respect_user_preferences`.
- Resolve the primary delivery target from `scheduled_job_recipients` when present, falling back to `config.email.recipient_emails` and `ScheduledJob::getUser()` for legacy rows.
- If `delivery_policy = required_system`, do not apply `receivesEmails()`. Send the email if the recipient email is valid and all normal mailer requirements pass.
- If `delivery_policy = respect_user_preferences`, the delivery target has a linked `User`, and `receivesEmails()` is false, do not call Mailer.
- Log `send_mail_skipped` on the `scheduled_jobs` table when preferences block delivery.
- Return `skipped(LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS, ...)` when preferences block delivery.
- Set `date_executed` when finalizing the job.

Recipient nuance:

- Current `scheduled_jobs.id_users` is nullable, so the base job can already represent an external/shared mailbox job. Do not make a user mandatory for email jobs.
- For new action/admin email scheduling, normalize the recipient list first, then create one scheduled email job per `to` recipient.
- Each created job should have exactly one primary recipient snapshot. If the primary recipient maps to a user by id or email, set both `scheduled_jobs.id_users` and `scheduled_job_recipients.id_users`. If no user exists, set both user references to null and keep `recipient_email`.
- This fan-out avoids partial delivery states. Each job can end in one clear status: `done`, `failed`, or a skipped status.
- For legacy rows or manually created rows that still contain multiple `recipient_emails`, the executor must split and process defensively. For known users with disabled email preferences, skip those users. For unknown external addresses, send if valid. Log a warning transaction that the row used legacy multi-recipient execution.
- Prefer moving all new multi-recipient creation paths to fan-out quickly so the legacy fallback is only for old data.

Action scheduler changes:

- Update `src/Service/Action/ActionSchedulerService.php` so email action jobs expand explicit recipient lists and target-group users into one `scheduleJob()` call per email delivery target.
- Add a recipient normalization helper/service instead of ad hoc string splitting in multiple places.
- Supported recipient inputs should include:
  - user ids from target groups;
  - `{{recipient.email}}` placeholder for the current action recipient;
  - explicit email addresses from action config;
  - shared/admin mailbox addresses that do not match a user.
- Resolve explicit email addresses case-insensitively to `User` where possible. If no user is found, keep the external email and do not apply user preferences.
- Store `delivery_policy = respect_user_preferences` for normal action notification emails unless an explicitly authorized admin-required path sets another policy.

System/admin-required mail changes:

- Update `src/Service/Auth/UserValidationService.php` and any password-reset/2FA/account-security mail scheduling code to set `delivery_policy = required_system`.
- For admin activation/resend mail, set `delivery_policy = required_system` if maintainers confirm that activation/resend must always be delivered.
- Do not expose `required_system` as a casual toggle in normal user profile preferences. It is a job policy set by trusted backend scheduling paths.

Notification preference logic:

- If the job has a target `User` and `receivesNotifications()` is false, do not check Firebase config and do not call Firebase.
- Log `send_notification_skipped` on the `scheduled_jobs` table.
- Return `skipped(LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS, ...)`.
- Set `date_executed` when finalizing the job.

Failure versus skipped:

- Disabled preference is not a domain failure.
- It should not mark the job `failed`.
- It should not make the due-runner command return failure.
- It should be visible in admin status and transaction history.

Update `src/Service/Core/LookupService.php` constants:

- Add `SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS`.
- Add `SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS`.
- Add `TRANSACTION_TYPES_SEND_NOTIFICATION_SKIPPED`.
- Add `TRANSACTION_TYPES_SEND_MAIL_SKIPPED`.

Update `JobSchedulerService::executeJob()` finalization:

- Use the result object's final status code instead of `done` or `failed` bool only.
- Always set `date_executed` for terminal statuses: `done`, `failed`, and skipped statuses.
- Keep no-auto-retry behavior.
- Keep scheduled-job cache invalidation.

### A5. Admin Scheduled-Job Display

Update `src/Service/CMS/Admin/AdminScheduledJobService.php`:

- List payload should include status code as well as status value:
  - existing `status`: human value can stay for backwards compatibility;
  - new `status_code`: lookup code.
- List payload should include recipient snapshot fields:
  - `recipient_email`
  - `recipient_user_id`
  - `delivery_policy`
  - `recipient_is_external`
- Detail payload `status` should include:
  - `id`
  - `value`
  - `code`
- Detail payload `job_type` should include `code` as well as `value`.
- Detail payload should include a `recipients` array from `scheduled_job_recipients`, even if the initial fan-out design normally creates only one primary recipient per job.
- Transaction formatting should ideally include `transaction_type_code` as well as the human value.

Update backend schemas:

- `config/schemas/api/v1/responses/admin/scheduled_jobs/scheduled_job_item.json`
- `config/schemas/api/v1/responses/admin/scheduled_jobs/scheduled_job.json`
- `config/schemas/api/v1/responses/admin/scheduled_jobs/job_transactions.json`

The UI can then show skipped statuses reliably without parsing localized text.

## Implementation Slice B: Docker Scheduled-Job Runner

The Docker runner should be built on top of the hardened per-job execution from Slice A.

### B1. Harden Per-Job Execution Against Races

Current `executeJob()` loads a job, sets it to running, and flushes. This can race with another caller.

Required behavior:

- Only `queued` jobs are executable by default.
- Manual execution may execute a future queued job, but not a job already `done`, `failed`, skipped, `cancelled`, `deleted`, or `running`.
- Scheduler execution only receives due queued jobs from the due query.
- Transition from `queued` to `running` must be atomic.
- If another process already moved the job out of `queued`, return `false` without side effects.

Preferred implementation:

- Add a repository or DBAL method such as `claimQueuedJobForExecution(int $jobId, DateTimeInterface $startedAt): bool`.
- It updates `scheduled_jobs` with a `WHERE id = :id AND id_job_status = :queuedStatusId`.
- It sets status to `running`.
- If adding stale detection, also set `date_started`.
- It checks affected row count. `0` means another caller won.

Recommended schema addition:

- Add nullable `scheduled_jobs.date_started`.
- Set it when a job is claimed as `running`.
- Use it to report stale running jobs.

### B2. Runner Settings and Run History

Use dedicated operational tables rather than CMS preferences.

Generate a Doctrine migration. Do not manually create the migration filename or class.

Create `scheduled_job_runner_settings`:

- `id`
- `enabled` boolean, default `1`
- `interval_seconds` integer, default `60`, minimum `60` for scheduler mode
- `max_jobs_per_run` integer nullable or default `100`
- `lock_ttl_seconds` integer default `120`
- `stale_running_after_seconds` integer default `900`
- `updated_at`
- `id_updated_by_users` nullable FK to `users`

Create `scheduled_job_runner_runs`:

- `id`
- `trigger_type` string: `scheduler`, `manual`, `system`
- `status` string: `running`, `succeeded`, `failed`, `skipped_disabled`, `skipped_interval`, `skipped_locked`
- `started_at`
- `finished_at` nullable
- `duration_ms` nullable
- `due_count`
- `attempted_count`
- `done_count`
- `failed_count`
- `skipped_count`
- `lock_acquired` boolean
- `error_message` nullable text
- `settings_snapshot` json nullable

Add entities/repositories only if useful. Follow the existing entity attribute style and naming rules.

### B3. ScheduledJobRepository Due Queries

Update `src/Repository/ScheduledJobRepository.php` with methods:

- `countDueQueuedJobs(DateTimeInterface $now): int`
- `findDueQueuedJobs(DateTimeInterface $now, int $limit): array`
- `countRunningJobs(): int`
- `findStaleRunningJobs(DateTimeInterface $threshold): array`
- `findJobsToExecute()` can remain as a compatibility wrapper or be replaced internally.

Due query rules:

- `status.lookupCode = queued`
- `dateToBeExecuted <= now UTC`
- order by `dateToBeExecuted ASC`, then `id ASC`
- apply `setMaxResults($limit)` in the query, not by slicing in PHP.

### B4. ScheduledJobRunnerService

Create `src/Service/Core/ScheduledJobRunnerService.php`.

Responsibilities:

- Load runner settings, creating the default row if missing.
- Check enabled/disabled.
- Check whether `interval_seconds` has elapsed since the last finished/succeeded/manual run according to the final product policy.
- Acquire a Symfony Lock named `scheduled_jobs_runner`.
- Use non-blocking acquisition.
- Fetch due queued jobs with DB-level limit.
- Call `JobSchedulerService::executeJob()` for each job.
- Track counts:
  - due
  - attempted
  - done
  - failed
  - skipped
  - locked/policy skips
- Write `scheduled_job_runner_runs`.
- Log one `check_scheduledJobs` transaction summary, matching the legacy system intent.
- Invalidate scheduled-job caches after state changes.

Do not put runner settings, lock handling, or run-history concerns into `JobSchedulerService`.

Lock behavior:

- Lock name: `scheduled_jobs_runner`
- TTL default: `120` seconds, read from settings when possible.
- If lock unavailable, create or return a run result with `skipped_locked` and exit success.
- Keep per-job atomic queued-to-running protection even with the lock.

### B5. Console Command

Refactor `src/Command/ScheduledJobsExecuteDueCommand.php` so it delegates to `ScheduledJobRunnerService`.

Options:

- `--limit=<n>` override max jobs for this invocation.
- `--force` bypass enabled/interval checks.
- `--dry-run` show due counts and policy state without executing jobs.
- `--json` optional machine-readable output for health tooling.

Exit-code policy:

- `0`: runner completed, skipped by normal policy, skipped by lock contention, or individual jobs failed/skipped cleanly and were marked.
- `1`: infrastructure failure, invalid configuration, database failure, lock subsystem failure that is not normal contention, or uncaught exception.

Docker behavior:

- The command must be safe to run every 60 seconds forever.
- It must write concise stdout/stderr logs.
- It must not require a TTY or interactive prompt.
- Temporary startup failures from MySQL or Redis should be logged clearly; the Docker loop retries on the next tick.

Production compose concept:

```yaml
scheduler:
  image: selfhelp/backend:${SELFHELP_VERSION}
  restart: unless-stopped
  depends_on:
    backend:
      condition: service_started
    redis:
      condition: service_healthy
    mysql:
      condition: service_healthy
  env_file:
    - .env
  command: >
    sh -lc "while true; do
      php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction;
      sleep $${SCHEDULED_JOBS_TICK_SECONDS:-60};
    done"
```

For Docker production, generated `.env` must include a shared lock backend:

```dotenv
LOCK_DSN=redis://redis:6379
```

Local/file locks are acceptable only for local development. Clustered deployments must use a shared lock backend.

Future improvement: replace the shell loop with a foreground Symfony command such as `app:scheduled-jobs:run-loop` for cleaner signal handling.

### B6. Admin Runner API

Add admin endpoints under `/cms-api/v1/admin/scheduled-jobs/runner`:

- `GET /admin/scheduled-jobs/runner/status`
  - read settings, latest run, health flags, queue counts, stale running count.
- `PUT /admin/scheduled-jobs/runner/settings`
  - update `enabled`, `intervalSeconds`, `maxJobsPerRun`, `lockTtlSeconds`, and optionally `staleRunningAfterSeconds`.
- `POST /admin/scheduled-jobs/runner/enable`
- `POST /admin/scheduled-jobs/runner/disable`
- `POST /admin/scheduled-jobs/runner/run-now`
  - execute due jobs through the runner service with `trigger = manual` and `force = true`.
- `GET /admin/scheduled-jobs/types`
  - expose core job types now; include plugin metadata already available from `AdminScheduledJobService::getJobTypeCatalog()`.

Permission plan:

- `admin.scheduled_job.read`
  - status, run history, job type catalog.
- `admin.scheduled_job.execute`
  - run-now and existing single-job execute.
- `admin.scheduled_job.manage`
  - settings, enable, disable.

Add route rows and permission mappings through a generated Doctrine migration.

Add JSON schemas under `config/schemas/api/v1` for:

- runner status response;
- runner settings request;
- runner settings/status response;
- runner run-now response;
- job type catalog response if not already present.

The status endpoint should expose:

- enabled state;
- configured interval;
- max jobs per run;
- lock TTL;
- last run status;
- last started/finished times;
- next eligible run time;
- due queued jobs count;
- oldest overdue job age;
- running jobs count;
- stale running jobs count;
- last error message;
- whether the scheduler appears stale.

## Shared Package Changes

Update `sh-selfhelp_shared`.

Files:

- `src/types/auth.ts`
  - Add `receives_notifications: boolean`.
  - Add `receives_emails: boolean`.
- `src/interpolation/replaceCalcedValues.ts`
  - Keep double-curly `{{...}}` as the only shared interpolation syntax.
  - Do not add compatibility support for legacy action `@...` placeholders in shared rendering helpers.
- `src/api/endpoints.ts`
  - Add `ENDPOINTS.USER.UPDATE_COMMUNICATION_PREFERENCES`.
- `src/types/styles/auth.ts`
  - Add any new `IProfileStyle` fields used by web/mobile profile renderers.
- `src/types/auth.ts`
  - Add `ADMIN_SCHEDULED_JOB_MANAGE` if Slice B adds that backend permission and shared consumers check it.
- `scripts/check-schema-parity.mjs`
  - It should pass once `IUserData` contains the new backend-required fields.

Recommended profile style fields:

- `profile_communication_preferences_title`
- `profile_communication_preferences_description`
- `profile_receive_notifications_label`
- `profile_receive_notifications_description`
- `profile_receive_emails_label`
- `profile_receive_emails_description`
- `profile_communication_preferences_button`
- `profile_communication_preferences_success`
- `profile_communication_preferences_error_general`

Use the existing style field naming pattern, not a new naming scheme.

Verification:

```bash
npm run typecheck
npm run lint
node scripts/check-schema-parity.mjs
```

Run the exact commands from shared `AGENTS.md` if they differ.

## Web Frontend Changes

Update `sh-selfhelp_frontend`.

Auth/profile contract:

- `src/types/auth/jwt-payload.types.ts`
  - Add `receives_notifications` and `receives_emails` to frontend-local `IUserData`.
  - Add `receivesNotifications` and `receivesEmails` to `IAuthUser`.
  - Add `ADMIN_SCHEDULED_JOB_MANAGE` if Slice B adds it.
- `src/config/mentions.config.ts`
  - Ensure admin editors insert double-curly variables such as `{{recipient.email}}`, `{{recipient.name}}`, and `{{record.field_name}}`.
  - Remove any legacy examples or shortcuts that emit `@user`, `@user_name`, or `@user_code`.
- `src/hooks/useUserData.ts`
  - Transform backend snake_case booleans into `IAuthUser` camelCase fields.
- `src/config/api.config.ts`
  - Add `USER_UPDATE_COMMUNICATION_PREFERENCES`.
- `src/api/auth.api.ts`
  - Add `updateCommunicationPreferences(receivesNotifications: boolean, receivesEmails: boolean)`.
  - Send snake_case request fields.
- `src/hooks/mutations/useProfileMutations.ts`
  - Add `useUpdateCommunicationPreferencesMutation()`.
  - Invalidate `USER_DATA` on success.

Profile UI:

- `src/app/components/frontend/styles/ProfileStyle.tsx`
  - Add a communication-preferences section.
  - Use Mantine `Switch` controls for the two booleans.
  - Initialize from `user.receivesNotifications` and `user.receivesEmails`.
  - Save through the new mutation.
  - Show success/error using the existing profile form patterns.
  - Use icons from `@tabler/icons-react` if the component already uses that icon set.
- If the profile style fields are CMS-controlled, update backend style seed migrations and docs as described in the backend/shared sections.

Admin users:

- `src/types/requests/admin/users.types.ts`
  - Add optional `receives_notifications` and `receives_emails`.
- `src/types/responses/admin/users.types.ts`
  - Add booleans to `IUserBasic` and `IUserDetails`.
- `src/app/components/cms/users/user-form-modal/UserFormModal.tsx`
  - Add switches for notification and email preferences.
  - Include values in create and update payloads.
- `src/api/admin/user.api.ts` and `src/hooks/useUsers.ts`
  - Update only if type changes require it.

Admin scheduled jobs:

- `src/config/api.config.ts`
  - Add runner endpoints and `admin.scheduled_job.manage` permission metadata.
- `src/api/admin/scheduled-jobs.api.ts`
  - Add methods for runner status/settings/enable/disable/run-now/types.
- `src/hooks/useScheduledJobs.ts`
  - Add queries/mutations for runner status/settings/run-now.
  - Invalidate scheduled jobs, scheduled job detail, calendar, and runner status after run-now.
- `src/types/responses/admin/scheduled-jobs.types.ts`
  - Add `status_code`, status `code`, job type `code`, runner types, and skipped statuses.
- `src/app/components/cms/scheduled-jobs/`
  - Add a compact runner status/settings panel to the existing scheduled-jobs admin page.
  - Add status badges for skipped preference statuses.
  - Show recipient email, external-recipient state, and delivery policy in list/detail views.
  - Show `required_system` as an explicit badge or label so admins understand why preferences were bypassed.
  - Add "Run due jobs now".
  - Link counts/health states to filtered scheduled-job views where possible.

UI should show:

- runner enabled/disabled toggle;
- interval;
- max jobs per run;
- last run status;
- last started/finished;
- next eligible run time;
- due queued count;
- oldest overdue job;
- running and stale running counts;
- last error;
- run-now action;
- job type catalog, with plugin badges when metadata is present.

Do not expose crontab editing in the UI. Show deployment guidance only:

- Docker scheduler tick expected every minute.
- Application interval controls whether the runner executes on a tick.

Verification:

```bash
npm run typecheck
npm run lint
npm test -- --runInBand
```

Run the exact focused commands from frontend `AGENTS.md`.

## Mobile App Changes

Update `sh-selfhelp_mobile`.

Shared contract:

- The mobile app receives the new fields through `@selfhelp/shared` `IUserData`.
- Update dependency/build flow according to the mobile repo rules once shared is changed.
- Keep mobile-rendered interpolation aligned with shared double-curly `{{...}}` syntax. Do not add mobile-only support for legacy `@...` placeholders.

API/service:

- `services/userService.ts`
  - Add `updateCommunicationPreferences(receivesNotifications: boolean, receivesEmails: boolean)`.
  - PUT to `ENDPOINTS.USER.UPDATE_COMMUNICATION_PREFERENCES`.
  - Return updated `IUserData`.

Store/query:

- Update auth store/query cache after a successful preference change.
- Keep `receives_notifications` and `receives_emails` in persisted session state if auth persistence serializes the full user object.

UI:

- `app/(app)/profile.tsx`
  - Add fallback controls for email and notification preferences when the CMS profile page is not available.
- `components/styles/auth/Profile.tsx`
  - Add controls for the CMS `profile` style so mobile users also have access when the CMS page renders.
  - Use React Native `Switch`.
  - Keep the backend delivery preference separate from OS push notification permission.

Important mobile distinction:

- `receives_notifications = false` means the backend must not send scheduled push notifications.
- Expo/OS notification permission controls whether the device can display notifications.
- Do not auto-request OS permission just because the backend preference is enabled.
- Do not delete a push token just because the backend preference is disabled; the scheduled-job executor must still enforce the preference.

Verification:

```bash
npm run typecheck
npm run lint
npm test
```

Run the exact commands from mobile `AGENTS.md`.

## Plugin Changes

SurveyJS plugin:

- `plugins/sh2-shp-survey-js/plugin.json` currently has `"scheduledJobs": []`.
- No direct code change is required for issue #29 unless the plugin starts creating scheduled email/notification jobs.

Plugin compatibility:

- Keep host runtime plugin dispatch intact.
- Keep `PluginScheduledJobHandlerInterface::execute()` returning bool for now.
- If plugin job handlers later send their own emails/push messages outside core `JobSchedulerService`, they must use an explicit host delivery service that checks `User.receivesEmails()` and `User.receivesNotifications()`.
- Do not allow plugins to bypass user delivery preferences by sending through raw Mailer/Firebase from plugin code without a documented capability and tests.

Future plugin scheduled-job slice:

- Persist plugin job types as `jobTypes` lookup rows or define one authoritative plugin lookup extension path.
- Add standard plugin payload storage in `JobSchedulerService::storeJobConfig()`.
- Expose plugin job type metadata in `GET /admin/scheduled-jobs/types`.
- Add plugin integration tests for register, schedule, execute, cleanup.

## Backend Style Documentation Changes

If profile UI text fields are added, update:

- `docs/reference/styles/auth/profile.md`
- `docs/reference/styles/index.md` only if the style catalog row needs a changed description, not for every field.
- Shared `IProfileStyle`.
- Frontend `ProfileStyle.tsx`.
- Mobile `components/styles/auth/Profile.tsx` if those fields are consumed on mobile.
- Backend style seed migrations that add new fields/default content.

Keep `Last verified` at `2026-06-05` or the actual implementation date.

## Backend Tests

Test impact analysis:

- Profile data workflow can break because `/auth/user-data` is consumed by web and mobile.
- Admin user workflow can break because user create/update schemas and forms change.
- Scheduled-job execution can break because skipped preference statuses change the status machine.
- Runner workflow can break due jobs, manual execution, command exit policy, cache invalidation, and locks.
- Interpolation can break action config, auth mail templates, frontend variable pickers, and CMS-rendered content if legacy `@...` placeholders are only partly migrated.
- Timezone-aware action scheduling can break due-date calculation, profile timezone updates, and golden form-action workflows.
- Plugin dispatch must remain compatible.

Required focused backend tests for Slice 0 and Slice T:

- `tests/Service/Action/ActionScheduleCalculatorServiceTest.php`
  - local 07:00 in Europe/Zurich winter and summer converts to the correct UTC instant.
  - local 07:00 in America/New_York converts using the date's actual offset.
  - purely relative elapsed schedules are not recalculated as wall-clock schedules.
  - DST skipped/ambiguous local times follow the chosen policy.
- `tests/Unit/Service/Action/ActionSchedulerServiceTest.php`
  - action recipient interpolation uses `{{recipient.email}}`, `{{recipient.name}}`, and `{{recipient.code}}`.
  - no new test fixture relies on `@user`.
  - one action with two recipient users in different timezones creates two jobs with different UTC execution times for the same local delivery time.
- `tests/Service/Auth/ProfileServiceTest.php` or an integration-level profile test
  - changing a user's timezone recalculates only that user's queued future wall-clock jobs.
  - done, failed, skipped, running, cancelled, deleted, and purely relative queued jobs are not recalculated.
- `tests/Golden/FormActionJobChainTest.php`
  - form submission schedules an action email at recipient-local 07:00, the due runner executes it at the matching UTC instant, and the rendered template uses `{{...}}` variables.

Required focused backend tests for Slice A:

- `tests/Controller/Api/V1/Auth/UserDataControllerTest.php`
  - authenticated user-data includes `receives_notifications` and `receives_emails`.
- `tests/Controller/Api/V1/Auth/ProfileControllerTest.php`
  - updating preferences persists and returns updated user-data.
  - unauthenticated request returns 401.
  - invalid payload returns schema validation error.
- `tests/Controller/Api/V1/AdminUserControllerTest.php`
  - admin create defaults preferences true.
  - admin create/update can set false.
  - list/detail include the fields.
- `tests/Service/Core/JobSchedulerServiceTest.php`
  - email job for a user with `receives_emails = false` does not send mail.
  - email job ends in `skipped_user_disabled_emails`.
  - email job for a user with `receives_emails = false` and `delivery_policy = required_system` is sent and ends in `done`.
  - external email job with no linked user is sent when the address is valid.
  - new fan-out jobs use one primary recipient per scheduled job.
  - notification job for a user with `receives_notifications = false` does not call Firebase and ends in `skipped_user_disabled_notifications`.
  - skipped jobs set `date_executed`.
  - skipped jobs log `send_mail_skipped` or `send_notification_skipped`.
  - done/failed/skipped jobs cannot be executed again.
- `tests/Unit/Service/Action/ActionSchedulerServiceTest.php`
  - explicit multi-recipient email action schedules one job per email address.
  - target-group email action schedules one job per target user.
  - explicit external/shared mailbox address schedules an email job with no linked user.
  - duplicate recipient emails are de-duplicated per action run.
- `tests/Integration/Command/ScheduledJobsExecuteDueCommandTest.php`
  - preference-skipped jobs are processed without command failure.
  - limit still works with skipped jobs.

Required focused backend tests for Slice B:

- Unit/integration tests for runner settings:
  - disabled runner skips.
  - interval not elapsed skips.
  - force bypasses interval.
  - lock contention skips.
  - limit is honored at query level.
- Per-job race guard:
  - queued job executes once.
  - already running/done/failed/skipped job is not executed again.
  - concurrent queued-to-running transition executes only once.
- Command tests:
  - `--limit`, `--force`, `--dry-run`, `--json`.
  - job domain failure records failed job but command exits success.
- Admin API tests:
  - status endpoint success shape.
  - settings update validation.
  - run-now success path.
  - permission matrix for read/execute/manage.
- Migration tests:
  - New migration round-trip under the repo's migration test conventions.
- Golden/smoke:
  - Extend `tests/Golden/FormActionJobChainTest.php` only as needed.
  - Existing smoke scheduled-job execution should continue to pass.

Backend verification commands:

```bash
php bin/phpunit tests/Controller/Api/V1/Auth/ProfileControllerTest.php
php bin/phpunit tests/Controller/Api/V1/Auth/UserDataControllerTest.php
php bin/phpunit tests/Controller/Api/V1/AdminUserControllerTest.php --filter preference
php bin/phpunit tests/Service/Core/JobSchedulerServiceTest.php
php bin/phpunit tests/Integration/Command/ScheduledJobsExecuteDueCommandTest.php
composer test:changed
composer phpstan
composer validate-db
```

Run only focused suites unless the team explicitly asks for broader DB-dependent coverage.

## API Contract Sketches

### Current User Data Additions

Add to `responses/auth/user_data.json` data object:

```json
{
  "receives_notifications": true,
  "receives_emails": true
}
```

### Update Preferences Request

`PUT /cms-api/v1/auth/user/communication-preferences`

```json
{
  "receives_notifications": false,
  "receives_emails": true
}
```

Response: standard envelope with `responses/auth/user_data`.

### Email Job Config Additions

For new scheduled email jobs, include delivery policy and a single primary recipient:

```json
{
  "email": {
    "delivery_policy": "respect_user_preferences",
    "recipient_emails": "qa.user@selfhelp.test",
    "subject": "Reminder",
    "body": "..."
  }
}
```

For required system mail:

```json
{
  "email": {
    "delivery_policy": "required_system",
    "recipient_emails": "qa.user@selfhelp.test",
    "subject": "Your login code",
    "body": "..."
  }
}
```

Recipient snapshot shape:

```json
{
  "channel": "email",
  "recipient_type": "to",
  "recipient_email": "shared-mailbox@example.org",
  "id_users": null,
  "delivery_policy": "respect_user_preferences",
  "resolved_from": "external_email"
}
```

### Schedule Metadata For User-Local Jobs

For wall-clock schedules, keep UTC in `scheduled_jobs.date_to_be_executed` and store the local intent in config:

```json
{
  "schedule": {
    "wall_clock": true,
    "local_datetime": "2026-06-05T07:00:00",
    "timezone": "Europe/Zurich",
    "timezone_source": "user",
    "rule": {
      "type": "after_period_on_day_at_time",
      "time": "07:00"
    }
  }
}
```

If the user changes timezone before execution, recalculate only queued future jobs with `wall_clock = true`. Keep `local_datetime` at 07:00 and update both `timezone` and the UTC `date_to_be_executed`.

### Scheduled Job List Addition

Existing list fields remain. Add:

```json
{
  "status": "Skipped: emails disabled by user",
  "status_code": "skipped_user_disabled_emails",
  "recipient_email": "qa.user@selfhelp.test",
  "recipient_user_id": 42,
  "recipient_is_external": false,
  "delivery_policy": "respect_user_preferences"
}
```

### Scheduled Job Detail Addition

```json
{
  "status": {
    "id": 123,
    "value": "Skipped: emails disabled by user",
    "code": "skipped_user_disabled_emails"
  },
  "job_type": {
    "id": 456,
    "value": "Email",
    "code": "email"
  },
  "recipients": [
    {
      "id": 789,
      "channel": "email",
      "recipient_type": "to",
      "recipient_email": "qa.user@selfhelp.test",
      "id_users": 42,
      "delivery_policy": "respect_user_preferences",
      "resolved_from": "user"
    }
  ]
}
```

### Runner Status Response Shape

Use this as a guide, not a final schema:

```json
{
  "settings": {
    "enabled": true,
    "intervalSeconds": 60,
    "maxJobsPerRun": 100,
    "lockTtlSeconds": 120,
    "staleRunningAfterSeconds": 900
  },
  "lastRun": {
    "id": 10,
    "triggerType": "scheduler",
    "status": "succeeded",
    "startedAt": "2026-06-05T10:00:00+00:00",
    "finishedAt": "2026-06-05T10:00:01+00:00",
    "durationMs": 1033,
    "dueCount": 3,
    "attemptedCount": 3,
    "doneCount": 2,
    "failedCount": 0,
    "skippedCount": 1,
    "errorMessage": null
  },
  "queue": {
    "dueQueuedCount": 0,
    "oldestOverdueSeconds": null,
    "runningCount": 0,
    "staleRunningCount": 0
  },
  "health": {
    "nextEligibleRunAt": "2026-06-05T10:01:01+00:00",
    "schedulerAppearsStale": false
  }
}
```

## Cache, Auth, Permission, and API Impact

Cache:

- Preference updates invalidate `CacheService::CATEGORY_USERS` list caches and user entity scopes.
- Preference updates should also bump `acl_version` through the existing profile/admin cache invalidation pattern, so web BFF/user-data caches refresh.
- Scheduled-job status changes continue to invalidate `CacheService::CATEGORY_SCHEDULED_JOBS`.
- Runner status can be uncached initially because it is operational and low volume.

Auth:

- Profile preference endpoint requires JWT authentication.
- Admin runner endpoints stay under `/cms-api/v1/admin`.

Permissions:

- Self-service preference updates require only the current authenticated user.
- Admin user create/update uses existing `admin.user.create` and `admin.user.update`.
- Runner settings require `admin.scheduled_job.manage`.
- Runner status requires `admin.scheduled_job.read`.
- Run-now requires `admin.scheduled_job.execute`.

API:

- Do not remove or rename existing response fields.
- Add fields in a backwards-compatible way where possible.
- When adding required fields to `user_data`, update shared/frontend/mobile in the same implementation phase.

Database:

- Store all timestamps in UTC.
- Use lowercase snake_case.
- Do not edit legacy SQL dumps.
- Generate migrations.

## Deployment Plan

1. Deploy database migrations.
2. Deploy backend code.
3. Clear/rebuild API route cache if new DB-backed routes were added.
4. Deploy shared package update.
5. Deploy frontend and mobile updates that consume the new user-data fields.
6. Installer generates production `compose.yaml` with the scheduler service.
7. Configure `LOCK_DSN` to Redis in Docker production.
8. Verify admin runner status:
   - enabled is true;
   - interval is 60 seconds;
   - last run updates after scheduler tick;
   - due queued job count drops after execution.
9. Verify preference skip behavior:
   - disable emails for a QA user;
   - queue a user-scoped email job;
   - execute due jobs;
   - confirm no email was sent;
   - confirm job status is `skipped_user_disabled_emails`;
   - confirm transaction history has `send_mail_skipped`;
   - repeat for notifications.
10. Verify user-local scheduling:
   - queue a wall-clock action for 07:00 for users in at least two timezones;
   - confirm each queued job stores the correct UTC execution instant and local schedule metadata;
   - change one user's timezone before execution;
   - confirm only that user's queued future wall-clock jobs are recalculated.

Host cron fallback for non-Docker/manual deployments:

```cron
* * * * * cd <backend-root> && php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction >> var/log/scheduled-jobs.log 2>&1
```

The Docker-only v1 installer should not generate host cron.

## Definition Of Done

- Users have persisted profile preferences for emails and notifications.
- Web and mobile profile screens let users update those preferences.
- Admin user create/update/detail/list exposes those preferences.
- `/auth/user-data` returns the preferences and shared/frontend/mobile types match.
- Scheduled email jobs never send to a known user with `receives_emails = false`.
- Scheduled notification jobs never send to a known user with `receives_notifications = false`.
- Preference-skipped deliveries end in a skipped status, not `failed`.
- Preference-skipped deliveries have clear audit transactions.
- New runtime code, active schemas, active docs, and UI variable pickers use Mustache double-curly interpolation, not legacy `@...` action placeholders.
- Wall-clock action schedules are calculated per recipient timezone, persisted in UTC, and recalculated for queued future user-linked jobs when the user changes timezone.
- Golden tests cover form action scheduling, user-local delivery time, multi-recipient fan-out, disabled-preference skips, and `{{...}}` interpolation.
- All due queued jobs still execute through `JobSchedulerService::executeJob()`.
- Manual single-job execution and immediate action execution still use the same function and respect preferences.
- Overlapping scheduler ticks cannot execute the same job twice.
- Docker scheduler container runs the due-job command every minute and respects DB-backed settings.
- Admin API can read runner status, update settings, enable/disable, and run due jobs now.
- Runner status reports disabled, locked, stale, last error, due count, skipped count, and last run.
- Existing plugin scheduled-job dispatch remains compatible.
- JSON schemas, route migrations, permissions, cache invalidation, docs, and tests are updated.
- Backend `composer phpstan` reports 0 errors after code changes.
