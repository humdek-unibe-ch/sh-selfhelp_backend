-- ============================================================================
-- 39b: Features, Table Renames, New Tables, Data Inserts
-- ============================================================================
-- Run this script SECOND (after 39a). It is idempotent and can be re-run safely.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 1. STYLE / PAGE UPDATES
-- ============================================================================

UPDATE styleGroup
SET position = 0, `description` = 'Reserved for internal system styles. Modifying or using these styles externally may cause unexpected behavior.'
WHERE `name` = 'intern';

UPDATE pages
SET id_actions = (SELECT id FROM lookups WHERE type_code = 'pageActions' AND lookup_code = 'sections')
WHERE keyword IN ('profile-link', 'logout')
  AND id_actions IS NOT NULL
  AND EXISTS (SELECT 1 FROM lookups WHERE type_code = 'pageActions' AND lookup_code = 'sections');

UPDATE pages SET is_system = 1 WHERE keyword IN ('logout', 'profile-link');
UPDATE pages SET url = '/missing' WHERE keyword = 'missing';
UPDATE pages SET url = '/no-access' WHERE keyword = 'no_access';
UPDATE pages SET url = '/no-access-guest' WHERE keyword = 'no_access_guest';
UPDATE pages SET url = '/profile-link' WHERE keyword = 'profile-link';

DELETE FROM pages 
WHERE keyword IN ("admin-link","cmsSelect","cmsInsert","cmsUpdate","cmsDelete",
"userSelect","userInsert","userUpdate","userDelete",
"groupSelect","groupInsert","groupUpdate","groupDelete",
"export","exportData","assetSelect","assetInsert","assetUpdate","assetDelete",
"userGenCode","email","exportDelete","groupUpdateCustom","data",
"cmsPreferences","cmsPreferencesUpdate","language",
"moduleScheduledJobs","moduleScheduledJobsCompose","cmsExport","cmsImport",
"moduleFormsActions","moduleFormsAction",
"ajax_get_groups","ajax_get_table_names","ajax_get_table_fields",
"ajax_search_anchor_section","ajax_search_data_source","ajax_search_user_chat",
"ajax_set_data_filter","ajax_set_user_language","ajax_get_lookups","ajax_get_languages",
"sh_globals","sh_modules","ajax_get_assets","moduleScheduledJobsCalendar","dataDelete",
"cms-api_v1_admin_get_access","cms-api_v1_admin_get_pages",
"cms-api_v1_admin_page_fields","cms-api_v1_admin_page_sections",
"cms-api_v1_content_get_all_routes","cms-api_v1_content_get_page","cms-api_v1_content_put_page",
"cms-api_v1_auth_login","cms-api_v1_auth_two-factor-verify",
"cms-api_v1_auth_refresh_token","cms-api_v1_auth_logout","callback");

-- Drop page columns no longer needed
CALL drop_foreign_key('pages', 'FK_2074E575E8D3C633');
CALL drop_foreign_key('pages', 'FK_2074E575DBD5589F');
CALL drop_table_column('pages', 'protocol');
CALL drop_table_column('pages', 'id_actions');
CALL drop_table_column('pages', 'id_navigation_section');

DELETE FROM lookups WHERE type_code = 'pageActions';


-- ============================================================================
-- 2. CONFIGURATION PAGES
-- ============================================================================

-- Global CSS page
INSERT IGNORE INTO `pageType` (`name`) VALUES ('global_css');

INSERT IGNORE INTO `pages` (`keyword`, `url`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`) 
VALUES ('sh-global-css', NULL, NULL, 0, 0, NULL, (SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1), (SELECT id FROM lookups WHERE lookup_code = 'web'));
SET @id_page_values = (SELECT id FROM pages WHERE keyword = 'sh-global-css');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) VALUES ('1', @id_page_values, '1', '0', '1', '0');

INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('css', '15');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('custom_css', get_field_type_id('css'), '0');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1), get_field_id('custom_css'), NULL, 'Enter your own CSS rules in this field to customize the appearance of your pages, elements, or components.');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1), get_field_id('title'), NULL, 'Page title');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1), get_field_id('description'), NULL, 'Page description');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('title'), '2', 'Custom CSS');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('title'), '3', 'Custom CSS');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('description'), '2', 'Geben Sie in diesem Feld Ihre eigenen CSS-Regeln ein.');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('description'), '3', 'Enter your own CSS rules in this field.');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('custom_css'), '1', '');

-- Global Values page
INSERT IGNORE INTO `pageType` (`name`) VALUES ('global_values');

INSERT IGNORE INTO `pages` (`keyword`, `url`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`) 
VALUES ('sh-global-values', NULL, NULL, 0, 10, NULL, (SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1), (SELECT id FROM lookups WHERE lookup_code = 'mobile_and_web'));
SET @id_page_values = (SELECT id FROM pages WHERE keyword = 'sh-global-values');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) VALUES ('1', @id_page_values, '1', '0', '1', '0');

INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('global_values', get_field_type_id('json'), '1');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1), get_field_id('global_values'), NULL, 'JSON object where can be defined global translation keys.');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1), get_field_id('title'), NULL, 'Page title');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1), get_field_id('description'), NULL, 'Page description');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('title'), '2', 'Global Values');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('title'), '3', 'Global Values');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('description'), '2', 'JSON object for global translations.');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_values, get_field_id('description'), '3', 'JSON object for global translations.');

-- Drop gender from translations
CALL drop_foreign_key('sections_fields_translation', 'FK_EC5054155D8601CD');
CALL drop_table_column('sections_fields_translation', 'id_genders');


-- ============================================================================
-- 3. FIELD MANAGEMENT
-- ============================================================================

-- FIX: Add config column to fields table BEFORE trying to insert with it
CALL add_table_column('fields', 'config', 'LONGTEXT DEFAULT NULL');

DELETE FROM `fields` WHERE `name` = 'children';

CALL add_table_column('styles_fields', 'title', 'VARCHAR(100) NOT NULL');
CALL add_table_column('pageType_fields', 'title', 'VARCHAR(100) NOT NULL');

-- Update pageType_fields titles (idempotent UPDATEs)
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'core' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'experiment' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'intern' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'maintenance' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_global_css' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page title' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('title');
UPDATE pageType_fields SET title = 'Page description' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'core' LIMIT 1) AND id_fields = get_field_id('description');
UPDATE pageType_fields SET title = 'Page icon' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'core' LIMIT 1) AND id_fields = get_field_id('icon');
UPDATE pageType_fields SET title = 'Activation email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_activate');
UPDATE pageType_fields SET title = 'Reminder email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_reminder');
UPDATE pageType_fields SET title = 'Email subject' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_subject');
UPDATE pageType_fields SET title = 'Activate subject' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_activate_subject');
UPDATE pageType_fields SET title = 'Reminder subject' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_reminder_subject');
UPDATE pageType_fields SET title = 'Activate email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_activate_email_address');
UPDATE pageType_fields SET title = 'Delete confirm email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_delete_profile_email_address');
UPDATE pageType_fields SET title = 'Delete subject' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_delete_profile_subject');
UPDATE pageType_fields SET title = 'Delete email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_delete_profile');
UPDATE pageType_fields SET title = 'Delete notify email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_delete_profile_email_address_notification_copy');
UPDATE pageType_fields SET title = '2FA subject' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_2fa_subject');
UPDATE pageType_fields SET title = '2FA email' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'emails' LIMIT 1) AND id_fields = get_field_id('email_2fa');
UPDATE pageType_fields SET title = 'Page description' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'experiment' LIMIT 1) AND id_fields = get_field_id('description');
UPDATE pageType_fields SET title = 'Page icon' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'experiment' LIMIT 1) AND id_fields = get_field_id('icon');
UPDATE pageType_fields SET title = 'Page description' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1) AND id_fields = get_field_id('description');
UPDATE pageType_fields SET title = 'Custom CSS' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_css' LIMIT 1) AND id_fields = get_field_id('custom_css');
UPDATE pageType_fields SET title = 'Page description' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1) AND id_fields = get_field_id('description');
UPDATE pageType_fields SET title = 'Translation keys' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'global_values' LIMIT 1) AND id_fields = get_field_id('global_values');
UPDATE pageType_fields SET title = 'Page description' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'intern' LIMIT 1) AND id_fields = get_field_id('description');
UPDATE pageType_fields SET title = 'Page icon' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'intern' LIMIT 1) AND id_fields = get_field_id('icon');
UPDATE pageType_fields SET title = 'Maintenance text' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'maintenance' LIMIT 1) AND id_fields = get_field_id('maintenance');
UPDATE pageType_fields SET title = 'Maintenance date' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'maintenance' LIMIT 1) AND id_fields = get_field_id('maintenance_date');
UPDATE pageType_fields SET title = 'Maintenance time' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'maintenance' LIMIT 1) AND id_fields = get_field_id('maintenance_time');
UPDATE pageType_fields SET title = 'Custom CSS' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_global_css' LIMIT 1) AND id_fields = get_field_id('custom_css');
UPDATE pageType_fields SET title = 'Enable reset' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('enable_reset_password');
UPDATE pageType_fields SET title = 'Question 1' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_01');
UPDATE pageType_fields SET title = 'Question 2' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_02');
UPDATE pageType_fields SET title = 'Question 3' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_03');
UPDATE pageType_fields SET title = 'Question 4' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_04');
UPDATE pageType_fields SET title = 'Question 5' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_05');
UPDATE pageType_fields SET title = 'Question 6' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_06');
UPDATE pageType_fields SET title = 'Question 7' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_07');
UPDATE pageType_fields SET title = 'Question 8' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_08');
UPDATE pageType_fields SET title = 'Question 9' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_09');
UPDATE pageType_fields SET title = 'Question 10' WHERE id_pageType = (SELECT id FROM pageType WHERE `name` = 'sh_security_questions' LIMIT 1) AND id_fields = get_field_id('security_question_10');

-- New field types
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-css', '8');
UPDATE `fields` SET id_type = get_field_type_id('select-css') WHERE `name` IN ('css', 'css_mobile');

UPDATE styles_fields SET title = 'Mobile CSS Classes' WHERE id_fields = get_field_id('css_mobile');

UPDATE users SET `name` = 'Guest' WHERE id = 1;


-- ============================================================================
-- 4. RENAME formActions -> actions
-- ============================================================================

DROP TABLE IF EXISTS actions;
CALL rename_table('formActions', 'actions');
CALL rename_table('scheduledJobs_formActions', 'scheduledJobs_actions');

CALL add_foreign_key('scheduledJobs_actions', 'FK_862DD4F8DBD5589F', 'id_actions', 'actions (id)');

CALL drop_foreign_key('actions', 'FK_3128FB5E8A8FCE9D');
CALL drop_foreign_key('actions', 'FK_548F1EF4AC2316F');
CALL drop_foreign_key('actions', 'FK_3128FB5EE2E6A7C3');
CALL drop_index('actions', 'IDX_548F1EF8A8FCE9D');

CALL rename_table_column('actions', 'id_formProjectActionTriggerTypes', 'id_actionTriggerTypes', NULL);
ALTER TABLE actions CHANGE id_actionTriggerTypes id_actionTriggerTypes INT NOT NULL;

CALL add_foreign_key('actions', 'FK_548F1EF4AC2316F', 'id_actionTriggerTypes', 'lookups (id)');
CALL add_index('actions', 'IDX_548F1EF4AC2316F', 'id_actionTriggerTypes', FALSE);

-- FIX: Keep id_dataTables nullable or update NULLs first
UPDATE actions SET id_dataTables = 0 WHERE id_dataTables IS NULL AND 0 = 1;
ALTER TABLE actions CHANGE id_dataTables id_dataTables INT NOT NULL;

CALL add_foreign_key('actions', 'FK_548F1EFE2E6A7C3', 'id_dataTables', 'dataTables(id)');
CALL add_index('scheduledJobs_actions', 'IDX_862DD4F8DBD5589F', 'id_actions', FALSE);

CALL rename_index('scheduledJobs_actions', 'idx_ae5b5d0b8030ba52', 'IDX_862DD4F88030BA52');
CALL rename_index('scheduledJobs_actions', 'idx_ae5b5d0bf3854f45', 'IDX_862DD4F8F3854F45');
CALL rename_index('actions', 'fk_548f1efe2e6a7c3', 'IDX_548F1EFE2E6A7C3');


-- ============================================================================
-- 5. CMS PREFERENCES PAGES
-- ============================================================================

INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('checkbox', '16');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-language', '8');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-timezone', '8');

INSERT IGNORE INTO `pageType` (`name`) VALUES ('cms_preferences');

INSERT IGNORE INTO `pages` (`keyword`, `url`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES ('sh-cms-preferences', NULL, NULL, 0, 0, NULL, (SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), (SELECT id FROM lookups WHERE lookup_code = 'web'));
SET @id_page_cms_prefs = (SELECT id FROM pages WHERE keyword = 'sh-cms-preferences');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) VALUES ('1', @id_page_cms_prefs, '1', '0', '1', '0');

INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('default_language_id', get_field_type_id('select-language'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('anonymous_users', get_field_type_id('checkbox'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('firebase_config', get_field_type_id('json'), '1');
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`) VALUES ('default_timezone', get_field_type_id('select-timezone'), '1');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('callback_api_key'), NULL, 'API key for callback services');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('default_language_id'), NULL, 'Default language for the CMS system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('anonymous_users'), '0', 'Allow anonymous users to access the system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('firebase_config'), NULL, 'Firebase configuration in JSON format');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('default_timezone'), 'Europe/Zurich', 'Default timezone for the CMS system');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('title'), NULL, 'Page title');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES ((SELECT id FROM pageType WHERE `name` = 'cms_preferences' LIMIT 1), get_field_id('description'), NULL, 'Page description');

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('title'), '2', 'CMS Preferences');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('title'), '3', 'CMS Preferences');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('description'), '2', 'Konfiguration der CMS-Einstellungen');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('description'), '3', 'CMS configuration settings');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('callback_api_key'), '1', '');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('default_language_id'), '1', 2);
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('anonymous_users'), '1', '0');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('firebase_config'), '1', '');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_cms_prefs, get_field_id('default_timezone'), '1', (SELECT id FROM lookups WHERE type_code = 'timezones' AND lookup_code = 'Europe/Zurich'));

UPDATE pages SET nav_position = null WHERE keyword IN ('sh-global-css', 'sh-global-values', 'sh-cms-preferences');


-- ============================================================================
-- 6. STYLE CLEANUP & SECTION COLUMN ADDITIONS
-- ============================================================================

DELETE FROM styles WHERE `name` IN (
    'jumbotron','markdownInline','chat','card','form','quiz','rawText',
    'accordionList','navigationContainer','navigationAccordion','nestedList',
    'navigationNested','sortableList','formUserInput','conditionalContainer',
    'json','userProgress','autocomplete','navigationBar','trigger',
    'conditionFailed','conditionBuilder','dataConfigBuilder','actionConfigBuilder'
);

DELETE FROM `fields` 
WHERE `id` NOT IN (SELECT DISTINCT `id_fields` FROM `styles_fields`) 
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `pages_fields`)
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `sections_fields_translation`)
AND `id` NOT IN (SELECT DISTINCT `id_fields` FROM `pages_fields_translation`);

DELETE FROM `fieldType` WHERE `id` NOT IN (SELECT DISTINCT `id_type` FROM `fields`);

CALL add_table_column('sections', 'debug', 'TINYINT DEFAULT 0');
CALL add_table_column('sections', 'condition', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'data_config', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'css', 'LONGTEXT DEFAULT NULL');
CALL add_table_column('sections', 'css_mobile', 'LONGTEXT DEFAULT NULL');

-- Migrate data from section translations to section columns
UPDATE sections s
JOIN sections_fields_translation sft ON sft.id_sections = s.id
JOIN styles st ON st.id = s.id_styles
SET s.css = sft.content
WHERE sft.id_fields = get_field_id('css') AND s.css IS NULL;

UPDATE sections s
JOIN sections_fields_translation sft ON sft.id_sections = s.id
JOIN styles st ON st.id = s.id_styles
SET s.debug = CAST(sft.content AS UNSIGNED)
WHERE sft.id_fields = get_field_id('debug');

UPDATE sections s
JOIN sections_fields_translation sft ON sft.id_sections = s.id
JOIN styles st ON st.id = s.id_styles
SET s.`condition` = sft.content
WHERE sft.id_fields = get_field_id('condition') AND s.`condition` IS NULL;

UPDATE sections s
JOIN sections_fields_translation sft ON sft.id_sections = s.id
JOIN styles st ON st.id = s.id_styles
SET s.data_config = sft.content
WHERE sft.id_fields = get_field_id('data_config') AND s.data_config IS NULL;

DELETE FROM `fields` WHERE `name` IN ('css', 'css_mobile', 'debug', 'condition', 'data_config');

-- More style deletions
DELETE FROM styles WHERE `name` IN (
    'tabs', 'tab', 'progressBar', 'table', 'tableRow', 'tableCell', 'accordion', '', 
    'card', 'alert', 'radioGroup', 'radio-group', 'radio', 'carousel', 'container', 'slider', 'checkbox', 'div', 'htmlTag',
    'textarea', 'formUserInputRecord', 'formUserInputLog', 'showUserInput', 'profile', 'resetPasword',
    'validate', 'heading', 'markdown', 'plaintext', 'input', 'select'
);

DELETE FROM `fields` WHERE `name` IN ('ajax', 'redirect_at_end', 'html_tag', 'type_input', 'options');

-- FIX: Corrected SQL logic for styles_fields delete (id_styles was in wrong subquery)
DELETE FROM styles_fields
WHERE id_styles = get_style_id('image')
  AND id_fields IN (SELECT id FROM fields WHERE `name` IN ('height', 'width'));

DELETE FROM styles_fields
WHERE id_styles = get_style_id('video')
  AND id_fields IN (SELECT id FROM fields WHERE `name` IN ('sources'));

INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-image', '8');
INSERT IGNORE INTO `fieldType` (`name`, `position`) VALUES ('select-video', '8');

INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`, `config`) VALUES ('video_src', get_field_type_id('select-video'), 0, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES (get_style_id('video'), get_field_id('video_src'), null, null, 0, 0, 'Video Source');

UPDATE `fields` SET id_type = get_field_type_id('select-image') WHERE `name` IN ('img_src');
UPDATE `fields` SET id_type = get_field_type_id('text') WHERE `name` IN ('value');

ALTER TABLE styles_fields CHANGE default_value default_value VARCHAR(1000) DEFAULT NULL;

CALL drop_index('transactions', 'idx_transactions_table_name');


-- ============================================================================
-- 7. NEW TABLES
-- ============================================================================

-- Styles allowed relationships
CREATE TABLE IF NOT EXISTS `styles_allowed_relationships` (
  `id_parent_style` int NOT NULL,
  `id_child_style` int NOT NULL,
  PRIMARY KEY (`id_parent_style`,`id_child_style`),
  KEY `IDX_757F0414DC4D59BB` (`id_parent_style`),
  KEY `IDX_757F041478A9D70E` (`id_child_style`),
  CONSTRAINT `FK_styles_relationships_parent` FOREIGN KEY (`id_parent_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_styles_relationships_child` FOREIGN KEY (`id_child_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gender removal
CALL drop_foreign_key('sections_fields_translation', 'FK_EC5054155D8601CD');
CALL drop_foreign_key('users', 'FK_1483A5E95D8601CD');
CALL drop_table_column('sections_fields_translation', 'id_genders');
CALL drop_table_column('users', 'id_genders');
DROP TABLE IF EXISTS genders;

-- dataCells language support
CALL add_table_column('dataCells', 'id_languages', 'int NOT NULL DEFAULT 1');
CALL add_foreign_key('dataCells', 'FK_dataCells_languages', 'id_languages', 'languages(id)');

-- Update primary key to include id_languages (only if not already 3-column PK)
SET @pk_col_count = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dataCells' AND INDEX_NAME = 'PRIMARY'
);
SET @sql = IF(@pk_col_count < 3,
    'ALTER TABLE `dataCells` DROP PRIMARY KEY, ADD PRIMARY KEY (`id_dataRows`, `id_dataCols`, `id_languages`)',
    'SELECT "PK already has 3 columns"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CALL add_index('dataCells', 'IDX_726A5F2520E4EF5E', 'id_languages', FALSE);

-- Action translations table
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

-- FIX: Create RBAC tables BEFORE stored procedures reference them
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_roles` (
  `id_users` int NOT NULL,
  `id_roles` int NOT NULL,
  PRIMARY KEY (`id_users`, `id_roles`),
  KEY `IDX_users_roles_id_users` (`id_users`),
  KEY `IDX_users_roles_id_roles` (`id_roles`),
  CONSTRAINT `FK_users_roles_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_users_roles_roles` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_data_access` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_roles` int NOT NULL,
  `id_resourceTypes` int NOT NULL,
  `resource_id` int NOT NULL,
  `crud_permissions` smallint unsigned DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_role_data_access_roles` (`id_roles`),
  KEY `IDX_role_data_access_resource_types` (`id_resourceTypes`),
  CONSTRAINT `FK_role_data_access_roles` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_role_data_access_resource_types` FOREIGN KEY (`id_resourceTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIX: Create dataAccessAudit BEFORE rename_table_column references it
CREATE TABLE IF NOT EXISTS `dataAccessAudit` (
    `id` int NOT NULL AUTO_INCREMENT,
    `id_users` int NOT NULL,
    `id_resourceTypes` int NOT NULL,
    `resource_id` int NOT NULL,
    `id_actions` int NOT NULL,
    `id_permissionResults` int NOT NULL,
    `crud_permission` smallint unsigned DEFAULT NULL,
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

-- Page versions table
DROP TABLE IF EXISTS `page_versions`;
CREATE TABLE `page_versions` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `id_pages` INT NOT NULL,
  `version_number` INT NOT NULL,
  `version_name` VARCHAR(255) DEFAULT NULL,
  `page_json` JSON NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` DATETIME DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_version_number` (`id_pages`, `version_number`),
  KEY `idx_id_pages` (`id_pages`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_published_at` (`published_at`),
  CONSTRAINT `FK_page_versions_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_page_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL add_table_column('pages', 'published_version_id', 'INT DEFAULT NULL');
CALL add_index('pages', 'IDX_2074E575B5D68A8D', 'published_version_id', FALSE);
CALL add_foreign_key('pages', 'FK_2074E575B5D68A8D', 'published_version_id', 'page_versions (id)');


-- ============================================================================
-- 8. LOOKUP DATA INSERTS
-- ============================================================================

INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('resourceTypes', 'group', 'Group', 'User groups for data access control'),
('resourceTypes', 'data_table', 'Data Table', 'Custom data tables'),
('resourceTypes', 'pages', 'Pages', 'Admin pages access control')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('auditActions', 'filter', 'Filter', 'Data filtering applied to READ operations'),
('auditActions', 'create', 'Create', 'Permission check for CREATE operations'),
('auditActions', 'read', 'Read', 'Permission check for specific READ operations'),
('auditActions', 'update', 'Update', 'Permission check for UPDATE operations'),
('auditActions', 'delete', 'Delete', 'Permission check for DELETE operations')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('permissionResults', 'granted', 'Granted', 'Permission was granted'),
('permissionResults', 'denied', 'Denied', 'Permission was denied')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- Timezones
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('timezones', 'America/New_York', 'Eastern Time (ET)', 'UTC-5/-4'),
('timezones', 'America/Chicago', 'Central Time (CT)', 'UTC-6/-5'),
('timezones', 'America/Denver', 'Mountain Time (MT)', 'UTC-7/-6'),
('timezones', 'America/Phoenix', 'Mountain Time (MST)', 'UTC-7'),
('timezones', 'America/Los_Angeles', 'Pacific Time (PT)', 'UTC-8/-7'),
('timezones', 'America/Anchorage', 'Alaska Time (AKT)', 'UTC-9/-8'),
('timezones', 'America/Juneau', 'Alaska Time (AKT)', 'UTC-9/-8'),
('timezones', 'Pacific/Honolulu', 'Hawaii Time (HT)', 'UTC-10'),
('timezones', 'America/Halifax', 'Atlantic Time (AT)', 'UTC-4/-3'),
('timezones', 'America/St_Johns', 'Newfoundland Time (NT)', 'UTC-3:30/-2:30'),
('timezones', 'America/Regina', 'Central Time (CT)', 'UTC-6'),
('timezones', 'America/Winnipeg', 'Central Time (CT)', 'UTC-6/-5'),
('timezones', 'America/Toronto', 'Eastern Time (ET)', 'UTC-5/-4'),
('timezones', 'America/Vancouver', 'Pacific Time (PT)', 'UTC-8/-7'),
('timezones', 'America/Edmonton', 'Mountain Time (MT)', 'UTC-7/-6'),
('timezones', 'America/Sao_Paulo', 'Brasilia Time (BRT)', 'UTC-3/-2'),
('timezones', 'America/Buenos_Aires', 'Argentina Time (ART)', 'UTC-3'),
('timezones', 'America/Lima', 'Peru Time (PET)', 'UTC-5'),
('timezones', 'America/Bogota', 'Colombia Time (COT)', 'UTC-5'),
('timezones', 'America/Caracas', 'Venezuelan Time (VET)', 'UTC-4'),
('timezones', 'America/Santiago', 'Chile Time (CLT)', 'UTC-4/-3'),
('timezones', 'America/Mexico_City', 'Central Time (CT)', 'UTC-6/-5'),
('timezones', 'Europe/London', 'Greenwich Mean Time (GMT)', 'UTC+0/+1'),
('timezones', 'Europe/Berlin', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Paris', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Rome', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Madrid', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Amsterdam', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Brussels', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Vienna', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Zurich', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Prague', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Warsaw', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Budapest', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Bucharest', 'Eastern European Time (EET)', 'UTC+2/+3'),
('timezones', 'Europe/Kiev', 'Eastern European Time (EET)', 'UTC+2/+3'),
('timezones', 'Europe/Athens', 'Eastern European Time (EET)', 'UTC+2/+3'),
('timezones', 'Europe/Helsinki', 'Eastern European Time (EET)', 'UTC+2/+3'),
('timezones', 'Europe/Stockholm', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Copenhagen', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Oslo', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Europe/Moscow', 'Moscow Time (MSK)', 'UTC+3'),
('timezones', 'Europe/Istanbul', 'Turkey Time (TRT)', 'UTC+3'),
('timezones', 'Asia/Tokyo', 'Japan Standard Time (JST)', 'UTC+9'),
('timezones', 'Asia/Shanghai', 'China Standard Time (CST)', 'UTC+8'),
('timezones', 'Asia/Hong_Kong', 'Hong Kong Time (HKT)', 'UTC+8'),
('timezones', 'Asia/Singapore', 'Singapore Time (SGT)', 'UTC+8'),
('timezones', 'Asia/Kolkata', 'India Standard Time (IST)', 'UTC+5:30'),
('timezones', 'Asia/Karachi', 'Pakistan Time (PKT)', 'UTC+5'),
('timezones', 'Asia/Dhaka', 'Bangladesh Time (BST)', 'UTC+6'),
('timezones', 'Asia/Bangkok', 'Indochina Time (ICT)', 'UTC+7'),
('timezones', 'Asia/Jakarta', 'Western Indonesian Time (WIB)', 'UTC+7'),
('timezones', 'Asia/Manila', 'Philippine Time (PHT)', 'UTC+8'),
('timezones', 'Asia/Seoul', 'Korea Standard Time (KST)', 'UTC+9'),
('timezones', 'Asia/Taipei', 'Taiwan Time (TWT)', 'UTC+8'),
('timezones', 'Asia/Kuala_Lumpur', 'Malaysia Time (MYT)', 'UTC+8'),
('timezones', 'Asia/Dubai', 'Gulf Time (GST)', 'UTC+4'),
('timezones', 'Asia/Riyadh', 'Arabia Time (AST)', 'UTC+3'),
('timezones', 'Asia/Tehran', 'Iran Time (IRT)', 'UTC+3:30/+4:30'),
('timezones', 'Asia/Jerusalem', 'Israel Time (IST)', 'UTC+2/+3'),
('timezones', 'Africa/Cairo', 'Eastern European Time (EET)', 'UTC+2/+3'),
('timezones', 'Africa/Johannesburg', 'South Africa Time (SAST)', 'UTC+2'),
('timezones', 'Africa/Lagos', 'West Africa Time (WAT)', 'UTC+1'),
('timezones', 'Africa/Nairobi', 'East Africa Time (EAT)', 'UTC+3'),
('timezones', 'Africa/Casablanca', 'Western European Time (WET)', 'UTC+0/+1'),
('timezones', 'Africa/Algiers', 'Central European Time (CET)', 'UTC+1/+2'),
('timezones', 'Australia/Sydney', 'Australian Eastern Time (AET)', 'UTC+10/+11'),
('timezones', 'Australia/Melbourne', 'Australian Eastern Time (AET)', 'UTC+10/+11'),
('timezones', 'Australia/Brisbane', 'Australian Eastern Time (AEST)', 'UTC+10'),
('timezones', 'Australia/Perth', 'Australian Western Time (AWST)', 'UTC+8'),
('timezones', 'Australia/Adelaide', 'Australian Central Time (ACT)', 'UTC+9:30/+10:30'),
('timezones', 'Pacific/Auckland', 'New Zealand Time (NZT)', 'UTC+12/+13'),
('timezones', 'Pacific/Fiji', 'Fiji Time (FJT)', 'UTC+12/+13'),
('timezones', 'Pacific/Guam', 'Chamorro Time (ChST)', 'UTC+10'),
('timezones', 'Pacific/Saipan', 'Chamorro Time (ChST)', 'UTC+10')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

-- User timezone
CALL add_table_column('users', 'id_timezones', 'INT DEFAULT NULL');
CALL add_foreign_key('users', 'FK_users_id_timezones', 'id_timezones', 'lookups(id)');
CALL add_index('users', 'IDX_1483A5E9F5677479', 'id_timezones', FALSE);

-- Job type lookups
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('jobTypes', 'email', 'Email', 'Email sending job'),
('jobTypes', 'notification', 'Notification', 'Push notification job'),
('jobTypes', 'task', 'Task', 'Custom task execution'),
('jobTypes', 'reminder', 'Reminder', 'Scheduled reminder job')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);

INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('scheduledJobsStatus', 'running', 'Running', 'Job is currently running'),
('scheduledJobsStatus', 'cancelled', 'Cancelled', 'Job was manually cancelled')
ON DUPLICATE KEY UPDATE lookup_value = VALUES(lookup_value), lookup_description = VALUES(lookup_description);


-- ============================================================================
-- 9. SCHEDULED JOBS REFACTORING
-- ============================================================================

-- Drop old junction tables
DROP TABLE IF EXISTS `scheduledJobs_actions`;
DROP TABLE IF EXISTS `scheduledJobs_users`;
DROP TABLE IF EXISTS `scheduledJobs_mailQueue`;
DROP TABLE IF EXISTS `scheduledJobs_notifications`;
DROP TABLE IF EXISTS `scheduledJobs_reminders`;
DROP TABLE IF EXISTS `scheduledJobs_tasks`;

-- Drop tables no longer needed
DROP TABLE IF EXISTS `mailAttachments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `mailQueue`;
DROP TABLE IF EXISTS `notifications`;

-- Drop and recreate scheduledJobs
DROP TABLE IF EXISTS `scheduledJobs`;
CREATE TABLE `scheduledJobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_users` int DEFAULT NULL,
  `id_actions` int DEFAULT NULL,
  `id_dataTables` int DEFAULT NULL,
  `id_dataRows` int DEFAULT NULL,
  `id_jobTypes` int NOT NULL,
  `id_jobStatus` int NOT NULL,
  `date_create` datetime NOT NULL,
  `date_to_be_executed` datetime NOT NULL,
  `date_executed` datetime DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `config` json DEFAULT NULL,
  PRIMARY KEY (`id`),
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

-- Drop acl_users table
DROP TABLE IF EXISTS `acl_users`;


-- ============================================================================
-- 10. DROP OLD OBJECTS
-- ============================================================================

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

-- Drop old views
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

-- Drop domain tables if still around
DROP TABLE IF EXISTS activityType;
DROP TABLE IF EXISTS styleType;

-- Remove styles.id_type column
CALL drop_foreign_key('styles', 'FK_B65AFAF57FE4B2B');
CALL drop_table_column('styles', 'id_type');


-- ============================================================================
-- 11. DATETIME COMMENT MIGRATIONS (tables must exist before this)
-- ============================================================================

CALL `rename_table_column`('apiRequestLogs', 'request_time', 'request_time', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('apiRequestLogs', 'response_time', 'response_time', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('callbackLogs', 'callback_date', 'callback_date', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('dataAccessAudit', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('dataRows', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('dataTables', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('page_versions', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('page_versions', 'published_at', 'published_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('role_data_access', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('role_data_access', 'updated_at', 'updated_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('transactions', 'transaction_time', 'transaction_time', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('user_activity', 'timestamp', 'timestamp', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('users_2fa_codes', 'created_at', 'created_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('users_2fa_codes', 'expires_at', 'expires_at', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('validation_codes', 'created', 'created', '(DC2Type:datetime_immutable)');
CALL `rename_table_column`('validation_codes', 'consumed', 'consumed', '(DC2Type:datetime_immutable)');


SET SQL_MODE = @OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS = 1;

SELECT '39b completed successfully' AS status;
