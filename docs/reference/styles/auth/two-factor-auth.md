# `two-factor-auth` style

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (auth two-factor flow, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-04.
Source of truth: `ITwoFactorAuthStyle` in `@selfhelp/shared`, `TwoFactorAuthStyle.tsx`, and `AuthController::twoFactorVerify`.

## Summary

The second step of login when two-factor authentication is enabled. After a
correct email/password, the user is sent a one-time code and lands on this form
to enter it. On success they receive their session tokens.

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/TwoFactorAuthStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`ITwoFactorAuthStyle`)
- **Backend endpoint:** `App\Controller\Api\V1\Auth\AuthController::twoFactorVerify`

## For administrators

Place a `two-factor-auth` section on the page the login flow redirects to when 2FA
is required. Configure the heading, the explanatory text, the code-input label,
the submit-button label, the failure message, and the text that tells the user
how long the code stays valid (`label_expiration_2fa`).

## For developers

`TwoFactorAuthStyle.tsx` renders the code-entry form and posts the code to
`AuthController::twoFactorVerify`. On success the controller issues the JWT
access/refresh tokens (the same payload as a direct login). The code has a
limited lifetime; `label_expiration_2fa` is the human-readable hint for it.

## Fields

`display`: `1` = translatable content, `0` = internal config. Exact defaults
live in the DB / `admin/styles/schema` endpoint.

| Field | Type | `display` | Purpose |
|-------|------|-----------|---------|
| `title` | text | 1 | Form heading. |
| `text_md` | markdown | 1 | Instructions shown above the input. |
| `label_code` | text | 1 | Label for the verification-code input. |
| `label_submit` | text | 1 | Submit-button label. |
| `alert_fail` | text | 1 | Shown when the code is wrong/expired. |
| `label_expiration_2fa` | text | 1 | Tells the user how long the code is valid. |
| spacing fields | various | 0 | Inherited from `IStyleWithSpacing`. |

## Related files

| File | Purpose |
|------|---------|
| `src/Controller/Api/V1/Auth/AuthController.php` | `twoFactorVerify` endpoint. |
| `sh-selfhelp_frontend/.../styles/TwoFactorAuthStyle.tsx` | Code-entry renderer. |

## Related references

- [login.md](./login.md) — the first login step that triggers 2FA.
- [_conventions.md](../_conventions.md) — common fields.
