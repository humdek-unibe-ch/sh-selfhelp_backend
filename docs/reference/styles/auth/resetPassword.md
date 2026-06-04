# `resetPassword` style

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (auth password-reset flow, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-04.
Source of truth: `IResetPasswordStyle` in `@selfhelp/shared`, `ResetPasswordStyle.tsx`, and the auth/password-reset endpoints + `MailTemplateService`.

## Summary

The "forgot password" form. A visitor enters their email; the backend sends a
password-reset email so they can set a new password. The style also configures
the **content of that reset email** (subject, body, HTML flag).

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/ResetPasswordStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`IResetPasswordStyle`)

## For administrators

Place a `resetPassword` section on your "forgot password" page (usually linked
from the [`login`](./login.md) page's `label_pw_reset` link). Fill in:

- the on-screen text the visitor sees (intro text, input placeholder, submit
  label, success message), and
- the email they receive (`subject_user`, `email_user` body, and whether the
  body is HTML).

Keep the success message deliberately vague ("If that email exists, we sent a
link") so the form does not reveal which addresses are registered.

## For developers

`ResetPasswordStyle.tsx` renders the request form and posts the email to the
password-reset endpoint. The backend issues a reset token and sends the email
rendered from `subject_user` / `email_user` (through `MailTemplateService`). The
reset link lands the user on the page that lets them choose a new password.

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
| `subject_user` | text | 1 | Subject line of the reset email. |
| `email_user` | markdown | 1 | Body of the reset email (supports the reset-link placeholder). |
| `is_html` | checkbox | 0 | Whether the reset email body is sent as HTML. |
| spacing fields | various | 0 | Inherited from `IStyleWithSpacing`. |

## Related files

| File | Purpose |
|------|---------|
| `sh-selfhelp_frontend/.../styles/ResetPasswordStyle.tsx` | Request-form renderer. |
| `src/Service/Auth/MailTemplateService.php` | Renders the reset email from the style's email fields. |

## Related references

- [login.md](./login.md) — links here via `label_pw_reset`.
- [_conventions.md](../_conventions.md) — common fields.
