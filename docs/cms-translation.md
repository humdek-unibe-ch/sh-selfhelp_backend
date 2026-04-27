# CMS Translation System

## Core Translation Principles

1. **Default language as source**: All initial content is saved in the default language
2. **Centralized translation interface**: Translators can work efficiently with all content in one place
3. **Automatic fallback mechanism**: Ensures content is always available even when translations are incomplete
4. **Database-driven translations**: Dynamic content is stored in translation tables with language associations

### Translation Interface Features

- Group translations by content type (pages, sections)
- Show side-by-side translation editing with the default language as reference
- Add visual indicators for missing translations

### Batch Translation Operations

- Export/import translations (CSV/XLSX)
- Batch translation status updates

### Translation Caching

- Caching layer for translations to improve performance
- Symfony's cache component with tags for efficient invalidation

## Language Fallback Behavior

When the API resolves translations for a requested `language_id`, the result is
merged **field-by-field** with the CMS default language translations. The
default language acts as a safety net so the frontend never renders a
half-translated page.

The merge happens in two repositories:

- `App\Repository\SectionsFieldsTranslationRepository::fetchTranslationsForSectionsWithFallback()`
  for `sections_fields_translation` rows.
- `App\Repository\PagesFieldsTranslationRepository::fetchTitleTranslationsWithFallback()`
  for the page-level `title` / `description` (display=1) fields.

### Merge Algorithm

For each entity (section or page):

1. Seed the result with the default-language translations.
2. For every field present in the requested-language translations:
   - If the requested-language `content` is **user-visibly non-empty**, it
     overrides the default value.
   - Otherwise, the default-language value is kept.

### What Counts as "User-Visibly Empty"

The decision is centralised in `App\Util\TranslationContentHelper::isEffectivelyEmpty()`.
A naive `trim()` check is **not** sufficient because the rich-text editor
persists cleared fields as HTML wrappers, not as empty strings.

The helper treats the following as empty (-> fallback fires):

- `null`
- `""`
- whitespace-only strings (spaces, tabs, newlines, `&nbsp;`, `\xC2\xA0`)
- HTML wrappers with no inner text and no media tags, e.g.
  - `<p></p>`
  - `<p><br></p>`
  - `<p class="single-line-paragraph"></p>`
  - `<div>&nbsp;</div>`

The helper treats the following as **non-empty** (-> requested language wins):

- any string with text after stripping tags + decoding entities
- HTML containing media tags: `<img>`, `<video>`, `<audio>`, `<iframe>`,
  `<embed>`, `<object>`, `<source>`, `<svg>`, `<canvas>`, `<picture>`,
  `<track>` (an image-only translation is still a real translation)

### Example

Page `dyn` has a button with the `label` field translated as:

| Language       | Stored content                                         |
| -------------- | ------------------------------------------------------ |
| German (id=2)  | `<p class="single-line-paragraph">test</p>`            |
| English (id=3) | `<p class="single-line-paragraph"></p>` (cleared by user) |

With the CMS default language set to German, requesting
`?language_id=3` now returns `label.content = "<p class="single-line-paragraph">test</p>"`
because the English content is "user-visibly empty" and the merge falls back
to German. Before this rule was introduced the empty English wrapper would
have won and the button would render with no label.

### Caching Note

Page and section payloads are cached per-language (cache keys include
`language_id`). After upgrading or changing the fallback rules you may need
to invalidate the `pages` and `sections` cache categories so already-cached
payloads are recomputed.
