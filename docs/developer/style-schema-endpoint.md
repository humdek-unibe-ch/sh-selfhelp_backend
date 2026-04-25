# Style Schema Endpoint & Prompt-Template Generator

## `GET /cms-api/v1/admin/styles/schema`

Returns a canonical view of every style registered in the `styles` table
together with its allowed fields, the DB default for each field, and the
allowed parent / child relationships.

**Permission:** `admin.access` (same level as the existing styles list).

**Response shape** (`stylesSchema.json`):

```jsonc
{
  "status": "success",
  "data": {
    "container": {
      "id": 12,
      "group": "mantine",
      "can_have_children": true,
      "description": "Generic Mantine container component…",
      "fields": {
        "mantine_size":      { "type": "slider",   "display": 0, "default_value": null, "help": "…", "title": "Size" },
        "mantine_fluid":     { "type": "checkbox", "display": 0, "default_value": "0" },
        "use_mantine_style": { "type": "checkbox", "display": 0, "default_value": "1", "hidden": 1 }
      },
      "allowed_children": ["card", "text", "flex"],
      "allowed_parents":  []
    }
  }
}
```

### Consumers

1. **`SectionExportImportService`** — two-phase import pre-validation uses
   the schema to reject unknown styles, fields not valid for a given style,
   or unknown locales before opening a DB transaction.
2. **Prompt-template generator** (`bin/console app:prompt-template:build`) —
   renders the style / field catalog appendix that gets concatenated with
   the hand-maintained base template.
3. **Frontend codegen** (`npm run gen:styles`) — regenerates
   `src/types/common/styles.types.generated.ts` with a `TStyleNameFromDb`
   union, per-style field shapes, a runtime default-value map, and
   relationship maps — all derived from the schema.

### Caching

The schema is loaded once per request through `StyleSchemaService`, which
wraps a single join query (`StyleRepository::findAllStylesWithFields()`)
with `CacheService::CATEGORY_STYLES` (TTL: 4 h). After any DB change to
`styles`, `fields`, `styles_fields`, or `styles_allowed_relationships`,
invalidate the category directly:

```php
$cacheService->withCategory(CacheService::CATEGORY_STYLES)->invalidateAllListsInCategory();
```

There is intentionally no `StyleSchemaService::invalidateCache()` wrapper —
keeping invalidation surface centralized in `CacheService` avoids two
parallel APIs that drift apart.

## Prompt template — rendered on demand

The AI prompt is **never served from a static file**. Every request to
`GET /admin/ai/section-prompt-template` calls
`PromptTemplateService::render()`, which:

1. Reads the hand-authored base markdown
   (`<ai_prompt_template_dir>/prompt_template_base.md`).
2. Renders the auto-generated style/field catalog from
   `StyleSchemaService::getSchema()` (cached for 4 h via
   `CacheService::CATEGORY_STYLES`).
3. Injects the catalog between `<!-- CATALOG:BEGIN -->` and
   `<!-- CATALOG:END -->` in the base markdown (or appends it at the end
   if the markers are missing) and returns the result as a string.

Because the catalog is pulled live from the cached schema, the prompt is
always in sync with the database — there is no "stale snapshot" failure
mode for the runtime endpoint.

### Path resolution

The directory is controlled by the `ai_prompt_template_dir` container
parameter (backed by the `AI_PROMPT_TEMPLATE_DIR` env var, default
`docs/ai`). Relative values are anchored to `%kernel.project_dir%`;
absolute paths pass through.

The directory MUST contain:

| File                                | Role                                                                                                                                                                   |
|-------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `prompt_template_base.md`           | **Committed, hand-authored.** Loaded on every request. Should include `<!-- CATALOG:BEGIN -->` / `<!-- CATALOG:END -->` markers; if missing, the catalog is appended.  |
| `ai_section_generation_prompt.md`   | **Optional, gitignored, never read at runtime.** Only written by `bin/console app:prompt-template:build` for offline review / diffing. Goes stale the moment the schema changes. |

If `prompt_template_base.md` is missing, the endpoint returns HTTP 500 with
the path it expected — make sure it ships in every deploy.

## `bin/console app:prompt-template:build` (optional snapshot dump)

```bash
bin/console app:prompt-template:build [--output=<path>]
```

This is **not** part of the runtime path. It exists only so an editor can
dump the currently-rendered prompt to disk for offline review or to diff
two snapshots after a schema change. The default output is
`<ai_prompt_template_dir>/ai_section_generation_prompt.md`, which is
gitignored on purpose. Do **not** commit the output and do **not** rely on
it for the API contract.

## `GET /cms-api/v1/admin/ai/section-prompt-template`

**Permission:** `admin.page.export` (same as export/import, since it's
authored content).

Returns the rendered prompt with `Content-Type: text/markdown; charset=utf-8`
and `Cache-Control: private, max-age=60`. The body is composed per request
from `prompt_template_base.md` + live `StyleSchemaService::getSchema()` —
edit the base markdown and the change is visible on the next call without
running any console command.

Used by the admin UI's *Copy AI prompt* button in `AddSectionModal`.

## Related files

| File                                                                   | Purpose                                                |
|------------------------------------------------------------------------|--------------------------------------------------------|
| `src/Repository/StyleRepository.php`                                   | `findAllStylesWithFields()` — single-query fetch                 |
| `src/Service/CMS/Admin/StyleSchemaService.php`                         | Cached accessor + default-values helpers                         |
| `src/Service/CMS/Admin/PromptTemplateService.php`                      | On-demand renderer (base markdown + live catalog)                |
| `src/Controller/Api/V1/Admin/AdminStyleController.php`                 | Hosts the two endpoints                                          |
| `src/Command/BuildPromptTemplateCommand.php`                           | Optional offline snapshot dumper (NOT used at runtime)           |
| `config/schemas/api/v1/responses/style/stylesSchema.json`              | Response JSON schema                                             |
| `migrations/Version20260424120000.php`                                 | Registers the two new `api_routes` rows                          |
| `<ai_prompt_template_dir>/prompt_template_base.md`                     | **Committed, hand-authored, loaded per request** (default `docs/ai/...`) |
| `<ai_prompt_template_dir>/ai_section_generation_prompt.md`             | Gitignored offline snapshot — never read at runtime              |
| `../sh-selfhelp_frontend/scripts/gen-styles-types.mjs`                 | FE codegen script (consumes the schema endpoint)                 |
