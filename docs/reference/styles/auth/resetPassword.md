# `resetPassword` style

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (auth password-reset flow, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-05.
Source of truth: `IResetPasswordStyle` in `@selfhelp/shared`, `ResetPasswordStyle.tsx`, `AuthController::forgotPassword`/`resetPassword`, `PasswordResetService`, and `MailTemplateService`.

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

Place a `resetPassword` section on your "forgot password" page (usually linked
from the [`login`](./login.md) page's `label_pw_reset` link). Fill in the
on-screen text the visitor sees: intro text, input placeholder, submit label,
and the success message.

The **content of the recovery email** is edited centrally on the mail-config
page (the `Recovery` subject/body), not on this style — the same place the
validation, welcome, and 2FA emails are edited.

Keep the success message deliberately vague ("If that email exists, we sent a
link") so the form does not reveal which addresses are registered.

## For developers

`ResetPasswordStyle.tsx` reads the `[[...slug]]` path to decide which screen to
render:

- **Request mode** (`/reset`): posts the email to `POST /auth/forgot-password`.
  The endpoint always returns a generic success (no account enumeration). For a
  known active account, `PasswordResetService::requestReset()` stores a one-time
  token on the user (`users.token`) and sends the `mail_recovery` email
  immediately via the job scheduler. The link points at
  `<FRONTEND_BASE_URL>/reset/{user_id}/{token}`.
- **Set-password mode** (`/reset/{user_id}/{token}`): posts the new password to
  `POST /auth/reset-password`. `PasswordResetService::resetPassword()` validates
  the token, sets the password, clears the token (single-use), and the UI
  redirects to login.

The email copy comes from `MailTemplateService` (`mail_recovery_subject` /
`mail_recovery_body` on the `sh-mail-config` page), **not** from this style's
`subject_user` / `email_user` fields — those are legacy and are not consumed by
the current backend. The mail is `required_system`, so it bypasses the user's
email preference. See [Authentication APIs](../../api/01-authentication.md#forgot-password-request-a-reset-link).

## Fields

`display`: `1` = translatable content, `0` = internal config. Exact defaults
live in the DB / `admin/styles/schema` endpoint.

| Field | Type | `display` | Purpose |
|-------|------|-----------|---------|
| `text_md` | markdown | 1 | Intro text shown above the form. |
| `placeholder` | text | 1 | Placeholder for the email input. |
| `label_pw_reset` | text | 1 | Submit button label. |
| `alert_success` | text | 1 | Confirmation shown after submitting. |
| `type` | text | 0 | Visual type/variant of the success alert. |
| `subject_user` | text | 1 | Legacy: not used. The reset email subject comes from `mail_recovery_subject` on the `sh-mail-config` page. |
| `email_user` | markdown | 1 | Legacy: not used. The reset email body comes from `mail_recovery_body` on the `sh-mail-config` page. |
| `is_html` | checkbox | 0 | Whether the reset email body is sent as HTML. |
| spacing fields | various | 0 | Inherited from `IStyleWithSpacing`. |

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
