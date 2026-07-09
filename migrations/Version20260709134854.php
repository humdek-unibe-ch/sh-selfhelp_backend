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
 * Extend `get_data_table_filtered` with an optional `selected_columns` parameter
 * (legacy parity for `entry-list` column subset loading).
 */
final class Version20260709134854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add selected_columns parameter to get_data_table_filtered stored procedure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS `get_data_table_filtered`');

        $this->addSql(<<<SQL
            CREATE FUNCTION `build_dynamic_columns_with_selection`(
                table_id_param INT,
                selected_columns_param VARCHAR(4000)
            ) RETURNS TEXT CHARSET utf8mb3
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                DECLARE result_sql TEXT;
                SELECT GROUP_CONCAT(DISTINCT CONCAT('MAX(IF(col.field_key = ''', REPLACE(c.field_key, '''', ''''''), ''', cell.value, NULL)) AS `', REPLACE(c.field_key, '`', '``'), '`')
                       ORDER BY c.id SEPARATOR ', ')
                INTO result_sql
                FROM data_cols c
                WHERE c.id_data_tables = table_id_param
                  AND (
                      IFNULL(TRIM(selected_columns_param), '') = ''
                      OR FIND_IN_SET(c.field_key, selected_columns_param) > 0
                  );
                RETURN result_sql;
            END
        SQL);

        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_data_table_filtered`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100),
                IN selected_columns_param VARCHAR(4000)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns_with_selection(table_id_param, selected_columns_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS `get_data_table_filtered`');
        $this->addSql('DROP FUNCTION IF EXISTS `build_dynamic_columns_with_selection`');

        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_data_table_filtered`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns(table_id_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
        SQL);
    }
}
