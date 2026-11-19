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
 * F03: page ACL is one right (`acl_select`). Drop insert/update/delete columns
 * and replace `get_user_acl` without those columns.
 */
final class Version20261118112441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove page ACL insert/update/delete bits; refresh get_user_acl (F03)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_acl_groups DROP acl_insert, DROP acl_update, DROP acl_delete');
        $this->replaceGetUserAclProcedure(withLegacyBits: false);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_acl_groups ADD acl_insert TINYINT(1) DEFAULT 0 NOT NULL, ADD acl_update TINYINT(1) DEFAULT 0 NOT NULL, ADD acl_delete TINYINT(1) DEFAULT 0 NOT NULL');
        $this->replaceGetUserAclProcedure(withLegacyBits: true);
    }

    private function replaceGetUserAclProcedure(bool $withLegacyBits): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS get_user_acl');

        if ($withLegacyBits) {
            $this->addSql(<<<'SQL'
                CREATE PROCEDURE get_user_acl(
                    IN param_user_id INT,
                    IN param_page_id INT
                )
                BEGIN
                    SELECT
                        param_user_id AS id_users,
                        id_pages,
                        MAX(acl_select) AS acl_select,
                        MAX(acl_insert) AS acl_insert,
                        MAX(acl_update) AS acl_update,
                        MAX(acl_delete) AS acl_delete,
                        keyword,
                        url,
                        id_parent_page,
                        is_headless,
                        id_page_types,
                        id_page_access_types,
                        is_system
                    FROM (
                        SELECT
                            ug.id_users,
                            acl.id_pages,
                            acl.acl_select,
                            acl.acl_insert,
                            acl.acl_update,
                            acl.acl_delete,
                            p.keyword,
                            p.url,
                            p.id_parent_page,
                            p.is_headless,
                            p.id_page_types,
                            p.id_page_access_types,
                            p.is_system
                        FROM rel_groups_users ug
                        JOIN users u             ON ug.id_users   = u.id
                        JOIN page_acl_groups acl ON acl.id_groups = ug.id_groups
                        JOIN pages p             ON p.id          = acl.id_pages
                        WHERE ug.id_users = param_user_id
                          AND (param_page_id = -1 OR acl.id_pages = param_page_id)

                        UNION ALL

                        SELECT
                            param_user_id AS id_users,
                            p.id          AS id_pages,
                            1             AS acl_select,
                            0             AS acl_insert,
                            0             AS acl_update,
                            0             AS acl_delete,
                            p.keyword,
                            p.url,
                            p.id_parent_page,
                            p.is_headless,
                            p.id_page_types,
                            p.id_page_access_types,
                            p.is_system
                        FROM pages p
                        WHERE p.is_open_access = 1
                          AND (param_page_id = -1 OR p.id = param_page_id)
                    ) AS combined_acl
                    GROUP BY
                        id_pages,
                        keyword,
                        url,
                        id_parent_page,
                        is_headless,
                        id_page_types,
                        is_system,
                        id_page_access_types;
                END
                SQL);

            return;
        }

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE get_user_acl(
                IN param_user_id INT,
                IN param_page_id INT
            )
            BEGIN
                SELECT
                    param_user_id AS id_users,
                    id_pages,
                    MAX(acl_select) AS acl_select,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    id_page_access_types,
                    is_system
                FROM (
                    SELECT
                        ug.id_users,
                        acl.id_pages,
                        acl.acl_select,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM rel_groups_users ug
                    JOIN users u             ON ug.id_users   = u.id
                    JOIN page_acl_groups acl ON acl.id_groups = ug.id_groups
                    JOIN pages p             ON p.id          = acl.id_pages
                    WHERE ug.id_users = param_user_id
                      AND (param_page_id = -1 OR acl.id_pages = param_page_id)

                    UNION ALL

                    SELECT
                        param_user_id AS id_users,
                        p.id          AS id_pages,
                        1             AS acl_select,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM pages p
                    WHERE p.is_open_access = 1
                      AND (param_page_id = -1 OR p.id = param_page_id)
                ) AS combined_acl
                GROUP BY
                    id_pages,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    is_system,
                    id_page_access_types;
            END
            SQL);
    }
}
