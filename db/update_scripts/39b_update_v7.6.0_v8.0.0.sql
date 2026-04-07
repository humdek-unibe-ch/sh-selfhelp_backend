
-- ===============================
-- Rename table `formActions` -> `actions` and update references
-- ===============================

-- Drop foreign keys and indexes referencing `formActions` in junction tables

DROP TABLE IF EXISTS actions;

CALL rename_table('formActions', 'actions');
CALL rename_table('scheduledJobs_formActions', 'scheduledJobs_actions');

CALL add_foreign_key('scheduledJobs_actions', 'FK_862DD4F8DBD5589F', 'id_actions', 'actions (id)');

CALL drop_foreign_key('actions', 'FK_3128FB5E8A8FCE9D');
CALL drop_index('actions', 'FK_3128FB5E8A8FCE9D');
CALL drop_foreign_key('actions', 'FK_548F1EF4AC2316F');
CALL drop_foreign_key('actions', 'FK_3128FB5EE2E6A7C3');
CALL drop_index('actions', 'IDX_548F1EF8A8FCE9D');
CALL rename_table_column('actions', 'id_formProjectActionTriggerTypes', 'id_actionTriggerTypes', NULL);
ALTER TABLE actions CHANGE id_actionTriggerTypes id_actionTriggerTypes INT NOT NULL;
CALL add_foreign_key(
  'actions',
  'FK_548F1EF4AC2316F',
  'id_actionTriggerTypes',
  'lookups (id)'
);
CALL add_index(
  'actions',
  'IDX_548F1EF4AC2316F',
  'id_actionTriggerTypes',
  FALSE
);

ALTER TABLE actions CHANGE id_dataTables id_dataTables INT NOT NULL;

-- Recreate foreign key and index to point to the new table/column
CALL add_foreign_key('actions', 'FK_548F1EFE2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_index('scheduledJobs_actions', 'IDX_862DD4F8DBD5589F', 'id_actions', FALSE);

CALL rename_index('scheduledJobs_actions', 'idx_ae5b5d0b8030ba52', 'IDX_862DD4F88030BA52');
CALL rename_index('scheduledJobs_actions', 'idx_ae5b5d0bf3854f45',  'IDX_862DD4F8F3854F45');
CALL rename_index('actions', 'fk_548f1efe2e6a7c3',  'IDX_548F1EFE2E6A7C3');

--- add new field types for CMS preferences
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('checkbox', '16');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-language', '8');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-timezone', '8');

--- add cms preferences page type
INSERT IGNORE INTO `pageType` (`name`) VALUES ('cms_preferences');

--- add cms preferences page
INSERT IGNORE INTO `pages` (`keyword`, `url`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES ('sh-cms-preferences', NULL, NULL, 0, 0, NULL, (SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), (SELECT id FROM lookups WHERE lookup_code = 'web'));
SET @id_page_cms_prefs = (SELECT id FROM pages WHERE keyword = 'sh-cms-preferences');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) VALUES ('0000000001', @id_page_cms_prefs, '1', '0', '1', '0');

--- add cms preferences fields
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('default_language_id', get_field_type_id('select-language'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('anonymous_users', get_field_type_id('checkbox'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('firebase_config', get_field_type_id('json'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('default_timezone', get_field_type_id('select-timezone'), '1');

--- add page type fields for cms preferences
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('callback_api_key'), NULL, 'API key for callback services');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('default_language_id'), NULL, 'Default language for the CMS system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('anonymous_users'), '0', 'Allow anonymous users to access the system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('firebase_config'), NULL, 'Firebase configuration in JSON format');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('default_timezone'), 'Europe/Zurich', 'Default timezone for the CMS system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('title'), NULL, 'Page title');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('description'), NULL, 'Page description');

-- add page translations for cms preferences
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('title'), '0000000002', 'CMS Preferences');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('title'), '0000000003', 'CMS Preferences');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('description'), '0000000002', 'Konfiguration der CMS-Einstellungen');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('description'), '0000000003', 'CMS configuration settings');

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('callback_api_key'), '0000000001', '');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('default_language_id'), '0000000001', 2);
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('anonymous_users'), '0000000001', '0');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('firebase_config'), '0000000001', '');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('default_timezone'), '0000000001', (SELECT id FROM lookups WHERE type_code = 'timezones' AND lookup_code = 'Europe/Zurich'));

UPDATE pages
SET nav_position = null
WHERE keyword IN ('sh-global-css', 'sh-global-values', 'sh-cms-preferences');

-- remove old styles
DELETE FROM styles
WHERE `name` IN (
    'jumbotron',
    'markdownInline',
    'chat',
    'card',
    'form',
    'quiz',
    'rawText',
    'accordionList',
    'navigationContainer',
    'navigationAccordion',
    'nestedList',
    'navigationNested',
    'sortableList',
    'formUserInput',
    'conditionalContainer',
    'json',
    'userProgress',
    'autocomplete',
    'navigationBar',
    'trigger',
    'conditionFailed',
    'conditionBuilder',
    'dataConfigBuilder',
    'actionConfigBuilder'
);

-- remove not needed fields
DELETE
FROM `fields` 
WHERE `id` NOT IN (SELECT DISTINCT `id_fields` FROM `styles_fields`) 
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `pages_fields`)
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `sections_fields_translation`)
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `pages_fields_translation`);

-- remove not needed field types
DELETE
FROM `fieldType` 
WHERE `id` NOT IN (SELECT DISTINCT `id_type` FROM `fields`);

CALL add_table_column('sections', 'debug', 'TINYINT DEFAULT 0');
CALL add_table_column('sections', 'condition', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'data_config', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'css', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'css_mobile', 'LONGTEXT DEFAULT NULL');

-- Update CSS
UPDATE sections s
JOIN sections_fields_translation sft 
    ON sft.id_sections = s.id
JOIN styles st 
    ON st.id = s.id_styles
SET s.css = sft.content
WHERE sft.id_fields = get_field_id('css');

-- Update DEBUG
UPDATE sections s
JOIN sections_fields_translation sft 
    ON sft.id_sections = s.id
JOIN styles st 
    ON st.id = s.id_styles
SET s.debug = CAST(sft.content AS UNSIGNED)
WHERE sft.id_fields = get_field_id('debug');

-- Update CONDITION
UPDATE sections s
JOIN sections_fields_translation sft 
    ON sft.id_sections = s.id
JOIN styles st 
    ON st.id = s.id_styles
SET s.`condition` = sft.content
WHERE sft.id_fields = get_field_id('condition');

-- Update DATA_CONFIG
UPDATE sections s
JOIN sections_fields_translation sft 
    ON sft.id_sections = s.id
JOIN styles st 
    ON st.id = s.id_styles
SET s.data_config = sft.content
WHERE sft.id_fields = get_field_id('data_config');

-- Remove not needed fields as they are now in the sections table
DELETE
FROM `fields` 
WHERE `name` IN ('css', 'css_mobile', 'debug', 'condition', 'data_config');

DELIMITER //

DROP PROCEDURE IF EXISTS `get_page_sections_hierarchical` //

CREATE PROCEDURE `get_page_sections_hierarchical`(IN page_id INT)
BEGIN
    WITH RECURSIVE section_hierarchy AS (
        -- Base case: get top-level sections for the page, position starts from 10
        SELECT
            s.id,
            s.`name`,
            s.id_styles,
            st.`name` AS style_name,
            CASE
                WHEN st.can_have_children = 1 THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM styles_allowed_relationships sar
                    WHERE sar.id_parent_style = st.id
                ) THEN 1
                ELSE 0
            END AS can_have_children,
            s.`condition`,
            s.css,
            s.css_mobile,
            s.debug,
            s.data_config,
            ps.`position` AS position,      -- Start at 10
            0 AS `level`,
            CAST(s.id AS CHAR(200)) AS `path`
        FROM pages_sections ps
        JOIN sections s ON ps.id_sections = s.id
        JOIN styles st ON s.id_styles = st.id
        LEFT JOIN sections_hierarchy sh ON s.id = sh.child
        WHERE ps.id_pages = page_id
        AND sh.parent IS NULL

        UNION ALL

        -- Recursive case: get children of sections
        SELECT
            s.id,
            s.`name`,
            s.id_styles,
            st.`name` AS style_name,
            CASE
                WHEN st.can_have_children = 1 THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM styles_allowed_relationships sar
                    WHERE sar.id_parent_style = st.id
                ) THEN 1
                ELSE 0
            END AS can_have_children,
            s.`condition`,
            s.css,
            s.css_mobile,
            s.debug,
            s.data_config,
            sh.position AS position,        -- Add 10 to each level
            h.`level` + 1,
            CONCAT(h.`path`, ',', s.id) AS `path`
        FROM section_hierarchy h
        JOIN sections_hierarchy sh ON h.id = sh.parent
        JOIN sections s ON sh.child = s.id
        JOIN styles st ON s.id_styles = st.id
    )

    -- Select the result
    SELECT
        id,
        `name` AS section_name,
        id_styles,
        style_name,
        can_have_children,
        `condition`,
		css,
		css_mobile,
		debug,
		data_config,
        position,
        `level`,
        `path`
    FROM section_hierarchy
    ORDER BY `path`, `position`;
END //

DELIMITER ;

-- Delete existing container style
-- DELETE FROM styles
-- WHERE `name` = 'container';

DELETE FROM styles
WHERE `name` IN ('tabs', 'tab', 'progressBar', 'table', 'tableRow', 'tableCell', 'accordion', '', 
'card', 'alert', 'radioGroup', 'radio-group', 'radio', 'carousel', 'container', 'slider', 'checkbox', 'div', 'htmlTag',
'textarea', 'formUserInputRecord', 'formUserInputLog', 'htmlTag', 'table', 'tableRow', 'tableCell', 'showUserInput', 'profile', 'resetPasword',
'validate', 'heading', 'markdown', 'plaintext', 'input', 'select');

DELETE FROM styles
WHERE `name` = 'select';

DELETE FROM `fields`
WHERE `name` IN ('ajax', 'redirect_at_end', 'html_tag', 'type_input', 'options');

DELETE FROM styles_fields
WHERE id_fields IN (SELECT id FROM fields WHERE `name` IN ('height', 'width') AND id_styles = get_style_id('image'));  

DELETE FROM styles_fields
WHERE id_fields IN (SELECT id FROM fields WHERE `name` IN ('sources') AND id_styles = get_style_id('video'));  

INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-image', '8');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-video', '8');

INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('video_src', get_field_type_id('select-video'), 0);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES (get_style_id('video'), get_field_id('video_src'), null, null, 0, 0, 'Video Source');

UPDATE `fields`
SET id_type = get_field_type_id('select-image')
WHERE `name` IN ('img_src');

UPDATE `fields`
SET id_type = get_field_type_id('text')
WHERE `name` IN ('value');

ALTER TABLE styles_fields CHANGE default_value default_value VARCHAR(1000) DEFAULT NULL;

CALL drop_index('transactions', 'idx_transactions_table_name');


-- Section Management API Enhancement
-- Added new section deletion capabilities:
-- - DELETE /admin/sections/unused/{section_id} - Delete single unused section (requires admin.section.delete permission)
-- - DELETE /admin/sections/unused - Delete all unused sections (requires admin.section.delete permission)
-- - DELETE /admin/pages/{page_keyword}/sections/{section_id}/force-delete - Force delete section from page (requires admin.page.delete permission)
-- - Updated existing DELETE /admin/pages/{page_keyword}/sections/{section_id} to require admin.page.delete permission
-- Added comprehensive transaction logging for all section deletion operations
-- Enhanced AdminSectionUtilityService with deletion capabilities and proper relationship cleanup
-- Added forceDeleteSection method that always deletes (never just removes from page)
-- All deletion operations are wrapped in database transactions with proper rollback handling
-- All operations properly check page access permissions before allowing section deletion

--
-- Styles Relationship System Enhancement v8.0.0
-- Added relational constraints for styles to define allowed parent-child relationships
-- This ensures that only valid style combinations can be created, preventing invalid hierarchies
--

-- Create table for defining allowed parent-child relationships between styles
-- This table enforces style-level constraints to ensure only valid combinations are allowed
-- Example: Style "tabs" can only have "tab" as children, "card-header" can only have "card" as parent
CREATE TABLE IF NOT EXISTS `styles_allowed_relationships` (
  `id_parent_style` int NOT NULL COMMENT 'ID of the parent style',
  `id_child_style` int NOT NULL COMMENT 'ID of the child style',
  PRIMARY KEY (`id_parent_style`,`id_child_style`),
  KEY `IDX_757F0414DC4D59BB` (`id_parent_style`),
  KEY `IDX_757F041478A9D70E` (`id_child_style`),
  CONSTRAINT `FK_styles_relationships_parent`
    FOREIGN KEY (`id_parent_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_styles_relationships_child`
    FOREIGN KEY (`id_child_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Defines allowed parent-child relationships between styles';
  ALTER TABLE styles_allowed_relationships CHANGE id_parent_style id_parent_style INT NOT NULL, CHANGE id_child_style id_child_style INT NOT NULL;


-- Drop gender-related foreign keys and columns before dropping the genders table
CALL drop_foreign_key('sections_fields_translation', 'FK_EC5054155D8601CD');
CALL drop_foreign_key('users', 'FK_1483A5E95D8601CD');
CALL drop_table_column('sections_fields_translation', 'id_genders');
CALL drop_table_column('users', 'id_genders');

DROP TABLE IF EXISTS genders;

-- =================================================
-- Data Tables Translation System
-- =================================================
-- This script adds language support to the dataCells table
-- allowing for multi-language content in data tables.
--
-- Translation Logic:
-- - Language ID 1 is the default/internal language (non-translatable)
-- - Language ID > 1 are translatable languages
-- - If a cell has id_languages = 1, it cannot have translations
-- - If a cell has id_languages > 1, it can have multiple translations
-- - When retrieving data, always include language 1 + requested language
--
-- Usage in get_dataTable_with_filter:
-- - Default language_id = 1 (returns only internal language)
-- - Specify language_id > 1 to get translations where available
-- =================================================

-- Add id_languages column to dataCells table
CALL add_table_column('dataCells', 'id_languages', 'int NOT NULL DEFAULT 1');

-- Add foreign key constraint to languages table
CALL add_foreign_key('dataCells', 'FK_dataCells_languages', 'id_languages', 'languages(id)');

-- Update primary key to include id_languages
-- First drop existing primary key
ALTER TABLE `dataCells` DROP PRIMARY KEY;

-- Add new composite primary key
ALTER TABLE `dataCells` ADD PRIMARY KEY (`id_dataRows`, `id_dataCols`, `id_languages`);

-- Add index for better performance on language queries
CALL add_index('dataCells', 'IDX_726A5F2520E4EF5E', 'id_languages', FALSE);

-- =================================================
-- Update get_dataTable_with_filter stored procedure
-- =================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `get_dataTable_with_filter`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_filter`(
	IN table_id_param INT,
	IN user_id_param INT,
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
			-- User filter (same as before)
			SET @user_filter = '';
			IF user_id_param > 0 THEN
				SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
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

			-- Build the main query with language filtering
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
		END;
	END IF;
END$$

DELIMITER ;

-- =================================================
-- Documentation: How the Translation System Works
-- =================================================
--
-- 1. Table Structure Changes:
--    - dataCells table now has id_languages column (default 1)
--    - Primary key is now (id_dataRows, id_dataCols, id_languages)
--    - Foreign key constraint to languages(id)
--
-- 2. Translation Logic:
--    - Language ID 1 = Internal/Default language (cannot be translated)
--    - Language ID 2+ = Translatable languages
--    - Rule: If a cell exists with id_languages = 1, it cannot have translations
--    - Rule: If a cell exists with id_languages > 1, it can have multiple translations
--
-- 3. Data Retrieval:
--    - get_dataTable_with_filter now accepts language_id_param (default 1)
--    - When language_id_param = 1: Returns only internal language data
--    - When language_id_param > 1: Returns internal language + requested language translations
--    - Translation fallback: Internal language (1) is always included as fallback
--
-- 4. Usage Examples:
--    - CALL get_dataTable_with_filter(1, 0, '', FALSE, 1);     -- Internal language only
--    - CALL get_dataTable_with_filter(1, 0, '', FALSE, 2);     -- Internal + language 2
--    - CALL get_dataTable_with_filter(1, 0, '', FALSE, 3);     -- Internal + language 3
--
-- 5. Data Entry Rules:
--    - New cells default to id_languages = 1
--    - To add translation: Insert new row with same id_dataRows/id_dataCols but different id_languages
--    - Cannot add id_languages > 1 if id_languages = 1 already exists for same cell
--    - Can add multiple id_languages > 1 for same cell (multiple translations)
--
-- 6. Migration Notes:
--    - Existing data automatically gets id_languages = 1 (default)
--    - No data loss during migration
--    - Backward compatible: existing calls work unchanged (default language_id = 1)

-- =================================================
-- Create get_dataTable_with_all_languages procedure
-- =================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `get_dataTable_with_all_languages`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_dataTable_with_all_languages`(
	IN table_id_param INT,
	IN user_id_param INT,
	IN filter_param VARCHAR(1000),
	IN exclude_deleted_param BOOLEAN -- If true it will exclude the deleted records and it will not return them
)
    READS SQL DATA
    DETERMINISTIC
BEGIN
	SET @@group_concat_max_len = 32000000;
	SET @sql = NULL;

	-- Build the dynamic column selection
	SELECT
	GROUP_CONCAT(DISTINCT
		CONCAT(
			'MAX(CASE WHEN col.`name` = "',
				col.name,
				'" THEN cell.`value` END) AS `',
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
			-- User filter
			SET @user_filter = '';
			IF user_id_param > 0 THEN
				SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
			END IF;

			-- Time period filter
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

			-- Exclude deleted filter
			SET @exclude_deleted_filter = '';
			CASE
				WHEN exclude_deleted_param = TRUE THEN
					SET @exclude_deleted_filter = CONCAT(' AND IFNULL(r.id_actionTriggerTypes, 0) <> ', (SELECT id FROM lookups WHERE type_code = 'actionTriggerTypes' AND lookup_code = 'deleted' LIMIT 0,1));
				ELSE
					SET @exclude_deleted_filter = '';
			END CASE;

			-- Build the main query - group by record and language to get separate rows for each language
			SET @sql = CONCAT('SELECT r.id AS record_id, r.`timestamp` AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code,
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
					WHERE t.id = ', table_id_param, @user_filter, @time_period_filter, @exclude_deleted_filter,
					' GROUP BY r.id, cell.id_languages ORDER BY r.id, cell.id_languages');

			-- Apply the additional filter
			SET @sql = CONCAT('SELECT * FROM (', @sql, ') AS filtered_data WHERE 1=1 ', filter_param);

			-- select @sql; -- Uncomment for debugging
			PREPARE stmt FROM @sql;
			EXECUTE stmt;
			DEALLOCATE PREPARE stmt;
		END;
	END IF;
END$$

DELIMITER ;

DROP VIEW IF EXISTS view_acl_groups_pages;
DROP VIEW IF EXISTS view_acl_users_in_groups_pages;
DROP VIEW IF EXISTS view_acl_users_pages;
DROP VIEW IF EXISTS view_acl_users_union;
DROP VIEW IF EXISTS view_formactions;
DROP VIEW IF EXISTS view_mailqueue;
DROP VIEW IF EXISTS view_notifications;
DROP VIEW IF EXISTS view_scheduledjobs;
DROP VIEW IF EXISTS view_scheduledjobs_reminders;
DROP VIEW IF EXISTS view_scheduledjobs_transactions;
DROP VIEW IF EXISTS view_sections_fields;
DROP VIEW IF EXISTS view_tasks;

DROP TABLE IF EXISTS activityType;
DROP TABLE IF EXISTS styleType;

CALL drop_foreign_key('styles', 'FK_B65AFAF57FE4B2B');
CALL drop_table_column('styles', 'id_type');

-- ===========================================
-- ACTION TRANSLATIONS SYSTEM
-- ===========================================

-- Action translations table for localized content
DROP TABLE IF EXISTS `action_translations`;
CREATE TABLE IF NOT EXISTS `action_translations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_actions` int NOT NULL,
  `translation_key` varchar(255) NOT NULL,
  `id_languages` int NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  CONSTRAINT `IDX_5AC50EA7DBD5589F` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `IDX_5AC50EA720E4EF5E` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Helper procedure to create indexes safely (only if they don't exist)
DELIMITER $$
DROP PROCEDURE IF EXISTS `add_index`$$
CREATE PROCEDURE `add_index`(
    param_table VARCHAR(100),
    param_index_name VARCHAR(100),
    param_index_columns VARCHAR(1000),
    param_is_unique BOOLEAN
)
BEGIN
    DECLARE column_list TEXT DEFAULT '';
    DECLARE remaining_columns TEXT DEFAULT param_index_columns;
    DECLARE current_column VARCHAR(100);
    DECLARE comma_pos INT;

    -- Check if index already exists
    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE `table_schema` = DATABASE()
        AND `table_name` = param_table
        AND `index_name` = param_index_name
    ) > 0 THEN
        SELECT CONCAT('Index ', param_index_name, ' already exists on table ', param_table) AS message;
    ELSE
        -- Build column list with proper backticks
        WHILE LENGTH(remaining_columns) > 0 DO
            SET comma_pos = LOCATE(',', remaining_columns);
            IF comma_pos > 0 THEN
                SET current_column = TRIM(SUBSTRING(remaining_columns, 1, comma_pos - 1));
                SET remaining_columns = SUBSTRING(remaining_columns, comma_pos + 1);
            ELSE
                SET current_column = TRIM(remaining_columns);
                SET remaining_columns = '';
            END IF;

            IF LENGTH(column_list) > 0 THEN
                SET column_list = CONCAT(column_list, ', `', current_column, '`');
            ELSE
                SET column_list = CONCAT('`', current_column, '`');
            END IF;
        END WHILE;

        -- Create the index
        SET @sqlstmt = CONCAT(
            'CREATE ',
            IF(param_is_unique, 'UNIQUE ', ''),
            'INDEX ',
            param_index_name,
            ' ON `',
            param_table,
            '` (',
            column_list,
            ');'
        );

        PREPARE st FROM @sqlstmt;
        EXECUTE st;
        DEALLOCATE PREPARE st;

        SELECT CONCAT('Index ', param_index_name, ' created on table ', param_table) AS message;
    END IF;
END$$
DELIMITER ;
