# `<style-name>` style

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (<repos that render/seed this style>).
Last verified: <YYYY-MM-DD>.
Source of truth: `styles` / `fields` / `rel_fields_styles` rows in the seed migrations, the `@selfhelp/shared` type, and the renderer component(s).

> Copy this file to `docs/reference/styles/<category>/<style-name>.md`, fill in
> every section, and link it from `docs/reference/styles/index.md` (replace the
> "catalog only" cell). Remove these quote blocks when done.

## Summary

One or two sentences: what the style renders and when an author would use it.

- **Category:** auth | layout | typography | media | interactive | forms | composite
- **Can have children:** yes | no (must match `styles.can_have_children` and the registry entry)
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/<Component>.tsx` (+ mobile component if applicable)
- **Shared type:** `sh-selfhelp_shared/src/types/styles/<group>.ts` (`I<Name>Style`)
- **Backend service(s):** the service that produces the data/behaviour, if any (e.g. `App\Service\Auth\...`).

## Fields

List **every** field the style owns. Mark `display`: `1` = translatable content, `0` = internal config. Pin defaults only where this page is the source for them; otherwise point to the live schema.

| Field | Type | `display` | Default | Purpose |
|-------|------|-----------|---------|---------|
| `field_name` | text | 1 | `…` | … |

## Behaviour

- Modes / conditional rendering (e.g. flags that hide fields or switch flows).
- Validation and submission contract (request shape, endpoint, schema).
- Empty/loading/error/success states the author should know about.
- Anything the backend ignores or overrides (source-of-truth notes).

## Frontend rendering

How the component maps fields to UI, default fallbacks, and any `{placeholder}` interpolation it performs.

## Related files

| File | Purpose |
|------|---------|
| `migrations/Version….php` | seeds the style / fields / translations |
| `config/schemas/api/v1/…` | request/response schema, if the style calls an API |
| `tests/…` | behaviour coverage |

## Change history

- `YYYY-MM-DD` — what changed and the migration/PR that did it.
