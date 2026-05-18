CALL add_table_column('fields', 'config', 'JSON DEFAULT NULL');

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
LEFT JOIN styleGroup sg
  ON s.id_group = sg.id;

DROP VIEW IF EXISTS view_fields;
CREATE VIEW view_fields
AS
SELECT f.id AS field_id, f.`name` AS field_name, f.display, ft.id AS field_type_id, ft.`name` AS field_type, ft.position, f.config
FROM `fields` f
LEFT JOIN fieldType ft ON (f.id_type = ft.id);

DROP VIEW IF EXISTS view_style_fields;
CREATE VIEW view_style_fields
AS
SELECT s.style_id, s.style_name, s.style_group, f.field_id, f.field_name, f.field_type, f.config, f.display, f.position,
sf.default_value, sf.help, sf.disabled, sf.hidden
FROM view_styles s
LEFT JOIN styles_fields sf ON (s.style_id = sf.id_styles)
LEFT JOIN view_fields f ON (f.field_id = sf.id_fields);

-- ============================================================================
-- Page Versioning & Publishing System
-- ============================================================================
-- Add page versioning and publishing capabilities to allow storing complete
-- published page JSON structures as versions. This hybrid approach stores
-- page structure while re-running dynamic elements (data retrieval, conditions)
-- when serving to ensure freshness.

-- 1. Create page_versions table
-- This table stores complete published page JSON structures as versions
DROP TABLE IF EXISTS `page_versions`;
CREATE TABLE `page_versions` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `id_pages` INT NOT NULL,
  `version_number` INT NOT NULL COMMENT 'Incremental version number per page',
  `version_name` VARCHAR(255) DEFAULT NULL COMMENT 'Optional user-defined name for the version',
  `page_json` JSON NOT NULL COMMENT 'Complete JSON structure from getPage() including all languages, conditions, data table configs',
  `created_by` INT DEFAULT NULL COMMENT 'User who created the version',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` DATETIME DEFAULT NULL COMMENT 'When this version was published',
  `metadata` JSON DEFAULT NULL COMMENT 'Additional info like change summary, tags, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_version_number` (`id_pages`, `version_number`),
  KEY `idx_id_pages` (`id_pages`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_published_at` (`published_at`),
  CONSTRAINT `FK_page_versions_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_page_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores published page versions with complete JSON structures';

-- 2. Update pages table to add published_version_id
-- This column points to the currently published version in page_versions table
CALL add_table_column('pages', 'published_version_id', 'INT DEFAULT NULL');
CALL add_index('pages', 'IDX_2074E575B5D68A8D', 'published_version_id', FALSE);
CALL add_foreign_key('pages', 'FK_2074E575B5D68A8D', 'published_version_id', 'page_versions (id)');

ALTER TABLE pages DROP FOREIGN KEY FK_2074E575B5D68A8D;
DROP INDEX IDX_2074E575B5D68A8D ON pages;
ALTER TABLE pages ADD CONSTRAINT FK_2074E575B5D68A8D FOREIGN KEY (published_version_id) REFERENCES page_versions (id);
CALL rename_index('pages', 'FK_2074E575B5D68A8D', 'IDX_pages_published_version_id');
CALL rename_index('pages', 'fk_2074e575b5d68a8d', 'IDX_pages_published_version_id');
ALTER TABLE page_versions CHANGE created_by created_by INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL;

-- Insert resource types into lookups table
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('resourceTypes', 'group', 'Group', 'User groups for data access control'),
('resourceTypes', 'data_table', 'Data Table', 'Custom data tables'),
('resourceTypes', 'pages', 'Pages', 'Admin pages access control')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Insert audit actions into lookups table
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('auditActions', 'filter', 'Filter', 'Data filtering applied to READ operations'),
('auditActions', 'create', 'Create', 'Permission check for CREATE operations'),
('auditActions', 'read', 'Read', 'Permission check for specific READ operations'),
('auditActions', 'update', 'Update', 'Permission check for UPDATE operations'),
('auditActions', 'delete', 'Delete', 'Permission check for DELETE operations')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Insert permission results into lookups table
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('permissionResults', 'granted', 'Granted', 'Permission was granted'),
('permissionResults', 'denied', 'Denied', 'Permission was denied')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Insert comprehensive timezone entries into lookups table
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
-- North America
('timezones', 'America/New_York', 'Eastern Time (ET)', 'Eastern Time Zone - UTC-5/-4'),
('timezones', 'America/Chicago', 'Central Time (CT)', 'Central Time Zone - UTC-6/-5'),
('timezones', 'America/Denver', 'Mountain Time (MT)', 'Mountain Time Zone - UTC-7/-6'),
('timezones', 'America/Phoenix', 'Mountain Time (MST)', 'Mountain Time Zone (no DST) - UTC-7'),
('timezones', 'America/Los_Angeles', 'Pacific Time (PT)', 'Pacific Time Zone - UTC-8/-7'),
('timezones', 'America/Anchorage', 'Alaska Time (AKT)', 'Alaska Time Zone - UTC-9/-8'),
('timezones', 'America/Juneau', 'Alaska Time (AKT)', 'Alaska Time Zone - UTC-9/-8'),
('timezones', 'Pacific/Honolulu', 'Hawaii Time (HT)', 'Hawaii Time Zone - UTC-10'),
('timezones', 'America/Halifax', 'Atlantic Time (AT)', 'Atlantic Time Zone - UTC-4/-3'),
('timezones', 'America/St_Johns', 'Newfoundland Time (NT)', 'Newfoundland Time Zone - UTC-3:30/-2:30'),
('timezones', 'America/Regina', 'Central Time (CT)', 'Central Time Zone (no DST) - UTC-6'),
('timezones', 'America/Winnipeg', 'Central Time (CT)', 'Central Time Zone - UTC-6/-5'),
('timezones', 'America/Toronto', 'Eastern Time (ET)', 'Eastern Time Zone - UTC-5/-4'),
('timezones', 'America/Vancouver', 'Pacific Time (PT)', 'Pacific Time Zone - UTC-8/-7'),
('timezones', 'America/Edmonton', 'Mountain Time (MT)', 'Mountain Time Zone - UTC-7/-6'),

-- South America
('timezones', 'America/Sao_Paulo', 'Brasília Time (BRT)', 'Brasília Time - UTC-3/-2'),
('timezones', 'America/Buenos_Aires', 'Argentina Time (ART)', 'Argentina Time - UTC-3'),
('timezones', 'America/Lima', 'Peru Time (PET)', 'Peru Time - UTC-5'),
('timezones', 'America/Bogota', 'Colombia Time (COT)', 'Colombia Time - UTC-5'),
('timezones', 'America/Caracas', 'Venezuelan Time (VET)', 'Venezuelan Time - UTC-4'),
('timezones', 'America/Santiago', 'Chile Time (CLT)', 'Chile Time - UTC-4/-3'),
('timezones', 'America/Mexico_City', 'Central Time (CT)', 'Central Time Zone - UTC-6/-5'),

-- Europe
('timezones', 'Europe/London', 'Greenwich Mean Time (GMT)', 'Greenwich Mean Time - UTC+0/+1'),
('timezones', 'Europe/Berlin', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Paris', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Rome', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Madrid', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Amsterdam', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Brussels', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Vienna', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Zurich', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Prague', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Warsaw', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Budapest', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Bucharest', 'Eastern European Time (EET)', 'Eastern European Time - UTC+2/+3'),
('timezones', 'Europe/Kiev', 'Eastern European Time (EET)', 'Eastern European Time - UTC+2/+3'),
('timezones', 'Europe/Athens', 'Eastern European Time (EET)', 'Eastern European Time - UTC+2/+3'),
('timezones', 'Europe/Helsinki', 'Eastern European Time (EET)', 'Eastern European Time - UTC+2/+3'),
('timezones', 'Europe/Stockholm', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Copenhagen', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Oslo', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),
('timezones', 'Europe/Moscow', 'Moscow Time (MSK)', 'Moscow Time - UTC+3'),
('timezones', 'Europe/Istanbul', 'Turkey Time (TRT)', 'Turkey Time - UTC+3'),

-- Asia
('timezones', 'Asia/Tokyo', 'Japan Standard Time (JST)', 'Japan Standard Time - UTC+9'),
('timezones', 'Asia/Shanghai', 'China Standard Time (CST)', 'China Standard Time - UTC+8'),
('timezones', 'Asia/Hong_Kong', 'Hong Kong Time (HKT)', 'Hong Kong Time - UTC+8'),
('timezones', 'Asia/Singapore', 'Singapore Time (SGT)', 'Singapore Time - UTC+8'),
('timezones', 'Asia/Kolkata', 'India Standard Time (IST)', 'India Standard Time - UTC+5:30'),
('timezones', 'Asia/Karachi', 'Pakistan Time (PKT)', 'Pakistan Time - UTC+5'),
('timezones', 'Asia/Dhaka', 'Bangladesh Time (BST)', 'Bangladesh Time - UTC+6'),
('timezones', 'Asia/Bangkok', 'Indochina Time (ICT)', 'Indochina Time - UTC+7'),
('timezones', 'Asia/Jakarta', 'Western Indonesian Time (WIB)', 'Western Indonesian Time - UTC+7'),
('timezones', 'Asia/Manila', 'Philippine Time (PHT)', 'Philippine Time - UTC+8'),
('timezones', 'Asia/Seoul', 'Korea Standard Time (KST)', 'Korea Standard Time - UTC+9'),
('timezones', 'Asia/Taipei', 'Taiwan Time (TWT)', 'Taiwan Time - UTC+8'),
('timezones', 'Asia/Kuala_Lumpur', 'Malaysia Time (MYT)', 'Malaysia Time - UTC+8'),
('timezones', 'Asia/Dubai', 'Gulf Time (GST)', 'Gulf Time - UTC+4'),
('timezones', 'Asia/Riyadh', 'Arabia Time (AST)', 'Arabia Time - UTC+3'),
('timezones', 'Asia/Tehran', 'Iran Time (IRT)', 'Iran Time - UTC+3:30/+4:30'),
('timezones', 'Asia/Jerusalem', 'Israel Time (IST)', 'Israel Time - UTC+2/+3'),

-- Africa
('timezones', 'Africa/Cairo', 'Eastern European Time (EET)', 'Eastern European Time - UTC+2/+3'),
('timezones', 'Africa/Johannesburg', 'South Africa Time (SAST)', 'South Africa Time - UTC+2'),
('timezones', 'Africa/Lagos', 'West Africa Time (WAT)', 'West Africa Time - UTC+1'),
('timezones', 'Africa/Nairobi', 'East Africa Time (EAT)', 'East Africa Time - UTC+3'),
('timezones', 'Africa/Casablanca', 'Western European Time (WET)', 'Western European Time - UTC+0/+1'),
('timezones', 'Africa/Algiers', 'Central European Time (CET)', 'Central European Time - UTC+1/+2'),

-- Australia & Oceania
('timezones', 'Australia/Sydney', 'Australian Eastern Time (AET)', 'Australian Eastern Time - UTC+10/+11'),
('timezones', 'Australia/Melbourne', 'Australian Eastern Time (AET)', 'Australian Eastern Time - UTC+10/+11'),
('timezones', 'Australia/Brisbane', 'Australian Eastern Time (AEST)', 'Australian Eastern Time (no DST) - UTC+10'),
('timezones', 'Australia/Perth', 'Australian Western Time (AWST)', 'Australian Western Time - UTC+8'),
('timezones', 'Australia/Adelaide', 'Australian Central Time (ACT)', 'Australian Central Time - UTC+9:30/+10:30'),
('timezones', 'Pacific/Auckland', 'New Zealand Time (NZT)', 'New Zealand Time - UTC+12/+13'),
('timezones', 'Pacific/Fiji', 'Fiji Time (FJT)', 'Fiji Time - UTC+12/+13'),

-- Pacific Islands
('timezones', 'Pacific/Guam', 'Chamorro Time (ChST)', 'Chamorro Time - UTC+10'),
('timezones', 'Pacific/Saipan', 'Chamorro Time (ChST)', 'Chamorro Time - UTC+10')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Add timezone field to users table
CALL add_table_column('users', 'id_timezones', 'INT DEFAULT NULL');
CALL add_foreign_key('users', 'FK_users_id_timezones', 'id_timezones', 'lookups(id)');
CALL add_index('users', 'IDX_1483A5E9F5677479', 'id_timezones', FALSE);

-- =================================================
-- Create get_dataTable_with_user_group_filter procedure
-- =================================================
-- This procedure filters data by user groups instead of individual users
-- Used for non-admin users who should only see data from users in their accessible groups

DELIMITER $$

DROP PROCEDURE IF EXISTS `get_dataTable_with_user_group_filter`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_user_group_filter`(
	IN table_id_param INT,
	IN current_user_id_param INT, -- Current user making the request
	IN filter_param VARCHAR(1000),
	IN exclude_deleted_param BOOLEAN, -- If true it will exclude the deleted records and it will not return them
	IN language_id_param INT -- Language ID for translations (default 1 = internal language only)
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
	SET @@group_concat_max_len = 32000000;
	SET @sql = NULL;

	-- Build the dynamic column selection (same as before)
	SELECT
	GROUP_CONCAT(DISTINCT
		CONCAT(
			'MAX(CASE WHEN col.`name` = "',
				col.name,
				'" THEN `value` END) AS `',
			replace(col.name, ' ', ''), '`'
		)
	) INTO @sql
	FROM  dataTables t
	INNER JOIN dataCols col on (t.id = col.id_dataTables)
	WHERE t.id = table_id_param AND col.`name` NOT IN ('id_users','record_id','user_name','id_actionTriggerTypes','triggerType', 'entry_date', 'user_code');

	IF (@sql is null) THEN
		SELECT `name` from view_dataTables where 1=2;
	ELSE
		BEGIN
			-- User group filter - find accessible users dynamically
			-- Get resource type ID for groups
			SET @group_resource_type_id = (SELECT id FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = 'group' LIMIT 1);

			-- Find all users that the current user can access through group permissions
			DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
			CREATE TEMPORARY TABLE accessible_users_temp AS
			SELECT DISTINCT ug.id_users
			FROM users_groups ug
			WHERE ug.id_groups IN (
				-- Find groups the current user can access
				SELECT rda.resource_id
				FROM role_data_access rda
				INNER JOIN roles r ON rda.id_roles = r.id
				INNER JOIN users_roles ur ON r.id = ur.id_roles
				WHERE ur.id_users = current_user_id_param
				AND rda.id_resourceTypes = @group_resource_type_id
				AND rda.crud_permissions > 0
			);

			-- Build user filter using the accessible users
			SET @user_filter = '';
			SET @accessible_user_count = (SELECT COUNT(*) FROM accessible_users_temp);
			IF @accessible_user_count > 0 THEN
				SET @user_filter = ' AND r.id_users IN (SELECT id_users FROM accessible_users_temp)';
			ELSE
				-- No accessible users - return no results
				SET @user_filter = ' AND 1=0';
			END IF;

			-- Time period filter (same as before)
			SET @time_period_filter = '';
			CASE
				WHEN filter_param LIKE '%LAST_HOUR%' THEN
					SET @time_period_filter = ' AND r.`timestamp` >= NOW() - INTERVAL 1 HOUR';
				WHEN filter_param LIKE '%LAST_DAY%' THEN
					SET @time_period_filter = ' AND r.`timestamp` >= NOW() - INTERVAL 1 DAY';
				WHEN filter_param LIKE '%LAST_WEEK%' THEN
					SET @time_period_filter = ' AND r.`timestamp` >= NOW() - INTERVAL 1 WEEK';
				WHEN filter_param LIKE '%LAST_MONTH%' THEN
					SET @time_period_filter = ' AND r.`timestamp` >= NOW() - INTERVAL 1 MONTH';
				WHEN filter_param LIKE '%LAST_YEAR%' THEN
					SET @time_period_filter = ' AND r.`timestamp` >= NOW() - INTERVAL 1 YEAR';
				ELSE
					SET @time_period_filter = '';
			END CASE;

			-- Exclude deleted filter (same as before)
			SET @exclude_deleted_filter = '';
			CASE
				WHEN exclude_deleted_param = TRUE THEN
					SET @exclude_deleted_filter = CONCAT(' AND IFNULL(r.id_actionTriggerTypes, 0) <> ', (SELECT id FROM lookups WHERE type_code = 'actionTriggerTypes' AND lookup_code = 'deleted' LIMIT 0,1));
				ELSE
					SET @exclude_deleted_filter = '';
			END CASE;

			-- Language filter for translations
			-- Always include language 1 (internal), and also include the requested language if different
			SET @language_filter = '';
			IF language_id_param IS NULL OR language_id_param = 1 THEN
				-- Default: only internal language (language_id = 1)
				SET @language_filter = ' AND cell.id_languages = 1';
			ELSE
				-- Include both internal language (1) and requested language
				-- This ensures we always have fallback to language 1, and translations where available
				SET @language_filter = CONCAT(' AND cell.id_languages IN (1, ', language_id_param, ')');
			END IF;

			-- Build the main query with user group filtering
			SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id,
					r.`timestamp` AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_actionTriggerTypes, l.lookup_code AS triggerType,', @sql,
					' FROM dataTables t
					INNER JOIN dataRows r ON (t.id = r.id_dataTables)
					INNER JOIN dataCells cell ON (cell.id_dataRows = r.id)
					INNER JOIN dataCols col ON (col.id = cell.id_dataCols)
					LEFT JOIN users u ON (r.id_users = u.id)
					LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
					LEFT JOIN lookups l ON (l.id = r.id_actionTriggerTypes)
					WHERE t.id = ', table_id_param, @user_filter, @time_period_filter, @exclude_deleted_filter, @language_filter,
					' GROUP BY r.id ) AS r WHERE 1=1  ', filter_param);

			-- select @sql; -- Uncomment for debugging
			PREPARE stmt FROM @sql;
			EXECUTE stmt;
			DEALLOCATE PREPARE stmt;

			-- Clean up temporary table
			DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
		END;
	END IF;
END$$

DELIMITER ;

CALL drop_foreign_key('cmsPreferences', 'FK_3F26A2DF5602A942');
DROP TABLE IF EXISTS cmsPreferences;
DROP VIEW IF EXISTS view_cmsPreferences;
DROP PROCEDURE IF EXISTS update_formId_reminders;
DROP PROCEDURE IF EXISTS get_group_acl;
DROP PROCEDURE IF EXISTS get_navigation;

DROP FUNCTION IF EXISTS get_form_fields_helper;
DROP FUNCTION IF EXISTS get_page_fields_helper;
DROP FUNCTION IF EXISTS get_sections_fields_helper;

DROP VIEW IF EXISTS view_datatables_data;
DROP VIEW IF EXISTS view_transactions;
DROP VIEW IF EXISTS view_user_codes;

-- =================================================
-- Scheduled Jobs Refactoring
-- =================================================
-- Complete refactor of scheduled jobs system:
-- - Drop old junction tables (scheduledJobs_actions, scheduledJobs_users, etc.)
-- - Create new simplified scheduledJobs table with direct relationships
-- - Add timezone-aware scheduling with dynamic adjustment
-- - Support for system jobs (user validation, password reset) without action/datatable context
-- =================================================

-- Drop old junction tables
DROP TABLE IF EXISTS `scheduledJobs_actions`;
DROP TABLE IF EXISTS `scheduledJobs_users`;
DROP TABLE IF EXISTS `scheduledJobs_mailQueue`;
DROP TABLE IF EXISTS `scheduledJobs_notifications`;
DROP TABLE IF EXISTS `scheduledJobs_reminders`;
DROP TABLE IF EXISTS `scheduledJobs_tasks`;

-- Drop tables no longer needed after removing scheduled jobs system
DROP TABLE IF EXISTS `mailAttachments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `mailQueue`;
DROP TABLE IF EXISTS `notifications`;

-- Drop and recreate the main scheduledJobs table with new structure
DROP TABLE IF EXISTS `scheduledJobs`;

CREATE TABLE `scheduledJobs` (
  `id` int NOT NULL AUTO_INCREMENT,

  -- Core relationships (nullable for system jobs)
  `id_users` int DEFAULT NULL,
  `id_actions` int DEFAULT NULL,
  `id_dataTables` int DEFAULT NULL,
  `id_dataRows` int DEFAULT NULL,

  -- Job classification (lookup-based)
  `id_jobTypes` int NOT NULL,
  `id_jobStatus` int NOT NULL,

  `date_create` datetime NOT NULL,
  `date_to_be_executed` datetime NOT NULL,
  `date_executed` datetime DEFAULT NULL,

  -- Job details
  `description` varchar(1000) DEFAULT NULL,
  `config` json DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- Foreign keys with Doctrine-compatible naming
  KEY `IDX_3E186B37FA06E4D9` (`id_users`),
  KEY `IDX_3E186B37DBD5589F` (`id_actions`),
  KEY `IDX_3E186B37E2E6A7C3` (`id_dataTables`),
  KEY `IDX_3E186B37F3854F45` (`id_dataRows`),
  KEY `IDX_3E186B3777FD8DE1` (`id_jobStatus`),
  KEY `IDX_3E186B3712C34CFB` (`id_jobTypes`),

  CONSTRAINT `FK_3E186B37FA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37DBD5589F` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37E2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37F3854F45` FOREIGN KEY (`id_dataRows`) REFERENCES `dataRows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B3777FD8DE1` FOREIGN KEY (`id_jobStatus`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B3712C34CFB` FOREIGN KEY (`id_jobTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Insert job type lookups using constants
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('jobTypes', 'email', 'Email', 'Email sending job'),
('jobTypes', 'notification', 'Notification', 'Push notification job'),
('jobTypes', 'task', 'Task', 'Custom task execution'),
('jobTypes', 'reminder', 'Reminder', 'Scheduled reminder job')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Insert additional job status lookups (existing ones are already in DB)
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('scheduledJobsStatus', 'running', 'Running', 'Job is currently running'),
('scheduledJobsStatus', 'cancelled', 'Cancelled', 'Job was manually cancelled')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Drop acl_users table as it's no longer needed (ACLs now handle only frontend view for groups)
DROP TABLE IF EXISTS `acl_users`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `rename_table_column` $$

CREATE PROCEDURE `rename_table_column`(
    param_table VARCHAR(100),
    param_old_column_name VARCHAR(100),
    param_new_column_name VARCHAR(100),
    param_comment TEXT
)
BEGIN

    DECLARE columnExists INT;
    DECLARE columnType VARCHAR(255);
    DECLARE dataType VARCHAR(100);
    DECLARE isNullable VARCHAR(3);
    DECLARE columnDefault TEXT;
    DECLARE extraValue VARCHAR(255);
    DECLARE columnComment TEXT;
    DECLARE newColumnType TEXT;
    DECLARE defaultClause TEXT DEFAULT '';
    DECLARE nullClause TEXT DEFAULT '';
    DECLARE extraClause TEXT DEFAULT '';
    DECLARE finalComment TEXT DEFAULT '';
    DECLARE commentClause TEXT DEFAULT '';

    SELECT COUNT(*)
        INTO columnExists
        FROM information_schema.COLUMNS
        WHERE `table_schema` = DATABASE()
        AND `table_name` = param_table
        AND `COLUMN_NAME` = param_old_column_name;

    IF columnExists > 0 THEN
        SELECT COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
            INTO columnType, dataType, isNullable, columnDefault, extraValue, columnComment
            FROM information_schema.COLUMNS
            WHERE `table_schema` = DATABASE()
            AND `table_name` = param_table
            AND `COLUMN_NAME` = param_old_column_name
            LIMIT 1;

        SET nullClause = IF(isNullable = 'YES', ' NULL', ' NOT NULL');

        IF columnDefault IS NULL THEN
            IF isNullable = 'YES' THEN
                SET defaultClause = ' DEFAULT NULL';
            END IF;
        ELSEIF UPPER(columnDefault) LIKE 'CURRENT_TIMESTAMP%' THEN
            SET defaultClause = CONCAT(' DEFAULT ', columnDefault);
        ELSEIF LOWER(dataType) IN ('tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit', 'boolean') THEN
            SET defaultClause = CONCAT(' DEFAULT ', columnDefault);
        ELSE
            SET defaultClause = CONCAT(' DEFAULT ', QUOTE(columnDefault));
        END IF;

        IF LOWER(extraValue) LIKE '%on update current_timestamp%' THEN
            SET extraClause = ' ON UPDATE CURRENT_TIMESTAMP';
        ELSEIF LOWER(extraValue) LIKE '%auto_increment%' THEN
            SET extraClause = ' AUTO_INCREMENT';
        END IF;

        SET finalComment = IFNULL(columnComment, '');
        IF param_comment IS NOT NULL AND param_comment != '' THEN
            IF finalComment = '' THEN
                SET finalComment = param_comment;
            ELSEIF INSTR(finalComment, param_comment) = 0 THEN
                SET finalComment = CONCAT(finalComment, param_comment);
            END IF;
        END IF;

        IF finalComment != '' THEN
            SET commentClause = CONCAT(' COMMENT ', QUOTE(finalComment));
        END IF;

        SET newColumnType = CONCAT(columnType, nullClause, defaultClause, extraClause, commentClause);
    END IF;

    SET @sqlstmt = (SELECT IF(
        columnExists > 0,
        CONCAT('ALTER TABLE `', param_table, '` CHANGE COLUMN `', param_old_column_name, '` `', param_new_column_name, '` ', newColumnType, ';'),
        "SELECT 'Column does not exist in the table'"
    ));

    PREPARE st FROM @sqlstmt;
    EXECUTE st;
    DEALLOCATE PREPARE st;

END $$

DELIMITER ;


-- Execute datetime column migrations using the modified stored procedure
CALL `rename_table_column`('apiRequestLogs', 'request_time', 'request_time', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('apiRequestLogs', 'response_time', 'response_time', '(DC2Type:datetime_immutable)');

ALTER TABLE callbackLogs CHANGE callback_date callback_date DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT '(DC2Type:datetime_immutable)';

CALL `rename_table_column`('dataAccessAudit', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('dataRows', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('dataTables', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('page_versions', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('page_versions', 'published_at', 'published_at', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('role_data_access', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('role_data_access', 'updated_at', 'updated_at', '(DC2Type:datetime_immutable)');

ALTER TABLE transactions CHANGE transaction_time transaction_time DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT '(DC2Type:datetime_immutable)';

CALL `rename_table_column`('user_activity', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('users_2fa_codes', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('users_2fa_codes', 'expires_at', 'expires_at', '(DC2Type:datetime_immutable)');

CALL `rename_table_column`('validation_codes', 'created', 'created', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('validation_codes', 'consumed', 'consumed', '(DC2Type:datetime_immutable)');

-- =====================================================
-- Update Data Table Stored Procedures with Timezone Conversion
-- =====================================================

-- Create helper function for building dynamic column selection (shared logic)
DROP FUNCTION IF EXISTS `build_dynamic_columns`;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_dynamic_columns`(table_id_param INT) RETURNS TEXT
    READS SQL DATA
    DETERMINISTIC
BEGIN
    DECLARE sql_columns TEXT;

    SELECT
        GROUP_CONCAT(DISTINCT
            CONCAT(
                'MAX(CASE WHEN col.`name` = "',
                col.name,
                '" THEN `value` END) AS `',
                replace(col.name, ' ', ''), '`'
            )
        ) INTO sql_columns
    FROM  dataTables t
    INNER JOIN dataCols col on (t.id = col.id_dataTables)
    WHERE t.id = table_id_param AND col.`name` NOT IN ('id_users','record_id','user_name','id_actionTriggerTypes','triggerType', 'entry_date', 'user_code');

    RETURN sql_columns;
END ;;
DELIMITER ;

-- Create helper function for time period filtering (shared logic)
DROP FUNCTION IF EXISTS `build_time_period_filter`;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_time_period_filter`(filter_param VARCHAR(1000)) RETURNS TEXT
    DETERMINISTIC
BEGIN
    CASE
        WHEN filter_param LIKE '%LAST_HOUR%' THEN
            RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 HOUR';
        WHEN filter_param LIKE '%LAST_DAY%' THEN
            RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 DAY';
        WHEN filter_param LIKE '%LAST_WEEK%' THEN
            RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 WEEK';
        WHEN filter_param LIKE '%LAST_MONTH%' THEN
            RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 MONTH';
        WHEN filter_param LIKE '%LAST_YEAR%' THEN
            RETURN ' AND r.`timestamp` >= NOW() - INTERVAL 1 YEAR';
        ELSE
            RETURN '';
    END CASE;
END ;;
DELIMITER ;

-- Create helper function for exclude deleted filtering (shared logic)
DROP FUNCTION IF EXISTS `build_exclude_deleted_filter`;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_exclude_deleted_filter`(exclude_deleted_param BOOLEAN) RETURNS TEXT
    DETERMINISTIC
BEGIN
    IF exclude_deleted_param = TRUE THEN
        RETURN CONCAT(' AND IFNULL(r.id_actionTriggerTypes, 0) <> ', (SELECT id FROM lookups WHERE type_code = 'actionTriggerTypes' AND lookup_code = 'deleted' LIMIT 0,1));
    ELSE
        RETURN '';
    END IF;
END ;;
DELIMITER ;

-- Create helper function for language filtering (shared logic)
DROP FUNCTION IF EXISTS `build_language_filter`;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_language_filter`(language_id_param INT) RETURNS TEXT
    DETERMINISTIC
BEGIN
    IF language_id_param IS NULL OR language_id_param = 1 THEN
        -- Default: only internal language (language_id = 1)
        RETURN ' AND cell.id_languages = 1';
    ELSE
        -- Include both internal language (1) and requested language
        RETURN CONCAT(' AND cell.id_languages IN (1, ', language_id_param, ')');
    END IF;
END ;;
DELIMITER ;

-- Create helper function for timezone conversion (shared logic)
DROP FUNCTION IF EXISTS `convert_entry_date_timezone`;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `convert_entry_date_timezone`(timestamp_value DATETIME, timezone_code VARCHAR(100)) RETURNS VARCHAR(19)
    DETERMINISTIC
BEGIN
    -- Convert timestamp from UTC to specified timezone and format as Y-m-d H:i:s
    RETURN DATE_FORMAT(CONVERT_TZ(timestamp_value, 'UTC', timezone_code), '%Y-%m-%d %H:%i:%s');
END ;;
DELIMITER ;

-- Update get_dataTable_with_all_languages procedure
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_all_languages` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_all_languages`(
    IN table_id_param INT,
    IN user_id_param INT,
    IN filter_param VARCHAR(1000),
    IN exclude_deleted_param BOOLEAN,
    IN timezone_code_param VARCHAR(100) -- New parameter for timezone conversion
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = build_dynamic_columns(table_id_param);

    IF (@sql is null) THEN
        SELECT `name` from view_dataTables where 1=2;
    ELSE
        BEGIN
            -- User filter
            SET @user_filter = '';
            IF user_id_param > 0 THEN
                SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
            END IF;

            -- Build the main query - group by record and language to get separate rows for each language
            SET @sql = CONCAT('SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code,
                    r.id_actionTriggerTypes, l.lookup_code AS triggerType, cell.id_languages, lang.locale AS language_locale, lang.language AS language_name,',
                    @sql,
                    ' FROM dataTables t
                    INNER JOIN dataRows r ON (t.id = r.id_dataTables)
                    LEFT JOIN users u ON (r.id_users = u.id)
                    LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                    LEFT JOIN lookups l ON (l.id = r.id_actionTriggerTypes)
                    INNER JOIN dataCells cell ON (cell.id_dataRows = r.id)
                    INNER JOIN dataCols col ON (col.id = cell.id_dataCols)
                    LEFT JOIN languages lang ON (lang.id = cell.id_languages)
                    WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param),
                    ' GROUP BY r.id, cell.id_languages ORDER BY r.id, cell.id_languages');

            -- Apply the additional filter
            SET @sql = CONCAT('SELECT * FROM (', @sql, ') AS filtered_data WHERE 1=1 ', filter_param);

            -- select @sql; -- Uncomment for debugging
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

-- Update get_dataTable_with_filter procedure
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_filter`(
    IN table_id_param INT,
    IN user_id_param INT,
    IN filter_param VARCHAR(1000),
    IN exclude_deleted_param BOOLEAN,
    IN language_id_param INT,
    IN timezone_code_param VARCHAR(100) -- New parameter for timezone conversion
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = build_dynamic_columns(table_id_param);

    IF (@sql is null) THEN
        SELECT `name` from view_dataTables where 1=2;
    ELSE
        BEGIN
            -- User filter
            SET @user_filter = '';
            IF user_id_param > 0 THEN
                SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
            END IF;

            -- Build the main query with language filtering
            SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id,
                    convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_actionTriggerTypes, l.lookup_code AS triggerType,', @sql,
                    ' FROM dataTables t
                    INNER JOIN dataRows r ON (t.id = r.id_dataTables)
                    INNER JOIN dataCells cell ON (cell.id_dataRows = r.id)
                    INNER JOIN dataCols col ON (col.id = cell.id_dataCols)
                    LEFT JOIN users u ON (r.id_users = u.id)
                    LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                    LEFT JOIN lookups l ON (l.id = r.id_actionTriggerTypes)
                    WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                    ' GROUP BY r.id ) AS r WHERE 1=1  ', filter_param);

            -- select @sql; -- Uncomment for debugging
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

-- Update get_dataTable_with_user_group_filter procedure
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_user_group_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_user_group_filter`(
    IN table_id_param INT,
    IN current_user_id_param INT,
    IN filter_param VARCHAR(1000),
    IN exclude_deleted_param BOOLEAN,
    IN language_id_param INT,
    IN timezone_code_param VARCHAR(100) -- New parameter for timezone conversion
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
    SET @@group_concat_max_len = 32000000;
    SET @sql = build_dynamic_columns(table_id_param);

    IF (@sql is null) THEN
        SELECT `name` from view_dataTables where 1=2;
    ELSE
        BEGIN
            -- User group filter - find accessible users dynamically
            -- Get resource type ID for groups
            SET @group_resource_type_id = (SELECT id FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = 'group' LIMIT 1);

            -- Find all users that the current user can access through group permissions
            DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
            CREATE TEMPORARY TABLE accessible_users_temp AS
            SELECT DISTINCT ug.id_users
            FROM users_groups ug
            WHERE ug.id_groups IN (
                -- Find groups the current user can access
                SELECT rda.resource_id
                FROM role_data_access rda
                INNER JOIN roles r ON rda.id_roles = r.id
                INNER JOIN users_roles ur ON r.id = ur.id_roles
                WHERE ur.id_users = current_user_id_param
                AND rda.id_resourceTypes = @group_resource_type_id
                AND rda.crud_permissions > 0
            );

            -- Build user filter using the accessible users
            SET @user_filter = '';
            SET @accessible_user_count = (SELECT COUNT(*) FROM accessible_users_temp);
            IF @accessible_user_count > 0 THEN
                SET @user_filter = ' AND r.id_users IN (SELECT id_users FROM accessible_users_temp)';
            ELSE
                -- No accessible users - return no results
                SET @user_filter = ' AND 1=0';
            END IF;

            -- Build the main query with user group filtering
            SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id,
                    convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_actionTriggerTypes, l.lookup_code AS triggerType,', @sql,
                    ' FROM dataTables t
                    INNER JOIN dataRows r ON (t.id = r.id_dataTables)
                    INNER JOIN dataCells cell ON (cell.id_dataRows = r.id)
                    INNER JOIN dataCols col ON (col.id = cell.id_dataCols)
                    LEFT JOIN users u ON (r.id_users = u.id)
                    LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                    LEFT JOIN lookups l ON (l.id = r.id_actionTriggerTypes)
                    WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                    ' GROUP BY r.id ) AS r WHERE 1=1  ', filter_param);

            -- select @sql; -- Uncomment for debugging
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;

            -- Clean up temporary table
            DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
        END;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

-- =====================================================
-- Create Data Access Audit Table
-- =====================================================

CREATE TABLE IF NOT EXISTS `dataAccessAudit` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `id_users` int(11) NOT NULL,
    `id_resourceTypes` int(11) NOT NULL,
    `resource_id` int(11) NOT NULL,
    `id_actions` int(11) NOT NULL,
    `id_permissionResults` int(11) NOT NULL,
    `crud_permission` smallint(5) unsigned DEFAULT NULL,
    `http_method` varchar(10) DEFAULT NULL,
    `request_body_hash` varchar(64) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text,
    `request_uri` text,
    `notes` text,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `IDX_dataAccessAudit_users` (`id_users`),
    KEY `IDX_dataAccessAudit_resource_types` (`id_resourceTypes`),
    KEY `IDX_dataAccessAudit_resource_id` (`resource_id`),
    KEY `IDX_dataAccessAudit_created_at` (`created_at`),
    KEY `IDX_dataAccessAudit_permission_results` (`id_permissionResults`),
    KEY `IDX_dataAccessAudit_http_method` (`http_method`),
    KEY `IDX_dataAccessAudit_request_body_hash` (`request_body_hash`),
    CONSTRAINT `FK_dataAccessAudit_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`),
    CONSTRAINT `FK_dataAccessAudit_resourceTypes` FOREIGN KEY (`id_resourceTypes`) REFERENCES `lookups` (`id`),
    CONSTRAINT `FK_dataAccessAudit_actions` FOREIGN KEY (`id_actions`) REFERENCES `lookups` (`id`),
    CONSTRAINT `FK_dataAccessAudit_permissionResults` FOREIGN KEY (`id_permissionResults`) REFERENCES `lookups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `groups`
SET requires_2fa = 0;

UPDATE users
SET email = 'admin@unibe.ch'
WHERE email = 'admin';

UPDATE users
SET email = 'tpf@unibe.ch'
WHERE email = 'tpf';
