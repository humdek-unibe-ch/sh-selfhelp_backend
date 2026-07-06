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
 * Navigation menu system final cleanup (no backward compatibility):
 *
 * - navigation_menu_items: add `layer` (top header row assignment for
 *   web_header root items), drop the removed virtual-children relics
 *   `id_child_source` + `auto_include_depth`.
 * - navigation_menus: drop the free-form `config` JSON column. The footer
 *   layout becomes a preset: `columns` / `inline` are seeded into the
 *   `navigationMenuPresets` lookup type and the previous
 *   `config.footer_layout` value is carried over to `id_preset` for
 *   web_footer (default `columns`).
 * - lookups: delete the unused `navigationChildSources` lookup rows.
 * - navigation_menu_item_translations: repair drift from
 *   `Version20260702161555`, which re-created the table without
 *   `ENGINE=InnoDB` — on MyISAM-default servers the FK definitions were
 *   silently dropped. Idempotent on healthy instances.
 */
final class Version20260706074503 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Navigation cleanup: item layer column; drop child-source/auto-include/config; footer layout as preset lookups; repair translations table engine/FKs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_item_translations ENGINE = InnoDB');
        if (!$this->foreignKeyExists('navigation_menu_item_translations', 'FK_E701902C6A8744E7')) {
            $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C6A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        }
        if (!$this->foreignKeyExists('navigation_menu_item_translations', 'FK_E701902C20E4EF5E')) {
            $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C20E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');
        }

        // Footer presets join the same lookup type the header presets use.
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationMenuPresets', 'columns', 'Columns (grouped)', 'Footer columns built from group headings'],
        );
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationMenuPresets', 'inline', 'Inline links', 'Flat centered footer link row'],
        );

        // Carry config.footer_layout over to the preset before config is dropped;
        // every web_footer menu ends up with an explicit preset (default columns).
        $this->addSql(<<<'SQL'
            UPDATE navigation_menus nm
            JOIN lookups mk ON mk.id = nm.id_navigation_menu_key
                AND mk.type_code = 'navigationMenuKeys' AND mk.lookup_code = 'web_footer'
            SET nm.id_preset = (
                SELECT p.id FROM lookups p
                WHERE p.type_code = 'navigationMenuPresets'
                  AND p.lookup_code = IF(
                      JSON_UNQUOTE(JSON_EXTRACT(nm.config, '$.footer_layout')) = 'inline',
                      'inline',
                      'columns'
                  )
                LIMIT 1
            )
            SQL);

        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY `FK_BB30D3E7151ECE5D`');
        $this->addSql('DROP INDEX IDX_BB30D3E7151ECE5D ON navigation_menu_items');
        $this->addSql('ALTER TABLE navigation_menu_items ADD layer VARCHAR(16) DEFAULT NULL, DROP auto_include_depth, DROP id_child_source');
        $this->addSql('ALTER TABLE navigation_menus DROP config');

        // The virtual-children feature is gone; its lookup group goes with it.
        $this->addSql("DELETE FROM lookups WHERE type_code = 'navigationChildSources'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildSources', 'manual', 'Manual', 'Only explicit child menu items'],
        );
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildSources', 'page_children', 'Page children', 'Auto-include page tree children'],
        );
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
            ['navigationChildSources', 'manual_plus_suggestions', 'Manual plus suggestions', 'Manual children with builder suggestions'],
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE navigation_menu_items
                ADD auto_include_depth INT DEFAULT NULL,
                ADD id_child_source INT NULL,
                DROP layer
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE navigation_menu_items
            SET id_child_source = (
                SELECT id FROM lookups
                WHERE type_code = 'navigationChildSources' AND lookup_code = 'manual'
                LIMIT 1
            )
            SQL);
        $this->addSql('ALTER TABLE navigation_menu_items MODIFY id_child_source INT NOT NULL');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT `FK_BB30D3E7151ECE5D` FOREIGN KEY (id_child_source) REFERENCES lookups (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_BB30D3E7151ECE5D ON navigation_menu_items (id_child_source)');

        $this->addSql('ALTER TABLE navigation_menus ADD config JSON DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE navigation_menus nm
            JOIN lookups mk ON mk.id = nm.id_navigation_menu_key
                AND mk.type_code = 'navigationMenuKeys' AND mk.lookup_code = 'web_footer'
            LEFT JOIN lookups p ON p.id = nm.id_preset
            SET nm.config = JSON_OBJECT('footer_layout', COALESCE(p.lookup_code, 'columns')),
                nm.id_preset = NULL
            SQL);

        $this->addSql("DELETE FROM lookups WHERE type_code = 'navigationMenuPresets' AND lookup_code IN ('columns', 'inline')");
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY'],
        );

        return (int) $count > 0;
    }
}
