<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Live sync: change notices, the access stamp and the page sync block

Audience: Developers and technical operators.
Status: active.
Applies to: core 0.1.49 and later.
Last verified: 2026-11-20.
Source of truth: `src/Service/Sync/`, `src/EventListener/ChangeNoticeFlushListener.php`, `src/EventListener/AclStampResponseListener.php`, `src/Controller/Api/V1/Auth/AuthEventsController.php`, `src/Service/CMS/Frontend/PageService.php`.

An open screen follows a change made elsewhere without polling. The server tells the clients that something changed (a **change notice**, pushed through Mercure). It never tells them what changed. A client that holds a matching tag fetches again through the normal API, so every read is checked by the ACL again.

## The change notice

Event name `changed`, sent as a **private** Mercure update:

```json
{ "v": 1, "tags": ["page:12", "navigation"] }
```

A message carries names and ids only, never content. A client ignores tags it does not know and messages with a higher `v`.

| Tag | Meaning |
| --- | --- |
| `acl` | The caller's groups, roles or grants changed: refresh user data (this runs the existing ACL cascade). |
| `navigation` | A menu or the pages in a menu changed. |
| `global_values` | Global values were saved. |
| `page:<id>` | The published page changed (publish, unpublish, version published, its ACL). |
| `page_draft:<id>` | A draft write on the page, or a section of it was saved. |
| `page_lock:<id>` | The edit lock of the page changed (sent by the page-lock service). |
| `table:<id>` | A record of a data table was saved, deleted, restored or changed by a quick action. |
| `section:<id>` | A section was saved in the admin. |
| `asset:<id>` | An asset changed. |

`SyncTag::PATTERN` is the only allowed shape; the notifier drops anything else.

## Topics and audiences

Topics (all under `MERCURE_TOPIC_PREFIX`, built by `MercureTopicResolver`): `<prefix>/sync/public`, `<prefix>/sync/users/<id>`, `<prefix>/sync/groups/<id>`, `<prefix>/sync/roles/<id>`. The older topics and the `acl-changed` event stay.

Groups see, roles edit. `ChangeNoticeService` is the one place that decides who gets what; `SyncAudienceResolver` reads the grants.

| Change | Tags | Topics |
| --- | --- | --- |
| One user's groups or roles | `acl` | that user |
| A group's page ACL | `acl`, `navigation`, `page:<id>` per changed page | that group |
| A role's permissions or data-access grants | `acl` | that role |
| Page published, unpublished, a version published | `page:<id>`, `navigation` | groups with `select` on the page; `public` when the page is open access; roles with a grant on the page; superuser roles |
| Draft write on a page | `page_draft:<id>` | roles with a grant on the page; superuser roles |
| Data record saved, deleted, restored, quick action | `table:<id>` | the record's owner; roles with a grant on the table; superuser roles; groups with `select` on a published page that reads the table for all users; `public` when such a page is open access |
| Navigation of any kind | `navigation` | `public` |
| Global values saved | `global_values` | `public` |
| Section saved in the admin | `section:<id>`, `page_draft:<page id>` (per page) | roles with a grant on the page; superuser roles |
| Asset changed | `asset:<id>` | roles with a grant on the asset; superuser roles |

A group or role change does not touch `users.acl_version` of its members. It raises `groups.acl_version` or `roles.acl_version` once and sends one message to the group or role topic. Per-user cache scopes are still cleared (that is a cache rule).

## How a service sends a notice

A write, its cache clear and its notice belong together. After the commit, next to the cache clear, call one method of `ChangeNoticeService` (`pagePublishedChanged`, `sectionSaved`, `tableChanged`, ...). Nothing is sent at once:

1. `ChangeNotifier` collects the notices and merges them **per topic**.
2. `ChangeNoticeFlushListener` sends them once, after the response (`kernel.terminate`), after a console command, or after a Messenger message. `ScheduledJobRunnerService` flushes after every job.
3. A request or job that fails before its commit never reaches a call site, so nothing is sent for a write that did not happen. A job that throws drops what it queued (`reset()`); a long-running worker also resets between units (F136/F211).
4. A hub that is down is logged and swallowed. The next API reply corrects the screen.

Never put content in a notice, and never send to a topic the table above does not name.

## Subscriber bootstrap

`GET /cms-api/v1/auth/events` also answers **guests** (200, never 401). The data gains `syncTopics`:

- guest: `[<prefix>/sync/public]`; `topic`, `impersonationTopic` and `systemUpdateTopic` are `null`;
- signed in: `[user, each group, each role, public]`.

The token's `mercure.subscribe` equals the list. When a client receives `acl`, it fetches a new bootstrap and reconnects, so its topic list follows its groups and roles.

## The access stamp

Every API response to a signed-in caller carries `X-Acl-Stamp` (exposed through CORS; guests get none). It is the first 16 hex characters of SHA-256 over:

```
<users.acl_version> "\n" <groups: sorted "id:acl_version", joined by ","> "\n" <roles: same>
```

`GET /auth/user-data` returns the same value as `acl_stamp`; it is computed fresh and is not part of the cached user-data payload, so it always equals the header of the same reply. A client that sees a different stamp from the one in its user data refreshes its user data, even if the push notice was lost. The header is added late (`KernelEvents::RESPONSE`, priority -20), so it reflects the writes of the request itself.

## The page sync block

Every page payload (published and draft) carries:

```json
"sync": { "depends_on": ["page:12", "table:31", "global_values"], "valid_until": "2026-12-01T00:00:00Z" }
```

- `depends_on`: `page:<id>` (`page_draft:<id>` when the draft is served), `table:<id>` for every data table the page reads (sections, `data_config`, lists, data views, records, found by `PageTableScanner`), and `global_values`. A client refetches the page when a notice carries one of these tags.
- `valid_until`: UTC ISO 8601, or `null`. The earliest moment the result can change because time passes. A condition that compares the clock with a fixed or user-specific moment gives that moment; the operator decides whether the day itself or the next day flips it. A text that shows the clock gives the next change of what it shows, never less than 5 minutes ahead. Any clock use the server cannot bound gives now + 5 minutes. `ClockBoundaries` collects these while the page is rendered.
- The cached page entry never outlives `valid_until`: the cache stores the entry with that moment and drops it on read once it has passed. A cache hit before that moment reports the earlier stored `valid_until`, which may be under 5 minutes away (the 5-minute floor applies when the entry is built).
- The client refetches at `valid_until` plus up to 2 seconds of random delay, while the page is open.

## Page views

A page view is recorded only when the request carries `X-Page-View: 1` (and is not a preview). A revalidation, prefetch, live refetch or `valid_until` refetch does not move the analytics. The referrer rule is unchanged: `X-Referrer-Host` travels on the same request. `X-Page-View` is an allowed CORS request header.

## The page draft version and the edit lock

Two editors can have the same page open. Two rules keep them from overwriting each other (contracts C8 and C9). Both are enforced by one listener, `PageDraftWriteGuardListener`, on the routes listed in `PageDraftWriteRoutes::NAMES` (every non-GET `/admin/pages/{page_id}/...` route except the lock routes: page update and delete, every section write, import into a page, restore from version, publish, unpublish, publish a version, delete a version). `PageDraftWriteRoutesTest` fails when `api_routes` has a write route of a page that is not in the list.

**Draft version (`pages.draft_version`).** A counter that starts at 1 and is raised by every draft write. `GET /admin/pages/{page_id}` returns it as `draft_version` and in the response header `X-Page-Version`. A draft write must send the version it is based on in the request header `X-Page-Version` (a positive whole number):

| Request | Answer |
|---|---|
| header missing or not a positive integer | `400` with error `page_version_required` |
| header differs from the current version | `409` with error `stale_page_version` and data `{page_id, current_version}`; nothing is written |
| header equals the current version | the write runs; a 2xx answer carries `X-Page-Version` with the new version (current + 1) |

The listener claims the version with one conditional `UPDATE ... WHERE draft_version = :sent` before the controller runs, so of two writes on the same version exactly one runs. When the controller then answers a non-2xx status, the claim is given back, so a failed write changes nothing. A 2xx write also sends `page_draft:<id>` to the page's editors. Writes made by code that calls a service directly (the CMS app wizard, the home seed, the page import) only touch pages that were just created, so they need no version.

**Edit lock.** One holder per page. The lease is not a table: it is one entry per page in the `cache.page_edit_lock` pool (Redis in production) holding the holder's id and name and three times. It ends **120 seconds after the last renewal** or **15 minutes after the holder's last draft write** (after the acquisition when they wrote nothing), whichever comes first. The ends are judged from the stored times with `time()`, never from the cache entry's lifetime, so they are exact and testable with ClockMock. A short `LockFactory` mutex per page guards each read-modify-write, so two callers never both take a free lock.

| Route | Effect |
|---|---|
| `GET /admin/pages/{page_id}/lock` | the status; changes nothing (an ended lease is removed and announced) |
| `POST /admin/pages/{page_id}/lock` | take a free lock, or renew the caller's own; `409 page_locked` when someone else holds it |
| `POST /admin/pages/{page_id}/lock/take-over` | become the holder, whoever held it |
| `DELETE /admin/pages/{page_id}/lock` | give the lock up when it is the caller's; nothing happens otherwise |

All four need `admin.page.update` and the update grant on the page (the same check as a page write): a caller without them gets 403 (404 for a page that does not exist) and never learns whether the page is locked. The data of a lock answer is `{page_id, held, mine, holder: {id, name}|null, expires_at, idle_expires_at}` (`mine` is per caller). A draft write by someone other than the holder is refused with `409 page_locked` and the same data, so the editor can show who is editing; the lock is checked before the version. A draft write while nobody holds the lock succeeds and leaves the caller holding it. A holder who is taken over gets `409 page_locked` on the next write.

Every change of who holds the lock (acquire of a free lock, take-over, release, an ended lease noticed on access, deleting the page) sends `page_lock:<id>` to the roles with a grant on the page and the superuser role. A renewal by the holder sends nothing. Roles edit, so lock and draft notices go to roles, never to groups.

## What does not update live

Only data that goes through the generic data store (`DataService`) sends `table:` notices. **Data a plugin keeps outside the data store does not update live.** A plugin that needs it must keep its data in the data store, or accept that its screens update on the next fetch.

## Tests

`MercureTestRecorder` records what would be sent; never poll. See `tests/Service/Sync/ChangeNotifierTest.php` (merge, flush once, rollback), `tests/Integration/Sync/SyncAudienceTest.php` (who gets what), `tests/Controller/Api/V1/Auth/AclStampHeaderTest.php`, `tests/Controller/Api/V1/Auth/AuthEventsControllerTest.php` and `tests/Service/CMS/Frontend/PageSyncBlockTest.php`; for the version and the lock `tests/Controller/Api/V1/Admin/PageDraftVersionTest.php`, `PageEditLockTest.php` (ClockMock, no sleeping) and `tests/Integration/Sync/PageDraftWriteRoutesTest.php`. `QaWebTestCase::jsonRequest()` adds the current `X-Page-Version` to a write to `/admin/pages/{id}/...`; pass `NO_PAGE_VERSION` to send none.
