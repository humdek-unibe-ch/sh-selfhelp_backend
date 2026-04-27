<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up to the system-page seeding (`Version20260425100000`):
 *
 *   1. Mark the auth-critical, validation, and error pages as
 *      `is_open_access = 1`. Without this flag the public
 *      `/api/pages/by-keyword/<kw>` endpoint returns a 403 to anonymous
 *      visitors → the slug catch-all sees `null` → falls back to the
 *      hardcoded React routes (`/auth/login`, `/auth/two-factor-authentication`).
 *      The user's first-load test (`http://localhost:3000/login`) was
 *      ending up on `/auth/login` for exactly this reason.
 *
 *   2. Mark the error pages (`missing`, `no_access`, `no_access_guest`)
 *      as `is_headless = 1` so they render without the website chrome
 *      (header, footer, locale switcher) — the same way the auth pages
 *      already do. A 404 wrapped in the navigation skin is jarring and
 *      makes admins doubt whether the platform actually broke.
 *
 *   3. Delete the `profile-link` and `logout` page rows. Both are pure
 *      navigation actions — `profile-link` is the avatar dropdown label
 *      and `logout` triggers the sign-out flow + redirect. Neither
 *      renders body content, so leaving an empty page row in the DB
 *      only confuses admins who see them in the System Pages menu.
 *
 *   4. Polish the on-page styling of every system page by writing
 *      `sections.css` (a *direct column* on `sections`, not a translatable
 *      field — this is also the bug fix for the dead-letter
 *      `Version20260425100100` migration which mistakenly tried to insert
 *      into `sections_fields_translation`). The classes are Tailwind
 *      utility tokens + Mantine variants that the rest of the project
 *      already uses, so no new dependency or build step is involved.
 *
 * The `down()` method reverts every step except the `profile-link` /
 * `logout` deletion (those rows are intentionally one-way; the user
 * confirmed they should disappear from the catalogue and any future
 * migration can recreate them via the standard install seed).
 */
final class Version20260425110000 extends AbstractMigration
{
    /**
     * Pages that need to be reachable without an authenticated session
     * (because their content IS the auth flow, the post-auth landing,
     * the activation flow, the public legal text, or an error screen).
     */
    private const OPEN_ACCESS_KEYWORDS = [
        'login',
        'two-factor-authentication',
        'reset_password',
        'validate',
        'missing',
        'no_access',
        'no_access_guest',
        'agb',
        'impressum',
        'disclaimer',
    ];

    /**
     * Pages that should render without the website chrome (header / footer
     * / locale switcher). The auth pages were already headless via
     * `new_create_db.sql`; the error pages picked up the website shell
     * by default which made admins think the platform was broken when a
     * 404 actually rendered.
     */
    private const HEADLESS_KEYWORDS = [
        'missing',
        'no_access',
        'no_access_guest',
    ];

    /**
     * Page keywords to physically delete (they are pure nav actions, not
     * content pages). Their `acl_groups` and `pages_fields_translation`
     * children are removed first to satisfy the FK constraints on the
     * `pages` row.
     */
    private const KEYWORDS_TO_DELETE = [
        'profile-link',
        'logout',
    ];

    /**
     * Map of `<sections.name> => <sections.css value>` we want to apply.
     *
     * Layout choices, by group:
     *
     * Privacy — the page is a long-form GDPR notice. We want headings to
     * read as proper "chapters" with breathing room and a faint divider,
     * lists with comfortable left padding, and paragraphs with relaxed
     * leading. The classes are pure Tailwind utility tokens (no Mantine
     * theme dependency) so they survive any future re-theming.
     *
     * Form pages (login / 2fa / reset / validate / profile) — wrap the
     * outer container in a centered max-width card so the form sits
     * pleasingly on the page on every breakpoint.
     *
     * Status / landing pages (home / missing / no_access /
     * no_access_guest) — center the hero block vertically and horizontally
     * so it reads as a poster, not a body paragraph.
     *
     * Legal pages (agb / impressum / disclaimer) — a comfortable
     * 720px reading column so the operator-supplied paragraphs do not
     * stretch across the full viewport.
     */
    private const CSS_BY_SECTION = [
        // ---------- privacy (cosmetic re-do; the previous attempt wrote
        // to `sections_fields_translation` which silently no-op'd because
        // `css` is a direct column on `sections`) ----------
        'privacy-h1' => 'mt-2 mb-2 text-3xl font-bold',
        'privacy-intro' => 'mb-10 text-base leading-relaxed text-gray-700 dark:text-gray-300',
        'privacy-h2-personal-data' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-legal-basis' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-retention' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-recipients' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-international' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-rights' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-cookies' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-contact' => 'mt-12 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700',
        'privacy-personal-data-intro' => 'mb-4 leading-relaxed',
        'privacy-legal-basis-text' => 'mb-4 leading-relaxed',
        'privacy-retention-text' => 'mb-4 leading-relaxed',
        'privacy-recipients-text' => 'mb-4 leading-relaxed',
        'privacy-international-text' => 'mb-4 leading-relaxed',
        'privacy-rights-intro' => 'mb-4 leading-relaxed',
        'privacy-cookies-intro' => 'mb-4 leading-relaxed',
        'privacy-contact-text' => 'mb-4 leading-relaxed',
        'privacy-personal-data-list' => 'pl-6 mb-8 space-y-2',
        'privacy-rights-list' => 'pl-6 mb-8 space-y-2',
        'privacy-cookies-list' => 'pl-6 mb-8 space-y-2',

        // ---------- login / 2fa / reset / validate / profile ----------
        // The styled components already render their own card; we just
        // need the wrapping container to center them in the viewport.
        'login-sys-container' => 'max-w-md mx-auto px-4 py-12',
        'twofa-sys-container' => 'max-w-md mx-auto px-4 py-12',
        'reset-sys-container' => 'max-w-md mx-auto px-4 py-12',
        'validate-sys-container' => 'max-w-md mx-auto px-4 py-12',
        'profile-sys-container' => 'max-w-3xl mx-auto px-4 py-8',

        // ---------- home / status pages ----------
        // The hero paper sits inside a centered, narrow column.
        'home-sys-hero-paper' => 'max-w-2xl mx-auto my-12',
        'home-sys-hero-icon' => 'flex justify-center mb-4',
        'home-sys-hero-title' => 'text-center mb-4',
        'home-sys-hero-text' => 'text-center text-gray-600 dark:text-gray-300 mb-6',
        'missing-sys-paper' => 'max-w-xl mx-auto my-16',
        'missing-sys-icon' => 'flex justify-center mb-4',
        'missing-sys-title' => 'text-center mb-3',
        'missing-sys-text' => 'text-center text-gray-600 dark:text-gray-300 mb-6',
        'missing-sys-home-button' => 'flex justify-center',
        'noaccess-sys-paper' => 'max-w-xl mx-auto my-16',
        'noaccess-sys-icon' => 'flex justify-center mb-4',
        'noaccess-sys-title' => 'text-center mb-3',
        'noaccess-sys-text' => 'text-center text-gray-600 dark:text-gray-300 mb-6',
        'noaccess-sys-home-button' => 'flex justify-center',
        'noaccessguest-sys-paper' => 'max-w-xl mx-auto my-16',
        'noaccessguest-sys-icon' => 'flex justify-center mb-4',
        'noaccessguest-sys-title' => 'text-center mb-3',
        'noaccessguest-sys-text' => 'text-center text-gray-600 dark:text-gray-300 mb-6',
        'noaccessguest-sys-login-button' => 'flex justify-center',

        // ---------- legal pages ----------
        // A comfortable reading column so paragraphs do not stretch
        // across a 4K viewport.
        'agb-sys-h1' => 'mt-2 mb-4 text-3xl font-bold',
        'agb-sys-intro' => 'mb-8 leading-relaxed',
        'agb-sys-alert' => 'max-w-3xl mx-auto mb-8',
        'impressum-sys-h1' => 'mt-2 mb-6 text-3xl font-bold text-center',
        'impressum-sys-operator-card' => 'max-w-3xl mx-auto mb-6',
        'impressum-sys-versions-card' => 'max-w-3xl mx-auto mb-6',
        'impressum-sys-libraries-card' => 'max-w-3xl mx-auto mb-6',
        'disclaimer-sys-h1' => 'mt-2 mb-4 text-3xl font-bold',
        'disclaimer-sys-intro' => 'mb-8 leading-relaxed',
        'disclaimer-sys-alert' => 'max-w-3xl mx-auto mb-8',
    ];

    public function getDescription(): string
    {
        return 'Publish system pages (open access + headless), drop profile-link/logout, polish section CSS.';
    }

    public function up(Schema $schema): void
    {
        $this->setOpenAccessFlags(true);
        $this->setHeadlessFlags(true);
        $this->deleteRetiredPages();
        $this->applyCssClasses();
    }

    public function down(Schema $schema): void
    {
        // Reverse-only what we changed. Deleted pages are intentionally
        // not restored — re-running the original install seed will
        // recreate them in the (unlikely) case an operator wants the
        // dropdown labels back as page rows.
        $this->setOpenAccessFlags(false);
        $this->setHeadlessFlags(false);
        $this->clearCssClasses();
    }

    // ----------------------------------------------------------------------
    // Step 1 — flip is_open_access on pages that must be reachable when
    // the visitor is anonymous.
    // ----------------------------------------------------------------------
    private function setOpenAccessFlags(bool $enable): void
    {
        $value = $enable ? 1 : 0;
        $list = $this->quoteList(self::OPEN_ACCESS_KEYWORDS);
        $this->addSql("UPDATE `pages` SET `is_open_access` = {$value} WHERE `keyword` IN ({$list})");
    }

    // ----------------------------------------------------------------------
    // Step 2 — flip is_headless on the error pages.
    // ----------------------------------------------------------------------
    private function setHeadlessFlags(bool $enable): void
    {
        $value = $enable ? 1 : 0;
        $list = $this->quoteList(self::HEADLESS_KEYWORDS);
        $this->addSql("UPDATE `pages` SET `is_headless` = {$value} WHERE `keyword` IN ({$list})");
    }

    // ----------------------------------------------------------------------
    // Step 3 — physically delete the pages we no longer want in the
    // catalogue. Children are removed first to satisfy FK constraints.
    // ----------------------------------------------------------------------
    private function deleteRetiredPages(): void
    {
        $list = $this->quoteList(self::KEYWORDS_TO_DELETE);

        $this->addSql(<<<SQL
            DELETE ag FROM `acl_groups` ag
            JOIN `pages` p ON p.`id` = ag.`id_pages`
            WHERE p.`keyword` IN ({$list})
        SQL);
        $this->addSql(<<<SQL
            DELETE pft FROM `pages_fields_translation` pft
            JOIN `pages` p ON p.`id` = pft.`id_pages`
            WHERE p.`keyword` IN ({$list})
        SQL);
        $this->addSql(<<<SQL
            DELETE pf FROM `pages_fields` pf
            JOIN `pages` p ON p.`id` = pf.`id_pages`
            WHERE p.`keyword` IN ({$list})
        SQL);
        $this->addSql(<<<SQL
            DELETE FROM `pages` WHERE `keyword` IN ({$list})
        SQL);
    }

    // ----------------------------------------------------------------------
    // Step 4 — write `sections.css` directly on the matching section rows.
    // ----------------------------------------------------------------------
    private function applyCssClasses(): void
    {
        foreach (self::CSS_BY_SECTION as $sectionName => $cssClasses) {
            $escName = $this->escape($sectionName);
            $escCss = $this->escape($cssClasses);
            $this->addSql("UPDATE `sections` SET `css` = '{$escCss}' WHERE `name` = '{$escName}'");
        }
    }

    private function clearCssClasses(): void
    {
        foreach (array_keys(self::CSS_BY_SECTION) as $sectionName) {
            $escName = $this->escape($sectionName);
            $this->addSql("UPDATE `sections` SET `css` = '' WHERE `name` = '{$escName}'");
        }
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------
    private function quoteList(array $values): string
    {
        return implode(',', array_map(fn(string $v) => "'" . $this->escape($v) . "'", $values));
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
