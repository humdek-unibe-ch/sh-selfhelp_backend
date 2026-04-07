-- ============================================================================
-- 39a: Preparations, Utility Procedures, Domain Migration, Doctrine Schema
-- ============================================================================
-- Run this script FIRST. It is idempotent and can be re-run safely.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- set DB version
UPDATE version
SET version = 'v8.0.0';

-- ============================================================================
-- 1. UTILITY PROCEDURES
-- ============================================================================

DROP PROCEDURE IF EXISTS rename_index;
DELIMITER //
CREATE PROCEDURE rename_index(
  IN param_table VARCHAR(100),
  IN old_index_name VARCHAR(100),
  IN new_index_name VARCHAR(100)
)
BEGIN
  DECLARE old_exists INT DEFAULT 0;
  DECLARE new_exists INT DEFAULT 0;

  SELECT COUNT(*) INTO old_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = param_table
    AND INDEX_NAME   = old_index_name;

  SELECT COUNT(*) INTO new_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = param_table
    AND INDEX_NAME   = new_index_name;

  IF new_exists > 0 THEN
    SELECT CONCAT('Index ', new_index_name, ' already exists on ', param_table) AS msg;
  ELSEIF old_exists = 0 THEN
    SELECT CONCAT('Index ', old_index_name, ' not found on ', param_table) AS msg;
  ELSE
    SET @sql := CONCAT('ALTER TABLE `', param_table, '` RENAME INDEX `', old_index_name, '` TO `', new_index_name, '`;');
    PREPARE st FROM @sql;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;


DELIMITER //
DROP PROCEDURE IF EXISTS add_index //
CREATE PROCEDURE add_index(
    param_table VARCHAR(100), 
    param_index_name VARCHAR(100), 
    param_index_column VARCHAR(1000),
    param_is_unique BOOLEAN
)
BEGIN
    DECLARE column_list TEXT DEFAULT '';
    DECLARE remaining_columns TEXT DEFAULT param_index_column;
    DECLARE current_column VARCHAR(100);
    DECLARE comma_pos INT;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS 
        WHERE `table_schema` = DATABASE()
        AND `table_name` = param_table
        AND `index_name` = param_index_name
    ) > 0 THEN
        SELECT CONCAT('Index ', param_index_name, ' already exists on table ', param_table) AS message;
    ELSE
        WHILE LENGTH(remaining_columns) > 0 DO
            SET comma_pos = LOCATE(',', remaining_columns);
            IF comma_pos > 0 THEN
                SET current_column = TRIM(SUBSTRING(remaining_columns, 1, comma_pos - 1));
                SET remaining_columns = SUBSTRING(remaining_columns, comma_pos + 1);
            ELSE
                SET current_column = TRIM(remaining_columns);
                SET remaining_columns = '';
            END IF;

            SET current_column = TRIM(BOTH '`' FROM current_column);

            IF LENGTH(column_list) > 0 THEN
                SET column_list = CONCAT(column_list, ', `', current_column, '`');
            ELSE
                SET column_list = CONCAT('`', current_column, '`');
            END IF;
        END WHILE;

        SET @sqlstmt = CONCAT(
            'CREATE ',
            IF(param_is_unique, 'UNIQUE ', ''),
            'INDEX `',
            param_index_name,
            '` ON `',
            param_table,
            '` (',
            column_list,
            ');'
        );
        PREPARE st FROM @sqlstmt;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;


DELIMITER $$
DROP PROCEDURE IF EXISTS `add_primary_key` $$
CREATE PROCEDURE `add_primary_key`(
  IN `param_table`   VARCHAR(100),
  IN `param_columns` VARCHAR(500)
)
BEGIN
  DECLARE cnt INT DEFAULT 0;
  SELECT COUNT(*) INTO cnt
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE table_schema    = DATABASE()
     AND table_name      = param_table
     AND constraint_type = 'PRIMARY KEY';

  IF cnt = 0 THEN
    SET @sqlstmt = CONCAT(
      'ALTER TABLE `', param_table,
      '` ADD PRIMARY KEY (', param_columns, ');'
    );
  ELSE
    SET @sqlstmt = "SELECT 'Primary key already exists on table.'";
  END IF;

  PREPARE st FROM @sqlstmt;
  EXECUTE st;
  DEALLOCATE PREPARE st;
END$$
DELIMITER ;


DELIMITER //
DROP PROCEDURE IF EXISTS add_foreign_key //
CREATE PROCEDURE add_foreign_key(param_table VARCHAR(100), fk_name VARCHAR(100), fk_column VARCHAR(100), fk_references VARCHAR(200))
BEGIN	
    SET @sqlstmt = (SELECT IF(
		(
			SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS 
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
            AND `constraint_name` = fk_name
		) > 0,
        "SELECT 'The foreign key already exists in the table'",
        CONCAT('ALTER TABLE `', param_table, '` ADD CONSTRAINT `', fk_name, '` FOREIGN KEY (`', fk_column, '`) REFERENCES ', fk_references, ' ON DELETE CASCADE;')
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END //
DELIMITER ;


DELIMITER //
DROP PROCEDURE IF EXISTS drop_foreign_key //
CREATE PROCEDURE drop_foreign_key(param_table VARCHAR(100), fk_name VARCHAR(100))
BEGIN	
    SET @sqlstmt = (SELECT IF(
		(
			SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS 
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
            AND `constraint_name` = fk_name
		) = 0,
        "SELECT 'Foreign key does not exist'",
        CONCAT('ALTER TABLE `', param_table, '` DROP FOREIGN KEY `', fk_name, '`;')
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END //
DELIMITER ;


DELIMITER //
DROP PROCEDURE IF EXISTS drop_index //
CREATE PROCEDURE drop_index(param_table VARCHAR(100), param_index_name VARCHAR(100))
BEGIN	
    SET @sqlstmt = (SELECT IF(
		(
			SELECT COUNT(*)
            FROM information_schema.STATISTICS 
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
            AND `index_name` = param_index_name
		) > 0,        
        CONCAT('ALTER TABLE `', param_table, '` DROP INDEX `', param_index_name, '`'),
        "SELECT 'The index does not exist in the table'"
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END //
DELIMITER ;


-- rename_table: safely rename a table (idempotent)
DELIMITER //
DROP PROCEDURE IF EXISTS rename_table //
CREATE PROCEDURE rename_table(
    IN old_name VARCHAR(100),
    IN new_name VARCHAR(100)
)
BEGIN
    DECLARE old_exists INT DEFAULT 0;
    DECLARE new_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO old_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = old_name;

    SELECT COUNT(*) INTO new_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = new_name;

    IF new_exists > 0 THEN
        SELECT CONCAT('Table `', new_name, '` already exists, skipping rename.') AS msg;
    ELSEIF old_exists = 0 THEN
        SELECT CONCAT('Table `', old_name, '` does not exist, skipping rename.') AS msg;
    ELSE
        SET @sql := CONCAT('RENAME TABLE `', old_name, '` TO `', new_name, '`;');
        PREPARE st FROM @sql;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;


-- rename_table_column: rename a column (idempotent, 4-param with optional comment)
-- Preserves full column definition: type, NOT NULL, DEFAULT, AUTO_INCREMENT, existing COMMENT
DELIMITER $$
DROP PROCEDURE IF EXISTS `rename_table_column` $$
CREATE PROCEDURE `rename_table_column`(
    param_table VARCHAR(100),
    param_old_column_name VARCHAR(100),
    param_new_column_name VARCHAR(100),
    param_comment TEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_col_type VARCHAR(255) DEFAULT '';
    DECLARE v_is_nullable VARCHAR(3) DEFAULT '';
    DECLARE v_col_default TEXT DEFAULT NULL;
    DECLARE v_extra VARCHAR(100) DEFAULT '';
    DECLARE v_col_comment VARCHAR(1024) DEFAULT '';
    DECLARE v_def TEXT DEFAULT '';

    SELECT COUNT(*), COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
    INTO v_exists, v_col_type, v_is_nullable, v_col_default, v_extra, v_col_comment
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = param_table
      AND COLUMN_NAME = param_old_column_name;

    IF v_exists = 0 THEN
        SELECT CONCAT('Column `', param_old_column_name, '` does not exist in `', param_table, '`') AS msg;
    ELSE
        SET v_def = v_col_type;

        IF v_col_default IS NOT NULL THEN
            SET v_def = CONCAT(v_def, ' DEFAULT ',
                CASE
                    WHEN v_col_default = 'CURRENT_TIMESTAMP' THEN 'CURRENT_TIMESTAMP'
                    ELSE CONCAT('''', v_col_default, '''')
                END);
        ELSEIF v_is_nullable = 'YES' THEN
            SET v_def = CONCAT(v_def, ' DEFAULT NULL');
        END IF;

        IF v_is_nullable = 'NO' THEN
            SET v_def = CONCAT(v_def, ' NOT NULL');
        END IF;

        IF v_extra LIKE '%auto_increment%' THEN
            SET v_def = CONCAT(v_def, ' AUTO_INCREMENT');
        END IF;
        IF v_extra LIKE '%on update CURRENT_TIMESTAMP%' THEN
            SET v_def = CONCAT(v_def, ' ON UPDATE CURRENT_TIMESTAMP');
        END IF;

        IF param_comment IS NOT NULL AND param_comment != '' THEN
            SET v_def = CONCAT(v_def, ' COMMENT ''', param_comment, '''');
        ELSEIF v_col_comment IS NOT NULL AND v_col_comment != '' THEN
            SET v_def = CONCAT(v_def, ' COMMENT ''', v_col_comment, '''');
        END IF;

        SET @sqlstmt = CONCAT('ALTER TABLE `', param_table, '` CHANGE COLUMN `',
            param_old_column_name, '` `', param_new_column_name, '` ', v_def, ';');
        PREPARE st FROM @sqlstmt;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END $$
DELIMITER ;


-- add_unique_key: safely add a unique key
DELIMITER $$
DROP PROCEDURE IF EXISTS `add_unique_key` $$
CREATE PROCEDURE `add_unique_key`(param_table VARCHAR(100), param_index VARCHAR(100), param_column VARCHAR(100))
BEGIN
    IF NOT EXISTS (
        SELECT NULL 
        FROM information_schema.STATISTICS
        WHERE `table_schema` = DATABASE()
        AND `table_name` = param_table
        AND `index_name` = param_index 
    ) THEN    
        SET @sqlstmt = CONCAT('ALTER TABLE `', param_table, '` ADD UNIQUE KEY `', param_index, '` (', param_column, ');');
        PREPARE st FROM @sqlstmt;
        EXECUTE st;
        DEALLOCATE PREPARE st;	
    END IF;
END $$
DELIMITER ;


-- insert_lookup: idempotent insert into lookups (checks by type_code+lookup_code)
DELIMITER $$
DROP PROCEDURE IF EXISTS `insert_lookup` $$
CREATE PROCEDURE `insert_lookup`(
    IN p_type_code VARCHAR(100),
    IN p_lookup_code VARCHAR(100),
    IN p_lookup_value VARCHAR(1000),
    IN p_lookup_description VARCHAR(1000)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM lookups
        WHERE type_code = p_type_code AND lookup_code = p_lookup_code
    ) THEN
        INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
        VALUES (p_type_code, p_lookup_code, p_lookup_value, p_lookup_description);
    END IF;
END $$
DELIMITER ;


-- ============================================================================
-- 2. PRE-MIGRATION SCHEMA CHANGES
-- ============================================================================

CALL add_table_column('pages', 'is_open_access', 'TINYINT DEFAULT 0');
CALL add_table_column('pages', 'is_system', 'TINYINT DEFAULT 0');

DELIMITER //
DROP PROCEDURE IF EXISTS get_user_acl //
CREATE PROCEDURE get_user_acl(
    IN param_user_id INT,
    IN param_page_id INT
)
BEGIN
    SELECT
        param_user_id  AS id_users,
        id_pages,
        MAX(acl_select) AS acl_select,
        MAX(acl_insert) AS acl_insert,
        MAX(acl_update) AS acl_update,
        MAX(acl_delete) AS acl_delete,
        keyword,
        url,                
        parent,
        is_headless,
        nav_position,
        footer_position,        
        id_type,
        id_pageAccessTypes,
        is_system
    FROM
    (
        SELECT
            ug.id_users,
            acl.id_pages,
            acl.acl_select,
            acl.acl_insert,
            acl.acl_update,
            acl.acl_delete,
            p.keyword,
            p.url,
            p.parent,
            p.is_headless,
            p.nav_position,
            p.footer_position,
            id_type,
            p.id_pageAccessTypes,
            is_system
        FROM users_groups ug
        JOIN users u             ON ug.id_users   = u.id
        JOIN acl_groups acl      ON acl.id_groups = ug.id_groups
        JOIN pages p             ON p.id           = acl.id_pages
        WHERE ug.id_users = param_user_id
          AND (param_page_id = -1 OR acl.id_pages = param_page_id)

        UNION ALL

        SELECT
            param_user_id       AS id_users,
            p.id                AS id_pages,
            1                   AS acl_select,
            0                   AS acl_insert,
            0                   AS acl_update,
            0                   AS acl_delete,
            p.keyword,
            p.url,           
            p.parent,
            p.is_headless,
            p.nav_position,
            p.footer_position,  
            id_type,          
            p.id_pageAccessTypes,
            is_system
        FROM pages p
        WHERE p.is_open_access = 1
          AND (param_page_id = -1 OR p.id = param_page_id)

    ) AS combined_acl
    GROUP BY
        id_pages, keyword, url, parent, is_headless,
        nav_position, footer_position, id_type, is_system, id_pageAccessTypes;
END //
DELIMITER ;


CREATE TABLE IF NOT EXISTS `logPerformance` (
  `id_user_activity` INT NOT NULL,
  `log` LONGTEXT,
  PRIMARY KEY (`id_user_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS refreshTokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    id_users INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (id_users)
);

-- remove open type pages and make them experiment pages with open_access flag
UPDATE pages
SET id_type = (SELECT id FROM pageType WHERE name = 'experiment'),
    is_open_Access = 1
WHERE id_type = (SELECT id FROM pageType WHERE name = 'open');

DELETE FROM pageType WHERE name = 'open';

UPDATE pages
SET is_system = 1
WHERE keyword IN ("login", "home", "profile", "missing", "no_access", "no_access_guest", "agb", "impressum", "disclaimer", "validate", "reset_password", "two-factor-authentication");

CALL drop_foreign_key('sections', 'sections_fk_owner');
CALL drop_table_column('sections', 'owner');

CALL add_table_column('styles', 'can_have_children', 'TINYINT(1) DEFAULT 0 NOT NULL');

UPDATE styles
SET can_have_children = 1
WHERE `name` IN ("htmlTag","dataContainer","tableCell","tableRow","loop","table","conditionFailed","formUserInputRecord","formUserInputLog","refContainer","entryRecord","entryList","conditionalContainer","div","formUserInput","navigationContainer","tabs","tab","link","form","figure","card","alert","validate","jumbotron","container","profile");


-- Fix scheduledJobs_reminders
CALL drop_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_dataTables');
CALL drop_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_scheduledJobs');
CALL add_primary_key('scheduledJobs_reminders', 'id_scheduledJobs, id_dataTables');
CALL add_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_dataTables', 'id_dataTables', '`dataTables` (`id`)');        
CALL add_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_scheduledJobs', 'id_scheduledJobs', '`scheduledJobs` (`id`)');        


-- ============================================================================
-- 3. DROP DEPRECATED TABLES & VIEWS
-- ============================================================================

DROP TABLE IF EXISTS `deprecated_formActions_external`;
DROP TABLE IF EXISTS `deprecated_formActions_internal`;
DROP TABLE IF EXISTS `deprecated_user_input`;
DROP TABLE IF EXISTS `deprecated_user_input_record`;
DROP VIEW IF EXISTS view_form;
DROP VIEW IF EXISTS view_user_input;


-- ============================================================================
-- 4. PLUGIN TABLE CLEANUP (conditional)
-- ============================================================================

-- Qualtrics plugin cleanup
SELECT COUNT(*) INTO @cnt FROM plugins WHERE name = 'qualtrics';
SET @plugin_missing = (@cnt = 0);

SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsActions_functions`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsActions_groups`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsSurveysResponses`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `scheduledJobs_qualtricsActions`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsReminders`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsActions`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsProjects`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `qualtricsSurveys`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP VIEW IF EXISTS `view_qualtricsActions`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP VIEW IF EXISTS `view_qualtricsSurveys`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP VIEW IF EXISTS `view_qualtricsReminders`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Chat plugin cleanup
SELECT COUNT(*) INTO @cnt FROM plugins WHERE name = 'chat';
SET @plugin_missing = (@cnt = 0);

SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `chatRecipiants`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `chat`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `chatRoom_users`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@plugin_missing, 'DROP TABLE IF EXISTS `chatRoom`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 5. DOMAIN-TO-LOOKUPS MIGRATION (FIXED)
-- ============================================================================
-- FIX: Drop foreign keys BEFORE dropping domain tables to avoid FK constraint errors

DELIMITER $$
DROP PROCEDURE IF EXISTS `MigrateDomainToLookups`$$
CREATE PROCEDURE `MigrateDomainToLookups`()
BEGIN

  -- 1) userStatus -> lookups + users.id_status
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE table_schema = DATABASE() AND table_name = 'userStatus'
  ) THEN
    INSERT IGNORE INTO lookups(type_code, lookup_code, lookup_value, lookup_description)
    SELECT 'userStatus', name, name, description
      FROM userStatus;

    CALL drop_foreign_key('users', 'fk_users_id_status');

    UPDATE users u
    JOIN userStatus us ON u.id_status = us.id
    JOIN lookups l    ON l.type_code   = 'userStatus'
                     AND l.lookup_code = us.name
    SET u.id_status = l.id;

    CALL add_foreign_key('users', 'fk_users_id_status', 'id_status', 'lookups(id)');

    DROP TABLE IF EXISTS `userStatus`;
  END IF;

  -- 2) actions (domain table) -> just drop it
  -- FIX: Drop FK from pages BEFORE dropping actions table
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE table_schema = DATABASE() AND table_name = 'actions'
  ) THEN
    IF EXISTS (
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE table_schema = DATABASE() AND table_name = 'actions'
      GROUP BY table_schema, table_name
      HAVING COUNT(*) = 2
    ) THEN
      CALL drop_foreign_key('pages', 'pages_fk_id_actions');
      DROP TABLE IF EXISTS `actions`;
    END IF;
  END IF;

  -- 3) activityType -> lookups + user_activity.id_type
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE table_schema = DATABASE() AND table_name = 'activityType'
  ) THEN
    INSERT IGNORE INTO lookups(type_code, lookup_code, lookup_value, lookup_description)
    SELECT 'activityType', name, name, NULL
      FROM activityType;

    CALL drop_foreign_key('user_activity', 'fk_user_activity_fk_id_type');

    UPDATE user_activity ua
    JOIN activityType at2 ON ua.id_type = at2.id
    JOIN lookups l       ON l.type_code   = 'activityType'
                       AND l.lookup_code = at2.name
    SET ua.id_type = l.id;

    CALL add_foreign_key('user_activity', 'fk_user_activity_fk_id_type', 'id_type', 'lookups(id)');

    DROP TABLE IF EXISTS `activityType`;
  END IF;

  -- 4) styleType -> lookups + styles.id_type
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE table_schema = DATABASE() AND table_name = 'styleType'
  ) THEN
    INSERT IGNORE INTO lookups(type_code, lookup_code, lookup_value, lookup_description)
    SELECT 'styleType', name, name, NULL
      FROM styleType;

    CALL drop_foreign_key('styles', 'styles_fk_id_type');

    UPDATE styles s
    JOIN styleType st ON s.id_type = st.id
    JOIN lookups l     ON l.type_code   = 'styleType'
                      AND l.lookup_code = st.name
    SET s.id_type = l.id;

    CALL add_foreign_key('styles', 'styles_fk_id_type', 'id_type', 'lookups(id)');

    DROP TABLE IF EXISTS `styleType`;
  END IF;

END$$
DELIMITER ;

CALL MigrateDomainToLookups();
DROP PROCEDURE IF EXISTS `MigrateDomainToLookups`;


-- ============================================================================
-- 6. VIEWS AFTER DOMAIN MIGRATION
-- ============================================================================

DROP VIEW IF EXISTS `view_styles`;
CREATE VIEW `view_styles` AS
SELECT
  CAST(s.id AS UNSIGNED) AS style_id,
  s.name AS style_name,
  s.description AS style_description,
  CAST(lst.id AS UNSIGNED) AS style_type_id,
  lst.lookup_value AS style_type,
  CAST(sg.id AS UNSIGNED) AS style_group_id,
  sg.name AS style_group,
  sg.description AS style_group_description,
  sg.position AS style_group_position
FROM styles s
LEFT JOIN lookups lst
  ON s.id_type = lst.id
  AND lst.type_code = 'styleType'
LEFT JOIN styleGroup sg
  ON s.id_group = sg.id;

DROP VIEW IF EXISTS `view_users`;
CREATE VIEW `view_users` AS
SELECT
  u.id AS id,
  u.email AS email,
  u.name AS name,
  IFNULL(
    CONCAT(u.last_login, ' (', TO_DAYS(NOW()) - TO_DAYS(u.last_login), ' days ago)'),
    'never'
  ) AS last_login,
  usl.lookup_value       AS status,
  usl.lookup_description AS description,
  u.blocked              AS blocked,
  (CASE
     WHEN u.name = 'admin' THEN 'admin'
     WHEN u.name = 'tpf'   THEN 'tpf'
     ELSE IFNULL(vc.code, '-')
   END)                  AS code,
  GROUP_CONCAT(DISTINCT g.name SEPARATOR '; ') AS `groups`,
  ua.activity_count     AS user_activity,
  ua.distinct_url_count AS ac,
  u.intern              AS intern,
  u.id_userTypes        AS id_userTypes,
  lut.lookup_code       AS user_type_code,
  lut.lookup_value      AS user_type
FROM users u
LEFT JOIN lookups usl ON usl.id = u.id_status AND usl.type_code = 'userStatus'
LEFT JOIN users_groups ug ON ug.id_users = u.id
LEFT JOIN `groups` g ON g.id = ug.id_groups
LEFT JOIN validation_codes vc ON u.id = vc.id_users
JOIN lookups lut ON lut.id = u.id_userTypes
LEFT JOIN (
  SELECT ua.id_users AS id_users, COUNT(*) AS activity_count,
    COUNT(DISTINCT CASE WHEN ua.id_type = 1 THEN ua.url END) AS distinct_url_count
  FROM user_activity ua GROUP BY ua.id_users
) AS ua ON ua.id_users = u.id
WHERE u.intern <> 1 AND u.id_status > 0
GROUP BY u.id, u.email, u.name, u.last_login, usl.lookup_value, usl.lookup_description,
  u.blocked, vc.code, ua.activity_count, ua.distinct_url_count, u.intern, u.id_userTypes,
  lut.lookup_code, lut.lookup_value
ORDER BY u.email;

CALL insert_lookup('pageActions', 'navigation', 'Navigation', 'Navigation section page');


-- ============================================================================
-- 7. CREATE NEW TABLES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `apiRequestLogs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `route_name` VARCHAR(255) DEFAULT NULL,
  `path` VARCHAR(255) NOT NULL,
  `method` VARCHAR(10) NOT NULL,
  `status_code` INT NOT NULL,
  `user_id` INT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `request_time` DATETIME NOT NULL,
  `response_time` DATETIME NOT NULL,
  `duration_ms` INT NOT NULL,
  `request_params` TEXT DEFAULT NULL,
  `request_headers` TEXT DEFAULT NULL,
  `response_data` TEXT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 8. DOCTRINE MIGRATION: Drop old FKs, alter columns, add new FKs/indexes
-- ============================================================================

-- Drop all old foreign keys
CALL drop_foreign_key('acl_groups', 'fk_acl_groups_id_groups');
CALL drop_foreign_key('acl_groups', 'fk_acl_groups_id_pages');
CALL drop_foreign_key('acl_users', 'acl_fk_id_pages');
CALL drop_foreign_key('acl_users', 'acl_fk_id_users');
CALL drop_foreign_key('assets', 'assets_fk_id_assetTypes');
CALL drop_foreign_key('codes_groups', 'fk_codes');
CALL drop_foreign_key('codes_groups', 'fk_id_groups');
CALL drop_foreign_key('dataCells', 'uploadCells_fk_id_uploadCols');
CALL drop_foreign_key('dataCells', 'uploadCells_fk_id_uploadRows');
CALL drop_foreign_key('dataCols', 'uploadCols_fk_id_uploadTables');
CALL drop_foreign_key('dataRows', 'uploadRows_fk_id_actionTriggerTypes');
CALL drop_foreign_key('dataRows', 'uploadRows_fk_id_uploadTables');
CALL drop_foreign_key('dataRows', 'uploadRows_fk_id_users');
CALL drop_foreign_key('fields', 'fields_fk_id_type');
CALL drop_foreign_key('formActions', 'formActions_id_dataTables');
CALL drop_foreign_key('genders', 'genders_fk_id_something');
CALL drop_foreign_key('groups', 'groups_fk_id_group_types');
CALL drop_foreign_key('hooks', 'hooks_fk_id_hookTypes');
CALL drop_foreign_key('logPerformance', 'logperformance_ibfk_1');
CALL drop_foreign_key('mailAttachments', 'mailAttachments_fk_id_mailQueue');
CALL drop_foreign_key('pages', 'pages_fk_id_actions');
CALL drop_foreign_key('pages', 'pages_fk_id_navigation_section');
CALL drop_foreign_key('pages', 'pages_fk_id_type');
CALL drop_foreign_key('pages', 'pages_fk_parent');
CALL drop_foreign_key('pages_fields', 'fk_page_fields_id_fields');
CALL drop_foreign_key('pages_fields', 'fk_page_fields_id_pages');
CALL drop_foreign_key('pages_fields_translation', 'pages_fields_translation_fk_id_fields');
CALL drop_foreign_key('pages_fields_translation', 'pages_fields_translation_fk_id_languages');
CALL drop_foreign_key('pages_fields_translation', 'pages_fields_translation_fk_id_pages');
CALL drop_foreign_key('pages_sections', 'pages_sections_fk_id_pages');
CALL drop_foreign_key('pages_sections', 'pages_sections_fk_id_sections');
CALL drop_foreign_key('pageType_fields', 'fk_pageType_fields_id_fields');
CALL drop_foreign_key('pageType_fields', 'fk_pageType_fields_id_pageType');
CALL drop_foreign_key('scheduledJobs', 'scheduledJobs_fk_id_jobStatus');
CALL drop_foreign_key('scheduledJobs', 'scheduledJobs_fk_id_jobTypes');
CALL drop_foreign_key('scheduledJobs_formActions', 'scheduledJobs_formActions_fk_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_formActions', 'scheduledJobs_formActions_fk_iid_formActions');
CALL drop_foreign_key('scheduledJobs_formActions', 'scheduledJobs_formActions_id_dataRows');
CALL drop_foreign_key('scheduledJobs_mailQueue', 'scheduledJobs_mailQueue_fk_id_mailQueue');
CALL drop_foreign_key('scheduledJobs_mailQueue', 'scheduledJobs_mailQueue_fk_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_notifications', 'scheduledJobs_notifications_fk_id_notifications');
CALL drop_foreign_key('scheduledJobs_notifications', 'scheduledJobs_notifications_fk_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_dataTables');
CALL drop_foreign_key('scheduledJobs_reminders', 'scheduledJobs_reminders_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_tasks', 'scheduledJobs_tasks_fk_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_tasks', 'scheduledJobs_tasks_fk_id_tasks');
CALL drop_foreign_key('scheduledJobs_users', 'scheduledJobs_users_fk_id_users');
CALL drop_foreign_key('scheduledJobs_users', 'scheduledJobs_users_fk_scheduledJobs');
CALL drop_foreign_key('sections', 'sections_fk_id_styles');
CALL drop_foreign_key('sections_fields_translation', 'sections_fields_translation_fk_id_fields');
CALL drop_foreign_key('sections_fields_translation', 'sections_fields_translation_fk_id_genders');
CALL drop_foreign_key('sections_fields_translation', 'sections_fields_translation_fk_id_languages');
CALL drop_foreign_key('sections_fields_translation', 'sections_fields_translation_fk_id_sections');
CALL drop_foreign_key('sections_hierarchy', 'sections_hierarchy_fk_child');
CALL drop_foreign_key('sections_hierarchy', 'sections_hierarchy_fk_parent');
CALL drop_foreign_key('sections_navigation', 'sections_navigation_fk_child');
CALL drop_foreign_key('sections_navigation', 'sections_navigation_fk_id_pages');
CALL drop_foreign_key('sections_navigation', 'sections_navigation_fk_parent');
CALL drop_foreign_key('styles', 'styles_fk_id_group');
CALL drop_foreign_key('styles', 'styles_fk_id_type');
CALL drop_foreign_key('styles_fields', 'styles_fields_fk_id_fields');
CALL drop_foreign_key('styles_fields', 'styles_fields_fk_id_styles');
CALL drop_foreign_key('transactions', 'transactions_fk_id_transactionBy');
CALL drop_foreign_key('transactions', 'transactions_fk_id_transactionTypes');
CALL drop_foreign_key('transactions', 'transactions_fk_id_users');
CALL drop_foreign_key('users', 'fk_users_id_genders');
CALL drop_foreign_key('users', 'fk_users_id_languages');
CALL drop_foreign_key('users', 'fk_users_id_status');
CALL drop_foreign_key('user_activity', 'fk_user_activity_fk_id_type');
CALL drop_foreign_key('user_activity', 'fk_user_activity_fk_id_users');
CALL drop_foreign_key('users_groups', 'fk_users_groups_id_groups');
CALL drop_foreign_key('users_groups', 'fk_users_groups_id_users');
CALL drop_foreign_key('validation_codes', 'validation_codes_fk_id_users');
CALL drop_foreign_key('cmsPreferences', 'fk_cmsPreferences_language');
CALL drop_foreign_key('groups', 'groups_fk_id_group_types');
CALL drop_foreign_key('pages', 'pages_fk_id_pacgeAccessTypes');
CALL drop_foreign_key('users', 'FK_1483A5E93F6026C1');

-- Missing FK drops: refresh_events tables (reference sections, users)
CALL drop_foreign_key('refresh_events_sections', 'fk_refresh_sections_sections');
CALL drop_foreign_key('refresh_events_sections', 'fk_refresh_sections_events');
CALL drop_foreign_key('refresh_events', 'fk_refresh_events_users');

-- Missing FK drops: chat plugin tables (safe no-op if tables don't exist)
CALL drop_foreign_key('chat', 'fk_chat_id_rcv_user');
CALL drop_foreign_key('chat', 'fk_chat_id_send');
CALL drop_foreign_key('chat', 'fk_chat_id_rcv_group');
CALL drop_foreign_key('chatRecipiants', 'chatRecipiants_fk_id_chat');
CALL drop_foreign_key('chatRecipiants', 'chatRecipiants_fk_id_room_users');
CALL drop_foreign_key('chatRecipiants', 'chatRecipiants_fk_id_users');

-- Missing FK drops: qualtrics plugin tables (safe no-op if tables don't exist)
CALL drop_foreign_key('qualtricsActions', 'qualtricsActions_fk_id_lookups_qualtricsProjectActionTriggerType');
CALL drop_foreign_key('qualtricsActions', 'qualtricsActions_fk_id_qualtricsActionScheduleTypes');
CALL drop_foreign_key('qualtricsActions', 'qualtricsActions_fk_id_qualtricsProjects');
CALL drop_foreign_key('qualtricsActions', 'qualtricsActions_fk_id_qualtricsSurveys');
CALL drop_foreign_key('qualtricsActions', 'qualtricsActions_fk_id_qualtricsSurveys_reminder');
CALL drop_foreign_key('qualtricsActions_functions', 'qualtricsActions_functions_fk_id_lookups');
CALL drop_foreign_key('qualtricsActions_functions', 'qualtricsActions_functions_fk_id_qualtricsActions');
CALL drop_foreign_key('qualtricsActions_groups', 'qualtricsActions_groups_fk_id_groups');
CALL drop_foreign_key('qualtricsActions_groups', 'qualtricsActions_groups_fk_id_qualtricsActions');
CALL drop_foreign_key('qualtricsReminders', 'qualtricsReminders_fk_id_qualtricsSurveys');
CALL drop_foreign_key('qualtricsReminders', 'qualtricsReminders_fk_id_scheduledJobs');
CALL drop_foreign_key('qualtricsReminders', 'qualtricsReminders_fk_id_users');
CALL drop_foreign_key('qualtricsSurveys', 'qualtricsSurveys_fk_id_qualtricsSurveyTypes');
CALL drop_foreign_key('qualtricsSurveys_responses', 'qSurveysResponses_fk_id_qualtricsProjectActionTriggerTypes');
CALL drop_foreign_key('qualtricsSurveys_responses', 'qSurveysResponses_fk_id_surveys');
CALL drop_foreign_key('qualtricsSurveys_responses', 'qSurveysResponses_fk_id_users');
CALL drop_foreign_key('scheduledJobs_qualtricsActions', 'scheduledJobs_qualtricsActions_fk_id_scheduledJobs');
CALL drop_foreign_key('scheduledJobs_qualtricsActions', 'scheduledJobs_qualtricsActions_fk_iid_qualtricsActions');

-- Drop old indexes
CALL drop_index('acl_groups', 'id_pages');
CALL drop_index('acl_groups', 'id_groups');
CALL drop_index('assets', 'assets_fk_id_assetTypes');
CALL drop_index('assets', 'file_name');
CALL drop_index('cmsPreferences', 'fk_cmspreferences_language');
CALL drop_index('codes_groups', 'fk_id_groups');
CALL drop_index('codes_groups', 'IDX_9F20ED7677153098');
CALL drop_index('dataCells', 'idx_uploadCells_value');
CALL drop_index('dataCols', 'unique_name_id_dataTables');
CALL drop_index('dataRows', 'idx_uploadRows_timestamp');
CALL drop_index('dataRows', 'uploadRows_fk_id_actionTriggerTypes');
CALL drop_index('dataRows', 'uploadRows_fk_id_users');
CALL drop_index('dataTables', 'idx_uploadTables_name_timestamp');
CALL drop_index('dataTables', 'uploadTables_name');
CALL drop_index('fields', 'fields_name');
CALL drop_index('fields', 'id_type');
CALL drop_index('fieldType', 'fieldType_name');
CALL drop_index('groups', 'name');
CALL drop_index('hooks', 'hooks_fk_id_hookTypes');
CALL drop_index('hooks', 'name');
CALL drop_index('languages', 'language');
CALL drop_index('languages', 'locale');
CALL drop_index('libraries', 'name');
CALL drop_index('lookups', 'idx_lookups_type_code_lookup_code');
CALL drop_index('mailAttachments', 'mailAttachments_fk_id_mailQueue');
CALL drop_index('plugins', 'plugins_name');
CALL drop_index('refreshTokens', 'idx_token_hash');
CALL drop_index('scheduledJobs', 'scheduledJobs_fk_id_jobStatus');
CALL drop_index('scheduledJobs', 'scheduledJobs_fk_id_jobTypes');
CALL drop_index('scheduledJobs_formActions', 'scheduledJobs_formActions_fk_iid_formActions');
CALL drop_index('scheduledJobs_formActions', 'scheduledJobs_formActions_id_dataRows');
CALL drop_index('scheduledJobs_formActions', 'IDX_AE5B5D0B8030BA52');
CALL drop_index('scheduledJobs_mailQueue', 'scheduledJobs_mailQueue_fk_id_mailQueue');
CALL drop_index('scheduledJobs_mailQueue', 'IDX_E560A18030BA52');
CALL drop_index('scheduledJobs_notifications', 'scheduledJobs_notifications_fk_id_notifications');
CALL drop_index('scheduledJobs_notifications', 'IDX_9879806C8030BA52');
CALL drop_index('sections', 'name');
CALL drop_index('styles_fields', 'id_fields');
CALL drop_index('styles_fields', 'id_styles');
CALL drop_index('transactions', 'idx_transactions_table_name');
CALL drop_index('users', 'id_genders');
CALL drop_index('users', 'id_languages');
CALL drop_index('users', 'id_status');
CALL drop_index('acl_users', 'id_pages');
CALL drop_index('acl_users', 'id_users');
CALL drop_index('dataCells', 'id_uploadCols');
CALL drop_index('dataCells', 'id_uploadRows');
CALL drop_index('dataCols', 'id_uploadTables');
CALL drop_index('dataRows', 'id_uploadTables');
CALL drop_index('groups', 'groups_fk_id_group_types');
CALL drop_index('pages', 'id_actions');
CALL drop_index('pages', 'id_navigation_section');
CALL drop_index('pages', 'id_type');
CALL drop_index('pages', 'keyword');
CALL drop_index('pages', 'pages_fk_id_pacgeAccessTypes');
CALL drop_index('pages', 'parent');
CALL drop_index('pages_fields', 'id_fields');
CALL drop_index('pages_fields', 'id_pages');
CALL drop_index('pages_fields_translation', 'id_fields');
CALL drop_index('pages_fields_translation', 'id_languages');
CALL drop_index('pages_fields_translation', 'id_pages');
CALL drop_index('pages_sections', 'id_pages');
CALL drop_index('pages_sections', 'id_sections');
CALL drop_index('pageType', 'pageType_name');
CALL drop_index('refreshtokens', 'idx_user_id');
CALL drop_index('scheduledJobs_tasks', 'scheduledJobs_tasks_fk_id_tasks');
CALL drop_index('scheduledJobs_users', 'scheduledJobs_users_fk_scheduledJobs');
CALL drop_index('sections', 'id_styles');
CALL drop_index('sections_fields_translation', 'id_fields');
CALL drop_index('sections_fields_translation', 'id_genders');
CALL drop_index('sections_fields_translation', 'id_languages');
CALL drop_index('sections_fields_translation', 'id_sections');
CALL drop_index('sections_hierarchy', 'child');
CALL drop_index('sections_hierarchy', 'parent');
CALL drop_index('sections_navigation', 'child');
CALL drop_index('sections_navigation', 'id_pages');
CALL drop_index('sections_navigation', 'parent');
CALL drop_index('styles', 'id_group');
CALL drop_index('styles', 'id_type');
CALL drop_index('styles', 'styles_name');
CALL drop_index('transactions', 'transactions_fk_id_transactionBy');
CALL drop_index('transactions', 'transactions_fk_id_transactionTypes');
CALL drop_index('transactions', 'transactions_fk_id_users');
CALL drop_index('users', 'email');
CALL drop_index('users', 'user_name');
CALL drop_index('user_activity', 'id_type');
CALL drop_index('user_activity', 'id_users');
CALL drop_index('users_groups', 'id_groups');
CALL drop_index('users_groups', 'id_users');
CALL drop_index('validation_codes', 'id_users');

-- Alter column types (remove zerofill, normalize types for Doctrine)
ALTER TABLE acl_groups CHANGE id_groups id_groups INT NOT NULL, CHANGE id_pages id_pages INT NOT NULL;
ALTER TABLE acl_users CHANGE id_users id_users INT NOT NULL, CHANGE id_pages id_pages INT NOT NULL;
ALTER TABLE apiRequestLogs CHANGE request_params request_params LONGTEXT DEFAULT NULL, CHANGE request_headers request_headers LONGTEXT DEFAULT NULL, CHANGE response_data response_data LONGTEXT DEFAULT NULL, CHANGE error_message error_message LONGTEXT DEFAULT NULL;
ALTER TABLE assets CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_assetTypes id_assetTypes INT NOT NULL;
ALTER TABLE cmsPreferences CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE default_language_id default_language_id INT DEFAULT NULL, CHANGE anonymous_users anonymous_users INT DEFAULT 0 NOT NULL;
ALTER TABLE codes_groups CHANGE id_groups id_groups INT NOT NULL;
ALTER TABLE dataCells CHANGE id_dataRows id_dataRows INT NOT NULL, CHANGE id_dataCols id_dataCols INT NOT NULL;
ALTER TABLE dataCols CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_dataTables id_dataTables INT DEFAULT NULL;
ALTER TABLE dataRows CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_dataTables id_dataTables INT DEFAULT NULL, CHANGE timestamp timestamp DATETIME NOT NULL, CHANGE id_users id_users INT DEFAULT NULL, CHANGE id_actionTriggerTypes id_actionTriggerTypes INT DEFAULT NULL;
ALTER TABLE dataTables CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE timestamp timestamp DATETIME NOT NULL;
ALTER TABLE `fields` CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_type id_type INT NOT NULL, CHANGE display display TINYINT(1) NOT NULL;       
ALTER TABLE fieldType CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE formActions CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_formProjectActionTriggerTypes id_formProjectActionTriggerTypes INT NOT NULL, CHANGE config config LONGTEXT DEFAULT NULL, CHANGE id_dataTables id_dataTables INT DEFAULT NULL;
ALTER TABLE genders CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE `groups` CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_group_types id_group_types INT DEFAULT NULL, CHANGE requires_2fa requires_2fa TINYINT(1) NOT NULL;
ALTER TABLE hooks CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_hookTypes id_hookTypes INT NOT NULL, CHANGE priority priority INT DEFAULT 10 NOT NULL;
ALTER TABLE languages CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE locale locale VARCHAR(5) NOT NULL, CHANGE csv_separator csv_separator VARCHAR(1) NOT NULL;
ALTER TABLE libraries CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE logPerformance CHANGE id_user_activity id_user_activity INT NOT NULL;
ALTER TABLE lookups CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE mailAttachments CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_mailQueue id_mailQueue INT NOT NULL, CHANGE template_path template_path VARCHAR(1000) NOT NULL;
ALTER TABLE mailQueue CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE recipient_emails recipient_emails LONGTEXT NOT NULL, CHANGE is_html is_html TINYINT(1) NOT NULL;
ALTER TABLE notifications CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE pages CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_actions id_actions INT DEFAULT NULL, CHANGE id_navigation_section id_navigation_section INT DEFAULT NULL, CHANGE parent parent INT DEFAULT NULL, CHANGE id_type id_type INT NOT NULL, CHANGE protocol protocol VARCHAR(100) DEFAULT NULL COMMENT 'pipe separated list of HTTP Methods (GET|POST)', CHANGE id_pageAccessTypes id_pageAccessTypes INT DEFAULT NULL;
-- refresh_events tables will be dropped in 39b (no Doctrine entity, no code usage)
ALTER TABLE pages_fields CHANGE id_pages id_pages INT NOT NULL, CHANGE id_fields id_fields INT NOT NULL;
ALTER TABLE pages_fields_translation CHANGE id_pages id_pages INT NOT NULL, CHANGE id_fields id_fields INT NOT NULL, CHANGE id_languages id_languages INT NOT NULL;
ALTER TABLE pages_sections CHANGE id_pages id_pages INT NOT NULL, CHANGE id_sections id_sections INT NOT NULL;
ALTER TABLE pageType CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE pageType_fields CHANGE id_fields id_fields INT NOT NULL, CHANGE id_pageType id_pageType INT NOT NULL;
ALTER TABLE plugins CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE refreshTokens CHANGE id_users id_users INT NOT NULL;
ALTER TABLE scheduledJobs CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_jobTypes id_jobTypes INT NOT NULL, CHANGE id_jobStatus id_jobStatus INT NOT NULL, CHANGE date_create date_create DATETIME NOT NULL;
CALL rename_table_column('scheduledJobs_formActions', 'id_formActions', 'id_actions', NULL);
ALTER TABLE scheduledJobs_formActions CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL, CHANGE id_actions id_actions INT NOT NULL, CHANGE id_dataRows id_dataRows INT DEFAULT NULL;
ALTER TABLE scheduledJobs_mailQueue CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL, CHANGE id_mailQueue id_mailQueue INT NOT NULL;
ALTER TABLE scheduledJobs_notifications CHANGE id_notifications id_notifications INT NOT NULL, CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL;
ALTER TABLE scheduledJobs_reminders CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL, CHANGE id_dataTables id_dataTables INT NOT NULL;
ALTER TABLE scheduledJobs_tasks CHANGE id_tasks id_tasks INT NOT NULL, CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL;
ALTER TABLE scheduledJobs_users CHANGE id_users id_users INT NOT NULL, CHANGE id_scheduledJobs id_scheduledJobs INT NOT NULL;
ALTER TABLE sections CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_styles id_styles INT NOT NULL;
ALTER TABLE sections_fields_translation CHANGE id_sections id_sections INT NOT NULL, CHANGE id_fields id_fields INT NOT NULL, CHANGE id_languages id_languages INT NOT NULL, CHANGE id_genders id_genders INT NOT NULL;
ALTER TABLE sections_hierarchy CHANGE parent parent INT NOT NULL, CHANGE child child INT NOT NULL;
ALTER TABLE sections_navigation CHANGE parent parent INT NOT NULL, CHANGE child child INT NOT NULL, CHANGE id_pages id_pages INT NOT NULL;
ALTER TABLE styleGroup CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE styles CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE styles CHANGE id_type id_type INT NOT NULL, CHANGE id_group id_group INT NOT NULL;
ALTER TABLE styles_fields CHANGE id_styles id_styles INT NOT NULL;
ALTER TABLE styles_fields CHANGE id_fields id_fields INT NOT NULL, CHANGE disabled disabled TINYINT(1) NOT NULL, CHANGE hidden hidden INT DEFAULT NULL;
ALTER TABLE tasks CHANGE id id INT AUTO_INCREMENT NOT NULL;
ALTER TABLE transactions CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_users id_users INT DEFAULT NULL, CHANGE transaction_time transaction_time DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE id_transactionTypes id_transactionTypes INT DEFAULT NULL, CHANGE id_transactionBy id_transactionBy INT DEFAULT NULL, CHANGE id_table_name id_table_name INT DEFAULT NULL, CHANGE transaction_log transaction_log LONGTEXT DEFAULT NULL;
ALTER TABLE users CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_genders id_genders INT DEFAULT NULL, CHANGE id_status id_status INT DEFAULT 1, CHANGE id_languages id_languages INT DEFAULT NULL, CHANGE id_userTypes id_userTypes INT NOT NULL;
ALTER TABLE user_activity CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_users id_users INT NOT NULL, CHANGE id_type id_type INT NOT NULL, CHANGE timestamp timestamp DATETIME NOT NULL;
ALTER TABLE users_2fa_codes CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE id_users id_users INT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE is_used is_used TINYINT(1) NOT NULL;
ALTER TABLE users_groups CHANGE id_users id_users INT NOT NULL, CHANGE id_groups id_groups INT NOT NULL;
CALL add_table_column('validation_codes', 'id_groups', 'INT DEFAULT NULL');
ALTER TABLE validation_codes CHANGE id_users id_users INT DEFAULT NULL, CHANGE created created DATETIME NOT NULL;  
ALTER TABLE version CHANGE id id INT AUTO_INCREMENT NOT NULL;

-- Add new Doctrine foreign keys
CALL add_foreign_key('acl_groups', 'FK_AB370E20D65A8C9D', 'id_groups', '`groups`(id)');
CALL add_foreign_key('acl_groups', 'FK_AB370E20CEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('acl_users', 'FK_901AE856FA06E4D9', 'id_users', 'users(id)');
CALL add_foreign_key('acl_users', 'FK_901AE856CEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('dataCells', 'FK_726A5F25F3854F45', 'id_dataRows', 'dataRows(id)');
CALL add_foreign_key('dataCells', 'FK_726A5F25B216B425', 'id_dataCols', 'dataCols(id)');
CALL add_foreign_key('dataCols', 'FK_E2CD58B0E2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_foreign_key('dataRows', 'FK_A35EA3D0E2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_foreign_key('fields', 'FK_7EE5E388FF2309B7', 'id_type', 'fieldType(id)');
CALL add_foreign_key('formActions', 'FK_3128FB5E8A8FCE9D', 'id_formProjectActionTriggerTypes', 'lookups(id)');
CALL add_foreign_key('formActions', 'FK_3128FB5EE2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_foreign_key('logPerformance', 'FK_6D164595F2D13C3F', 'id_user_activity', 'user_activity(id)');
CALL add_foreign_key('pages', 'FK_2074E575DBD5589F', 'id_actions', 'lookups(id)');
CALL add_foreign_key('pages', 'FK_2074E575E8D3C633', 'id_navigation_section', 'sections(id)');
CALL add_foreign_key('pages', 'FK_2074E5753D8E604F', 'parent', 'pages(id)');
CALL add_foreign_key('pages', 'FK_2074E5757FE4B2B', 'id_type', 'pageType(id)');
CALL add_foreign_key('pages_fields', 'FK_D36F9887CEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('pages_fields', 'FK_D36F988758D25665', 'id_fields', 'fields(id)');
CALL add_foreign_key('pages_fields_translation', 'FK_903943EECEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('pages_fields_translation', 'FK_903943EE58D25665', 'id_fields', 'fields(id)');
CALL add_foreign_key('pages_fields_translation', 'FK_903943EE20E4EF5E', 'id_languages', 'languages(id)');
CALL add_foreign_key('pages_sections', 'FK_6BD95A69CEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('pages_sections', 'FK_6BD95A697B4DAF0D', 'id_sections', 'sections(id)');
CALL add_foreign_key('pageType_fields', 'FK_B305C681FDE305E9', 'id_pageType', 'pageType(id)');
CALL add_foreign_key('pageType_fields', 'FK_B305C68158D25665', 'id_fields', 'fields(id)');
CALL add_foreign_key('scheduledJobs_reminders', 'FK_23156A608030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('scheduledJobs_reminders', 'FK_23156A60E2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_foreign_key('scheduledJobs_tasks', 'FK_96A54FA88030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('scheduledJobs_tasks', 'FK_96A54FA8BEDD24A7', 'id_tasks', 'tasks(id)');
CALL add_foreign_key('scheduledJobs_users', 'FK_D27E8FD6FA06E4D9', 'id_users', 'users(id)');
CALL add_foreign_key('scheduledJobs_users', 'FK_D27E8FD68030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('sections', 'FK_2B964398906D4F18', 'id_styles', 'styles(id)');
CALL add_foreign_key('sections_fields_translation', 'FK_EC5054157B4DAF0D', 'id_sections', 'sections(id)');
CALL add_foreign_key('sections_fields_translation', 'FK_EC50541558D25665', 'id_fields', 'fields(id)');
CALL add_foreign_key('sections_fields_translation', 'FK_EC50541520E4EF5E', 'id_languages', 'languages(id)');
CALL add_foreign_key('sections_fields_translation', 'FK_EC5054155D8601CD', 'id_genders', 'genders(id)');
CALL add_foreign_key('sections_hierarchy', 'FK_A6D0AE7C3D8E604F', 'parent', 'sections(id)');
CALL add_foreign_key('sections_hierarchy', 'FK_A6D0AE7C22B35429', 'child', 'sections(id)');
CALL add_foreign_key('sections_navigation', 'FK_21BBDC413D8E604F', 'parent', 'sections(id)');
CALL add_foreign_key('sections_navigation', 'FK_21BBDC4122B35429', 'child', 'sections(id)');
CALL add_foreign_key('sections_navigation', 'FK_21BBDC41CEF1A445', 'id_pages', 'pages(id)');
CALL add_foreign_key('styles', 'FK_B65AFAF57FE4B2B', 'id_type', 'lookups(id)');
CALL add_foreign_key('styles', 'FK_B65AFAF5834505F5', 'id_group', 'styleGroup(id)');
CALL add_foreign_key('styles_fields', 'FK_4F23ED261DF44B12', 'id_fields', 'fields(id)');
CALL add_foreign_key('styles_fields', 'FK_4F23ED26D54B526F', 'id_styles', 'styles(id)');
CALL add_foreign_key('transactions', 'FK_EAA81A4CC41DBD5F', 'id_transactionTypes', 'lookups(id)');
CALL add_foreign_key('transactions', 'FK_EAA81A4CFC2E5563', 'id_transactionBy', 'lookups(id)');
CALL add_foreign_key('transactions', 'FK_EAA81A4CFA06E4D9', 'id_users', 'users(id)');
CALL add_foreign_key('user_activity', 'FK_4CF9ED5AFA06E4D9', 'id_users', 'users(id)');
CALL add_foreign_key('user_activity', 'FK_4CF9ED5A7FE4B2B', 'id_type', 'lookups(id)');
CALL add_foreign_key('users_groups', 'FK_FF8AB7E0FA06E4D9', 'id_users', 'users(id)');
CALL add_foreign_key('users_groups', 'FK_FF8AB7E0D65A8C9D', 'id_groups', '`groups`(id)');
CALL add_foreign_key('validation_codes', 'FK_DBEC45EFA06E4D9', 'id_users', 'users(id)');
-- refresh_events FK re-adds removed: tables will be dropped in 39b
CALL add_foreign_key('refreshTokens', 'FK_BFB6788AFA06E4D9', 'id_users', 'users(id)');

-- Add new Doctrine indexes
CALL add_index('acl_groups', 'IDX_AB370E20D65A8C9D', 'id_groups', FALSE);
CALL add_index('acl_groups', 'IDX_AB370E20CEF1A445', 'id_pages', FALSE);
CALL add_index('acl_users', 'IDX_901AE856FA06E4D9', 'id_users', FALSE);
CALL add_index('acl_users', 'IDX_901AE856CEF1A445', 'id_pages', FALSE);
CALL add_index('assets', 'UNIQ_79D17D8ED7DF1668', 'file_name', TRUE);
CALL add_index('cmsPreferences', 'IDX_3F26A2DF5602A942', 'default_language_id', FALSE);
CALL add_index('dataCells', 'IDX_726A5F25F3854F45', 'id_dataRows', FALSE);
CALL add_index('dataCells', 'IDX_726A5F25B216B425', 'id_dataCols', FALSE);
CALL add_index('dataCols', 'IDX_E2CD58B0E2E6A7C3', 'id_dataTables', FALSE);
CALL add_index('dataRows', 'IDX_A35EA3D0E2E6A7C3', 'id_dataTables', FALSE);
CALL add_index('styles_fields', 'IDX_4F23ED26906D4F18', 'id_styles', FALSE);
CALL add_index('styles_fields', 'IDX_4F23ED2658D25665', 'id_fields', FALSE);
CALL add_index('pages', 'UNIQ_2074E5755A93713B', 'keyword', TRUE);
CALL add_index('pages', 'IDX_2074E575DBD5589F', 'id_actions', FALSE);
CALL add_index('pages', 'IDX_2074E575E8D3C633', 'id_navigation_section', FALSE);
CALL add_index('pages', 'IDX_2074E5753D8E604F', 'parent', FALSE);
CALL add_index('pages', 'IDX_2074E5757FE4B2B', 'id_type', FALSE);
CALL add_index('pages', 'IDX_2074E57534643D90', 'id_pageAccessTypes', FALSE);
CALL add_index('pages_fields', 'IDX_D36F9887CEF1A445', 'id_pages', FALSE);
CALL add_index('pages_fields', 'IDX_D36F988758D25665', 'id_fields', FALSE);
CALL add_index('pages_fields_translation', 'IDX_903943EECEF1A445', 'id_pages', FALSE);
CALL add_index('pages_fields_translation', 'IDX_903943EE58D25665', 'id_fields', FALSE);
CALL add_index('pages_fields_translation', 'IDX_903943EE20E4EF5E', 'id_languages', FALSE);
CALL add_index('pages_sections', 'IDX_6BD95A69CEF1A445', 'id_pages', FALSE);
CALL add_index('pages_sections', 'IDX_6BD95A697B4DAF0D', 'id_sections', FALSE);
CALL add_index('pageType', 'UNIQ_AD38E97C5E237E06', 'name', TRUE);
CALL add_index('pageType_fields', 'IDX_B305C681FDE305E9', 'id_pageType', FALSE);
CALL add_index('pageType_fields', 'IDX_B305C68158D25665', 'id_fields', FALSE);
CALL add_index('refreshTokens', 'IDX_BFB6788AFA06E4D9', 'id_users', FALSE);
CALL add_index('scheduledJobs_reminders', 'IDX_23156A60E2E6A7C3', 'id_dataTables', FALSE);
CALL add_index('scheduledJobs_tasks', 'IDX_96A54FA8BEDD24A7', 'id_tasks', FALSE);
CALL add_index('scheduledJobs_users', 'IDX_D27E8FD68030BA52', 'id_scheduledJobs', FALSE);
CALL add_index('sections', 'IDX_2B964398906D4F18', 'id_styles', FALSE);
CALL add_index('sections_fields_translation', 'IDX_EC5054157B4DAF0D', 'id_sections', FALSE);
CALL add_index('sections_fields_translation', 'IDX_EC50541558D25665', 'id_fields', FALSE);
CALL add_index('sections_fields_translation', 'IDX_EC50541520E4EF5E', 'id_languages', FALSE);
CALL add_index('sections_fields_translation', 'IDX_EC5054155D8601CD', 'id_genders', FALSE);
CALL add_index('sections_hierarchy', 'IDX_A6D0AE7C3D8E604F', 'parent', FALSE);
CALL add_index('sections_hierarchy', 'IDX_A6D0AE7C22B35429', 'child', FALSE);
CALL add_index('sections_navigation', 'IDX_21BBDC413D8E604F', 'parent', FALSE);
CALL add_index('sections_navigation', 'IDX_21BBDC4122B35429', 'child', FALSE);
CALL add_index('sections_navigation', 'IDX_21BBDC41CEF1A445', 'id_pages', FALSE);
CALL add_index('styles', 'UNIQ_B65AFAF55E237E06', 'name', TRUE);
CALL add_index('styles', 'IDX_B65AFAF57FE4B2B', 'id_type', FALSE);
CALL add_index('styles', 'IDX_B65AFAF5834505F5', 'id_group', FALSE);
CALL add_index('transactions', 'IDX_EAA81A4CC41DBD5F', 'id_transactionTypes', FALSE);
CALL add_index('transactions', 'IDX_EAA81A4CFC2E5563', 'id_transactionBy', FALSE);
CALL add_index('transactions', 'IDX_EAA81A4CFA06E4D9', 'id_users', FALSE);
CALL add_index('users', 'UNIQ_1483A5E9E7927C74', 'email', TRUE);
CALL add_index('users', 'UNIQ_1483A5E924A232CF', 'user_name', TRUE);
CALL add_index('user_activity', 'IDX_4CF9ED5AFA06E4D9', 'id_users', FALSE);
CALL add_index('user_activity', 'IDX_4CF9ED5A7FE4B2B', 'id_type', FALSE);
CALL add_index('users_2fa_codes', 'IDX_65A1E404FA06E4D9', 'id_users', FALSE);
CALL add_index('users_groups', 'IDX_FF8AB7E0FA06E4D9', 'id_users', FALSE);
CALL add_index('users_groups', 'IDX_FF8AB7E0D65A8C9D', 'id_groups', FALSE);
CALL add_index('validation_codes', 'IDX_DBEC45EFA06E4D9', 'id_users', FALSE);
CALL add_index('validation_codes', 'IDX_DBEC45ED65A8C9D', 'id_groups', FALSE);
CALL add_index('users', 'IDX_1483A5E93F6026C1', 'id_userTypes', FALSE);
CALL add_index('fields', 'IDX_7EE5E3887FE4B2B', 'id_type', FALSE);

-- Remaining FK and index additions
CALL add_foreign_key('users', 'FK_1483A5E93F6026C1', 'id_userTypes', 'lookups(id)');
CALL add_foreign_key('cmsPreferences', 'FK_3F26A2DF5602A942', 'default_language_id', 'languages(id)');
CALL add_foreign_key('pages', 'FK_2074E57534643D90', 'id_pageAccessTypes', 'lookups (id)');
CALL add_foreign_key('validation_codes', 'FK_DBEC45ED65A8C9D', 'id_groups', '`groups` (id)');

CALL add_foreign_key('codes_groups', 'FK_9F20ED7677153098', 'code', 'validation_codes(code)');
CALL add_foreign_key('codes_groups', 'FK_9F20ED76D65A8C9D', 'id_groups', '`groups`(id)');
CALL add_index('codes_groups', 'IDX_9F20ED7677153098', 'code', FALSE);
CALL add_index('codes_groups', 'IDX_9F20ED76D65A8C9D', 'id_groups', FALSE);

CALL add_foreign_key('mailAttachments', 'FK_76D06F85CE570F32', 'id_mailQueue', 'mailQueue(id)');
CALL add_index('mailAttachments', 'IDX_76D06F85CE570F32', 'id_mailQueue', FALSE);

CALL add_foreign_key('scheduledJobs', 'FK_3E186B3777FD8DE1', 'id_jobStatus', 'lookups(id)');
CALL add_foreign_key('scheduledJobs', 'FK_3E186B3712C34CFB', 'id_jobTypes', 'lookups(id)');
CALL add_index('scheduledJobs', 'IDX_3E186B3777FD8DE1', 'id_jobStatus', FALSE);
CALL add_index('scheduledJobs', 'IDX_3E186B3712C34CFB', 'id_jobTypes', FALSE);

CALL add_foreign_key('scheduledJobs_formActions', 'FK_AE5B5D0B8030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('scheduledJobs_formActions', 'FK_AE5B5D0BF3854F45', 'id_dataRows', 'dataRows(id)');
CALL add_index('scheduledJobs_formActions', 'IDX_AE5B5D0B8030BA52', 'id_scheduledJobs', FALSE);
CALL add_index('scheduledJobs_formActions', 'IDX_AE5B5D0BF3854F45', 'id_dataRows', FALSE);

CALL add_foreign_key('scheduledJobs_mailQueue', 'FK_E560A18030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('scheduledJobs_mailQueue', 'FK_E560A1CE570F32', 'id_mailQueue', 'mailQueue(id)');
CALL add_index('scheduledJobs_mailQueue', 'IDX_E560A18030BA52', 'id_scheduledJobs', FALSE);
CALL add_index('scheduledJobs_mailQueue', 'IDX_E560A1CE570F32', 'id_mailQueue', FALSE);

CALL add_foreign_key('scheduledJobs_notifications', 'FK_9879806C8030BA52', 'id_scheduledJobs', 'scheduledJobs(id)');
CALL add_foreign_key('scheduledJobs_notifications', 'FK_9879806CDE2861B6', 'id_notifications', 'notifications(id)');
CALL add_index('scheduledJobs_notifications', 'IDX_9879806C8030BA52', 'id_scheduledJobs', FALSE);
CALL add_index('scheduledJobs_notifications', 'IDX_9879806CDE2861B6', 'id_notifications', FALSE);

CALL add_foreign_key('assets', 'FK_79D17D8E843A9330', 'id_assetTypes', 'lookups(id)');
CALL add_index('assets', 'IDX_79D17D8E843A9330', 'id_assetTypes', FALSE);

CALL add_foreign_key('users', 'FK_1483A5E95D8601CD', 'id_genders', 'genders(id)');
CALL add_index('users', 'IDX_1483A5E95D8601CD', 'id_genders', FALSE);
CALL add_foreign_key('users', 'FK_1483A5E95D37D0F1', 'id_status', 'lookups(id)');
CALL add_index('users', 'IDX_1483A5E95D37D0F1', 'id_status', FALSE);
CALL add_foreign_key('users', 'FK_1483A5E920E4EF5E', 'id_languages', 'languages(id)');
CALL add_index('users', 'IDX_1483A5E920E4EF5E', 'id_languages', FALSE);

SET @user_type_user_id = (
  SELECT id FROM lookups WHERE type_code = 'userTypes' AND lookup_value = 'user'
);
SET @sql = CONCAT(
  'ALTER TABLE users MODIFY id_userTypes INT NOT NULL DEFAULT ', @user_type_user_id, ';'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Deduplicate lookups before adding unique key (handles partial re-runs)
DELETE l1 FROM lookups l1
INNER JOIN lookups l2
  ON l1.type_code = l2.type_code
 AND l1.lookup_code = l2.lookup_code
 AND l1.id > l2.id;

-- Unique keys
CALL add_unique_key('lookups', 'uniq_type_lookup', 'type_code,lookup_code');
CALL add_unique_key('languages', 'UNIQ_A0D153794180C698', 'locale');
CALL add_unique_key('fields', 'UNIQ_7EE5E3885E237E06', 'name');
CALL add_unique_key('fieldType', 'UNIQ_C1760DF55E237E06', 'name');


SET SQL_MODE = @OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS = 1;

SELECT '39a completed successfully' AS status;
