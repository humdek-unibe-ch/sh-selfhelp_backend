# `login` style

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (auth API, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-24.
Source of truth: `ILoginStyle` in `@selfhelp/shared`, `LoginStyle.tsx`, the auth login endpoint, `migrations/Version20260604111011.php` (for `label_register`), and `migrations/Version20260619131830.php` (for `subtitle`).

## Summary

Email/password sign-in form. On submit it calls the login API; if the account
has 2FA enabled it routes to the [`two-factor-auth`](../index.md) page, otherwise
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
| `subtitle` | text | 1 | `` (empty) | Optional subtitle under the title; hidden when empty. |
| `label_user` | text | 1 | `Email/Username` | Email input label/placeholder. |
| `label_pw` | text | 1 | `Password` | Password input label/placeholder. |
| `label_login` | text | 1 | `Sign in` | Submit button label. |
| `label_pw_reset` | text | 1 | `Forgot password?` | Reset-password link label (→ `/reset`). |
| `label_register` | text | 1 | `Create account` **(seeded)** | Registration link label (→ register page). |
| `alert_fail` | text | 1 | `Invalid email or password.` | Failure notification message (overridden by the API error when present). |
| `color` | color-picker | 0 | `dark` | Submit button colour (cross-platform, via the shared mapper). Renderers treat the neutral `dark`/`black` accent **adaptively** so it stays readable in dark mode (see Frontend rendering). |
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

### Adaptive neutral accent (dark mode)

The seeded `color` default is `dark`, which the shared colour mapper renders as a
near-black accent. Applied literally that makes the submit button (and the
reset/register links) blend into a **dark-mode** background. So when the resolved
accent is the neutral `dark` or `black`, both renderers adapt instead of using it
verbatim:

- **Web** (`LoginStyle.tsx`): the submit `Button` drops the Mantine `color` and
  uses theme tokens (`var(--mantine-color-text)` background, `var(--mantine-color-body)`
  text) so it inverts correctly per scheme; the `Anchor` links drop the accent and
  fall back to the default theme link colour.
- **Mobile** (`components/styles/auth/Login.tsx`): the accent falls back to the
  theme primary in dark mode (the button label is a fixed light colour), keeping
  the button and links legible.

Any **non-neutral** accent (an explicit brand colour) is honoured as-is on both
platforms, and **light mode is unchanged**. This is a renderer-only concern — the
backend seed default stays `dark`.

## Related files

| File | Purpose |
|------|---------|
| `migrations/Version20260604111011.php` | Seeds `label_register` (+ translation). |
| `sh-selfhelp_frontend/.../styles/LoginStyle.tsx` | Renderer. |
| `sh-selfhelp_frontend/.../styles/__tests__/LoginStyle.test.tsx` | Registration-link label coverage. |

## Change history

- `2026-06-24` — Documented the **adaptive neutral accent**: both renderers now
  treat a `dark`/`black` `color` adaptively so the submit button and links stay
  readable in dark mode (frontend `>= 0.1.34`, mobile `>= 0.1.13`). Renderer-only;
  the seeded `color` default is unchanged.
- `2026-06-19` — Linked the optional translatable `subtitle` content field
  (`Version20260619131830`) and documented the existing `color` submit
  colour. Removed the stale `type` field row (no such DB field; the dead `type`
  read in the renderer is dropped in the coupled shared/renderer wave).
- `2026-06-04` — Added the CMS-managed `label_register` link label
  (`Version20260604111011`).
