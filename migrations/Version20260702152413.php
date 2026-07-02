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
 * Move menu icons and labels onto navigation_menu_items; drop item translations.
 */
final class Version20260702152413 extends AbstractMigration
{
    private const PAGE_ICON_FIELDS = ['icon', 'mobile_icon'];

    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'Navigation menu items: store icon/mobile_icon/label on items, migrate data, '
            . 'drop navigation_menu_item_translations, remove page icon fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_items ADD mobile_icon VARCHAR(100) DEFAULT NULL, ADD label VARCHAR(255) DEFAULT NULL');

        $this->addSql(
            'UPDATE navigation_menu_items nmi
             INNER JOIN navigation_menu_item_translations t ON t.id_navigation_menu_items = nmi.id
             INNER JOIN lookups it ON it.id = nmi.id_item_type
             SET nmi.label = t.label
             WHERE t.id_languages = 1
               AND t.label IS NOT NULL AND t.label <> \'\'
               AND it.lookup_code IN (\'external_url\', \'group\')
               AND (nmi.label IS NULL OR nmi.label = \'\')'
        );

        $this->addSql(
            'UPDATE navigation_menu_items nmi
             INNER JOIN pages_fields_translation pft ON pft.id_pages = nmi.id_pages
             INNER JOIN fields f ON f.id = pft.id_fields AND f.name = \'icon\'
             SET nmi.icon_override = pft.content
             WHERE nmi.id_pages IS NOT NULL
               AND (nmi.icon_override IS NULL OR nmi.icon_override = \'\')
               AND pft.content IS NOT NULL AND pft.content <> \'\''
        );

        $this->addSql(
            'UPDATE navigation_menu_items nmi
             INNER JOIN pages_fields_translation pft ON pft.id_pages = nmi.id_pages
             INNER JOIN fields f ON f.id = pft.id_fields AND f.name = \'mobile_icon\'
             SET nmi.mobile_icon = pft.content
             WHERE nmi.id_pages IS NOT NULL
               AND (nmi.mobile_icon IS NULL OR nmi.mobile_icon = \'\')
               AND pft.content IS NOT NULL AND pft.content <> \'\''
        );

        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY `FK_E701902C20E4EF5E`');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY `FK_E701902C6A8744E7`');
        $this->addSql('DROP TABLE navigation_menu_item_translations');

        $this->addSql('ALTER TABLE navigation_menu_items CHANGE icon_override icon VARCHAR(100) DEFAULT NULL');

        foreach (self::PAGE_ICON_FIELDS as $field) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$field]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$field]);
        }

        $this->addSql('DELETE FROM `field_types` WHERE `name` = ?', ['select-icon-mobile']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('INSERT IGNORE INTO `field_types` (`name`, `position`) VALUES (\'select-icon-mobile\', 0)');

        $this->addSql(
            'INSERT IGNORE INTO `fields` (`name`, id_field_types, `display`)
             SELECT \'icon\', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = \'select-icon\''
        );
        $this->addSql(
            'INSERT IGNORE INTO `fields` (`name`, id_field_types, `display`)
             SELECT \'mobile_icon\', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = \'select-icon-mobile\''
        );

        foreach (['core', 'experiment'] as $pageType) {
            foreach (['icon' => ['Menu icon (web)', 'Icon shown next to this page in the website menu.'], 'mobile_icon' => ['Menu icon (mobile)', 'Icon shown next to this page in the mobile app menu.']] as $field => [$title, $help]) {
                $this->addSql(
                    'INSERT IGNORE INTO `rel_fields_page_types` (id_page_types, id_fields, title, help)
                     SELECT pt.id, f.id, ?, ?
                     FROM `page_types` pt, `fields` f
                     WHERE pt.`name` = ? AND f.`name` = ?',
                    [$title, $help, $pageType, $field]
                );
            }
        }

        $this->addSql('CREATE TABLE navigation_menu_item_translations (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) DEFAULT NULL, description VARCHAR(500) DEFAULT NULL, aria_label VARCHAR(255) DEFAULT NULL, id_navigation_menu_items INT NOT NULL, id_languages INT NOT NULL, INDEX IDX_E701902C20E4EF5E (id_languages), INDEX IDX_E701902C6A8744E7 (id_navigation_menu_items), UNIQUE INDEX uq_navigation_menu_item_translations_item_lang (id_navigation_menu_items, id_languages), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT `FK_E701902C20E4EF5E` FOREIGN KEY (id_languages) REFERENCES languages (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT `FK_E701902C6A8744E7` FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON UPDATE NO ACTION ON DELETE CASCADE');

        $this->addSql('ALTER TABLE navigation_menu_items ADD icon_override VARCHAR(100) DEFAULT NULL, DROP icon, DROP mobile_icon, DROP label');
    }
}
