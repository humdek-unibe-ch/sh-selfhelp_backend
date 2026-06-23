# `validate` style

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (account-activation API, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-22.
Source of truth: `IValidateStyle` in `@selfhelp/shared`, `ValidateStyle.tsx`, the token-validation endpoints, and `migrations/Version20260604111011.php` (for the lifecycle status fields).

## Summary

Account-activation page reached from the link in the registration email
(`/validate/{userId}/{token}`). It validates the token, lets the user set their
name + password, completes activation, then redirects to login. This is where a
self-registered user (open or code-required) finishes onboarding.

- **Category:** auth
- **Can have children:** yes
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/ValidateStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`IValidateStyle`)
- **Backend:** token-validation + complete-validation endpoints (see `docs/reference/api/03-user-validation.md`).

## Lifecycle states

The component renders one of four states; the status text for each is now
CMS-managed (seeded by `Version20260604111011`, with the previous hardcoded
English as the fallback):

| State | Trigger | Fields used |
|-------|---------|-------------|
| Loading | token is being validated | `loading_title`, `loading_text` |
| Invalid link | token validation failed / `token_valid === false` | `error_title`, `error_heading`, `error_text` |
| Form | token valid | activation form fields (below) |
| Success | activation completed | `success_title`, `alert_success`, `redirect_text` |

## Fields

`display`: `1` = translatable content, `0` = internal config. Defaults marked
**(seeded)** are pinned by this repo's migrations; the rest show the frontend
fallback used when the field is empty.

### Lifecycle status (added 2026-06-04)

| Field | `display` | Default (en) | Purpose |
|-------|-----------|--------------|---------|
| `loading_title` | 1 | `Validating Link` **(seeded)** | Heading while the token is verified. |
| `loading_text` | 1 | `Please wait while we validate your account activation link...` **(seeded)** | Body while the token is verified. |
| `error_title` | 1 | `Invalid Validation Link` **(seeded)** | Alert title for an invalid/expired link. |
| `error_heading` | 1 | `Account validation failed` **(seeded)** | Bold heading inside the invalid-link alert. |
| `error_text` | 1 | `This validation link is invalid or has expired…` **(seeded)** | Fallback body when the link can't be validated (API message wins when present). |
| `success_title` | 1 | `Success` **(seeded)** | Alert title after activation. |
| `redirect_text` | 1 | `Redirecting to login in {seconds}s...` **(seeded)** | Countdown text; **must** contain the `{seconds}` placeholder. |

de-CH translations are seeded for all seven fields.

### Activation form

| Field | `display` | Default | Purpose |
|-------|-----------|---------|---------|
| `title` | 1 | — | Card heading (hidden when empty). |
| `subtitle` | 1 | — | Card subtitle (hidden when empty). |
| `label_name` / `name_placeholder` / `name_description` | 1 | `Name` / — / — | Name input. |
| `anonymous_user_name_description` | 1 | — | Alternate name description for anonymous users. |
| `label_timezone` | 1 | `Timezone` | Label for the timezone select (anonymous-user activation form). |
| `label_pw` / `pw_placeholder` | 1 | `Password` / — | Password input. |
| `label_pw_confirm` | 1 | `Confirm Password` | Confirm-password input. |
| `label_activate` | 1 | `Activate` | Submit button label. |
| `alert_fail` | 1 | `Validation failed` | Error alert title. |
| `alert_success` | 1 | `Validation successful` | Success alert body. |

### Form behaviour / styling config

`name`, `redirect_at_end`, `btn_cancel_url` (cancel-button target page),
`label_save`, `label_update`, `label_cancel`, plus the **shared** button knobs
`buttons_size` / `buttons_radius` / `buttons_variant` /
`buttons_position` / `buttons_order` and the button colours
`btn_save_color` / `btn_cancel_color` (all portable to mobile via
the shared mapper — RF-21), the card radius `radius`, and the web-only
`web_card_padding` / `web_card_shadow` / `web_border`. See the live
`admin/styles/schema` endpoint for their exact defaults.

> Verified against the live schema 2026-06-22: the button knobs are `shared_*`
> (promoted from the old `web_buttons_*` / `web_btn_*` by RF-21), the cancel
> target is `btn_cancel_url`, and the card radius is `radius`. The earlier
> `cancel_url` / `page_keyword` / `value_name` / `web_radius` names are gone.

## Behaviour

- The `{userId}` + `{token}` are parsed from the `/validate/{uid}/{token}` path
  segments, so the page works under any route slug containing `validate`.
- On success the page **always** redirects to login after a 3s countdown
  (regardless of `redirect_at_end`) because the token is consumed and a revisit
  would 404.
- Password/confirm matching is validated live and on submit.

## Frontend rendering

Each lifecycle label is read as
`style.<field>?.content ?? (style.fields?.<field>?.content as string | undefined) ?? '<fallback>'`.
`redirect_text` is rendered with `redirectText.replace('{seconds}', String(redirectCountdown))`,
so the placeholder is mandatory for the countdown to show.

## Related files

| File | Purpose |
|------|---------|
| `migrations/Version20260604111011.php` | Seeds the seven lifecycle status fields (+ translations). |
| `docs/reference/api/03-user-validation.md` | Token + complete-validation API contract. |
| `sh-selfhelp_frontend/.../styles/__tests__/ValidateStyle.test.tsx` | Loading + invalid-link label coverage. |

## Change history

- `2026-06-04` — Made the activation lifecycle status text (loading / invalid
  link / success / redirect countdown) CMS-managed (`Version20260604111011`).
