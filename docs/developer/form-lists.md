# Form lists (`entry-form-list` and `data-view`)

Audience: Backend developers (and renderer authors who need the server rules).
Status: active.
Applies to: SelfHelp2 backend, `App\Service\CMS\FormList\*`, `FormListController`, `PageController` (migration `Version20261113120844`).
Last verified: 2026-11-15.
Source of truth: the classes above, `FormController` and `FormListGuard`, the JSON schemas under `config/schemas/api/v1/` (`common/_form_list_state.json`, `common/_form_list_error.json`, `responses/frontend/form_list_*.json`, `requests/frontend/list_*.json`), and the tests listed at the end.

A list is the server-rendered view of the records of a form with a create / view /
edit / delete workflow. It adds **no new storage, cache, permission system or
extension point**: records live in the generic data store, reads go through the
[data query engine](./data-query-engine.md), writes go through the ordinary form
endpoints and `DataService`, history is the [record history](./record-history.md),
and who sees what is decided by groups (pages, data access) and roles (editing).
The server decides; clients draw.

- Styles and fields for authors and administrators: [../reference/styles/forms.md](../reference/styles/forms.md#entry-form-list).
- Wire contract: [../reference/api/22-form-lists.md](../reference/api/22-form-lists.md).
- Task guide for administrators: [../user/entry-lists.md](../user/entry-lists.md).

## Two styles, one foundation

| Style | Writes | Typical use |
| --- | --- | --- |
| `entry-form-list` | create, update, delete, quick actions, history/restore | participant to-do lists, journals; team-managed CMS-in-CMS lists |
| `data-view` | never (the server refuses a forged write whatever the caller may do elsewhere) | public news/FAQ/team lists; read-only personal views |

Both read their configuration from the **section's own fields** through
`FormListConfigReader`, the only class that knows how those fields are stored
(shapes, enumerations, limits). `FormListConfigValidator` adds what needs the
table (does the column exist, does the value fit its type, do the filter and
the quick-action conditions compile against the table) and runs **when a list
section is saved and when a bundle is imported**; there is no unchecked
configuration path. If a bad value gets into the store another way, the list
answers `500 invalid_list_config` without taking the page down.

A list reads **one table**: its own `data_table`, otherwise the table of its
inline form (the shallowest form of the `form-record` / `form-log` /
`entry-record-form` family under it, by level, position and id;
`FormListLayout`). A `data-view` always names a table.

## The dataset boundary

Every read, the open record, quick actions, history and writes use the same
**dataset**: the table, the owner scope (`own_entries_only`: a visitor's own
records; a guest reads nothing) and the author's fixed filter. The visitor's
search, sort, filters and page only **narrow** it. The open record is looked up
inside the dataset and never inside the visitor's search, so a link keeps
working after narrowing and can never reach a record outside the dataset.

## One effective configuration per request

Every list request (state, page payload, form write, delete, quick action, history, restore,
row template) reads **one** `EffectivePage` (`EffectivePageResolver`): the **published**
version of the page for a visitor, the **draft** only when a caller who may **edit** the
page asks for the preview (`preview=true`: `admin.page.update` plus the update data-access
grant on that page; the admin role passes), and the draft for a page that was never
published. A guest preview is `401`; a signed-in caller without those rights gets `403` and
nothing from the draft. The list configuration (`FormListConfigReader` over the section
fields of that version), the inline form (`EffectiveFormFactory`: inputs, rules, options,
files, conditions), the conditions of the section and its ancestors, the quick actions and
the row template all come from it, so a request can never combine the published page tree
with live list properties or the draft form with the published list. The resolver is
memoised per request. Page access (ACL), routes, menus and the stored data are not part of
the snapshot: they stay live, as everywhere else in the platform.

## Request flow

1. **Resolve** (`FormListContextResolver`): the page is readable by the visitor, the section sits on that page, is visible and is a list, every ancestor condition and its own condition hold, and only then are the configuration and table read, all from the effective page above. A page the caller may not see answers `404` on every list route (`401` for a guest on a page that is not open). A list that does not exist on a page the caller can open answers like one on another page (`403`).
2. **Parse** (`FormListUrlStateParser`): the raw query string, namespace `<list_key>.`, strict (unknown, repeated, array-style or malformed parameters are refused, never ignored). PHP rewrites a dot in a parameter name, so the raw string is read. Modes are `view`, `edit`, `create` and `history`. `history` needs a `record` and answers `403 history_disabled` when the list offers no history.
3. **Build** (`FormListStateService`): one engine call pages the ids, a second reads only the projected cells of that page, a count gives the total, and controls with `options_from: data` get their facets. Rows carry `can_update`, `can_delete`, `can_view_history` and the quick actions whose condition holds now. Long text is cut; files are described without paths.
4. **Deliver**: embedded in the page payload (`list_state` / `list_error`, added by `PageController::withListStates` after the page cache, so the cached page stays shared and rows are per visitor) and on interaction by `GET /cms-api/v1/lists/{section_id}/state`. Pages with lists and every list endpoint are `Cache-Control: private, no-store`. A list that cannot render becomes `list_error` on its own section; the rest of the page is served.

## The record template of a data view

A `data-view` whose `list_layout` is `custom` takes **child sections** as the layout of one
record (the only list style with free children; the children of an `entry-form-list` are its form).

- **Authoring.** The sections are ordinary sections under the view in the page tree. The
  migration `Version20261113210755` sets `styles.can_have_children = 1` for `data-view` and adds
  the property `list_row_css` (display 0, web only). `FormListConfigReader` accepts `custom` for a
  data view only and refuses `list_row_css` values that are not plain class names (at most 300
  characters).
- **Not in the page payload.** `PageService` keeps the children of a data view raw (no hydration,
  no interpolation), and `FormListPageStateService::decorate` strips them from the page payload.
  A visitor therefore never receives the template with its unreplaced tokens, only the drawn
  records.
- **Drawing.** `FormListContextResolver::rowTemplate` reads the template from the cached page tree
  (`getPageSections`, with the styles in `StyleNames::ROW_TEMPLATE_EXCLUDED_STYLE_NAMES` removed
  at any depth); `FormListStateService::withRowSections` builds one token map per record (the
  projected values, `record_id`, `entry_date`; file cells are skipped) and
  `PageService::renderRowTemplate` renders the template once per record. It maps the stored keys to
  the input names of a form-owned table (`DataService::remapEntriesToInputNames`), adds the
  translated option labels (`OptionLabelHydrator::hydrate`) and `_index`, and runs
  `processSectionsRecursively` per record, so translations and classes work as anywhere else.
- **What a template section can read.** The same sections feature set as anywhere on the page,
  with one context per record that the **server** owns: the projected values of the record,
  `record_id`, `entry_date`, `_index` and the option labels; the identity scopes `system` and
  `globals`; the declared route of the page (`{{route.record_id}}`, below); and the supporting
  values of the view (its `data_config`, one scope per entry, read once for the page with the route
  as the only context). A section of the template may also have a `data_config` of its own that uses
  `{{record_id}}`, a value of the record or a `{{route.*}}` token, and may carry a **condition** that
  uses `{{tokens}}` of that context, for example
  `{">":[{"var":"{{seats}}"},10]}` or `{"==":["{{city}}","Basel"]}`, so a card can show a section only
  for the records it applies to. A column of a record that is called `route`, `route_requirements`,
  `system`, `globals` or `_index`, or like a scope the page declares, never replaces that scope; a
  column the list does not show is not a token (`{{note}}` is empty), and a file is not one.
- **Conditions of a template are safe by construction.** `InterpolationService::interpolateCondition`
  resolves the tokens in the decoded JSON Logic, one string at a time, never in the JSON text, so a
  value of a record can only become a text in the condition: it cannot close a string, add an operator
  or change the shape. `{"var":"{{city}}"}` is the value of the column, not a lookup of the variable
  of that name, so a record can never pick which system variable (`language`, `user_group`, ...) a
  condition reads; a `var` without a token still is a variable. Values are compared as they are (no
  HTML escaping) and a value that is not a text (a list) is an empty text. A section outside a record
  template keeps its condition exactly as before (it is not interpolated, apart from the `debug` view).
- **Route of the page.** A page with a route (`/team/{record_id}`) draws its template with
  `{{route.record_id}}`. In the page payload the server already knows the route; on interaction the
  client repeats the declared route parameters as `route[<name>]=<value>` in the list state request
  (`FormListController::state`). They are not believed: `PageRouteResolverService::resolveParams`
  builds the path from a route of **that page** and accepts the parameters only when the resolver maps
  it back to that page with exactly these values (the requirement of every placeholder holds, none
  is missing or extra, and a value that the resolver would send to another page or that adds a segment
  is refused: `400 route_mismatch`; a malformed shape is `400 invalid_route`). A request without
  `route[...]` has no route values, and a data_config filter of the template that needs one finds
  **nothing** (`FormListStateService` renders with a strict `data_config` mode: an unresolvable
  filter is `record_id = 0`, never "no filter").
- **State.** `rows[].sections`, `list.has_template`, `list.row_css`
  (`config/schemas/api/v1/common/_form_list_state.json`). Search, sort, filters and paging are
  unchanged: they pick the records, the template only draws them. The first paint of the page and
  the list state endpoint render the template from the same context, from the published page for a
  visitor and from the draft for a preview.
- **Limits (kept on purpose).** The response grows with the complexity of the template times
  `page_size`; templates use a page size of 6 to 12. A condition of the template reads the record,
  the route and the supporting values, but not another record. Row templates need no new table,
  cache or permission: they are rendered from the same dataset, in the same request, as the rows.

## Writes

A list has no create/update/delete route. The inline form submits through
`POST /forms/submit`, `PUT /forms/update` (`update_based_on.record_id` plus
`expected_version`) and `DELETE /forms/delete` (`section_id` = the list).
`FormListGuard` is consulted by `FormController` for each: the operation switches
(`403 operation_disabled`), a guest on an own-entries list (`401`), and the table
binding (`400 table_mismatch`: the form must write into the list's table).
Ownership rules stay in `DataAccessSecurityService`: with `own_entries_only` on a
record is changeable only by its owner; with it off, changing someone else's
record needs the table's update (delete) grant; the **owner is never changed** by
an edit, a quick action or a restore. A participant needs only the page `select` right of
their group (groups see); the page's insert/update/delete rights are not consulted.

The request is **untrusted**, and the same four rules hold for every route
(`FormInputValidator`, `FormListBoundary`, `RecordWriteContext`):

1. **Inputs come from the effective form.** A body key must be an input of the form as the
   effective page defines it. Owner, trigger and internal metadata (`id`, `id_users`,
   `id_action_trigger_types`, `triggerType`, `timestamp`, `version`, `record_id`, `__*`, …) and
   unknown keys are refused (`400 protected_field` / `unknown_field`) on JSON and multipart,
   for signed-in people and guests. A form without any declared input keeps accepting plain
   keys (finding). Jobs and imports use the trusted internal `DataService` entry points; a
   client cannot select them.
2. **The input rules apply everywhere.** Required, range, length, format, options, date
   limits, read-only, hidden by a condition, file type and count: one validator for the
   normal save, the value a quick action assigns and the state a restore would produce
   (`422 invalid_form_data`; a restore that would write a value the form refuses today is
   blocked whole: `422 restore_blocked`). A refusal leaves no row, revision, upload or job.
3. **The dataset bounds every door.** Update (by record number or by field values), delete,
   quick action, history and restore find their record inside the list's dataset (table,
   fixed filter, own entries); anything else is `404`, like a missing record. The boundary
   is checked again under the row lock of the write, so a record that left the dataset
   meanwhile is not changed. A write that moves an entry out of the dataset is allowed.
4. **The version is strict.** `expected_version` is required for interactive updates,
   deletes, quick actions and restores: missing, malformed (also as multipart text),
   negative, fractional or coerced values are `400` before an upload is stored; a stale one
   is `409`. Trusted operations that have no draft (jobs, imports) use the internal lock
   protocol of `RecordWriteContext`, which a client cannot choose.

The same change also closed the
security findings this workflow stands on: update and delete are bound to the
form's table (F127), request query/body parameters are no longer merged into
condition and interpolation variables (F04; route parameters keep their own
`{{route.*}}` scope), the section's and its ancestors' conditions are checked
again when a form is submitted, updated or deleted (F05), and the table a
section binds is checked against the editor's access when the section is saved
or imported (F07; `SectionTableBindingGuard`).

## Quick actions

`FormListQuickActionService` runs `POST /lists/{id}/records/{record}/actions/{action}`
with only `{page_id, expected_version}`. The client names an action; the author
defined what it writes. Order: operation allowed → signed in → action exists →
record inside the dataset → caller may update it → the action's `when` still
holds on the stored record → version matches → the value the action assigns passes the
input rules of the effective form (the candidate state is validated like a save) → one
ordinary write with source `quick_action`, so audit, revision and the form's actions (emails,
jobs) behave as for any edit. A refused request changes nothing and schedules nothing
(`FormListQuickActionTest`, `FormListWriteValidationTest`).

## History

`FormListHistoryService` exposes versions, comparison, the state at a version,
restore (with dry run) and historical files, only when `show_history` is on, only
for a signed-in caller who owns the record or (on a shared list) may update the table,
inside the dataset, and only for fields the list's columns or the visible inputs of the
form show (an input a condition hides from the person is not shown). Files of old versions
are described, never exposed by path, and delivered only by the version-file endpoint as a
private, `no-store`, sandboxed attachment; an erased history has no files
(`FormListHistoricalFileTest`). See [record-history.md](./record-history.md).

## Portability

Bundles must not carry install-specific ids. `FormListPortability` +
`FormListColumnReferences` (the one place that knows where a list names a
column: columns, default order, controls, fixed filter, quick actions) do this:

- a list bound to a form of the same bundle names its table by the owner token `@section:<form name>` (like entry tables) and its columns by **input name**;
- export converts field keys to names (and adds the inline forms' rows when `includeDataRows` is on; two sections with the name of an exported form refuse the export);
- import relinks names to the new field keys after the sections exist and again after owner tokens resolve, then validates strictly; a list that cannot work with its data answers `422 Import failed: the list "<name>" cannot work with its data: ...` and creates nothing. The relink is idempotent;
- input names that appear in a list must match `^[A-Za-z][A-Za-z0-9_]{0,63}$` (use snake_case); `detail_url` is a site path and gets the route prefix of the import; a list bound to another install's numeric table id exports as is (install-local).

## Decisions worth knowing

- **Column types live on `data_cols`** (`id_value_type`, lookup group `dataColValueTypes`, NULL = text); a known form input supplies it when its column is created and refreshes it when the form is saved.
- **Declared, never-saved fields read as empty (F169).** A list column, filter, sort or `fixed_filter` / `list_fixed_filter` that names an input the form declares but that has no `data_cols` row yet (nobody has saved a value for it) is treated as an empty value: `eq 'published'` matches nothing, `empty` / `ne` behave as on a missing cell, a projected column shows empty, and the list answers 200 — never `Unknown column`. Columns are still created on the first save that holds the field. A name the form does not declare stays refused.
- **Search** is a case-insensitive substring over author-marked searchable columns (2 to 100 characters).
- **Structured filters only.** Lists never take SQL; `list_fixed_filter` and `list_controls` are JSON validated twice.
- **No new export.** Admin data export and page-bundle dataset export were moved to batched reads with identical output.
- **Published configuration for visitors, draft only for a preview.** The section fields of a list, its form and its row template are read from the published version of the page (the draft for an explicit preview of a signed-in author, and for a page that was never published); access rights and data stay live. See "One effective configuration per request" above; what stays live is what finding F43 names (ACL, routes, menus, bound data).

## Known limits (kept on purpose)

- A condition of a section of a record template reads the record, the declared route and the supporting values of the view; the condition of a section outside a template, and the clones of an `entry-list`, are still not interpolated outside the `debug` view.
- A template section that links to the record writes the address itself (`/events/{{record_id}}`); `detail_url` is for the table and cards layouts.
- A duplicate `list_key` on one page is a configuration fault (`500`).
- A save that does not touch the configuration fields is not blocked by an already-invalid list.
- Quick-action `set` values are default-language literals.
- File URLs appear in public lists only for `files_public` fields (section-scoped current-file route) or for the owner / table READ (`/forms/files`). Anyone-with-link is gone (F165).
- Template tokens for a file field on entry-list / entry-record / data-view row layouts: `{{field__url}}` (first file), `{{field__files}}` (JSON), `{{field__links}}` (HTML `<a>` list for the `text` style). `__url` / `__links` are marked server-built at interpolate time so they stay raw; visitor text is always escaped. Same string for every viewer; access is on the file request (rule 3.6b).
- Lists are not recorded by `extractDataTableDependencies` (the dependency scan of imports); the table binding is still checked on import.
- `data_rows.id_users` is unindexed; a composite `(id_data_tables, id_users)` index is a candidate if own-record lists on very large tables need it.

## Tests

`FormListConfigReaderTest`, `FormListUrlStateParserTest`, `FormListColumnReferencesTest` (unit); `FormListQueryTest`, `FormListHydrationTest`, `FormListSeedDefaultsTest`, `FormListTemplatePortabilityTest`, `DataTableExportTest` (integration); `FormListStateTest`, `FormListRowTemplateTest`, `FormListSecurityTest`, `FormListQuickActionTest`, `FormListWriteValidationTest`, `FormListPublishedConfigTest`, `FormListHistoricalFileTest`, `FormListAuthoringTest`, `FormRecordVersionTest`, `FormRecordBindingTest`, `FormConditionRecheckTest` (controller); `FormListSchemaContractTest` (contract: validates every list response, in every mode, against its published schema - the response formatter only logs a mismatch, so only this test fails on drift); `FormListWorkflowTest` (golden); `DataRevisionTest`, `DataRevisionRaceTest`, `DataRevisionErasureTest`, `DataColumnValueTypeTest`.

`QaFormListFixture` (group `qa`, seeded by `composer test:reset-db`; `QaFormListFixtureSmokeTest` checks it) is the data of the browser and native specs: `qa-entries` (an `entries` list with the form in a modal and history, a `tasks` list with the form on a page and a file input), `qa-events` and `qa-event-cards` (public data views) and `qa-wide` (two lists whose form has 24 inputs with very long labels and 12 long column labels, for the size checks of the frontend `form-list-wide.spec.ts`). No checkbox of `qa-wide` is required: the stock checkbox style without an input wrapper posts no value, so a required one could not be satisfied from the browser.

## Related references

- [data-query-engine.md](./data-query-engine.md), [record-history.md](./record-history.md), [data-table-filter-safety.md](./data-table-filter-safety.md).
- [section-export-import.md](./section-export-import.md) - bundle format.
- [19-data-access-management.md](./19-data-access-management.md) - table grants.
