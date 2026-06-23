# Style mobile mapping (semantic → Mantine + HeroUI Native)

Audience: Developers (shared package, web renderer, mobile renderer).
Status: active (architecture reference; mobile renderer adoption in progress).
Applies to: the shared semantic contract and its two renderer adapters.
Last verified: 2026-06-23.
Source of truth: `@selfhelp/shared` `theme/semantic.ts` (mapper), `BASE_STYLE_REGISTRY` (web Mantine targets), the installed `heroui-native@1.0.2` export list, the mobile `mobileStyleProps` + per-style renderers, and `mobile.md` (repo root).

> How a SelfHelp style and its fields turn into pixels on each platform. The
> golden rule: **the CMS contract maps to each renderer; Mantine and HeroUI Native
> never map to each other.** Read
> [`style-field-naming-rules.md`](./style-field-naming-rules.md) first.

## 1. Adapter architecture

```
section.fields (DB)
   │  resolveSharedStyleProps(fields)         // reads unprefixed portable fields
   ▼
ISharedStyleProps  { size, radius, color, variant, spacing, states, fullWidth }
   ├── toMantineSemanticProps(props)  ──► Mantine props      (web renderer)
   └── toHeroUiSemanticProps(props)   ──► HeroUI Native props (mobile renderer)
                                       └─ toReactNativeSemanticStyle(props, theme)  // styles with no HeroUI component
```

- **Web** reads unprefixed portable fields + `web_*` (never `mobile_*`).
- **Mobile** reads unprefixed portable fields + `mobile_*` (never `web_*`).
- Precedence on each side: platform override (`web_*`/`mobile_*`) → unprefixed
  portable semantic → component default.
- The mapper does **no clamping** and has **no implicit cross-platform fallback**
  (mobile.md §8.2). Today there are **0 `mobile_*` fields** in the DB, so mobile
  currently runs purely off the unprefixed portable + content/behaviour fields.

## 2. Semantic mapper tables (verbatim from `theme/semantic.ts`)

### size — `size` (`sm | md | lg`)

| shared | Mantine `size` | HeroUI Native `size` |
|--------|----------------|----------------------|
| `sm` | `sm` | `sm` |
| `md` | `md` | `md` |
| `lg` | `lg` | `lg` |

`web_size` keeps the full Mantine `xs..xl`; it is web-only and not mapped to mobile.

### radius — `radius` (`none | sm | md | lg | full`)

| shared | Mantine `radius` | HeroUI Native (px) |
|--------|------------------|--------------------|
| `none` | `0` | `0` |
| `sm` | `'sm'` | `RADIUS_PX.sm` |
| `md` | `'md'` | `RADIUS_PX.md` |
| `lg` | `'lg'` | `RADIUS_PX.lg` |
| `full` | `9999` (`FULL_RADIUS_PX`) | `9999` (pill) |

### colour — `color` (`neutral | primary | secondary | success | warning | danger`)

Colour is settable on **both** platforms (the login button colour applies on
mobile too) through the single unprefixed `color` field. The adapter resolves it
to a Mantine palette name (web) and a HeroUI Native / theme colour (mobile):

| `color` | Web (Mantine `color`) | Mobile (HeroUI Native / theme) |
|----------------|-----------------------|--------------------------------|
| `primary` | `blue` | accent / primary |
| `secondary` | `gray` | default |
| `success` | `green` | success |
| `warning` | `yellow` | warning |
| `danger` | `red` | danger |
| `neutral` | `gray` / `dark` | foreground / default |

`color` is the single portable colour field read by both platforms; there is no
`web_color` field (it was unprefixed to `color`). The only `web_color_*` fields
that remain are the colour-picker widget config (`web_color_format`,
`web_color_input_*`), which configure the web widget, not a semantic colour.

### variant — `variant` (`default | filled | light | outline | subtle | transparent`)

The unprefixed `variant` field maps straight through: Mantine consumes it as its
`variant` prop (web); HeroUI Native resolves it to the nearest HeroUI variant
(mobile). There is no `intent` field — appearance is driven by `color` + `variant`.

### spacing — `spacing` (box-model token object)

`{"mt","mb","ms","me","pt","pb","ps","pe"}` of spacing tokens (`none|xs|sm|md|lg|xl`).
Web maps each side to Mantine margin/padding props; mobile resolves each to px
(`none → 0`) via the theme scale.

### states — unprefixed booleans → mapper

`disabled`, `loading`, `invalid`, `required` are read as unprefixed behaviour
fields and become `disabled/loading/error/required` (Mantine) /
`isDisabled/isLoading/isInvalid/isRequired` (HeroUI Native).

## 3. HeroUI Native 1.0.2 — installed component catalog

These are the components actually exported by the pinned `heroui-native` package
(the authoritative mobile catalog — not every web/Mantine component has a peer):

`accordion`, `alert`, `avatar`, `bottom-sheet`, `button`, `card`, `checkbox`,
`chip`, `close-button`, `control-field`, `description`, `dialog`, `field-error`,
`input`, `input-group`, `input-otp`, `label`, `link-button`, `list-group`,
`menu`, `popover`, `pressable-feedback`, `radio`, `radio-group`, `scroll-shadow`,
`search-field`, `select`, `separator`, `skeleton`, `skeleton-group`, `slider`,
`spinner`, `sub-menu`, `surface`, `switch`, `tabs`, `tag-group`, `text-area`,
`text-field`, `toast`.

**Used to render CMS styles today / milestone one** (mobile.md §8.3): `button`,
`alert`, `avatar`, `card`, `chip`/`tag-group`, `separator`, `surface`,
`text-field`, `text-area`, `checkbox`, `switch`, `radio-group`, `select`,
`slider`, `accordion`, `tabs`, `list-group`, `scroll-shadow`.

**HeroUI Native has NO peer** for these CMS concepts → React Native / Expo:
date picker (`datepicker`), color picker (`color-input`/`color-picker`),
progress bar (`progress*`), timeline (`timeline*`), rating (`rating`), rich-text
editing (`rich-text-editor`, read-only on mobile v1), carousel
(reanimated-carousel), media (`image`/`video`/`audio` via Expo).

**Exported but intentionally NOT a CMS style** (used internally only, mobile.md
§4.6/§10): `bottom-sheet`, `dialog`, `popover`, `menu`, `sub-menu`, `skeleton`,
`skeleton-group`, `spinner`, `toast`, `input-otp`, `search-field`. They power
app workflows (e.g. OTP inside `two-factor-auth`, dialog inside
`entry-record-delete`) without being author-selectable styles.

## 4. Mobile UI capability contract

The mobile renderer talks to UI primitives only through the shared
`IMobileUiAdapters` contract (`@selfhelp/shared`), so the public OSS build and the
private Pro build cannot drift. OSS adapters in place today
(`components/ui/adapters/oss`): `MobileButton`, `MobileText`, `MobileContainer`,
`MobileCard`, `MobileInput`, `MobileTextarea`, `MobileSwitch`, `MobileCheckbox`,
`MobileSelect`, `MobileModal`. The target capability set (mobile.md §8.3) adds
`ActionIcon`, `Surface`, `Alert`, `Avatar`, `BadgeOrChip`, `Divider`,
`RadioGroup`, `Slider`, `RangeSlider`, `DatePicker`, `FilePicker`, `Rating`,
`Accordion`, `Tabs`, `RichTextInput`.

`effective adapter = OSS adapter + available Pro overrides` — a missing Pro
override falls back to the OSS implementation, never to web.

## 5. Worked example — `alert` (field by field)

The user-flagged canonical case. Mantine `Alert` (web) vs HeroUI Native `Alert`
(mobile). DB fields (live), how each is loaded:

| Field | scope | Web (Mantine `Alert`) | Mobile (HeroUI Native `Alert`) | Status |
|-------|-------|------------------------|--------------------------------|--------|
| `content` | content | body / `children` | `Alert.Description` | **active** (canonical message) |
| `web_alert_title` | web | `title` prop | read as legacy `titleLegacy` fallback for `Alert.Title` | mis-scoped — title is copy; should be unprefixed `alert_title` |
| `radius` | common | `radius` token | radius px | active |
| `size` | common | `size` | HeroUI size | active |
| `spacing` | common | margin+padding | px box model | active |
| `color` | common | `color` | `color` (via mapper) | active (unprefixed semantic colour, both platforms) |
| `variant` | common | `variant` | `variant` (via mapper) | active (unprefixed, both platforms) |
| `web_left_icon` | web | `icon` | — (HeroUI `Alert.Indicator`) | web-only |
| `web_with_close_button` | web | `withCloseButton` | — | web-only |
| `web_alert_with_close_button` | web | (unused twin) | — | **duplicate** of `web_with_close_button` |
| `value` | common | (unused) | (unused) | **duplicate** of `content` |
| `use_web_style` | common | toggle Mantine vs raw | — | active |

Key facts proven from the code:

- The mobile renderer (`components/styles/interactive/Alert.tsx`) loads the
  message from **`content`** (and a title from `alert_title || web_alert_title`).
  There is **no `mobile_message` field** — mobile already reuses the shared
  `content`. The illustrative "remove `mobile_message`" cleanup applies in spirit
  to the real duplicate `value`.
- The mobile renderer reading `web_alert_title` is a **web→mobile leak** that
  violates the precedence rule (mobile.md §6.3); the fix is to make the title an
  unprefixed `alert_title` content field that both platforms read.
- The status color on mobile comes from the unprefixed `color` field via the
  mapper (there is no `web_color` field; it was unprefixed to `color`).

Target shape for `alert` (see [refactoring](./style-refactoring-recommendations.md)):
`content`, `alert_title` (unprefixed copy), `color`, `variant`, `radius`,
`size`, `spacing`, `closable` (unprefixed bool), `web_left_icon`,
`use_web_style`. Removed: `value`, `web_alert_with_close_button`, and the
`web_` prefix on the title.

## 6. Layout mapping caveats (CSS web ≠ RN flexbox)

Many layout styles look 1:1 but behave differently — document the mobile mapping
explicitly rather than assuming parity:

| Concept | Web (Mantine/CSS) | Mobile (React Native) | Caveat |
|---------|-------------------|------------------------|--------|
| `flex`/`group`/`stack` | CSS flexbox (default `row`, wrapping, `gap`) | RN flexbox (default `column`, no wrap, `gap` supported) | RN's default axis and wrap differ; `direction`/`wrap` must be explicit |
| `grid`/`simple-grid` | CSS grid | RN has **no** grid; emulate with flex-wrap + width % | column counts → flex basis; responsive breakpoints differ |
| `container` | max-width centring | RN `View` with `maxWidth` + `alignSelf:center` | viewport-relative widths need care |
| `scroll-area` | `ScrollArea` overlay scrollbars | RN `ScrollView` (+ HeroUI `scroll-shadow`) | nested scroll/gesture conflicts |
| `aspect-ratio` | `aspect-ratio` CSS | RN `aspectRatio` style | close |
| `space` | fixed gap element | RN `View` with height/width | close |

Layout styles therefore map to **adapted** RN wrappers, not HeroUI components.
This is why "tons of layout components that make sense on web" still render on
mobile — but as semantic flex wrappers, and authors should lean on
`gap`/`align`/`justify`/`direction` (which map cleanly)
rather than web-only CSS.

## 7. Per-style mobile target (summary)

The full per-field detail is in
[`style-field-audit.generated.json`](./style-field-audit.generated.json); the web
Mantine target and mobile HeroUI Native / RN target per style are tabulated in
[`style-platform-matrix.md`](./style-platform-matrix.md). The mapping rules above
are sufficient to implement any style:

1. Find the style's web Mantine component (matrix column 3).
2. If a HeroUI Native peer exists (§3), use it; else use the RN/Expo primitive (§3).
3. Read `shared_*` through the mapper (§2); read `mobile_*` overrides if any
   (none today); never read `web_*`.
4. Map content/behaviour fields (unprefixed) the same on both platforms.
5. For layout, use the RN flexbox caveats (§6).

## 8. HeroUI Native **Pro** components

HeroUI Native ships an OSS set (§3) **and** a licensed **Pro** set. The OSS set has
no date picker, rating, progress, number stepper, segmented control, charts, etc.,
so the OSS build renders those CMS styles with **adapted RN fallbacks**. The Pro
build (the private `sh-selfhelp_mobile_pro_ui` repo) swaps in the real Pro
component for the **same CMS fields** — richer native UX for licensed installs.

Contract (unchanged from §4): `effective adapter = OSS adapter + Pro override`. A
missing Pro override falls back to OSS, **never** to web. The CMS catalog and the
shared semantic contract do not change between builds — only the adapter
implementation behind `IMobileUiAdapters` does. So **always build the OSS adapter
first**, then register the Pro override where it exists.

### Pro catalog (provided)

- **Buttons:** `ProgressButton`, `SlideButton`, `SocialAuthButton`, `ToggleButton`,
  `ToggleButtonGroup`
- **Charts:** `AreaChart`, `BarChart`, `ChartCrosshair`, `ChartIndicator`,
  `ChartTooltip`, `ComposedChart`, `LineChart`, `PieChart`, `RadarChart`,
  `RadialChart`
- **Data display:** `Badge`, `EmptyState`, `Widget`
- **Date & time:** `Calendar`, `DateField`, `DatePicker`, `DateRangePicker`,
  `DateTimePicker`, `RangeCalendar`, `TimePicker`, `WheelDateTimePicker`,
  `WheelTimePicker`
- **Feedback:** `NumberValue`, `ProgressBar`, `ProgressCircle`, `Rating`,
  `TrendChip`
- **Forms:** `NumberStepper`, `NumberField`, `NumberPad`, `RadioButtonGroup`,
  `WheelPicker`, `WheelPickerGroup`
- **Navigation:** `Segment`, `Stepper`, `SplitView`

### CMS style → Pro override (OSS fallback in parentheses)

| CMS style | OSS fallback | Pro override |
|-----------|--------------|--------------|
| `badge` | chip / `tag-group` | `Badge` |
| `datepicker` | RN date primitive | `DatePicker` / `Calendar` / `DateField` / `DateRangePicker` / `DateTimePicker` / `TimePicker` / `WheelDateTimePicker` / `WheelTimePicker` |
| `rating` | custom stars | `Rating` |
| `progress` / `progress-root` / `progress-section` | RN `View` bar | `ProgressBar` / `ProgressCircle` |
| `number-input` | numeric `text-field` | `NumberField` / `NumberStepper` / `NumberPad` |
| `segmented-control` | `tabs` | `Segment` |
| `timeline` / `timeline-item` | custom RN | `Stepper` (vertical) |
| `radio` | `radio-group` | `RadioButtonGroup` |
| `range-slider` | `slider` (range) | `WheelPicker` family where it fits |
| `button` (variants) | `button` | `ProgressButton` / `SlideButton` / `SocialAuthButton` / `ToggleButton(+Group)` (opt-in via `variant` or dedicated styles) |
| `show-user-input` empty state | RN text | `EmptyState` |
| future data/chart styles | — | `AreaChart` / `BarChart` / `LineChart` / `PieChart` / `RadarChart` / `RadialChart` / `ComposedChart` (+ `ChartCrosshair` / `ChartIndicator` / `ChartTooltip`) |
| future KPI/dashboard styles | — | `Widget` / `NumberValue` / `TrendChip` |
| future multi-pane layout | RN stack | `SplitView` |

Mapping rule: if a Pro component exists for the concept, the Pro adapter uses it
directly (no adaptation); the OSS adapter keeps the fallback. New styles whose
*primary* reason to exist is a Pro component (charts, widgets) are added to the
catalog only with a concrete authoring use case, fields, render target, and an OSS
fallback — same bar as any new style.

## Related references

- [`style-field-naming-rules.md`](./style-field-naming-rules.md)
- [`style-platform-matrix.md`](./style-platform-matrix.md)
- [`style-field-audit.md`](./style-field-audit.md)
- [`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md)
- `mobile.md` (repo root) — full mobile rendering plan.
