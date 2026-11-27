# Host hooks for plugins (plugin API 0.2.0)

Audience: Plugin authors.
Status: active.
Applies to: SelfHelp2 core >= 0.1.52, `@selfhelp/shared` plugin SDK `PLUGIN_API_VERSION` 0.2.0, mobile renderer contract `MOBILE_RENDERER_VERSION` 0.4.0 (C27 to C30).
Last verified: 2026-11-27.
Source of truth: the contracts in task `2026-11-25-plugin-resource-hooks` and the code under `src/Plugin`, `src/Service/Sync`, `src/Service/Versioning`.

A plugin extends only through its manifest and these host services. It must not add its own cache, queue, settings store or rate limiter. Results of deferred work go through `DataService::saveData` with the revision door `job` so `table:` change notices fire. A public plugin endpoint decides like a core form (page + section, page ACL, settings from the stored section).

Using any key below needs `"pluginApiVersion": ">=0.2.0"` (or `0.2.0`) in `plugin.json`. A bare version means at least this version within the same major: on host `0.2.0`, `0.1.0` is accepted, `0.3.0` is refused, `^0.1.0` is refused. The mobile host services C27 to C30 are not manifest keys: they need `compatibility.mobile` `>=0.4.0` and a feature check in the plugin.

## Rules for web and mobile renderers

When a live refresh updates a plugin style's data (a `res:` or `table:` notice):

- never discard typed answers the visitor still has on screen;
- keep answers that still apply to the new content;
- show a short "content was updated" notice.

## C1 — Resource tag

Tag shape: `res:<type>:<id>` (`<type>` registered, `<id>` positive integer). Use `SyncTag::resource($type, $id)` on the backend. Page `sync.depends_on` may list these tags.

## C2 — Manifest additions

Declare in `plugin.json` (schema `docs/plugins/plugin-manifest.schema.json`):

```json
{
  "pluginApiVersion": "0.2.0",
  "styles": [{ "name": "survey-js", "resourceFields": { "survey_id": "surveyjs_survey" } }],
  "cacheCategories": [{ "name": "surveys", "ttlSeconds": 3600 }],
  "scheduledJobs": [{
    "type": "r_script", "label": "R script", "actionPlannable": true,
    "configSchema": { "type": "object", "properties": { "script_id": { "type": "integer" } }, "required": ["script_id"] },
    "resourceFields": { "script_id": "rserve_script" },
    "timeoutSeconds": 120
  }],
  "settings": [{ "key": "api_key", "label": "API key", "type": "string", "secret": true, "envOverride": "LLM_API_KEY" }],
  "rateLimits": [{ "name": "chat", "policy": "sliding_window", "limit": 20, "interval": "1 minute" }],
  "jsonLogicOperations": [{ "name": "stats.mean", "description": "Mean of numbers" }],
  "listens": ["record.saved", "user.deleted"],
  "recurringJobs": [{ "name": "nightly", "type": "r_script", "cron": "0 2 * * *" }]
}
```

## C3 — Item services

```php
use App\Plugin\Resource\PluginResourceServiceInterface;

// after commit of an item change
$resources->changed('surveyjs_survey', $id, published: true);
$resources->deleted('surveyjs_survey', $id);
// inside the create transaction
$resources->grantCreator('surveyjs.survey.create', 'surveyjs_survey', $id);
$flags = $resources->rightsFor('surveyjs_survey', [$id], $userId);
// unfinished participant pin (null = serve newest published)
$n = $resources->pinnedVersion($userId, $tableId, 'surveyjs_survey', $id);
```

## C4 — Cache

```php
$cache->withResourceScope('surveyjs_survey', $id)->get(/* ... */);
$cache->invalidateResourceScope('surveyjs_survey', $id);
// plugin category name is "<pluginId>.<name>" from cacheCategories
$cache->withCategory('sh2-shp-survey-js.surveys')->get(/* ... */);
```

Uninstall and purge clear plugin categories through `PluginCacheInvalidator`.

## C5 — Change notices

Prefer C3 `changed()` / `deleted()`. They clear the resource scope and call `ChangeNoticeService::resourceChanged($type, $id, $published)` with tag `res:<type>:<id>` to editors and, when published, to viewers of pages that reference the item.

## C6 — Render hook

```php
use App\Plugin\Render\PluginStyleResolverInterface;
use App\Plugin\Render\PluginRenderContext;
use App\Plugin\Render\PluginResolvedSection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('selfhelp.plugin.style_resolver')]
final class SurveyResolver implements PluginStyleResolverInterface
{
    public function styleName(): string { return 'survey-js'; }

    public function resolve(array $section, PluginRenderContext $context): PluginResolvedSection
    {
        $pin = /* pinnedVersion or newest */;
        return new PluginResolvedSection(
            data: ['survey' => $pin],
            dependsOn: ['res:surveyjs_survey:' . $id],
        );
    }
}
```

Runs after the page cache, only for sections still visible. A throw becomes `section.plugin_error = "resolver_failed"`. Data lands at `section.plugin`.

## C7 — Where used

`GET /cms-api/v1/admin/resources/{type}/{id}/usage` (`admin.access` + view grant on the item). Web: `api.resources.usage(type, id)` and optional `ui.ResourceUsageList`.

## C8 — Bind check

On section save/import and action save, a `resourceFields` value must be a positive integer the editor may view (else 403 `resource_not_bindable`). Empty values and system actors pass.

## C9 — Roles popup listing

Host `GET` of plugin resources returns only viewable items, each with `can_assign`. The web matrix disables rows without it.

## C10 — SDK sync map

```ts
definePlugin({
  pluginApiVersion: '0.2.0',
  sync: [
    { tags: ['res:surveyjs_survey:*'], queryKey: ['surveyjs', 'survey'] },
    { tags: ['acl'], queryKey: ['surveyjs', 'admin'] },
  ],
  // ...
});
```

Shared helper: `matchPluginSyncEntries`. Host invalidates matching query keys on `changed` notices; `acl` entries also run from the web ACL cascade.

## C11 — Web SDK API

`IPluginApi` gains `resources.usage`, optional `ui.ResourceUsageList`, `settings.get()`, `sectionAnchor(sectionId)`. Mobile has none of these. What mobile gets instead is in C27 to C30.

## C12 — Plugin job types in actions

Declare `actionPlannable` + `label` + `configSchema`. Editors see types from `GET /admin/actions/plugin-job-types`. On plan, the job config carries `plugin_config`, `record_id`, `record_ref` (C26), `data_table_id`, `trigger`. Handlers implement `PluginScheduledJobHandlerInterface`.

A handler that writes results:

```php
$dataService->saveData($tableId, $data, $userId, RecordWriteContext::forJob(/* ... */));
```

Use the revision door `job` so core sends `table:` notices.

## C13 — Jobs that start at once

```php
use App\Plugin\ScheduledJob\PluginJobQueueInterface;

$id = $queue->queueNow($pluginId, 'r_script', ['script_id' => 3], $userId);
$id = $queue->queueAt($pluginId, 'r_script', $config, new \DateTimeImmutable('+1 hour'), $userId);
```

The scheduler waits on Redis list `selfhelp:scheduled_jobs:wake` instead of a plain sleep.

## C14 — Plugin settings and secrets

```php
use App\Plugin\Settings\PluginSettingsInterface;

$value = $settings->get($pluginId, 'api_key'); // envOverride wins, then stored, then default
```

Admin routes: `GET`/`PUT /admin/plugins/{pluginId}/settings` (`admin.plugins.manage`). Secrets are encrypted; `PUT` omits keep a secret, `null` clears it. Uninstall keeps rows; purge deletes them.

## C15 — Rate limits

```php
use App\Plugin\RateLimit\PluginRateLimiterInterface;

$limiter->consume($pluginId, 'chat', 'user:' . $userId);
// refusal → 429 rate_limited, meta.retry_after, Retry-After header
```

## C16 — Pure JSON Logic operations

```php
use App\Plugin\Logic\JsonLogicOperationInterface;

final class MeanOperation implements JsonLogicOperationInterface
{
    public function name(): string { return 'stats.mean'; }
    public function apply(array $args): mixed { /* pure: no clock, I/O, DB */ }
}
```

Declare the name in `jsonLogicOperations`. The public plugin manifest lists them for the condition builder. A failing operation fails the condition closed.

## C17 — Aggregation

```php
$rows = $engine->aggregate($query, new AggregateSpec(
    groupBy: ['status'],
    metrics: [['fn' => 'count', 'as' => 'n']],
    maxGroups: 200,
));
```

Same filters and access as `run()`.

## C18 — Realtime to one user

```php
$publisher->publish($pluginId, 'progress', $payload, audience: 'user', topicParams: ['userId' => $uid]);
```

Only that user's `/auth/events` token includes the topic.

## C19 — Content reference and pinning

- Core forms store `page:<id>@v<n>` or `page:<id>@draft`.
- Plugins pass `RecordWriteContext::withContentRef('res:surveyjs_survey:12@v3')`.
- Jobs/imports leave `content_ref` null unless the caller sets it.
- Use `pinnedVersion()` in the resolver (C6) so a started participant keeps their version.

## C20 — Managed users and writes for them

```php
use App\Plugin\Users\PluginUserScopeInterface;
use App\Plugin\Users\PluginRecordWriterInterface;

if ($scope->mayManage($actorId, $ownerId)) { /* ... */ }
$ids = $scope->managedUserIds($actorId, groupIds: null, limit: 1000);

$recordId = $writer->saveFor(
    $pluginId, $ownerUserId, $actorUserId, $tableName, $data,
    contentRef: 'res:surveyjs_survey:12@v3',
);
// 403 user_not_managed when the actor may not manage the owner
```

Table name must start with the plugin's `ownedDataTablePrefix`. Revision door is `plugin`.

## C21 — Plugin dependencies

Declare `dependencies` / `conflicts` in the manifest. Install/update/enable refuse with 409 `plugin_dependency_missing` / `plugin_conflict`. Disable/uninstall/purge refuse with 409 `plugin_dependents_enabled`.

## C22 — Parallel jobs and timeouts

Manifest `scheduledJobs[].timeoutSeconds` (10–900, default 120). Scheduler runs `--concurrency` child processes; over-limit jobs fail with reason `timeout`.

## C23 — Section anchor

Web only: `api.sectionAnchor(sectionId)` returns `.section-<id>`. Host keeps that class on every rendered section. Tours use host actions only; content never runs plugin JavaScript.

## C24 — Read-only after-commit events

Implement `PluginDomainEventListenerInterface`, declare `listens` in the manifest:

| Event | Class |
| --- | --- |
| `record.saved` | `RecordSavedEvent($tableId, $recordId, $ownerUserId, $trigger, $contentRef, $recordRef)` |
| `record.deleted` | `RecordDeletedEvent($tableId, $recordId, $ownerUserId, $recordRef)` |
| `user.created` | `UserCreatedEvent($userId)` |
| `user.deleted` | `UserDeletedEvent($userId)` |
| `page.published` | `PagePublishedEvent($pageId, $versionNumber)` |

Delivered after commit. A throwing listener is logged and does not roll back the write. Listeners may queue jobs (C13), call C3 `changed()`, or send notices — not write data directly.

## C25 — Recurring plugin jobs

Manifest `recurringJobs: [{ name, type, cron, description }]`. Host keeps one queued future job per entry (`config.recurring = name`) and requeues after every run. Disable/uninstall removes them.

## C26 — Record version reference

Reference: `record:<data_rows.id>@v<n>` (the version after the write that planned the job or raised the event). Not a `content_ref`.

```php
use App\Service\Versioning\VersionRef;

$ref = VersionRef::record($recordId, $version);
$parts = VersionRef::parse($ref); // kind, id, version — or null
```

- `scheduled_jobs.record_ref` on jobs planned from a record; null for reminders, `queueNow`/`queueAt`, recurring jobs.
- Plugin job config also carries `record_ref` next to `record_id`.
- `RecordSavedEvent` / `RecordDeletedEvent` expose `recordRef`.

## C27 to C30 — Mobile host services (mobile renderer 0.4.0)

These are optional members of `IMobileHostServices` (`getMobileHostServices()` from `@selfhelp/shared/plugin-sdk`), served by the mobile app (`services/pluginHostServices.ts`) and the web preview. A plugin that uses one raises `compatibility.mobile` to `>=0.4.0` and **feature-detects** it (`if (host.upload)`), because an older app binary does not have it. `PLUGIN_API_VERSION` stays `0.2.0`. A plugin never imports a native module, never asks the system for a permission itself and keeps no secret on the device (rule 7.13 in `concepts/rules.md`; `concepts/plugins.md` section 2).

### C27 — Upload

```ts
const host = getMobileHostServices();
if (host?.upload) {
  const answer = await host.upload({
    path: '/cms-api/v1/plugins/sh2-shp-survey-js/answers',   // relative; an absolute path is refused
    fields: { survey_id: '12' },
    files: [{ field: 'file', name: 'answer.m4a', type: 'audio/m4a', uri }], // exactly one of uri / base64
  });
  if (answer.sessionExpired) { /* sign in again */ }
}
```

The host builds one `multipart/form-data` body, the way core form writes do, with the token, client headers and one 401 refresh like `request`. The answer has the `IMobileHostResponse` shape (`ok`, `status`, `data`, `reason`, `error`, `sessionExpired`). Nothing is sent, and the answer is `{ ok: false, status: 0, reason }`, when `reason` is `invalid_upload` (no file, or a file with both or neither of `uri` and `base64`) or `file_too_large` (one limit of **25 MB** for the files and text parts of a request). A `base64` file is written to a temporary file in the app cache (a `Blob` in the web preview) and removed after the request, whatever the result. The backend needs no change: the plugin's own controller reads multipart like a core form controller.

### C28 — Microphone and camera permission

```ts
if (host?.requestPermission) {
  const state = await host.requestPermission('microphone'); // 'granted' | 'denied' | 'blocked'
  if (state === 'blocked') {
    // The system will not show the prompt again: explain why, then
    host.openAppSettings?.();
  }
}
```

Only the app asks the system; a plugin in a WebView cannot. `granted`: allowed now or already. `denied`: the user said no and the app may ask again. `blocked`: the system will not show the prompt again (`canAskAgain` is false), so offer `openAppSettings()` (the app's system settings; it does nothing in the web preview). The iOS purpose texts say what the permissions are for (photos, videos and recorded audio as answers in forms and surveys), because the stores review them. Recording inside the plugin's own WebView also needs `mediaCapturePermissionGrantType` on that WebView and `media-src blob:` in its CSP; those belong to the plugin (the task note `plugin-handoff-mobile.md`).

### C29 — Device storage

```ts
const storage = host?.getPluginStorage?.('sh2-shp-survey-js'); // throws on an id that is not a manifest id
await storage?.setItem('draft.12', JSON.stringify(answers));
const draft = await storage?.getItem('draft.12'); // string | null
await storage?.removeItem('draft.12');            // after a successful submit
```

A small key-value store for drafts and view state (a guest loses an unfinished survey otherwise). Keys are 1 to 128 characters; the total per plugin, user and server is **256 KB**, and a `setItem` over it rejects with `plugin_storage_full` and changes nothing. The store is kept per server, user (a guest is user `0`) and plugin, so a guest and a signed-in user never see each other's values, and `clear()` removes only that plugin's values for the current user and server. It is **emptied with the other client caches** on logout, a server switch and a rejected token refresh (concept rule 3.3). It is not encrypted: never store secrets or tokens, and the server still decides everything on submit. Plugins are trusted code; nothing stops a plugin from passing another plugin's id, and review checks that.

### C30 — HTML in mobile plugins

One rule, the same in the SDK comment on `IMobilePluginApi` and in `concepts/plugins.md`:

- **Native React Native text:** `parseInlineRich` (inline formatting and links) and `stripHtmlToText` (plain text), both exported from `@selfhelp/shared/plugin-sdk` (with the type `IInlineNode`).
- **HTML inside the plugin's own WebView:** the plugin bundles a sanitizer into the WebView runtime and keeps a strict CSP. The host offers no sanitizer on mobile.
- **Web part:** `api.sanitizeHtml`; never bundle a sanitizer there.

## Lifecycle codes (dependencies)

| HTTP | Error code |
| --- | --- |
| 409 | `plugin_dependency_missing` |
| 409 | `plugin_dependents_enabled` |
| 409 | `plugin_conflict` |
| 409 | `plugin_manifest_collision` |

## See also

- [developer-guide.md](./developer-guide.md)
- [realtime-and-no-polling.md](./realtime-and-no-polling.md)
- [gdpr-and-data-ownership.md](./gdpr-and-data-ownership.md)
- Workspace concepts: `concepts/plugins.md` section 2a (plugin API)
