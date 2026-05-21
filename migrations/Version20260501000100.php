<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/LegacySeedTrait.php';

/**
 * Seed migration: reference data.
 *
 * Loads the small, low-churn reference rows that everything else
 * depends on (lookup categories, languages, field types, style groups,
 * permissions, roles, role/permission links, page types). Sourced
 * directly from `db/legacy/new_create_db.sql` via the LegacySeedTrait
 * with table + column renames applied.
 *
 * Order rationale:
 *   - lookups must exist before fields/styles/page_types/api_routes
 *     can reference them via FK.
 *   - permissions + roles must exist before rel_permissions_roles can
 *     wire them together.
 *   - page_types must exist before pages are inserted (next seed).
 */
final class Version20260601000100 extends AbstractMigration
{
    use LegacySeedTrait;

    public function getDescription(): string
    {
        return 'Seed reference data: lookups, languages, field_types, style_groups, permissions, roles, page_types.';
    }

    public function up(Schema $schema): void
    {
        $this->seedFromLegacy('lookups');
        $this->seedFromLegacy('languages');
        $this->seedFromLegacy('fieldType');
        $this->seedFromLegacy('styleGroup');
        $this->seedFromLegacy('permissions');
        $this->seedFromLegacy('roles');
        $this->seedFromLegacy('roles_permissions');
        $this->seedFromLegacy('pageType');
        $this->seedFromLegacy('libraries');
        $this->seedFromLegacy('groups');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `groups`');
        $this->addSql('DELETE FROM `libraries`');
        $this->addSql('DELETE FROM `page_types`');
        $this->addSql('DELETE FROM `rel_permissions_roles`');
        $this->addSql('DELETE FROM `roles`');
        $this->addSql('DELETE FROM `permissions`');
        $this->addSql('DELETE FROM `style_groups`');
        $this->addSql('DELETE FROM `field_types`');
        $this->addSql('DELETE FROM `languages`');
        $this->addSql('DELETE FROM `lookups`');
    }
}
