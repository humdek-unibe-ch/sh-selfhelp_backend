<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the CMS error-surface styles and wire them onto the existing system
 * pages so the CMS renders the same content the frontend ships as fallbacks.
 *
 * Adds three styles in the auth style group (resolved from the `login` style
 * so no group id is hardcoded), mirroring how `login`/`register` are wired:
 *   - noAccess  — access-denied surface (403)
 *   - missing   — page-not-found surface (200, addressable)
 *   - notFound  — global 404 surface (registered for completeness; no page)
 *
 * The field-name contract MUST stay in sync with @selfhelp/shared and the
 * frontend components (NoAccessStyle.tsx / MissingStyle.tsx / NotFoundStyle):
 *   title, message, button_label, login_label, show_login, show_icon,
 *   mantine_color, mantine_radius, mantine_shadow, mantine_button_variant.
 *
 * `title`, `mantine_color`, `mantine_radius` and `mantine_shadow` already
 * exist and are reused. The remaining fields are created if missing.
 *
 * These pages previously rendered a hand-built fallback tree assembled from
 * primitive styles (container > paper > theme-icon + title + text + button),
 * seeded as `missing-sys-%`, `noaccess-sys-%` and `noaccessguest-sys-%` by
 * Version20260501000800. up() first removes those superseded trees so each
 * page renders exactly one section with its dedicated error style instead of
 * two stacked surfaces.
 *
 * One section is then seeded per existing system page (no_access /
 * no_access_guest / missing). The frontend self-wraps the style in
 * Container + Paper, so no wrapper container section is created. Section
 * content is stored under language id 1 (the language-independent base that
 * the renderer always merges in via the property-translation pass), so it
 * renders for every requested language without needing per-language rows.
 *
 * Idempotent: the legacy cleanup is a DELETE by name pattern, styles/fields/
 * links use INSERT IGNORE on their unique keys, and the new sections are
 * guarded with NOT EXISTS, so re-running is a no-op.
 *
 * Pre-1.0.0: breaking changes are acceptable.
 */
final class Version20260605134800 extends AbstractMigration
{
    /** Section names created by this migration (used by up guards + down cleanup). */
    private const SECTION_NO_ACCESS       = 'no-access-sys';
    private const SECTION_NO_ACCESS_GUEST = 'no-access-guest-sys';
    private const SECTION_MISSING         = 'missing-sys';

    public function getDescription(): string
    {
        return 'Seed noAccess/missing/notFound CMS styles + fields and wire sections onto the no_access, no_access_guest and missing system pages.';
    }

    public function up(Schema $schema): void
    {
        $this->removeLegacyErrorSections();
        $this->createFields();
        $this->createStyles();
        $this->linkFields();
        $this->seedSections();
    }

    public function down(Schema $schema): void
    {
        // Remove the seeded sections (cascades rel_pages_sections and
        // sections_fields_translation via FK ON DELETE CASCADE). The
        // superseded primitive fallback trees (`*-sys-%`) removed in up()
        // are intentionally NOT restored — they were replaced by the
        // dedicated error styles (pre-1.0.0, deliberate).
        $names = sprintf(
            "'%s', '%s', '%s'",
            self::SECTION_NO_ACCESS,
            self::SECTION_NO_ACCESS_GUEST,
            self::SECTION_MISSING
        );
        $this->addSql("DELETE FROM `sections` WHERE `name` IN ({$names})");

        // Remove the style-field links and then the three styles. Shared
        // fields (title, message, mantine_*, ...) are intentionally kept as
        // they may be used by other styles.
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            WHERE s.`name` IN ('noAccess', 'missing', 'notFound')
        SQL);

        $this->addSql("DELETE FROM `styles` WHERE `name` IN ('noAccess', 'missing', 'notFound')");
    }

    // ------------------------------------------------------------------
    // up() steps
    // ------------------------------------------------------------------

    /**
     * Remove the superseded primitive fallback section trees for the three
     * error pages (seeded by Version20260501000800). Deleting the section
     * rows cascades their page links, hierarchy links and field translations
     * via FK ON DELETE CASCADE. The patterns carry a trailing hyphen so the
     * new `missing-sys` / `no_access-sys` / `no_access_guest-sys` sections are
     * never matched.
     */
    private function removeLegacyErrorSections(): void
    {
        $this->addSql(<<<SQL
            DELETE FROM `sections`
            WHERE `name` LIKE 'missing-sys-%'
               OR `name` LIKE 'noaccess-sys-%'
               OR `name` LIKE 'noaccessguest-sys-%'
        SQL);
    }

    /**
     * Create the fields that do not already exist. `title`, `mantine_color`,
     * `mantine_radius` and `mantine_shadow` are reused, not recreated.
     */
    private function createFields(): void
    {
        // Translatable content fields (display = 1).
        $this->insertField('message', 'markdown', display: 1);
        $this->insertField('button_label', 'text', display: 1);
        $this->insertField('login_label', 'text', display: 1);

        // Property fields (display = 0).
        $this->insertField('show_icon', 'checkbox', display: 0);
        $this->insertField('show_login', 'checkbox', display: 0);

        // Mantine button variant select, mirroring the existing
        // `mantine_variant` option set.
        $variantConfig = '{"options": [{"text": "Filled", "value": "filled"}, {"text": "Light", "value": "light"}, {"text": "Outline", "value": "outline"}, {"text": "Subtle", "value": "subtle"}, {"text": "Default", "value": "default"}, {"text": "Transparent", "value": "transparent"}, {"text": "White", "value": "white"}], "clearable": false, "searchable": false}';
        $this->insertField('mantine_button_variant', 'select', display: 0, config: $variantConfig);
    }

    /**
     * Register the three styles in the same style group as `login`.
     */
    private function createStyles(): void
    {
        $this->insertStyle('noAccess', 'Access-denied page surface (403).');
        $this->insertStyle('missing', 'Page-not-found surface (200, addressable).');
        $this->insertStyle('notFound', 'Global 404 surface.');
    }

    /**
     * Link fields to styles with per-style defaults, help text and labels.
     */
    private function linkFields(): void
    {
        // noAccess.
        $this->linkField('noAccess', 'title', '', 'Title', 'Main heading shown on the surface.');
        $this->linkField('noAccess', 'message', '', 'Message', 'Supporting text shown below the title.');
        $this->linkField('noAccess', 'button_label', '', 'Button label', 'Label for the primary action button (links back to home).');
        $this->linkField('noAccess', 'login_label', '', 'Login label', 'Label for the sign-in button (shown when "Show login" is on).');
        $this->linkField('noAccess', 'show_login', '0', 'Show login button', 'Show the sign-in button (used for the guest access-denied surface).');
        $this->linkField('noAccess', 'show_icon', '1', 'Show icon', 'Show the large status icon.');
        $this->linkField('noAccess', 'mantine_color', 'red', 'Color', 'Mantine theme color.');
        $this->linkField('noAccess', 'mantine_radius', 'md', 'Radius', 'Mantine border radius.');
        $this->linkField('noAccess', 'mantine_shadow', '', 'Shadow', 'Mantine shadow size.');
        $this->linkField('noAccess', 'mantine_button_variant', 'light', 'Button variant', 'Mantine button variant.');

        // missing.
        $this->linkField('missing', 'title', '', 'Title', 'Main heading shown on the surface.');
        $this->linkField('missing', 'message', '', 'Message', 'Supporting text shown below the title.');
        $this->linkField('missing', 'button_label', '', 'Button label', 'Label for the primary action button (links back to home).');
        $this->linkField('missing', 'show_icon', '1', 'Show icon', 'Show the large status icon.');
        $this->linkField('missing', 'mantine_color', 'gray', 'Color', 'Mantine theme color.');
        $this->linkField('missing', 'mantine_radius', 'md', 'Radius', 'Mantine border radius.');
        $this->linkField('missing', 'mantine_shadow', '', 'Shadow', 'Mantine shadow size.');
        $this->linkField('missing', 'mantine_button_variant', 'filled', 'Button variant', 'Mantine button variant.');

        // notFound. This style is registered for completeness but never
        // attached to a page (the global Next.js 404 keeps the FE fallback),
        // so the frontend default copy is carried as the style-field default
        // value instead of in a seeded section.
        $this->linkField('notFound', 'title', '', 'Title', 'Main heading shown on the surface.');
        $this->linkField('notFound', 'message', 'The page you are looking for does not exist or has been moved.', 'Message', 'Supporting text shown below the title.');
        $this->linkField('notFound', 'button_label', 'Back to home', 'Button label', 'Label for the primary action button (links back to home).');
        $this->linkField('notFound', 'login_label', 'Sign in', 'Login label', 'Label for the sign-in button (shown when "Show login" is on).');
        $this->linkField('notFound', 'show_icon', '1', 'Show icon', 'Show the large status icon.');
        $this->linkField('notFound', 'mantine_color', 'gray', 'Color', 'Mantine theme color.');
        $this->linkField('notFound', 'mantine_radius', 'md', 'Radius', 'Mantine border radius.');
        $this->linkField('notFound', 'mantine_shadow', '', 'Shadow', 'Mantine shadow size.');
        $this->linkField('notFound', 'mantine_button_variant', 'light', 'Button variant', 'Mantine button variant.');
    }

    /**
     * Create one section per existing system page and seed its content.
     * notFound is intentionally not attached to any page.
     */
    private function seedSections(): void
    {
        // no_access (logged-in, lacks permission).
        $this->insertSection(self::SECTION_NO_ACCESS, 'noAccess', 'no_access');
        $this->insertContent(self::SECTION_NO_ACCESS, 'title', 'Access denied');
        $this->insertContent(self::SECTION_NO_ACCESS, 'message', 'Your account does not have permission to view this page. If you think this is a mistake, please contact the research team or your administrator.');
        $this->insertContent(self::SECTION_NO_ACCESS, 'button_label', 'Back to home');
        $this->insertContent(self::SECTION_NO_ACCESS, 'show_login', '0');

        // no_access_guest (not logged in) — shows the sign-in button.
        $this->insertSection(self::SECTION_NO_ACCESS_GUEST, 'noAccess', 'no_access_guest');
        $this->insertContent(self::SECTION_NO_ACCESS_GUEST, 'title', 'Access denied');
        $this->insertContent(self::SECTION_NO_ACCESS_GUEST, 'message', 'You need to be logged in to view this page. Please sign in to continue.');
        $this->insertContent(self::SECTION_NO_ACCESS_GUEST, 'button_label', 'Back to home');
        $this->insertContent(self::SECTION_NO_ACCESS_GUEST, 'login_label', 'Sign in');
        $this->insertContent(self::SECTION_NO_ACCESS_GUEST, 'show_login', '1');

        // missing (page not found, addressable).
        $this->insertSection(self::SECTION_MISSING, 'missing', 'missing');
        $this->insertContent(self::SECTION_MISSING, 'title', 'Page not found');
        $this->insertContent(self::SECTION_MISSING, 'message', 'The page you are looking for does not exist or has been moved. Please check the URL or head back to the home page.');
        $this->insertContent(self::SECTION_MISSING, 'button_label', 'Back to home');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertField(string $name, string $fieldType, int $display, ?string $config = null): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT :name, ft.id, :display, :config
            FROM `field_types` ft
            WHERE ft.`name` = :fieldType
        SQL, [
            'name'      => $name,
            'display'   => $display,
            'config'    => $config,
            'fieldType' => $fieldType,
        ]);
    }

    private function insertStyle(string $name, string $description): void
    {
        // id_style_groups is copied from `login` so the auth group id is not
        // hardcoded.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `styles` (`name`, `id_style_groups`, `description`, `can_have_children`)
            SELECT :name, login.id_style_groups, :description, 0
            FROM `styles` login
            WHERE login.`name` = 'login'
        SQL, [
            'name'        => $name,
            'description' => $description,
        ]);
    }

    private function linkField(string $style, string $field, string $defaultValue, string $title, string $help): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT s.id, f.id, :defaultValue, :help, 0, 0, :title
            FROM `styles` s, `fields` f
            WHERE s.`name` = :style AND f.`name` = :field
        SQL, [
            'defaultValue' => $defaultValue,
            'help'         => $help,
            'title'        => $title,
            'style'        => $style,
            'field'        => $field,
        ]);
    }

    private function insertSection(string $section, string $style, string $pageKeyword): void
    {
        // Guarded insert keeps the migration idempotent (sections.name has no
        // unique constraint).
        $this->addSql(<<<SQL
            INSERT INTO `sections` (`id_styles`, `name`)
            SELECT s.id, :section
            FROM `styles` s
            WHERE s.`name` = :style
              AND NOT EXISTS (SELECT 1 FROM `sections` x WHERE x.`name` = :section)
        SQL, [
            'section' => $section,
            'style'   => $style,
        ]);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
            SELECT p.id, sec.id, 10
            FROM `pages` p, `sections` sec
            WHERE p.`keyword` = :pageKeyword AND sec.`name` = :section
        SQL, [
            'pageKeyword' => $pageKeyword,
            'section'     => $section,
        ]);
    }

    private function insertContent(string $section, string $field, string $content): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `sections_fields_translation`
                (`id_sections`, `id_fields`, `id_languages`, `content`)
            SELECT sec.id, f.id, 1, :content
            FROM `sections` sec, `fields` f
            WHERE sec.`name` = :section AND f.`name` = :field
        SQL, [
            'content' => $content,
            'section' => $section,
            'field'   => $field,
        ]);
    }
}
