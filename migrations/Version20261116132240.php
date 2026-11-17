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
 * Raise the seeded web_header root item_limit from 5 to 8.
 *
 * Only rows still on the old seed value (5) are updated, so a menu an admin
 * already customized stays as they set it. Footer/drawer stay unlimited;
 * mobile_bottom_tabs stays at 5.
 */
final class Version20261116132240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bump web_header navigation_menus.item_limit from seed 5 to 8 when still at 5';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE navigation_menus nm
            INNER JOIN lookups mk
                ON mk.id = nm.id_navigation_menu_key
               AND mk.type_code = 'navigationMenuKeys'
               AND mk.lookup_code = 'web_header'
            SET nm.item_limit = 8
            WHERE nm.item_limit = 5
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE navigation_menus nm
            INNER JOIN lookups mk
                ON mk.id = nm.id_navigation_menu_key
               AND mk.type_code = 'navigationMenuKeys'
               AND mk.lookup_code = 'web_header'
            SET nm.item_limit = 5
            WHERE nm.item_limit = 8
        SQL);
    }
}
