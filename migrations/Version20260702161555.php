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
 * Restore navigation_menu_item_translations for group/external menu labels.
 */
final class Version20260702161555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore navigation_menu_item_translations and seed default-language labels from navigation_menu_items.label.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE navigation_menu_item_translations (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) DEFAULT NULL, id_navigation_menu_items INT NOT NULL, id_languages INT NOT NULL, INDEX idx_navigation_menu_item_translations_id_navigation_menu_items (id_navigation_menu_items), INDEX idx_navigation_menu_item_translations_id_languages (id_languages), UNIQUE INDEX uq_navigation_menu_item_translations_item_lang (id_navigation_menu_items, id_languages), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C6A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C20E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');

        $this->addSql(
            'INSERT INTO navigation_menu_item_translations (label, id_navigation_menu_items, id_languages)
             SELECT nmi.label, nmi.id, 1
             FROM navigation_menu_items nmi
             INNER JOIN lookups it ON it.id = nmi.id_item_type
             WHERE it.lookup_code IN (\'external_url\', \'group\')
               AND nmi.label IS NOT NULL AND nmi.label <> \'\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY FK_E701902C6A8744E7');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY FK_E701902C20E4EF5E');
        $this->addSql('DROP TABLE navigation_menu_item_translations');
    }
}
