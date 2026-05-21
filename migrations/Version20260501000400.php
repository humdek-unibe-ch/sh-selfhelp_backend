<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/LegacySeedTrait.php';

/**
 * Seed migration: system pages, sections, page ACLs, page-level fields.
 *
 * Materializes every system page the frontend resolves at boot
 * (privacy, login, two-factor-authentication, reset_password, validate,
 * profile + profile-link + logout, home, missing, no_access,
 * no_access_guest, agb, impressum, disclaimer, sh-global-css,
 * sh-global-values, sh-cms-preferences) plus their sections and the
 * default group ACL rows (admin/therapist/subject).
 *
 * This migration folds in the four legacy system-page migrations
 * (`Version20260425090000`, `Version20260425100000`,
 * `Version20260425100100`, `Version20260425110000`) by sourcing the
 * data from `db/legacy/new_create_db.sql` which already contains the
 * post-migration state of those rows.
 *
 * Depends on:
 *   - Version20260601000000_CanonicalBaseline (schema)
 *   - Version20260601000100_SeedReferenceData (page_types, groups)
 *   - Version20260601000200_SeedFieldsAndStyles (fields, styles, rel_fields_styles)
 *   - Version20260601000300_SeedApiRoutes (no direct dep, but ordering is convention)
 */
final class Version20260601000400 extends AbstractMigration
{
    use LegacySeedTrait;

    public function getDescription(): string
    {
        return 'Seed system pages, sections, page field translations, page ACLs.';
    }

    public function up(Schema $schema): void
    {
        $this->seedFromLegacy('pages');
        $this->seedFromLegacy('pages_fields');
        $this->seedFromLegacy('sections');
        $this->seedFromLegacy('pages_sections');
        $this->seedFromLegacy('sections_hierarchy');
        $this->seedFromLegacy('sections_navigation');
        $this->seedFromLegacy('sections_fields_translation');
        $this->seedFromLegacy('pages_fields_translation');
        $this->seedFromLegacy('acl_groups');

        // The legacy `new_create_db.sql` ships these system pages with
        // is_open_access=0. In every running install they are flipped to 1
        // through the admin UI because they MUST be reachable without a
        // session (login form, password reset, 2FA challenge, the 404 /
        // 403 pages themselves, legal pages, etc.). We materialize that
        // post-install state here so a brand-new clean install behaves the
        // same way the existing prod databases do.
        $openAccessKeywords = [
            'login',
            'home',
            'missing',
            'no_access',
            'no_access_guest',
            'agb',
            'impressum',
            'disclaimer',
            'validate',
            'reset_password',
            'two-factor-authentication',
        ];
        $list = "'" . implode("','", $openAccessKeywords) . "'";
        $this->addSql("UPDATE `pages` SET `is_open_access` = 1 WHERE `keyword` IN ($list)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `page_acl_groups`');
        $this->addSql('DELETE FROM `pages_fields_translation`');
        $this->addSql('DELETE FROM `sections_fields_translation`');
        $this->addSql('DELETE FROM `rel_sections_navigation`');
        $this->addSql('DELETE FROM `rel_sections_hierarchy`');
        $this->addSql('DELETE FROM `rel_pages_sections`');
        $this->addSql('DELETE FROM `sections`');
        $this->addSql('DELETE FROM `rel_fields_pages`');
        $this->addSql('DELETE FROM `pages`');
    }
}
