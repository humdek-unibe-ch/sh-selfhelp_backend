<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702145030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop navigation_menu_item_exclusions, remove convert/exclusion API routes, reset menu items to manual child source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions DROP FOREIGN KEY `FK_8C8A2DD36A8744E7`');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions DROP FOREIGN KEY `FK_8C8A2DD3CEF1A445`');
        $this->addSql('DROP TABLE navigation_menu_item_exclusions');

        $this->addSql(<<<'SQL'
            UPDATE navigation_menu_items nmi
            INNER JOIN lookups cs ON cs.id = nmi.id_child_source
            SET nmi.id_child_source = (
                SELECT id FROM lookups
                WHERE type_code = 'navigationChildSources' AND lookup_code = 'manual'
                LIMIT 1
            ),
            nmi.auto_include_depth = NULL
            WHERE cs.lookup_code IN ('page_children', 'manual_plus_suggestions')
            SQL);

        foreach ([
            'admin_navigation_item_exclusion_add',
            'admin_navigation_item_exclusion_remove',
            'admin_navigation_item_convert_auto_children',
        ] as $routeCode) {
            $this->addSql(
                'DELETE FROM rel_api_routes_permissions WHERE id_api_routes IN (SELECT id FROM api_routes WHERE route_name = ?)',
                [$routeCode],
            );
            $this->addSql('DELETE FROM api_routes WHERE route_name = ?', [$routeCode]);
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE navigation_menu_item_exclusions (id INT AUTO_INCREMENT NOT NULL, id_navigation_menu_items INT NOT NULL, id_pages INT NOT NULL, INDEX IDX_8C8A2DD36A8744E7 (id_navigation_menu_items), INDEX IDX_8C8A2DD3CEF1A445 (id_pages), UNIQUE INDEX uq_navigation_menu_item_exclusions_item_page (id_navigation_menu_items, id_pages), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT `FK_8C8A2DD36A8744E7` FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT `FK_8C8A2DD3CEF1A445` FOREIGN KEY (id_pages) REFERENCES pages (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
