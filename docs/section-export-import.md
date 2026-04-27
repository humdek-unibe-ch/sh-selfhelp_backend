# Section Export/Import API Documentation

This document describes the section export/import functionality — the
contract between the admin UI, the static AI-prompt-generation workflow,
and the backend import pre-validation pipeline.

## Overview

Four admin endpoints power the round-trip:

1. **`GET /cms-api/v1/admin/pages/{page_keyword}/sections/export`** — export every
   section (and nested child) of a page.
2. **`GET /cms-api/v1/admin/pages/{page_keyword}/sections/{section_id}/export`** —
   export a single section with its entire subtree.
3. **`POST /cms-api/v1/admin/pages/{page_keyword}/sections/import`** — import
   sections as root-level children of the page.
4. **`POST /cms-api/v1/admin/pages/{page_keyword}/sections/{parent_section_id}/import`**
   — import sections as children of an existing section.

All four require the `admin.page.export` permission.

Two supporting endpoints power AI-assisted authoring:

- **`GET /cms-api/v1/admin/styles/schema`** (`admin.access`) — full style /
  field / default / relationship catalog. Consumed by the import
  pre-validation pass, the FE codegen script (`npm run gen:styles`), and the
  prompt-template generator (`bin/console app:prompt-template:build`).
- **`GET /cms-api/v1/admin/ai/section-prompt-template`** (`admin.page.export`)
  — serves the static markdown the admin UI copies to the user's clipboard
  when they click *Copy AI prompt* in the import modal.

## Minimized JSON shape

Both export and import use a **minimized** JSON payload: any field whose
value equals the DB default (`styles_fields.default_value`) is dropped from
the export, and a missing field in an import falls back to that same DB
default. This keeps payloads small and makes hand-authored or AI-generated
JSON much easier to write correctly.

Example (minimized export of one hero container):

```json
[
  {
    "section_name": "hero",
    "style_name": "container",
    "fields": {
      "mantine_size": { "en-GB": { "content": "md" } }
    }
  }
]
```

Emission rules:

- A field translation entry is emitted **only** when
  `content !== styles_fields.default_value` **or** `meta !== null`.
- `meta` is emitted only when non-null.
- `global_fields` (holds `condition`, `data_config`, `css`, `css_mobile`,
  `debug`) — each key is emitted only when non-null/non-empty; `debug` only
  when `true`. The whole object is omitted when every key would be.
- `fields` is omitted when no field survives the filter.
- `children` is omitted when empty.
- `section_name` is optional on import — backend auto-generates `"-{timestamp}"`.
- Booleans are stored as `"0"` / `"1"` strings in translation entries;
  `global_fields.debug` is the only real JSON boolean.
- `content: null` is **accepted** by the schema for forward-compat with
  older exporters, but the import path normalizes it to `""` before
  persisting. The underlying column
  `sections_fields_translation.content` is `TEXT NOT NULL` and the entity
  setter takes a non-nullable `string`. So a round-trip
  `export → import` will turn any latent `null` content into `""`.
  This is intentional: it means consumers never have to handle a `null`
  translation, and it kills a class of bugs where stale `null` values
  would resurface after a restore.

The frontend helper `readJsonFile` now only enforces that the top-level is
an array and that each entry has a `style_name`. Every other integrity check
happens server-side in the pre-validation pass.

## Request / Response shape

### Export response

```json
{
  "data": {
    "sectionsData": [
      {
        "section_name": "hero",
        "style_name": "container",
        "fields": { "mantine_size": { "en-GB": { "content": "md" } } }
      }
    ]
  }
}
```

### Import request

```json
{
  "sections": [
    {
      "section_name": "hero",
      "style_name": "container",
      "fields": { "mantine_size": { "en-GB": { "content": "md" } } }
    }
  ],
  "position": 0
}
```

`section_name`, `fields`, `global_fields`, and `children` are all optional.

### Import success response

The backend returns a flat list of imported sections (root + every nested
child). Field names match the actual `SectionExportImportService::importSections`
output:

```json
{
  "data": [
    { "id": 123, "section_name": "hero-1719834000", "style_name": "container", "position": 0 },
    { "id": 124, "section_name": "card-1719834000", "style_name": "card",      "position": null }
  ]
}
```

Notes:
- `section_name` is the auto-suffixed name actually persisted (`-{timestamp}`).
- `position` is `null` for descendants (they're attached via `sections_hierarchy`,
  not `pages_sections`); only first-level entries inserted with a `position`
  argument carry a numeric value.

### Import validation failure (HTTP 422)

When the two-phase pre-validation pass detects any problem (unknown style,
unknown field for that style, unknown locale, structural error), the entire
payload is rejected **before** `beginTransaction` — no partial writes.

The error travels through `ServiceException` → `ApiResponseFormatter::formatException()`
→ `formatError()`, so it lands in the **standard error envelope**: `errors[]`
sits under `data`, not at the top level, and `status` is the numeric HTTP
code (not the string `"error"`):

```json
{
  "status": 422,
  "message": "Unprocessable Entity",
  "error": "Import validation failed. See `data.errors[]` for per-node details.",
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-04-25T10:12:34+00:00"
  },
  "data": {
    "errors": [
      { "path": "$.sections[0]",                    "type": "unknown_style",  "detail": "Style 'herro' is not registered in the CMS." },
      { "path": "$.sections[0].fields.title",       "type": "unknown_field",  "detail": "Field 'title' is not valid for style 'container'." },
      { "path": "$.sections[0].fields.title.de-XX", "type": "unknown_locale", "detail": "Locale 'de-XX' is not registered." }
    ]
  }
}
```

The admin UI (`AddSectionModal`) reads `response.data.errors[]` and surfaces
it as a Mantine alert list so users can fix the JSON before retrying.

## Transactions & caching

- Import is wrapped in a DB transaction; pre-validation happens outside of
  it so we never pay the cost of opening one if the payload is invalid.
- On `commit`, the relevant cache categories (`pages`, `sections`,
  `conditions`) are invalidated. `styles` is intentionally **not** touched
  — imports never mutate the style schema, only section instances and
  their `global_fields.condition` blobs.
- Pre-validation + persistence share the same `StyleSchemaService` cached
  view of the style schema — no extra queries per request.

## AI prompt workflow (phase 1 — manual)

1. Admin clicks **Copy AI prompt** in the import modal.
2. Frontend calls `GET /admin/ai/section-prompt-template`, gets markdown,
   writes it to the clipboard.
3. User pastes into ChatGPT / Gemini / Claude / etc. together with a natural
   language description and the `<LOCALES>` placeholder replaced.
4. User downloads the returned JSON array, uploads it via the file picker.
5. Backend pre-validates → either 422 with structured `errors[]`, or 200
   with the imported sections list.

Phase 2 (direct LLM integration) will reuse the same pre-validation and
cached schema; nothing is thrown away.

## Placeholder image

The canonical placeholder image is committed at
`public/assets/image-holder.png` and served by the default Symfony static
route. The prompt template instructs LLMs to use
`/assets/image-holder.png` (relative path) for every `img_src` value so the
FE resolves it locally regardless of the deployment host.

## Editing the prompt template

The runtime endpoint `GET /admin/ai/section-prompt-template` renders the
prompt **on demand** from
`<ai_prompt_template_dir>/prompt_template_base.md` plus the live
`StyleSchemaService::getSchema()` catalog. There is no build step on the
hot path — edit the base markdown, save, and the next call to the endpoint
returns the new content (the schema cache picks up DB changes
automatically; force a refresh with
`CacheService::CATEGORY_STYLES → invalidateAllListsInCategory()` if needed).

The optional console command:

```bash
bin/console app:prompt-template:build
```

writes a snapshot to `<ai_prompt_template_dir>/ai_section_generation_prompt.md`
**only for offline review / diffing**. That file is gitignored, never
served, and goes stale the moment any style or field changes — do not
rely on it. The path is still controlled by the `AI_PROMPT_TEMPLATE_DIR`
env var; see `docs/developer/style-schema-endpoint.md` for the full
path-resolution rules.

The frontend codegen (`npm run gen:styles`) regenerates
`src/types/common/styles.types.generated.ts` from the same schema endpoint
that feeds the prompt — keep them refreshed together.

## Permissions summary

| Endpoint                                    | Permission          |
|---------------------------------------------|---------------------|
| `GET /admin/pages/{…}/sections/export`      | `admin.page.export` |
| `POST /admin/pages/{…}/sections/import`     | `admin.page.export` |
| `GET /admin/styles/schema`                  | `admin.access`      |
| `GET /admin/ai/section-prompt-template`     | `admin.page.export` |
