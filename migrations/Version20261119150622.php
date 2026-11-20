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
 * F237: importing sections into a page or a section and restoring sections
 * from a version change a page, so they need `admin.page.update`, not
 * `admin.page.export` (decided 2026-11-19, user: "Yes, require update").
 * A route accepts any one of its permissions, so export is unlinked rather
 * than kept beside update. The services still check the update grant on the
 * page.
 *
 * Data-only — generated with `doctrine:migrations:generate` (no schema diff).
 */
final class Version20261119150622 extends AbstractMigration
{
    private const ROUTES = "'admin_sections_import_to_page', 'admin_sections_import_to_section', 'admin_sections_restore_from_version'";

    public function getDescription(): string
    {
        return 'Section import and restore routes need admin.page.update instead of admin.page.export (F237)';
    }

    public function up(Schema $schema): void
    {
        $this->swap('admin.page.export', 'admin.page.update');
    }

    public function down(Schema $schema): void
    {
        $this->swap('admin.page.update', 'admin.page.export');
    }

    private function swap(string $from, string $to): void
    {
        $this->addSql(
            'DELETE rap FROM rel_api_routes_permissions rap'
            . ' INNER JOIN api_routes ar ON ar.id = rap.id_api_routes'
            . ' INNER JOIN permissions p ON p.id = rap.id_permissions'
            . ' WHERE ar.route_name IN (' . self::ROUTES . ')'
            . ' AND p.name = ?',
            [$from]
        );
        $this->addSql(
            'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)'
            . ' SELECT ar.id, p.id FROM api_routes ar'
            . ' JOIN permissions p ON p.name = ?'
            . ' WHERE ar.route_name IN (' . self::ROUTES . ')',
            [$to]
        );
    }
}
