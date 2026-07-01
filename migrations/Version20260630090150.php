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
 * Issue #30 (CMS-in-CMS): add the lookup-backed `page_surface` axis that
 * separates `public` website pages from `cms` application pages in the admin
 * UI.
 *
 * This is a third, independent axis: `page_type` keeps driving the field schema
 * (always `experiment` for new pages), `is_system` keeps protecting special
 * pages from deletion, and `page_surface` only organizes/ACL-scopes pages.
 *
 * Adds the central-registry lookup group `pageSurface` (`public` | `cms`,
 * core-owned/closed) and a nullable FK `pages.id_page_surface` → `lookups.id`.
 * A NULL FK resolves to `public`; existing pages are backfilled to `public`.
 */
final class Version20260630090150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #30: add lookup group pageSurface (public|cms) and the nullable FK pages.id_page_surface; backfill existing pages to public.';
    }

    public function up(Schema $schema): void
    {
        // 1. Seed the surface values into the central lookup registry (core-owned).
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('pageSurface', 'public', 'Public', 'Public website page rendered on the public frontend under normal page ACL'), "
            . "('pageSurface', 'cms', 'CMS application', 'CMS-in-CMS application page (admin/editor tooling) grouped separately and route-resolved but ACL-gated')"
        );

        // 2. Add the nullable FK column (NULL = default 'public' surface).
        $this->addSql('ALTER TABLE pages ADD id_page_surface INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pages ADD CONSTRAINT fk_pages_id_page_surface FOREIGN KEY (id_page_surface) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_pages_id_page_surface ON pages (id_page_surface)');

        // 3. Backfill: every existing page is a public website page.
        $this->addSql(
            "UPDATE pages p "
            . "JOIN lookups l ON l.type_code = 'pageSurface' AND l.lookup_code = 'public' "
            . "SET p.id_page_surface = l.id"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pages DROP FOREIGN KEY fk_pages_id_page_surface');
        $this->addSql('DROP INDEX idx_pages_id_page_surface ON pages');
        $this->addSql('ALTER TABLE pages DROP id_page_surface');
        $this->addSql("DELETE FROM lookups WHERE type_code = 'pageSurface'");
    }
}
