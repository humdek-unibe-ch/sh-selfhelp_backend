# Interactive styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 interactive styles (`@selfhelp/shared` `interactive` category).
Last verified: 2026-06-04.
Source of truth: `src/types/styles/interactive.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Interactive styles are buttons, links, badges, and status chrome. Read
[`_conventions.md`](./_conventions.md) first; common fields and standard Mantine
cosmetic props (`mantine_size`, `mantine_color`, `mantine_radius`,
`mantine_variant`, icons) are not repeated below.

---

## button

**Purpose.** Mantine `Button` — the primary call-to-action. Can act as a link and can ask for confirmation before navigating.

**Administrators.** Set the `label`. To make it navigate, turn on `is_link` and set either `page_keyword` (an internal page) or `url` (external); `open_in_new_tab` opens a new tab. For destructive/important actions, fill the `confirmation_*` fields to pop a confirm dialog first.

**Developers.** Renders `<Button>`; when `is_link` it wraps navigation. When `confirmation_title`/`confirmation_message` are set, a confirmation modal gates the action (with `confirmation_continue` / `label_cancel` buttons). `mantine_fullwidth`, `mantine_compact`, and `mantine_auto_contrast` tune layout/contrast.

**Distinctive fields.** `label`, `is_link`, `page_keyword`, `url`, `open_in_new_tab`, `disabled`, `mantine_left_icon` / `mantine_right_icon`, `mantine_fullwidth`, `mantine_compact`, `mantine_auto_contrast`, `confirmation_title`, `confirmation_message`, `confirmation_continue`, `label_cancel`.

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

**Administrators.** A compact button showing just an icon (e.g. a settings cog). Pick the icon (`mantine_left_icon`); optionally make it a link to a page.

**Developers.** Renders `<ActionIcon>`; `mantine_action_icon_loading` shows a spinner. Supports `is_link` + `page_keyword` navigation.

**Distinctive fields.** `mantine_left_icon` (the icon), `mantine_action_icon_loading`, `is_link`, `page_keyword`, `open_in_new_tab`, `disabled`.

**Children.** No.

---

## alert

**Purpose.** Mantine `Alert` — a coloured call-out box for important messages.

**Administrators.** Surface a notice, warning, or success message. Set the `mantine_alert_title` and `content`, pick a colour, and optionally allow it to be dismissed.

**Developers.** Renders `<Alert>`; `mantine_with_close_button` adds a dismiss control with `close_button_label`. Can also contain child sections.

**Distinctive fields.** `mantine_alert_title`, `content`, `mantine_with_close_button`, `close_button_label`, `mantine_left_icon`.

**Children.** Yes.

---

## badge

**Purpose.** Mantine `Badge` — a small status pill/label.

**Administrators.** Tag content with a short label (e.g. "New", a count, a status). Set the `label`, colour, and variant.

**Developers.** Renders `<Badge>`. Supports left/right icons and `mantine_auto_contrast`.

**Distinctive fields.** `label`, `mantine_left_icon` / `mantine_right_icon`, `mantine_auto_contrast`.

**Children.** No.

---

## avatar

**Purpose.** Mantine `Avatar` — a user/profile image, initials, or icon placeholder.

**Administrators.** Show a profile picture. Provide `img_src`, or fall back to `mantine_avatar_initials` / an icon when there is no image.

**Developers.** Renders `<Avatar src>`; falls back to initials/icon.

**Distinctive fields.** `img_src`, `alt`, `mantine_avatar_initials`, `mantine_left_icon`, `mantine_avatar_variant`.

**Children.** No.

---

## chip

**Purpose.** Mantine `Chip` — a selectable pill, often used as a checkbox/radio alternative in forms.

**Administrators.** A toggleable tag. As a form input, set `name`, the on/off values (`chip_on_value` / `chip_off_value`), and whether it is required. `mantine_chip_multiple` allows multi-select within a group.

**Developers.** Renders `<Chip>`; participates in form submission via `name`/`value`. `mantine_chip_checked` sets the initial state; tooltip fields add a hover hint.

**Distinctive fields.** `label`, `name`, `value`, `chip_on_value` / `chip_off_value` (and the `mantine_chip_on_value`/`mantine_chip_off_value` mirrors), `chip_checked` / `mantine_chip_checked`, `mantine_chip_multiple`, `is_required`, `disabled`, `mantine_chip_variant`, `tooltip`, `mantine_tooltip_position`, `mantine_left_icon`, `mantine_icon_size`.

**Children.** No.

---

## indicator

**Purpose.** Mantine `Indicator` — a small dot/badge overlaid on a corner of its child (e.g. a notification count on an avatar).

**Administrators.** Add a status dot or count to another element. Place the target as a child; set position, size, and an optional `label` (count).

**Developers.** Renders `<Indicator>` around its child. `mantine_indicator_processing` animates it; `mantine_indicator_disabled` hides it.

**Distinctive fields.** `label` (count), `mantine_indicator_position`, `mantine_indicator_size`, `mantine_indicator_offset`, `mantine_indicator_inline`, `mantine_indicator_processing`, `mantine_indicator_disabled`, `mantine_border`, `mantine_radius`, `mantine_color`.

**Children.** Yes.

---

## theme-icon

**Purpose.** Mantine `ThemeIcon` — an icon inside a coloured rounded badge.

**Administrators.** Show an icon with a coloured background (e.g. feature bullets). Pick the icon and colour.

**Developers.** Renders `<ThemeIcon>` containing `mantine_left_icon`.

**Distinctive fields.** `mantine_left_icon` (the icon), `mantine_variant`.

**Children.** No.

---

## notification

**Purpose.** Mantine `Notification` — a toast-style notification block.

**Administrators.** Render an inline notification with a title, body, icon, and optional loading state / close button.

**Developers.** Renders `<Notification>`; `mantine_notification_loading` shows a spinner; `mantine_notification_with_close_button` adds dismiss.

**Distinctive fields.** `title`, `content`, `mantine_left_icon`, `mantine_notification_loading`, `mantine_notification_with_close_button`, `mantine_border`, `mantine_radius`, `mantine_color`.

**Children.** No.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
