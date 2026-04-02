-- ============================================================================
-- 39c: Views, Stored Procedures, Helper Functions, Final Cleanup
-- ============================================================================
-- Run this script THIRD (after 39a and 39b). Idempotent and re-runnable.
--
-- 39b dropped ALL old views and many stored procedures because underlying
-- tables changed (formActions->actions, scheduledJobs simplified, pages
-- columns dropped, acl_users dropped, genders dropped, styles.id_type dropped).
-- This script recreates every view and procedure needed for v8.0.0.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- ============================================================================
-- 1. RECREATE view_styles (styles.id_type was dropped in 39b)
--    The version from 39a referenced styles.id_type which no longer exists.
-- ============================================================================

DROP VIEW IF EXISTS `view_styles`;
CREATE VIEW `view_styles` AS
SELECT
  CAST(s.id AS UNSIGNED) AS style_id,
  s.name AS style_name,
  s.description AS style_description,
  CAST(sg.id AS UNSIGNED) AS style_group_id,
  sg.name AS style_group,
  sg.description AS style_group_description,
  sg.position AS style_group_position
FROM styles s
LEFT JOIN styleGroup sg ON s.id_group = sg.id;


-- ============================================================================
-- 2. RECREATE view_fields (add config column from 39b)
-- ============================================================================

DROP VIEW IF EXISTS `view_fields`;
CREATE VIEW `view_fields` AS
SELECT
  CAST(f.id AS UNSIGNED) AS field_id,
  f.name AS field_name,
  f.display AS display,
  CAST(ft.id AS UNSIGNED) AS field_type_id,
  ft.name AS field_type,
  ft.position AS position,
  f.config AS config
FROM fields f
LEFT JOIN fieldType ft ON f.id_type = ft.id;


-- ============================================================================
-- 3. RECREATE view_style_fields (depends on view_styles, view_fields)
-- ============================================================================

DROP VIEW IF EXISTS `view_style_fields`;
CREATE VIEW `view_style_fields` AS
SELECT
  s.style_id, s.style_name, s.style_group,
  f.field_id, f.field_name, f.field_type, f.config, f.display, f.position,
  sf.default_value, sf.help, sf.disabled, sf.hidden
FROM view_styles s
LEFT JOIN styles_fields sf ON s.style_id = sf.id_styles
LEFT JOIN view_fields f ON f.field_id = sf.id_fields;


-- ============================================================================
-- 4. RECREATE view_datatables_data (dropped in 39b)
-- ============================================================================

DROP VIEW IF EXISTS `view_datatables_data`;
CREATE VIEW `view_datatables_data` AS
SELECT
  t.id AS table_id,
  r.id AS row_id,
  r.`timestamp` AS entry_date,
  col.id AS col_id,
  t.name AS table_name,
  col.name AS col_name,
  cell.value AS value,
  t.`timestamp` AS `timestamp`,
  r.id_users AS id_users,
  t.displayName AS displayName
FROM dataTables t
LEFT JOIN dataRows r ON t.id = r.id_dataTables
LEFT JOIN dataCells cell ON cell.id_dataRows = r.id
LEFT JOIN dataCols col ON col.id = cell.id_dataCols;


-- ============================================================================
-- 5. RECREATE view_transactions (dropped in 39b)
-- ============================================================================

DROP VIEW IF EXISTS `view_transactions`;
CREATE VIEW `view_transactions` AS
SELECT
  t.id AS id,
  t.transaction_time AS transaction_time,
  t.id_transactionTypes AS id_transactionTypes,
  tran_type.lookup_value AS transaction_type,
  t.id_transactionBy AS id_transactionBy,
  tran_by.lookup_value AS transaction_by,
  t.id_users AS id_users,
  u.name AS user_name,
  t.table_name AS table_name,
  t.id_table_name AS id_table_name,
  REPLACE(JSON_EXTRACT(t.transaction_log, '$.verbal_log'), '"', '') AS transaction_verbal_log
FROM transactions t
JOIN lookups tran_type ON tran_type.id = t.id_transactionTypes
JOIN lookups tran_by ON tran_by.id = t.id_transactionBy
LEFT JOIN users u ON u.id = t.id_users;


-- ============================================================================
-- 6. RECREATE view_user_codes (dropped in 39b)
-- ============================================================================

DROP VIEW IF EXISTS `view_user_codes`;
CREATE VIEW `view_user_codes` AS
SELECT
  u.id AS id,
  u.email AS email,
  u.name AS name,
  u.blocked AS blocked,
  CASE
    WHEN u.name = 'admin' THEN 'admin'
    WHEN u.name = 'tpf'   THEN 'tpf'
    ELSE IFNULL(vc.code, '-')
  END AS code,
  u.intern AS intern
FROM users u
LEFT JOIN validation_codes vc ON u.id = vc.id_users
WHERE u.intern <> 1 AND u.id_status > 0;


-- ============================================================================
-- 7. ACL VIEWS (pages.protocol, id_actions, id_navigation_section dropped;
--              acl_users table dropped)
-- ============================================================================

DROP VIEW IF EXISTS `view_acl_groups_pages`;
CREATE VIEW `view_acl_groups_pages` AS
SELECT
  acl.id_groups AS id_groups,
  acl.id_pages AS id_pages,
  CASE WHEN p.is_open_access = 1 THEN 1 ELSE acl.acl_select END AS acl_select,
  acl.acl_insert AS acl_insert,
  acl.acl_update AS acl_update,
  acl.acl_delete AS acl_delete,
  p.keyword AS keyword,
  p.url AS url,
  p.parent AS parent,
  p.is_headless AS is_headless,
  p.nav_position AS nav_position,
  p.footer_position AS footer_position,
  p.id_type AS id_type,
  p.is_open_access AS is_open_access,
  p.is_system AS is_system
FROM acl_groups acl
JOIN pages p ON acl.id_pages = p.id
GROUP BY acl.id_groups, acl.id_pages, acl.acl_select, acl.acl_insert,
  acl.acl_update, acl.acl_delete, p.keyword, p.url, p.parent,
  p.is_headless, p.nav_position, p.footer_position, p.id_type,
  p.is_open_access, p.is_system;

DROP VIEW IF EXISTS `view_acl_users_in_groups_pages`;
CREATE VIEW `view_acl_users_in_groups_pages` AS
SELECT
  ug.id_users AS id_users,
  acl.id_pages AS id_pages,
  MAX(IFNULL(acl.acl_select, 0)) AS acl_select,
  MAX(IFNULL(acl.acl_insert, 0)) AS acl_insert,
  MAX(IFNULL(acl.acl_update, 0)) AS acl_update,
  MAX(IFNULL(acl.acl_delete, 0)) AS acl_delete,
  p.keyword AS keyword,
  p.url AS url,
  p.parent AS parent,
  p.is_headless AS is_headless,
  p.nav_position AS nav_position,
  p.footer_position AS footer_position,
  p.id_type AS id_type,
  p.is_open_access AS is_open_access,
  p.is_system AS is_system
FROM users u
JOIN users_groups ug ON ug.id_users = u.id
JOIN acl_groups acl ON acl.id_groups = ug.id_groups
JOIN pages p ON acl.id_pages = p.id
GROUP BY ug.id_users, acl.id_pages, p.keyword, p.url, p.parent,
  p.is_headless, p.nav_position, p.footer_position, p.id_type,
  p.is_open_access, p.is_system;

DROP VIEW IF EXISTS `view_acl_users_union`;
CREATE VIEW `view_acl_users_union` AS
SELECT * FROM view_acl_users_in_groups_pages;


-- ============================================================================
-- 8. view_actions (was view_formactions; references `actions` table)
-- ============================================================================

DROP VIEW IF EXISTS `view_actions`;
CREATE VIEW `view_actions` AS
SELECT
  a.id AS id,
  a.name AS action_name,
  dt.name AS dataTable_name,
  a.id_actionTriggerTypes AS id_actionTriggerTypes,
  trig.lookup_value AS trigger_type,
  trig.lookup_code AS trigger_type_code,
  a.config AS config,
  dt.id AS id_dataTables
FROM actions a
JOIN lookups trig ON trig.id = a.id_actionTriggerTypes
LEFT JOIN view_dataTables dt ON dt.id = a.id_dataTables;


-- ============================================================================
-- 9. view_scheduledjobs (simplified: no junction tables, all columns inline)
-- ============================================================================

DROP VIEW IF EXISTS `view_scheduledjobs`;
CREATE VIEW `view_scheduledjobs` AS
SELECT
  sj.id AS id,
  l_status.lookup_code AS status_code,
  l_status.lookup_value AS status,
  l_types.lookup_code AS type_code,
  l_types.lookup_value AS type,
  sj.config AS config,
  sj.date_create AS date_create,
  sj.date_to_be_executed AS date_to_be_executed,
  sj.date_executed AS date_executed,
  sj.description AS description,
  sj.id_users AS id_users,
  sj.id_actions AS id_actions,
  sj.id_dataTables AS id_dataTables,
  sj.id_dataRows AS id_dataRows,
  sj.id_jobTypes AS id_jobTypes,
  sj.id_jobStatus AS id_jobStatus,
  dt.name AS dataTables_name
FROM scheduledJobs sj
JOIN lookups l_status ON l_status.id = sj.id_jobStatus
JOIN lookups l_types ON l_types.id = sj.id_jobTypes
LEFT JOIN view_dataTables dt ON dt.id = sj.id_dataTables;


-- ============================================================================
-- 10. view_scheduledjobs_transactions (simplified)
-- ============================================================================

DROP VIEW IF EXISTS `view_scheduledjobs_transactions`;
CREATE VIEW `view_scheduledjobs_transactions` AS
SELECT
  sj.id AS id,
  sj.date_create AS date_create,
  sj.date_to_be_executed AS date_to_be_executed,
  sj.date_executed AS date_executed,
  t.id AS transaction_id,
  t.transaction_time AS transaction_time,
  t.transaction_type AS transaction_type,
  t.transaction_by AS transaction_by,
  t.user_name AS user_name,
  t.transaction_verbal_log AS transaction_verbal_log
FROM scheduledJobs sj
JOIN view_transactions t
  ON t.table_name = 'scheduledJobs' AND t.id_table_name = sj.id
ORDER BY sj.id, t.id;


-- ============================================================================
-- 11. view_sections_fields (genders table dropped, remove gender join)
-- ============================================================================

DROP VIEW IF EXISTS `view_sections_fields`;
CREATE VIEW `view_sections_fields` AS
SELECT
  s.id AS id_sections,
  s.name AS section_name,
  IFNULL(sft.content, '') AS content,
  IFNULL(sft.meta, '') AS meta,
  s.id_styles AS id_styles,
  fields.style_name AS style_name,
  fields.field_id AS id_fields,
  fields.field_name AS field_name,
  IFNULL(l.locale, '') AS locale
FROM sections s
LEFT JOIN view_style_fields fields ON fields.style_id = s.id_styles
LEFT JOIN sections_fields_translation sft
  ON sft.id_sections = s.id AND sft.id_fields = fields.field_id
LEFT JOIN languages l ON sft.id_languages = l.id;


-- ============================================================================
-- 12. DROP QUALTRICS JUNCTION/VIEWS (old scheduledJobs structure is gone)
-- ============================================================================

DROP TABLE IF EXISTS `scheduledJobs_qualtricsActions`;
DROP VIEW IF EXISTS `view_qualtricsActions`;
DROP VIEW IF EXISTS `view_qualtricsReminders`;
DROP VIEW IF EXISTS `view_qualtricsSurveys`;


-- ============================================================================
-- 13. DROP OBSOLETE PROCEDURES
-- ============================================================================

DROP PROCEDURE IF EXISTS `get_user_acl`;


-- ============================================================================
-- 14. CLEANUP: drop orphaned id_genders column (genders table dropped in 39b)
-- ============================================================================

CALL drop_table_column('sections_fields_translation', 'id_genders');


-- ============================================================================
-- 15. HELPER FUNCTIONS
-- ============================================================================

DROP FUNCTION IF EXISTS `build_dynamic_columns`;
DELIMITER ;;
CREATE FUNCTION `build_dynamic_columns`(table_id_param INT) RETURNS TEXT
    READS SQL DATA
    DETERMINISTIC
BEGIN
    DECLARE sql_columns TEXT;
    SELECT GROUP_CONCAT(DISTINCT
        CONCAT('MAX(CASE WHEN col.`name` = "', col.name,
               '" THEN `value` END) AS `', REPLACE(col.name, ' ', ''), '`')
    ) INTO sql_columns
    FROM dataTables t
    INNER JOIN dataCols col ON t.id = col.id_dataTables
    WHERE t.id = table_id_param
      AND col.`name` NOT IN ('id_users','record_id','user_name',
          'id_actionTriggerTypes','triggerType','entry_date','user_code');
    RETURN sql_columns;
END ;;
DELIMITER ;

DROP FUNCTION IF EXISTS `build_time_period_filter`;
DELIMITER ;;
CREATE FUNCTION `build_time_period_filter`(filter_param VARCHAR(1000)) RETURNS TEXT
    DETERMINISTIC
BEGIN
    CASE
        WHEN filter_param LIKE '%LAST_HOUR%'  THEN RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 HOUR';
        WHEN filter_param LIKE '%LAST_DAY%'   THEN RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 DAY';
        WHEN filter_param LIKE '%LAST_WEEK%'  THEN RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 WEEK';
        WHEN filter_param LIKE '%LAST_MONTH%' THEN RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 MONTH';
        WHEN filter_param LIKE '%LAST_YEAR%'  THEN RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 YEAR';
        ELSE RETURN '';
    END CASE;
END ;;
DELIMITER ;

DROP FUNCTION IF EXISTS `build_exclude_deleted_filter`;
DELIMITER ;;
CREATE FUNCTION `build_exclude_deleted_filter`(exclude_deleted_param BOOLEAN) RETURNS TEXT
    DETERMINISTIC
BEGIN
    IF exclude_deleted_param = TRUE THEN
        RETURN CONCAT(' AND IFNULL(r.id_actionTriggerTypes, 0) <> ',
            (SELECT id FROM lookups
             WHERE type_code = 'actionTriggerTypes' AND lookup_code = 'deleted'
             LIMIT 1));
    ELSE
        RETURN '';
    END IF;
END ;;
DELIMITER ;

DROP FUNCTION IF EXISTS `build_language_filter`;
DELIMITER ;;
CREATE FUNCTION `build_language_filter`(language_id_param INT) RETURNS TEXT
    DETERMINISTIC
BEGIN
    IF language_id_param IS NULL OR language_id_param = 1 THEN
        RETURN ' AND cell.id_languages = 1';
    ELSE
        RETURN CONCAT(' AND cell.id_languages IN (1, ', language_id_param, ')');
    END IF;
END ;;
DELIMITER ;

DROP FUNCTION IF EXISTS `convert_entry_date_timezone`;
DELIMITER ;;
CREATE FUNCTION `convert_entry_date_timezone`(
    timestamp_value DATETIME,
    timezone_code VARCHAR(100)
) RETURNS VARCHAR(19)
    DETERMINISTIC
BEGIN
    RETURN DATE_FORMAT(
        CONVERT_TZ(timestamp_value, 'UTC', timezone_code),
        '%Y-%m-%d %H:%i:%s'
    );
END ;;
DELIMITER ;


-- ============================================================================
-- 16. RECREATE get_page_fields_helper (dropped in 39b; pages columns changed)
-- ============================================================================

DROP FUNCTION IF EXISTS `get_page_fields_helper`;
DELIMITER ;;
CREATE FUNCTION `get_page_fields_helper`(
    page_id INT,
    language_id INT,
    default_language_id INT
) RETURNS TEXT CHARSET utf8mb3
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = NULL;
    SELECT
      GROUP_CONCAT(DISTINCT
        CONCAT(
          'MAX(CASE WHEN f.`name` = "', f.`name`,
          '" THEN COALESCE(',
            '(SELECT content FROM pages_fields_translation AS pft ',
             'WHERE pft.id_pages = p.id AND pft.id_fields = f.id ',
             'AND pft.id_languages = ', language_id,
             ' AND content <> "" LIMIT 1), ',
            'COALESCE((SELECT content FROM pages_fields_translation AS pft ',
             'WHERE pft.id_pages = p.id AND pft.id_fields = f.id ',
             'AND pft.id_languages = (CASE WHEN f.display = 0 THEN 1 ELSE ',
             default_language_id, ' END) LIMIT 1), "")) ',
          'END) AS `', REPLACE(f.`name`, ' ', ''), '`'
        )
      ) INTO @sql
    FROM pages AS p
    LEFT JOIN pageType_fields AS ptf ON ptf.id_pageType = p.id_type
    LEFT JOIN fields AS f ON f.id = ptf.id_fields
    WHERE p.id = page_id OR page_id = -1;
    RETURN @sql;
END ;;
DELIMITER ;


-- ============================================================================
-- 17. RECREATE get_sections_fields_helper (dropped in 39b; genders removed)
-- ============================================================================

DROP FUNCTION IF EXISTS `get_sections_fields_helper`;
DELIMITER ;;
CREATE FUNCTION `get_sections_fields_helper`(
    section_id INT,
    language_id INT
) RETURNS TEXT CHARSET utf8mb3
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = NULL;
    SELECT
      GROUP_CONCAT(DISTINCT
        CONCAT(
          'MAX(CASE WHEN f.`name` = "', f.`name`,
          '" THEN sft.content END) AS `',
          REPLACE(f.`name`, ' ', ''), '`'
        )
      ) INTO @sql
    FROM sections AS s
    LEFT JOIN sections_fields_translation AS sft
      ON sft.id_sections = s.id
      AND (language_id = sft.id_languages OR sft.id_languages = 1)
    LEFT JOIN fields AS f ON f.id = sft.id_fields
    WHERE s.id = section_id OR section_id = -1;
    RETURN @sql;
END ;;
DELIMITER ;


-- ============================================================================
-- 18. STORED PROCEDURES: page/section fields
-- ============================================================================

DROP PROCEDURE IF EXISTS `get_page_fields`;
DELIMITER ;;
CREATE PROCEDURE `get_page_fields`(
    page_id INT,
    language_id INT,
    default_language_id INT,
    filter_param VARCHAR(1000),
    order_param VARCHAR(1000)
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SELECT get_page_fields_helper(page_id, language_id, default_language_id) INTO @sql;

    IF (@sql IS NULL) THEN
        SELECT * FROM pages WHERE 1=2;
    ELSE
        BEGIN
            SET @sql = CONCAT(
                'SELECT p.id, p.keyword, p.url, "select" AS access_level, ',
                'p.parent, p.is_headless, p.nav_position, p.footer_position, ',
                'p.id_type, p.id_pageAccessTypes, p.is_open_access, p.is_system, ',
                @sql,
                ' FROM pages p ',
                'LEFT JOIN pageType_fields AS ptf ON ptf.id_pageType = p.id_type ',
                'LEFT JOIN fields AS f ON f.id = ptf.id_fields ',
                'WHERE (p.id = ', page_id, ' OR -1 = ', page_id, ') ',
                'GROUP BY p.id, p.keyword, p.url, p.parent, p.is_headless, ',
                'p.nav_position, p.footer_position, p.id_type, ',
                'p.id_pageAccessTypes, p.is_open_access, p.is_system ',
                'HAVING 1 ', filter_param
            );

            IF (order_param <> '') THEN
                SET @sql = CONCAT(
                    'SELECT * FROM (', @sql, ') AS t ', order_param
                );
            END IF;

            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;


DROP PROCEDURE IF EXISTS `get_sections_fields`;
DELIMITER ;;
CREATE PROCEDURE `get_sections_fields`(
    section_id INT,
    language_id INT,
    filter_param VARCHAR(1000),
    order_param VARCHAR(1000)
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SELECT get_sections_fields_helper(section_id, language_id) INTO @sql;

    IF (@sql IS NULL) THEN
        SELECT * FROM sections WHERE 1=2;
    ELSE
        BEGIN
            SET @sql = CONCAT(
                'SELECT s.id AS section_id, s.name AS section_name, ',
                'st.id AS style_id, st.name AS style_name, ',
                @sql,
                ' FROM sections s ',
                'INNER JOIN styles st ON s.id_styles = st.id ',
                'LEFT JOIN sections_fields_translation AS sft ON sft.id_sections = s.id ',
                'LEFT JOIN fields AS f ON sft.id_fields = f.id ',
                'WHERE (s.id = ', section_id, ' OR -1 = ', section_id, ') ',
                'AND (IFNULL(sft.id_languages, 1) = 1 OR sft.id_languages = ', language_id, ') ',
                'GROUP BY s.id, s.name, st.id, st.name ',
                'HAVING 1 ', filter_param
            );

            IF (order_param <> '') THEN
                SET @sql = CONCAT(
                    'SELECT * FROM (', @sql, ') AS t ', order_param
                );
            END IF;

            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;


-- ============================================================================
-- 19. STORED PROCEDURE: hierarchical page sections (NEW for v8)
-- ============================================================================

DROP PROCEDURE IF EXISTS `get_page_sections_hierarchical`;
DELIMITER //
CREATE PROCEDURE `get_page_sections_hierarchical`(IN page_id INT)
BEGIN
    WITH RECURSIVE section_hierarchy AS (
        SELECT
            s.id, s.name, s.id_styles,
            st.name AS style_name,
            s.`condition`, s.css, s.css_mobile, s.debug, s.data_config,
            ps.position AS position,
            0 AS `level`,
            CAST(s.id AS CHAR(200)) AS `path`
        FROM pages_sections ps
        JOIN sections s ON ps.id_sections = s.id
        JOIN styles st ON s.id_styles = st.id
        LEFT JOIN sections_hierarchy sh ON s.id = sh.child
        WHERE ps.id_pages = page_id AND sh.parent IS NULL

        UNION ALL

        SELECT
            s.id, s.name, s.id_styles,
            st.name AS style_name,
            s.`condition`, s.css, s.css_mobile, s.debug, s.data_config,
            sh.position AS position,
            h.`level` + 1,
            CONCAT(h.`path`, ',', s.id) AS `path`
        FROM section_hierarchy h
        JOIN sections_hierarchy sh ON h.id = sh.parent
        JOIN sections s ON sh.child = s.id
        JOIN styles st ON s.id_styles = st.id
    )
    SELECT id, name AS section_name, id_styles, style_name,
        `condition`, css, css_mobile, debug, data_config,
        position, `level`, `path`
    FROM section_hierarchy
    ORDER BY `path`, position;
END //
DELIMITER ;


-- ============================================================================
-- 20. DATA TABLE PROCEDURES (with timezone + language support for v8)
-- ============================================================================

DROP PROCEDURE IF EXISTS `get_dataTable_with_filter`;
DELIMITER ;;
CREATE PROCEDURE `get_dataTable_with_filter`(
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
        SELECT `name` FROM view_dataTables WHERE 1=2;
    ELSE
        BEGIN
            SET @user_filter = '';
            IF user_id_param > 0 THEN
                SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
            END IF;

            SET @sql = CONCAT(
                'SELECT * FROM (SELECT r.id AS record_id, ',
                'convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, ',
                'r.id_users, u.`name` AS user_name, MAX(vc.code) AS user_code, ',
                'r.id_actionTriggerTypes, l.lookup_code AS triggerType, ',
                @sql,
                ' FROM dataTables t ',
                'INNER JOIN dataRows r ON t.id = r.id_dataTables ',
                'INNER JOIN dataCells cell ON cell.id_dataRows = r.id ',
                'INNER JOIN dataCols col ON col.id = cell.id_dataCols ',
                'LEFT JOIN users u ON r.id_users = u.id ',
                'LEFT JOIN validation_codes vc ON u.id = vc.id_users ',
                'LEFT JOIN lookups l ON l.id = r.id_actionTriggerTypes ',
                'WHERE t.id = ', table_id_param,
                @user_filter,
                build_time_period_filter(filter_param),
                build_exclude_deleted_filter(exclude_deleted_param),
                build_language_filter(language_id_param),
                ' GROUP BY r.id) AS r WHERE 1=1 ', filter_param
            );

            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;


DROP PROCEDURE IF EXISTS `get_dataTable_with_all_languages`;
DELIMITER ;;
CREATE PROCEDURE `get_dataTable_with_all_languages`(
    IN table_id_param INT,
    IN user_id_param INT,
    IN filter_param VARCHAR(1000),
    IN exclude_deleted_param BOOLEAN,
    IN timezone_code_param VARCHAR(100)
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = build_dynamic_columns(table_id_param);

    IF (@sql IS NULL) THEN
        SELECT `name` FROM view_dataTables WHERE 1=2;
    ELSE
        BEGIN
            SET @user_filter = '';
            IF user_id_param > 0 THEN
                SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
            END IF;

            SET @sql = CONCAT(
                'SELECT r.id AS record_id, ',
                'convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, ',
                'r.id_users, u.`name` AS user_name, MAX(vc.code) AS user_code, ',
                'r.id_actionTriggerTypes, l.lookup_code AS triggerType, ',
                'cell.id_languages, lang.locale AS language_locale, ',
                'lang.language AS language_name, ',
                @sql,
                ' FROM dataTables t ',
                'INNER JOIN dataRows r ON t.id = r.id_dataTables ',
                'LEFT JOIN users u ON r.id_users = u.id ',
                'LEFT JOIN validation_codes vc ON u.id = vc.id_users ',
                'LEFT JOIN lookups l ON l.id = r.id_actionTriggerTypes ',
                'INNER JOIN dataCells cell ON cell.id_dataRows = r.id ',
                'INNER JOIN dataCols col ON col.id = cell.id_dataCols ',
                'LEFT JOIN languages lang ON lang.id = cell.id_languages ',
                'WHERE t.id = ', table_id_param,
                @user_filter,
                build_time_period_filter(filter_param),
                build_exclude_deleted_filter(exclude_deleted_param),
                ' GROUP BY r.id, cell.id_languages ORDER BY r.id, cell.id_languages'
            );

            SET @sql = CONCAT(
                'SELECT * FROM (', @sql, ') AS filtered_data WHERE 1=1 ', filter_param
            );

            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;


DROP PROCEDURE IF EXISTS `get_dataTable_with_user_group_filter`;
DELIMITER ;;
CREATE PROCEDURE `get_dataTable_with_user_group_filter`(
    IN table_id_param INT,
    IN current_user_id_param INT,
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
        SELECT `name` FROM view_dataTables WHERE 1=2;
    ELSE
        BEGIN
            SET @group_resource_type_id = (
                SELECT id FROM lookups
                WHERE type_code = 'resourceTypes' AND lookup_code = 'group'
                LIMIT 1
            );

            DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
            CREATE TEMPORARY TABLE accessible_users_temp AS
            SELECT DISTINCT ug.id_users
            FROM users_groups ug
            WHERE ug.id_groups IN (
                SELECT rda.resource_id
                FROM role_data_access rda
                INNER JOIN roles r ON rda.id_roles = r.id
                INNER JOIN users_roles ur ON r.id = ur.id_roles
                WHERE ur.id_users = current_user_id_param
                  AND rda.id_resourceTypes = @group_resource_type_id
                  AND rda.crud_permissions > 0
            );

            SET @user_filter = '';
            SET @accessible_user_count = (SELECT COUNT(*) FROM accessible_users_temp);
            IF @accessible_user_count > 0 THEN
                SET @user_filter = ' AND r.id_users IN (SELECT id_users FROM accessible_users_temp)';
            ELSE
                SET @user_filter = ' AND 1=0';
            END IF;

            SET @sql = CONCAT(
                'SELECT * FROM (SELECT r.id AS record_id, ',
                'convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, ',
                'r.id_users, u.`name` AS user_name, MAX(vc.code) AS user_code, ',
                'r.id_actionTriggerTypes, l.lookup_code AS triggerType, ',
                @sql,
                ' FROM dataTables t ',
                'INNER JOIN dataRows r ON t.id = r.id_dataTables ',
                'INNER JOIN dataCells cell ON cell.id_dataRows = r.id ',
                'INNER JOIN dataCols col ON col.id = cell.id_dataCols ',
                'LEFT JOIN users u ON r.id_users = u.id ',
                'LEFT JOIN validation_codes vc ON u.id = vc.id_users ',
                'LEFT JOIN lookups l ON l.id = r.id_actionTriggerTypes ',
                'WHERE t.id = ', table_id_param,
                @user_filter,
                build_time_period_filter(filter_param),
                build_exclude_deleted_filter(exclude_deleted_param),
                build_language_filter(language_id_param),
                ' GROUP BY r.id) AS r WHERE 1=1 ', filter_param
            );

            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;

            DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
        END;
    END IF;
END ;;
DELIMITER ;


-- ============================================================================
-- 21. FINAL CHECKS
-- ============================================================================

SET SQL_MODE = @OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS = 1;

SELECT '39c completed successfully - v8.0.0 migration complete' AS status;
