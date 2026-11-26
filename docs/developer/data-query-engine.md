# Data query engine

Audience: Backend developers.
Status: active.
Applies to: SelfHelp2 backend, `App\Service\CMS\DataQuery\*` (migrations `Version20261113104850`, `Version20261113120844` and `Version20261113135247`).
Last verified: 2026-11-15.
Source of truth: `DataQuery`, `DataQueryEngine`, `DataFilter`, `DataFilterGroup`, `DataFilterJson`, `DataQueryLimits`, `LegacyFilterTranslator`, `DataTableRepository`, `DataService`, and `tests/Integration/Repository/FormListQueryTest.php`.

One structured reader of the generic data store (`data_tables`, `data_cols`,
`data_rows`, `data_cells`). Every part of the platform that **reads** records
describes its read with a `DataQuery` and runs it through `DataQueryEngine`, so
access scope, typing, language fallback, search, sorting and paging behave the
same for a participant list, a public view, a prefill, a repeater and an export.
Writing stays in `DataService` (and `DataRevisionService`, see
[record-history.md](./record-history.md)).

## How a read runs

1. The table's columns are read (key, id, declared value type).
2. **One query selects the candidate record ids**: the table, the owner scope, deleted rows, filters, search and sort. Each column a filter, search or sort uses is reached by a primary-key lookup of its cell; no pivot over the table is built.
3. The page of ids is cut (`LIMIT/OFFSET`) **before** any cell is read.
4. The cells of that page, for the **projected columns only**, are read and placed into rows.

So the cells materialized for an interactive list are bounded by page size times
projected columns, whatever the table holds.

## The query object

`DataQuery` (immutable): `tableId`, `ownerScope`, `userId`, `filter` (`DataFilterGroup`), `search`, `sorts`, `limit`, `offset`, `columns` (projection), `excludeDeleted`, `languageId`, `allLanguages`, `timezone`, `withTotal`, `optionalColumns` (field key → value type for inputs the form declares but that have no `data_cols` row yet; they read as empty instead of `Unknown column`, F169).

| Owner scope | Meaning |
| --- | --- |
| `any` | no owner restriction (admin and global readers) |
| `user` | only the records of `userId`; **without a user nothing matches**, so a guest never reads anonymous records |
| `groups` | the records of users in the groups the roles of `userId` may access (data-access grants); these queries are not cached |

Entry points: `run()` (rows, optional total), `count()`, `first()`, `batches()`
(stable batches of at most 1,000 for exports), `facet()` (distinct values with
counts inside the visitor's dataset), `columnsOf()`, `compile()` (planning
without executing; used by validation and the admin preview).

## Limits

`DataQueryLimits`: page size at most 100 (default 25), at most 3 sort keys, at
most 30 filter nodes, depth 3, lists of at most 50 values, values of at most 500
characters, search of 2 to 100 characters over at most 20 columns, at most 100
projected columns, batches of at most 1,000. Reading everything at once
(`limit = null`) is possible for the legacy complete-result readers listed below
and must be chosen on purpose.

## Semantics (frozen, tested)

- **Language.** The requested language's cell wins; a missing one falls back to the language-independent cell (`Language::INDEPENDENT_ID` = 1), then to the CMS default language when that is set and differs. (The old procedure returned the larger text when both existed; that is corrected.) With `allLanguages` the result has one row per record and language, and `LIMIT` counts records. The CMS default language id is part of every engine cache key.
- **Types.** `data_cols.id_value_type` (lookup group `dataColValueTypes`: `text`, `number`, `date`, `datetime`, `boolean`; NULL = text) decides how a cell's text is compared and sorted. A value that cannot be read as the column's type behaves like a missing value and sorts last; a typed comparison never matches it. The engine never guesses a type from values. A known form input supplies the type when its column is created and when the form is saved; other sources (SurveyJS, imports, plugins) stay `text` until declared.
- **Dates and times (one grammar, PHP and SQL agree).** `DataColValueType` is the only place that reads a stored value as a date, a datetime or a number, and the same grammar is evaluated in PHP (validation, sorting of loaded rows, filter values) and in SQL (`REGEXP_LIKE` with a `\z` end anchor, then a strict `STR_TO_DATE`/`CAST`). A `date` is a real calendar day `YYYY-MM-DD` (year 1000 to 9999, leap years honoured; `2026-02-30` is not a date). A `datetime` is a date, or `YYYY-MM-DD` + space or `T` + `HH:MM[:SS[.fraction]]` with a real clock (hours 00-23, minutes and seconds 00-59), optionally followed by `Z` or an offset `±HH:MM` / `±HHMM` between -13:59 and +14:00 (the fraction of a second is not compared; the second is). A `date` column reads a datetime text as the day it is written with. The stored text is the truth: only spaces are trimmed, a trailing newline or any other character makes it malformed. A value with a zone is compared as the **UTC instant** it names (so `2026-11-13T12:00:00+01:00` equals `2026-11-13 11:00:00`); a value without a zone is a UTC clock, like every stored timestamp ("stored in UTC, converted to the CMS timezone on output"). No wall-clock or timezone-local type is stored and no typed column is indexed. A **malformed existing value never fails a listing**: it behaves like a missing value (sorts last, never matches a typed comparison), while a malformed value in a filter the author or visitor *sends* is refused with a `400`/validation error before any SQL runs. The behavior is pinned by `DataColumnValueTypeTest` (including equivalent instants, leap days, bad clocks and offsets) and `FormListQueryTest`, which pass on MySQL 8.0 and 9.1 (the engine version is `ENGINE_VERSION = 3`, so cached results of an earlier grammar or CMS-default fallback are not reused).
- **Missing, empty, null.** `IS NULL` means missing or empty; `empty` / `not_empty` operators behave the same way (see `DataFilter`).
- **Search.** Case-insensitive substring over the author-marked searchable columns (2 to 100 characters, plain text; `%`, `_` and `\` are escaped). Search narrows the filtered set; it never widens the dataset.
- **Order.** The requested sort keys, then the record id as tie-break in the direction of the first key; without a sort, record id ascending. Rows without a usable typed value come last in both directions. Text comparison follows the column collation.
- **Deleted.** Soft-deleted records (trigger type `deleted`) are left out unless the query keeps them.
- **Rows without cells** are returned with empty values.
- **Row fields** a filter or sort may name besides columns: `record_id`, `id_users`, `id_action_trigger_types`, `entry_date`, `user_name`, `user_code`, `triggerType`. Lists never expose the person-identifying ones.

## Safety

Values are always bound parameters; column names are allowlisted against the
table's own columns; there is no text path from a request into SQL. The legacy
SQL fragment authors wrote in `data_config` is read by `LegacyFilterTranslator`
into the same structured filter (or refused with a message naming the part that
has no structured form); see [data-table-filter-safety.md](./data-table-filter-safety.md).
Structured JSON filters (`list_fixed_filter`, `list_controls`) are validated by
`FormListConfigValidator` when saved or imported and again by `DataFilterJson`
before planning.

## Caching

`run()` caches per table: key `dq_<ENGINE_VERSION>_<sha1 of the whole normalized query>` in cache category `CATEGORY_DATA_TABLES` with the table's entity scope. Every write to a table through `DataService` (save, delete, restore) bumps that scope, so a cached page never outlives a change of its data. The key contains the owner scope and user, language, timezone, filter, search, sort, page and projection; queries scoped by group grants are not cached. Raise `ENGINE_VERSION` when the shape of a cached result changes.

## Reader inventory

Every place that reads generic-store records, how it reads now, and the test that
pins it. `DataTableRepository::getDataTableWith*()` and `batchesWith*()` are thin
adapters (legacy fragment → structured query → engine); the three `get_data_table_*`
stored procedures are no longer called by any code path.

| Reader | Where | Reads through | Notes | Tests |
| --- | --- | --- | --- | --- |
| Form lists and data views | `FormListStateService`, `FormListContextResolver`, `FormListQuickActionService`, `FormListHistoryService` | the engine directly | paged, searched, sorted, projected, counted, faceted; owner scope and fixed filter bound every read | `FormListQueryTest`, `FormListStateTest`, `FormListSecurityTest` |
| Entry lists and records, repeaters | `PageService::resolveEntryRows()` | `DataService::getData*` → repository → engine | complete result on purpose (the style has no paging); unchanged semantics | `EntryListHydrationTest`, `EntryRecordHydrationTest`, `FormListQueryTest` (equivalence block) |
| `data_config` retrieval | `SectionUtilityService::fetchData()` (two sites) | `DataService::getData` | complete result on purpose; filter prepared by `DataTableFilterService` | `FilterSafetyTest` |
| Form prefill and record edit | `DataService` (`getFormRecordDataForRecord`, latest-record prefill, all-languages reads) | repository → engine | one record or the latest record | `FormRecordEditModeTest`, `FormRecordBindingTest`, `DataServiceTest` |
| Update by field match | `DataService::saveData` (match branch) | `DataService::getData` | equality predicate built from identifier-checked names | `FilterSafetyTest` |
| Admin data grid | `AdminDataController::getData` | `DataService::getData` / `getDataWithUserGroupFilter` | complete result on purpose (the endpoint has no paging contract); bounded replacement is a finding | `AdminDataControllerTest`, `DataTableRepositoryTest` |
| Admin data export (one table, selected tables) | `DataTableExportService` | `DataService::getDataInBatches*` | batches of 500, written as they arrive (CSV spools rows through a temporary buffer to write its header once), identical output | `DataTableExportTest` |
| Page-bundle dataset export | `PageExportImportService` | `DataService::getDataInBatches` | batched; identical output | `PageBundleEntryTablePortabilityTest`, `PageBundleEntryRecordLoadRecordFromTest` |
| Admin filter preview | `DataQueryPreviewService` | `LegacyFilterTranslator` (no read) | reports what the engine would run | `DataQueryPreviewServiceTest`, `DataQueryPreviewSchemaContractTest` |
| Single-file delivery, file clean-up | `FormFileUploadService` | one cell by record id and column key; reachability scans after an explicit erasure | not list reads; primary-key lookups and maintenance scans | `FormFileFieldSubmitTest`, `DataRevisionErasureTest` |

The readers that return complete results keep their existing, bounded-by-author
configuration; moving them to a paged contract changes their observable output and
is a proposal, not part of this change.

## Performance

### Targets and what the engine guarantees

Targets (approved): a page of 25 rows by 8 columns takes at most 1 s cold, at
most 150 ms cached and at most 1 s right after a write, and the response is at
most 200 KB; a million-record table takes at most 3 s cold and after a write.
Counting and substring search over many candidates is **not** constant time and is
documented below instead of hidden.

What holds on any machine and is asserted by the proof test:

- the cells a response carries follow the page size and the projection (25 rows
  by 8 columns = 200 cells, by 30 columns = 750), never the table size;
- no pivot over the table is built: candidate ids are selected first, the page is
  cut, then only the projected cells of that page are read by primary key;
- the response stays far under the byte budget (about 14.5 KB for 25 x 8, about
  57 KB for 25 x 30) and the PHP peak memory stays flat (55 to 60 MB in the test
  environment, including the kernel) from 100,000 to a million records.

### Indexes of `data_rows`

Migration `Version20261113135247` adds two covering indexes. Every interactive
list orders rows by `timestamp DESC, id DESC` and counts them; without them
MySQL read and sorted **every row of the table** (or of the owner) for each page
and each count.

| Index | Serves |
| --- | --- |
| `idx_data_rows_table_timestamp (id_data_tables, timestamp, id, id_action_trigger_types)` | default order of a table, whole-table count, row-field filters |
| `idx_data_rows_table_user_timestamp (id_data_tables, id_users, timestamp, id, id_action_trigger_types)` | owner-scoped lists (`own_entries_only`), owner counts |

`id` precedes `id_action_trigger_types` on purpose: the index order then equals
`ORDER BY timestamp DESC, id DESC`, so the reverse index scan stops after the
page instead of sorting (with the trigger type first the optimizer chose a
filesort). The migration builds both indexes online (about 3 s for 1.1 million
rows); the older `idx_data_rows_id_data_tables` is now a prefix of the new ones and
is left in place (removing it is a separate clean-up).

Million-record table, same statements, `EXPLAIN ANALYZE` (MySQL 9.1):

| Statement | Before | After |
| --- | --- | --- |
| default page: candidate ids | 987 ms, 1,000,025 rows examined, filesort | 1.3 ms, 26 rows examined |
| default page, whole-table count | 921 ms | 103 ms (index only) |
| participant with 90,000 records: page ids / count | 4,373 ms / 4,134 ms | 9 ms / 180 ms |
| participant with 200 records: page ids / count | 3,886 ms / 3,848 ms | 0.3 ms / 4 ms |
| fixed filter on a cell, page ids | 1,147 ms, 1,000,068 rows examined | 1.0 ms, 69 rows examined |

### Measured requests

Through the real HTTP stack in one process (`tests/Golden/FormListPerformanceTest.php`),
on a Windows development machine, PHP 8.4, MySQL 9.1.0 with a **256 MB InnoDB
buffer pool** against about 1.5 GB of `data_cells` data and indexes (12.9 million
cells, 1.1 million records). Cold = application caches cleared; cached = the same
request again; after write = one record written through `DataService`, then the
same request. Times are milliseconds and vary with the buffer pool state, most for
the I/O-bound rows.

| Scenario (25 rows, 8 columns unless noted) | Cold | Cached | After write | Statements cold / cached |
| --- | ---: | ---: | ---: | --- |
| Million records: default page, fixed filter, 740,000 matching | 2,956 | 29 | 2,387 | 22 / 12 |
| Million: participant with 90,000 records | 162 | 39 | 66 | 25 / 12 |
| Million: participant with 200 records | 71 | 34 | 73 | 20 / 12 |
| Million: participant, search for a common word | 775 to 799 | 37 | 759 to 807 | 18 / 12 |
| Million: page 20,000 | 3,964 | 27 | 3,955 | 18 / 12 |
| Million: sort by a number column | 6,585 | 30 | 6,560 | 18 / 12 |
| Million: sort by a text column | 6,512 | 31 | 6,452 | 18 / 12 |
| Million: search for a rare word (about 50 matches) | 11,742 | 28 | 11,685 | 18 / 12 |
| Million: search for a common word (100,000 matches) | 6,969 | 29 | 6,981 | 18 / 12 |
| Million: three filters (choice, number range, date range) | 3,063 | 36 | 3,121 | 20 / 14 |
| Million: default page with two data-driven choice lists | 12,400 | 40 | 12,351 | 25 / 12 |
| 100,000 x 50 columns: default page, 8 columns shown | 660 | 34 | 479 | 22 / 12 |
| 100,000 x 50: search for a common word | 652 | 32 | 745 | 18 / 12 |
| 100,000 x 50: sort by a number column | 1,223 | 31 | 1,259 | 18 / 12 |
| 100,000 x 50: default page, 30 columns shown (750 cells, 56 KB) | 593 | 31 | 547 | 21 / 12 |
| 100,000 x 50: search, 30 columns shown | 694 | 31 | 699 | 18 / 12 |
| 100,000 x 50: sort by a number column, 30 columns shown | 1,208 | 33 | 1,180 | 18 / 12 |

Reading the numbers:

- **Cached** is 27 to 40 ms in every scenario (12 statements: authentication, page
  and language lookups; the list state itself comes from the cache).
- **Within target**: everything on the 100,000 x 50 dataset except the number
  sort (a near miss: 1.2 s against 1 s), the default page and the participant
  lists of the million-record table.
- **Over target by design of the four-table store**: on the million-record table,
  a sort by a cell, a substring search, filters on cells, data-driven choice
  lists (they count values over the whole dataset) and a count behind a filter on
  a cell cost O(number of candidate records): MySQL must visit one `data_cells`
  row per candidate (a primary-key lookup, about 0.025 ms when cached in the
  buffer pool, many times more when it has to read the disk) and evaluate the
  value (for a typed sort a pattern check and a cast). Indexing cannot remove
  that without storing typed or searchable copies of the values, which would be
  a new storage concept and is only a proposal. The same statements run for a
  small table in milliseconds.
- **After a write** a table's cached pages are recomputed, so a write makes the
  next read as slow as a cold one; the participant lists, whose cost does not
  depend on the table size, stay fast.

What helps in practice, without any engine change: keep participant lists
`own_entries_only` (the owner index makes them independent of the table size);
filter on row fields (`entry_date`, `user_name`, record id) rather than on cells
when a list covers a very large table; avoid data-driven choice lists on large
tables (use fixed options); give MySQL a buffer pool that holds the working set of
`data_cells` (the cell-driven numbers above are bound by the 256 MB pool of the
test machine); and keep fixed filters selective.

### Reproduce

The proof runs only in an isolated database whose name contains `perf` (the seed
script refuses any other). Never use the development or the regular test database.

```bash
export APP_ENV=test TEST_TOKEN=_form_list_perf
export REDIS_URL='redis://localhost:6379/6'   # a logical database of its own
php bin/console app:test:reset-db --force --env=test
php tests/Support/Performance/seed-form-list-dataset.php   # deterministic, restartable
SH_PERF=1 php bin/phpunit tests/Golden/FormListPerformanceTest.php
```

The seed creates 100,000 records x 50 columns with typed and text columns, and a
million-record table with 90,000 records of one participant and 200 of another.
The run writes `var/perf/form-list-performance.json` and `.md` with, per scenario,
cold/cached/after-write time, MySQL statements, PHP peak memory, response bytes,
rows, cells and whether each budget was met, plus the `EXPLAIN ANALYZE` plan and
rows examined of the canonical queries and the environment it ran on. Times are
reported next to the targets, not asserted (they depend on the machine); what is
asserted is status 200 and the page-size bound. Drop the isolated database when done
(`DROP DATABASE selfhelp_test_form_list_perf`) and clear its Redis database.

## Related references

- [form-lists.md](./form-lists.md) - the list styles built on the engine.
- [record-history.md](./record-history.md) - versions and revisions of records.
- [data-table-filter-safety.md](./data-table-filter-safety.md) - how author fragments are checked and translated.
- [../reference/api/18-admin-data-tables.md](../reference/api/18-admin-data-tables.md) - admin data API and export.
- [17-global-cache-system.md](./17-global-cache-system.md) - cache categories and invalidation.
