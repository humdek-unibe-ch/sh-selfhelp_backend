# `login` style

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (auth API, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-04.
Source of truth: `ILoginStyle` in `@selfhelp/shared`, `LoginStyle.tsx`, the auth login endpoint, and `migrations/Version20260604111011.php` (for `label_register`).

## Summary

Email/password sign-in form. On submit it calls the login API; if the account
has 2FA enabled it routes to the [`twoFactorAuth`](../index.md) page, otherwise
it redirects to `redirectTo` (or home). Provides links to password reset and
registration.

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/LoginStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`ILoginStyle`)
- **Backend:** auth login endpoint (`AuthApi.login`) issuing the JWT.

## Fields

`display`: `1` = translatable content, `0` = internal config. Non-seeded
defaults are the frontend fallback used when the field is empty.

| Field | Type | `display` | Default | Purpose |
|-------|------|-----------|---------|---------|
| `login_title` | text | 1 | `Welcome back!` | Form heading. |
| `label_user` | text | 1 | `Email/Username` | Email input label/placeholder. |
| `label_pw` | text | 1 | `Password` | Password input label/placeholder. |
| `label_login` | text | 1 | `Sign in` | Submit button label. |
| `label_pw_reset` | text | 1 | `Forgot password?` | Reset-password link label (→ `/reset`). |
| `label_register` | text | 1 | `Create account` **(seeded)** | Registration link label (→ register page). |
| `alert_fail` | text | 1 | `Invalid email or password.` | Failure notification message (overridden by the API error when present). |
| `type` | text | 0 | `light` | Button variant: `dark` → filled, otherwise light. |
| spacing fields | various | 0 | — | Inherited from `IStyleWithSpacing`. |

de-CH translation seeded for `label_register`: `Konto erstellen`.

## Behaviour

- Submit calls the login API. If `requires_2fa` is returned, the user id is
  stashed in `sessionStorage` and the flow routes to the two-factor page.
- On success it shows a notification and navigates to the `redirectTo` query
  param or the home route.
- Failure surfaces the API error message (falling back to `alert_fail`) as a
  notification — the form does not render an inline alert.

## Frontend rendering

`LoginStyle.tsx` reads `label_register` as
`style.label_register?.content ?? (style.fields?.label_register?.content as string | undefined) ?? 'Create account'`
(same narrowing pattern as the other dynamic labels) and renders it as the
second `Anchor` below the submit button. All other labels use the
`style.<field>?.content || '<fallback>'` pattern.

## Related files

| File | Purpose |
|------|---------|
| `migrations/Version20260604111011.php` | Seeds `label_register` (+ translation). |
| `sh-selfhelp_frontend/.../styles/LoginStyle.tsx` | Renderer. |
| `sh-selfhelp_frontend/.../styles/__tests__/LoginStyle.test.tsx` | Registration-link label coverage. |

## Change history

- `2026-06-04` — Added the CMS-managed `label_register` link label
  (`Version20260604111011`).
