# Realtime & The No-Polling Rule

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

The SelfHelp plugin ecosystem is **realtime-first**: every state
change that a UI depends on is published as a Mercure event, and
**no UI is allowed to poll the backend**. This is a hard architecture
rule.

## Why no polling

Polling kills the host in three ways:

1. **Trust cost** — every poll authenticates a JWT, walks the ACL,
   and re-runs `UserPermissionService::buildUserPermissions()`. At
   1 Hz × 500 admins, that's ~500 ACL evaluations per second purely
   to ask "did anything change?".
2. **Cache cost** — polling invalidates the request-level cache on
   every request; the realtime path leaves the cache hot.
3. **UX cost** — the UI is **always** at least one poll interval out
   of date. Realtime UIs feel instant.

We accept the operational complexity of running a Mercure hub in
exchange for the above.

## The plugin realtime contract

Every plugin operation publishes to **exactly one** topic shape:

| Topic                                | When                                  |
|--------------------------------------|---------------------------------------|
| `plugin/{pluginId}/state`            | `enabled` / `disabled` / `uninstalled` |
| `plugin/{pluginId}/version`          | Version changed (install or update).  |
| `plugin/operation/{operationId}`     | Operation row updated.                |
| `plugin/lookup/{pluginId}/{typeCode}`| Lookup table mutation in plugin scope. |
| `plugin/feature-flag/{flagId}`       | Feature flag toggled.                 |
| `plugin/health/{pluginId}`           | Per-plugin doctor row changed.        |

The topics are published by
[`PluginRealtimePublisher`](../../src/Plugin/Realtime/PluginRealtimePublisher.php)
with the configured `mercure_topic_prefix` prepended.

## Consuming on the frontend (web)

Plugins call the shared hook:

```tsx
import { usePluginRealtime } from '@selfhelp/shared/plugin-sdk';

usePluginRealtime({
  pluginId: 'sh2-shp-survey-js',
  topics: ['survey/response/created', 'survey/response/updated'],
  onMessage: (msg) => queryClient.invalidateQueries(['surveys']),
});
```

The hook:

- Resolves to the host's Mercure URL via
  `useMercureConfig` (frontend `src/hooks/useMercureConfig.ts`).
- Subscribes with the user's JWT.
- Reconnects with exponential backoff (250 ms → 30 s cap) on transient
  failure.
- Auto-unsubscribes on unmount.

## Consuming on mobile

Mobile uses the wrapper described in
[`mobile-plugins.md`](./mobile-plugins.md); the API surface to plugin
authors is identical.

## Consuming inside the backend (PHP)

Some plugins need to **publish** their own topics. They depend on
`PluginRealtimePublisherInterface`:

```php
public function __construct(
    private readonly PluginRealtimePublisherInterface $realtime,
) {}

public function onResponseSaved(int $sectionId, int $userId): void
{
    $this->realtime->publish(
        pluginId: 'sh2-shp-survey-js',
        topic: 'survey/response/saved',
        payload: ['sectionId' => $sectionId, 'userId' => $userId],
    );
}
```

The publisher enforces the topic prefix, JWT signing, and an
`audience` claim that scopes the message to the right set of
subscribers (so admin-only events are not delivered to
unauthenticated subscribers).

## Mercure hub reachability

The doctor's `mercure` site check does an HTTP GET against the
configured `MERCURE_PUBLIC_URL`. Any response is accepted — even
HTTP 401/400 — because the goal is to detect a **down** hub, not an
**unauthenticated** request. A `504 Gateway Timeout` or connection
refused trips the check to `error`.

## Topic naming rules

| Rule                                    | Why                                |
|-----------------------------------------|------------------------------------|
| Always `plugin/{pluginId}/…`            | Lets the publisher enforce the prefix per plugin. |
| Slash-separated, lower-case             | Mercure topic matching uses URI templates. |
| No PII in the topic itself              | Topics show in dev tools and logs. |
| Payload contains only IDs, not the full row | Subscribers re-fetch through the cached API to get the latest. |

## When polling is allowed

**Never**, with one exception: the `selfhelp:plugin:doctor` command
is allowed to poll the lock file at a 1 Hz tick when running with
`--watch`, because the doctor is a CLI tool and the lock file is on
local disk.

If you find yourself reaching for `setInterval` in a plugin UI, stop
and reach for a Mercure topic instead. The host-side cost of adding a
new topic is zero; the host-side cost of a new poll source is high.

## Related docs

- [Architecture](./architecture.md) (§ Realtime)
- [Mobile plugins](./mobile-plugins.md)
- [Plugin operations & rollback](./plugin-operations-and-rollback.md)
