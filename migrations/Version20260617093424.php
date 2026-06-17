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
 * Remove the obsolete `admin_sections_force_delete_v1` API route.
 *
 * The legacy `new_create_db.sql` snapshot (replayed by the seed migration
 * Version20260501000300 via LegacySeedTrait) still ships a row:
 *   DELETE /admin/pages/{page_id}/sections/{section_id}/force-delete
 *     -> AdminSectionController::forceDeleteSection
 * That controller method no longer exists: the single force-delete endpoint
 * was split into the two clear operations introduced by
 * Version20260609090611 — detach (`removeSectionFromPage`) and destroy
 * (`deleteSection`). The dangling route fails the ApiRouteInventory guardrail
 * ("every DB route resolves to a real controller method"), so this migration
 * drops the stale route and its permission link on fresh installs.
 *
 * down() faithfully restores the legacy route + its `admin.page.delete`
 * permission link so the migration round-trips.
 */
final class Version20260617093424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the obsolete admin_sections_force_delete route (superseded by section detach/destroy).';
    }

    public function up(Schema $schema): void
    {
        // Drop the permission link first, then the dangling route row.
        $this->addSql(<<<SQL
            DELETE rp FROM `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            WHERE ar.route_name = 'admin_sections_force_delete_v1' AND ar.version = 'v1'
        SQL);
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` = 'admin_sections_force_delete_v1' AND `version` = 'v1'"
        );
    }

    public function down(Schema $schema): void
    {
        // Restore the legacy route exactly as the new_create_db.sql snapshot
        // seeded it (controller method intentionally points at the removed
        // forceDeleteSection — this is a faithful reversal of up()).
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_sections_force_delete_v1',
                'v1',
                '/admin/pages/{page_id}/sections/{section_id}/force-delete',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionController::forceDeleteSection',
                'DELETE',
                JSON_OBJECT('page_id', '[0-9]+', 'section_id', '[0-9]+'),
                NULL
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.delete'
            WHERE ar.route_name = 'admin_sections_force_delete_v1' AND ar.version = 'v1'
        SQL);
    }
}
