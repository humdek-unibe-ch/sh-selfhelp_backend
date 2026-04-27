# Seeding System Pages

> **Audience:** backend developers + DevOps. Anyone who needs to add a new
> built-in CMS page that ships with every install (legal pages, login,
> two-factor, profile, error screens, etc.).

## 1. What is a "system page"?

A system page is a CMS-managed page that

- ships with every install (it is created by a Doctrine migration, not by a
  human in the admin UI),
- has the `pages.is_system = 1` flag, which makes
  `AdminPageService::deletePage()` reject any attempt to delete it
  (admins can still **edit** the content and translations — they just cannot
  remove the row),
- carries one or more sections that hold the actual content,
- is fully translatable through the standard
  `pages_fields_translation` / `sections_fields_translation` mechanism, and
- is reachable from the dynamic slug catch-all (`src/app/[[...slug]]/page.tsx`
  in the frontend) like any other CMS page.

The shipped catalogue is:

| Keyword | Type | Notes |
|---|---|---|
| `home`                      | landing page                    | open access (`is_open_access = 1`) |
| `login`                     | login form                      | open access; uses `login` style component |
| `two-factor-authentication` | 2FA verification                | open access; uses `twoFactorAuth` style |
| `reset_password`            | password-reset request form     | open access; uses `resetPassword` style |
| `validate`                  | account-activation form         | open access; uses `validate` style. URL pattern is `/validate/[i:uid]/[a:token]` — the slug catch-all special-cases this so `slug = ['validate', uid, token]` resolves to keyword `validate` and `ValidateStyle` reads `uid` + `token` from `params.slug`. |
| `profile`                   | full profile management         | authenticated; uses `profile` style |
| `missing`                   | friendly 404                    | open access; **headless**; rich content |
| `no_access`                 | "access denied" for signed-in   | open access; **headless**; rich content + button. URL is `/no-access` while the keyword is `no_access`; the slug catch-all has a `SLUG_TO_KEYWORD` alias so the lookup uses the canonical keyword. |
| `no_access_guest`           | "access denied" for guests      | open access; **headless**; URL `/no-access-guest`, same alias treatment as `no_access`. |
| `agb`                       | Terms & Conditions              | operator-specific text; default scaffolding shipped |
| `impressum`                 | Imprint / version + license info | shipped with default version table; operators add legal contact |
| `disclaimer`                | Disclaimer                      | operator-specific text; default scaffolding shipped |
| `privacy`                   | GDPR privacy notice             | open access; full GDPR-compliant content |

The page rows themselves were created by the early `new_create_db.sql`
schema and the page-flag patches — what each migration in
`migrations/` adds is the **content** (sections + translations).

## 2. The components in play

### Frontend styled components

For pages that are forms, we already have purpose-built styled components:

| Style name      | Frontend file                                          | What it renders |
|-----------------|--------------------------------------------------------|-----------------|
| `login`         | `src/app/components/frontend/styles/LoginStyle.tsx`    | email + password form |
| `twoFactorAuth` | `src/app/components/frontend/styles/TwoFactorAuthStyle.tsx` | 6-digit code form |
| `resetPassword` | `src/app/components/frontend/styles/ResetPasswordStyle.tsx` | email-only reset request |
| `validate`      | `src/app/components/frontend/styles/ValidateStyle.tsx` | name + password + confirm |
| `profile`       | `src/app/components/frontend/styles/ProfileStyle.tsx`  | full profile management |

Each of these reads its labels, alert messages, button text, and styling
options from CMS fields (`label_user`, `label_pw`, `alert_fail`, etc.).
Seeding a system page that uses one of them is a one-section affair: insert
one row in `sections` with the correct `id_styles`, then populate its
fields and translations.

### Generic content components

For pages that are descriptive (privacy, agb, missing, no_access, …) we
build the content out of the same Mantine-flavoured catalogue admins use
in the UI:

- `paper` / `card` / `card-segment` — visual containers
- `title` (`mantine_title_order: 1` / `2` / `3`) — headings
- `text` — paragraphs
- `theme-icon` (`mantine_left_icon`: a tabler icon name like `IconShield`)
- `alert` (`mantine_color`: `blue` / `green` / `yellow` / `red`)
- `list` + `list-item` — bullet/ordered lists
- `divider` — visual separators
- `button` (`label`, `page_keyword`) — call-to-action

The full field catalogue per style is queryable from the DB:

```sql
SELECT s.name AS style, f.name AS field, sf.default_value, sf.title
FROM styles s
JOIN styles_fields sf ON sf.id_styles = s.id
JOIN fields f ON f.id = sf.id_fields
WHERE s.name = '<style_name>'
ORDER BY f.name;
```

### Backend safeguard

`AdminPageService::deletePage()` throws a `ServiceException` with
`HTTP 403` when the target page has `is_system = 1`:

```php
if ($page->isSystem()) {
    throw new ServiceException(
        'Cannot delete system pages. ...',
        Response::HTTP_FORBIDDEN
    );
}
```

That is the only place in the code that enforces the flag — admins still
load, edit, and translate the page through the normal admin endpoints. Do
not bypass this check from a custom service.

### Frontend fallback for login + 2FA

`/login` and `/two-factor-authentication` are special: if the CMS payload
is empty or the BFF call fails the slug catch-all
(`src/app/[[...slug]]/page.tsx`) redirects to the static escape-hatch
routes `src/app/auth/login/page.tsx` and
`src/app/auth/two-factor-authentication/page.tsx`. Those routes are
hardcoded React forms that talk directly to the auth API — they exist so
the platform is recoverable even if a migration is broken or an admin
accidentally empties the page. **Never delete those routes.**

The day-to-day URLs (`/login`, `/two-factor-authentication`) are served
by the slug catch-all from CMS content. The static `/auth/...` routes
are reached only as a redirect fallback. The `proxy.ts` admin-gate also
points at `/login`, so an unauthenticated visit to `/admin/...` lands on
the CMS login page first; only when the CMS payload is empty does the
slug catch-all bounce to `/auth/login`.

### URL → keyword aliases

Some shipped pages keep a kebab-case URL but a snake-case CMS keyword:

| URL                | Keyword             |
|--------------------|---------------------|
| `/no-access`       | `no_access`         |
| `/no-access-guest` | `no_access_guest`   |
| `/reset`           | `reset_password`    |

The slug catch-all has a small `SLUG_TO_KEYWORD` map for this, so
`slug.join('/')` is rewritten to the canonical keyword before the
backend lookup. The `validate` page is the other special case: its URL
is `/validate/[i:uid]/[a:token]` (the legacy AltoRouter pattern), so
the catch-all detects three-segment slugs starting with `validate` and
resolves the keyword to plain `validate`. `ValidateStyle` reads `uid`
and `token` from `params.slug` itself.

### `is_headless` and the error pages

`missing`, `no_access`, and `no_access_guest` are flagged
`is_headless = 1` so they render without the website chrome (header,
footer, locale switcher). A 404 wrapped in normal navigation is jarring
and tends to convince admins the platform itself is broken. The auth
pages (`login`, `two-factor-authentication`) are also headless for the
same reason. Polishing these pages further is fine, but do not remove
the headless flag.

## 3. Anatomy of a seeding migration

The canonical reference is `migrations/Version20260425090000.php` (the
privacy migration). Every other system-page migration follows the exact
same skeleton.

### 3.1 What `up()` writes

A single migration touches up to four tables:

1. **`pages`** — only when the page row does not already exist. Use
   `INSERT IGNORE ... WHERE keyword = '<keyword>'` so re-runs are no-ops
   and so a previous `new_create_db.sql` install (where the row was
   created up-front with `is_system = 1`) is also handled. **For the
   pages already shipped by `new_create_db.sql` (login, home, profile…)
   this step is skipped — only the content is seeded.**

2. **`pages_fields_translation`** — page-level `title` and `description`
   per locale (used for the `<title>` tab text, the meta description, and
   the footer link label).

3. **`acl_groups`** — one row per group that should see the page.
   The convention is:

   | group | select | insert | update | delete |
   |---|---|---|---|---|
   | `admin`     | 1 | 0 | 1 | 0 |
   | `therapist` | 1 | 0 | 0 | 0 |
   | `subject`   | 1 | 0 | 0 | 0 |

   Anonymous visitors are handled by the `is_open_access` flag, not by
   ACL rows.

4. **`sections` + `pages_sections` + `sections_hierarchy` + `sections_fields_translation`**:
   - one row per section
   - `pages_sections` for top-level sections (link to the page)
   - `sections_hierarchy` for nested children (link to the parent section)
   - one `sections_fields_translation` row per non-translatable property
     (always `id_languages = 1`, the `all` locale)
   - one row per (translatable field × locale) combination

### 3.2 What `down()` deletes

`pages.id` cascades into `pages_sections`, `pages_fields_translation`, and
`acl_groups` — but **not** into `sections`. A migration that creates
sections must explicitly delete them. The privacy migration does it
through a name-prefix pattern:

```php
public function down(Schema $schema): void
{
    $this->addSql("DELETE FROM `sections` WHERE `name` LIKE 'privacy-%'");
    $this->addSql("DELETE FROM `pages` WHERE `keyword` = 'privacy'");
}
```

If the page row already existed before the migration (e.g. seeded by
`new_create_db.sql`), `down()` only deletes the **sections** — not the
page row itself — so the catalogue stays consistent across rollbacks.

### 3.3 Idempotency

- `INSERT IGNORE` is used everywhere a unique constraint exists
  (`pages.keyword`, `pages_fields_translation` PK, `sections.name` if
  unique, `acl_groups` PK).
- The migration tracker prevents re-runs in normal use, so the only
  realistic re-run scenario is a developer running a single migration by
  number after partially completing it. The `INSERT IGNORE` pattern keeps
  that case harmless.
- `sections` does **not** have a unique constraint on `name`, so re-running
  would create duplicates. Use the `down()` → `up()` flow for re-seeding.

### 3.4 SQL escaping

The migrations interpolate strings directly into SQL because every value
is a literal known at migration-author time. The `escape()` helper in the
privacy migration handles the only two characters that can break a
single-quoted SQL literal:

```php
private function escape(string $value): string
{
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
}
```

Do **not** widen this to handle generic user input — these migrations are
not used at runtime.

## 4. Step-by-step: adding a new system page

### Step 1 — Decide the page metadata

| Field | Typical value |
|---|---|
| `keyword`              | URL-safe identifier (`my-page`)     |
| `url`                  | `/my-page` (or with router params)  |
| `id_type`              | `core` (resolves to `pageType.core` row) |
| `id_pageAccessTypes`   | `mobile_and_web` (lookup `pageAccessTypes`) |
| `is_open_access`       | `1` for pre-login pages, `0` otherwise |
| `is_system`            | `1` |
| `nav_position`         | `NULL` for non-nav pages, integer for nav menu |
| `footer_position`      | integer for footer links, `NULL` otherwise |

If the page should appear in the admin pages tree but not in any nav
menu, leave both positions `NULL`.

### Step 2 — Decide the page content shape

Pick one of two approaches:

#### A. Form pages — one styled-component section

```
[
  {
    "section_name": "<keyword>-<style>",
    "style_name": "login",            // or twoFactorAuth, validate, etc.
    "fields": {
      "label_user":   { "en-GB": "Email", "de-CH": "E-Mail" },
      "label_pw":     { "en-GB": "Password", "de-CH": "Passwort" },
      "label_login":  { "en-GB": "Sign in", "de-CH": "Anmelden" },
      "alert_fail":   { "en-GB": "...", "de-CH": "..." },
      "login_title":  { "en-GB": "Welcome back", "de-CH": "Willkommen zurück" },
    }
  }
]
```

The labels come from the styled component (look at the React `*.tsx`
file). Properties marked "non-translatable" (boolean toggles, sizes, …)
go under the `all` locale; user-visible strings go under each real
locale.

#### B. Content pages — a tree of generic styles

For pages like `privacy`, `missing`, or `home` you assemble a tree out of
the generic styles. The convention used in this codebase is a flat list
of section descriptors that the migration walks once — see
`Version20260425090000::SECTIONS`. Each entry is either a top-level
section (`pages_sections` row) or a nested child (`sections_hierarchy`
row keyed by its parent's `key`).

A typical "friendly status page" pattern (404, no-access, etc.):

```
- paper                        ← visual container
  ├── theme-icon               ← large coloured icon
  ├── title (h1)               ← "Page not found"
  ├── text                     ← short explanation paragraph
  └── button                   ← "Go home" CTA
```

A typical "rich content" pattern (privacy, agb, …):

```
- title (h1)                   ← page heading
- text                         ← intro
- title (h2)                   ← section heading
- text                         ← body paragraph
- list                         ← bullet list
  ├── list-item
  ├── list-item
  └── …
- divider                      ← visual rest
- … repeat …
```

### Step 3 — Create the migration file

Filename: `migrations/Version<timestamp>.php`. Copy the structure of
`Version20260425090000.php`:

1. Constants for the page keyword, URL, footer position, and the page meta
   per locale.
2. A `SECTIONS` constant array describing every section in display order.
   Each entry: `key`, `style`, optional `parent`, `fields`, `translations`.
3. `up()` calls helper methods: `insertPageRow()` (skip if the page row
   already ships in `new_create_db.sql`), `insertPageTranslations()`,
   `insertAclRows()`, `insertSections()`.
4. `down()` deletes sections by `name LIKE '<prefix>-%'` and (when the
   migration also created the page row) deletes the page by keyword.

### Step 4 — Add the React fallback if it is a critical-auth page

If the new page MUST keep working when the CMS is unreachable
(login, 2FA), add it to the `FALLBACK_KEYWORDS` map in
`src/app/[[...slug]]/page.tsx` (frontend):

```ts
const FALLBACK_KEYWORDS: Record<string, string> = {
    'login': '/auth/login',
    'two-factor-authentication': '/auth/two-factor-authentication',
    // <-- new keyword here
};
```

Also create the static fallback route under `src/app/auth/<keyword>/page.tsx`
with a hardcoded React form that talks directly to the auth API.

### Step 5 — Run the migration

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Then **clear the APCu cache pools** that hold permissions and frontend nav:

```bash
php bin/console cache:pool:clear cache.permissions cache.user_frontend cache.global cache.admin
```

This step is essential — the navigation API caches the list of pages a
user can see, and a freshly seeded page is only revealed after the cache
is invalidated. The `dev_log.md` entry for the privacy migration records
this gotcha in detail.

### Step 6 — Verify

```bash
# 1. Page row + flags
php bin/console doctrine:query:sql \
  "SELECT keyword, is_system, is_open_access FROM pages WHERE keyword='<keyword>'"

# 2. Section count
php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM pages_sections ps JOIN pages p ON p.id=ps.id_pages WHERE p.keyword='<keyword>'"

# 3. Frontend nav exposes the page
curl -s "http://localhost:8000/cms-api/v1/frontend/pages?language_id=3" | jq '.data[] | select(.keyword=="<keyword>")'
```

## 5. Catalogue of shipped seeding migrations

| Migration | Pages seeded | Why one migration vs many |
|---|---|---|
| `Version20260425090000` | `privacy` | First migration in this family; demonstrates the pattern in isolation. |
| `Version20260425100000` | `login`, `two-factor-authentication`, `reset_password`, `validate`, `profile`, `home`, `missing`, `no_access`, `no_access_guest`, `agb`, `impressum`, `disclaimer` | All grouped because they ship together as the "out-of-the-box system surface". Splitting into 12 migrations would multiply the cache-clear step and add nothing testable. |
| `Version20260425100100` | privacy CSS polish | Dead-letter migration: it tried to insert CSS into `sections_fields_translation` because the original draft assumed `css` was a translatable field. It is actually a direct column on `sections`, so this insert was a silent no-op. Left in the catalogue (it is harmless and already executed) so the timeline reads correctly; the real CSS work lives in `Version20260425110000` below. |
| `Version20260425110000` | publishing flags + cleanup + section CSS polish | Publishes the auth / status pages by setting `is_open_access = 1`; flags `missing` / `no_access` / `no_access_guest` as `is_headless = 1`; physically deletes `profile-link` and `logout` (pure-action pages with no body content); writes Tailwind utility classes onto `sections.css` for every system page so the rendered layout is centred, padded, and visually grouped. |

## 6. Common pitfalls

1. **Stale APCu cache.** The most common "the page does not appear in
   navigation" symptom. Always clear the four cache pools after a
   seeding migration: `cache.permissions`, `cache.user_frontend`,
   `cache.global`, `cache.admin`. The frontend will rebuild them on the
   next request.

   **Stale Redis cache.** `cache:pool:clear` only clears the in-memory
   distributed lock, not the actual Redis data store. If a permissions
   change still does not take effect after running the cache:pool:clear
   commands, run:

   ```bash
   docker exec redis redis-cli FLUSHALL
   ```

   This was the root cause of the "login still 403s after publishing"
   bug fixed in `Version20260425110000`.

2. **Forgetting the `acl_groups` rows.** Without them only `admin`
   sees the page (the admin role bypasses ACL). Therapist + subject
   users will get a 404 from the navigation API even though the row
   exists in `pages`.

3. **Forgetting the `is_open_access` flag on pre-login pages.** Login,
   2FA, reset-password, validate, no-access-guest, privacy, agb,
   impressum, disclaimer, and missing are reachable without a session,
   so they MUST set `is_open_access = 1`. The
   `get_user_acl` stored procedure has a `UNION ALL` branch that exposes
   open-access pages to anonymous visitors; without the flag the page
   is invisible to logged-out users.

4. **Mixing translatable and property fields.** Property fields
   (booleans, sizes, options) live under `id_languages = 1` (`all`) and
   appear in the migration under `'fields'`. Translatable fields
   (labels, paragraphs) live under each real locale and appear under
   `'translations'`. Putting a label under `all` makes it untranslatable;
   putting a property under `en-GB` makes it locale-specific by accident.

5. **Reusing a section name.** `sections.name` is not enforced unique
   by the schema, but is treated as a stable handle by the migration
   helpers (parent lookup, `down()` clean-up). Use a per-page prefix —
   e.g. `<keyword>-<key>` — to guarantee no two migrations collide.

6. **Editing an applied migration.** Don't. Add a follow-up migration
   that does an `UPDATE` (or a `DELETE` + `INSERT` pair) instead. The
   `Version20260425100100` privacy-polish migration is an example of a
   non-destructive follow-up that adds CSS classes to existing sections
   rather than rewriting them.

## 7. Reference

- `migrations/Version20260425090000.php` — the privacy seeding migration
- `migrations/Version20260425100000.php` — the system-page bulk seeding
- `migrations/Version20260425100100.php` — abandoned privacy CSS draft (kept for history)
- `migrations/Version20260425110000.php` — publishing flags + cleanup + real CSS polish
- `src/Service/CMS/Admin/AdminPageService::deletePage()` — `is_system` enforcement
- `src/app/[[...slug]]/page.tsx` (frontend) — slug catch-all + fallback redirect + URL aliasing
- `src/app/components/cms/admin-shell/admin-navbar/AdminNavbar.tsx` (frontend) — sidebar grouping for footer + system pages
- `src/app/components/shared/auth/AuthButton.tsx` (frontend) — fallback profile dropdown when `profile-link` is absent
- `docs/developer/13-acl-system.md` — ACL row semantics
- `docs/developer/08-cms-architecture.md` — pages / sections / fields model
