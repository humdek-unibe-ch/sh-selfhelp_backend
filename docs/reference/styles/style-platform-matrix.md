# Style platform matrix

Audience: Developers and maintainers (backend, shared, frontend, mobile).
Status: active (architecture reference).
Applies to: the 90 core CMS styles (no plugin styles).
Last verified: 2026-06-23.
Source of truth: live DB catalog (`admin/styles/schema`), `@selfhelp/shared` `BASE_STYLE_REGISTRY` (web Mantine targets), the installed `heroui-native@1.0.2` export list (mobile targets), the frontend `BasicStyle` + mobile `styleImpls` dispatch maps. The machine-readable form is [`style-field-audit.generated.json`](./style-field-audit.generated.json).

> One row per style: where it renders, what it maps to on each platform, and how
> well the concept survives the trip to mobile. This is the "platform marking"
> layer requested for the mobile refactor. Read
> [`style-field-naming-rules.md`](./style-field-naming-rules.md) for the field
> contract and [`style-mobile-mapping.md`](./style-mobile-mapping.md) for the
> deep per-renderer mapping.

## Three separate platform concepts (do not conflate)

| Concept | Values | Owner | Purpose |
|---------|--------|-------|---------|
| Request **client** | `web` `mobile` | request/API context (`VariableResolverService::getPlatform`) | which renderer is running |
| Page **access target** | `web` `mobile` `mobile_and_web` | `pages.id_page_access_types` (`pageAccessTypes` lookup) | where a *page* may load |
| Style **render target** | `web` `mobile` `both` | `styles.id_render_target` (`styleRenderTargets` lookup; `NULL`→`both`) | where a *style* is intentionally renderable |

There is deliberately **no** `pages.id_platform`; page targeting stays on
`pageAccessTypes`. A client silently omits styles whose render target excludes it
— that is not an error and must not show a placeholder.

## Current state

- **All 90 styles are `render_target = both`** (backfilled by
  `Version20260618143215`; no style has been individually targeted yet). **No
  current core style is intentionally web-only or mobile-only** — in particular
  the auth-flow styles (`login`, `register`, `reset-password`, `two-factor-auth`,
  `validate`, `profile`) render on **both** platforms and must not be marked
  web-only.
- **Catalog parity is perfect**: every style exists in the DB, the shared
  registry, the web renderer, and the mobile renderer (90 / 90 / 90 / 90).
- So the open work is *field-level* (scope, drift, `mobile_*`, spacing
  consolidation) and *render-target assignment*, not missing renderers.

## Legend — "mobile fit"

| Code | Meaning |
|------|---------|
| `1:1` | A HeroUI Native component (or direct RN primitive) matches the concept closely. |
| `adapted` | Renderable on mobile, but the layout/interaction differs (CSS→RN flexbox, table→cards, etc.). Needs a deliberate mobile design. |
| `structural` | No visible UI of its own; controls the section tree / data scope. Same behaviour on both platforms. |
| `web-first` | Concept is web-centric; mobile gets a reduced or read-only equivalent. Candidate for a documented mobile fallback. |

## auth / system

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `login` | no | custom form (TextInput/Button) | RN form (HeroUI `text-field`+`button`) | adapted | both |
| `register` | no | custom form | RN form | adapted | both |
| `validate` | yes | custom multi-step | RN multi-step | adapted | both |
| `reset-password` | no | custom form | RN form | adapted | both |
| `two-factor-auth` | no | custom OTP form | RN form (HeroUI `input-otp` internally) | adapted | both |
| `profile` | no | custom screens (64 fields) | RN screens | adapted | both — biggest content surface |
| `no-access` | no | Paper + Button surface | HeroUI `surface`+`button` | adapted | both |
| `missing` | no | Paper + Button surface | HeroUI `surface`+`button` | adapted | both |
| `not-found` | no | Paper + Button surface | HeroUI `surface`+`button` | adapted | both |
| `version` | no | diagnostic text (0 fields) | RN Text | web-first | **decide: web only or remove** if unreferenced (mobile.md §5.3) |

## layout

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `container` | yes | `Container` | RN `View` (max-width) | adapted | both |
| `box` | yes | `Box` | RN `View` | 1:1 | both |
| `flex` | yes | `Flex` | RN `View` flexbox | adapted | both — CSS flex ≠ RN flex (see mapping) |
| `group` | yes | `Group` | RN `View` (row) | adapted | both |
| `stack` | yes | `Stack` | RN `View` (column) | adapted | both |
| `simple-grid` | yes | `SimpleGrid` | RN `View` (flex-wrap) | adapted | both — no CSS grid on RN |
| `grid` | yes | `Grid` (12-col) | RN flex rows/cols | adapted | both — 12-col → flex |
| `grid-column` | yes | `Grid.Col` | RN `View` | adapted | both |
| `space` | no | `Space` | RN `View` spacer | 1:1 | both |
| `divider` | no | `Divider` | HeroUI `separator` | 1:1 | both |
| `paper` | yes | `Paper` | HeroUI `surface` | 1:1 | both |
| `center` | yes | `Center` | RN `View` (center) | 1:1 | both |
| `scroll-area` | yes | `ScrollArea` | RN `ScrollView` (+ HeroUI `scroll-shadow`) | 1:1 | both |
| `card` | yes | `Card` | HeroUI `card` | 1:1 | both |
| `card-segment` | yes | `Card.Section` | HeroUI `card` sub-part / `View` | adapted | both |
| `aspect-ratio` | yes | `AspectRatio` | RN `View` (`aspectRatio`) | 1:1 | both |
| `background-image` | yes | `BackgroundImage` | RN `ImageBackground` | 1:1 | both |
| `ref-container` | yes | renders referenced section | same (no UI) | structural | both |
| `data-container` | yes | data-scope wrapper | same (no UI) | structural | both — verify it has real behaviour both sides (mobile.md §11.2) |

## typography

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `title` | no | `Title` | RN `Text` (heading role) | 1:1 | both |
| `text` | no | `Text` | RN `Text` | 1:1 | both |
| `code` | no | `Code` | RN `Text` (mono) | 1:1 | both |
| `highlight` | no | `Highlight` | RN `Text` (marked) | adapted | both |
| `blockquote` | no | `Blockquote` | RN `View`+`Text` | adapted | both |
| `html-tag` | yes | raw HTML element | RN `View`/`Text` (sanitized subset) | web-first | both — document supported mobile HTML subset |
| `kbd` | no | `Kbd` | RN `Text` (badge) | adapted | both |
| `typography` | yes | `Typography` (prose) | RN `View` | web-first | both — web-centric prose wrapper |
| `fieldset` | yes | `Fieldset` | RN `View` + legend `Text` | adapted | both |
| `spoiler` | yes | `Spoiler` | RN collapsible (accessible) | adapted | both |

## media

| Style | Children | Web → Mantine/HTML | Mobile → Expo/RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `image` | no | `Image` | expo-image | 1:1 | both |
| `video` | no | HTML5 `<video>` | expo-video | 1:1 | both |
| `audio` | no | HTML5 `<audio>` | expo-audio | 1:1 | both |
| `figure` | yes | `<figure>`+caption | RN `View`+`Image`+`Text` | 1:1 | both |
| `carousel` | yes | Embla | reanimated-carousel | adapted | both — gestures must not fight page scroll |

## interactive / feedback

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `button` | no | `Button` | HeroUI `button` | 1:1 | both |
| `link` | no | `<a>` | HeroUI `link-button` / RN `Pressable` | 1:1 | both |
| `action-icon` | no | `ActionIcon` | HeroUI `button` (icon) | adapted | both |
| `alert` | yes | `Alert` | HeroUI `alert` | 1:1 | both — **see audit: duplicate fields** |
| `badge` | no | `Badge` | HeroUI `chip` / `tag-group` | adapted | both |
| `avatar` | no | `Avatar` | HeroUI `avatar` | 1:1 | both |
| `chip` | no | `Chip` | HeroUI `chip` | 1:1 | both |
| `indicator` | yes | `Indicator` | RN `View` overlay dot | adapted | both |
| `theme-icon` | no | `ThemeIcon` | RN `View`+icon | adapted | both |
| `notification` | no | `Notification` | HeroUI `toast` / `alert` | adapted | both |

## forms / user input

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `form-log` | yes | form wrapper | RN form | adapted | both |
| `form-record` | yes | form wrapper | RN form | adapted | both |
| `input` | no | HTML input | HeroUI `text-field` | 1:1 | both |
| `text-input` | no | `TextInput` | HeroUI `text-field` | 1:1 | both |
| `textarea` | no | `Textarea` | HeroUI `text-area` | 1:1 | both |
| `rich-text-editor` | no | Tiptap | read-only viewer (v1) | web-first | both — document the writable subset |
| `select` | no | `Select` | HeroUI `select` | 1:1 | both |
| `radio` | yes | `Radio`/`RadioGroup` | HeroUI `radio-group` | 1:1 | both |
| `checkbox` | no | `Checkbox` | HeroUI `checkbox` | 1:1 | both |
| `slider` | no | `Slider` | HeroUI `slider` | 1:1 | both |
| `range-slider` | no | `RangeSlider` | HeroUI `slider` (range) / RN | adapted | both |
| `datepicker` | no | `DatePicker` | RN date picker (no HeroUI date) | web-first | both — 27 fields, 20 `web_` |
| `switch` | no | `Switch` | HeroUI `switch` | 1:1 | both |
| `combobox` | no | `Combobox` | HeroUI `select` / `search-field` | adapted | both |
| `color-input` | no | `ColorInput` | RN custom | web-first | both — web-first |
| `color-picker` | no | `ColorPicker` | RN custom | web-first | both — web-first |
| `file-input` | no | `FileInput` | expo-document-picker | adapted | both — validate size/ext before upload |
| `number-input` | no | `NumberInput` | HeroUI `text-field` (numeric keyboard) | adapted | both |
| `segmented-control` | no | `SegmentedControl` | HeroUI `tabs` / RN segmented | adapted | both |
| `rating` | no | `Rating` | RN custom stars | adapted | both |
| `progress` | no | `Progress` | RN `View` bar (no HeroUI progress) | adapted | both |
| `progress-root` | yes | `Progress.Root` | RN `View` | adapted | both |
| `progress-section` | no | `Progress.Section` | RN `View` | adapted | both |
| `show-user-input` | no | data table | RN list/cards (not a table) | web-first | both — mobile must not copy the desktop table |

## composite / collection / data

| Style | Children | Web → Mantine | Mobile → HeroUI Native / RN | Fit | Render-target note |
|-------|:--:|---------------|------------------------------|:--:|--------------------|
| `accordion` | yes | `Accordion` | HeroUI `accordion` | 1:1 | both |
| `accordion-item` | yes | `Accordion.Item` | HeroUI `accordion` item | 1:1 | both — child-only placement |
| `tabs` | yes | `Tabs` | HeroUI `tabs` | 1:1 | both |
| `tab` | yes | `Tabs.Tab`+`Panel` | HeroUI `tabs` item | 1:1 | both — child-only placement |
| `timeline` | yes | `Timeline` | RN custom (no HeroUI timeline) | adapted | both |
| `timeline-item` | yes | `Timeline.Item` | RN custom | adapted | both — child-only placement |
| `list` | yes | `List` | HeroUI `list-group` / RN | adapted | both |
| `list-item` | yes | `List.Item` | HeroUI `list-group` item / RN | adapted | both |
| `entry-list` | yes | data-driven list | RN list | structural/adapted | both — preserve data-access scope |
| `entry-record` | yes | single-record container | RN `View` | structural | both |
| `entry-record-delete` | no | inline delete confirm | HeroUI `dialog`+`button` | adapted | both — preserve delete permission |
| `loop` | yes | repeater over rows | same (no UI) | structural | both |

## How to read the "render-target note"

Every style is currently `both`. The notes flag where a deliberate
**non-`both`** decision (or a documented mobile fallback) is worth considering:

- `version` is the one concrete candidate for **`web`** (or removal) — it is a
  build/diagnostic surface with 0 author fields.
- The `web-first` styles (`html-tag`, `typography`, `rich-text-editor`,
  `color-picker`, `color-input`, `show-user-input`) stay `both` but need an
  explicit, documented **mobile fallback** so they are not silently broken.
- The `structural` styles (`ref-container`, `data-container`, `loop`,
  `entry-record`) carry behaviour, not UI; keep `both` and verify the behaviour
  exists on both platforms.

Reassigning a render target is a small, intentional migration that writes
`styles.id_render_target` (see `Version20260618143215` for the mechanism). It
should never be used to paper over an unfinished renderer.

## HeroUI Native Pro overrides

The "mobile mapping" column above is the **OSS** target. Where a HeroUI Native
**Pro** component exists, the mobile Pro build renders the same CMS style with the
richer Pro component while OSS keeps the fallback — no catalog or field change.
Pro-backed styles today: `badge`, `datepicker`, `rating`, `progress*`,
`number-input`, `segmented-control`, `timeline*`, `radio`, plus future
chart/widget/split-view styles. Full catalog + per-style table:
[`style-mobile-mapping.md`](./style-mobile-mapping.md) §8 (RF-25…RF-34).

## Related references

- [`style-field-naming-rules.md`](./style-field-naming-rules.md) — field contract + lifecycle.
- [`style-mobile-mapping.md`](./style-mobile-mapping.md) — deep semantic → Mantine + HeroUI Native mapping.
- [`style-field-audit.md`](./style-field-audit.md) — DB-vs-code field audit.
- [`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md) — proposed changes.
