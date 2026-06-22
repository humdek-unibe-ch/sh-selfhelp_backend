# System styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 system/diagnostic styles (`@selfhelp/shared` `auth` category, non-flow surfaces).
Last verified: 2026-06-22.
Source of truth: `src/types/styles/error.ts` (shared), `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and the web (`src/app/components/frontend/styles/`) + mobile (`components/styles/auth/SystemSurfaces.tsx`) renderers.

System styles are the small, non-interactive surfaces the platform shows when a
page cannot render its normal content: access denied, content missing, page not
found, and the build/version diagnostic. They are author-placeable styles (so a
CMS can theme the message), render target `both`, and share a common field set.
Read [`_conventions.md`](./_conventions.md) first; common fields are not repeated
below.

**Shared field set (no-access / missing / not-found).** All three are the same
renderer family and expose the same fields:

| Field | Scope | Purpose |
|-------|-------|---------|
| `title` | content | Heading (markdown-inline). |
| `message` | content | Body copy (markdown). |
| `button_label` | content | Label of the primary "back to home" button. |
| `login_label` | content | Label of the optional "sign in" button (`no-access` / `not-found` only). |
| `show_icon` | common | Toggle the surface icon (default on). |
| `show_login` | common | Toggle the sign-in button (`no-access` only; default off). |
| `color` | shared | Accent colour for the icon + button (mapped to both platforms). |
| `variant` | shared | Button variant (`light` / `filled` / …). |
| `radius` | shared | Corner radius. |
| `web_shadow` | web | Card shadow (web only). |

**Cross-platform note (verified live 2026-06-22).** The **web** renderer draws a
full message card: a tinted icon circle (`show_icon` + `color`), the
title/message, and the `variant`-styled action button(s). The **mobile**
renderer (`components/styles/auth/SystemSurfaces.tsx`) currently renders a
**reduced** surface — title + message in a themed surface honouring
`radius` — and does not yet draw the icon or the coloured action button.
That is the documented "plain accessible surface" fallback; honouring
`show_icon` / `color` / `button_label` on mobile is a tracked enhancement,
not a contract bug.

---

## no-access

**Purpose.** The access-denied surface shown when the current user lacks access to the requested page/section.

**Administrators.** Customise the denied message with `title` / `message`, the `button_label`, and (optionally) a sign-in button via `show_login` + `login_label`. Pick the accent with `color` (default `red`) and the button look with `variant`.

**Developers.** Web renders the full message card (tinted lock icon when `show_icon`, `color` accent, `variant` button); mobile renders the reduced `SystemSurface` (title + message, `radius`). See the shared field set + cross-platform note above.

**Distinctive fields.** Shared field set above; `show_login` + `login_label` are unique to `no-access`. Defaults: `color=red`, `variant=light`, `show_icon` on, `show_login` off.

**Children.** No.

---

## missing

**Purpose.** A generic "content unavailable / missing" system surface.

**Administrators.** Shown when a referenced piece of content cannot be resolved. Customise with `title` / `message` / `button_label`, the accent (`color`, default `gray`) and the button look (`variant`, default `filled`).

**Developers.** Same renderer family as `no-access` (web message card / mobile `SystemSurface`). Has no `login_label` / `show_login`.

**Distinctive fields.** Shared field set above (no sign-in button). Defaults: `color=gray`, `variant=filled`, `show_icon` on.

**Children.** No.

---

## not-found

**Purpose.** The page-not-found (404) system surface.

**Administrators.** Shown for an unknown route/page. Customise with `title` / `message` / `button_label` / `login_label`, the accent (`color`, default `gray`) and the button look (`variant`, default `light`).

**Developers.** Same renderer family as `no-access` (web message card / mobile `SystemSurface`). Note: a *truly* unknown route is also served by the app-level Next.js not-found fallback, which renders the same message-card family.

**Distinctive fields.** Shared field set above (incl. `login_label`). Defaults: `color=gray`, `variant=light`, `show_icon` on.

**Children.** No.

---

## version

**Purpose.** A build/version diagnostic surface.

**Administrators.** A diagnostic marker with no authored content fields. The authoritative version information lives on the admin System Maintenance page, not in a page section, so placing this style produces no visible output today.

**Developers.** `version` has no content fields. Both renderers are intentionally inert — the mobile `Version` renders `null`, and the web `VersionStyle` renders `null` — kept as real renderers (not `UnknownStyle` fallbacks) so the established `both`-target style satisfies web/mobile registry parity. Per the catalog rule, if no content references `version` it is a candidate for removal in a future coordinated catalog migration; until then it stays as an inert diagnostic.

**Distinctive fields.** None.

**Children.** No.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [auth/](./auth/) — the interactive auth **flow** styles (`login`, `register`, …).
- [index.md](./index.md) — full style catalog.
