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
 * Backfills full data-table access for the seeded admin role.
 *
 * Data Management reads `role_data_access`; admin access should come from
 * the same table instead of a code-level role-name bypass.
 */
final class Version20260525091440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant the admin role full CRUD role_data_access entries for all existing data tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO `role_data_access`
                (`id_roles`, `id_resource_types`, `resource_id`, `crud_permissions`, `created_at`, `updated_at`)
            SELECT
                r.`id`,
                rt.`id`,
                dt.`id`,
                15,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            FROM `roles` r
            INNER JOIN `lookups` rt
                ON rt.`type_code` = 'resourceTypes'
               AND rt.`lookup_code` = 'data_table'
            CROSS JOIN `data_tables` dt
            WHERE r.`name` = 'admin'
            ON DUPLICATE KEY UPDATE
                `crud_permissions` = 15,
                `updated_at` = UTC_TIMESTAMP()
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Deliberately no-op: removing admin data access on rollback could lock
        // operators out of Data Management rows that they intentionally granted.
    }
}
