# GDPR and data ownership

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend >= 0.1.52.
Last verified: 2026-11-26.
Source of truth: `UserDataCleanerService`, `UserDataCleanupEvent`, rule 7.6, concepts "Removing data".

The host does not offer plugin-specific GDPR export, cleanup or retention
interfaces (F111). Exportable user data belongs in the generic data store;
plugins that keep user data in their own tables clean it through the event
below.

## The ownership rule

Every catalogue row a plugin contributes is tagged with `id_plugins` (styles,
fields, permissions, lookups, api_routes, …). Plugin-owned SQL tables are
declared in `plugin.json` (`dataAccess.ownedTables`) and created only by the
plugin's migrations.

## Recommended home for user data

Put participant data in the generic store under the plugin's
`ownedDataTablePrefix`, written through `PluginRecordWriterInterface::saveFor`
or `DataService::saveData`. Then core export, data access, actions and user
cleanup work without plugin-specific GDPR endpoints.

## Cleaning a user's data

When a user is cleaned or deleted, the host runs `UserDataCleanerService`
inside one transaction and dispatches `UserDataCleanupEvent` with the user id
**before** core rows go. Enabled plugins that own tables with user data must:

1. Listen to `UserDataCleanupEvent` and delete their rows for that user.
2. Register file/cache cleanup with `$event->afterCommit()` — never delete
   files inside the listener (a throw must roll back the whole removal).
3. Keep a foreign key to `users` with `ON DELETE CASCADE` as a backstop
   (only enabled plugins hear the event).

```php
use App\Plugin\Event\UserDataCleanupEvent;

public function onUserDataCleanup(UserDataCleanupEvent $event): void
{
    $this->repo->deleteForUser($event->getUserId());
    $event->afterCommit(function () use ($event): void {
        $this->files->deleteForUser($event->getUserId());
    });
}
```

Core tables `transactions` and `data_access_audit` keep their rows and clear
the user link (`ON DELETE SET NULL`). Explicit table-content erase and plugin
purge are the other removal paths (concepts "Removing data").

## What a plugin must not invent

- Its own export/erasure HTTP API for data that could live in the generic store.
- A second cleanup path that bypasses `UserDataCleanupEvent`.
- A settings or secrets store outside host `plugin_settings` (C14).

## See also

- [host-hooks.md](./host-hooks.md) (C14 settings, C20 writes for managed users)
- [developer-guide.md](./developer-guide.md)
- Workspace `concepts/plugins.md` section 4 and `rules.md` 7.6
