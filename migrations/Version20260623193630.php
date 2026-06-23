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
 * Seed the `admin.mobile_preview.view` permission (granted to the admin role).
 *
 * This is the dedicated entitlement for the full-screen CMS **Live Preview**
 * surface (the new-tab, free-navigation mobile/web preview). It is intentionally
 * SEPARATE from `admin.mobile_preview.create` (which gates minting a one-time
 * preview code in {@see Version20260623121051}): a role can be allowed to OPEN
 * the live preview UI (`view`) and to mint sessions (`create`) independently,
 * and the frontend gates the `/admin/preview` route + the editor "Open live
 * preview" entry on `view`. No api_route is added here — the live preview reuses
 * the existing mint (`create`) + public exchange routes; this permission only
 * surfaces in the admin's user-data `permissions[]` so the UI can gate on it.
 *
 * Follows the additive permission-seeding pattern of Version20260623121051. No
 * schema change.
 */
final class Version20260623193630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the admin.mobile_preview.view permission (full-screen CMS Live Preview entitlement), granted to the admin role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES ('admin.mobile_preview.view', 'Can open the full-screen CMS mobile/web live preview')");
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`)
            SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin'
            WHERE p.name = 'admin.mobile_preview.view'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name = 'admin.mobile_preview.view'");
        $this->addSql("DELETE FROM `permissions` WHERE name = 'admin.mobile_preview.view'");
    }
}
