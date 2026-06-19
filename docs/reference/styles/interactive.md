# Interactive styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 interactive styles (`@selfhelp/shared` `interactive` category).
Last verified: 2026-06-04.
Source of truth: `src/types/styles/interactive.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Interactive styles are buttons, links, badges, and status chrome. Read
[`_conventions.md`](./_conventions.md) first; common fields and standard Mantine
cosmetic props (`web_size`, `shared_color`, `web_radius`,
`web_variant`, icons) are not repeated below.

---

## button

**Purpose.** Mantine `Button` — the primary call-to-action. Can act as a link and can ask for confirmation before navigating.

**Administrators.** Set the `label`. To make it navigate, turn on `is_link` and set either `page_keyword` (an internal page) or `url` (external); `open_in_new_tab` opens a new tab. For destructive/important actions, fill the `confirmation_*` fields to pop a confirm dialog first.

**Developers.** Renders `<Button>`; when `is_link` it wraps navigation. When `confirmation_title`/`confirmation_message` are set, a confirmation modal gates the action (with `confirmation_continue` / `label_cancel` buttons). `web_fullwidth`, `web_compact`, and `web_auto_contrast` tune layout/contrast.

**Distinctive fields.** `label`, `is_link`, `page_keyword`, `url`, `open_in_new_tab`, `disabled`, `web_left_icon` / `web_right_icon`, `web_fullwidth`, `web_compact`, `web_auto_contrast`, `confirmation_title`, `confirmation_message`, `confirmation_continue`, `label_cancel`.

**Children.** No.

---

## link

**Purpose.** A plain anchor / pressable navigation element.

**Administrators.** A text link. Set the `label` and `url`, and whether it opens in a new tab.

**Developers.** Renders `<a>` (web) / `Pressable` (mobile).

**Distinctive fields.** `label`, `url`, `open_in_new_tab`.

**Children.** No.

---

## action-icon

**Purpose.** Mantine `ActionIcon` — an icon-only button.

**Administrators.** A compact button showing just an icon (e.g. a settings cog). Pick the icon (`web_left_icon`); optionally make it a link to a page.

**Developers.** Renders `<ActionIcon>`; `web_action_icon_loading` shows a spinner. Supports `is_link` + `page_keyword` navigation.

**Distinctive fields.** `web_left_icon` (the icon), `web_action_icon_loading`, `is_link`, `page_keyword`, `open_in_new_tab`, `disabled`.

**Children.** No.

---

## alert

**Purpose.** A coloured call-out box for important messages. Web → Mantine
`Alert`; mobile → HeroUI Native `Alert` (`Indicator` + `Content` + `Title` +
`Description`). See the field-by-field mapping in
[style-mobile-mapping.md](./style-mobile-mapping.md) §5.

**Administrators.** Surface a notice, warning, or success message. Set the title
and `content`, pick a colour/variant (web), and optionally allow it to be
dismissed.

**Developers.** The message comes from `content` (read by both platforms; mobile
maps it to `Alert.Description`). On web `web_with_close_button` adds a dismiss
control. The mobile status colour comes from `shared_intent` via the shared
mapper, not from `shared_color`. Can also contain child sections.

**Distinctive fields.** `content` (the message), the title field,
`web_with_close_button`, `shared_color`, `web_variant`, `web_left_icon`.

> **Audit note (see [style-field-audit.md](./style-field-audit.md) §5).**
> **Landed (backend `Version20260619090609`):** `value` (duplicated `content`) and
> `web_alert_with_close_button` (duplicated `web_with_close_button`) were removed,
> and `web_alert_title` was renamed to the unprefixed `alert_title` both platforms
> read (RF-07/08/10). The `@selfhelp/shared` type + web/mobile reads follow in the
> coupled renderer wave (the shared type's stale `close_button_label` is dropped
> there). Tracked in
> [style-refactoring-recommendations.md](./style-refactoring-recommendations.md).

**Children.** Yes.

---

## badge

**Purpose.** Mantine `Badge` — a small status pill/label.

**Administrators.** Tag content with a short label (e.g. "New", a count, a status). Set the `label`, colour, and variant.

**Developers.** Renders `<Badge>`. Supports left/right icons and `web_auto_contrast`.

**Distinctive fields.** `label`, `web_left_icon` / `web_right_icon`, `web_auto_contrast`.

**Children.** No.

---

## avatar

**Purpose.** Mantine `Avatar` — a user/profile image, initials, or icon placeholder.

**Administrators.** Show a profile picture. Provide `img_src`, or fall back to `web_avatar_initials` / an icon when there is no image.

**Developers.** Renders `<Avatar src>`; falls back to initials/icon.

**Distinctive fields.** `img_src`, `alt`, `web_avatar_initials`, `web_left_icon`, `web_avatar_variant`.

**Children.** No.

---

## chip

**Purpose.** Mantine `Chip` — a selectable pill, often used as a checkbox/radio alternative in forms.

**Administrators.** A toggleable tag. As a form input, set `name`, the on/off values (`chip_on_value` / `chip_off_value`), and whether it is required. `web_chip_multiple` allows multi-select within a group.

**Developers.** Renders `<Chip>`; participates in form submission via `name`/`value`. `web_chip_checked` sets the initial state; tooltip fields add a hover hint.

**Distinctive fields.** `label`, `name`, `value`, `chip_on_value` / `chip_off_value` (and the `web_chip_on_value`/`web_chip_off_value` mirrors), `chip_checked` / `web_chip_checked`, `web_chip_multiple`, `is_required`, `disabled`, `web_chip_variant`, `tooltip`, `web_tooltip_position`, `web_left_icon`, `web_icon_size`.

**Children.** No.

---

## indicator

**Purpose.** Mantine `Indicator` — a small dot/badge overlaid on a corner of its child (e.g. a notification count on an avatar).

**Administrators.** Add a status dot or count to another element. Place the target as a child; set position, size, and an optional `label` (count).

**Developers.** Renders `<Indicator>` around its child. `web_indicator_processing` animates it; `web_indicator_disabled` hides it.

**Distinctive fields.** `label` (count), `web_indicator_position`, `web_indicator_size`, `web_indicator_offset`, `web_indicator_inline`, `web_indicator_processing`, `web_indicator_disabled`, `web_border`, `web_radius`, `shared_color`.

**Children.** Yes.

---

## theme-icon

**Purpose.** Mantine `ThemeIcon` — an icon inside a coloured rounded badge.

**Administrators.** Show an icon with a coloured background (e.g. feature bullets). Pick the icon and colour.

**Developers.** Renders `<ThemeIcon>` containing `web_left_icon`.

**Distinctive fields.** `web_left_icon` (the icon), `web_variant`.

**Children.** No.

---

## notification

**Purpose.** Mantine `Notification` — a toast-style notification block.

**Administrators.** Render an inline notification with a title, body, icon, and optional loading state / close button.

**Developers.** Renders `<Notification>`; `web_notification_loading` shows a spinner; `web_notification_with_close_button` adds dismiss.

**Distinctive fields.** `title`, `content`, `web_left_icon`, `web_notification_loading`, `web_notification_with_close_button`, `web_border`, `web_radius`, `shared_color`.

**Children.** No.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
