# System styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 system/diagnostic styles (`@selfhelp/shared` `auth` category, non-flow surfaces).
Last verified: 2026-06-18.
Source of truth: `src/types/styles/error.ts` (shared), `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and the web (`src/app/components/frontend/styles/`) + mobile (`components/styles/auth/SystemSurfaces.tsx`) renderers.

System styles are the small, non-interactive surfaces the platform shows when a
page cannot render its normal content: access denied, content missing, page not
found, and the build/version diagnostic. They are author-placeable styles (so a
CMS can theme the message), render target `both`, and read the common
`title` / `message` content fields. Read [`_conventions.md`](./_conventions.md)
first; common fields are not repeated below.

---

## no-access

**Purpose.** The access-denied surface shown when the current user lacks access to the requested page/section.

**Administrators.** Customise the denied message with `title` / `message`. Use it on a page (or as the configured no-access surface) to explain why a user cannot see content.

**Developers.** Web renders an accessible message card from the interpolated `title` / `message`; mobile renders the same through the shared `SystemSurface` (`components/styles/auth/SystemSurfaces.tsx`), honouring `shared_radius`. The `web_*` presentation fields are web-only; the mobile fallback is a plain accessible surface.

**Distinctive fields.** `title`, `message` (both content/interpolated). Corner radius via `shared_radius`.

**Children.** No.

---

## missing

**Purpose.** A generic "content unavailable / missing" system surface.

**Administrators.** Shown when a referenced piece of content cannot be resolved. Customise with `title` / `message`.

**Developers.** Same renderer family as `no-access` (web message card / mobile `SystemSurface`).

**Distinctive fields.** `title`, `message`. Corner radius via `shared_radius`.

**Children.** No.

---

## not-found

**Purpose.** The page-not-found (404) system surface.

**Administrators.** Shown for an unknown route/page. Customise with `title` / `message`.

**Developers.** Same renderer family as `no-access` (web message card / mobile `SystemSurface`).

**Distinctive fields.** `title`, `message`. Corner radius via `shared_radius`.

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
