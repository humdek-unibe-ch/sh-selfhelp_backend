# `profile` style

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (self-service account management, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-04.
Source of truth: `IProfileStyle` in `@selfhelp/shared`, `ProfileStyle.tsx`, `App\Service\Auth\ProfileService`, and `App\Controller\Api\V1\Auth\ProfileController`.

## Summary

A complete self-service account page for the signed-in user. It shows account
information and provides four actions: change display name, change timezone,
change password, and delete the account. Every label, description, button,
success message, and error message is CMS-managed and translatable, so the whole
page can be localised without code.

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/ProfileStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`IProfileStyle`)
- **Backend:** `App\Service\Auth\ProfileService` (via `App\Controller\Api\V1\Auth\ProfileController`)

## For administrators

Place a single `profile` section on a logged-in-only page. Because it bundles
several panels, the field list is large but organised by panel — you only need
to translate the labels for the panels you keep. Appearance fields let you show
the panels as an **accordion** or as plain stacked cards, set columns, and pick
a card variant/radius/shadow.

Each action panel has its own labels, a success message, and per-error messages
(e.g. "current password wrong", "passwords do not match") so users get precise
feedback in their language.

## For developers

`ProfileStyle.tsx` renders the panels and calls `ProfileController` endpoints,
which delegate to `ProfileService` for the actual mutations (name, timezone,
password, deletion). All copy is read from the style fields with safe fallbacks.
The account-info panel renders read-only user data returned by the backend.

## Fields by panel

`display`: content/label fields are translatable (`1`); appearance toggles are
config (`0`). Exact defaults live in the DB / `admin/styles/schema` endpoint.

### Account information (read-only)

`profile_title` (page heading), `profile_account_info_title`, and the labels
`profile_label_email`, `profile_label_username`, `profile_label_name`,
`profile_label_created`, `profile_label_last_login`, `profile_label_timezone`.

### Change display name

`profile_name_change_title`, `profile_name_change_description`,
`profile_name_change_label`, `profile_name_change_placeholder`,
`profile_name_change_button`, `profile_name_change_success`, and the errors
`profile_name_change_error_required`, `profile_name_change_error_invalid`,
`profile_name_change_error_general`.

### Change timezone

`profile_timezone_change_title`, `profile_timezone_change_description`,
`profile_timezone_change_label`, `profile_timezone_change_placeholder`,
`profile_timezone_change_button`, `profile_timezone_change_success`, and the
errors `profile_timezone_change_error_required`,
`profile_timezone_change_error_general`.

### Change password

`profile_password_reset_title`, `profile_password_reset_description`, the field
labels `profile_password_reset_label_current` / `_new` / `_confirm`, the
matching placeholders `profile_password_reset_placeholder_current` / `_new` /
`_confirm`, the `profile_password_reset_button`,
`profile_password_reset_success`, and the errors
`profile_password_reset_error_current_required`, `_current_wrong`,
`_new_required`, `_confirm_required`, `_mismatch`, `_weak`, `_general`.

### Delete account

`profile_delete_title`, `profile_delete_description`,
`profile_delete_alert_text`, `profile_delete_modal_warning`,
`profile_delete_label_email`, `profile_delete_placeholder_email`,
`profile_delete_button`, `profile_delete_success`, and the errors
`profile_delete_error_email_required`, `profile_delete_error_email_mismatch`,
`profile_delete_error_general`. Deletion requires the user to retype their email
as confirmation.

### Appearance (config)

`profile_use_accordion`, `profile_accordion_multiple`,
`profile_accordion_default_opened`, `profile_gap`, `profile_columns`,
`profile_variant`, `profile_radius`, `profile_shadow`.

### Generic alerts

`alert_success`, `alert_fail`, `alert_del_success`, `alert_del_fail`.

## Related files

| File | Purpose |
|------|---------|
| `src/Service/Auth/ProfileService.php` | Name/timezone/password/delete operations. |
| `src/Controller/Api/V1/Auth/ProfileController.php` | Profile endpoints. |
| `sh-selfhelp_frontend/.../styles/ProfileStyle.tsx` | Renderer. |

## Related references

- [_conventions.md](../_conventions.md) — common fields.
- [index.md](../index.md) — full style catalog.
