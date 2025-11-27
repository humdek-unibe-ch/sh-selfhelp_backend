-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: sym_test
-- ------------------------------------------------------
-- Server version	9.1.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `acl_groups`
--

DROP TABLE IF EXISTS `acl_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acl_groups` (
  `id_groups` int NOT NULL,
  `id_pages` int NOT NULL,
  `acl_select` tinyint(1) NOT NULL DEFAULT '1',
  `acl_insert` tinyint(1) NOT NULL DEFAULT '0',
  `acl_update` tinyint(1) NOT NULL DEFAULT '0',
  `acl_delete` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_groups`,`id_pages`),
  KEY `IDX_AB370E20D65A8C9D` (`id_groups`),
  KEY `IDX_AB370E20CEF1A445` (`id_pages`),
  CONSTRAINT `FK_AB370E20CEF1A445` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_AB370E20D65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `action_translations`
--

DROP TABLE IF EXISTS `action_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `action_translations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_actions` int NOT NULL,
  `translation_key` varchar(255) NOT NULL,
  `id_languages` int NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_5AC50EA7DBD5589F` (`id_actions`),
  KEY `IDX_5AC50EA720E4EF5E` (`id_languages`),
  CONSTRAINT `IDX_5AC50EA720E4EF5E` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `IDX_5AC50EA7DBD5589F` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `actions`
--

DROP TABLE IF EXISTS `actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `id_actionTriggerTypes` int NOT NULL,
  `config` longtext,
  `id_dataTables` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_548F1EF4AC2316F` (`id_actionTriggerTypes`),
  KEY `IDX_548F1EFE2E6A7C3` (`id_dataTables`),
  CONSTRAINT `FK_548F1EF4AC2316F` FOREIGN KEY (`id_actionTriggerTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_548F1EFE2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_routes`
--

DROP TABLE IF EXISTS `api_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_routes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `route_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `controller` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `methods` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requirements` json DEFAULT NULL,
  `params` json DEFAULT NULL COMMENT 'Expected parameters: name → {in: body|query, required: bool}',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_route_name_version` (`route_name`,`version`),
  UNIQUE KEY `uniq_version_path_methods` (`version`,`path`,`methods`)
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_routes_permissions`
--

DROP TABLE IF EXISTS `api_routes_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_routes_permissions` (
  `id_api_routes` int NOT NULL,
  `id_permissions` int NOT NULL,
  PRIMARY KEY (`id_api_routes`,`id_permissions`),
  KEY `IDX_487141C411A805E4` (`id_api_routes`),
  KEY `IDX_487141C435FF0198` (`id_permissions`),
  CONSTRAINT `FK_arp_api_routes` FOREIGN KEY (`id_api_routes`) REFERENCES `api_routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_arp_permissions` FOREIGN KEY (`id_permissions`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `apiRequestLogs`
--

DROP TABLE IF EXISTS `apiRequestLogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `apiRequestLogs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `route_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_time` datetime NOT NULL,
  `response_time` datetime NOT NULL,
  `duration_ms` int NOT NULL,
  `request_params` longtext COLLATE utf8mb4_unicode_ci,
  `request_headers` longtext COLLATE utf8mb4_unicode_ci,
  `response_data` longtext COLLATE utf8mb4_unicode_ci,
  `error_message` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=61116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_assetTypes` int NOT NULL,
  `folder` varchar(100) DEFAULT NULL,
  `file_name` varchar(100) DEFAULT NULL,
  `file_path` varchar(1000) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_79D17D8ED7DF1668` (`file_name`),
  KEY `IDX_79D17D8E843A9330` (`id_assetTypes`),
  CONSTRAINT `FK_79D17D8E843A9330` FOREIGN KEY (`id_assetTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `callbackLogs`
--

DROP TABLE IF EXISTS `callbackLogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `callbackLogs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `callback_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `remote_addr` varchar(200) DEFAULT NULL,
  `redirect_url` varchar(1000) DEFAULT NULL,
  `callback_params` longtext,
  `status` varchar(200) DEFAULT NULL,
  `callback_output` longtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `codes_groups`
--

DROP TABLE IF EXISTS `codes_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `codes_groups` (
  `code` varchar(16) NOT NULL,
  `id_groups` int NOT NULL,
  PRIMARY KEY (`code`,`id_groups`),
  KEY `IDX_9F20ED76D65A8C9D` (`id_groups`),
  KEY `IDX_9F20ED7677153098` (`code`),
  CONSTRAINT `FK_9F20ED7677153098` FOREIGN KEY (`code`) REFERENCES `validation_codes` (`code`) ON DELETE CASCADE,
  CONSTRAINT `FK_9F20ED76D65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dataAccessAudit`
--

DROP TABLE IF EXISTS `dataAccessAudit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataAccessAudit` (
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
  `user_agent` longtext,
  `request_uri` longtext,
  `notes` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `IDX_D2C78316DBD5589F` (`id_actions`),
  KEY `IDX_dataAccessAudit_users` (`id_users`),
  KEY `IDX_dataAccessAudit_resource_types` (`id_resourceTypes`),
  KEY `IDX_dataAccessAudit_resource_id` (`resource_id`),
  KEY `IDX_dataAccessAudit_created_at` (`created_at`),
  KEY `IDX_dataAccessAudit_permission_results` (`id_permissionResults`),
  KEY `IDX_dataAccessAudit_http_method` (`http_method`),
  KEY `IDX_dataAccessAudit_request_body_hash` (`request_body_hash`)
) ENGINE=MyISAM AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dataCells`
--

DROP TABLE IF EXISTS `dataCells`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataCells` (
  `id_dataRows` int NOT NULL,
  `id_dataCols` int NOT NULL,
  `value` longtext NOT NULL,
  `id_languages` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_dataRows`,`id_dataCols`,`id_languages`),
  KEY `IDX_726A5F25F3854F45` (`id_dataRows`),
  KEY `IDX_726A5F25B216B425` (`id_dataCols`),
  KEY `IDX_726A5F2520E4EF5E` (`id_languages`),
  CONSTRAINT `FK_726A5F25B216B425` FOREIGN KEY (`id_dataCols`) REFERENCES `dataCols` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_726A5F25F3854F45` FOREIGN KEY (`id_dataRows`) REFERENCES `dataRows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_dataCells_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dataCols`
--

DROP TABLE IF EXISTS `dataCols`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataCols` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `id_dataTables` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_E2CD58B0E2E6A7C3` (`id_dataTables`),
  CONSTRAINT `FK_E2CD58B0E2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dataRows`
--

DROP TABLE IF EXISTS `dataRows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataRows` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_dataTables` int DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `id_users` int DEFAULT NULL,
  `id_actionTriggerTypes` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_A35EA3D0E2E6A7C3` (`id_dataTables`),
  CONSTRAINT `FK_A35EA3D0E2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dataTables`
--

DROP TABLE IF EXISTS `dataTables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataTables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `timestamp` datetime NOT NULL,
  `displayName` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `doctrine_migration_versions`
--

DROP TABLE IF EXISTS `doctrine_migration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctrine_migration_versions` (
  `version` varchar(191) COLLATE utf8mb3_unicode_ci NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fields`
--

DROP TABLE IF EXISTS `fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `id_type` int NOT NULL,
  `display` tinyint(1) NOT NULL,
  `config` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_7EE5E3885E237E06` (`name`),
  KEY `IDX_7EE5E3887FE4B2B` (`id_type`),
  CONSTRAINT `FK_7EE5E388FF2309B7` FOREIGN KEY (`id_type`) REFERENCES `fieldType` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2867 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fieldType`
--

DROP TABLE IF EXISTS `fieldType`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fieldType` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `position` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_C1760DF55E237E06` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=224 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups`
--

DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(250) NOT NULL,
  `id_group_types` int DEFAULT NULL,
  `requires_2fa` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hooks`
--

DROP TABLE IF EXISTS `hooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hooks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_hookTypes` int NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `class` varchar(100) NOT NULL,
  `function` varchar(100) NOT NULL,
  `exec_class` varchar(100) NOT NULL,
  `exec_function` varchar(100) NOT NULL,
  `priority` int NOT NULL DEFAULT '10',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `locale` varchar(5) NOT NULL,
  `language` varchar(100) NOT NULL,
  `csv_separator` varchar(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_A0D153794180C698` (`locale`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `libraries`
--

DROP TABLE IF EXISTS `libraries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libraries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(250) DEFAULT NULL,
  `version` varchar(500) DEFAULT NULL,
  `license` varchar(1000) DEFAULT NULL,
  `comments` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logPerformance`
--

DROP TABLE IF EXISTS `logPerformance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logPerformance` (
  `id_user_activity` int NOT NULL,
  `log` longtext,
  PRIMARY KEY (`id_user_activity`),
  CONSTRAINT `FK_6D164595F2D13C3F` FOREIGN KEY (`id_user_activity`) REFERENCES `user_activity` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lookups`
--

DROP TABLE IF EXISTS `lookups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lookups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_code` varchar(100) NOT NULL,
  `lookup_code` varchar(100) DEFAULT NULL,
  `lookup_value` varchar(200) DEFAULT NULL,
  `lookup_description` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_type_lookup` (`type_code`,`lookup_code`)
) ENGINE=InnoDB AUTO_INCREMENT=1264 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `page_versions`
--

DROP TABLE IF EXISTS `page_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `page_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_pages` int NOT NULL,
  `version_number` int NOT NULL COMMENT 'Incremental version number per page',
  `version_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional user-defined name for the version',
  `page_json` json NOT NULL COMMENT 'Complete JSON structure from getPage() including all languages, conditions, data table configs',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL COMMENT 'When this version was published',
  `metadata` json DEFAULT NULL COMMENT 'Additional info like change summary, tags, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_version_number` (`id_pages`,`version_number`),
  KEY `idx_id_pages` (`id_pages`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_published_at` (`published_at`),
  CONSTRAINT `FK_page_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_page_versions_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=212 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores published page versions with complete JSON structures';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pages`
--

DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `parent` int DEFAULT NULL,
  `is_headless` tinyint(1) NOT NULL DEFAULT '0',
  `nav_position` int DEFAULT NULL,
  `footer_position` int DEFAULT NULL,
  `id_type` int NOT NULL,
  `id_pageAccessTypes` int DEFAULT NULL,
  `is_open_access` tinyint DEFAULT '0',
  `is_system` tinyint DEFAULT '0',
  `published_version_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_2074E5755A93713B` (`keyword`),
  KEY `IDX_2074E5753D8E604F` (`parent`),
  KEY `IDX_2074E5757FE4B2B` (`id_type`),
  KEY `IDX_2074E57534643D90` (`id_pageAccessTypes`),
  KEY `IDX_pages_published_version_id` (`published_version_id`),
  CONSTRAINT `FK_2074E57534643D90` FOREIGN KEY (`id_pageAccessTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_2074E5753D8E604F` FOREIGN KEY (`parent`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_2074E5757FE4B2B` FOREIGN KEY (`id_type`) REFERENCES `pageType` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_2074E575B5D68A8D` FOREIGN KEY (`published_version_id`) REFERENCES `page_versions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pages_fields`
--

DROP TABLE IF EXISTS `pages_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages_fields` (
  `id_pages` int NOT NULL,
  `id_fields` int NOT NULL,
  `default_value` varchar(100) DEFAULT NULL,
  `help` longtext,
  PRIMARY KEY (`id_pages`,`id_fields`),
  KEY `IDX_D36F9887CEF1A445` (`id_pages`),
  KEY `IDX_D36F988758D25665` (`id_fields`),
  CONSTRAINT `FK_D36F988758D25665` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_D36F9887CEF1A445` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pages_fields_translation`
--

DROP TABLE IF EXISTS `pages_fields_translation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages_fields_translation` (
  `id_pages` int NOT NULL,
  `id_fields` int NOT NULL,
  `id_languages` int NOT NULL,
  `content` longtext NOT NULL,
  PRIMARY KEY (`id_pages`,`id_fields`,`id_languages`),
  KEY `IDX_903943EECEF1A445` (`id_pages`),
  KEY `IDX_903943EE58D25665` (`id_fields`),
  KEY `IDX_903943EE20E4EF5E` (`id_languages`),
  CONSTRAINT `FK_903943EE20E4EF5E` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_903943EE58D25665` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_903943EECEF1A445` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pages_sections`
--

DROP TABLE IF EXISTS `pages_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages_sections` (
  `id_pages` int NOT NULL,
  `id_sections` int NOT NULL,
  `position` int DEFAULT NULL,
  PRIMARY KEY (`id_pages`,`id_sections`),
  KEY `IDX_6BD95A69CEF1A445` (`id_pages`),
  KEY `IDX_6BD95A697B4DAF0D` (`id_sections`),
  CONSTRAINT `FK_6BD95A697B4DAF0D` FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_6BD95A69CEF1A445` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pageType`
--

DROP TABLE IF EXISTS `pageType`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pageType` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_AD38E97C5E237E06` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pageType_fields`
--

DROP TABLE IF EXISTS `pageType_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pageType_fields` (
  `id_pageType` int NOT NULL,
  `id_fields` int NOT NULL,
  `default_value` varchar(100) DEFAULT NULL,
  `help` longtext,
  `title` varchar(100) NOT NULL,
  PRIMARY KEY (`id_pageType`,`id_fields`),
  KEY `IDX_B305C68158D25665` (`id_fields`),
  CONSTRAINT `FK_B305C68158D25665` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_B305C681FDE305E9` FOREIGN KEY (`id_pageType`) REFERENCES `pageType` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_2DEDCC6F5E237E06` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `plugins`
--

DROP TABLE IF EXISTS `plugins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `version` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `refreshTokens`
--

DROP TABLE IF EXISTS `refreshTokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refreshTokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `id_users` int NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `IDX_BFB6788AFA06E4D9` (`id_users`)
) ENGINE=MyISAM AUTO_INCREMENT=2698 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_data_access`
--

DROP TABLE IF EXISTS `role_data_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_data_access` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_roles` int NOT NULL,
  `id_resourceTypes` int NOT NULL,
  `resource_id` int NOT NULL,
  `crud_permissions` smallint unsigned NOT NULL DEFAULT '2',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_resource` (`id_roles`,`id_resourceTypes`,`resource_id`),
  KEY `IDX_role_data_access_roles` (`id_roles`),
  KEY `IDX_role_data_access_resource_types` (`id_resourceTypes`),
  KEY `IDX_role_data_access_resource_id` (`resource_id`),
  KEY `IDX_role_data_access_permissions` (`crud_permissions`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_B63E2EC75E237E06` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles_permissions`
--

DROP TABLE IF EXISTS `roles_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles_permissions` (
  `id_permissions` int NOT NULL,
  `id_roles` int NOT NULL,
  PRIMARY KEY (`id_permissions`,`id_roles`),
  KEY `IDX_CEC2E04358BB6FF7` (`id_roles`),
  CONSTRAINT `FK_CEC2E04335FF0198` FOREIGN KEY (`id_permissions`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_CEC2E04358BB6FF7` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `scheduledJobs`
--

DROP TABLE IF EXISTS `scheduledJobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  CONSTRAINT `FK_3E186B3712C34CFB` FOREIGN KEY (`id_jobTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B3777FD8DE1` FOREIGN KEY (`id_jobStatus`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37DBD5589F` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37E2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37F3854F45` FOREIGN KEY (`id_dataRows`) REFERENCES `dataRows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3E186B37FA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_styles` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `debug` tinyint DEFAULT '0',
  `condition` longtext,
  `data_config` longtext,
  `css` longtext,
  `css_mobile` longtext,
  PRIMARY KEY (`id`),
  KEY `IDX_2B964398906D4F18` (`id_styles`),
  CONSTRAINT `FK_2B964398906D4F18` FOREIGN KEY (`id_styles`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=262 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections_fields_translation`
--

DROP TABLE IF EXISTS `sections_fields_translation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections_fields_translation` (
  `id_sections` int NOT NULL,
  `id_fields` int NOT NULL,
  `id_languages` int NOT NULL,
  `content` longtext NOT NULL,
  `meta` varchar(10000) DEFAULT NULL,
  PRIMARY KEY (`id_sections`,`id_fields`,`id_languages`),
  KEY `IDX_EC5054157B4DAF0D` (`id_sections`),
  KEY `IDX_EC50541558D25665` (`id_fields`),
  KEY `IDX_EC50541520E4EF5E` (`id_languages`),
  CONSTRAINT `FK_EC50541520E4EF5E` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_EC50541558D25665` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_EC5054157B4DAF0D` FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections_hierarchy`
--

DROP TABLE IF EXISTS `sections_hierarchy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections_hierarchy` (
  `parent` int NOT NULL,
  `child` int NOT NULL,
  `position` int DEFAULT NULL,
  PRIMARY KEY (`parent`,`child`),
  KEY `IDX_A6D0AE7C3D8E604F` (`parent`),
  KEY `IDX_A6D0AE7C22B35429` (`child`),
  CONSTRAINT `FK_A6D0AE7C22B35429` FOREIGN KEY (`child`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_A6D0AE7C3D8E604F` FOREIGN KEY (`parent`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections_navigation`
--

DROP TABLE IF EXISTS `sections_navigation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections_navigation` (
  `parent` int NOT NULL,
  `child` int NOT NULL,
  `id_pages` int NOT NULL,
  `position` int NOT NULL,
  PRIMARY KEY (`parent`,`child`),
  KEY `IDX_21BBDC413D8E604F` (`parent`),
  KEY `IDX_21BBDC4122B35429` (`child`),
  KEY `IDX_21BBDC41CEF1A445` (`id_pages`),
  CONSTRAINT `FK_21BBDC4122B35429` FOREIGN KEY (`child`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_21BBDC413D8E604F` FOREIGN KEY (`parent`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_21BBDC41CEF1A445` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `styleGroup`
--

DROP TABLE IF EXISTS `styleGroup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `styleGroup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` longtext,
  `position` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `styleGroup_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `styles`
--

DROP TABLE IF EXISTS `styles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `styles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `id_group` int NOT NULL,
  `description` longtext,
  `can_have_children` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_B65AFAF55E237E06` (`name`),
  KEY `IDX_B65AFAF5834505F5` (`id_group`),
  CONSTRAINT `FK_B65AFAF5834505F5` FOREIGN KEY (`id_group`) REFERENCES `styleGroup` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=607 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `styles_allowed_relationships`
--

DROP TABLE IF EXISTS `styles_allowed_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `styles_allowed_relationships` (
  `id_parent_style` int NOT NULL,
  `id_child_style` int NOT NULL,
  PRIMARY KEY (`id_parent_style`,`id_child_style`),
  KEY `IDX_757F0414DC4D59BB` (`id_parent_style`),
  KEY `IDX_757F041478A9D70E` (`id_child_style`),
  CONSTRAINT `FK_styles_relationships_child` FOREIGN KEY (`id_child_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_styles_relationships_parent` FOREIGN KEY (`id_parent_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Defines allowed parent-child relationships between styles';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `styles_fields`
--

DROP TABLE IF EXISTS `styles_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `styles_fields` (
  `id_styles` int NOT NULL,
  `id_fields` int NOT NULL,
  `default_value` varchar(1000) DEFAULT NULL,
  `help` longtext,
  `disabled` tinyint(1) NOT NULL,
  `hidden` int DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  PRIMARY KEY (`id_styles`,`id_fields`),
  KEY `IDX_4F23ED26906D4F18` (`id_styles`),
  KEY `IDX_4F23ED2658D25665` (`id_fields`),
  CONSTRAINT `FK_4F23ED261DF44B12` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_4F23ED26D54B526F` FOREIGN KEY (`id_styles`) REFERENCES `styles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_time` datetime NOT NULL,
  `id_transactionTypes` int DEFAULT NULL,
  `id_transactionBy` int DEFAULT NULL,
  `id_users` int DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `id_table_name` int DEFAULT NULL,
  `transaction_log` longtext,
  PRIMARY KEY (`id`),
  KEY `IDX_EAA81A4CC41DBD5F` (`id_transactionTypes`),
  KEY `IDX_EAA81A4CFC2E5563` (`id_transactionBy`),
  KEY `IDX_EAA81A4CFA06E4D9` (`id_users`),
  CONSTRAINT `FK_EAA81A4CC41DBD5F` FOREIGN KEY (`id_transactionTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_EAA81A4CFA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_EAA81A4CFC2E5563` FOREIGN KEY (`id_transactionBy`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2596 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_activity`
--

DROP TABLE IF EXISTS `user_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_users` int NOT NULL,
  `url` varchar(200) NOT NULL,
  `timestamp` datetime NOT NULL,
  `id_type` int NOT NULL,
  `exec_time` decimal(10,8) DEFAULT NULL,
  `keyword` varchar(100) DEFAULT NULL,
  `params` varchar(1000) DEFAULT NULL,
  `mobile` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_4CF9ED5AFA06E4D9` (`id_users`),
  KEY `IDX_4CF9ED5A7FE4B2B` (`id_type`),
  CONSTRAINT `FK_4CF9ED5A7FE4B2B` FOREIGN KEY (`id_type`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_4CF9ED5AFA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `blocked` tinyint(1) NOT NULL DEFAULT '0',
  `id_status` int DEFAULT '1',
  `intern` tinyint(1) NOT NULL DEFAULT '0',
  `token` varchar(32) DEFAULT NULL,
  `id_languages` int DEFAULT NULL,
  `is_reminded` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` date DEFAULT NULL,
  `last_url` varchar(100) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `device_token` varchar(200) DEFAULT NULL,
  `security_questions` varchar(1000) DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `id_userTypes` int NOT NULL DEFAULT '72',
  `id_timezones` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_1483A5E9E7927C74` (`email`),
  UNIQUE KEY `UNIQ_1483A5E924A232CF` (`user_name`),
  KEY `IDX_1483A5E93F6026C1` (`id_userTypes`),
  KEY `IDX_1483A5E95D37D0F1` (`id_status`),
  KEY `IDX_1483A5E920E4EF5E` (`id_languages`),
  KEY `IDX_1483A5E9F5677479` (`id_timezones`),
  CONSTRAINT `FK_1483A5E920E4EF5E` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_1483A5E93F6026C1` FOREIGN KEY (`id_userTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_1483A5E95D37D0F1` FOREIGN KEY (`id_status`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_users_id_timezones` FOREIGN KEY (`id_timezones`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=146 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_2fa_codes`
--

DROP TABLE IF EXISTS `users_2fa_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_2fa_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_users` int NOT NULL,
  `code` varchar(6) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_65A1E404FA06E4D9` (`id_users`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_groups`
--

DROP TABLE IF EXISTS `users_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_groups` (
  `id_users` int NOT NULL,
  `id_groups` int NOT NULL,
  PRIMARY KEY (`id_users`,`id_groups`),
  KEY `IDX_FF8AB7E0FA06E4D9` (`id_users`),
  KEY `IDX_FF8AB7E0D65A8C9D` (`id_groups`),
  CONSTRAINT `FK_FF8AB7E0D65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_FF8AB7E0FA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_roles`
--

DROP TABLE IF EXISTS `users_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_roles` (
  `id_users` int NOT NULL,
  `id_roles` int NOT NULL,
  PRIMARY KEY (`id_users`,`id_roles`),
  KEY `IDX_51498A8EFA06E4D9` (`id_users`),
  KEY `IDX_51498A8E58BB6FF7` (`id_roles`),
  CONSTRAINT `FK_51498A8E58BB6FF7` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_51498A8EFA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `validation_codes`
--

DROP TABLE IF EXISTS `validation_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `validation_codes` (
  `code` varchar(16) NOT NULL,
  `id_users` int DEFAULT NULL,
  `created` datetime NOT NULL,
  `consumed` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `id_groups` int DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `IDX_DBEC45EFA06E4D9` (`id_users`),
  KEY `IDX_DBEC45ED65A8C9D` (`id_groups`),
  CONSTRAINT `FK_DBEC45ED65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_DBEC45EFA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `version`
--

DROP TABLE IF EXISTS `version`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `version` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `view_datatables`
--

DROP TABLE IF EXISTS `view_datatables`;
/*!50001 DROP VIEW IF EXISTS `view_datatables`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_datatables` AS SELECT 
 1 AS `id`,
 1 AS `name_id`,
 1 AS `name`,
 1 AS `timestamp`,
 1 AS `value`,
 1 AS `text`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `view_fields`
--

DROP TABLE IF EXISTS `view_fields`;
/*!50001 DROP VIEW IF EXISTS `view_fields`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_fields` AS SELECT 
 1 AS `field_id`,
 1 AS `field_name`,
 1 AS `display`,
 1 AS `field_type_id`,
 1 AS `field_type`,
 1 AS `position`,
 1 AS `config`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `view_style_fields`
--

DROP TABLE IF EXISTS `view_style_fields`;
/*!50001 DROP VIEW IF EXISTS `view_style_fields`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_style_fields` AS SELECT 
 1 AS `style_id`,
 1 AS `style_name`,
 1 AS `style_group`,
 1 AS `field_id`,
 1 AS `field_name`,
 1 AS `field_type`,
 1 AS `config`,
 1 AS `display`,
 1 AS `position`,
 1 AS `default_value`,
 1 AS `help`,
 1 AS `disabled`,
 1 AS `hidden`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `view_styles`
--

DROP TABLE IF EXISTS `view_styles`;
/*!50001 DROP VIEW IF EXISTS `view_styles`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_styles` AS SELECT 
 1 AS `style_id`,
 1 AS `style_name`,
 1 AS `style_description`,
 1 AS `style_group_id`,
 1 AS `style_group`,
 1 AS `style_group_description`,
 1 AS `style_group_position`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `view_users`
--

DROP TABLE IF EXISTS `view_users`;
/*!50001 DROP VIEW IF EXISTS `view_users`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_users` AS SELECT 
 1 AS `id`,
 1 AS `email`,
 1 AS `name`,
 1 AS `last_login`,
 1 AS `status`,
 1 AS `description`,
 1 AS `blocked`,
 1 AS `code`,
 1 AS `groups`,
 1 AS `user_activity`,
 1 AS `ac`,
 1 AS `intern`,
 1 AS `id_userTypes`,
 1 AS `user_type_code`,
 1 AS `user_type`*/;
SET character_set_client = @saved_cs_client;

--
-- Dumping events for database 'sym_test'
--

--
-- Dumping routines for database 'sym_test'
--
/*!50003 DROP FUNCTION IF EXISTS `get_field_id` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `get_field_id`(field varchar(100)) RETURNS int
BEGIN 
	DECLARE field_id INT;    
	SELECT id INTO field_id
	FROM fields
	WHERE name = field COLLATE utf8_unicode_ci;
    RETURN field_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `get_field_type_id` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `get_field_type_id`(field_type varchar(100)) RETURNS int
BEGIN 
	DECLARE field_type_id INT;    
	SELECT id INTO field_type_id
	FROM fieldType
	WHERE name = field_type COLLATE utf8_unicode_ci;
    RETURN field_type_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `get_style_group_id` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `get_style_group_id`(style_group varchar(100)) RETURNS int
BEGIN 
	DECLARE style_group_id INT;    
	SELECT id INTO style_group_id
	FROM styleGroup
	WHERE name = style_group COLLATE utf8_unicode_ci;
    RETURN style_group_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `get_style_id` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `get_style_id`(style varchar(100)) RETURNS int
BEGIN 
	DECLARE style_id INT;    
	SELECT id INTO style_id
	FROM styles
	WHERE name = style COLLATE utf8_unicode_ci;
    RETURN style_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `add_foreign_key` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_foreign_key`(param_table VARCHAR(100), fk_name VARCHAR(100), fk_column VARCHAR(100), fk_references VARCHAR(200))
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
        CONCAT('ALTER TABLE ', param_table, ' ADD CONSTRAINT ', fk_name, ' FOREIGN KEY (', fk_column, ') REFERENCES ', fk_references, ' ON DELETE CASCADE;')
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `add_index` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_index`(
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `add_primary_key` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_primary_key`(
  IN `param_table`   VARCHAR(100),
  IN `param_columns` VARCHAR(500)  -- e.g. 'col1, col2'
)
BEGIN
  DECLARE cnt INT DEFAULT 0;

  -- Check if a PRIMARY KEY already exists on the table
  SELECT COUNT(*) INTO cnt
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE table_schema    = DATABASE()
     AND table_name      = param_table
     AND constraint_type = 'PRIMARY KEY';

  -- Build the appropriate statement
  IF cnt = 0 THEN
    SET @sqlstmt = CONCAT(
      'ALTER TABLE `', param_table,
      '` ADD PRIMARY KEY (', param_columns, ');'
    );
  ELSE
    SET @sqlstmt = "SELECT 'Primary key already exists on table.'";
  END IF;

  -- Execute it
  PREPARE st FROM @sqlstmt;
  EXECUTE st;
  DEALLOCATE PREPARE st;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `add_table_column` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_table_column`(
    IN param_table VARCHAR(100), 
    IN param_column VARCHAR(100), 
    IN param_column_type VARCHAR(500)
)
BEGIN
    SET @sqlstmt = (
        SELECT IF(
            (
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS
                WHERE `table_schema` = DATABASE()
                AND `table_name` = param_table
                AND `COLUMN_NAME` = param_column 
            ) > 0,
            "SELECT 'Column already exists in the table'",
            CONCAT('ALTER TABLE `', param_table, '` ADD COLUMN `', param_column, '` ', param_column_type, ';')
        )
    );

    PREPARE st FROM @sqlstmt;
    EXECUTE st;
    DEALLOCATE PREPARE st;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `add_unique_key` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_unique_key`(param_table VARCHAR(100), param_index VARCHAR(100), param_column VARCHAR(100))
BEGIN
    IF NOT EXISTS 
	(
		SELECT NULL 
		FROM information_schema.STATISTICS
		WHERE `table_schema` = DATABASE()
		AND `table_name` = param_table
		AND `index_name` = param_index 
	) THEN    
		SET @sqlstmt = CONCAT('ALTER TABLE ', param_table, ' ADD UNIQUE KEY ', param_index, ' (', param_column, ');');
		PREPARE st FROM @sqlstmt;
        EXECUTE st;
        DEALLOCATE PREPARE st;	
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `drop_foreign_key` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `drop_foreign_key`(param_table VARCHAR(100), fk_name VARCHAR(100))
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
        CONCAT('ALTER TABLE `', param_table, '` DROP FOREIGN KEY ', fk_name, ' ;')
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `drop_index` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `drop_index`(param_table VARCHAR(100), param_index_name VARCHAR(100))
BEGIN	
    SET @sqlstmt = (SELECT IF(
		(
			SELECT COUNT(*)
            FROM information_schema.STATISTICS 
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
            AND `index_name` = param_index_name
		) > 0,        
        CONCAT('ALTER TABLE `', param_table, '` DROP INDEX ', param_index_name),
        "SELECT 'The index does not exists in the table'"
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `drop_table_column` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `drop_table_column`(param_table VARCHAR(100), param_column VARCHAR(100))
BEGIN	
    SET @sqlstmt = (SELECT IF(
		(
			SELECT COUNT(*) 
			FROM information_schema.COLUMNS
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
			AND `COLUMN_NAME` = param_column 
		) = 0,
        "SELECT 'Column does not exist'",
        CONCAT('ALTER TABLE `', param_table, '` DROP COLUMN `', param_column, '` ;')
    ));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_all_languages` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_dataTable_with_user_group_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_page_fields` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_page_fields`( page_id INT, language_id INT, default_language_id INT, filter_param VARCHAR(1000), order_param VARCHAR(1000))
    READS SQL DATA
    DETERMINISTIC
BEGIN  
	-- page_id -1 returns all pages
    SET @@group_concat_max_len = 32000000;
	SELECT get_page_fields_helper(page_id, language_id, default_language_id) INTO @sql;	
	
    IF (@sql is null) THEN	
        SELECT * FROM pages WHERE 1=2;
    ELSE 
		BEGIN
		SET @sql = CONCAT(
			'select p.id, p.keyword, p.url, p.protocol, p.id_actions, "select" AS access_level, p.id_navigation_section, p.parent, p.is_headless, p.nav_position, p.footer_position, p.id_type, p.id_pageAccessTypes, a.name AS `action`, ', 
			@sql, 
			'FROM pages p
            LEFT JOIN actions AS a ON a.id = p.id_actions
			LEFT JOIN pageType_fields AS ptf ON ptf.id_pageType = p.id_type 
			LEFT JOIN fields AS f ON f.id = ptf.id_fields
			WHERE (p.id = ', page_id, ' OR -1 = ', page_id, ')
            GROUP BY p.id, p.keyword, p.url, p.protocol, p.id_actions, p.id_navigation_section, p.parent, p.is_headless, p.nav_position, p.footer_position, p.id_type, p.id_pageAccessTypes, a.name HAVING 1 ', filter_param
        );
        
        IF (order_param <> '') THEN	        
			SET @sql = concat(
				'SELECT * FROM (',
				@sql,
				') AS t ', order_param
			);
		END IF;

		PREPARE stmt FROM @sql;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;
        end;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_page_sections_hierarchical` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_page_sections_hierarchical`(IN page_id INT)
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
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_sections_fields` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_sections_fields`( section_id INT, language_id INT, gender_id INT, filter_param VARCHAR(1000), order_param VARCHAR(1000))
    READS SQL DATA
    DETERMINISTIC
BEGIN  
	-- section_id -1 returns all sections
    SET @@group_concat_max_len = 32000000;
	SELECT get_sections_fields_helper(section_id, language_id, gender_id) INTO @sql;	
	
    IF (@sql is null) THEN	
        SELECT * FROM sections WHERE 1=2;
    ELSE 
		BEGIN
		SET @sql = CONCAT(
			'SELECT s.id AS section_id, s.name AS section_name, st.id AS style_id, st.name AS style_name, ', 
			@sql, 
			'FROM sections s
            INNER JOIN styles st ON (s.id_styles = st.id)
			LEFT JOIN sections_fields_translation AS sft ON sft.id_sections = s.id   
			LEFT JOIN fields AS f ON sft.id_fields = f.id
			WHERE (s.id = ', section_id, ' OR -1 = ', section_id, ') AND ( IFNULL(id_languages, 1) = 1 OR id_languages=', language_id, ') 
            GROUP BY s.id, s.name, st.id, st.name HAVING 1 ', filter_param
        );
        
        IF (order_param <> '') THEN	        
			SET @sql = concat(
				'SELECT * FROM (',
				@sql,
				') AS t ', order_param
			);
		END IF;

		PREPARE stmt FROM @sql;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;
        end;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `get_user_acl` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_user_acl`(
    IN param_user_id INT,
    IN param_page_id INT  -- -1 means "all pages"
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
        -- 1) Group-based ACL
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

        -- 3) Open-access pages (only all if param_page_id = -1, or just that page if it's open)
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
        id_pages,
        keyword,
        url,      
        parent,
        is_headless,
        nav_position,
        footer_position,     
        id_type,   
        is_system,
        id_pageAccessTypes;

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `rename_index` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `rename_index`(
  IN param_table VARCHAR(100),
  IN old_index_name VARCHAR(100),
  IN new_index_name VARCHAR(100)
)
BEGIN
  DECLARE old_exists INT DEFAULT 0;
  DECLARE new_exists INT DEFAULT 0;

  -- does old index exist?
  SELECT COUNT(*) INTO old_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = param_table
    AND INDEX_NAME   = old_index_name;

  -- does new index already exist?
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
    SELECT CONCAT('Renamed `', param_table, '`.`', old_index_name, '` -> `', new_index_name, '`') AS msg;
  END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `rename_table` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `rename_table`(param_old_table_name VARCHAR(100), param_new_table_name VARCHAR(100))
BEGIN	
	DECLARE tableExists INT;
	SELECT COUNT(*) 
			INTO tableExists
			FROM information_schema.COLUMNS
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_old_table_name; 
	SET @sqlstmt = (SELECT IF(
		tableExists > 0,        
		CONCAT('RENAME TABLE ', param_old_table_name, ' TO ', param_new_table_name),
		"SELECT 'Table does not exists in the table'"
	));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `rename_table_column` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `rename_table_column`(param_table VARCHAR(100), param_old_column_name VARCHAR(100), param_new_column_name VARCHAR(100))
BEGIN	
	DECLARE columnExists INT;
	DECLARE columnType VARCHAR(255);
	SELECT COUNT(*), COLUMN_TYPE 
			INTO columnExists, columnType
			FROM information_schema.COLUMNS
			WHERE `table_schema` = DATABASE()
			AND `table_name` = param_table
			AND `COLUMN_NAME` = param_old_column_name; 
	SET @sqlstmt = (SELECT IF(
		columnExists > 0,        
		CONCAT('ALTER TABLE ', param_table, ' CHANGE COLUMN ', param_old_column_name, ' ', param_new_column_name, ' ', columnType, ';'),
		"SELECT 'Column does not exists in the table'"
	));
	PREPARE st FROM @sqlstmt;
	EXECUTE st;
	DEALLOCATE PREPARE st;	
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Final view structure for view `view_datatables`
--

/*!50001 DROP VIEW IF EXISTS `view_datatables`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_datatables` AS select `datatables`.`id` AS `id`,`datatables`.`name` AS `name_id`,(case when (ifnull(`datatables`.`displayName`,'') = '') then `datatables`.`name` else `datatables`.`displayName` end) AS `name`,`datatables`.`timestamp` AS `timestamp`,`datatables`.`id` AS `value`,(case when (ifnull(`datatables`.`displayName`,'') = '') then `datatables`.`name` else `datatables`.`displayName` end) AS `text` from `datatables` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `view_fields`
--

/*!50001 DROP VIEW IF EXISTS `view_fields`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_fields` AS select `f`.`id` AS `field_id`,`f`.`name` AS `field_name`,`f`.`display` AS `display`,`ft`.`id` AS `field_type_id`,`ft`.`name` AS `field_type`,`ft`.`position` AS `position`,`f`.`config` AS `config` from (`fields` `f` left join `fieldtype` `ft` on((`f`.`id_type` = `ft`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `view_style_fields`
--

/*!50001 DROP VIEW IF EXISTS `view_style_fields`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_style_fields` AS select `s`.`style_id` AS `style_id`,`s`.`style_name` AS `style_name`,`s`.`style_group` AS `style_group`,`f`.`field_id` AS `field_id`,`f`.`field_name` AS `field_name`,`f`.`field_type` AS `field_type`,`f`.`config` AS `config`,`f`.`display` AS `display`,`f`.`position` AS `position`,`sf`.`default_value` AS `default_value`,`sf`.`help` AS `help`,`sf`.`disabled` AS `disabled`,`sf`.`hidden` AS `hidden` from ((`view_styles` `s` left join `styles_fields` `sf` on((`s`.`style_id` = `sf`.`id_styles`))) left join `view_fields` `f` on((`f`.`field_id` = `sf`.`id_fields`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `view_styles`
--

/*!50001 DROP VIEW IF EXISTS `view_styles`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_styles` AS select cast(`s`.`id` as unsigned) AS `style_id`,`s`.`name` AS `style_name`,`s`.`description` AS `style_description`,cast(`sg`.`id` as unsigned) AS `style_group_id`,`sg`.`name` AS `style_group`,`sg`.`description` AS `style_group_description`,`sg`.`position` AS `style_group_position` from (`styles` `s` left join `stylegroup` `sg` on((`s`.`id_group` = `sg`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `view_users`
--

/*!50001 DROP VIEW IF EXISTS `view_users`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_users` AS select `u`.`id` AS `id`,`u`.`email` AS `email`,`u`.`name` AS `name`,ifnull(concat(`u`.`last_login`,' (',(to_days(now()) - to_days(`u`.`last_login`)),' days ago)'),'never') AS `last_login`,`usl`.`lookup_value` AS `status`,`usl`.`lookup_description` AS `description`,`u`.`blocked` AS `blocked`,(case when (`u`.`name` = 'admin') then 'admin' when (`u`.`name` = 'tpf') then 'tpf' else ifnull(`vc`.`code`,'-') end) AS `code`,group_concat(distinct `g`.`name` separator '; ') AS `groups`,`ua`.`activity_count` AS `user_activity`,`ua`.`distinct_url_count` AS `ac`,`u`.`intern` AS `intern`,`u`.`id_userTypes` AS `id_userTypes`,`lut`.`lookup_code` AS `user_type_code`,`lut`.`lookup_value` AS `user_type` from ((((((`users` `u` left join `lookups` `usl` on(((`usl`.`id` = `u`.`id_status`) and (`usl`.`type_code` = 'userStatus')))) left join `users_groups` `ug` on((`ug`.`id_users` = `u`.`id`))) left join `groups` `g` on((`g`.`id` = `ug`.`id_groups`))) left join `validation_codes` `vc` on((`u`.`id` = `vc`.`id_users`))) join `lookups` `lut` on((`lut`.`id` = `u`.`id_userTypes`))) left join (select `ua`.`id_users` AS `id_users`,count(0) AS `activity_count`,count(distinct (case when (`ua`.`id_type` = 1) then `ua`.`url` end)) AS `distinct_url_count` from `user_activity` `ua` group by `ua`.`id_users`) `ua` on((`ua`.`id_users` = `u`.`id`))) where ((`u`.`intern` <> 1) and (`u`.`id_status` > 0)) group by `u`.`id`,`u`.`email`,`u`.`name`,`u`.`last_login`,`usl`.`lookup_value`,`usl`.`lookup_description`,`u`.`blocked`,`vc`.`code`,`ua`.`activity_count`,`ua`.`distinct_url_count`,`u`.`intern`,`u`.`id_userTypes`,`lut`.`lookup_code`,`lut`.`lookup_value` order by `u`.`email` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-11-27 13:39:39
