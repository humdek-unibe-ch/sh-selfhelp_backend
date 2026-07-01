# `reset-password` style

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (auth password-reset flow, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-30.
Source of truth: `IResetPasswordStyle` in `@selfhelp/shared`, `ResetPasswordStyle.tsx`, `AuthController::forgotPassword`/`resetPassword`, `PasswordResetService`, `MailTemplateService`, and the seeded `page_routes` (migration `Version20260630083708`).

## Summary

The password-reset flow. The same section renders **two screens** depending on
the URL:

- `/reset` — the "forgot password" request form (visitor enters their email).
- `/reset/{user_id}/{token}` — the "set a new password" form, reached from the
  link in the recovery email.

The recovery email's **content is not configured on this style**. It is rendered
from the central mail config (`sh-mail-config` page → `mail_recovery` subject/body)
by `MailTemplateService`, exactly like the validation, welcome, and 2FA emails.
The recovery email is a **system mail** (`required_system`), so it is delivered
even when the recipient disabled platform emails in their profile.

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/ResetPasswordStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`IResetPasswordStyle`)

## For administrators

Place a `reset-password` section on your "forgot password" page (usually linked
from the [`login`](./login.md) page's `label_pw_reset` link). It handles both
the "request a reset link" screen and the "set a new password" screen reached
from the recovery email. Fill in the text for both modes so visitors see the
right labels and success/error states at each step.

The **content of the recovery email** and the **post-reset confirmation email**
are edited centrally on the mail-config page (the `Recovery` and `Password
Changed` subject/body fields), not on this style — the same place the
validation, welcome, and 2FA emails are edited.

Keep the success message deliberately vague ("If that email exists, we sent a
link") so the form does not reveal which addresses are registered.

## For developers

The reset-password page owns two seeded `page_routes` (plus the legacy
`/reset-password` alias): canonical `/reset` and `/reset/{user_id}/{token}`
(requirements `user_id = \d+`, `token = [A-Za-z0-9._~-]+`). The backend resolver
extracts the params, so `ResetPasswordStyle.tsx` reads `route_params.user_id` /
`route_params.token` (from `GET /pages/resolve`) — **not** the raw slug — to
decide which screen to render:

- **Request mode** (`/reset`, no params): posts the email to
  `POST /auth/forgot-password`. The endpoint always returns a generic success (no
  account enumeration). For a known active account,
  `PasswordResetService::requestReset()` stores a one-time token on the user
  (`users.token`) and sends the `mail_recovery` email immediately via the job
  scheduler. The link points at `<FRONTEND_BASE_URL>/reset/{user_id}/{token}`.
- **Set-password mode** (`/reset/{user_id}/{token}`, params present): posts the
  new password to `POST /auth/reset-password`.
  `PasswordResetService::resetPassword()` validates the token, sets the password,
  clears the token (single-use), sends the `mail_password_changed` confirmation
  email immediately, and the UI redirects to login.

The `user_id` / `token` route parameter names are `snake_case` and identical
across backend, frontend, and mobile (see
[27-db-driven-public-routing.md](../../../developer/27-db-driven-public-routing.md)).

The email copy comes from `MailTemplateService` (`mail_recovery_subject` /
`mail_recovery_body` on the `sh-mail-config` page), **not** from this style's
legacy `subject_user` field. The mail is `required_system`, so it bypasses the
user's email preference. See
[Authentication APIs](../../api/01-authentication.md#forgot-password-request-a-reset-link).

## Fields

`display`: `1` = translatable content, `0` = internal config. Exact defaults
live in the DB / `admin/styles/schema` endpoint.

| Field | Type | `display` | Purpose |
|-------|------|-----------|---------|
| `placeholder` | text | 1 | Placeholder for the email input (request mode). |
| `label_pw_reset` | text | 1 | Submit button label (request mode). |
| `alert_success` | text | 1 | Confirmation shown after submitting the email form (request mode). |
| `color` | color-picker | 0 | Submit/link-button accent colour (cross-platform via the shared mapper; default `blue`). |
| `reset_title` | text | 1 | Heading shown above the set-password form when the reset token is present in the URL. |
| `reset_label_pw` | text | 1 | Label for the new-password input on the set-password form. |
| `reset_pw_placeholder` | text | 1 | Placeholder for the new-password input. |
| `reset_label_pw_confirm` | text | 1 | Label for the confirm-password input. |
| `reset_pw_confirm_placeholder` | text | 1 | Placeholder for the confirm-password input. |
| `reset_label_submit` | text | 1 | Submit button label for the set-password form. |
| `reset_success_title` | text | 1 | Alert title shown after the password has been reset. |
| `reset_alert_success` | text | 1 | Alert body shown after the password has been reset. |
| `reset_redirect_text` | text | 1 | Countdown text shown while redirecting to login after a successful reset. Must contain `{seconds}`. |
| `reset_error_invalid_token` | text | 1 | Fallback message for an invalid or expired reset link. |
| `reset_error_pw_short` | text | 1 | Validation message when the new password is shorter than 8 characters. |
| `reset_error_pw_mismatch` | text | 1 | Validation message when the two entered passwords do not match. |
| `spacing` | spacing | 0 | Inherited from `IStyleWithSpacing`. |

> Verified against the live `admin/styles/schema` on 2026-06-22: the legacy
> `type` / `subject_user` / `is_html` fields are **not** in the DB catalog and the
> earlier doc rows for them were removed. The request-mode copy fields
> (`placeholder`, `label_pw_reset`, `alert_success`) **are** live and read by the
> renderer's "request a reset link" screen — they are not superseded by the
> `reset_*` set, which drives the separate "set a new password" screen.

## Related files

| File | Purpose |
|------|---------|
| `sh-selfhelp_frontend/.../styles/ResetPasswordStyle.tsx` | Request + set-new-password renderer (mode chosen from the URL). |
| `src/Controller/Api/V1/Auth/AuthController.php` | `forgotPassword` / `resetPassword` endpoints. |
| `src/Service/Auth/PasswordResetService.php` | Issues/consumes the token and sends the recovery mail. |
| `src/Service/Auth/MailTemplateService.php` | Renders the `mail_recovery` email from the central mail config. |

## Related references

- [login.md](./login.md) — links here via `label_pw_reset`.
- [_conventions.md](../_conventions.md) — common fields.
