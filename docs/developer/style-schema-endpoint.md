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
with `CacheService::CATEGORY_STYLES` (TTL: 4 h). Any style / field
migration should call `StyleSchemaService::invalidateCache()` or add the
category to a cache-invalidation job — see `CacheService::invalidateAllListsInCategory`.

## Prompt-template generator

```bash
bin/console app:prompt-template:build \
  [--base=<path/to/prompt_template_base.md>] \
  [--output=<path/to/ai_section_generation_prompt.md>]
```

The command:

1. Reads the hand-maintained base markdown (defaults to
   `../sh-selfhelp_frontend/docs/AI Prompts/prompt_template_base.md`).
2. Renders the auto-generated catalog appendix from the style schema
   service.
3. Writes the concatenated final markdown to
   `../sh-selfhelp_frontend/docs/AI Prompts/ai_section_generation_prompt.md`
   (the file served by `GET /admin/ai/section-prompt-template`).

Re-run it whenever styles/fields change or the base prompt is edited.
The generated file is meant to be committed so that the clipboard-copy
flow in the admin UI always returns a reproducible, version-controlled
prompt.

## `GET /cms-api/v1/admin/ai/section-prompt-template`

**Permission:** `admin.page.export` (same as export/import, since it's
authored content).

Returns the contents of `ai_section_generation_prompt.md` with
`Content-Type: text/markdown; charset=utf-8`. No per-request composition —
regenerate the file offline with the console command above.

Used by the admin UI's *Copy AI prompt* button in `AddSectionModal`.

## Related files

| File                                                                   | Purpose                                                |
|------------------------------------------------------------------------|--------------------------------------------------------|
| `src/Repository/StyleRepository.php`                                   | `findAllStylesWithFields()` — single-query fetch       |
| `src/Service/CMS/Admin/StyleSchemaService.php`                         | Cached accessor + default-values helpers               |
| `src/Controller/Api/V1/Admin/AdminStyleController.php`                 | Hosts the two endpoints                                |
| `src/Command/BuildPromptTemplateCommand.php`                           | The generator console command                          |
| `config/schemas/api/v1/responses/style/stylesSchema.json`              | Response JSON schema                                   |
| `migrations/Version20260424120000.php`                                 | Registers the two new `api_routes` rows                |
| `../sh-selfhelp_frontend/docs/AI Prompts/prompt_template_base.md`      | Hand-maintained base                                   |
| `../sh-selfhelp_frontend/docs/AI Prompts/ai_section_generation_prompt.md` | Generated, committed template                       |
| `../sh-selfhelp_frontend/scripts/gen-styles-types.mjs`                 | FE codegen script (consumes the schema endpoint)       |
