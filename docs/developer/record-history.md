# Record versions and history

Audience: Backend developers and operators.
Status: active.
Applies to: SelfHelp2 backend, generic data store (`data_rows`, `data_revisions*`; migration `Version20261113120906`).
Last verified: 2026-11-13.
Source of truth: `DataService`, `App\Service\CMS\DataRevision\*` (`DataRevisionService`, `DataRestoreService`, `DataRevisionErasureService`, `RecordWriteContext`, `RestorePlan`), `FormListHistoryService`, and the tests `DataRevisionTest`, `DataRevisionRaceTest`, `DataRevisionErasureTest`, `FormRecordVersionTest`.

Every change to a record of the generic data store is a **revision**, and every
record has a **version**. Together they give two things the platform did not have:
a save that cannot silently overwrite another save, and a readable history with
comparison and restore. No new concept: the history lives with the record in the
generic store, is written only by `DataService`, and is read only through the
existing access rules.

## The version protocol (writing)

`DataService` is the only writer of the store. Inside **the same database
transaction** as the change it:

1. takes the record lock and reads `data_rows.version` (`DataRevisionService::lockVersion`);
2. refuses a stale save: an interactive caller must send `expected_version` (a missing or malformed one is `400 expected_version_required` / `invalid_expected_version`, before anything is written), and when it is not the stored one nothing is written and the API answers `409 stale_version` with `data {reason, record_id, expected_version, current_version}` (`assertExpectedVersion`);
3. changes the cells;
4. records the change (`recordChange`): the version moves forward by exactly one with **one conditional statement**, and one revision with its field changes is stored. A save that changes nothing stores nothing and does not advance the version.

The lock serializes the writers of one record, so a second save of the same
version waits, then finds the version moved and is refused (`DataRevisionRaceTest`).
A failure at any step rolls back the data, the version and the history together.

`expected_version` is **required on the wire** for every interactive update, delete,
quick action and restore: a person's request could otherwise overwrite a change the
person never saw. Only the trusted internal entry points that have no draft to protect
(scheduled jobs, imports) take the lock, advance the version and do not compare; a
client cannot select them. A list sends the version of its state; the classic record
styles read it from the page (`section_data[]._version`, `entries[]._version`,
`record_version` of an `entry-record-delete`, see
[17-form-submissions.md](../reference/api/17-form-submissions.md)).

## Storage

| Table | Holds |
| --- | --- |
| `data_rows.version` | the record's version: 1 when created, +1 per persisted change |
| `data_revisions` | one event per persisted change: `version`, `created_at`, `id_users` (actor), `id_impersonating_users`, `id_operations`, `id_sources`, `id_transactions` (the audit event of the same write), `restored_from_version`, `is_checkpoint`, `schema_version`; unique `(id_data_rows, version)` |
| `data_revision_changes` | per field and language: the stable `field_key`, the `field_label` it had, `id_before_values`, `id_after_values`; for a checkpoint, `is_snapshot` rows listing what each field held afterwards |
| `data_revision_values` | the immutable values, stored **once per record** (`content_hash`, `byte_length`, `content`) |

Lookups: `dataRevisionOperations` = `create`, `update`, `delete`, `restore`, `baseline`; `dataRevisionSources` = `form`, `admin`, `quick_action`, `job`, `import`, `system`. The **source is decided by the server** (`RecordWriteContext`), never by a client: people saving through a form are `form`, a list quick action is `quick_action`, everything else defaults to `system`.

How the storage stays small and fast:

- unchanged fields cost nothing; a value that comes back (a revert) reuses the stored value; a value whose digest matches is reused only after the bytes are compared;
- every `CHECKPOINT_EVERY` (20) versions a **checkpoint** also lists the whole state by reference (not copies), so a past state is rebuilt from the nearest checkpoint, never from the beginning;
- records that existed before history began get a `baseline` revision the first time they change; no earlier authorship is invented. No data was converted by the migration.

Stated plainly: reuse works on **whole values**. A one-character edit inside a 10 MB value stores the changed 10 MB value again. Owner reassignment is not a content revision.

## Files

An uploaded file is referenced by a logical key (`uploads/form-files/...`) in the cell and in history values. Editing or soft-deleting a record **keeps** its files, because history retains them. A file is removed only by an explicit erasure and only when no current cell and no history value still names it (`FormFileUploadService::deleteUnreferencedFiles`, a best-effort reachability scan). Whether an old file is still on disk is reported to history readers (`available`), and a restore that would bring back a missing file is blocked (`file_unavailable`).

## Reading history

`DataRevisionService` exposes `history()` (newest first, paged), `revisionHeader()`, `revisionChanges()`, `stateAt()` and `compare()`. Access is **not** decided there: the list layer (`FormListHistoryService`, see [form-lists.md](./form-lists.md)) checks the page, the list, the dataset, the caller's right to update the record and `show_history`, and passes only the field keys the caller may see. Events that touch only hidden fields are hidden from restricted readers; creates, deletes and baselines are always shown. Long values are cut in comparisons (`VALUE_PREVIEW_BYTES`).

## Restore

`DataRestoreService::plan()` builds a `RestorePlan` for an earlier version; `apply()` writes it inside the normal write protocol, so a restore takes the lock, refuses a stale `expected_version`, and appends **one `restore` revision** with `restored_from_version`. It never rewinds or deletes history.

- The chosen version's fields are written back; fields the record has now but that version did not have are removed; the owner, the trigger type and every other field stay.
- A value is written only if it is valid for **today's** schema: the column and the language must still exist, a typed column must be able to read the value, and any named file must still be on disk. Otherwise the plan carries blockers (`field_missing`, `language_missing`, `file_unavailable`, `invalid_value`), nothing is applied, and the API answers `422 restore_blocked`. The client may retry with only the fields that can be restored.
- A dry run returns the plan without writing.
- Deleted records are not restorable.
- Who may restore is the list layer's decision (page, list occurrence, table grant or ownership); the service decides nothing about access.

## Explicit erasure

History is kept until someone erases data on purpose. `DataRevisionErasureService` is the one place that removes revisions, changes and values, and it names the files they referenced:

- `eraseOwnedBy($userId)`: a user's data (`UserDataCleanerService`);
- `eraseTable($tableId)`: a whole data table (`DataTableService`);
- `eraseColumns($tableId, $fieldKeys)`: columns an administrator deletes (`DataTableService`).

Each runs **inside the caller's transaction**, deletes explicitly in foreign-key order (it must keep working if a cascade is ever relaxed) and counts what it removed. The caller passes the returned file keys to `deleteUnreferencedFiles()` **after the commit**. Content only ever lives in the record it belongs to, so erasing one record's revisions cannot damage another's history. Removing a user who merely **edited** someone else's record keeps that record's history and only detaches the actor link (the person goes, the content stays), the same policy as the audit rows. The foreign keys from `data_rows` cascade as a second line of defence.

## Known limits (kept on purpose)

- Dedup is by whole value (see above).
- `data_cells.value` is `utf8mb3`; the revision tables are `utf8mb4`.
- The file-reachability scan reads values that mention the upload directory; it is a maintenance path, not an interactive one.
- A restore is limited to the fields the list or its form shows.

## Related references

- [data-query-engine.md](./data-query-engine.md) - the reader of the store.
- [form-lists.md](./form-lists.md) - the list layer that exposes history.
- [../reference/api/22-form-lists.md](../reference/api/22-form-lists.md) - history and restore endpoints.
- [12-transaction-logging.md](./12-transaction-logging.md) - the audit trail each revision links to.
