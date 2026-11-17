# Data table filter safety

**Audience:** Developers and security reviewers  
**Status:** Active  
**Applies to:** `sh-selfhelp_backend` with the data query engine (`App\Service\CMS\DataQuery\*`)  
**Last verified:** 2026-11-13  
**Source of truth:** `App\Service\CMS\Common\DataTableFilterService`, `App\Service\CMS\DataQuery\LegacyFilterTranslator`, `App\Service\CMS\DataQuery\DataQueryEngine`

Authors write filter fragments in the CMS (`data_config` filters, the `filter` of
an `entry-list`). They are **never trusted** from the CMS UI or from a bundle
import. Since the data query engine ([data-query-engine.md](./data-query-engine.md))
a fragment is **never pasted into SQL**: it is checked by `DataTableFilterService`,
read by `LegacyFilterTranslator` into a structured filter (columns, operators,
values, sort, window), and the engine binds every value as a parameter and
allowlists every column against the table's own columns. The three
`get_data_table_*` stored procedures still exist in the database but no code path
calls them (cleanup is a proposed finding).

Two layers therefore protect a read: the **checks** below (which keep the refusals
of the stored-procedure era) and the **translation** (which refuses anything
that has no structured form, so there is no text left to inject into).

## Contract

| Step | Rule |
|------|------|
| Route tokens | `{{route.<param>}}` interpolated with Symfony route requirements; `*_id` params validated as positive integers and emitted as literals (never quoted strings from URL) |
| String route values | Allow-list `^[a-zA-Z0-9._@-]+$` or match explicit regex requirement; embedded quotes rejected |
| Unresolved `{{` | Entire filter rejected (empty string) — never partial SQL |
| Denylist | `;`, `--`, `/*`, `DROP`, `DELETE`, `UPDATE`, `INSERT`, `UNION`, `INTO OUTFILE`, `LOAD_FILE`, `INFORMATION_SCHEMA`, `SLEEP`, `BENCHMARK`, `EXEC`/`EXECUTE` |
| Max length | 1000 chars |
| AND glue | Bare conditions receive a leading `AND` in one place (`glueLeadingAnd`) |
| Translation | `LegacyFilterTranslator` accepts comparisons, `[NOT] IN`, `BETWEEN`, `IS [NOT] NULL`, `LIKE` with only a leading/trailing `%`, `AND`/`OR`/parentheses, `ORDER BY`, `LIMIT`. Functions, sub-queries, arithmetic, `GROUP BY`, inner wildcards and unknown columns are refused with a message naming the part. A refused read returns nothing; the admin preview reports it |
| Binding | The engine binds every value; a number written without quotes compares as a number; a quoted value compares as the column's declared type (`data_cols.id_value_type`) |
| `record_id` on entry-record | **Not via author filter.** `load_record_from` names the route param; `PageService::resolveEntryRows()` builds `AND record_id = <int>` from the matched route (same idea as `entry-record-form`) |

## Audited call sites

| # | Call site | Used by | Protection |
|---|-----------|---------|------------|
| 1 | `DataTableFilterService::prepareFilter()` | entry-list `filter`, `PageService::interpolateDataConfig()` | Full pipeline (tokens, denylist, length) |
| 2 | `PageService::resolveEntryRows()` | entry-list / entry-record hydration | Lists: `prepareFilter()`. Records: typed int from `load_record_from` + route params (no author SQL) |
| 3 | `SectionUtilityService::fetchData()` | `data_config` retrieval | `resolveDataConfigFilter()` → `prepareFilter()` |
| 4 | `DataService::getData()` / `getDataWithUserGroupFilter()` / `getDataWithAllLanguages()` / `getDataInBatches*()` | all callers that pass a fragment | Strips `{{` + `isSafeFilterFragment()`; then `guardForStoredProcedure()` |
| 5 | `DataTableRepository::getDataTableWith*()` / `batchesWith*()` | repository boundary | `guardForStoredProcedure()` (length + unresolved tokens), then `LegacyFilterTranslator`, then `DataQueryEngine` (bound values, allowlisted columns) |
| 6 | `DataService` update-path (match by field values) | form update by field match | `buildStringEqualityPredicate()` (identifier allow-list + quoted literals), then call site 5 |
| 7 | `AdminDataController::getData()` | admin grid API | `filter = ''` (fixed empty) |
| 8 | Frontend `FilterBuilderInline` / `DataSourceForm` | authoring only | **Not trusted** — server validation is authoritative |
| 9 | `entry-form-list` / `data-view` | form lists | **No fragment at all.** The list's filters are structured JSON (`list_fixed_filter`, `list_controls`); `FormListConfigValidator` checks them when the section is saved or imported and `DataFilterJson` checks them again before the engine plans the query ([form-lists.md](./form-lists.md)) |

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
the normalized filter fragment, the engine call and the structured query it
would run (filter, sort, window), route params used, and validation errors,
**without executing any query**. The preview uses the same `LegacyFilterTranslator`
as the real read, so a filter the preview accepts is a filter that returns rows,
and a construct the translator refuses is reported as an error. The response key
`stored_procedure` keeps its old name so existing clients still render it; it
now describes the engine call. The section inspector filter builder debounces
preview calls separately from auto-save.

## Tests

- Unit: `tests/Unit/Service/CMS/Common/DataTableFilterServiceTest.php`, `tests/Unit/Service/CMS/Admin/DataQueryPreviewServiceTest.php`
- Integration matrix: `tests/Integration/CMS/FilterSafetyTest.php` (one regression per call site 1–8)
- Call site 9 (structured list filters): `tests/Unit/Service/CMS/FormList/FormListConfigReaderTest.php`, `tests/Controller/Api/V1/Admin/FormListAuthoringTest.php`, `tests/Controller/Api/V1/Frontend/FormListSecurityTest.php`
- Engine equivalence with the legacy filter shapes: `tests/Integration/Repository/FormListQueryTest.php`
- Entry holder binding: `tests/Integration/CMS/EntryListHydrationTest.php`, `tests/Integration/CMS/EntryRecordHydrationTest.php` (helper-scope filters, no `data_config` row fallback)
- Preview contract: `tests/Controller/Api/V1/Admin/DataQueryPreviewSchemaContractTest.php`

## Related references

- [data-query-engine.md](./data-query-engine.md) — the engine, its limits and the reader inventory
- [composite.md](../reference/styles/composite.md) — entry-list / entry-record binding fields
- [27-db-driven-public-routing.md](./27-db-driven-public-routing.md) — `{{route.*}}` interpolation scope
