# Style field naming rules and lifecycle

Audience: Developers and maintainers (backend, `@selfhelp/shared`, frontend, mobile).
Status: active (architecture contract — drives the planned field refactor).
Applies to: SelfHelp2 CMS style catalog (`styles` / `fields` / `rel_fields_styles`), `@selfhelp/shared` style types + semantic mapper, the web (Mantine) renderer, and the mobile (HeroUI Native / React Native) renderer.
Last verified: 2026-06-22.
Source of truth: the live DB catalog (`GET /cms-api/v1/admin/styles/schema`), `StyleRepository::deriveFieldScope()`, `@selfhelp/shared` (`theme/semantic.ts`, `registry/styles.registry.ts`, `types/styles/*`), the frontend `BasicStyle` dispatcher, and the mobile `BasicStyle` + `mobileStyleProps`.

> This is the **field naming contract** for the style system. It is the rulebook
> the audit ([`style-field-audit.md`](./style-field-audit.md)) checks against and
> the refactor ([`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md))
> moves the catalog toward. Read [`_conventions.md`](./_conventions.md) for the
> day-to-day field reference; this page is the *why* and the *rules*.

## 1. The layered architecture (the important part)

Do **not** map Mantine props directly to HeroUI Native props. The CMS owns its own
**semantic style contract**; each renderer is an *adapter* off that contract:

```
DB style + fields                 ← source of truth (this repo)
  → shared semantic style contract  (@selfhelp/shared: scope + unprefixed common fields + mapper)
      → Web renderer adapter         (Mantine)
      → Mobile renderer adapter      (HeroUI Native / React Native)
```

Consequences of this layering:

- A field exists because **SelfHelp** needs the concept, never because one UI
  library happens to expose a prop. There are no `mantine_*` or `heroui_*`
  fields (the legacy `mantine_*` names were migrated away in
  `Version20260618143216`).
- When a property is portable, it lives once as a single **unprefixed**
  `common`-scope field and the `@selfhelp/shared` mapper (`theme/semantic.ts`)
  translates it to each platform. Replacing Mantine or HeroUI Native later is an
  adapter change, not a DB change.
- Genuinely platform-specific presentation lives under `web_*` / `mobile_*` and is
  read only by that platform's renderer.

## 2. Naming taxonomy

Field scope is **two independent dimensions** collapsed into one value:

1. **Translatability** — the DB `display` flag (`1` = translatable content, `0` =
   single-value property).
2. **Platform** — the field-name prefix.

The backend derives one `scope` from both in a single helper
(`StyleRepository::deriveFieldScope($name, $display)`); the frontend consumes the
emitted `scope` and must never re-derive it from the name or `display`.

| Category | Naming | `display` | `scope` | Examples | Read by |
|----------|--------|-----------|---------|----------|---------|
| Translatable content | unprefixed | `1` | `content` | `label`, `title`, `content`, `placeholder`, `alert_*` copy | both platforms |
| Cross-platform behaviour, data **and** presentation | unprefixed | `0` | `common` | `value`, `is_required`, `url`, `name`, `size`, `radius`, `color`, `spacing`, `gap`, `variant` | both platforms |
| Web-only presentation | `web_` | `0` | `web` | `web_variant`, `web_shadow`, `web_size` | web only |
| Mobile-only presentation | `mobile_` | `0` | `mobile` | `mobile_variant`, `mobile_keyboard_type`, `mobile_haptic` | mobile only |

**No prefix = both platforms.** Cross-platform *presentation* (`size`, `radius`,
`color`, `spacing`, …) and cross-platform *behaviour/data* (`value`, `name`, …)
share the single `common` scope. The redundant `shared_` prefix was dropped from
47 fields by migration `Version20260622165615`. Three fields keep the prefix as
**reserved-name exceptions** — `shared_height`, `shared_width`, `shared_icon` —
because the bare names already exist as page-type fields (`common`-scope still).

Derivation (the backend's single source of truth):

```text
display === 1               -> content   (translatable copy, any prefix)
display === 0 & web_*        -> web
display === 0 & mobile_*     -> mobile
display === 0 & (unprefixed) -> common    (behaviour, data, AND cross-platform presentation)
```

### 2.1 Rules

- **Content fields are shared by default.** Translatable copy (`display = 1`) is
  read by both platforms, so it stays **unprefixed** (`content`, `title`,
  `label`). Do *not* prefix copy with `web_`/`mobile_`. (Today `alert.web_alert_title`
  violates this — see the audit.) Only add a `mobile_*` copy field when mobile
  genuinely needs *different wording*, which should be rare.
- **A property is unprefixed (cross-platform) only when it has a precise, tested
  mapping on both platforms.** Promotion from `web_*` to unprefixed requires a
  mapper entry in `theme/semantic.ts` plus tests on both renderers. Do not
  bulk-promote.
- **A concept must exist under exactly one field name.** Do not duplicate a field
  across two names (`alert.value` + `alert.content` violates this — see the audit).
- **No library-named fields.** Never `mantine_radius`, `heroui_variant`,
  `web_mantine_color`. Use the unprefixed `radius` / `color` and let the adapter
  decide.
- **Renderer precedence is fixed:** current-platform `web_*`/`mobile_*` override →
  unprefixed cross-platform semantic → component default. The mobile renderer must
  never fall back to `web_*`; the web renderer must never fall back to `mobile_*`.
- **Field type is the editor control, not the platform.** Types are
  renderer-neutral (`spacing`, `select`, `color-picker`); they were de-Mantine-d
  in `Version20260618143216` (`mantine_spacing_margin_padding` → `spacing`).
- **Colour is unprefixed, not `web_`.** Authors set `color` once and it applies on
  both platforms. A bare `web_color` survives only as a web-only escape hatch for
  an exact Mantine palette value with no semantic meaning.
- **No prefix means both platforms.** Never reintroduce a `shared_` prefix; an
  unprefixed property field already applies to both. The only `shared_*` names
  that remain (`shared_height`, `shared_width`, `shared_icon`) are reserved-name
  exceptions kept to avoid colliding with page-type fields.
- **`use_web_style` is retired (RF-01).** Web always renders the Mantine
  component, so the toggle is removed catalog-wide rather than kept as dead
  `common` data.

## 3. The cross-platform semantic scales

These unprefixed `common`-scope values are the **true cross-platform common
denominator**. HeroUI Native has no `xs`/`xl`, so the scales are intentionally
narrower than the Mantine `web_*` scales and map 1:1 with no clamping
(`@selfhelp/shared` `theme/semantic.ts`). They were unprefixed from `shared_*` in
`Version20260622165615`:

| Field | Allowed values | Web (Mantine) | Mobile (HeroUI Native / RN) |
|-------|----------------|---------------|------------------------------|
| `size` | `sm` `md` `lg` | size token (subset of `xs..xl`) | HeroUI size `sm/md/lg` |
| `radius` | `none` `sm` `md` `lg` `full` | token / number (`full` → pill px) | px (`none`→0, `full`→9999) |
| `color` | `neutral` `primary` `secondary` `success` `warning` `danger` | Mantine `color` name | HeroUI Native / theme color |
| `variant` | `default` `filled` `light` `outline` `subtle` `transparent` | Mantine `variant` | HeroUI variant |
| `spacing` | box-model JSON object | margin+padding props | px padding/margin via theme |
| `gap` `align` `justify` `direction` `wrap` `orientation` `text_align` `full_width` | canonical layout enums | Mantine layout props | RN flexbox |

> `intent` (`neutral`/`primary`/…) is a *legacy* semantic the mapper still reads
> as a fallback but is **not** in the live catalog; real sections drive
> appearance through `color` / `variant`.

Full mapping tables are in [`style-mobile-mapping.md`](./style-mobile-mapping.md).

## 4. Field lifecycle status

Documenting *what exists* is not enough — every field also gets a **lifecycle
status** so the catalog can be cleaned, not just described. Statuses are an audit
classification (they are not stored in the DB; they live in the audit + this doc):

| Status | Meaning | Action |
|--------|---------|--------|
| `active` | Used by ≥1 renderer; correctly scoped. | Keep. |
| `web-only` | Correctly `web_`, read only by web. | Keep. |
| `mobile-only` | Correctly `mobile_`, read only by mobile. | Keep (none exist yet). |
| `common` | Unprefixed behaviour, data, **and** cross-platform presentation (the old `shared` status folded in here when `shared_*` was unprefixed in `Version20260622165615`). | Keep. |
| `legacy` | Old shape kept only for migration/compat. | Migrate, then retire. |
| `duplicate` | Two fields carry the same concept. | Merge to one; drop the other. |
| `deprecated` | Superseded; scheduled for removal. | Stop reading; remove after migration. |
| `unused` | In the catalog but read by no renderer and no content references it. | Remove via migration after a zero-reference check. |
| `typo` | Misspelled field name. | Rename via migration; migrate stored values by `id_fields`. |
| `candidate-for-removal` | Needs a product decision. | Decide, then act. |

The audit assigns these per field. Examples already found:

- `alert.value` — `duplicate` of `alert.content`.
- `alert.web_alert_with_close_button` — `duplicate` of `alert.web_with_close_button`.
- `alert.web_alert_title` — mis-scoped: translatable copy under a `web_` prefix
  (should be unprefixed/content).
- `datepicker.web_datepicker_allow_deseselect` — `typo` (`deseselect` → `deselect`).

## 5. Per-field documentation template

Every field documented in this reference should be describable by this template
(the [audit](./style-field-audit.md) keeps the machine-readable version in
`style-field-audit.generated.json`):

```text
field:          radius
db column/key:  fields.name = 'radius' (display 0), rel_fields_styles default
type (editor):  slider  (options none|sm|md|lg|full)
scope:          common   (unprefixed = both platforms)
translatable:   no
used by web:    yes  → Mantine `radius` (token / px for pill)
used by mobile: yes  → HeroUI Native radius px (none→0, full→9999)
fallback:       component default when unset
status:         common
notes:          unprefixed from radius by Version20260622165615
```

For a content field the same template reads, e.g. for `alert.content`:

```text
field:          content
type (editor):  textarea
scope:          content   (display 1, translatable)
used by web:    yes  → Mantine Alert children/body
used by mobile: yes  → HeroUI Native Alert.Description
status:         active
notes:          the canonical message field; alert.value duplicates it (remove value)
```

## 6. Filenames in this folder

These architecture docs were requested as `STYLE_*` files; they are named in the
folder's established lowercase-kebab convention (matching `_conventions.md`,
`style-schema-endpoint.md`). Mapping:

| Requested name | File |
|----------------|------|
| `STYLE_FIELD_NAMING_RULES.md` | this page |
| `STYLE_PLATFORM_MATRIX.md` | [`style-platform-matrix.md`](./style-platform-matrix.md) |
| `STYLE_MOBILE_MAPPING.md` | [`style-mobile-mapping.md`](./style-mobile-mapping.md) |
| `STYLE_FIELD_AUDIT.md` | [`style-field-audit.md`](./style-field-audit.md) |
| `STYLE_REFACTORING_RECOMMENDATIONS.md` | [`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md) |
| `style-field-audit.generated.json` | [`style-field-audit.generated.json`](./style-field-audit.generated.json) |

## Related references

- [`_conventions.md`](./_conventions.md) — shared field reference (the *what*).
- [`style-platform-matrix.md`](./style-platform-matrix.md) — per-style render target + renderer mapping.
- [`style-mobile-mapping.md`](./style-mobile-mapping.md) — semantic contract → Mantine + HeroUI Native.
- [`style-field-audit.md`](./style-field-audit.md) — the deep DB-vs-code audit.
- [`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md) — proposed cleanups.
- `mobile.md` (repo root) — the full mobile rendering implementation plan.
