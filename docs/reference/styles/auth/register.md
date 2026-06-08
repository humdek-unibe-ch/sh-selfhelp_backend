# `register` style

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (backend `RegistrationService`, `@selfhelp/shared`, frontend renderer).
Last verified: 2026-06-04.
Source of truth: `App\Service\Auth\RegistrationService`, `migrations/Version20260604111011.php` (+ the baseline auth seed), `IRegisterStyle` in `@selfhelp/shared`, and `RegisterStyle.tsx`.

## Summary

Self-registration form. Collects an email (and, in code-required mode, a
validation code) and creates a pending user; the password is set later on the
[`validate`](./validate.md) activation page. After a successful submit it shows a
success alert plus two navigation buttons.

- **Category:** auth
- **Can have children:** no
- **Renderer:** `sh-selfhelp_frontend/src/app/components/frontend/styles/RegisterStyle.tsx`
- **Shared type:** `sh-selfhelp_shared/src/types/styles/auth.ts` (`IRegisterStyle`)
- **Backend service:** `App\Service\Auth\RegistrationService` (called by `AuthController::register`)

## Fields

`display`: `1` = translatable content, `0` = internal config. Defaults marked
**(seeded)** are pinned by this repo's migrations; the rest show the frontend
fallback used when the field is empty (the live `admin/styles/schema` endpoint
is authoritative for DB defaults).

| Field | Type | `display` | Default | Purpose |
|-------|------|-----------|---------|---------|
| `title` | text | 1 | `Registration` | Form heading. |
| `label_user` | text | 1 | `Email` | Email input label/placeholder. |
| `label_submit` | text | 1 | `Register` | Submit button label. |
| `alert_fail` | text | 1 | `Invalid email or validation code.` | Shown on a failed submit (overridden by the API error message when present). |
| `alert_success` | text | 1 | `Registration successful! Please check your email…` | Shown after a successful submit. |
| `open_registration` | text | 0 | `0` | **Policy flag.** `1` = open registration (no code). `0`/missing = a validation code is required. |
| `group` | select-group | 0 | `3` (seeded default) | **Group assignment.** Multi-select of the groups every user who registers through this section joins. Stored as the selected group IDs joined into one string (e.g. `2,3`). Applied in **both** modes (in code-required mode it is merged with the code's groups); see Behaviour. |
| `label_code` | text | 1 | `Validation Code` **(seeded)** | Label above the validation-code input (code-required mode only). |
| `code_placeholder` | text | 1 | `Enter your code` **(seeded)** | Placeholder inside the validation-code input. |
| `label_go_home` | text | 1 | `Go Home` **(seeded)** | Post-registration button → home page. |
| `label_go_to_login` | text | 1 | `Go to Login` **(seeded)** | Post-registration button → login page. |
| `label_pw` | text | 1 | — | Part of the contract; not rendered by the current form (password is set on activation). |
| `success` | text | 1 | — | Legacy success content field; superseded by `alert_success`. |
| spacing fields | various | 0 | — | Inherited from `IStyleWithSpacing` (margins/padding). |

de-CH translations are seeded for the four new register labels (`Validierungscode`,
`Geben Sie Ihren Code ein`, `Zur Startseite`, `Zur Anmeldung`).

## Behaviour

**The backend is the source of truth for the registration policy.** The
frontend mirrors it for UX but never relaxes it:

- **`open_registration === '1'` (open):** the code input is hidden and **no**
  code is sent. `RegistrationService` ignores any submitted code, mints one
  unique 8-char uppercase-alphanumeric code via
  `RegistrationCodeService::generateUnique()`, links it to the new user, marks
  it consumed immediately, and enrols the user into **every** group listed in
  the section's `group` field. The minted code is linked to the first of those
  groups and surfaces in the admin registration-code list as a used historical
  code.
- **`open_registration !== '1'` (code required):** the code input is shown and
  required. `RegistrationService` claims the supplied code under a
  `PESSIMISTIC_WRITE` lock (`claimRegistrationCode`), rejecting invalid/used
  codes, then consumes and links it. The user joins the **union** of the
  group(s) the code grants and the group(s) the section's `group` field
  configures (deduplicated by id), so one registration can grant several groups
  at once. A code may itself grant several groups (`validation_code_groups`).

Both modes create the user inside one transaction and queue the existing
account-activation email job. The new account is left in the `invited` status
(pending email validation) and is **not** blocked. The API response schema is
identical for both modes.

**Multi-group assignment.** The `group` field is a select-group **MultiSelect**,
so an admin can pick several groups (for example `subject` and `therapist`).
`resolveSectionPolicy` extracts every integer ID from the stored value
(separator-agnostic) and deduplicates it, and the service creates one
`users_groups` row per group. In code-required mode these section groups are
merged with the code's own groups. Different register sections can therefore
enrol users into different group sets. The groups are read **server-side from
the CMS section** — never from the request body — so a visitor cannot choose
their own groups.

**Request contract** (`config/schemas/api/v1/requests/auth/register.json`):
`page_id` (int, required), `email` (string, required), `code` (string,
optional — only sent in code-required mode).

## Frontend rendering

`RegisterStyle.tsx` reads each label as
`style.<field>?.content ?? (style.fields?.<field>?.content as string | undefined) ?? '<fallback>'`
(the `fields` bag is dynamically typed, hence the narrowing). It derives
`codeRequired = (style.open_registration?.content ?? style.fields?.open_registration?.content ?? '0') !== '1'`
and uses it to (a) conditionally render the code `TextInput` and (b) include
`code` in the mutation payload only when required. On
`register.isSuccess` it swaps the form for the success alert plus the
`label_go_home` / `label_go_to_login` buttons.

## Related files

| File | Purpose |
|------|---------|
| `src/Service/Auth/RegistrationService.php` | Registration logic (open + code-required modes). |
| `src/Service/Auth/RegistrationCodeService.php` | `generateUnique()` / batch `generate()` code minting. |
| `migrations/Version20260604111011.php` | Seeds `label_code`, `code_placeholder`, `label_go_home`, `label_go_to_login` (+ translations). |
| `config/schemas/api/v1/requests/auth/register.json` | Request schema. |
| `tests/Service/Auth/RegistrationServiceTest.php` | Open + code-required coverage. |
| `sh-selfhelp_frontend/.../styles/__tests__/RegisterStyle.test.tsx` | Frontend mode/label coverage. |

## Change history

- `2026-06-04` — Added open-registration support and made the validation-code
  label/placeholder and the two post-registration buttons CMS-managed
  (`Version20260604111011`).
- `2026-06-04` — Open registration now enrols the user into **all** groups
  selected in the section's `group` MultiSelect (previously only the first was
  applied because the value was cast with `(int)`).
- `2026-06-04` — Code-required registration now merges the code's group(s) with
  the section's `group` MultiSelect (a code can grant several groups via
  `validation_code_groups`), and new accounts are left `invited` (pending), no
  longer `blocked`.
