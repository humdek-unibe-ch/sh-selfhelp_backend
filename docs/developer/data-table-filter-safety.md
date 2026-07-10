# Data table filter safety

**Audience:** Developers and security reviewers  
**Status:** Active  
**Applies to:** `sh-selfhelp_backend` `0.1.35+`  
**Last verified:** 2026-07-09  
**Source of truth:** `App\Service\CMS\Common\DataTableFilterService`

Author-written SQL filter fragments are concatenated after `WHERE 1=1` inside the
`get_data_table_filtered*` stored procedures. They are **never trusted** from the
CMS UI or bundle import. All server paths must pass through
`DataTableFilterService` before reaching the repository.

## Contract

| Step | Rule |
|------|------|
| Route tokens | `{{route.<param>}}` interpolated with Symfony route requirements; `*_id` params validated as positive integers and emitted as literals (never quoted strings from URL) |
| String route values | Allow-list `^[a-zA-Z0-9._@-]+$` or match explicit regex requirement; embedded quotes rejected |
| Unresolved `{{` | Entire filter rejected (empty string) — never partial SQL |
| Denylist | `;`, `--`, `/*`, `DROP`, `DELETE`, `UPDATE`, `INSERT`, `UNION`, `INTO OUTFILE`, `LOAD_FILE`, `INFORMATION_SCHEMA`, `SLEEP`, `BENCHMARK`, `EXEC`/`EXECUTE` |
| Max length | 1000 chars (`VARCHAR(1000)` SP parameter) |
| AND glue | Bare conditions receive a leading `AND` in one place (`glueLeadingAnd`) |
| `record_id` on entry-record | **Not via author filter.** `load_record_from` names the route param; `PageService::resolveEntryRows()` builds `AND record_id = <int>` from the matched route (same idea as `entry-record-form`) |

## Audited call sites

| # | Call site | Used by | Protection |
|---|-----------|---------|------------|
| 1 | `DataTableFilterService::prepareFilter()` | entry-list `filter`, `PageService::interpolateDataConfig()` | Full pipeline |
| 2 | `PageService::resolveEntryRows()` | entry-list / entry-record hydration | Lists: `prepareFilter()`. Records: typed int from `load_record_from` + route params (no author SQL) |
| 3 | `SectionUtilityService::fetchData()` | `data_config` retrieval | `resolveDataConfigFilter()` → `prepareFilter()` |
| 4 | `DataService::getData()` / `getDataWithUserGroupFilter()` / `getDataWithAllLanguages()` | SP callers | Strips `{{` + `isSafeFilterFragment()` |
| 5 | `DataTableRepository::getDataTableWith*()` | repository boundary | `guardForStoredProcedure()` (length + unresolved tokens) |
| 6 | `DataService` update-path ~L173 | form update by field match | `buildStringEqualityPredicate()` (identifier allow-list + quoted literals) |
| 7 | `AdminDataController::getData()` | admin grid API | `filter = ''` (fixed empty) |
| 8 | Frontend `FilterBuilderInline` / `DataSourceForm` | authoring only | **Not trusted** — server validation is authoritative |

## Entry holders vs `data_config`

`data_config` remains supported on any section for generic retrieved data and
interpolation context (helper scopes such as `filters`, parent scopes, route
tokens). **`entry-list` and `entry-record` never use `data_config` to choose
their row table or retrieve mode.** Entry row loading is configured only through
`fields.data_table`, `own_entries_only`, `filter` (lists), `load_record_from`
(records), `scope`, and related style fields. Missing `fields.data_table` means
no entry rows, even when a legacy `data_config.table` binding is still present
on the section.

**Inspector UX:** authors who only edit **Data configuration** on an entry holder
will see an empty public list or detail until they set **Data table** under
**Properties**. Helper-scope `data_config` entries are optional and do not
substitute for **Data table**.

## Admin filter preview

`POST /cms-api/v1/admin/data/query-preview` (permission `admin.data.read`) returns
the normalized filter fragment, stored-procedure call shape, route params used,
and validation errors — **without executing arbitrary SQL**. The section
inspector filter builder debounces preview calls separately from auto-save.

## Tests

- Unit: `tests/Unit/Service/CMS/Common/DataTableFilterServiceTest.php`
- Integration matrix: `tests/Integration/CMS/FilterSafetyTest.php` (one regression per call site above)
- Entry holder binding: `tests/Integration/CMS/EntryListHydrationTest.php`, `tests/Integration/CMS/EntryRecordHydrationTest.php` (helper-scope filters, no `data_config` row fallback)
- Preview contract: `tests/Controller/Api/V1/Admin/DataQueryPreviewSchemaContractTest.php`

## Related references

- [composite.md](../reference/styles/composite.md) — entry-list / entry-record binding fields
- [27-db-driven-public-routing.md](./27-db-driven-public-routing.md) — `{{route.*}}` interpolation scope
