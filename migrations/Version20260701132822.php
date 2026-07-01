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
 * Repair navigation tables created as MyISAM (FK constraints silently skipped) and
 * add missing foreign keys on upgraded instances. No-op when schema already matches.
 */
final class Version20260701132822 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Convert navigation/search tables to InnoDB and add missing FK constraints on upgraded databases.';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'navigation_menus',
            'navigation_menu_items',
            'navigation_menu_item_exclusions',
            'navigation_menu_item_translations',
            'navigation_settings',
            'user_navigation_state',
            'page_search_index',
        ] as $table) {
            if ($this->tableExists($table)) {
                $this->addSql(sprintf('ALTER TABLE %s ENGINE=InnoDB', $table));
            }
        }

        $constraints = [
            ['navigation_menu_item_exclusions', 'FK_8C8A2DD36A8744E7', 'ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT FK_8C8A2DD36A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE'],
            ['navigation_menu_item_exclusions', 'FK_8C8A2DD3CEF1A445', 'ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT FK_8C8A2DD3CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE'],
            ['navigation_menu_item_translations', 'FK_E701902C6A8744E7', 'ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C6A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE'],
            ['navigation_menu_item_translations', 'FK_E701902C20E4EF5E', 'ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C20E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE'],
            ['navigation_menu_items', 'FK_BB30D3E72FC3CDDB', 'ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E72FC3CDDB FOREIGN KEY (id_navigation_menus) REFERENCES navigation_menus (id) ON DELETE CASCADE'],
            ['navigation_menu_items', 'FK_BB30D3E73AEADE5D', 'ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E73AEADE5D FOREIGN KEY (id_parent_item) REFERENCES navigation_menu_items (id) ON DELETE CASCADE'],
            ['navigation_menu_items', 'FK_BB30D3E7C4DBAFF5', 'ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7C4DBAFF5 FOREIGN KEY (id_item_type) REFERENCES lookups (id)'],
            ['navigation_menu_items', 'FK_BB30D3E7CEF1A445', 'ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE'],
            ['navigation_menu_items', 'FK_BB30D3E7151ECE5D', 'ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7151ECE5D FOREIGN KEY (id_child_source) REFERENCES lookups (id)'],
            ['navigation_menus', 'FK_CC49980A9D915BD8', 'ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A9D915BD8 FOREIGN KEY (id_navigation_menu_key) REFERENCES lookups (id)'],
            ['navigation_menus', 'FK_CC49980A69893C5E', 'ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A69893C5E FOREIGN KEY (id_platform) REFERENCES lookups (id)'],
            ['navigation_menus', 'FK_CC49980A62DC371E', 'ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A62DC371E FOREIGN KEY (id_surface) REFERENCES lookups (id)'],
            ['navigation_menus', 'FK_CC49980AA6851DF', 'ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980AA6851DF FOREIGN KEY (id_preset) REFERENCES lookups (id) ON DELETE SET NULL'],
            ['navigation_settings', 'FK_2C0F024D23C60493', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D23C60493 FOREIGN KEY (id_web_header_search_mode) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024D8DB25976', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D8DB25976 FOREIGN KEY (id_search_default_visibility) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024D8B1FC201', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D8B1FC201 FOREIGN KEY (id_search_field_policy) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024DEC3F2CAD', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024DEC3F2CAD FOREIGN KEY (id_web_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL'],
            ['navigation_settings', 'FK_2C0F024D174EA010', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D174EA010 FOREIGN KEY (id_web_user_start_page) REFERENCES pages (id) ON DELETE SET NULL'],
            ['navigation_settings', 'FK_2C0F024D948E519B', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D948E519B FOREIGN KEY (id_web_user_start_mode) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024DB09D1FB2', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024DB09D1FB2 FOREIGN KEY (id_mobile_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL'],
            ['navigation_settings', 'FK_2C0F024D617BBAB', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D617BBAB FOREIGN KEY (id_mobile_user_start_page) REFERENCES pages (id) ON DELETE SET NULL'],
            ['navigation_settings', 'FK_2C0F024D85D74A20', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D85D74A20 FOREIGN KEY (id_mobile_user_start_mode) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024D86EE5D6B', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D86EE5D6B FOREIGN KEY (id_mobile_start_page_source) REFERENCES lookups (id)'],
            ['navigation_settings', 'FK_2C0F024D65234D8B', 'ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D65234D8B FOREIGN KEY (id_route_sync_old_route_policy) REFERENCES lookups (id)'],
            ['page_search_index', 'FK_A5A08A6CEF1A445', 'ALTER TABLE page_search_index ADD CONSTRAINT FK_A5A08A6CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE'],
            ['page_search_index', 'FK_A5A08A620E4EF5E', 'ALTER TABLE page_search_index ADD CONSTRAINT FK_A5A08A620E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE'],
            ['user_navigation_state', 'FK_60BA00E3FA06E4D9', 'ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E3FA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE CASCADE'],
            ['user_navigation_state', 'FK_60BA00E369893C5E', 'ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E369893C5E FOREIGN KEY (id_platform) REFERENCES lookups (id)'],
            ['user_navigation_state', 'FK_60BA00E3CEF1A445', 'ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E3CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE'],
        ];

        foreach ($constraints as [$table, $name, $sql]) {
            if ($this->tableExists($table) && !$this->foreignKeyExists($table, $name)) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $drops = [
            ['navigation_menu_item_exclusions', 'FK_8C8A2DD36A8744E7'],
            ['navigation_menu_item_exclusions', 'FK_8C8A2DD3CEF1A445'],
            ['navigation_menu_item_translations', 'FK_E701902C6A8744E7'],
            ['navigation_menu_item_translations', 'FK_E701902C20E4EF5E'],
            ['navigation_menu_items', 'FK_BB30D3E72FC3CDDB'],
            ['navigation_menu_items', 'FK_BB30D3E73AEADE5D'],
            ['navigation_menu_items', 'FK_BB30D3E7C4DBAFF5'],
            ['navigation_menu_items', 'FK_BB30D3E7CEF1A445'],
            ['navigation_menu_items', 'FK_BB30D3E7151ECE5D'],
            ['navigation_menus', 'FK_CC49980A9D915BD8'],
            ['navigation_menus', 'FK_CC49980A69893C5E'],
            ['navigation_menus', 'FK_CC49980A62DC371E'],
            ['navigation_menus', 'FK_CC49980AA6851DF'],
            ['navigation_settings', 'FK_2C0F024D23C60493'],
            ['navigation_settings', 'FK_2C0F024D8DB25976'],
            ['navigation_settings', 'FK_2C0F024D8B1FC201'],
            ['navigation_settings', 'FK_2C0F024DEC3F2CAD'],
            ['navigation_settings', 'FK_2C0F024D174EA010'],
            ['navigation_settings', 'FK_2C0F024D948E519B'],
            ['navigation_settings', 'FK_2C0F024DB09D1FB2'],
            ['navigation_settings', 'FK_2C0F024D617BBAB'],
            ['navigation_settings', 'FK_2C0F024D85D74A20'],
            ['navigation_settings', 'FK_2C0F024D86EE5D6B'],
            ['navigation_settings', 'FK_2C0F024D65234D8B'],
            ['page_search_index', 'FK_A5A08A6CEF1A445'],
            ['page_search_index', 'FK_A5A08A620E4EF5E'],
            ['user_navigation_state', 'FK_60BA00E3FA06E4D9'],
            ['user_navigation_state', 'FK_60BA00E369893C5E'],
            ['user_navigation_state', 'FK_60BA00E3CEF1A445'],
        ];

        foreach ($drops as [$table, $name]) {
            if ($this->tableExists($table) && $this->foreignKeyExists($table, $name)) {
                $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $name));
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return (int) $count > 0;
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
