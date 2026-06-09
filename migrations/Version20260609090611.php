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
 * Split the single section-DELETE endpoint into two clear operations.
 *
 * Previously the only seeded route was `admin_sections_delete`:
 *   DELETE /admin/pages/{page_id}/sections/{section_id} -> deleteSection
 * which always destroyed the section record. There was no reachable way to
 * merely unlink a shared section (e.g. a refContainer) from one page, so the
 * frontend "remove" and "delete" buttons collapsed onto the same destructive
 * call.
 *
 * After this migration:
 *   - `admin_sections_delete` is repointed to removeSectionFromPage (a pure
 *     detach: drops only this page's link, keeps the section row for its other
 *     usages). Permission relaxed to admin.page.update — detaching is an edit,
 *     not a destroy.
 *   - `admin_sections_destroy` is added:
 *       DELETE /admin/sections/{section_id} -> deleteSection
 *     which permanently destroys the section record on every page that used it.
 *     Permission admin.page.delete.
 */
final class Version20260609090611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split section DELETE into remove-from-page (detach) and destroy (page-independent delete).';
    }

    public function up(Schema $schema): void
    {
        // 1) Repoint the existing route to the detach controller action.
        $this->addSql(<<<SQL
            UPDATE `api_routes`
            SET `controller` = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminPageController::removeSectionFromPage'
            WHERE `route_name` = 'admin_sections_delete' AND `version` = 'v1'
        SQL);

        // Relax its permission from admin.page.delete to admin.page.update.
        $this->addSql(<<<SQL
            UPDATE `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            JOIN `permissions` p ON p.name = 'admin.page.update'
            SET rp.id_permissions = p.id
            WHERE ar.route_name = 'admin_sections_delete' AND ar.version = 'v1'
        SQL);

        // 2) Add the page-independent destroy route.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_sections_destroy',
                'v1',
                '/admin/sections/{section_id}',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionController::deleteSection',
                'DELETE',
                JSON_OBJECT('section_id', '[0-9]+'),
                NULL
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.delete'
            WHERE ar.route_name = 'admin_sections_destroy' AND ar.version = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop the destroy route + its permission link.
        $this->addSql(<<<SQL
            DELETE rp FROM `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            WHERE ar.route_name = 'admin_sections_destroy' AND ar.version = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'admin_sections_destroy' AND `version` = 'v1'");

        // Restore the original controller + permission on admin_sections_delete.
        $this->addSql(<<<SQL
            UPDATE `api_routes`
            SET `controller` = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionController::deleteSection'
            WHERE `route_name` = 'admin_sections_delete' AND `version` = 'v1'
        SQL);
        $this->addSql(<<<SQL
            UPDATE `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            JOIN `permissions` p ON p.name = 'admin.page.delete'
            SET rp.id_permissions = p.id
            WHERE ar.route_name = 'admin_sections_delete' AND ar.version = 'v1'
        SQL);
    }
}
