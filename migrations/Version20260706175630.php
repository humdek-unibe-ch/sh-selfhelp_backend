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
 * Branch pager toggle + header branding + headless register page:
 *
 * - navigation_menus: add `show_pager` (menu-level default for the prev/next
 *   pager on nested web pages, default on) — independent of the sidebar mode.
 * - navigation_menu_items: add nullable `show_pager` (per-parent-item override
 *   of the menu default; NULL = inherit).
 * - navigation_settings: add branding — `logo_asset_path` (public path of the
 *   header/drawer logo from the asset library), `logo_alt` (accessible brand
 *   text, also the text fallback), `id_logo_link_page` (logo click target;
 *   NULL = home page).
 * - pages: the `register` system page becomes headless, matching `login`, so
 *   the auth card renders full-screen without the site chrome.
 */
final class Version20260706175630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pager toggle on menus/items, logo branding in navigation settings, headless register page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_items ADD show_pager TINYINT DEFAULT NULL');
        $this->addSql('ALTER TABLE navigation_menus ADD show_pager TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD logo_asset_path VARCHAR(500) DEFAULT NULL, ADD logo_alt VARCHAR(255) DEFAULT NULL, ADD id_logo_link_page INT DEFAULT NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024DF7CBDF35 FOREIGN KEY (id_logo_link_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2C0F024DF7CBDF35 ON navigation_settings (id_logo_link_page)');

        $this->addSql("UPDATE pages SET is_headless = 1 WHERE keyword = 'register'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_items DROP show_pager');
        $this->addSql('ALTER TABLE navigation_menus DROP show_pager');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024DF7CBDF35');
        $this->addSql('DROP INDEX IDX_2C0F024DF7CBDF35 ON navigation_settings');
        $this->addSql('ALTER TABLE navigation_settings DROP logo_asset_path, DROP logo_alt, DROP id_logo_link_page');

        $this->addSql("UPDATE pages SET is_headless = 0 WHERE keyword = 'register'");
    }
}
