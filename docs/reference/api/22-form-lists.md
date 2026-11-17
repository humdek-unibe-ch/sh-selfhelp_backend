# Form list APIs (`entry-form-list` and `data-view`)

Audience: Developers and integrators (web, mobile, scripts).
Status: active.
Applies to: SelfHelp2 Symfony backend with the `entry-form-list` and `data-view` styles (migration `Version20261113120844`).
Last verified: 2026-11-14.
Source of truth: `FormListController`, `PageController`, `FormController`, `App\Service\CMS\FormList\*`, and the JSON schemas `config/schemas/api/v1/common/_form_list_state.json`, `_form_list_error.json`, `responses/frontend/form_list_*.json`, `requests/frontend/list_*.json`.

A list shows the records of a form with search, sort, filters and paging. The
**server decides** what a visitor may see and do; clients draw the answer. This
page is the wire contract. The styles are in
[../styles/forms.md](../styles/forms.md#entry-form-list), the architecture in
[../../developer/form-lists.md](../../developer/form-lists.md).

## Where a client gets a list

### In the page payload

`GET /cms-api/v1/pages/by-keyword/{keyword}` and `GET /cms-api/v1/pages/resolve?path=...`
add one property to every section of a list style, at any depth:

- `list_state`: the state object below;
- `list_error`: `{status, message, reason, parameter}` when this list cannot be drawn. The page is still served; the other sections are unaffected.

The list parameters of the page URL (`news.q=sleep&news.page=2`) are forwarded
unchanged as query parameters to these two endpoints. A page that contains a list
is answered `Cache-Control: private, no-store`. The cached page holds the
configuration only; rows are added after the cache, per visitor.

**Published or draft.** One effective configuration serves a request: a visitor is served
the **published** version of the page, and the list state, the forms, the conditions, the
quick actions, the history and the row templates of that request all read it. Only a caller
who may **edit** the page (`admin.page.update` plus the update grant; admin passes) and
asks for it (`preview=true` on the page, state and history endpoints; `"preview": true` in
the body of a write, quick action or restore) is served the draft, and then every part
reads the draft. A guest preview is `401`; a signed-in caller without edit rights gets
`403` and nothing from the draft. A page that was never published is served as its draft.
Access rights, routes, menus and the stored data are always live.

**No internal query configuration is sent.** The section of a list in the page payload keeps
what a renderer needs (`list_key`, `title`, `form_presentation`, `show_history`) and its
`list_state`. The way the data is read (`data_table`, `own_entries_only`, `allow_*`,
`list_columns`, `list_sort`, `list_controls`, `list_fixed_filter`, `quick_actions`,
`data_config`, `page_size`, `list_layout`, `list_row_css`, `detail_url`) stays on the
server, in the section and in its translations, except in the draft of an author who
switched `debug` on.

### On interaction

`GET /cms-api/v1/lists/{section_id}/state?page_id=<id>&language_id=<id>&<key>.page=2&...`

`page_id` is required. The response is the usual envelope with the state object
in `data`, `private, no-store`. Errors are HTTP statuses (see [Errors](#errors)).
There is no route permission; access is decided per request.

A page with a route (`/team/{record_id}`) names its declared route parameters as
`route[<name>]=<value>` (at most 8, values up to 255 characters), for example
`...&route[record_id]=3`; `preview=true` asks for the draft of a caller who may edit the page.
The route is the context of the record template (`{{route.record_id}}`) and of the supporting
values of a data view. It is not believed: the server accepts the parameters only when one of
**this page's** active routes has exactly these placeholders and the route resolver maps the
resulting path back to this page with exactly these values (`400 route_mismatch` otherwise, for
a missing, extra, unknown or malformed value, a value that adds a segment or that the resolver
sends to another page; `400 invalid_route` when `route` is not a map of text values). Without
`route[...]` the list has no route values. The page payload (`list_state`) already carries the
state for the route the page was resolved with.

## URL state

All parameters of a list live in the namespace `<key>.` (`<key>` is the section's
`list_key`, unique per page). Anything outside the namespace is ignored by the list.

| Parameter | Value | Meaning |
| --- | --- | --- |
| `<key>.page` | `[1-9][0-9]{0,8}`, at most 1,000,000 | page, 1-based; a page past the end is served as the last page |
| `<key>.q` | 2 to 100 characters after trimming | case-insensitive substring over the searchable columns |
| `<key>.sort` | `field:asc` or `field:desc`, comma separated, at most 3 | order; each field must be sortable, none twice |
| `<key>.f.<id>` | by control type | one value per control: text, `a,b` for a multi select, `1`/`0` for yes/no, `from..to` for ranges |
| `<key>.record` | positive integer | the open record |
| `<key>.mode` | `view`, `edit`, `create`, `history` | what to do with it (`record` alone means `view`). `history` needs a `record` and is refused with `403 history_disabled` when the list offers no history |

Malformed, unknown or repeated parameters in the namespace are refused with `400`
(`reason`, `parameter`), never silently ignored. The open record is looked up
inside the visitor's dataset (owner scope plus the author's fixed filter), not
inside the visitor's search, so a link to a record survives narrowing the list.

## State object

```json
{
  "list": {"section_id": 311, "page_id": 12, "key": "news", "style": "entry-form-list", "layout": "table",
           "row_css": "", "has_template": false,
           "form_presentation": "modal", "form_section_id": 312, "table_exists": true,
           "labels": {"add": "Add entry", "empty": "No entries yet.", "search": "Search", "confirm_delete": "Delete this entry?"}},
  "columns": [{"field": "title", "label": "Title", "format": "text", "sortable": true, "searchable": true}],
  "controls": [{"id": "status", "type": "select", "field": "status", "label": "Status",
                "options": [{"value": "open", "label": "open", "count": 4}]}],
  "state": {"page": 1, "page_size": 25, "q": null, "sort": [{"field": "entry_date", "direction": "desc"}],
            "filters": {}, "record": null, "mode": null, "query": {}},
  "capabilities": {"create": true, "search": true, "history": false,
                   "quick_actions": [{"id": "done", "label": "Mark done", "confirm": null}]},
  "rows": [{"record_id": 41, "entry_date": "2026-11-13 09:12:00", "version": 2, "values": {"title": "Write report"},
            "is_own": true, "can_update": true, "can_delete": true, "can_view_history": false,
            "quick_actions": ["done"], "detail_url": null}],
  "pagination": {"page": 1, "page_size": 25, "total": 1, "total_pages": 1},
  "selected": null
}
```

- `values` holds exactly the configured columns, keyed by column field. Formats `file` and `image` are `{ "files": [{ "index", "name", "mime", "size", "url" }] }` with `url` = `/lists/<section>/records/<record>/files/<field>?page_id=<id>&index=<n>` (section-scoped; never a storage path). Format `image` is drawn as a thumbnail, `file` as download links. Text longer than 1,000 bytes (8,000 for the open record) is cut.
- Prefill of an editable record (`selected.form_values` and form `record_prefill`) gives each `file-input` the same `{files:[…]}` shape. On update, omit the field to keep files; send `{ "remove": true }` to clear all, or `{ "remove_indexes": [0] }` to drop indexes (then an upload replaces a single-file field or appends to a multi-file field).
- `state.query` is the set of URL parameters that express the served state; clients build links from it and keep unrelated parameters.
- `capabilities.create` is false for a guest on an own-entries list. `rows[].can_*` are drawing hints, never authorization.
- `rows[].quick_actions` are the action ids whose condition holds now for that record; `capabilities.quick_actions` carries labels and confirmation texts.
- `selected` is the open record with `values`, `version`, `can_*`, `quick_actions` and `form_values` (prefill of the inline form; null when the caller cannot edit). Send `selected.version` back as `expected_version`.
- `list.form_section_id` is the inline form: a normal child section of the list in the page payload, drawn only while `state.mode` is `create` or `edit`.
- `list.table_exists` is false until the form was first submitted; show the empty state and the add button.
- `list.layout` is `table`, `cards` or `custom` (`custom` only for `data-view`). With `custom`, `list.has_template` is true when the view holds sections, and then every row carries `sections`: the section payloads of the view (the shape of the sections of a page payload) drawn for that record, with their tokens already replaced by the record's values. Only the configured columns plus `record_id`, `entry_date`, `_index` and the option labels `_<field>_label` are available; a file column is not. `list.row_css` holds the classes of the element around the records (web only; `""` for the other layouts). `rows[].sections` is absent when `has_template` is false (draw cards). A `data-view` carries no children in the page payload: the sections of a record reach a client only here.

## Writes use the ordinary form endpoints

A list has no create/update/delete route of its own. `FormListGuard` is consulted by
the existing endpoints ([17-form-submissions.md](17-form-submissions.md)):

| Operation | Request | Success | Refusals |
| --- | --- | --- | --- |
| create | `POST /cms-api/v1/forms/submit` with `section_id` = the inline form | `record_id`, `version` (1) | `403 operation_disabled`, `401` guest on an own-entries list, `400 table_mismatch`, `400 protected_field` / `unknown_field`, `422 invalid_form_data` |
| update | `PUT /cms-api/v1/forms/update` with `update_based_on: {record_id}` and `expected_version` | `record_id`, `updated_at`, `version` | `400 expected_version_required` / `invalid_expected_version`, `409 stale_version` (nothing written), `404` outside the dataset, `422 invalid_form_data` |
| delete | `DELETE /cms-api/v1/forms/delete` with `section_id` = **the list**, `record_id`, `expected_version` | `record_id`, `version` | `400 expected_version_required`, `409 stale_version`, `404` outside the dataset |

`expected_version` is **required** for every update, delete, quick action and restore (a
dry run needs none), and always sent by a list client; the rows and `selected` carry the
`version` to send back. A request without it, or with a value that is not a whole number of
at least 1, is refused with `400` before anything is stored. A stale save keeps the draft on
the client; the client offers reload, comparison and re-apply.

The writes answer to the rules every write follows
([17-form-submissions.md](17-form-submissions.md#rules-every-write-follows)): the body only
carries inputs of the form (as the effective page defines them) and nothing that names the
owner or the trigger, the input rules (required, range, options, format, read-only, hidden by
condition, files) are enforced with `422 invalid_form_data`, and a record number is only
reached inside the list's dataset (otherwise `404`). A participant needs the page `select`
right of their group; the operation switches (`allow_create`, `allow_update`,
`allow_delete`), record ownership and the existing data-table grants decide the rest.

## Current file of a shown record

`GET /cms-api/v1/lists/{section_id}/records/{record_id}/files/{field_key}?page_id=<id>&index=0&language_id=&preview=`

`section_id` is the `entry-form-list`, `data-view`, `entry-record` or
`entry-list` that shows the record. Access follows the **page** (F165): the
caller must see **the page being rendered** (`page_id`) and the record must be
inside the section's boundary (owner scope; list `filter` / data-view fixed
filter / `entry-record.fixed_filter`, including `status = published` on public
surfaces). The field must be a `file-input` the section shows or a row template
references (`{{…field…}}`, `__url`, `__files`), and for page viewers
`files_public` must be on (owner and table READ still get it without that
switch via `/forms/files`). Images (except `image/svg+xml`) are `inline`; SVG
and other non-image types are `attachment`. Headers match form-file delivery
(`private, no-store`, sandbox CSP). Hidden page or record: `404` (never `403`
that reveals it). Guest on a non-open page: `401`.

Entry-list / entry-record / data-view row templates also get
`{{scope.field__url}}` (first file), `{{scope.field__files}}` (JSON of the
`files` list above) and `{{scope.field__links}}` (HTML: one `<a href="{url}">`
per file with the escaped name, joined by `<br>`; empty string when there are
no files), built with this section-scoped route and the current page id. The
existing `text` style draws `__links` as clickable links on web and mobile;
`__files` stays for authors who need the JSON. `__url` and `__links` are
server-built: at interpolate time they are marked so Mustache leaves them
raw (origin-based escape; a visitor string that looks the same is escaped).
Nothing per caller enters the shared page cache: the same HTML for every
viewer; access is decided on the file request.

## Quick action

`POST /cms-api/v1/lists/{section_id}/records/{record_id}/actions/{action_id}`

Body `{page_id, expected_version}` (and `preview` for the draft) and nothing else. Response
`{record_id, version, action}`. The client names an action; the **author** defined what it
writes. Check order: operation allowed, signed in, action exists, record inside the
dataset, caller may update it, the action's `when` still holds, version matches, the value
the action assigns passes the rules of the form's input (a value the input refuses, for
example an option it no longer offers, is a `422 invalid_form_data`), then one ordinary
write with source `quick_action`. A refused request changes nothing.

## History (only when `show_history` is on)

All endpoints need a signed-in caller, the record inside the dataset (otherwise
`404`), `show_history` (otherwise `403 history_disabled`), and name only fields
the list or its form shows (`400 field_not_allowed`). A data view has no history.

| Endpoint | Query / body | Result |
| --- | --- | --- |
| `GET /cms-api/v1/lists/{id}/records/{record}/history` | `page_id`, `page`, `page_size` (1-100, default 20) | `{items, total, page, page_size, current_version}`; items newest first with `version`, `created_at`, `operation`, `source`, `actor`, `impersonated_by`, `restored_from_version`, `changed_fields`, `is_checkpoint` |
| `GET .../versions/{version}` | `page_id` | the revision's changes and the record's field state at that version |
| `GET .../compare` | `page_id`, `from`, `to` | the field changes between two versions |
| `POST .../restore` | `{page_id, version, expected_version, field_keys?, dry_run?}` | a dry run returns `{restorable, changes, blockers}`; a restore returns `{restored_from_version, version}` |
| `GET .../versions/{version}/files/{field_key}` | `page_id`, `file_language`, `index` | the historical file (attachment, `private, no-store`, sandboxed CSP) |

A change of a revision or a comparison is `{field_key, field_label, language_id,
change: added|changed|removed, kind: value|file, before, after, truncated}`; for a
file (`kind: file`) `before_files` and `after_files` list
`{index, name, available, url?}` (`url` is the historical-file route when
`available`) and `before`/`after` are `null`. The `changes` of a restore dry run are
`{field_key, field_label, language_id, action: set|remove, kind, before, after}` (with
the same file lists) - they say what the restore would write, so they carry `action`
instead of `change`. `changed_fields` of a history item is the number of fields the
revision changed.

A restore is a **new revision** (history is never rewound), needs the current update
permission, is validated against the current form and files with the same rules as an
ordinary save (an old value the input refuses today, a required input the restore would
empty, a file that is gone), and applies only fields the caller can see. A restore that
cannot be applied whole answers
`422 restore_blocked` with `blockers [{field_key, field_label, language_id, reason, detail}]`
where `reason` is `field_missing`, `language_missing`, `file_unavailable` or
`invalid_value`; nothing is applied. Deleted records are not restorable.

## Authoring (administration API)

A list is configured through the **section fields** of the ordinary section
endpoints (`PUT /admin/pages/{page_id}/sections/{section_id}`, section and page
bundle import). There is no list-specific administration route.

- **Validated on save and import.** A save that touches a list field is checked against the table the list reads. A problem answers `400` with the usual `errors[]` (`field`, `message`), **every** problem in one answer, and nothing is stored. A save that does not touch the list fields is never held up by a list that has become invalid. A `data-view` without a table is refused. A section or bundle import with an invalid list answers `422` with per-node errors before anything is created. Binding a table the editor's role cannot read is refused with `403` (F07).
- **`list_source` in the section details.** `GET /admin/pages/{page_id}/sections/{section_id}` carries `list_source` for `entry-form-list` and `data-view` sections whose table can be found: `data_table` (`{id, name}`, or `null` while the form inside the list was never submitted), `form_section_id` (the form inside the list when the list reads that form's table), `columns` (`{field, label, type}`: the inputs of the form in form order, then stored columns no input owns) and `row_fields` (`{field, label, type, column, sort, filter}`: entry date, record number and author, with the places a list may use them in). `field` is the immutable column key (`section_<input id>`) that the list's JSON fields store; the editor's pickers draw from this answer, which is exactly the set the save check accepts. It is read per request and never cached, because it follows the inputs of the form.

## Errors

Envelope `{status, message, data, error?}`; `data.reason` is machine readable.

| Status | `reason` | When |
| --- | --- | --- |
| 400 | `invalid_list_parameter`, `unknown_list_parameter`, `duplicate_list_parameter`, `field_not_allowed`, `table_mismatch` | malformed state, forged body, form bound to another table |
| 400 | `invalid_route`, `route_mismatch` | `route` is not a map of text values, or its parameters are not those of a route of this page |
| 400 | `expected_version_required`, `invalid_expected_version`, `unsupported_target_selection`, `protected_field`, `unknown_field`, `invalid_file_value` | a write without a usable version or target, or with a key that is no input of the form or names the owner, the trigger or internal data |
| 401 | `login_required` | a guest on an own-entries list, on history, or on a page that is not open (every list route) |
| 403 | `operation_disabled`, `history_disabled` | the operation is not offered |
| 404 | `unknown_action` | page or section not accessible or not on that page; record, version, file or action not found; wrong table, another owner, outside the fixed filter, deleted and missing look the same |
| 409 | `stale_version`, `action_not_available` | the record changed since it was read; the action's condition no longer holds. `data` carries `record_id`, `expected_version`, `current_version` for `stale_version` |
| 422 | `invalid_form_data`, `restore_blocked` | a value that breaks the rules of its input (`errors [{field, key, rule, message}]`); a restore that cannot be applied whole; also a bundle import whose list does not fit its data |
| 500 | `invalid_list_config` | a stored configuration that is not valid (the cause is logged, not sent) |

## Related references

- [../styles/forms.md](../styles/forms.md) - the two styles and their fields.
- [17-form-submissions.md](17-form-submissions.md) - the form endpoints lists write through.
- [15-frontend-pages.md](15-frontend-pages.md) - the page endpoints that carry `list_state`.
- [../../developer/data-query-engine.md](../../developer/data-query-engine.md), [../../developer/record-history.md](../../developer/record-history.md), [../../developer/form-lists.md](../../developer/form-lists.md).
