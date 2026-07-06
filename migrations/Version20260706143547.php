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
 * Web children-navigation presentation (menu branch UX):
 *
 * - lookups: seed the `navigationChildrenNavModes` type (`sidebar` / `pills` /
 *   `none`) — how a web page presents its menu branch (children/siblings).
 * - navigation_menus: add `id_children_nav` (menu-level default; NULL resolves
 *   to the platform default `sidebar`) and `show_breadcrumbs` (breadcrumb trail
 *   above nested web pages, default on).
 * - navigation_menu_items: add `id_children_nav` (per-parent-item override of
 *   the menu default).
 */
final class Version20260706143547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Children navigation modes: lookup seed + navigation_menus children_nav/show_breadcrumbs + per-item override.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildrenNavModes', 'sidebar', 'Left sidebar', 'Branch pages render a left sidebar with the parent and its children plus a prev/next pager'],
        );
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildrenNavModes', 'pills', 'Top pill strip', 'Branch pages render a compact horizontal pill strip above the content'],
        );
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildrenNavModes', 'none', 'Hidden', 'Branch pages render only their own content without generated child navigation'],
        );

        $this->addSql('ALTER TABLE navigation_menu_items ADD id_children_nav INT DEFAULT NULL');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E73FA06825 FOREIGN KEY (id_children_nav) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BB30D3E73FA06825 ON navigation_menu_items (id_children_nav)');
        $this->addSql('ALTER TABLE navigation_menus ADD show_breadcrumbs TINYINT DEFAULT 1 NOT NULL, ADD id_children_nav INT DEFAULT NULL');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A3FA06825 FOREIGN KEY (id_children_nav) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CC49980A3FA06825 ON navigation_menus (id_children_nav)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E73FA06825');
        $this->addSql('DROP INDEX IDX_BB30D3E73FA06825 ON navigation_menu_items');
        $this->addSql('ALTER TABLE navigation_menu_items DROP id_children_nav');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY FK_CC49980A3FA06825');
        $this->addSql('DROP INDEX IDX_CC49980A3FA06825 ON navigation_menus');
        $this->addSql('ALTER TABLE navigation_menus DROP show_breadcrumbs, DROP id_children_nav');

        $this->addSql("DELETE FROM lookups WHERE type_code = 'navigationChildrenNavModes'");
    }
}
