-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: sym
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
-- Dumping data for table `acl_groups`
--

LOCK TABLES `acl_groups` WRITE;
/*!40000 ALTER TABLE `acl_groups` DISABLE KEYS */;
INSERT INTO `acl_groups` VALUES (1,1,1,1,1,1),(1,2,1,1,1,1),(1,3,1,0,1,0),(1,4,1,1,1,1),(1,5,1,0,1,0),(1,6,1,1,1,1),(1,7,1,1,1,1),(1,8,1,1,1,1),(1,30,1,1,1,1),(1,31,1,1,1,1),(1,32,1,1,1,1),(1,33,1,1,1,1),(1,35,1,1,1,1),(1,84,1,0,1,0),(1,85,1,0,1,0),(1,86,1,0,1,0),(1,87,1,0,1,0),(2,1,1,0,0,0),(2,2,1,0,0,0),(2,3,1,0,0,0),(2,4,1,0,0,0),(2,5,1,0,0,0),(2,6,1,0,0,0),(2,7,1,0,0,0),(2,8,1,0,0,0),(2,30,1,0,0,0),(2,31,1,0,0,0),(2,32,1,0,0,0),(2,33,1,0,0,0),(2,35,1,0,0,0),(3,1,1,0,0,0),(3,2,1,0,0,0),(3,3,1,0,0,0),(3,4,1,0,0,0),(3,5,1,0,0,0),(3,6,1,0,0,0),(3,7,1,0,0,0),(3,8,1,0,0,0),(3,30,1,0,0,0),(3,31,1,0,0,0),(3,32,1,0,0,0),(3,33,1,0,0,0),(3,35,1,0,0,0);
/*!40000 ALTER TABLE `acl_groups` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `action_translations`
--

LOCK TABLES `action_translations` WRITE;
/*!40000 ALTER TABLE `action_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `action_translations` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `actions`
--

LOCK TABLES `actions` WRITE;
/*!40000 ALTER TABLE `actions` DISABLE KEYS */;
/*!40000 ALTER TABLE `actions` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_routes`
--

LOCK TABLES `api_routes` WRITE;
/*!40000 ALTER TABLE `api_routes` DISABLE KEYS */;
INSERT INTO `api_routes` VALUES (1,'auth_login','v1','/auth/login','App\\Controller\\Api\\V1\\Auth\\AuthController::login','POST',NULL,NULL),(2,'auth_2fa_verify','v1','/auth/two-factor-verify','App\\Controller\\Api\\V1\\Auth\\AuthController::twoFactorVerify','POST',NULL,NULL),(3,'auth_refresh_token','v1','/auth/refresh-token','App\\Controller\\Api\\V1\\Auth\\AuthController::refreshToken','POST',NULL,NULL),(4,'auth_logout','v1','/auth/logout','App\\Controller\\Api\\V1\\Auth\\AuthController::logout','POST',NULL,NULL),(5,'auth_set_language','v1','/auth/set-language','App\\Controller\\Api\\V1\\Auth\\AuthController::setUserLanguage','POST',NULL,NULL),(6,'user_validate_token','v1','/validate/{user_id}/{token}','App\\Controller\\Api\\V1\\Auth\\UserValidationController::validateToken','GET','{\"token\": \"[a-f0-9]{32}\", \"user_id\": \"[0-9]+\"}',NULL),(7,'user_complete_validation','v1','/validate/{user_id}/{token}/complete','App\\Controller\\Api\\V1\\Auth\\UserValidationController::completeValidation','POST','{\"token\": \"[a-f0-9]{32}\", \"user_id\": \"[0-9]+\"}',NULL),(8,'admin_lookups','v1','/admin/lookups','App\\Controller\\Api\\V1\\Admin\\Common\\LookupController::getAllLookups','GET',NULL,NULL),(9,'admin_page_keywords','v1','/admin/page-keywords','App\\Controller\\Api\\V1\\Admin\\Common\\LookupController::getPageKeywords','GET',NULL,NULL),(10,'admin_pages_get_all','v1','/admin/pages','App\\Controller\\Api\\V1\\Admin\\AdminPageController::getPages','GET',NULL,NULL),(11,'admin_pages_get_all_with_language','v1','/admin/pages/language/{language_id}','App\\Controller\\Api\\V1\\Admin\\AdminPageController::getPages','GET','{\"language_id\": \"[0-9]+\"}',NULL),(12,'admin_pages_get_one','v1','/admin/pages/{page_id}','App\\Controller\\Api\\V1\\Admin\\AdminPageController::getPage','GET','{\"page_id\": \"[0-9]+\"}',NULL),(13,'admin_pages_create','v1','/admin/pages','App\\Controller\\Api\\V1\\Admin\\AdminPageController::createPage','POST',NULL,'{\"url\": {\"in\": \"body\", \"required\": false}, \"parent\": {\"in\": \"body\", \"required\": false}, \"keyword\": {\"in\": \"body\", \"required\": true}, \"headless\": {\"in\": \"body\", \"required\": false}, \"openAccess\": {\"in\": \"body\", \"required\": false}, \"navPosition\": {\"in\": \"body\", \"required\": false}, \"footerPosition\": {\"in\": \"body\", \"required\": false}, \"pageAccessTypeCode\": {\"in\": \"body\", \"required\": true}}'),(14,'admin_pages_update','v1','/admin/pages/{page_id}','App\\Controller\\Api\\V1\\Admin\\AdminPageController::updatePage','PUT','{\"page_id\": \"[0-9]+\"}','{\"fields\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}, \"pageData\": {\"in\": \"body\", \"type\": \"object\", \"required\": true}}'),(15,'admin_pages_delete','v1','/admin/pages/{page_id}','App\\Controller\\Api\\V1\\Admin\\AdminPageController::deletePage','DELETE','{\"page_id\": \"[0-9]+\"}',NULL),(16,'admin_pages_sections_get','v1','/admin/pages/{page_id}/sections','App\\Controller\\Api\\V1\\Admin\\AdminPageController::getPageSections','GET','{\"page_id\": \"[0-9]+\"}',NULL),(17,'admin_languages_get_all','v1','/admin/languages','App\\Controller\\Api\\V1\\Admin\\AdminLanguageController::getAllLanguages','GET',NULL,NULL),(18,'admin_languages_get_one','v1','/admin/languages/{id}','App\\Controller\\Api\\V1\\Admin\\AdminLanguageController::getLanguage','GET','{\"id\": \"[0-9]+\"}',NULL),(19,'admin_languages_create','v1','/admin/languages','App\\Controller\\Api\\V1\\Admin\\AdminLanguageController::createLanguage','POST',NULL,'{\"locale\": {\"in\": \"body\", \"required\": true}, \"language\": {\"in\": \"body\", \"required\": true}, \"csv_separator\": {\"in\": \"body\", \"required\": false}}'),(20,'admin_languages_update','v1','/admin/languages/{id}','App\\Controller\\Api\\V1\\Admin\\AdminLanguageController::updateLanguage','PUT','{\"id\": \"[0-9]+\"}','{\"locale\": {\"in\": \"body\", \"required\": false}, \"language\": {\"in\": \"body\", \"required\": false}, \"csv_separator\": {\"in\": \"body\", \"required\": false}}'),(21,'admin_languages_delete','v1','/admin/languages/{id}','App\\Controller\\Api\\V1\\Admin\\AdminLanguageController::deleteLanguage','DELETE','{\"id\": \"[0-9]+\"}',NULL),(22,'admin_styles_get','v1','/admin/styles','App\\Controller\\Api\\V1\\Admin\\AdminStyleController::getStyles','GET',NULL,NULL),(23,'admin_pages_create_section','v1','/admin/pages/{page_id}/sections/create','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::createPageSection','POST','{\"page_id\": \"[0-9]+\"}','{\"styleId\": {\"in\": \"body\", \"required\": true}, \"position\": {\"in\": \"body\", \"required\": true}}'),(24,'admin_pages_add_section','v1','/admin/pages/{page_id}/sections','App\\Controller\\Api\\V1\\Admin\\AdminPageController::addSectionToPage','PUT','{\"page_id\": \"[0-9]+\"}','{\"position\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}, \"sectionId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": true}, \"oldParentSectionId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}}'),(25,'admin_pages_remove_section','v1','/admin/pages/{page_id}/sections/{section_id}','App\\Controller\\Api\\V1\\Admin\\AdminPageController::removeSectionFromPage','DELETE','{\"page_id\": \"[0-9]+\", \"section_id\": \"[0-9]+\"}',NULL),(26,'admin_sections_create_child','v1','/admin/pages/{page_id}/sections/{parent_section_id}/sections/create','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::createChildSection','POST','{\"parent_section_id\": \"\\\\d+\"}','{\"styleId\": {\"in\": \"body\", \"required\": true}, \"position\": {\"in\": \"body\", \"required\": true}}'),(27,'admin_sections_add','v1','/admin/pages/{page_id}/sections/{parent_section_id}/sections','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::addSectionToSection','PUT','{\"page_id\": \"[0-9]+\", \"parent_section_id\": \"[0-9]+\"}','{\"position\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}, \"childSectionId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": true}, \"oldParentPageId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}, \"oldParentSectionId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}}'),(28,'admin_sections_remove','v1','/admin/pages/{page_id}/sections/{parent_section_id}/sections/{child_section_id}','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::removeSectionFromSection','DELETE','{\"page_id\": \"[0-9]+\", \"child_section_id\": \"[0-9]+\", \"parent_section_id\": \"[0-9]+\"}',NULL),(29,'admin_sections_update','v1','/admin/pages/{page_id}/sections/{section_id}','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::updateSection','PUT','{\"page_id\": \"[0-9]+\", \"section_id\": \"[0-9]+\"}','{\"sectionId\": {\"in\": \"body\", \"type\": \"integer\", \"required\": true}, \"sectionName\": {\"in\": \"body\", \"type\": \"string\", \"required\": true}, \"contentFields\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}, \"propertyFields\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(30,'admin_sections_get','v1','/admin/pages/{page_id}/sections/{section_id}','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::getSection','GET','{\"page_id\": \"[0-9]+\", \"section_id\": \"[0-9]+\"}',NULL),(31,'admin_sections_get_children_sections','v1','/admin/pages/{page_id}/sections/{parent_section_id}/sections','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::getChildrenSections','GET','{\"page_id\": \"[0-9]+\", \"parent_section_id\": \"[0-9]+\"}',NULL),(32,'admin_sections_export_page','v1','/admin/pages/{page_id}/sections/export','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::exportPageSections','GET','{\"page_id\": \"[0-9]+\"}',NULL),(33,'admin_sections_export_section','v1','/admin/pages/{page_id}/sections/{section_id}/export','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::exportSection','GET','{\"page_id\": \"[0-9]+\", \"section_id\": \"[0-9]+\"}',NULL),(34,'admin_sections_import_to_page','v1','/admin/pages/{page_id}/sections/import','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::importSectionsToPage','POST','{\"page_id\": \"[0-9]+\"}','{\"position\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}, \"sections\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(35,'admin_sections_import_to_section','v1','/admin/pages/{page_id}/sections/{parent_section_id}/import','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::importSectionsToSection','POST','{\"page_id\": \"[0-9]+\", \"parent_section_id\": \"[0-9]+\"}','{\"position\": {\"in\": \"body\", \"type\": \"integer\", \"required\": false}, \"sections\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(36,'admin_sections_restore_from_version','v1','/admin/pages/{page_id}/sections/restore-from-version/{version_id}','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::restoreSectionsFromVersion','POST','{\"page_id\": \"[0-9]+\", \"version_id\": \"[0-9]+\"}',NULL),(37,'pages_get_all','v1','/pages','App\\Controller\\Api\\V1\\Frontend\\PageController::getPages','GET',NULL,NULL),(38,'pages_get_all_with_language','v1','/pages/language/{language_id}','App\\Controller\\Api\\V1\\Frontend\\PageController::getPages','GET','{\"language_id\": \"[0-9]+\"}',NULL),(39,'pages_get_one','v1','/pages/{page_id}','App\\Controller\\Api\\V1\\Frontend\\PageController::getPage','GET','{\"page_id\": \"[0-9]+\"}',NULL),(40,'languages_get_all','v1','/languages','App\\Controller\\Api\\V1\\Frontend\\LanguageController::getAllLanguages','GET',NULL,NULL),(41,'form_submit','v1','/forms/submit','App\\Controller\\Api\\V1\\Frontend\\FormController::submitForm','POST',NULL,'{\"files\": {\"in\": \"form\", \"required\": false, \"description\": \"Uploaded files for file input fields\"}, \"page_id\": {\"in\": \"body\", \"required\": true}, \"form_data\": {\"in\": \"body\", \"required\": true}, \"section_id\": {\"in\": \"body\", \"required\": true}}'),(42,'form_update','v1','/forms/update','App\\Controller\\Api\\V1\\Frontend\\FormController::updateForm','PUT',NULL,'{\"files\": {\"in\": \"form\", \"required\": false, \"description\": \"Uploaded files for file input fields\"}, \"page_id\": {\"in\": \"body\", \"required\": true}, \"form_data\": {\"in\": \"body\", \"required\": true}, \"section_id\": {\"in\": \"body\", \"required\": true}, \"update_based_on\": {\"in\": \"body\", \"required\": false}}'),(43,'form_delete','v1','/forms/delete','App\\Controller\\Api\\V1\\Frontend\\FormController::deleteForm','DELETE',NULL,'{\"page_id\": {\"in\": \"body\", \"required\": true}, \"record_id\": {\"in\": \"body\", \"required\": true}, \"section_id\": {\"in\": \"body\", \"required\": true}}'),(45,'admin_users_get_all_v1','v1','/admin/users','App\\Controller\\Api\\V1\\Admin\\AdminUserController::getUsers','GET',NULL,'{\"page\": {\"in\": \"query\", \"required\": false}, \"sort\": {\"in\": \"query\", \"required\": false}, \"search\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"required\": false}, \"sortDirection\": {\"in\": \"query\", \"required\": false}}'),(46,'admin_users_get_one_v1','v1','/admin/users/{userId}','App\\Controller\\Api\\V1\\Admin\\AdminUserController::getUserById','GET','{\"userId\": \"[0-9]+\"}',NULL),(47,'admin_users_create_v1','v1','/admin/users','App\\Controller\\Api\\V1\\Admin\\AdminUserController::createUser','POST',NULL,'{\"name\": {\"in\": \"body\", \"required\": false}, \"email\": {\"in\": \"body\", \"required\": true}, \"blocked\": {\"in\": \"body\", \"required\": false}, \"password\": {\"in\": \"body\", \"required\": false}, \"role_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}, \"group_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}, \"user_name\": {\"in\": \"body\", \"required\": false}, \"id_languages\": {\"in\": \"body\", \"required\": false}, \"user_type_id\": {\"in\": \"body\", \"required\": false}, \"validation_code\": {\"in\": \"body\", \"required\": true}}'),(48,'admin_users_update_v1','v1','/admin/users/{userId}','App\\Controller\\Api\\V1\\Admin\\AdminUserController::updateUser','PUT','{\"userId\": \"[0-9]+\"}','{\"name\": {\"in\": \"body\", \"required\": false}, \"email\": {\"in\": \"body\", \"required\": false}, \"blocked\": {\"in\": \"body\", \"required\": false}, \"password\": {\"in\": \"body\", \"required\": false}, \"user_name\": {\"in\": \"body\", \"required\": false}, \"id_languages\": {\"in\": \"body\", \"required\": false}, \"user_type_id\": {\"in\": \"body\", \"required\": false}}'),(49,'admin_users_delete_v1','v1','/admin/users/{userId}','App\\Controller\\Api\\V1\\Admin\\AdminUserController::deleteUser','DELETE','{\"userId\": \"[0-9]+\"}',NULL),(50,'admin_users_block_v1','v1','/admin/users/{userId}/block','App\\Controller\\Api\\V1\\Admin\\AdminUserController::toggleUserBlock','PATCH','{\"userId\": \"[0-9]+\"}','{\"blocked\": {\"in\": \"body\", \"required\": true}}'),(51,'admin_users_groups_get_v1','v1','/admin/users/{userId}/groups','App\\Controller\\Api\\V1\\Admin\\AdminUserController::getUserGroups','GET','{\"userId\": \"[0-9]+\"}',NULL),(52,'admin_users_groups_add_v1','v1','/admin/users/{userId}/groups','App\\Controller\\Api\\V1\\Admin\\AdminUserController::addGroupsToUser','POST','{\"userId\": \"[0-9]+\"}','{\"group_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(53,'admin_users_groups_remove_v1','v1','/admin/users/{userId}/groups','App\\Controller\\Api\\V1\\Admin\\AdminUserController::removeGroupsFromUser','DELETE','{\"userId\": \"[0-9]+\"}','{\"group_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(54,'admin_users_roles_get_v1','v1','/admin/users/{userId}/roles','App\\Controller\\Api\\V1\\Admin\\AdminUserController::getUserRoles','GET','{\"userId\": \"[0-9]+\"}',NULL),(55,'admin_users_roles_add_v1','v1','/admin/users/{userId}/roles','App\\Controller\\Api\\V1\\Admin\\AdminUserController::addRolesToUser','POST','{\"userId\": \"[0-9]+\"}','{\"role_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(56,'admin_users_roles_remove_v1','v1','/admin/users/{userId}/roles','App\\Controller\\Api\\V1\\Admin\\AdminUserController::removeRolesFromUser','DELETE','{\"userId\": \"[0-9]+\"}','{\"role_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(57,'admin_users_send_activation_v1','v1','/admin/users/{userId}/send-activation-mail','App\\Controller\\Api\\V1\\Admin\\AdminUserController::sendActivationMail','POST','{\"userId\": \"[0-9]+\"}',NULL),(58,'admin_users_clean_data_v1','v1','/admin/users/{userId}/clean-data','App\\Controller\\Api\\V1\\Admin\\AdminUserController::cleanUserData','POST','{\"userId\": \"[0-9]+\"}',NULL),(59,'admin_users_impersonate_v1','v1','/admin/users/{userId}/impersonate','App\\Controller\\Api\\V1\\Admin\\AdminUserController::impersonateUser','POST','{\"userId\": \"[0-9]+\"}',NULL),(60,'admin_groups_get_all_v1','v1','/admin/groups','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::getGroups','GET',NULL,'{\"page\": {\"in\": \"query\", \"required\": false}, \"sort\": {\"in\": \"query\", \"required\": false}, \"search\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"required\": false}, \"sortDirection\": {\"in\": \"query\", \"required\": false}}'),(61,'admin_groups_get_one_v1','v1','/admin/groups/{groupId}','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::getGroupById','GET','{\"groupId\": \"[0-9]+\"}',NULL),(62,'admin_groups_create_v1','v1','/admin/groups','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::createGroup','POST',NULL,'{\"acls\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}, \"name\": {\"in\": \"body\", \"required\": true}, \"description\": {\"in\": \"body\", \"required\": false}, \"requires_2fa\": {\"in\": \"body\", \"required\": false}, \"id_group_types\": {\"in\": \"body\", \"required\": false}}'),(63,'admin_groups_update_v1','v1','/admin/groups/{groupId}','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::updateGroup','PUT','{\"groupId\": \"[0-9]+\"}','{\"acls\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}, \"name\": {\"in\": \"body\", \"required\": false}, \"description\": {\"in\": \"body\", \"required\": false}, \"requires_2fa\": {\"in\": \"body\", \"required\": false}, \"id_group_types\": {\"in\": \"body\", \"required\": false}}'),(64,'admin_groups_delete_v1','v1','/admin/groups/{groupId}','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::deleteGroup','DELETE','{\"groupId\": \"[0-9]+\"}',NULL),(65,'admin_groups_acls_get_v1','v1','/admin/groups/{groupId}/acls','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::getGroupAcls','GET','{\"groupId\": \"[0-9]+\"}',NULL),(66,'admin_groups_acls_update_v1','v1','/admin/groups/{groupId}/acls','App\\Controller\\Api\\V1\\Admin\\AdminGroupController::updateGroupAcls','PUT','{\"groupId\": \"[0-9]+\"}','{\"acls\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(67,'admin_roles_get_all_v1','v1','/admin/roles','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::getRoles','GET',NULL,'{\"page\": {\"in\": \"query\", \"required\": false}, \"sort\": {\"in\": \"query\", \"required\": false}, \"search\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"required\": false}, \"sortDirection\": {\"in\": \"query\", \"required\": false}}'),(68,'admin_roles_get_one_v1','v1','/admin/roles/{roleId}','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::getRoleById','GET','{\"roleId\": \"[0-9]+\"}',NULL),(69,'admin_roles_create_v1','v1','/admin/roles','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::createRole','POST',NULL,'{\"name\": {\"in\": \"body\", \"required\": true}, \"description\": {\"in\": \"body\", \"required\": false}, \"permission_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}}'),(70,'admin_roles_update_v1','v1','/admin/roles/{roleId}','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::updateRole','PUT','{\"roleId\": \"[0-9]+\"}','{\"name\": {\"in\": \"body\", \"required\": false}, \"description\": {\"in\": \"body\", \"required\": false}, \"permission_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": false}}'),(71,'admin_roles_delete_v1','v1','/admin/roles/{roleId}','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::deleteRole','DELETE','{\"roleId\": \"[0-9]+\"}',NULL),(72,'admin_roles_permissions_get_v1','v1','/admin/roles/{roleId}/permissions','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::getRolePermissions','GET','{\"roleId\": \"[0-9]+\"}',NULL),(73,'admin_roles_permissions_add_v1','v1','/admin/roles/{roleId}/permissions','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::addPermissionsToRole','POST','{\"roleId\": \"[0-9]+\"}','{\"permission_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(74,'admin_roles_permissions_remove_v1','v1','/admin/roles/{roleId}/permissions','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::removePermissionsFromRole','DELETE','{\"roleId\": \"[0-9]+\"}','{\"permission_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(75,'admin_roles_permissions_update_v1','v1','/admin/roles/{roleId}/permissions','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::updateRolePermissions','PUT','{\"roleId\": \"[0-9]+\"}','{\"permission_ids\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(76,'admin_permissions_get_all_v1','v1','/admin/permissions','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::getAllPermissions','GET',NULL,NULL),(77,'admin_api_routes_get_all_v1','v1','/admin/api-routes','App\\Controller\\Api\\V1\\Admin\\AdminRoleController::getApiRoutesWithPermissions','GET',NULL,NULL),(78,'admin_assets_get_all_v1','v1','/admin/assets','App\\Controller\\Api\\V1\\Admin\\AdminAssetController::getAllAssets','GET',NULL,'{\"page\": {\"in\": \"query\", \"required\": false}, \"folder\": {\"in\": \"query\", \"required\": false}, \"search\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"required\": false}}'),(79,'admin_assets_get_one_v1','v1','/admin/assets/{assetId}','App\\Controller\\Api\\V1\\Admin\\AdminAssetController::getAssetById','GET','{\"assetId\": \"[0-9]+\"}',NULL),(80,'admin_assets_create_v1','v1','/admin/assets','App\\Controller\\Api\\V1\\Admin\\AdminAssetController::createAsset','POST',NULL,'{\"file\": {\"in\": \"form\", \"required\": false}, \"files\": {\"in\": \"form\", \"required\": false}, \"folder\": {\"in\": \"form\", \"required\": false}, \"file_name\": {\"in\": \"form\", \"required\": false}, \"overwrite\": {\"in\": \"form\", \"required\": false}, \"file_names\": {\"in\": \"form\", \"required\": false}}'),(81,'admin_assets_delete_v1','v1','/admin/assets/{assetId}','App\\Controller\\Api\\V1\\Admin\\AdminAssetController::deleteAsset','DELETE','{\"assetId\": \"[0-9]+\"}',NULL),(82,'admin_actions_get_all_v1','v1','/admin/actions','App\\Controller\\Api\\V1\\Admin\\AdminActionController::getActions','GET',NULL,'{\"page\": {\"in\": \"query\", \"required\": false}, \"sort\": {\"in\": \"query\", \"required\": false}, \"search\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"required\": false}, \"sortDirection\": {\"in\": \"query\", \"required\": false}}'),(83,'admin_actions_update_v1','v1','/admin/actions/{actionId}','App\\Controller\\Api\\V1\\Admin\\AdminActionController::updateAction','PUT','{\"actionId\": \"[0-9]+\"}','{\"name\": {\"in\": \"body\", \"required\": false}, \"config\": {\"in\": \"body\", \"required\": false}, \"id_dataTables\": {\"in\": \"body\", \"required\": false}, \"id_actionTriggerTypes\": {\"in\": \"body\", \"required\": false}}'),(84,'admin_actions_get_one_v1','v1','/admin/actions/{actionId}','App\\Controller\\Api\\V1\\Admin\\AdminActionController::getActionById','GET','{\"actionId\": \"[0-9]+\"}',NULL),(85,'admin_actions_delete_v1','v1','/admin/actions/{actionId}','App\\Controller\\Api\\V1\\Admin\\AdminActionController::deleteAction','DELETE','{\"actionId\": \"[0-9]+\"}',NULL),(86,'admin_actions_create_v1','v1','/admin/actions','App\\Controller\\Api\\V1\\Admin\\AdminActionController::createAction','POST',NULL,'{\"name\": {\"in\": \"body\", \"required\": true}, \"config\": {\"in\": \"body\", \"required\": false}, \"id_actionTriggerTypes\": {\"in\": \"body\", \"required\": true}}'),(87,'admin_sections_unused_get_v1','v1','/admin/sections/unused','App\\Controller\\Api\\V1\\Admin\\AdminSectionUtilityController::getUnusedSections','GET',NULL,NULL),(88,'admin_sections_ref_containers_get_v1','v1','/admin/sections/ref-containers','App\\Controller\\Api\\V1\\Admin\\AdminSectionUtilityController::getRefContainers','GET',NULL,NULL),(89,'admin_sections_unused_delete_v1','v1','/admin/sections/unused/{section_id}','App\\Controller\\Api\\V1\\Admin\\AdminSectionUtilityController::deleteUnusedSection','DELETE','{\"section_id\": \"[0-9]+\"}',NULL),(90,'admin_sections_unused_delete_all_v1','v1','/admin/sections/unused','App\\Controller\\Api\\V1\\Admin\\AdminSectionUtilityController::deleteAllUnusedSections','DELETE',NULL,NULL),(91,'admin_sections_force_delete_v1','v1','/admin/pages/{page_id}/sections/{section_id}/force-delete','App\\Controller\\Api\\V1\\Admin\\AdminSectionController::forceDeleteSection','DELETE','{\"page_id\": \"[0-9]+\", \"section_id\": \"[0-9]+\"}',NULL),(92,'admin_cache_clear_api_routes_v1','v1','/admin/cache/api-routes/clear','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::clearApiRoutesCache','POST',NULL,NULL),(94,'frontend_css_classes_get_all','v1','/frontend/css-classes','App\\Controller\\CssController::getCssClasses','GET',NULL,NULL),(95,'admin_scheduled_jobs_get_all_v1','v1','/admin/scheduled-jobs','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::getScheduledJobs','GET',NULL,NULL),(96,'admin_scheduled_jobs_get_one_v1','v1','/admin/scheduled-jobs/{jobId}','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::getScheduledJobById','GET','{\"jobId\": \"[0-9]+\"}',NULL),(97,'admin_scheduled_jobs_execute_v1','v1','/admin/scheduled-jobs/{jobId}/execute','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::executeScheduledJob','POST','{\"jobId\": \"[0-9]+\"}',NULL),(98,'admin_scheduled_jobs_cancel_v1','v1','/admin/scheduled-jobs/{jobId}/cancel','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::cancelScheduledJob','POST','{\"jobId\": \"[0-9]+\"}',NULL),(99,'admin_scheduled_jobs_delete_v1','v1','/admin/scheduled-jobs/{jobId}','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::deleteScheduledJob','DELETE','{\"jobId\": \"[0-9]+\"}',NULL),(100,'admin_scheduled_jobs_transactions_v1','v1','/admin/scheduled-jobs/{jobId}/transactions','App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::getJobTransactions','GET','{\"jobId\": \"[0-9]+\"}',NULL),(101,'admin_data_tables_get_v1','v1','/admin/data/tables','App\\Controller\\Api\\V1\\Admin\\AdminDataController::getDataTables','GET',NULL,NULL),(102,'admin_data_get_v1','v1','/admin/data','App\\Controller\\Api\\V1\\Admin\\AdminDataController::getData','GET',NULL,'{\"user_id\": {\"in\": \"query\", \"required\": false}, \"table_name\": {\"in\": \"query\", \"required\": true}, \"exclude_deleted\": {\"in\": \"query\", \"required\": false}}'),(103,'admin_data_record_delete_v1','v1','/admin/data/records/{recordId}','App\\Controller\\Api\\V1\\Admin\\AdminDataController::deleteRecord','DELETE','{\"recordId\": \"[0-9]+\"}','{\"own_entries_only\": {\"in\": \"query\", \"required\": false}}'),(104,'admin_data_table_delete_v1','v1','/admin/data/tables/{tableName}','App\\Controller\\Api\\V1\\Admin\\AdminDataController::deleteDataTable','DELETE','{\"tableName\": \"[A-Za-z0-9_-]+\"}',NULL),(105,'admin_data_table_columns_delete_v1','v1','/admin/data/tables/{tableName}/columns','App\\Controller\\Api\\V1\\Admin\\AdminDataController::deleteColumns','DELETE','{\"tableName\": \"[A-Za-z0-9_-]+\"}','{\"columns\": {\"in\": \"body\", \"type\": \"array\", \"required\": true}}'),(106,'admin_data_table_columns_get_v1','v1','/admin/data/tables/{tableName}/columns','App\\Controller\\Api\\V1\\Admin\\AdminDataController::getColumns','GET','{\"tableName\": \"[A-Za-z0-9_-]+\"}',NULL),(107,'admin_data_table_column_names_get_v1','v1','/admin/data/tables/{tableName}/column-names','App\\Controller\\Api\\V1\\Admin\\AdminDataController::getColumnNames','GET','{\"tableName\": \"[A-Za-z0-9_-]+\"}',NULL),(108,'admin_cache_stats_v1','v1','/admin/cache/stats','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::getCacheStats','GET',NULL,NULL),(109,'admin_cache_clear_all_v1','v1','/admin/cache/clear/all','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::clearAllCaches','POST',NULL,NULL),(110,'admin_cache_clear_category_v1','v1','/admin/cache/clear/category','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::clearCacheCategory','POST',NULL,'{\"category\": {\"in\": \"body\", \"required\": true}}'),(111,'admin_cache_clear_user_v1','v1','/admin/cache/clear/user','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::clearUserCache','POST',NULL,'{\"user_id\": {\"in\": \"body\", \"required\": true}}'),(112,'admin_cache_reset_stats_v1','v1','/admin/cache/stats/reset','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::resetCacheStats','POST',NULL,NULL),(113,'admin_cache_health_v1','v1','/admin/cache/health','App\\Controller\\Api\\V1\\Admin\\AdminCacheController::getCacheHealth','GET',NULL,NULL),(114,'auth_user_data_get_v1','v1','/auth/user-data','App\\Controller\\Api\\V1\\Auth\\UserDataController::getCurrentUserData','GET',NULL,NULL),(115,'auth_user_name_update_v1','v1','/auth/user/name','App\\Controller\\Api\\V1\\Auth\\ProfileController::updateName','PUT',NULL,NULL),(116,'auth_user_timezone_update_v1','v1','/auth/user/timezone','App\\Controller\\Api\\V1\\Auth\\ProfileController::updateTimezone','PUT',NULL,NULL),(117,'auth_user_password_update_v1','v1','/auth/user/password','App\\Controller\\Api\\V1\\Auth\\ProfileController::updatePassword','PUT',NULL,NULL),(118,'auth_user_account_delete_v1','v1','/auth/user/account','App\\Controller\\Api\\V1\\Auth\\ProfileController::deleteAccount','DELETE',NULL,NULL),(119,'admin_actions_translations_get_all_v1','v1','/admin/actions/{actionId}/translations','App\\Controller\\Api\\V1\\Admin\\AdminActionTranslationController::getTranslations','GET','{\"actionId\": \"[0-9]+\"}','{\"language_id\": {\"in\": \"query\", \"required\": false}}'),(120,'admin_page_publish','v1','/admin/pages/{page_id}/versions/publish','App\\Controller\\Api\\V1\\Admin\\PageVersionController::publishPage','POST','{\"page_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}, \"metadata\": {\"in\": \"body\", \"required\": false}, \"version_name\": {\"in\": \"body\", \"required\": false}}'),(121,'admin_page_publish_specific_version','v1','/admin/pages/{page_id}/versions/{version_id}/publish','App\\Controller\\Api\\V1\\Admin\\PageVersionController::publishSpecificVersion','POST','{\"page_id\": \"[0-9]+\", \"version_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}, \"version_id\": {\"in\": \"path\", \"required\": true}}'),(122,'admin_page_unpublish','v1','/admin/pages/{page_id}/versions/unpublish','App\\Controller\\Api\\V1\\Admin\\PageVersionController::unpublishPage','POST','{\"page_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}}'),(123,'admin_page_versions_list','v1','/admin/pages/{page_id}/versions','App\\Controller\\Api\\V1\\Admin\\PageVersionController::listVersions','GET','{\"page_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}}'),(124,'admin_page_version_get','v1','/admin/pages/{page_id}/versions/{version_id}','App\\Controller\\Api\\V1\\Admin\\PageVersionController::getVersion','GET','{\"page_id\": \"[0-9]+\", \"version_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}, \"version_id\": {\"in\": \"path\", \"required\": true}}'),(125,'admin_page_versions_compare','v1','/admin/pages/{page_id}/versions/compare/{version1_id}/{version2_id}','App\\Controller\\Api\\V1\\Admin\\PageVersionController::compareVersions','GET','{\"page_id\": \"[0-9]+\", \"version1_id\": \"[0-9]+\", \"version2_id\": \"[0-9]+\"}','{\"format\": {\"in\": \"query\", \"required\": false}, \"page_id\": {\"in\": \"path\", \"required\": true}, \"version1_id\": {\"in\": \"path\", \"required\": true}, \"version2_id\": {\"in\": \"path\", \"required\": true}}'),(126,'admin_page_versions_compare_draft','v1','/admin/pages/{page_id}/versions/compare-draft/{version_id}','App\\Controller\\Api\\V1\\Admin\\PageVersionController::compareDraftWithVersion','GET','{\"page_id\": \"[0-9]+\", \"version_id\": \"[0-9]+\"}','{\"format\": {\"in\": \"query\", \"required\": false}, \"page_id\": {\"in\": \"path\", \"required\": true}, \"version_id\": {\"in\": \"path\", \"required\": true}}'),(127,'admin_page_versions_has_changes','v1','/admin/pages/{page_id}/versions/has-changes','App\\Controller\\Api\\V1\\Admin\\PageVersionController::hasUnpublishedChanges','GET','{\"page_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}}'),(128,'admin_page_version_delete','v1','/admin/pages/{page_id}/versions/{version_id}','App\\Controller\\Api\\V1\\Admin\\PageVersionController::deleteVersion','DELETE','{\"page_id\": \"[0-9]+\", \"version_id\": \"[0-9]+\"}','{\"page_id\": {\"in\": \"path\", \"required\": true}, \"version_id\": {\"in\": \"path\", \"required\": true}}'),(129,'admin_audit_data_access_list','v1','/admin/audit/data-access','App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessLogs','GET','{}','{\"page\": {\"in\": \"query\", \"default\": 1, \"required\": false}, \"action\": {\"in\": \"query\", \"required\": false}, \"date_to\": {\"in\": \"query\", \"required\": false}, \"user_id\": {\"in\": \"query\", \"required\": false}, \"pageSize\": {\"in\": \"query\", \"default\": 20, \"required\": false}, \"date_from\": {\"in\": \"query\", \"required\": false}, \"resource_type\": {\"in\": \"query\", \"required\": false}, \"permission_result\": {\"in\": \"query\", \"required\": false}}'),(130,'admin_audit_data_access_detail','v1','/admin/audit/data-access/{id}','App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessLog','GET','{\"id\": \"[0-9]+\"}','{\"id\": {\"in\": \"path\", \"required\": true}}'),(131,'admin_audit_data_access_stats','v1','/admin/audit/data-access/stats','App\\Controller\\Api\\V1\\Admin\\AdminAuditController::getDataAccessStats','GET','{}','{}'),(132,'admin_data_access_roles_list','v1','/admin/data-access/roles','App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getRolesWithPermissions','GET','{}','{}'),(133,'admin_data_access_role_permission_set','v1','/admin/data-access/roles/{roleId}/permissions','App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::setRolePermissions','POST','{\"roleId\": \"[0-9]+\"}','{\"roleId\": {\"in\": \"path\", \"required\": true}, \"permissions\": {\"in\": \"body\", \"required\": true}}'),(134,'admin_data_access_role_effective_permissions','v1','/admin/data-access/roles/{roleId}/effective-permissions','App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getRoleEffectivePermissions','GET','{\"roleId\": \"[0-9]+\"}','{\"roleId\": {\"in\": \"path\", \"required\": true}}');
/*!40000 ALTER TABLE `api_routes` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `api_routes_permissions`
--

LOCK TABLES `api_routes_permissions` WRITE;
/*!40000 ALTER TABLE `api_routes_permissions` DISABLE KEYS */;
INSERT INTO `api_routes_permissions` VALUES (8,1),(9,1),(10,2),(11,2),(12,2),(13,3),(14,4),(15,5),(16,2),(17,8),(18,8),(19,8),(20,8),(21,8),(22,1),(23,4),(24,4),(25,4),(26,4),(27,4),(28,4),(29,4),(30,2),(31,2),(32,7),(33,7),(34,7),(35,7),(36,7),(45,9),(46,9),(47,10),(48,11),(49,12),(50,13),(51,9),(52,11),(53,11),(54,9),(55,11),(56,11),(57,11),(58,11),(59,15),(60,16),(61,16),(62,17),(63,18),(64,19),(65,16),(66,20),(67,21),(68,21),(69,22),(70,23),(71,24),(72,21),(73,25),(74,25),(75,25),(76,26),(77,26),(78,29),(79,29),(80,30),(81,31),(82,32),(83,33),(84,32),(85,34),(86,33),(87,4),(88,4),(89,35),(90,35),(91,5),(92,45),(95,37),(96,37),(97,38),(98,39),(99,40),(100,37),(101,41),(102,41),(103,42),(104,42),(105,43),(106,41),(107,41),(108,44),(109,45),(110,45),(111,45),(112,46),(113,44),(119,47),(120,50),(121,50),(122,50),(123,48),(124,48),(125,53),(126,53),(127,48),(128,52),(129,54),(130,54),(131,54),(132,21),(132,23),(133,21),(133,23),(134,21),(134,23);
/*!40000 ALTER TABLE `api_routes_permissions` ENABLE KEYS */;
UNLOCK TABLES;

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
  `request_time` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `response_time` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `duration_ms` int NOT NULL,
  `request_params` longtext COLLATE utf8mb4_unicode_ci,
  `request_headers` longtext COLLATE utf8mb4_unicode_ci,
  `response_data` longtext COLLATE utf8mb4_unicode_ci,
  `error_message` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `apiRequestLogs`
--

LOCK TABLES `apiRequestLogs` WRITE;
/*!40000 ALTER TABLE `apiRequestLogs` DISABLE KEYS */;
INSERT INTO `apiRequestLogs` VALUES (1,'pages_get_all_with_language_v1','/cms-api/v1/pages/language/1','GET',200,NULL,'::1','2026-04-07 12:30:32','2026-04-07 12:30:33',230,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":false,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:33+02:00\"},\"data\":[]}',NULL),(2,'languages_get_all_v1','/cms-api/v1/languages','GET',200,NULL,'::1','2026-04-07 12:30:40','2026-04-07 12:30:40',149,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":false,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:40+02:00\"},\"data\":[{\"id\":2,\"locale\":\"de-CH\",\"language\":\"Deutsch (Schweiz)\",\"csvSeparator\":\",\"},{\"id\":3,\"locale\":\"en-GB\",\"language\":\"English (GB)\",\"csvSeparator\":\",\"}]}',NULL),(3,'pages_get_all_with_language_v1','/cms-api/v1/pages/language/2','GET',200,NULL,'::1','2026-04-07 12:30:40','2026-04-07 12:30:40',52,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":false,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:40+02:00\"},\"data\":[]}',NULL),(4,'pages_get_all_with_language_v1','/cms-api/v1/pages/language/1','GET',200,NULL,'::1','2026-04-07 12:30:40','2026-04-07 12:30:40',36,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Chromium\\\";v=\\\"146\\\", \\\"Not-A.Brand\\\";v=\\\"24\\\", \\\"Google Chrome\\\";v=\\\"146\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/146.0.0.0 Safari\\/537.36\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":false,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:40+02:00\"},\"data\":[]}',NULL),(5,'auth_login_v1','/cms-api/v1/auth/login','POST',200,NULL,'::1','2026-04-07 12:30:43','2026-04-07 12:30:43',209,'{\"json\":{\"email\":\"stefan.kodzhabashev@unibe.ch\",\"password\":\"********\"}}','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"content-length\":[\"62\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"sec-ch-ua-mobile\":[\"?0\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"content-type\":[\"application\\/json\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":true,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:43+02:00\"},\"data\":{\"access_token\":\"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE3NzU1NjUwNDMsImV4cCI6MTc3NTU2ODY0Mywicm9sZXMiOlsiUk9MRV9BRE1JTiJdLCJpZF91c2VycyI6NSwidXNlcm5hbWUiOiJzdGVmYW4ua29kemhhYmFzaGV2QHVuaWJlLmNoIn0.W7zwEwm_Hcl-TWJD0OhHqJRR0ph7NhbSZlN29sGyfEicVjDbROP3-wmkOCEop8VzHX7-uGzvN_eJbwRG3aP_p8O7NPYFN0QcZwBPGH9VFEjP4A2_xmWr9_PSnIwJ20zjQmJRvathYfjktjAPNsEZ4Ry4xiFsnaOfQgcDl0wZEUMR-AEEyc5gDk5kLjQg3iEZa4MbqWXX9aAsS9fRzIqP8pvTQNsrNSD0KdTDuvZwrGiMseZJTc3dYXcEfP0EA6FiC17mWNi7Yz6zM1e7EOscPdoGbLkO0hYjL-THz5aguHNiNtEYFtb7sOef631SFQWillIDmueWM7vV0-mErs3Y2G5e50l2qyujtywVVkJtckRwd3uTrwa0KF1pQK_t1Aifx6BzT2TLOgms1Q3VFWyvLzqB3F2Rx92VQ1MNBQ97LqheJBzbJ3QNWW6jfzclU-YMygVmRPEYzzFsOG75n5witkf9BZFX8AtBvMEpOO6JB-lUtMD0_KBwan9sVqoUVMiM59VxILURm1w9Rq-GOcVUQz0Yrmg9hOKcIAxprQPg92QwciV8jqrjsBaSPXyQhdGvC-5Vsiojft6uzSJhzMLhZPGbC6rhPdpDjks13iSoKEg5SW-WbMIFbzGcZVOfgVJGzOuWm5LIw28hjH40z21_D_PsNsiwB4WnWDfzNsYQsTg\",\"refresh_token\":\"b35fc7cd6e25be57d87d18a91c278174c2f6cce5abd30823396cc0915a9ae4c1\",\"user\":{\"id\":5,\"email\":\"stefan.kodzhabashev@unibe.ch\",\"name\":\"Stefan Kodzhabashev\",\"language_id\":2,\"language_locale\":\"de-CH\"}}}',NULL),(6,'pages_get_all_with_language_v1','/cms-api/v1/pages/language/2','GET',200,5,'::1','2026-04-07 12:30:44','2026-04-07 12:30:44',196,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":true,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:44+02:00\"},\"data\":[{\"id_users\":5,\"id_pages\":30,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"agb\",\"url\":\"\\/agb\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":300,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"AGB\",\"children\":[]},{\"id_users\":5,\"id_pages\":32,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"disclaimer\",\"url\":\"\\/disclaimer\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":200,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Disclaimer\",\"children\":[]},{\"id_users\":5,\"id_pages\":2,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"home\",\"url\":\"\\/home\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Projekt Name\",\"children\":[]},{\"id_users\":5,\"id_pages\":31,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"impressum\",\"url\":\"\\/impressum\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":100,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Impressum\",\"children\":[]},{\"id_users\":5,\"id_pages\":1,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"login\",\"url\":\"\\/login\",\"parent\":null,\"is_headless\":1,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Login\",\"children\":[]},{\"id_users\":5,\"id_pages\":6,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"missing\",\"url\":\"\\/missing\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Seite nicht gefunden\",\"children\":[]},{\"id_users\":5,\"id_pages\":7,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"no_access\",\"url\":\"\\/no-access\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Kein Zugriff\",\"children\":[]},{\"id_users\":5,\"id_pages\":8,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"no_access_guest\",\"url\":\"\\/no-access-guest\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Kein Zugriff\",\"children\":[]},{\"id_users\":5,\"id_pages\":3,\"acl_select\":1,\"acl_insert\":0,\"acl_update\":1,\"acl_delete\":0,\"keyword\":\"profile-link\",\"url\":\"\\/profile-link\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Profil\",\"children\":[{\"id_users\":5,\"id_pages\":4,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"profile\",\"url\":\"\\/profile\",\"parent\":3,\"is_headless\":0,\"nav_position\":10,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Einstellungen\",\"children\":[]},{\"id_users\":5,\"id_pages\":5,\"acl_select\":1,\"acl_insert\":0,\"acl_update\":1,\"acl_delete\":0,\"keyword\":\"logout\",\"url\":\"\\/login\",\"parent\":3,\"is_headless\":0,\"nav_position\":20,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Logout\",\"children\":[]}]},{\"id_users\":5,\"id_pages\":35,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"reset_password\",\"url\":\"\\/reset\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Passwort zur\\u00fccksetzen\",\"children\":[]},{\"id_users\":5,\"id_pages\":84,\"acl_select\":1,\"acl_insert\":0,\"acl_update\":1,\"acl_delete\":0,\"keyword\":\"two-factor-authentication\",\"url\":\"\\/two-factor-authentication\",\"parent\":null,\"is_headless\":1,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Two-Factor Authentication\",\"children\":[]},{\"id_users\":5,\"id_pages\":33,\"acl_select\":1,\"acl_insert\":1,\"acl_update\":1,\"acl_delete\":1,\"keyword\":\"validate\",\"url\":\"\\/validate\\/[i:uid]\\/[a:token]\",\"parent\":null,\"is_headless\":0,\"nav_position\":null,\"footer_position\":null,\"id_type\":2,\"id_pageAccessTypes\":64,\"is_system\":1,\"title\":\"Benutzer Validierung\",\"children\":[]}]}',NULL),(7,'auth_user_data_get_v1_v1','/cms-api/v1/auth/user-data','GET',200,5,'::1','2026-04-07 12:30:44','2026-04-07 12:30:44',206,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":true,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:44+02:00\"},\"data\":{\"id\":5,\"email\":\"stefan.kodzhabashev@unibe.ch\",\"name\":\"Stefan Kodzhabashev\",\"user_name\":\"stefan.kodzhabashev@unibe.ch\",\"blocked\":false,\"language\":{\"id\":2,\"locale\":\"de-CH\",\"name\":\"Deutsch (Schweiz)\"},\"timezone\":{\"id\":123,\"code\":\"Europe\\/Zurich\",\"name\":\"Central European Time (CET)\",\"description\":\"Central European Time - UTC+1\\/+2\"},\"roles\":[{\"id\":1,\"name\":\"admin\",\"description\":\"Administrator role with full access\"}],\"permissions\":[\"admin.access\",\"admin.action_translation.read\",\"admin.action.delete\",\"admin.action.read\",\"admin.action.update\",\"admin.asset.create\",\"admin.asset.delete\",\"admin.asset.read\",\"admin.audit.view\",\"admin.cache.clear\",\"admin.cache.manage\",\"admin.cache.read\",\"admin.cms_preferences.read\",\"admin.cms_preferences.update\",\"admin.data.delete\",\"admin.data.delete_columns\",\"admin.data.read\",\"admin.group.acl\",\"admin.group.create\",\"admin.group.delete\",\"admin.group.read\",\"admin.group.update\",\"admin.page_version.compare\",\"admin.page_version.create\",\"admin.page_version.delete\",\"admin.page_version.publish\",\"admin.page_version.read\",\"admin.page_version.unpublish\",\"admin.page.create\",\"admin.page.delete\",\"admin.page.export\",\"admin.page.insert\",\"admin.page.read\",\"admin.page.update\",\"admin.permission.read\",\"admin.role.create\",\"admin.role.delete\",\"admin.role.permissions\",\"admin.role.read\",\"admin.role.update\",\"admin.scheduled_job.cancel\",\"admin.scheduled_job.delete\",\"admin.scheduled_job.execute\",\"admin.scheduled_job.read\",\"admin.section.delete\",\"admin.settings\",\"admin.user.block\",\"admin.user.create\",\"admin.user.delete\",\"admin.user.impersonate\",\"admin.user.read\",\"admin.user.unblock\",\"admin.user.update\"],\"groups\":[{\"id\":1,\"name\":\"admin\",\"description\":\"full access\"}]}}',NULL),(8,'admin_pages_get_all_v1','/cms-api/v1/admin/pages','GET',200,5,'::1','2026-04-07 12:30:44','2026-04-07 12:30:44',205,'[]','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":true,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:44+02:00\"},\"data\":[{\"id_pages\":1,\"parent\":null,\"keyword\":\"login\",\"url\":\"\\/login\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":true,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":2,\"parent\":null,\"keyword\":\"home\",\"url\":\"\\/home\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":3,\"parent\":null,\"keyword\":\"profile-link\",\"url\":\"\\/profile-link\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":4,\"parent\":3,\"keyword\":\"profile\",\"url\":\"\\/profile\",\"nav_position\":10,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":5,\"parent\":3,\"keyword\":\"logout\",\"url\":\"\\/login\",\"nav_position\":20,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":6,\"parent\":null,\"keyword\":\"missing\",\"url\":\"\\/missing\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":7,\"parent\":null,\"keyword\":\"no_access\",\"url\":\"\\/no-access\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":8,\"parent\":null,\"keyword\":\"no_access_guest\",\"url\":\"\\/no-access-guest\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":30,\"parent\":null,\"keyword\":\"agb\",\"url\":\"\\/agb\",\"nav_position\":null,\"footer_position\":300,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":31,\"parent\":null,\"keyword\":\"impressum\",\"url\":\"\\/impressum\",\"nav_position\":null,\"footer_position\":100,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":32,\"parent\":null,\"keyword\":\"disclaimer\",\"url\":\"\\/disclaimer\",\"nav_position\":null,\"footer_position\":200,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":33,\"parent\":null,\"keyword\":\"validate\",\"url\":\"\\/validate\\/[i:uid]\\/[a:token]\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":35,\"parent\":null,\"keyword\":\"reset_password\",\"url\":\"\\/reset\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":84,\"parent\":null,\"keyword\":\"two-factor-authentication\",\"url\":\"\\/two-factor-authentication\",\"nav_position\":null,\"footer_position\":null,\"is_headless\":true,\"is_open_access\":false,\"is_system\":true,\"id_pageAccessTypes\":64,\"id_type\":2,\"crud\":15},{\"id_pages\":85,\"parent\":null,\"keyword\":\"sh-global-css\",\"url\":null,\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":false,\"id_pageAccessTypes\":63,\"id_type\":10,\"crud\":15},{\"id_pages\":86,\"parent\":null,\"keyword\":\"sh-global-values\",\"url\":null,\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":false,\"id_pageAccessTypes\":64,\"id_type\":6,\"crud\":15},{\"id_pages\":87,\"parent\":null,\"keyword\":\"sh-cms-preferences\",\"url\":null,\"nav_position\":null,\"footer_position\":null,\"is_headless\":false,\"is_open_access\":false,\"is_system\":false,\"id_pageAccessTypes\":63,\"id_type\":12,\"crud\":15}]}',NULL),(9,'pages_get_one_v1','/cms-api/v1/pages/2','GET',200,5,'::1','2026-04-07 12:30:44','2026-04-07 12:30:44',221,'{\"query\":{\"language_id\":\"2\"}}','{\"host\":[\"localhost\"],\"connection\":[\"keep-alive\"],\"sec-ch-ua-platform\":[\"\\\"Windows\\\"\"],\"x-client-type\":[\"web\"],\"accept\":[\"application\\/json\"],\"sec-ch-ua\":[\"\\\"Not:A-Brand\\\";v=\\\"99\\\", \\\"Opera\\\";v=\\\"129\\\", \\\"Chromium\\\";v=\\\"145\\\"\"],\"user-agent\":[\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/145.0.0.0 Safari\\/537.36 OPR\\/129.0.0.0\"],\"sec-ch-ua-mobile\":[\"?0\"],\"origin\":[\"http:\\/\\/localhost:3000\"],\"sec-fetch-site\":[\"same-site\"],\"sec-fetch-mode\":[\"cors\"],\"sec-fetch-dest\":[\"empty\"],\"referer\":[\"http:\\/\\/localhost:3000\\/\"],\"accept-encoding\":[\"gzip, deflate, br, zstd\"],\"accept-language\":[\"en-US,en;q=0.9,bg;q=0.8,de;q=0.7,fr;q=0.6,ru;q=0.5,it;q=0.4,ja;q=0.3,nl;q=0.2,hu;q=0.1\"],\"x-php-ob-level\":[\"1\"]}','{\"status\":200,\"message\":\"OK\",\"error\":null,\"logged_in\":true,\"meta\":{\"version\":\"v1\",\"timestamp\":\"2026-04-07T14:30:44+02:00\"},\"data\":{\"page\":{\"id\":2,\"keyword\":\"home\",\"url\":\"\\/home\",\"parent_page_id\":null,\"is_headless\":false,\"nav_position\":null,\"footer_position\":null,\"sections\":[]}}}',NULL);
/*!40000 ALTER TABLE `apiRequestLogs` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `callbackLogs`
--

DROP TABLE IF EXISTS `callbackLogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `callbackLogs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `callback_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  `remote_addr` varchar(200) DEFAULT NULL,
  `redirect_url` varchar(1000) DEFAULT NULL,
  `callback_params` longtext,
  `status` varchar(200) DEFAULT NULL,
  `callback_output` longtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `callbackLogs`
--

LOCK TABLES `callbackLogs` WRITE;
/*!40000 ALTER TABLE `callbackLogs` DISABLE KEYS */;
/*!40000 ALTER TABLE `callbackLogs` ENABLE KEYS */;
UNLOCK TABLES;

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
  KEY `IDX_9F20ED7677153098` (`code`),
  KEY `IDX_9F20ED76D65A8C9D` (`id_groups`),
  CONSTRAINT `FK_9F20ED7677153098` FOREIGN KEY (`code`) REFERENCES `validation_codes` (`code`) ON DELETE CASCADE,
  CONSTRAINT `FK_9F20ED76D65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `codes_groups`
--

LOCK TABLES `codes_groups` WRITE;
/*!40000 ALTER TABLE `codes_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `codes_groups` ENABLE KEYS */;
UNLOCK TABLES;

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
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_D2C78316DBD5589F` (`id_actions`),
  KEY `IDX_dataAccessAudit_users` (`id_users`),
  KEY `IDX_dataAccessAudit_resource_types` (`id_resourceTypes`),
  KEY `IDX_dataAccessAudit_resource_id` (`resource_id`),
  KEY `IDX_dataAccessAudit_created_at` (`created_at`),
  KEY `IDX_dataAccessAudit_permission_results` (`id_permissionResults`),
  KEY `IDX_dataAccessAudit_http_method` (`http_method`),
  KEY `IDX_dataAccessAudit_request_body_hash` (`request_body_hash`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dataAccessAudit`
--

LOCK TABLES `dataAccessAudit` WRITE;
/*!40000 ALTER TABLE `dataAccessAudit` DISABLE KEYS */;
/*!40000 ALTER TABLE `dataAccessAudit` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `dataCells`
--

LOCK TABLES `dataCells` WRITE;
/*!40000 ALTER TABLE `dataCells` DISABLE KEYS */;
/*!40000 ALTER TABLE `dataCells` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dataCols`
--

LOCK TABLES `dataCols` WRITE;
/*!40000 ALTER TABLE `dataCols` DISABLE KEYS */;
/*!40000 ALTER TABLE `dataCols` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dataRows`
--

DROP TABLE IF EXISTS `dataRows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataRows` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_dataTables` int DEFAULT NULL,
  `timestamp` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `id_users` int DEFAULT NULL,
  `id_actionTriggerTypes` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_A35EA3D0E2E6A7C3` (`id_dataTables`),
  CONSTRAINT `FK_A35EA3D0E2E6A7C3` FOREIGN KEY (`id_dataTables`) REFERENCES `dataTables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dataRows`
--

LOCK TABLES `dataRows` WRITE;
/*!40000 ALTER TABLE `dataRows` DISABLE KEYS */;
/*!40000 ALTER TABLE `dataRows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dataTables`
--

DROP TABLE IF EXISTS `dataTables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dataTables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `timestamp` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `displayName` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dataTables`
--

LOCK TABLES `dataTables` WRITE;
/*!40000 ALTER TABLE `dataTables` DISABLE KEYS */;
/*!40000 ALTER TABLE `dataTables` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=622 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fields`
--

LOCK TABLES `fields` WRITE;
/*!40000 ALTER TABLE `fields` DISABLE KEYS */;
INSERT INTO `fields` VALUES (1,'label_user',1,1,NULL),(2,'label_pw',1,1,NULL),(3,'label_login',1,1,NULL),(4,'label_pw_reset',1,1,NULL),(5,'alert_fail',1,1,NULL),(7,'login_title',1,1,NULL),(8,'label',7,1,NULL),(9,'label_pw_confirm',1,1,NULL),(12,'delete_title',1,1,NULL),(13,'label_delete',1,1,NULL),(14,'delete_content',2,1,NULL),(19,'alert_del_fail',1,1,NULL),(20,'alert_del_success',1,1,NULL),(21,'level',5,0,NULL),(22,'title',7,1,NULL),(24,'text',2,1,NULL),(25,'text_md',4,1,NULL),(27,'url',1,0,NULL),(28,'type',9,0,NULL),(29,'is_fluid',3,0,NULL),(30,'alt',1,1,NULL),(34,'subtitle',1,1,NULL),(35,'alert_success',1,1,NULL),(36,'label_name',1,1,NULL),(37,'name_placeholder',1,1,NULL),(38,'name_description',7,1,NULL),(39,'label_gender',1,1,NULL),(40,'gender_male',1,1,NULL),(41,'gender_female',1,1,NULL),(42,'label_activate',1,1,NULL),(43,'pw_placeholder',1,1,NULL),(44,'success',1,1,NULL),(45,'is_dismissable',3,0,NULL),(46,'is_expanded',3,0,NULL),(49,'caption_title',1,1,NULL),(50,'caption',7,1,NULL),(51,'label_cancel',1,1,NULL),(52,'url_cancel',1,0,NULL),(53,'img_src',36,1,NULL),(55,'placeholder',1,1,NULL),(56,'is_required',3,0,NULL),(57,'name',1,0,NULL),(58,'value',1,0,NULL),(59,'is_paragraph',3,0,NULL),(60,'count',1,0,NULL),(61,'count_max',5,0,NULL),(66,'items',8,1,NULL),(67,'is_multiple',3,0,NULL),(68,'labels',8,1,NULL),(69,'min',5,0,NULL),(70,'max',5,0,NULL),(71,'sources',8,1,NULL),(83,'id_prefix',1,0,NULL),(85,'is_inline',3,0,NULL),(86,'open_in_new_tab',3,0,NULL),(87,'is_log',3,0,NULL),(90,'label_submit',1,1,NULL),(99,'has_controls',3,0,NULL),(100,'has_indicators',3,0,NULL),(101,'is_striped',3,0,NULL),(102,'has_label',3,0,NULL),(103,'has_crossfade',3,0,NULL),(106,'description',2,1,NULL),(110,'email_user',11,1,NULL),(111,'subject_user',1,1,NULL),(114,'is_html',3,0,NULL),(115,'maintenance',4,1,NULL),(116,'maintenance_date',13,0,NULL),(117,'maintenance_time',14,0,NULL),(139,'anchor',16,0,NULL),(140,'open_registration',3,0,NULL),(141,'live_search',3,0,NULL),(142,'disabled',3,0,NULL),(143,'group',17,0,NULL),(146,'export_pdf',3,0,NULL),(155,'format',1,0,NULL),(168,'image_selector',3,0,NULL),(170,'data_table',20,0,NULL),(174,'icon',1,0,NULL),(176,'own_entries_only',3,0,NULL),(178,'gender_divers',1,1,NULL),(179,'locked_after_submit',3,0,NULL),(181,'filter',12,0,NULL),(186,'confirmation_title',1,1,NULL),(187,'confirmation_continue',1,1,NULL),(188,'confirmation_message',2,1,NULL),(189,'column_names',1,1,NULL),(190,'loop',8,0,NULL),(191,'page_keyword',25,0,NULL),(192,'allow_clear',3,0,NULL),(193,'custom_css',26,0,NULL),(194,'value_gender',1,0,NULL),(195,'value_name',1,0,NULL),(201,'internal',3,0,NULL),(213,'label_security_question_1',1,1,NULL),(214,'label_security_question_2',1,1,NULL),(215,'anonymous_users_registration',4,1,NULL),(216,'anonymous_user_name_description',4,1,NULL),(217,'toggle_switch',3,0,NULL),(218,'checkbox_value',1,0,NULL),(219,'color_background',29,0,NULL),(220,'color_border',29,0,NULL),(221,'color_text',29,0,NULL),(222,'load_as_table',3,0,NULL),(223,'scope',1,0,NULL),(224,'url_param',1,0,NULL),(226,'fields_map',8,1,NULL),(227,'confirmation_cancel',7,1,NULL),(228,'height',1,0,NULL),(229,'width',1,0,NULL),(230,'markdown_editor',3,0,NULL),(233,'label_expiration_2fa',7,1,NULL),(236,'default_language_id',34,1,NULL),(237,'anonymous_users',3,1,NULL),(238,'firebase_config',8,1,NULL),(239,'default_timezone',35,1,NULL),(240,'video_src',37,0,NULL),(241,'mantine_icon_size',38,0,'{\"options\": [{\"text\": \"Small (14px)\", \"value\": \"14\"}, {\"text\": \"Medium (16px)\", \"value\": \"16\"}, {\"text\": \"Large (18px)\", \"value\": \"18\"}, {\"text\": \"Extra Large (20px)\", \"value\": \"20\"}, {\"text\": \"XL (24px)\", \"value\": \"24\"}, {\"text\": \"XXL (32px)\", \"value\": \"32\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"16\"}'),(242,'mantine_spacing_margin_padding',46,0,NULL),(243,'mantine_spacing_margin',45,0,NULL),(244,'mantine_loop',3,0,NULL),(245,'mantine_control_size',38,0,'{\"options\": [{\"text\": \"Small (14px)\", \"value\": \"14\"}, {\"text\": \"Medium (16px)\", \"value\": \"16\"}, {\"text\": \"Large (18px)\", \"value\": \"18\"}, {\"text\": \"Extra Large (20px)\", \"value\": \"20\"}, {\"text\": \"XL (24px)\", \"value\": \"24\"}, {\"text\": \"XXL (32px)\", \"value\": \"32\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"16\"}'),(246,'mantine_border_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(247,'mantine_background_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(248,'mantine_mt',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(249,'mantine_mb',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(250,'mantine_ml',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(251,'mantine_mr',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(252,'mantine_pt',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(253,'mantine_pb',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(254,'mantine_pl',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(255,'mantine_pr',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(256,'mantine_border',3,0,NULL),(257,'mantine_border_size',40,0,'{\"options\": [{\"text\": \"1px\", \"value\": \"1\"}, {\"text\": \"2px\", \"value\": \"2\"}, {\"text\": \"3px\", \"value\": \"3\"}, {\"text\": \"4px\", \"value\": \"4\"}, {\"text\": \"5px\", \"value\": \"5\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"1\"}'),(258,'mantine_shadow',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(259,'readonly',3,0,NULL),(260,'translatable',3,0,NULL),(261,'mantine_tooltip_position',38,0,'{\"options\": [{\"text\": \"Top\", \"value\": \"top\"}, {\"text\": \"Bottom\", \"value\": \"bottom\"}, {\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Right\", \"value\": \"right\"}, {\"text\": \"Top Start\", \"value\": \"top-start\"}, {\"text\": \"Top End\", \"value\": \"top-end\"}, {\"text\": \"Bottom Start\", \"value\": \"bottom-start\"}, {\"text\": \"Bottom End\", \"value\": \"bottom-end\"}, {\"text\": \"Left Start\", \"value\": \"left-start\"}, {\"text\": \"Left End\", \"value\": \"left-end\"}, {\"text\": \"Right Start\", \"value\": \"right-start\"}, {\"text\": \"Right End\", \"value\": \"right-end\"}], \"clearable\": true, \"searchable\": false}'),(262,'mantine_variant',38,0,'{\"options\": [{\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Light\", \"value\": \"light\"}, {\"text\": \"Outline\", \"value\": \"outline\"}, {\"text\": \"Subtle\", \"value\": \"subtle\"}, {\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Transparent\", \"value\": \"transparent\"}, {\"text\": \"White\", \"value\": \"white\"}], \"clearable\": false, \"searchable\": false}'),(263,'mantine_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(264,'mantine_size',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(265,'mantine_radius',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(266,'mantine_left_icon',41,0,NULL),(267,'mantine_right_icon',41,0,NULL),(268,'mantine_orientation',42,0,'{\"options\": [{\"text\": \"Horizontal\", \"value\": \"horizontal\"}, {\"text\": \"Vertical\", \"value\": \"vertical\"}]}'),(269,'mantine_color_format',42,0,'{\"options\": [{\"text\": \"Hex\", \"value\": \"hex\"}, {\"text\": \"RGBA\", \"value\": \"rgba\"}, {\"text\": \"HSLA\", \"value\": \"hsla\"}]}'),(270,'mantine_numeric_min',38,0,'{\"options\": [{\"text\": \"0\", \"value\": \"0\"}, {\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"100\", \"value\": \"100\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(271,'mantine_numeric_max',38,0,'{\"options\": [{\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"100\", \"value\": \"100\"}, {\"text\": \"1000\", \"value\": \"1000\"}, {\"text\": \"10000\", \"value\": \"10000\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(272,'mantine_numeric_step',38,0,'{\"options\": [{\"text\": \"0.1\", \"value\": \"0.1\"}, {\"text\": \"0.5\", \"value\": \"0.5\"}, {\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"10\", \"value\": \"10\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(273,'mantine_width',38,0,'{\"options\": [{\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"75%\", \"value\": \"75%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"Auto\", \"value\": \"auto\"}, {\"text\": \"Fit Content\", \"value\": \"fit-content\"}, {\"text\": \"Max Content\", \"value\": \"max-content\"}, {\"text\": \"Min Content\", \"value\": \"min-content\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(274,'mantine_height',38,0,'{\"options\": [{\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"75%\", \"value\": \"75%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"Auto\", \"value\": \"auto\"}, {\"text\": \"Fit Content\", \"value\": \"fit-content\"}, {\"text\": \"Max Content\", \"value\": \"max-content\"}, {\"text\": \"Min Content\", \"value\": \"min-content\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(275,'mantine_gap',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"0\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(276,'mantine_justify',38,0,'{\"options\": [{\"text\": \"Start\", \"value\": \"flex-start\"}, {\"text\": \"Center\", \"value\": \"center\"}, {\"text\": \"End\", \"value\": \"flex-end\"}, {\"text\": \"Space Between\", \"value\": \"space-between\"}, {\"text\": \"Space Around\", \"value\": \"space-around\"}, {\"text\": \"Space Evenly\", \"value\": \"space-evenly\"}], \"clearable\": true, \"searchable\": false}'),(277,'mantine_align',38,0,'{\"options\": [{\"text\": \"Start\", \"value\": \"flex-start\"}, {\"text\": \"Center\", \"value\": \"center\"}, {\"text\": \"End\", \"value\": \"flex-end\"}, {\"text\": \"Stretch\", \"value\": \"stretch\"}, {\"text\": \"Baseline\", \"value\": \"baseline\"}], \"clearable\": true, \"searchable\": false}'),(278,'mantine_direction',42,0,'{\"options\": [{\"text\": \"Row\", \"value\": \"row\"}, {\"text\": \"Column\", \"value\": \"column\"}, {\"text\": \"Row Reverse\", \"value\": \"row-reverse\"}, {\"text\": \"Column Reverse\", \"value\": \"column-reverse\"}]}'),(279,'mantine_wrap',42,0,'{\"options\": [{\"text\": \"Wrap\", \"value\": \"wrap\"}, {\"text\": \"No Wrap\", \"value\": \"nowrap\"}, {\"text\": \"Wrap Reverse\", \"value\": \"wrap-reverse\"}]}'),(280,'mantine_fullwidth',3,0,NULL),(281,'mantine_compact',3,0,NULL),(282,'mantine_auto_contrast',3,0,NULL),(283,'is_link',3,0,NULL),(284,'use_mantine_style',3,0,NULL),(285,'mantine_switch_on_label',1,1,'{\"placeholder\": \"Enter on label\"}'),(286,'mantine_switch_off_label',1,1,'{\"placeholder\": \"Enter off label\"}'),(287,'content',2,1,NULL),(288,'cite',1,1,NULL),(289,'mantine_radio_options',8,1,'{\"rows\": 5, \"placeholder\": \"Enter JSON array of radio options\"}'),(290,'mantine_segmented_control_data',2,1,'{\"rows\": 3, \"placeholder\": \"Enter JSON array of segmented control options\"}'),(291,'mantine_combobox_data',2,1,'{\"rows\": 3, \"placeholder\": \"Enter JSON array of combobox options\"}'),(292,'mantine_multi_select_data',2,1,'{\"rows\": 3, \"placeholder\": \"Enter JSON array of multi-select options\"}'),(293,'mantine_use_input_wrapper',3,0,NULL),(294,'type_input',38,0,'{\"options\": [{\"text\": \"Text\", \"value\": \"text\"}, {\"text\": \"Email\", \"value\": \"email\"}, {\"text\": \"Password\", \"value\": \"password\"}, {\"text\": \"Number\", \"value\": \"number\"}, {\"text\": \"Checkbox\", \"value\": \"checkbox\"}, {\"text\": \"Color\", \"value\": \"color\"}, {\"text\": \"Date\", \"value\": \"date\"}, {\"text\": \"Time\", \"value\": \"time\"}, {\"text\": \"Telephone\", \"value\": \"tel\"}, {\"text\": \"URL\", \"value\": \"url\"}], \"clearable\": false, \"searchable\": false}'),(295,'options',8,1,'{\"rows\": 5, \"placeholder\": \"Enter JSON array of select options\"}'),(296,'mantine_progress_striped',3,0,NULL),(297,'mantine_progress_animated',3,0,NULL),(298,'mantine_progress_auto_contrast',3,0,NULL),(299,'mantine_progress_transition_duration',38,0,'{\"options\": [{\"text\": \"Fast (150ms)\", \"value\": \"150\"}, {\"text\": \"Normal (200ms)\", \"value\": \"200\"}, {\"text\": \"Slow (300ms)\", \"value\": \"300\"}, {\"text\": \"Very Slow (400ms)\", \"value\": \"400\"}, {\"text\": \"Instant (0ms)\", \"value\": \"0\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"200\"}'),(300,'mantine_tooltip_label',1,1,NULL),(309,'mantine_center_inline',3,0,NULL),(312,'mantine_miw',38,0,'{\"options\": [{\"text\": \"0\", \"value\": \"0\"}, {\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"200px\", \"value\": \"200px\"}, {\"text\": \"300px\", \"value\": \"300px\"}, {\"text\": \"400px\", \"value\": \"400px\"}, {\"text\": \"500px\", \"value\": \"500px\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(313,'mantine_mih',38,0,'{\"options\": [{\"text\": \"0\", \"value\": \"0\"}, {\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"200px\", \"value\": \"200px\"}, {\"text\": \"300px\", \"value\": \"300px\"}, {\"text\": \"400px\", \"value\": \"400px\"}, {\"text\": \"500px\", \"value\": \"500px\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(314,'mantine_maw',38,0,'{\"options\": [{\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"75%\", \"value\": \"75%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"200px\", \"value\": \"200px\"}, {\"text\": \"300px\", \"value\": \"300px\"}, {\"text\": \"400px\", \"value\": \"400px\"}, {\"text\": \"500px\", \"value\": \"500px\"}, {\"text\": \"600px\", \"value\": \"600px\"}, {\"text\": \"800px\", \"value\": \"800px\"}, {\"text\": \"1000px\", \"value\": \"1000px\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(315,'mantine_mah',38,0,'{\"options\": [{\"text\": \"25%\", \"value\": \"25%\"}, {\"text\": \"50%\", \"value\": \"50%\"}, {\"text\": \"75%\", \"value\": \"75%\"}, {\"text\": \"100%\", \"value\": \"100%\"}, {\"text\": \"200px\", \"value\": \"200px\"}, {\"text\": \"300px\", \"value\": \"300px\"}, {\"text\": \"400px\", \"value\": \"400px\"}, {\"text\": \"500px\", \"value\": \"500px\"}, {\"text\": \"600px\", \"value\": \"600px\"}, {\"text\": \"800px\", \"value\": \"800px\"}, {\"text\": \"1000px\", \"value\": \"1000px\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(316,'mantine_fluid',3,0,NULL),(317,'mantine_px',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(318,'mantine_py',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(324,'mantine_breakpoints',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(325,'mantine_cols',40,0,'{\"options\": [{\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}]}'),(326,'mantine_group_wrap',42,0,'{\"options\": [{\"text\": \"No Wrap\", \"value\": \"0\"}, {\"text\": \"Wrap\", \"value\": \"1\"}]}'),(327,'mantine_group_grow',3,0,NULL),(328,'mantine_vertical_spacing',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"0\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(329,'mantine_space_direction',42,0,'{\"options\": [{\"text\": \"Horizontal\", \"value\": \"horizontal\"}, {\"text\": \"Vertical\", \"value\": \"vertical\"}]}'),(330,'mantine_grid_overflow',42,0,'{\"options\": [{\"text\": \"Visible\", \"value\": \"visible\"}, {\"text\": \"Hidden\", \"value\": \"hidden\"}]}'),(331,'mantine_grid_span',40,0,'{\"options\": [{\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"7\", \"value\": \"7\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"9\", \"value\": \"9\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"11\", \"value\": \"11\"}, {\"text\": \"12\", \"value\": \"12\"}, {\"text\": \"Auto\", \"value\": \"auto\"}, {\"text\": \"Content\", \"value\": \"content\"}]}'),(332,'mantine_grid_offset',40,0,'{\"options\": [{\"text\": \"0\", \"value\": \"0\"}, {\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"7\", \"value\": \"7\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"9\", \"value\": \"9\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"11\", \"value\": \"11\"}]}'),(333,'mantine_grid_order',40,0,'{\"options\": [{\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"7\", \"value\": \"7\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"9\", \"value\": \"9\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"11\", \"value\": \"11\"}, {\"text\": \"12\", \"value\": \"12\"}]}'),(334,'mantine_grid_grow',3,0,NULL),(335,'mantine_tabs_variant',38,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Outline\", \"value\": \"outline\"}, {\"text\": \"Pills\", \"value\": \"pills\"}], \"clearable\": false, \"searchable\": false}'),(336,'mantine_tabs_radius',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(337,'mantine_tab_disabled',3,0,NULL),(338,'mantine_aspect_ratio',38,0,'{\"options\": [{\"text\": \"16:9\", \"value\": \"16/9\"}, {\"text\": \"4:3\", \"value\": \"4/3\"}, {\"text\": \"1:1\", \"value\": \"1/1\"}, {\"text\": \"21:9\", \"value\": \"21/9\"}, {\"text\": \"3:2\", \"value\": \"3/2\"}, {\"text\": \"9:16\", \"value\": \"9/16\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false}'),(339,'mantine_chip_variant',38,0,'{\"options\": [{\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Outline\", \"value\": \"outline\"}, {\"text\": \"Light\", \"value\": \"light\"}], \"clearable\": false, \"searchable\": false}'),(340,'chip_checked',3,0,NULL),(341,'chip_on_value',1,0,'{\"placeholder\": \"1\"}'),(342,'chip_off_value',1,0,'{\"placeholder\": \"0\"}'),(343,'mantine_image_fit',38,0,'{\"options\": [{\"text\": \"Contain (fit entire image)\", \"value\": \"contain\"}, {\"text\": \"Cover (fill container)\", \"value\": \"cover\"}, {\"text\": \"Fill (stretch to fill)\", \"value\": \"fill\"}, {\"text\": \"None (original size)\", \"value\": \"none\"}, {\"text\": \"Scale Down (smaller of none/contain)\", \"value\": \"scale-down\"}], \"clearable\": false, \"searchable\": false}'),(353,'mantine_color_picker_swatches_per_row',40,0,'{\"options\": [{\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"7\", \"value\": \"7\"}, {\"text\": \"8\", \"value\": \"8\"}]}'),(354,'mantine_color_picker_swatches',2,0,NULL),(355,'mantine_color_picker_saturation_label',1,1,'{\"placeholder\": \"Saturation\"}'),(356,'mantine_color_picker_hue_label',1,1,'{\"placeholder\": \"Hue\"}'),(357,'mantine_color_picker_alpha_label',1,1,'{\"placeholder\": \"Alpha\"}'),(358,'mantine_fieldset_variant',38,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Unstyled\", \"value\": \"unstyled\"}], \"clearable\": false, \"searchable\": false}'),(359,'mantine_file_input_multiple',3,0,NULL),(360,'mantine_file_input_accept',38,0,'{\"options\": [{\"text\": \"All Images\", \"value\": \"image/*\"}, {\"text\": \"Common Images (PNG, JPG, GIF)\", \"value\": \"image/png,image/jpeg,image/gif\"}, {\"text\": \"PNG Images\", \"value\": \"image/png\"}, {\"text\": \"JPEG Images\", \"value\": \"image/jpeg\"}, {\"text\": \"WebP Images\", \"value\": \"image/webp\"}, {\"text\": \"All Audio Files\", \"value\": \"audio/*\"}, {\"text\": \"All Video Files\", \"value\": \"video/*\"}, {\"text\": \"PDF Documents\", \"value\": \".pdf\"}, {\"text\": \"Word Documents\", \"value\": \".doc,.docx\"}, {\"text\": \"Excel Files\", \"value\": \".xls,.xlsx\"}, {\"text\": \"PowerPoint Files\", \"value\": \".ppt,.pptx\"}, {\"text\": \"Text Files\", \"value\": \".txt\"}, {\"text\": \"Archive Files\", \"value\": \".zip,.rar\"}, {\"text\": \"JSON Files\", \"value\": \"application/json\"}, {\"text\": \"CSV Files\", \"value\": \"text/csv\"}], \"clearable\": true, \"creatable\": true, \"searchable\": true, \"placeholder\": \"image/*\"}'),(361,'mantine_file_input_clearable',3,0,NULL),(362,'mantine_file_input_max_size',38,0,'{\"options\": [{\"text\": \"1 KB\", \"value\": \"1024\"}, {\"text\": \"10 KB\", \"value\": \"10240\"}, {\"text\": \"100 KB\", \"value\": \"102400\"}, {\"text\": \"512 KB\", \"value\": \"524288\"}, {\"text\": \"1 MB\", \"value\": \"1048576\"}, {\"text\": \"2 MB\", \"value\": \"2097152\"}, {\"text\": \"5 MB\", \"value\": \"5242880\"}, {\"text\": \"10 MB\", \"value\": \"10485760\"}, {\"text\": \"20 MB\", \"value\": \"20971520\"}, {\"text\": \"50 MB\", \"value\": \"52428800\"}, {\"text\": \"100 MB\", \"value\": \"104857600\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"5242880\"}'),(363,'mantine_file_input_max_files',38,0,'{\"options\": [{\"text\": \"1 file\", \"value\": \"1\"}, {\"text\": \"3 files\", \"value\": \"3\"}, {\"text\": \"5 files\", \"value\": \"5\"}, {\"text\": \"10 files\", \"value\": \"10\"}, {\"text\": \"20 files\", \"value\": \"20\"}, {\"text\": \"50 files\", \"value\": \"50\"}, {\"text\": \"100 files\", \"value\": \"100\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"5\"}'),(364,'mantine_file_input_drag_drop',3,0,NULL),(368,'mantine_number_input_decimal_scale',40,0,'{\"options\": [{\"text\": \"0\", \"value\": \"0\"}, {\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}]}'),(369,'mantine_number_input_clamp_behavior',42,0,'{\"options\": [{\"text\": \"Strict\", \"value\": \"strict\"}, {\"text\": \"Blur\", \"value\": \"blur\"}]}'),(371,'mantine_radio_label_position',38,0,'{\"options\": [{\"text\": \"Right (default)\", \"value\": \"right\"}, {\"text\": \"Left\", \"value\": \"left\"}], \"clearable\": false, \"searchable\": false}'),(372,'mantine_radio_card',3,0,NULL),(373,'mantine_radio_variant',38,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Outline\", \"value\": \"outline\"}], \"clearable\": false, \"searchable\": false}'),(374,'mantine_range_slider_marks_values',2,1,NULL),(375,'mantine_range_slider_show_label',3,0,NULL),(376,'mantine_range_slider_labels_always_on',3,0,NULL),(377,'mantine_range_slider_inverted',3,0,NULL),(378,'mantine_slider_marks_values',2,1,NULL),(379,'mantine_slider_show_label',3,0,NULL),(380,'mantine_slider_labels_always_on',3,0,NULL),(381,'mantine_slider_inverted',3,0,NULL),(383,'mantine_slider_required',3,0,NULL),(384,'mantine_rating_count',40,0,'{\"options\": [{\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"7\", \"value\": \"7\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"9\", \"value\": \"9\"}, {\"text\": \"10\", \"value\": \"10\"}]}'),(385,'mantine_rating_fractions',40,0,'{\"options\": [{\"text\": \"Whole\", \"value\": \"1\"}, {\"text\": \"Halves\", \"value\": \"2\"}, {\"text\": \"Thirds\", \"value\": \"3\"}, {\"text\": \"Quarters\", \"value\": \"4\"}, {\"text\": \"Fifths\", \"value\": \"5\"}]}'),(386,'mantine_rating_use_smiles',3,0,NULL),(387,'mantine_rating_empty_icon',41,0,NULL),(388,'mantine_rating_full_icon',41,0,NULL),(389,'mantine_rating_highlight_selected_only',3,0,NULL),(391,'mantine_segmented_control_item_border',3,0,NULL),(395,'mantine_label_position',42,0,'{\"options\": [{\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Right\", \"value\": \"right\"}]}'),(396,'mantine_switch_on_value',1,0,'{\"placeholder\": \"Enter value for on state (e.g., 1, true, yes)\"}'),(397,'mantine_combobox_options',8,1,'{\"rows\": 3, \"placeholder\": \"Enter JSON array of combobox options\"}'),(398,'mantine_combobox_multi_select',3,0,NULL),(399,'mantine_combobox_searchable',3,0,NULL),(400,'mantine_combobox_creatable',3,0,NULL),(401,'mantine_combobox_clearable',3,0,NULL),(402,'mantine_combobox_separator',1,0,'{\"placeholder\": \" \"}'),(403,'mantine_multi_select_max_values',38,0,'{\"options\": [{\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"25\", \"value\": \"25\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(404,'mantine_action_icon_loading',3,0,NULL),(405,'mantine_notification_loading',3,0,NULL),(406,'mantine_notification_with_close_button',3,0,NULL),(407,'mantine_alert_title',1,1,NULL),(408,'mantine_with_close_button',3,0,NULL),(409,'mantine_accordion_variant',38,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Contained\", \"value\": \"contained\"}, {\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Separated\", \"value\": \"separated\"}], \"clearable\": false, \"searchable\": false}'),(410,'mantine_accordion_multiple',3,0,NULL),(411,'mantine_accordion_chevron_position',42,0,'{\"options\": [{\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Right\", \"value\": \"right\"}]}'),(412,'mantine_accordion_chevron_size',38,0,'{\"options\": [{\"text\": \"Small (14px)\", \"value\": \"14\"}, {\"text\": \"Medium (16px)\", \"value\": \"16\"}, {\"text\": \"Large (18px)\", \"value\": \"18\"}, {\"text\": \"Extra Large (20px)\", \"value\": \"20\"}, {\"text\": \"XL (24px)\", \"value\": \"24\"}, {\"text\": \"XXL (32px)\", \"value\": \"32\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false, \"placeholder\": \"16\"}'),(413,'mantine_accordion_disable_chevron_rotation',3,0,NULL),(414,'mantine_accordion_loop',3,0,NULL),(415,'mantine_accordion_transition_duration',38,0,'{\"options\": [{\"text\": \"Instant (0ms)\", \"value\": \"0\"}, {\"text\": \"Fast (150ms)\", \"value\": \"150\"}, {\"text\": \"Normal (200ms)\", \"value\": \"200\"}, {\"text\": \"Slow (300ms)\", \"value\": \"300\"}, {\"text\": \"Very Slow (400ms)\", \"value\": \"400\"}, {\"text\": \"Extra Slow (500ms)\", \"value\": \"500\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false, \"placeholder\": \"200\"}'),(416,'mantine_accordion_default_value',1,0,'{\"placeholder\": \"item-1,item-2\"}'),(417,'mantine_accordion_item_value',1,0,'{\"placeholder\": \"unique-item-value\"}'),(418,'mantine_accordion_item_icon',41,0,NULL),(419,'mantine_avatar_initials',1,0,'{\"placeholder\": \"Enter name to generate initials (e.g., Stefan Kod → SK)\"}'),(420,'mantine_indicator_processing',3,0,NULL),(421,'mantine_indicator_disabled',3,0,NULL),(422,'mantine_indicator_size',40,0,'{\"options\": [{\"text\": \"6px\", \"value\": \"6\"}, {\"text\": \"7px\", \"value\": \"7\"}, {\"text\": \"8px\", \"value\": \"8\"}, {\"text\": \"9px\", \"value\": \"9\"}, {\"text\": \"10px\", \"value\": \"10\"}, {\"text\": \"11px\", \"value\": \"11\"}, {\"text\": \"12px\", \"value\": \"12\"}, {\"text\": \"13px\", \"value\": \"13\"}, {\"text\": \"14px\", \"value\": \"14\"}, {\"text\": \"15px\", \"value\": \"15\"}, {\"text\": \"16px\", \"value\": \"16\"}, {\"text\": \"17px\", \"value\": \"17\"}, {\"text\": \"18px\", \"value\": \"18\"}, {\"text\": \"19px\", \"value\": \"19\"}, {\"text\": \"20px\", \"value\": \"20\"}, {\"text\": \"21px\", \"value\": \"21\"}, {\"text\": \"22px\", \"value\": \"22\"}, {\"text\": \"23px\", \"value\": \"23\"}, {\"text\": \"24px\", \"value\": \"24\"}, {\"text\": \"25px\", \"value\": \"25\"}, {\"text\": \"26px\", \"value\": \"26\"}, {\"text\": \"27px\", \"value\": \"27\"}, {\"text\": \"28px\", \"value\": \"28\"}, {\"text\": \"29px\", \"value\": \"29\"}, {\"text\": \"30px\", \"value\": \"30\"}, {\"text\": \"31px\", \"value\": \"31\"}, {\"text\": \"32px\", \"value\": \"32\"}, {\"text\": \"33px\", \"value\": \"33\"}, {\"text\": \"34px\", \"value\": \"34\"}, {\"text\": \"35px\", \"value\": \"35\"}, {\"text\": \"36px\", \"value\": \"36\"}, {\"text\": \"37px\", \"value\": \"37\"}, {\"text\": \"38px\", \"value\": \"38\"}, {\"text\": \"39px\", \"value\": \"39\"}, {\"text\": \"40px\", \"value\": \"40\"}]}'),(423,'mantine_indicator_position',38,0,'{\"options\": [{\"text\": \"Top Start\", \"value\": \"top-start\"}, {\"text\": \"Top Center\", \"value\": \"top-center\"}, {\"text\": \"Top End\", \"value\": \"top-end\"}, {\"text\": \"Middle Start\", \"value\": \"middle-start\"}, {\"text\": \"Middle Center\", \"value\": \"middle-center\"}, {\"text\": \"Middle End\", \"value\": \"middle-end\"}, {\"text\": \"Bottom Start\", \"value\": \"bottom-start\"}, {\"text\": \"Bottom Center\", \"value\": \"bottom-center\"}, {\"text\": \"Bottom End\", \"value\": \"bottom-end\"}], \"clearable\": false, \"searchable\": false}'),(424,'mantine_indicator_label',1,0,NULL),(425,'mantine_indicator_inline',3,0,NULL),(426,'mantine_indicator_offset',38,0,'{\"options\": [{\"text\": \"0px\", \"value\": \"0\"}, {\"text\": \"2px\", \"value\": \"2\"}, {\"text\": \"4px\", \"value\": \"4\"}, {\"text\": \"6px\", \"value\": \"6\"}, {\"text\": \"8px\", \"value\": \"8\"}, {\"text\": \"10px\", \"value\": \"10\"}, {\"text\": \"12px\", \"value\": \"12\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false, \"placeholder\": \"0\"}'),(427,'mantine_spoiler_show_label',1,1,'{\"placeholder\": \"Enter show label\"}'),(428,'mantine_spoiler_hide_label',1,1,'{\"placeholder\": \"Enter hide label\"}'),(429,'mantine_timeline_bullet_size',38,0,'{\"options\": [{\"text\": \"12px\", \"value\": \"12\"}, {\"text\": \"16px\", \"value\": \"16\"}, {\"text\": \"20px\", \"value\": \"20\"}, {\"text\": \"24px\", \"value\": \"24\"}, {\"text\": \"32px\", \"value\": \"32\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(430,'mantine_timeline_line_width',38,0,'{\"options\": [{\"text\": \"1px\", \"value\": \"1\"}, {\"text\": \"2px\", \"value\": \"2\"}, {\"text\": \"3px\", \"value\": \"3\"}, {\"text\": \"4px\", \"value\": \"4\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(431,'mantine_timeline_active',38,0,'{\"options\": [{\"text\": \"None\", \"value\": \"-1\"}, {\"text\": \"First item\", \"value\": \"0\"}, {\"text\": \"First two items\", \"value\": \"1\"}, {\"text\": \"First three items\", \"value\": \"3\"}, {\"text\": \"First four items\", \"value\": \"4\"}, {\"text\": \"First five items\", \"value\": \"5\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(432,'mantine_timeline_align',42,0,'{\"options\": [{\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Right\", \"value\": \"right\"}]}'),(433,'mantine_timeline_item_bullet',41,0,NULL),(434,'mantine_timeline_item_line_variant',38,0,'{\"options\": [{\"text\": \"Solid\", \"value\": \"solid\"}, {\"text\": \"Dashed\", \"value\": \"dashed\"}, {\"text\": \"Dotted\", \"value\": \"dotted\"}], \"clearable\": false, \"searchable\": false}'),(435,'mantine_code_block',3,0,NULL),(436,'mantine_highlight_highlight',1,1,NULL),(437,'mantine_title_order',42,0,'{\"options\": [{\"text\": \"H1\", \"value\": \"1\"}, {\"text\": \"H2\", \"value\": \"2\"}, {\"text\": \"H3\", \"value\": \"3\"}, {\"text\": \"H4\", \"value\": \"4\"}, {\"text\": \"H5\", \"value\": \"5\"}, {\"text\": \"H6\", \"value\": \"6\"}]}'),(438,'mantine_title_text_wrap',42,0,'{\"options\": [{\"text\": \"Wrap\", \"value\": \"wrap\"}, {\"text\": \"Balance\", \"value\": \"balance\"}, {\"text\": \"No Wrap\", \"value\": \"nowrap\"}]}'),(439,'mantine_title_line_clamp',38,0,'{\"options\": [{\"text\": \"1 line\", \"value\": \"1\"}, {\"text\": \"2 lines\", \"value\": \"2\"}, {\"text\": \"3 lines\", \"value\": \"3\"}, {\"text\": \"4 lines\", \"value\": \"4\"}, {\"text\": \"5 lines\", \"value\": \"5\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"3\"}'),(440,'mantine_list_list_style_type',38,0,'{\"options\": [{\"text\": \"Disc (●)\", \"value\": \"disc\"}, {\"text\": \"Circle (○)\", \"value\": \"circle\"}, {\"text\": \"Square (■)\", \"value\": \"square\"}, {\"text\": \"Decimal (1, 2, 3)\", \"value\": \"decimal\"}, {\"text\": \"Decimal Leading Zero (01, 02, 03)\", \"value\": \"decimal-leading-zero\"}, {\"text\": \"Lower Alpha (a, b, c)\", \"value\": \"lower-alpha\"}, {\"text\": \"Upper Alpha (A, B, C)\", \"value\": \"upper-alpha\"}, {\"text\": \"Lower Roman (i, ii, iii)\", \"value\": \"lower-roman\"}, {\"text\": \"Upper Roman (I, II, III)\", \"value\": \"upper-roman\"}, {\"text\": \"None\", \"value\": \"none\"}]}'),(441,'mantine_list_item_content',2,1,NULL),(442,'mantine_list_with_padding',3,0,NULL),(443,'mantine_list_center',3,0,NULL),(444,'mantine_list_icon',41,0,NULL),(445,'mantine_list_item_icon',41,0,NULL),(446,'mantine_divider_variant',38,0,'{\"options\": [{\"text\": \"Solid\", \"value\": \"solid\"}, {\"text\": \"Dashed\", \"value\": \"dashed\"}, {\"text\": \"Dotted\", \"value\": \"dotted\"}], \"clearable\": false, \"searchable\": false}'),(447,'mantine_divider_label',1,1,'{\"placeholder\": \"Divider label\"}'),(448,'mantine_divider_label_position',38,0,'{\"options\": [{\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Center\", \"value\": \"center\"}, {\"text\": \"Right\", \"value\": \"right\"}], \"clearable\": false, \"searchable\": false}'),(449,'mantine_paper_shadow',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(450,'mantine_scroll_area_scrollbar_size',38,0,'{\"options\": [{\"text\": \"6px\", \"value\": \"6\"}, {\"text\": \"8px\", \"value\": \"8\"}, {\"text\": \"10px\", \"value\": \"10\"}, {\"text\": \"12px\", \"value\": \"12\"}, {\"text\": \"16px\", \"value\": \"16\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false}'),(451,'mantine_scroll_area_type',42,0,'{\"options\": [{\"text\": \"Hover\", \"value\": \"hover\"}, {\"text\": \"Always\", \"value\": \"always\"}, {\"text\": \"Never\", \"value\": \"never\"}]}'),(452,'mantine_scroll_area_offset_scrollbars',3,0,NULL),(453,'mantine_scroll_area_scroll_hide_delay',38,0,'{\"options\": [{\"text\": \"Instant (0ms)\", \"value\": \"0\"}, {\"text\": \"Very Fast (300ms)\", \"value\": \"300\"}, {\"text\": \"Fast (500ms)\", \"value\": \"500\"}, {\"text\": \"Normal (1000ms)\", \"value\": \"1000\"}, {\"text\": \"Slow (1500ms)\", \"value\": \"1500\"}, {\"text\": \"Very Slow (2000ms)\", \"value\": \"2000\"}, {\"text\": \"Extra Slow (3000ms)\", \"value\": \"3000\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"1000\"}'),(454,'mantine_card_shadow',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(455,'mantine_card_padding',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(456,'mantine_text_font_weight',38,0,'{\"options\": [{\"text\": \"Thin (100)\", \"value\": \"100\"}, {\"text\": \"Extra Light (200)\", \"value\": \"200\"}, {\"text\": \"Light (300)\", \"value\": \"300\"}, {\"text\": \"Regular (400)\", \"value\": \"400\"}, {\"text\": \"Medium (500)\", \"value\": \"500\"}, {\"text\": \"Semi Bold (600)\", \"value\": \"600\"}, {\"text\": \"Bold (700)\", \"value\": \"700\"}, {\"text\": \"Extra Bold (800)\", \"value\": \"800\"}, {\"text\": \"Black (900)\", \"value\": \"900\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"400\"}'),(457,'mantine_text_font_style',42,0,'{\"options\": [{\"text\": \"Normal\", \"value\": \"normal\"}, {\"text\": \"Italic\", \"value\": \"italic\"}]}'),(458,'mantine_text_text_decoration',42,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Underline\", \"value\": \"underline\"}, {\"text\": \"Strikethrough\", \"value\": \"line-through\"}]}'),(459,'mantine_text_text_transform',42,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Uppercase\", \"value\": \"uppercase\"}, {\"text\": \"Capitalize\", \"value\": \"capitalize\"}, {\"text\": \"Lowercase\", \"value\": \"lowercase\"}]}'),(460,'mantine_text_align',42,0,'{\"options\": [{\"text\": \"Left\", \"value\": \"left\"}, {\"text\": \"Center\", \"value\": \"center\"}, {\"text\": \"Right\", \"value\": \"right\"}, {\"text\": \"Justify\", \"value\": \"justify\"}]}'),(461,'mantine_text_variant',42,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Gradient\", \"value\": \"gradient\"}]}'),(462,'mantine_text_gradient',8,0,NULL),(463,'mantine_text_truncate',42,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"End\", \"value\": \"end\"}, {\"text\": \"Start\", \"value\": \"start\"}]}'),(464,'mantine_text_line_clamp',38,0,'{\"options\": [{\"text\": \"2 lines\", \"value\": \"2\"}, {\"text\": \"3 lines\", \"value\": \"3\"}, {\"text\": \"4 lines\", \"value\": \"4\"}, {\"text\": \"5 lines\", \"value\": \"5\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"3\"}'),(465,'mantine_text_inherit',3,0,NULL),(466,'mantine_text_span',3,0,NULL),(467,'drag_free',3,0,NULL),(468,'skip_snaps',3,0,NULL),(470,'mantine_carousel_slide_size',40,0,'{\"max\": 100, \"min\": 10, \"step\": 5, \"marks\": [{\"label\": \"25%\", \"value\": 25, \"saveValue\": \"25\"}, {\"label\": \"50%\", \"value\": 50, \"saveValue\": \"50\"}, {\"label\": \"75%\", \"value\": 75, \"saveValue\": \"75\"}, {\"label\": \"100%\", \"value\": 100, \"saveValue\": \"100\"}], \"defaultValue\": 100}'),(471,'mantine_carousel_slide_gap',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(472,'mantine_carousel_controls_offset',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(473,'mantine_carousel_next_control_icon',41,0,NULL),(474,'mantine_carousel_previous_control_icon',41,0,NULL),(475,'mantine_carousel_align',42,0,'{\"options\": [{\"text\": \"Start\", \"value\": \"start\"}, {\"text\": \"Center\", \"value\": \"center\"}, {\"text\": \"End\", \"value\": \"end\"}]}'),(476,'mantine_carousel_contain_scroll',42,0,'{\"options\": [{\"text\": \"Auto\", \"value\": \"auto\"}, {\"text\": \"Trim Snaps\", \"value\": \"trimSnaps\"}, {\"text\": \"Keep Snaps\", \"value\": \"keepSnaps\"}]}'),(477,'mantine_carousel_in_view_threshold',40,0,'{\"max\": 1, \"min\": 0, \"step\": 0.1, \"marks\": [{\"label\": \"0%\", \"value\": 0, \"saveValue\": \"0\"}, {\"label\": \"50%\", \"value\": 0.5, \"saveValue\": \"0.5\"}, {\"label\": \"100%\", \"value\": 1, \"saveValue\": \"1\"}], \"defaultValue\": 0}'),(478,'mantine_carousel_duration',38,0,'{\"options\": [{\"text\": \"Fast (10ms)\", \"value\": \"10\"}, {\"text\": \"Normal (25ms)\", \"value\": \"25\"}, {\"text\": \"Slow (50ms)\", \"value\": \"50\"}, {\"text\": \"Very Slow (100ms)\", \"value\": \"100\"}, {\"text\": \"Extra Slow (150ms)\", \"value\": \"150\"}, {\"text\": \"Super Slow (200ms)\", \"value\": \"200\"}, {\"text\": \"Instant (0ms)\", \"value\": \"0\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"25\"}'),(479,'mantine_carousel_embla_options',8,0,NULL),(480,'mantine_checkbox_icon',41,0,NULL),(481,'mantine_checkbox_labelPosition',42,0,'{\"options\": [{\"text\": \"Right\", \"value\": \"right\"}, {\"text\": \"Left\", \"value\": \"left\"}]}'),(482,'mantine_datepicker_type',42,0,'{\"options\": [{\"text\": \"Date Only\", \"value\": \"date\"}, {\"text\": \"Time Only\", \"value\": \"time\"}, {\"text\": \"Date & Time\", \"value\": \"datetime\"}]}'),(483,'mantine_datepicker_format',38,0,'{\"options\": [{\"text\": \"YYYY-MM-DD\", \"value\": \"YYYY-MM-DD\"}, {\"text\": \"MM/DD/YYYY\", \"value\": \"MM/DD/YYYY\"}, {\"text\": \"DD/MM/YYYY\", \"value\": \"DD/MM/YYYY\"}, {\"text\": \"DD.MM.YYYY\", \"value\": \"DD.MM.YYYY\"}, {\"text\": \"MMM DD, YYYY\", \"value\": \"MMM DD, YYYY\"}, {\"text\": \"DD MMM YYYY\", \"value\": \"DD MMM YYYY\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false, \"placeholder\": \"YYYY-MM-DD\"}'),(484,'mantine_datepicker_locale',1,0,NULL),(485,'mantine_datepicker_placeholder',1,1,NULL),(486,'mantine_datepicker_min_date',1,0,NULL),(487,'mantine_datepicker_max_date',1,0,NULL),(488,'mantine_datepicker_first_day_of_week',42,0,'{\"options\": [{\"text\": \"Su\", \"value\": \"0\"}, {\"text\": \"Mo\", \"value\": \"1\"}, {\"text\": \"Tu\", \"value\": \"2\"}, {\"text\": \"We\", \"value\": \"3\"}, {\"text\": \"Th\", \"value\": \"4\"}, {\"text\": \"Fr\", \"value\": \"5\"}, {\"text\": \"Sa\", \"value\": \"6\"}]}'),(489,'mantine_datepicker_weekend_days',1,0,NULL),(490,'mantine_datepicker_clearable',3,0,NULL),(491,'mantine_datepicker_allow_deseselect',3,0,NULL),(492,'mantine_datepicker_readonly',3,0,NULL),(493,'mantine_datepicker_with_time_grid',3,0,NULL),(494,'mantine_datepicker_consistent_weeks',3,0,NULL),(495,'mantine_datepicker_hide_outside_dates',3,0,NULL),(496,'mantine_datepicker_hide_weekends',3,0,NULL),(497,'mantine_datepicker_time_step',42,0,'{\"options\": [{\"text\": \"1 min\", \"value\": \"1\"}, {\"text\": \"5 min\", \"value\": \"5\"}, {\"text\": \"10 min\", \"value\": \"10\"}, {\"text\": \"15 min\", \"value\": \"15\"}, {\"text\": \"30 min\", \"value\": \"30\"}, {\"text\": \"1 hour\", \"value\": \"60\"}]}'),(498,'mantine_datepicker_time_format',42,0,'{\"options\": [{\"text\": \"12-hour\", \"value\": \"12\"}, {\"text\": \"24-hour\", \"value\": \"24\"}]}'),(499,'mantine_datepicker_date_format',38,0,'{\"options\": [{\"text\": \"YYYY-MM-DD\", \"value\": \"YYYY-MM-DD\"}, {\"text\": \"MM/DD/YYYY\", \"value\": \"MM/DD/YYYY\"}, {\"text\": \"DD/MM/YYYY\", \"value\": \"DD/MM/YYYY\"}, {\"text\": \"DD.MM.YYYY\", \"value\": \"DD.MM.YYYY\"}, {\"text\": \"MMM DD, YYYY\", \"value\": \"MMM DD, YYYY\"}, {\"text\": \"DD MMM YYYY\", \"value\": \"DD MMM YYYY\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false, \"placeholder\": \"YYYY-MM-DD\"}'),(500,'mantine_datepicker_time_grid_config',2,0,NULL),(501,'mantine_datepicker_with_seconds',3,0,NULL),(502,'mantine_text_input_variant',42,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Unstyled\", \"value\": \"unstyled\"}]}'),(503,'mantine_textarea_autosize',3,0,NULL),(504,'mantine_textarea_min_rows',38,0,'{\"options\": [{\"text\": \"1\", \"value\": \"1\"}, {\"text\": \"2\", \"value\": \"2\"}, {\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"3\"}'),(505,'mantine_textarea_max_rows',38,0,'{\"options\": [{\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"10\", \"value\": \"10\"}, {\"text\": \"15\", \"value\": \"15\"}, {\"text\": \"20\", \"value\": \"20\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"8\"}'),(506,'mantine_textarea_resize',42,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Vertical\", \"value\": \"vertical\"}, {\"text\": \"Both\", \"value\": \"both\"}]}'),(507,'mantine_textarea_variant',42,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Unstyled\", \"value\": \"unstyled\"}]}'),(508,'mantine_rich_text_editor_variant',42,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Subtle\", \"value\": \"subtle\"}]}'),(509,'mantine_rich_text_editor_placeholder',1,1,NULL),(510,'mantine_rich_text_editor_bubble_menu',3,0,NULL),(511,'mantine_rich_text_editor_text_color',3,0,NULL),(512,'mantine_rich_text_editor_task_list',3,0,NULL),(513,'mantine_buttons_size',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(514,'mantine_buttons_radius',40,0,'{\"options\": [{\"text\": \"None\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}]}'),(515,'mantine_buttons_variant',38,0,'{\"options\": [{\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Light\", \"value\": \"light\"}, {\"text\": \"Outline\", \"value\": \"outline\"}, {\"text\": \"Transparent\", \"value\": \"transparent\"}, {\"text\": \"White\", \"value\": \"white\"}, {\"text\": \"Subtle\", \"value\": \"subtle\"}, {\"text\": \"Gradient\", \"value\": \"gradient\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"filled\"}'),(516,'mantine_buttons_position',38,0,'{\"options\": [{\"text\": \"Justified (based on button order)\", \"value\": \"space-between\"}, {\"text\": \"Centered\", \"value\": \"center\"}, {\"text\": \"Right aligned\", \"value\": \"flex-end\"}, {\"text\": \"Left aligned\", \"value\": \"flex-start\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"space-between\"}'),(517,'mantine_buttons_order',42,0,'{\"options\": [{\"text\": \"Save → Cancel\", \"value\": \"save-cancel\"}, {\"text\": \"Cancel → Save\", \"value\": \"cancel-save\"}]}'),(518,'btn_save_label',1,1,NULL),(519,'btn_update_label',1,1,NULL),(520,'btn_cancel_label',1,1,NULL),(521,'mantine_btn_save_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(522,'mantine_btn_cancel_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(523,'mantine_btn_update_color',39,0,'{\"options\": [{\"text\": \"Gray\", \"value\": \"gray\"}, {\"text\": \"Red\", \"value\": \"red\"}, {\"text\": \"Grape\", \"value\": \"grape\"}, {\"text\": \"Violet\", \"value\": \"violet\"}, {\"text\": \"Blue\", \"value\": \"blue\"}, {\"text\": \"Cyan\", \"value\": \"cyan\"}, {\"text\": \"Green\", \"value\": \"green\"}, {\"text\": \"Lime\", \"value\": \"lime\"}, {\"text\": \"Yellow\", \"value\": \"yellow\"}, {\"text\": \"Orange\", \"value\": \"orange\"}]}'),(524,'redirect_at_end',25,0,NULL),(525,'btn_cancel_url',25,0,NULL),(526,'alert_error',2,1,NULL),(527,'html_tag_content',2,1,'{\"description\": \"Content to display inside the HTML tag\", \"placeholder\": \"Enter HTML content or text\"}'),(528,'html_tag',38,0,'{\"options\": [{\"text\": \"<div> - Division\", \"value\": \"div\"}, {\"text\": \"<span> - Inline Container\", \"value\": \"span\"}, {\"text\": \"<p> - Paragraph\", \"value\": \"p\"}, {\"text\": \"<h1> - Heading 1\", \"value\": \"h1\"}, {\"text\": \"<h2> - Heading 2\", \"value\": \"h2\"}, {\"text\": \"<h3> - Heading 3\", \"value\": \"h3\"}, {\"text\": \"<h4> - Heading 4\", \"value\": \"h4\"}, {\"text\": \"<h5> - Heading 5\", \"value\": \"h5\"}, {\"text\": \"<h6> - Heading 6\", \"value\": \"h6\"}, {\"text\": \"<section> - Section\", \"value\": \"section\"}, {\"text\": \"<article> - Article\", \"value\": \"article\"}, {\"text\": \"<aside> - Aside\", \"value\": \"aside\"}, {\"text\": \"<header> - Header\", \"value\": \"header\"}, {\"text\": \"<footer> - Footer\", \"value\": \"footer\"}, {\"text\": \"<nav> - Navigation\", \"value\": \"nav\"}, {\"text\": \"<main> - Main Content\", \"value\": \"main\"}, {\"text\": \"<ul> - Unordered List\", \"value\": \"ul\"}, {\"text\": \"<ol> - Ordered List\", \"value\": \"ol\"}, {\"text\": \"<li> - List Item\", \"value\": \"li\"}, {\"text\": \"<dl> - Description List\", \"value\": \"dl\"}, {\"text\": \"<dt> - Description Term\", \"value\": \"dt\"}, {\"text\": \"<dd> - Description Definition\", \"value\": \"dd\"}, {\"text\": \"<blockquote> - Blockquote\", \"value\": \"blockquote\"}, {\"text\": \"<pre> - Preformatted Text\", \"value\": \"pre\"}, {\"text\": \"<code> - Code\", \"value\": \"code\"}, {\"text\": \"<em> - Emphasis\", \"value\": \"em\"}, {\"text\": \"<strong> - Strong Emphasis\", \"value\": \"strong\"}, {\"text\": \"<b> - Bold\", \"value\": \"b\"}, {\"text\": \"<i> - Italic\", \"value\": \"i\"}, {\"text\": \"<u> - Underline\", \"value\": \"u\"}, {\"text\": \"<mark> - Highlight\", \"value\": \"mark\"}, {\"text\": \"<small> - Small Text\", \"value\": \"small\"}, {\"text\": \"<sup> - Superscript\", \"value\": \"sup\"}, {\"text\": \"<sub> - Subscript\", \"value\": \"sub\"}, {\"text\": \"<cite> - Citation\", \"value\": \"cite\"}, {\"text\": \"<q> - Quote\", \"value\": \"q\"}, {\"text\": \"<abbr> - Abbreviation\", \"value\": \"abbr\"}, {\"text\": \"<dfn> - Definition\", \"value\": \"dfn\"}, {\"text\": \"<time> - Time\", \"value\": \"time\"}, {\"text\": \"<var> - Variable\", \"value\": \"var\"}, {\"text\": \"<samp> - Sample Output\", \"value\": \"samp\"}, {\"text\": \"<kbd> - Keyboard Input\", \"value\": \"kbd\"}, {\"text\": \"<address> - Address\", \"value\": \"address\"}, {\"text\": \"<del> - Deleted Text\", \"value\": \"del\"}, {\"text\": \"<ins> - Inserted Text\", \"value\": \"ins\"}, {\"text\": \"<s> - Strikethrough\", \"value\": \"s\"}, {\"text\": \"<figure> - Figure\", \"value\": \"figure\"}, {\"text\": \"<figcaption> - Figure Caption\", \"value\": \"figcaption\"}, {\"text\": \"<table> - Table\", \"value\": \"table\"}, {\"text\": \"<thead> - Table Head\", \"value\": \"thead\"}, {\"text\": \"<tbody> - Table Body\", \"value\": \"tbody\"}, {\"text\": \"<tfoot> - Table Foot\", \"value\": \"tfoot\"}, {\"text\": \"<tr> - Table Row\", \"value\": \"tr\"}, {\"text\": \"<th> - Table Header\", \"value\": \"th\"}, {\"text\": \"<td> - Table Cell\", \"value\": \"td\"}, {\"text\": \"<caption> - Table Caption\", \"value\": \"caption\"}, {\"text\": \"<colgroup> - Table Column Group\", \"value\": \"colgroup\"}, {\"text\": \"<col> - Table Column\", \"value\": \"col\"}, {\"text\": \"<fieldset> - Fieldset\", \"value\": \"fieldset\"}, {\"text\": \"<legend> - Legend\", \"value\": \"legend\"}, {\"text\": \"<label> - Label\", \"value\": \"label\"}, {\"text\": \"<button> - Button\", \"value\": \"button\"}, {\"text\": \"<output> - Output\", \"value\": \"output\"}, {\"text\": \"<meter> - Meter\", \"value\": \"meter\"}, {\"text\": \"<details> - Details\", \"value\": \"details\"}, {\"text\": \"<summary> - Summary\", \"value\": \"summary\"}, {\"text\": \"<dialog> - Dialog\", \"value\": \"dialog\"}, {\"text\": \"<canvas> - Canvas\", \"value\": \"canvas\"}, {\"text\": \"<svg> - SVG\", \"value\": \"svg\"}, {\"text\": \"<picture> - Picture\", \"value\": \"picture\"}, {\"text\": \"<img> - Image\", \"value\": \"img\"}, {\"text\": \"<a> - Link\", \"value\": \"a\"}], \"clearable\": false, \"creatable\": false, \"searchable\": true, \"placeholder\": \"div\"}'),(529,'profile_title',1,1,'{\"description\": \"Main title for the profile section\", \"placeholder\": \"My Profile\"}'),(530,'profile_label_email',1,1,'{\"description\": \"Label for email field display\", \"placeholder\": \"Email\"}'),(531,'profile_label_username',1,1,'{\"description\": \"Label for username field display\", \"placeholder\": \"Username\"}'),(532,'profile_label_name',1,1,'{\"description\": \"Label for full name field display\", \"placeholder\": \"Full Name\"}'),(533,'profile_label_created',1,1,'{\"description\": \"Label for account creation date\", \"placeholder\": \"Account Created\"}'),(534,'profile_label_last_login',1,1,'{\"description\": \"Label for last login date\", \"placeholder\": \"Last Login\"}'),(535,'profile_label_timezone',1,1,'{\"description\": \"Label for timezone selection\", \"placeholder\": \"Timezone\"}'),(536,'timezone',35,1,'{\"required\": true, \"clearable\": false, \"creatable\": false, \"searchable\": true, \"placeholder\": \"Select your timezone\"}'),(537,'profile_account_info_title',1,1,'{\"description\": \"Title for the account information section\", \"placeholder\": \"Account Information\"}'),(538,'profile_name_change_title',1,1,'{\"description\": \"Title for name change section\", \"placeholder\": \"Change Display Name\"}'),(539,'profile_name_change_description',2,1,'{\"description\": \"Description explaining name change functionality\", \"placeholder\": \"<p>Update your display name. This will be visible to other users.</p>\"}'),(540,'profile_name_change_label',1,1,'{\"description\": \"Label for new name input field\", \"placeholder\": \"New Display Name\"}'),(541,'profile_name_change_placeholder',1,1,'{\"description\": \"Placeholder text for name input\", \"placeholder\": \"Enter new display name\"}'),(542,'profile_name_change_button',1,1,'{\"description\": \"Text for the name change button\", \"placeholder\": \"Update Display Name\"}'),(543,'profile_name_change_success',1,1,'{\"description\": \"Success message for name change\", \"placeholder\": \"Display name updated successfully!\"}'),(544,'profile_name_change_error_required',1,1,'{\"description\": \"Error message when name is empty\", \"placeholder\": \"Display name is required\"}'),(545,'profile_name_change_error_invalid',1,1,'{\"description\": \"Error message for invalid name format\", \"placeholder\": \"Display name contains invalid characters\"}'),(546,'profile_name_change_error_general',1,1,'{\"description\": \"General error message for name change failures\", \"placeholder\": \"Failed to update display name. Please try again.\"}'),(547,'profile_password_reset_title',1,1,'{\"description\": \"Title for password reset section\", \"placeholder\": \"Change Password\"}'),(548,'profile_password_reset_description',2,1,'{\"description\": \"Description explaining password reset functionality\", \"placeholder\": \"<p>Set a new password for your account. Make sure it is strong and secure.</p>\"}'),(549,'profile_password_reset_label_current',1,1,'{\"description\": \"Label for current password input field\", \"placeholder\": \"Current Password\"}'),(550,'profile_password_reset_label_new',1,1,'{\"description\": \"Label for new password input field\", \"placeholder\": \"New Password\"}'),(551,'profile_password_reset_label_confirm',1,1,'{\"description\": \"Label for password confirmation input field\", \"placeholder\": \"Confirm New Password\"}'),(552,'profile_password_reset_placeholder_current',1,1,'{\"description\": \"Placeholder for current password input\", \"placeholder\": \"Enter current password\"}'),(553,'profile_password_reset_placeholder_new',1,1,'{\"description\": \"Placeholder for new password input\", \"placeholder\": \"Enter new password\"}'),(554,'profile_password_reset_placeholder_confirm',1,1,'{\"description\": \"Placeholder for password confirmation input\", \"placeholder\": \"Confirm new password\"}'),(555,'profile_password_reset_button',1,1,'{\"description\": \"Text for the password reset button\", \"placeholder\": \"Update Password\"}'),(556,'profile_password_reset_success',1,1,'{\"description\": \"Success message for password change\", \"placeholder\": \"Password updated successfully!\"}'),(557,'profile_password_reset_error_current_required',1,1,'{\"description\": \"Error message when current password is empty\", \"placeholder\": \"Current password is required\"}'),(558,'profile_password_reset_error_current_wrong',1,1,'{\"description\": \"Error message when current password is wrong\", \"placeholder\": \"Current password is incorrect\"}'),(559,'profile_password_reset_error_new_required',1,1,'{\"description\": \"Error message when new password is empty\", \"placeholder\": \"New password is required\"}'),(560,'profile_password_reset_error_confirm_required',1,1,'{\"description\": \"Error message when confirmation password is empty\", \"placeholder\": \"Password confirmation is required\"}'),(561,'profile_password_reset_error_mismatch',1,1,'{\"description\": \"Error message when passwords don\'t match\", \"placeholder\": \"New passwords do not match\"}'),(562,'profile_password_reset_error_weak',1,1,'{\"description\": \"Error message for weak password\", \"placeholder\": \"Password is too weak. Please choose a stronger password.\"}'),(563,'profile_password_reset_error_general',1,1,'{\"description\": \"General error message for password change failures\", \"placeholder\": \"Failed to update password. Please try again.\"}'),(564,'profile_delete_title',1,1,'{\"description\": \"Title for account deletion section\", \"placeholder\": \"Delete Account\"}'),(565,'profile_delete_description',2,1,'{\"description\": \"Warning description for account deletion\", \"placeholder\": \"<p>Permanently delete your account and all associated data. This action cannot be undone.</p>\"}'),(566,'profile_delete_alert_text',1,1,'{\"description\": \"Warning text in the delete account alert\", \"placeholder\": \"This action cannot be undone. All your data will be permanently deleted.\"}'),(567,'profile_delete_modal_warning',2,1,'{\"description\": \"Detailed warning text in the delete account modal\", \"placeholder\": \"Deleting your account will permanently remove all your data, including profile information, preferences, and any content you have created.\"}'),(568,'profile_delete_label_email',1,1,'{\"description\": \"Label for email confirmation field\", \"placeholder\": \"Confirm Email\"}'),(569,'profile_delete_placeholder_email',1,1,'{\"description\": \"Placeholder for email confirmation input\", \"placeholder\": \"Enter your email to confirm\"}'),(570,'profile_delete_button',1,1,'{\"description\": \"Text for the account deletion button\", \"placeholder\": \"Delete Account\"}'),(571,'profile_delete_success',1,1,'{\"description\": \"Success message for account deletion\", \"placeholder\": \"Account deleted successfully.\"}'),(572,'profile_delete_error_email_required',1,1,'{\"description\": \"Error message when email is empty\", \"placeholder\": \"Email confirmation is required\"}'),(573,'profile_delete_error_email_mismatch',1,1,'{\"description\": \"Error message when email doesn\'t match\", \"placeholder\": \"Email does not match your account email\"}'),(574,'profile_delete_error_general',1,1,'{\"description\": \"General error message for account deletion failures\", \"placeholder\": \"Failed to delete account. Please try again.\"}'),(575,'profile_gap',38,0,'{\"options\": [{\"text\": \"Extra Small (8px)\", \"value\": \"xs\"}, {\"text\": \"Small (12px)\", \"value\": \"sm\"}, {\"text\": \"Medium (16px)\", \"value\": \"md\"}, {\"text\": \"Large (20px)\", \"value\": \"lg\"}, {\"text\": \"Extra Large (24px)\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"md\"}'),(576,'profile_use_accordion',3,0,NULL),(577,'profile_accordion_multiple',3,0,NULL),(578,'profile_accordion_default_opened',38,0,'{\"options\": [{\"text\": \"User Information\", \"value\": \"user_info\"}, {\"text\": \"Change Username\", \"value\": \"username_change\"}, {\"text\": \"Change Password\", \"value\": \"password_reset\"}, {\"text\": \"Delete Account\", \"value\": \"account_delete\"}], \"clearable\": true, \"searchable\": false, \"multiSelect\": true, \"placeholder\": \"Select sections to open by default\"}'),(579,'profile_variant',38,0,'{\"options\": [{\"text\": \"Default\", \"value\": \"default\"}, {\"text\": \"Filled\", \"value\": \"filled\"}, {\"text\": \"Outline\", \"value\": \"outline\"}, {\"text\": \"Light\", \"value\": \"light\"}, {\"text\": \"Subtle\", \"value\": \"subtle\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"default\"}'),(580,'profile_radius',40,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": true, \"searchable\": false, \"placeholder\": \"sm\"}'),(581,'profile_shadow',40,0,'{\"options\": [{\"text\": \"No Shadow\", \"value\": \"none\"}, {\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"creatable\": false, \"searchable\": false, \"placeholder\": \"none\"}'),(582,'profile_columns',38,0,'{\"options\": [{\"text\": \"1 Column\", \"value\": \"1\"}, {\"text\": \"2 Columns\", \"value\": \"2\"}, {\"text\": \"3 Columns\", \"value\": \"3\"}, {\"text\": \"4 Columns\", \"value\": \"4\"}], \"clearable\": false, \"creatable\": false, \"searchable\": false, \"placeholder\": \"1\"}'),(583,'label_timezone',1,1,'{\"placeholder\": \"Timezone\"}'),(584,'label_save',1,1,'{\"placeholder\": \"Save\"}'),(585,'label_update',1,1,'{\"placeholder\": \"Update\"}'),(586,'cancel_url',25,0,NULL),(587,'ajax',3,0,NULL),(604,'mantine_checkbox_checked',3,0,NULL),(605,'mantine_checkbox_indeterminate',3,0,NULL),(606,'mantine_textarea_rows',38,0,'{\"options\": [{\"text\": \"3\", \"value\": \"3\"}, {\"text\": \"4\", \"value\": \"4\"}, {\"text\": \"5\", \"value\": \"5\"}, {\"text\": \"6\", \"value\": \"6\"}, {\"text\": \"8\", \"value\": \"8\"}, {\"text\": \"10\", \"value\": \"10\"}], \"clearable\": false, \"creatable\": true, \"searchable\": false}'),(608,'mantine_select_searchable',3,0,NULL),(609,'mantine_select_clearable',3,0,NULL),(611,'mantine_alert_with_close_button',3,0,NULL),(616,'mantine_image_src',1,1,'{\"placeholder\": \"Image URL or path\"}'),(617,'mantine_image_alt',1,1,'{\"placeholder\": \"Alt text for accessibility\"}'),(619,'mantine_list_type',42,0,'{\"options\": [{\"text\": \"Unordered\", \"value\": \"unordered\"}, {\"text\": \"Ordered\", \"value\": \"ordered\"}]}'),(620,'mantine_list_spacing',38,0,'{\"options\": [{\"text\": \"Extra Small\", \"value\": \"xs\"}, {\"text\": \"Small\", \"value\": \"sm\"}, {\"text\": \"Medium\", \"value\": \"md\"}, {\"text\": \"Large\", \"value\": \"lg\"}, {\"text\": \"Extra Large\", \"value\": \"xl\"}], \"clearable\": true, \"searchable\": false}');
/*!40000 ALTER TABLE `fields` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fieldType`
--

LOCK TABLES `fieldType` WRITE;
/*!40000 ALTER TABLE `fieldType` DISABLE KEYS */;
INSERT INTO `fieldType` VALUES (1,'text',10),(2,'textarea',30),(3,'checkbox',0),(4,'markdown',40),(5,'number',50),(7,'markdown-inline',20),(8,'json',45),(9,'style-bootstrap',5),(10,'type-input',4),(11,'email',90),(12,'code',42),(13,'date',25),(14,'time',24),(16,'anchor-section',14),(17,'select-group',7),(20,'select-data_table',8),(22,'condition',6),(23,'data-config',7),(25,'select-page-keyword',9),(26,'css',15),(29,'color',9),(30,'html-tag',5),(32,'select-css',8),(34,'select-language',8),(35,'select-timezone',8),(36,'select-image',8),(37,'select-video',8),(38,'select',1),(39,'color-picker',2),(40,'slider',3),(41,'select-icon',4),(42,'segment',5),(45,'mantine_spacing_margin',0),(46,'mantine_spacing_margin_padding',0);
/*!40000 ALTER TABLE `fieldType` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `groups`
--

LOCK TABLES `groups` WRITE;
/*!40000 ALTER TABLE `groups` DISABLE KEYS */;
INSERT INTO `groups` VALUES (1,'admin','full access',70,0),(2,'therapist','access to home, legal, profile, experiment, manage experiment',70,0),(3,'subject','access to home, legal, profile, experiment',70,0);
/*!40000 ALTER TABLE `groups` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `hooks`
--

LOCK TABLES `hooks` WRITE;
/*!40000 ALTER TABLE `hooks` DISABLE KEYS */;
/*!40000 ALTER TABLE `hooks` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` VALUES (1,'all','Independent',','),(2,'de-CH','Deutsch (Schweiz)',','),(3,'en-GB','English (GB)',',');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `libraries`
--

LOCK TABLES `libraries` WRITE;
/*!40000 ALTER TABLE `libraries` DISABLE KEYS */;
INSERT INTO `libraries` VALUES (1,'[Altorouter](https://github.com/dannyvankooten/AltoRouter)','1.2.0','[MIT](https://tldrlegal.com/license/mit-license)','[License Details](https://github.com/dannyvankooten/AltoRouter?tab=MIT-1-ov-file#readme)'),(2,'[Autosize](https://github.com/jackmoore/autosize)','1.1.6','[MIT](https://tldrlegal.com/license/mit-license)',''),(3,'[Bootstrap](https://getbootstrap.com/)','4.4.1','[MIT](https://tldrlegal.com/license/mit-license)','[Browser Support](https://getbootstrap.com/docs/4.4/getting-started/browsers-devices/), [License Details](https://getbootstrap.com/docs/4.4/about/license/)'),(4,'[Datatables](https://datatables.net/)','1.10.18','[MIT](https://tldrlegal.com/license/mit-license)','[License Details](https://datatables.net/license/)'),(5,'[Deepmerge](https://github.com/TehShrike/deepmerge)','4.2.2','[MIT](https://tldrlegal.com/license/mit-license)',''),(6,'[Font Awesome](https://fontawesome.com/)','5.2.0','Code: [MIT](https://tldrlegal.com/license/mit-license), Icons: [CC](https://creativecommons.org/licenses/by/4.0/), Fonts: [OFL](https://scripts.sil.org/cms/scripts/page.php?site_id=nrsi&id=OFL)','[Browser Support](https://fontawesome.com/how-to-use/on-the-web/other-topics/browser-support), [License Details](https://fontawesome.com/license/free)'),(7,'[GUMP](https://github.com/Wixel/GUMP.git)','1.5.6','[MIT](https://tldrlegal.com/license/mit-license)',''),(8,'[jQuery](https://jquery.com/)','3.3.1','[MIT](https://tldrlegal.com/license/mit-license)','[Browser Support](https://jquery.com/browser-support/), [License Details](https://jquery.org/license/)'),(9,'[JsonLogic](https://github.com/jwadhams/json-logic-php/)','1.3.10','[MIT](https://tldrlegal.com/license/mit-license)',''),(10,'[mermaid](https://mermaidjs.github.io/)','8.2.3','[MIT](https://tldrlegal.com/license/mit-license)',''),(11,'[Parsedown](https://github.com/erusev/parsedown)','1.7.1','[MIT](https://tldrlegal.com/license/mit-license)',''),(12,'[PHPMailer](https://github.com/PHPMailer/PHPMailer)','6.0.7','[LGPL](https://tldrlegal.com/license/gnu-lesser-general-public-license-v2.1-(lgpl-2.1))','[License Details](https://github.com/PHPMailer/PHPMailer#license)'),(13,'[Plotly.js](https://plotly.com/javascript)','1.52.3','[MIT](https://tldrlegal.com/license/mit-license)',''),(14,'[ResizeSensor](https://github.com/marcj/css-element-queries)','1.2.2','[MIT](https://tldrlegal.com/license/mit-license)',''),(15,'[Monaco Editor](https://github.com/microsoft/monaco-editor)','0.33.0','[MIT](https://tldrlegal.com/license/mit-license)',''),(16,'[EasyMDE](https://github.com/ionaru/easy-markdown-editor)','2.16.1','[MIT](https://tldrlegal.com/license/mit-license)',''),(17,'[Flatpickr](https://github.com/flatpickr/flatpickr)','4.6.13','[MIT](https://tldrlegal.com/license/mit-license)',''),(18,'[Html2pdf](https://github.com/eKoopmans/html2pdf.js)','0.9.2','[MIT](https://tldrlegal.com/license/mit-license)',''),(19,'[Iscroll](https://github.com/cubiq/iscroll)','4.2.5','[MIT](https://tldrlegal.com/license/mit-license)',''),(20,'[jQuery QueryBuilder](https://github.com/mistic100/jQuery-QueryBuilder)','2.6.0','[MIT](https://tldrlegal.com/license/mit-license)',''),(21,'[jQueryConfirm](https://craftpip.github.io/jquery-confirm/)','3.3.4','[MIT](https://tldrlegal.com/license/mit-license)',''),(22,'[PHP-fcm)](https://github.com/EdwinHoksberg/php-fcm)','1.2.0','[MIT](https://tldrlegal.com/license/mit-license)',''),(23,'[Sortable](https://rubaxa.github.io/Sortable/)','1.7.0','[MIT](https://tldrlegal.com/license/mit-license)','');
/*!40000 ALTER TABLE `libraries` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `logPerformance`
--

LOCK TABLES `logPerformance` WRITE;
/*!40000 ALTER TABLE `logPerformance` DISABLE KEYS */;
/*!40000 ALTER TABLE `logPerformance` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lookups`
--

LOCK TABLES `lookups` WRITE;
/*!40000 ALTER TABLE `lookups` DISABLE KEYS */;
INSERT INTO `lookups` VALUES (1,'notificationTypes','email','Email','The notification will be sent by email'),(2,'actionScheduleTypes','immediately','Immediately','Shcedule and send the mail immediately'),(3,'actionScheduleTypes','on_fixed_datetime','On specific fixed datetime','Shcedule and send the mail on specific fixed datetime'),(4,'actionScheduleTypes','after_period','After time period','Schedule the mail after specific time period'),(5,'actionScheduleTypes','after_period_on_day_at_time','After time period on a weekday at given time','Schedule the mail after specific time on specific day from the week at specific time'),(8,'actionTriggerTypes','started','Started','When the user saved data is saved with status `started`'),(9,'actionTriggerTypes','finished','Finished','When the user saved data is saved with status `finished`'),(11,'actionScheduleJobs','nothing','Nothing','Nothing to be scheduled'),(12,'actionScheduleJobs','notification','Notification','Shcedule a notification eamil'),(13,'actionScheduleJobs','reminder','Reminder','Schedule a reminder email. If the survey was done the remider is canceled'),(14,'timePeriod','seconds','Second(s)','Second(s)'),(15,'timePeriod','minutes','Minute(s)','Minute(s)'),(16,'timePeriod','hours','Hour(s)','Hour(s)'),(17,'timePeriod','days','Day(s)','Day(s)'),(18,'timePeriod','weeks','Week(s)','Week(s)'),(19,'timePeriod','months','Month(s)','Month(s)'),(20,'weekdays','monday','Monday','Monday'),(21,'weekdays','tuesday','Tuesday','Tuesday'),(22,'weekdays','wednesday','Wednesday','Wednesday'),(23,'weekdays','thursday','Thursday','Thursday'),(24,'weekdays','friday','Friday','Friday'),(25,'weekdays','saturday','Saturday','Saturday'),(26,'weekdays','sunday','Sunday','Sunday'),(27,'scheduledJobsStatus','queued','Queued','Status for initialization. When the job is queued it goes in this status'),(28,'scheduledJobsStatus','deleted','Deleted','When the job is deleted'),(29,'scheduledJobsStatus','done','Done','Job was executed successfully'),(30,'scheduledJobsStatus','failed','Failed','When something happened and the job failed'),(31,'scheduledJobsSearchDateTypes','date_create','Entry date','The date that the queue record was created'),(32,'scheduledJobsSearchDateTypes','date_to_be_executed','Date to be executed','The date when the queue record should be executed'),(33,'scheduledJobsSearchDateTypes','date_executed','Execution date','The date when the queue record was executed'),(34,'transactionTypes','insert','Add new entry','Add new entry to a table'),(35,'transactionTypes','select','View entry','View entry from a table'),(36,'transactionTypes','update','Edit entry','Edit entry from a table'),(37,'transactionTypes','delete','Delete entry','Delete entry from a table'),(38,'transactionTypes','send_mail_ok','Send mail successfully','Send mail successfully'),(39,'transactionTypes','send_mail_fail','Send mail failed','Send mail failed'),(40,'transactionTypes','check_scheduledJobs','Check scheduled jobs','Check scheduled hobs and execute if there are any'),(41,'transactionBy','by_cron_job','By cron job','The action was executed by cron job'),(42,'transactionBy','by_user','By user','The action was done by an user'),(50,'transactionBy','by_system','By Selfhelp','By Selfhelp'),(51,'notificationTypes','push_notification','Push Notification','The notification will be sent by a push message. It works only for mobile devices!'),(52,'transactionTypes','send_notification_ok','Send notification successfully','Send notification successfully'),(53,'transactionTypes','send_notification_fail','Send notification failed','Send notification failed'),(54,'jobTypes','email','Email','Email sending job'),(55,'jobTypes','notification','Notification','Push notification job'),(56,'jobTypes','task','Task','Custom task execution'),(57,'transactionBy','by_anonymous_user','By anonymous user','The action was done by an anonymous user'),(58,'transactionTypes','status_change','Status changed','Status change'),(59,'actionScheduleJobs','task','Task','Schedule'),(60,'transactionTypes','execute_task_ok','Execute task successfully','Execute task successfully'),(61,'transactionTypes','execute_task_fail','Execute task failed','Execute task failed'),(62,'pageAccessTypes','mobile','Mobile','The page will be loaded only for mobile apps'),(63,'pageAccessTypes','web','Web','The page will be loaded only for the website'),(64,'pageAccessTypes','mobile_and_web','Mobile and web','The page will be loaded for web and mobile'),(65,'hookTypes','hook_overwrite_return','Overwrite return value','On execution it overwrites the return value of the function'),(66,'hookTypes','hook_on_function_execute','On function execute','On function execute trigger event and we can execute another hook function'),(67,'assetTypes','css','CSS','A CSS file that will be used for custom styling'),(68,'assetTypes','asset','Asset','Files that can be used as assets. All uploaded files are public and everyone can have access to them'),(69,'assetTypes','static','Static','Static files which are uploaded in the static table'),(70,'groupTypes','db_role','DB Role','In the DB role we can set up multiple types of access. It is used for specific custom roles'),(71,'groupTypes','group','Group','The group type has only `select` privileges and it is used for access to pages and condition checks'),(72,'userTypes','user','User','All default users'),(73,'actionTriggerTypes','updated','Updated','When the user saved data is saved with status `updated`'),(74,'actionTriggerTypes','deleted','Deleted','When the user saved data is saved with status `deleted`'),(75,'userStatus','interested','interested','This user has shown interest in the platform but has not yet met the preconditions to be invited.'),(76,'userStatus','invited','invited','This user was invited to join the platform but has not yet validated the email address.'),(77,'userStatus','active','active','This user can log in and visit all accessible pages.'),(78,'userStatus','auto_created','auto_created','This user was auto created. The user has only code and cannot login. If the real user register later with the code the user will be activated to normal user.'),(83,'resourceTypes','group','Group','User groups for data access control'),(84,'resourceTypes','data_table','Data Table','Custom data tables'),(85,'resourceTypes','pages','Pages','Admin pages access control'),(86,'auditActions','filter','Filter','Data filtering applied to READ operations'),(87,'auditActions','create','Create','Permission check for CREATE operations'),(88,'auditActions','read','Read','Permission check for specific READ operations'),(89,'auditActions','update','Update','Permission check for UPDATE operations'),(90,'auditActions','delete','Delete','Permission check for DELETE operations'),(91,'permissionResults','granted','Granted','Permission was granted'),(92,'permissionResults','denied','Denied','Permission was denied'),(93,'timezones','America/New_York','Eastern Time (ET)','Eastern Time Zone - UTC-5/-4'),(94,'timezones','America/Chicago','Central Time (CT)','Central Time Zone - UTC-6/-5'),(95,'timezones','America/Denver','Mountain Time (MT)','Mountain Time Zone - UTC-7/-6'),(96,'timezones','America/Phoenix','Mountain Time (MST)','Mountain Time Zone (no DST) - UTC-7'),(97,'timezones','America/Los_Angeles','Pacific Time (PT)','Pacific Time Zone - UTC-8/-7'),(98,'timezones','America/Anchorage','Alaska Time (AKT)','Alaska Time Zone - UTC-9/-8'),(99,'timezones','America/Juneau','Alaska Time (AKT)','Alaska Time Zone - UTC-9/-8'),(100,'timezones','Pacific/Honolulu','Hawaii Time (HT)','Hawaii Time Zone - UTC-10'),(101,'timezones','America/Halifax','Atlantic Time (AT)','Atlantic Time Zone - UTC-4/-3'),(102,'timezones','America/St_Johns','Newfoundland Time (NT)','Newfoundland Time Zone - UTC-3:30/-2:30'),(103,'timezones','America/Regina','Central Time (CT)','Central Time Zone (no DST) - UTC-6'),(104,'timezones','America/Winnipeg','Central Time (CT)','Central Time Zone - UTC-6/-5'),(105,'timezones','America/Toronto','Eastern Time (ET)','Eastern Time Zone - UTC-5/-4'),(106,'timezones','America/Vancouver','Pacific Time (PT)','Pacific Time Zone - UTC-8/-7'),(107,'timezones','America/Edmonton','Mountain Time (MT)','Mountain Time Zone - UTC-7/-6'),(108,'timezones','America/Sao_Paulo','Brasília Time (BRT)','Brasília Time - UTC-3/-2'),(109,'timezones','America/Buenos_Aires','Argentina Time (ART)','Argentina Time - UTC-3'),(110,'timezones','America/Lima','Peru Time (PET)','Peru Time - UTC-5'),(111,'timezones','America/Bogota','Colombia Time (COT)','Colombia Time - UTC-5'),(112,'timezones','America/Caracas','Venezuelan Time (VET)','Venezuelan Time - UTC-4'),(113,'timezones','America/Santiago','Chile Time (CLT)','Chile Time - UTC-4/-3'),(114,'timezones','America/Mexico_City','Central Time (CT)','Central Time Zone - UTC-6/-5'),(115,'timezones','Europe/London','Greenwich Mean Time (GMT)','Greenwich Mean Time - UTC+0/+1'),(116,'timezones','Europe/Berlin','Central European Time (CET)','Central European Time - UTC+1/+2'),(117,'timezones','Europe/Paris','Central European Time (CET)','Central European Time - UTC+1/+2'),(118,'timezones','Europe/Rome','Central European Time (CET)','Central European Time - UTC+1/+2'),(119,'timezones','Europe/Madrid','Central European Time (CET)','Central European Time - UTC+1/+2'),(120,'timezones','Europe/Amsterdam','Central European Time (CET)','Central European Time - UTC+1/+2'),(121,'timezones','Europe/Brussels','Central European Time (CET)','Central European Time - UTC+1/+2'),(122,'timezones','Europe/Vienna','Central European Time (CET)','Central European Time - UTC+1/+2'),(123,'timezones','Europe/Zurich','Central European Time (CET)','Central European Time - UTC+1/+2'),(124,'timezones','Europe/Prague','Central European Time (CET)','Central European Time - UTC+1/+2'),(125,'timezones','Europe/Warsaw','Central European Time (CET)','Central European Time - UTC+1/+2'),(126,'timezones','Europe/Budapest','Central European Time (CET)','Central European Time - UTC+1/+2'),(127,'timezones','Europe/Bucharest','Eastern European Time (EET)','Eastern European Time - UTC+2/+3'),(128,'timezones','Europe/Kiev','Eastern European Time (EET)','Eastern European Time - UTC+2/+3'),(129,'timezones','Europe/Athens','Eastern European Time (EET)','Eastern European Time - UTC+2/+3'),(130,'timezones','Europe/Helsinki','Eastern European Time (EET)','Eastern European Time - UTC+2/+3'),(131,'timezones','Europe/Stockholm','Central European Time (CET)','Central European Time - UTC+1/+2'),(132,'timezones','Europe/Copenhagen','Central European Time (CET)','Central European Time - UTC+1/+2'),(133,'timezones','Europe/Oslo','Central European Time (CET)','Central European Time - UTC+1/+2'),(134,'timezones','Europe/Moscow','Moscow Time (MSK)','Moscow Time - UTC+3'),(135,'timezones','Europe/Istanbul','Turkey Time (TRT)','Turkey Time - UTC+3'),(136,'timezones','Asia/Tokyo','Japan Standard Time (JST)','Japan Standard Time - UTC+9'),(137,'timezones','Asia/Shanghai','China Standard Time (CST)','China Standard Time - UTC+8'),(138,'timezones','Asia/Hong_Kong','Hong Kong Time (HKT)','Hong Kong Time - UTC+8'),(139,'timezones','Asia/Singapore','Singapore Time (SGT)','Singapore Time - UTC+8'),(140,'timezones','Asia/Kolkata','India Standard Time (IST)','India Standard Time - UTC+5:30'),(141,'timezones','Asia/Karachi','Pakistan Time (PKT)','Pakistan Time - UTC+5'),(142,'timezones','Asia/Dhaka','Bangladesh Time (BST)','Bangladesh Time - UTC+6'),(143,'timezones','Asia/Bangkok','Indochina Time (ICT)','Indochina Time - UTC+7'),(144,'timezones','Asia/Jakarta','Western Indonesian Time (WIB)','Western Indonesian Time - UTC+7'),(145,'timezones','Asia/Manila','Philippine Time (PHT)','Philippine Time - UTC+8'),(146,'timezones','Asia/Seoul','Korea Standard Time (KST)','Korea Standard Time - UTC+9'),(147,'timezones','Asia/Taipei','Taiwan Time (TWT)','Taiwan Time - UTC+8'),(148,'timezones','Asia/Kuala_Lumpur','Malaysia Time (MYT)','Malaysia Time - UTC+8'),(149,'timezones','Asia/Dubai','Gulf Time (GST)','Gulf Time - UTC+4'),(150,'timezones','Asia/Riyadh','Arabia Time (AST)','Arabia Time - UTC+3'),(151,'timezones','Asia/Tehran','Iran Time (IRT)','Iran Time - UTC+3:30/+4:30'),(152,'timezones','Asia/Jerusalem','Israel Time (IST)','Israel Time - UTC+2/+3'),(153,'timezones','Africa/Cairo','Eastern European Time (EET)','Eastern European Time - UTC+2/+3'),(154,'timezones','Africa/Johannesburg','South Africa Time (SAST)','South Africa Time - UTC+2'),(155,'timezones','Africa/Lagos','West Africa Time (WAT)','West Africa Time - UTC+1'),(156,'timezones','Africa/Nairobi','East Africa Time (EAT)','East Africa Time - UTC+3'),(157,'timezones','Africa/Casablanca','Western European Time (WET)','Western European Time - UTC+0/+1'),(158,'timezones','Africa/Algiers','Central European Time (CET)','Central European Time - UTC+1/+2'),(159,'timezones','Australia/Sydney','Australian Eastern Time (AET)','Australian Eastern Time - UTC+10/+11'),(160,'timezones','Australia/Melbourne','Australian Eastern Time (AET)','Australian Eastern Time - UTC+10/+11'),(161,'timezones','Australia/Brisbane','Australian Eastern Time (AEST)','Australian Eastern Time (no DST) - UTC+10'),(162,'timezones','Australia/Perth','Australian Western Time (AWST)','Australian Western Time - UTC+8'),(163,'timezones','Australia/Adelaide','Australian Central Time (ACT)','Australian Central Time - UTC+9:30/+10:30'),(164,'timezones','Pacific/Auckland','New Zealand Time (NZT)','New Zealand Time - UTC+12/+13'),(165,'timezones','Pacific/Fiji','Fiji Time (FJT)','Fiji Time - UTC+12/+13'),(166,'timezones','Pacific/Guam','Chamorro Time (ChST)','Chamorro Time - UTC+10'),(167,'timezones','Pacific/Saipan','Chamorro Time (ChST)','Chamorro Time - UTC+10'),(168,'jobTypes','reminder','Reminder','Scheduled reminder job'),(172,'scheduledJobsStatus','running','Running','Job is currently running'),(173,'scheduledJobsStatus','cancelled','Cancelled','Job was manually cancelled');
/*!40000 ALTER TABLE `lookups` ENABLE KEYS */;
UNLOCK TABLES;

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
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `published_at` datetime DEFAULT NULL COMMENT 'When this version was published(DC2Type:datetime_immutable)',
  `metadata` json DEFAULT NULL COMMENT 'Additional info like change summary, tags, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_version_number` (`id_pages`,`version_number`),
  KEY `idx_id_pages` (`id_pages`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_published_at` (`published_at`),
  CONSTRAINT `FK_page_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_page_versions_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores published page versions with complete JSON structures';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `page_versions`
--

LOCK TABLES `page_versions` WRITE;
/*!40000 ALTER TABLE `page_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `page_versions` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pages`
--

LOCK TABLES `pages` WRITE;
/*!40000 ALTER TABLE `pages` DISABLE KEYS */;
INSERT INTO `pages` VALUES (1,'login','/login',NULL,1,NULL,NULL,2,64,0,1,NULL),(2,'home','/home',NULL,0,NULL,NULL,2,64,0,1,NULL),(3,'profile-link','/profile-link',NULL,0,NULL,NULL,2,64,0,1,NULL),(4,'profile','/profile',3,0,10,NULL,2,64,0,1,NULL),(5,'logout','/login',3,0,20,NULL,2,64,0,1,NULL),(6,'missing','/missing',NULL,0,NULL,NULL,2,64,0,1,NULL),(7,'no_access','/no-access',NULL,0,NULL,NULL,2,64,0,1,NULL),(8,'no_access_guest','/no-access-guest',NULL,0,NULL,NULL,2,64,0,1,NULL),(30,'agb','/agb',NULL,0,NULL,300,2,64,0,1,NULL),(31,'impressum','/impressum',NULL,0,NULL,100,2,64,0,1,NULL),(32,'disclaimer','/disclaimer',NULL,0,NULL,200,2,64,0,1,NULL),(33,'validate','/validate/[i:uid]/[a:token]',NULL,0,NULL,NULL,2,64,0,1,NULL),(35,'reset_password','/reset',NULL,0,NULL,NULL,2,64,0,1,NULL),(84,'two-factor-authentication','/two-factor-authentication',NULL,1,NULL,NULL,2,64,0,1,NULL),(85,'sh-global-css',NULL,NULL,0,NULL,NULL,10,63,0,0,NULL),(86,'sh-global-values',NULL,NULL,0,NULL,NULL,6,64,0,0,NULL),(87,'sh-cms-preferences',NULL,NULL,0,NULL,NULL,12,63,0,0,NULL);
/*!40000 ALTER TABLE `pages` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `pages_fields`
--

LOCK TABLES `pages_fields` WRITE;
/*!40000 ALTER TABLE `pages_fields` DISABLE KEYS */;
INSERT INTO `pages_fields` VALUES (2,106,NULL,'A short description of the research project. This field will be used as `meta:description` in the HTML header. Some services use this tag to provide the user with information on the webpage (e.g. automatic link-replacement in messaging tools on smartphones use this description.)'),(2,115,NULL,'This field defines the content of the alert message that is shown when a date is set in the field `maintenance_date`. Use markdown with the special keywords `@date` and `@time` which will be replaced by a human-readable form of the fields `maintenance_date` and `maintenance_time`.'),(2,116,NULL,'If set (together with the field `maintenance_time`), an alert message is shown at the top of the page displaying to content as defined in the field `maintenance` (where the key `@data` is replaced by this field).'),(2,117,NULL,'If set (together with the field `maintenance_date`), an alert message is shown at the top of the page displaying to content as defined in the field `maintenance` (where the key `@time` is replaced by this field).');
/*!40000 ALTER TABLE `pages_fields` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `pages_fields_translation`
--

LOCK TABLES `pages_fields_translation` WRITE;
/*!40000 ALTER TABLE `pages_fields_translation` DISABLE KEYS */;
INSERT INTO `pages_fields_translation` VALUES (1,22,2,'Login'),(1,22,3,'Login'),(1,174,1,''),(2,22,2,'Projekt Name'),(2,22,3,'Project Name'),(2,174,1,''),(3,22,2,'Profil'),(3,22,3,'Profile'),(3,174,1,''),(4,22,2,'Einstellungen'),(4,22,3,'Settings'),(4,174,1,''),(5,22,2,'Logout'),(5,22,3,'Logout'),(5,174,1,''),(6,22,2,'Seite nicht gefunden'),(6,22,3,'Missing'),(6,174,1,''),(7,22,2,'Kein Zugriff'),(7,22,3,'No Access'),(7,174,1,''),(8,22,2,'Kein Zugriff'),(8,22,3,'No Access'),(8,174,1,''),(30,22,2,'AGB'),(30,22,3,'GTC'),(30,174,1,''),(31,22,2,'Impressum'),(31,22,3,'Impressum'),(31,174,1,''),(32,22,2,'Disclaimer'),(32,22,3,'Disclaimer'),(32,174,1,''),(33,22,2,'Benutzer Validierung'),(33,22,3,'User Validation'),(33,174,1,''),(35,22,2,'Passwort zurücksetzen'),(35,22,3,'Reset Password'),(35,174,1,''),(84,8,2,'Two-Factor Authentication'),(84,22,2,'Two-Factor Authentication'),(85,22,2,'Custom CSS'),(85,22,3,'Custom CSS'),(85,106,2,'Geben Sie in diesem Feld Ihre eigenen CSS-Regeln ein, um das Erscheinungsbild Ihrer Seiten, Elemente oder Komponenten individuell anzupassen. Alle hier definierten CSS-Klassen oder -Stile werden automatisch auf Ihrer Website geladen. Anschließend können Sie die von Ihnen vergebenen Klassennamen in Ihren Inhalten, Layouts oder Widgets verwenden.'),(85,106,3,'Enter your own CSS rules in this field to customize the appearance of your pages, elements, or components. Any CSS classes or styles you define here will be automatically loaded on your site. You can then use the class names you define in your content, layouts, or widgets.'),(85,193,1,''),(86,22,2,'Global Values'),(86,22,3,'Global Values'),(86,106,2,'JSON object where can be defined global translation keys and use the key to load the proper translation based on the selected language. A key is accessed by {{key_name}}, and this will be replaced with the value for the selected language'),(86,106,3,'JSON object where can be defined global translation keys and use the key to load the proper translation based on the selected language. A key is accessed by {{key_name}}, and this will be replaced with the value for the selected language'),(87,22,2,'CMS Preferences'),(87,22,3,'CMS Preferences'),(87,106,2,'Konfiguration der CMS-Einstellungen'),(87,106,3,'CMS configuration settings'),(87,236,1,'2'),(87,237,1,'0'),(87,238,1,''),(87,239,1,'');
/*!40000 ALTER TABLE `pages_fields_translation` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `pages_sections`
--

LOCK TABLES `pages_sections` WRITE;
/*!40000 ALTER TABLE `pages_sections` DISABLE KEYS */;
INSERT INTO `pages_sections` VALUES (35,28,NULL),(84,69,0);
/*!40000 ALTER TABLE `pages_sections` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pageType`
--

LOCK TABLES `pageType` WRITE;
/*!40000 ALTER TABLE `pageType` DISABLE KEYS */;
INSERT INTO `pageType` VALUES (12,'cms_preferences'),(2,'core'),(7,'emails'),(3,'experiment'),(10,'global_css'),(6,'global_values'),(1,'intern'),(5,'maintenance'),(8,'sh_global_css'),(9,'sh_security_questions');
/*!40000 ALTER TABLE `pageType` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `pageType_fields`
--

LOCK TABLES `pageType_fields` WRITE;
/*!40000 ALTER TABLE `pageType_fields` DISABLE KEYS */;
INSERT INTO `pageType_fields` VALUES (1,22,'','The title of the page. This field is used as\n - HTML title of the page\n - Menu name in the header','Page title'),(1,106,NULL,'A short description of the research project. This field will be used as `meta:description` in the HTML header. Some services use this tag to provide the user with information on the webpage (e.g. automatic link-replacement in messaging tools on smartphones use this description.)','Page description'),(1,174,'','The icon which will be used for menus. For mobile icons use prefix `mobile-`','Page icon'),(2,22,'','The title of the page. This field is used as\n - HTML title of the page\n - Menu name in the header','Page title'),(2,106,NULL,'A short description of the research project. This field will be used as `meta:description` in the HTML header. Some services use this tag to provide the user with information on the webpage (e.g. automatic link-replacement in messaging tools on smartphones use this description.)','Page description'),(2,174,'','The icon which will be used for menus. For mobile icons use prefix `mobile-`','Page icon'),(3,22,'','The title of the page. This field is used as\n - HTML title of the page\n - Menu name in the header','Page title'),(3,106,NULL,'A short description of the research project. This field will be used as `meta:description` in the HTML header. Some services use this tag to provide the user with information on the webpage (e.g. automatic link-replacement in messaging tools on smartphones use this description.)','Page description'),(3,174,'','The icon which will be used for menus. For mobile icons use prefix `mobile-`','Page icon'),(5,22,NULL,'Page title','Page title'),(5,115,NULL,'This field defines the content of the alert message that is shown when a date is set in the field `maintenance_date`. Use markdown with the special keywords `@date` and `@time` which will be replaced by a human-readable form of the fields `maintenance_date` and `maintenance_time`.','Maintenance text'),(5,116,NULL,'If set (together with the field `maintenance_time`), an alert message is shown at the top of the page displaying to content as defined in the field `maintenance` (where the key `@data` is replaced by this field).','Maintenance date'),(5,117,NULL,'If set (together with the field `maintenance_date`), an alert message is shown at the top of the page displaying to content as defined in the field `maintenance` (where the key `@time` is replaced by this field).','Maintenance time'),(6,22,NULL,'Page title','Page title'),(6,106,NULL,'Page description','Page description'),(7,22,NULL,'Page title','Page title'),(8,22,NULL,'Page title','Page title'),(8,193,NULL,'Custom CSS raw code','Custom CSS'),(9,22,NULL,'Page title','Page title'),(10,22,NULL,'Page title','Page title'),(10,106,NULL,'Page description','Page description'),(10,193,NULL,'Enter your own CSS rules in this field to customize the appearance of your pages, elements, or components. Any CSS classes or styles you define here will be automatically loaded on your site. You can then use the class names you define in your content, layouts, or widgets.','Custom CSS'),(12,22,NULL,'Page title',''),(12,106,NULL,'Page description',''),(12,236,NULL,'Default language for the CMS system',''),(12,237,'0','Allow anonymous users to access the system',''),(12,238,NULL,'Firebase configuration in JSON format',''),(12,239,'Europe/Zurich','Default timezone for the CMS system','');
/*!40000 ALTER TABLE `pageType_fields` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'admin.access','Can view and enter the admin/backend area'),(2,'admin.page.read','Can read existing pages'),(3,'admin.page.create','Can create new pages'),(4,'admin.page.update','Can edit existing pages'),(5,'admin.page.delete','Can delete pages'),(6,'admin.page.insert','Can insert content into pages'),(7,'admin.page.export','Can export sections from pages'),(8,'admin.settings','Full access to CMS settings'),(9,'admin.user.read','Can read existing users'),(10,'admin.user.create','Can create new users'),(11,'admin.user.update','Can edit existing users'),(12,'admin.user.delete','Can delete users'),(13,'admin.user.block','Can block users'),(14,'admin.user.unblock','Can unblock users'),(15,'admin.user.impersonate','Can impersonate users'),(16,'admin.group.read','Can read existing groups'),(17,'admin.group.create','Can create new groups'),(18,'admin.group.update','Can edit existing groups'),(19,'admin.group.delete','Can delete groups'),(20,'admin.group.acl','Can manage group ACLs'),(21,'admin.role.read','Can read existing roles'),(22,'admin.role.create','Can create new roles'),(23,'admin.role.update','Can edit existing roles'),(24,'admin.role.delete','Can delete roles'),(25,'admin.role.permissions','Can manage role permissions'),(26,'admin.permission.read','Can read all available permissions'),(27,'admin.cms_preferences.read','Can read CMS preferences'),(28,'admin.cms_preferences.update','Can update CMS preferences'),(29,'admin.asset.read','Can read assets'),(30,'admin.asset.create','Can create assets'),(31,'admin.asset.delete','Can delete assets'),(32,'admin.action.read','Can read actions'),(33,'admin.action.update','Can update actions'),(34,'admin.action.delete','Can delete actions'),(35,'admin.section.delete','Can delete sections'),(37,'admin.scheduled_job.read','Can read scheduled jobs'),(38,'admin.scheduled_job.execute','Can execute scheduled jobs'),(39,'admin.scheduled_job.cancel','Can cancel scheduled jobs'),(40,'admin.scheduled_job.delete','Can delete scheduled jobs'),(41,'admin.data.read','Can read data and data tables'),(42,'admin.data.delete','Can delete data records and data tables'),(43,'admin.data.delete_columns','Can delete columns from data tables'),(44,'admin.cache.read','Can read cache statistics and monitoring data'),(45,'admin.cache.clear','Can clear caches'),(46,'admin.cache.manage','Can manage cache settings and operations'),(47,'admin.action_translation.read','Can read action translations'),(48,'admin.page_version.read','Can read and list page versions'),(49,'admin.page_version.create','Can create new page versions'),(50,'admin.page_version.publish','Can publish page versions'),(51,'admin.page_version.unpublish','Can unpublish page versions'),(52,'admin.page_version.delete','Can delete page versions'),(53,'admin.page_version.compare','Can compare page versions'),(54,'admin.audit.view','View audit logs and security monitoring data');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `plugins`
--

LOCK TABLES `plugins` WRITE;
/*!40000 ALTER TABLE `plugins` DISABLE KEYS */;
/*!40000 ALTER TABLE `plugins` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refresh  tokens`
--

LOCK TABLES `refreshTokens` WRITE;
/*!40000 ALTER TABLE `refreshTokens` DISABLE KEYS */;
INSERT INTO `refreshTokens` VALUES (1,5,'b35fc7cd6e25be57d87d18a91c278174c2f6cce5abd30823396cc0915a9ae4c1','2026-05-07 12:30:43',NULL);
/*!40000 ALTER TABLE `refreshTokens` ENABLE KEYS */;
UNLOCK TABLES;

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
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_resource` (`id_roles`,`id_resourceTypes`,`resource_id`),
  KEY `IDX_role_data_access_roles` (`id_roles`),
  KEY `IDX_role_data_access_resource_types` (`id_resourceTypes`),
  KEY `IDX_role_data_access_resource_id` (`resource_id`),
  KEY `IDX_role_data_access_permissions` (`crud_permissions`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_data_access`
--

LOCK TABLES `role_data_access` WRITE;
/*!40000 ALTER TABLE `role_data_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_data_access` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Administrator role with full access');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `roles_permissions`
--

LOCK TABLES `roles_permissions` WRITE;
/*!40000 ALTER TABLE `roles_permissions` DISABLE KEYS */;
INSERT INTO `roles_permissions` VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),(18,1),(19,1),(20,1),(21,1),(22,1),(23,1),(24,1),(25,1),(26,1),(27,1),(28,1),(29,1),(30,1),(31,1),(32,1),(33,1),(34,1),(35,1),(37,1),(38,1),(39,1),(40,1),(41,1),(42,1),(43,1),(44,1),(45,1),(46,1),(47,1),(48,1),(49,1),(50,1),(51,1),(52,1),(53,1),(54,1);
/*!40000 ALTER TABLE `roles_permissions` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scheduledJobs`
--

LOCK TABLES `scheduledJobs` WRITE;
/*!40000 ALTER TABLE `scheduledJobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `scheduledJobs` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES (1,1,'login-login',0,NULL,NULL,NULL,NULL),(7,8,'goBack-button',0,NULL,NULL,NULL,NULL),(8,8,'goHome-button',0,NULL,NULL,'ml-3',NULL),(27,8,'toLogin-button',0,NULL,NULL,NULL,NULL),(28,35,'resetPassword-resetPassword',0,NULL,NULL,NULL,NULL),(35,41,'register-register',0,NULL,NULL,'mt-3',NULL),(59,60,'impressum-version',0,NULL,NULL,NULL,NULL),(69,82,'twoFactorAuth-twoFactorAuth',0,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `sections_fields_translation`
--

LOCK TABLES `sections_fields_translation` WRITE;
/*!40000 ALTER TABLE `sections_fields_translation` DISABLE KEYS */;
INSERT INTO `sections_fields_translation` VALUES (1,1,2,'Email',NULL),(1,1,3,'Email',NULL),(1,2,2,'Passwort',NULL),(1,2,3,'Password',NULL),(1,3,2,'Anmelden',NULL),(1,3,3,'Login',NULL),(1,4,2,'Passwort vergessen?',NULL),(1,4,3,'Forgotten the Password?',NULL),(1,5,2,'Die Email Adresse oder das Passwort ist nicht korrekt.',NULL),(1,5,3,'The email address or the password is not correct.',NULL),(1,7,2,'Bitte einloggen',NULL),(1,7,3,'Please Login',NULL),(7,8,2,'Zurück',NULL),(7,8,3,'Back',NULL),(7,27,1,'#back',NULL),(7,28,1,'primary',NULL),(8,8,2,'Zur Startseite',NULL),(8,8,3,'Home',NULL),(8,27,1,'#home',NULL),(8,28,1,'primary',NULL),(27,8,2,'Zum Login',NULL),(27,8,3,'To Login',NULL),(27,27,1,'#login',NULL),(27,28,1,'primary',NULL),(28,3,2,'Zum Login',NULL),(28,3,3,'To Login',NULL),(28,4,2,'Passwort zurücksetzen',NULL),(28,4,3,'Reset Password',NULL),(28,5,2,'Aktivierungs Email konnte nicht versendet werden.',NULL),(28,5,3,'Activation email could not be sent.',NULL),(28,25,2,'# Passwort Zurücksetzen\r\n\r\nHier können sie Ihr Passwort zurücksetzen.\r\nBitte geben sie Ihre Email Adresse ein mit welcher sie bei @project registriert sind.\r\nSie werden eine Email erhalten mit einem neuen Aktivierungslink um Ihr Passwort zurück zu setzen.',NULL),(28,25,3,'# Reset Password\r\n\r\nThis page allows you to reset your password.\r\nPlease enter the email address with which you are registered on @project.\r\nYou will receive an email with a new activation link which will allow you to reset the password.',NULL),(28,28,1,'primary',NULL),(28,35,2,'Die Aktivierungs Email wurde versendet. Klicken sie auf den Aktivierungslink um Ihr Passwort zurück zu setzen.',NULL),(28,35,3,'The activation mail was sent. Click the activation link to rest your password.',NULL),(28,44,2,'Email versendet',NULL),(28,44,3,'Email Sent',NULL),(28,55,2,'Bitte Email eingeben',NULL),(28,55,3,'Please Enter Email',NULL),(28,110,2,'Guten Tag\r\n\r\nUm das Passwort von Ihrem @project Account zurück zu setzten klicken Sie bitte auf den untenstehenden Link.\r\n\r\n@link\r\n\r\nVielen Dank!\r\n\r\nIhr @project Team\r\n',NULL),(28,110,3,'Hello\r\n\r\nTo reset password of your @project account please click the link below.\r\n\r\n@link\r\n\r\nThank you!\r\n\r\nSincerely, your @project team.\r\n',NULL),(28,111,2,'@project Passwort zurück setzen',NULL),(28,111,3,'@project Password Reset',NULL),(28,114,1,'0',NULL),(35,1,2,'Email',NULL),(35,1,3,'Email',NULL),(35,2,2,'Validierungs-Code',NULL),(35,2,3,'Validation Code',NULL),(35,5,2,'Die Email Adresse oder der Aktivierungs-Code ist ungültig',NULL),(35,5,3,'The email address or the activation code is invalid',NULL),(35,22,2,'Registration',NULL),(35,22,3,'Registration',NULL),(35,35,2,'Der erste Schritt der Registrierung war erfolgreich. Sie werden in Kürze eine Email mit einem Aktivierunks-Link erhalten.\r\n\r\nBitte folgen Sie diesem Link um die Registrierung abzuschliessen.',NULL),(35,35,3,'The first step of the registration was successful.\r\nShortly you will receive an email with an activation link.\r\n\r\nPlease follow this activation link to complete the registration.',NULL),(35,44,2,'Registrierung erfolgreich',NULL),(35,44,3,'Registration Successful',NULL),(35,90,2,'Registrieren',NULL),(35,90,3,'Register',NULL),(69,5,2,'Invalid verification code. Please try again.',NULL),(69,8,2,'Two-Factor Authentication',NULL),(69,25,2,'Please enter the 6-digit code sent to your email',NULL),(69,233,2,'Code expires in',NULL);
/*!40000 ALTER TABLE `sections_fields_translation` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `sections_hierarchy`
--

LOCK TABLES `sections_hierarchy` WRITE;
/*!40000 ALTER TABLE `sections_hierarchy` DISABLE KEYS */;
/*!40000 ALTER TABLE `sections_hierarchy` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `sections_navigation`
--

LOCK TABLES `sections_navigation` WRITE;
/*!40000 ALTER TABLE `sections_navigation` DISABLE KEYS */;
/*!40000 ALTER TABLE `sections_navigation` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `styleGroup`
--

LOCK TABLES `styleGroup` WRITE;
/*!40000 ALTER TABLE `styleGroup` DISABLE KEYS */;
INSERT INTO `styleGroup` VALUES (1,'intern','Reserved for internal system styles. Modifying or using these styles externally may cause unexpected behavior.',0),(2,'Form','A form is a wrapper for input fields. It allows to send content of the input fields to the server and store the data to the database. Several style are available:',60),(3,'Input','An input field must be placed inside a form wrapper. An input field allows a user to enter data and submit these to the server. The chosen form wrapper decides what happens with the submitted data. The following input fields styles are available:',70),(4,'Wrapper','A wrapper is a style that allows to group child elements. Wrappers can have a visual component or can be invisible. Visible wrapper are useful to provide some structure in a document while invisible wrappers serve merely as a grouping option . The latter can be useful in combination with CSS classes. The following wrappers are available:',10),(5,'Text','Text styles allow to control how text is displayed. These styles are used to create the main content. The following styles are available:',20),(6,'List','Lists are styles that allow to define more sophisticated lists than the markdown syntax allows. They come with attached javascript functionality. The following lists are available:',50),(7,'Media','The media styles allow to display different media on a webpage. The following styles are available:',40),(8,'Link','Link styles allow to render different types of links:',30),(9,'Admin','The admin styles are for user registration and access handling.\r\nThe following styles are available:',80),(12,'Triggers','Trigger styles allow to attach an action that can be executed when the user fulfill the triger condition',75),(13,'Mobile','Styles that are only used by the mobile application',79),(14,'mantine','Mantine UI components for modern web interfaces',10);
/*!40000 ALTER TABLE `styleGroup` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=166 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `styles`
--

LOCK TABLES `styles` WRITE;
/*!40000 ALTER TABLE `styles` DISABLE KEYS */;
INSERT INTO `styles` VALUES (1,'login',9,'provides a small form where the user can enter his or her email and password to access the WebApp. It also includes a link to reset a password.',0),(8,'button',8,'renders a button-style link with several predefined colour schemes.',0),(13,'figure',7,'allows to attach a caption to media elements. A figure expects a media style as its immediate child.',1),(15,'image',7,'allows to render an image on a page.',0),(18,'link',8,'renders a standard link but allows to open the target in a new tab.',1),(27,'video',7,'allows to load and display a video on a page.',0),(35,'resetPassword',1,'',0),(41,'register',9,'provides a small form to allow a user to register for the WebApp. In order to register a user must provide a valid email and activation code. Activation codes can be generated in the admin section of the WebApp. The list of available codes can be exported.',0),(43,'audio',7,'allows to load and replay an audio source on a page.',0),(60,'version',9,'Add information about the DB version and for the git version of Selfhelp',0),(63,'entryList',4,'Wrap other styles that later visualize list of entries (inserted via `formUserInput`).',1),(64,'entryRecord',4,'Wrap other styles that later visualize a record from the entry list',1),(67,'refContainer',4,'Wrap other styles that later can be used in different place. It can be used for creating resusable blocks.',1),(75,'loop',4,'A style which takes an array object and loop the rows and load its children passing the values of the rows',1),(79,'dataContainer',4,'Data container style which propagate all loaded data to its children.',1),(81,'entryRecordDelete',4,'Style that allows the user to delete entry record',0),(82,'twoFactorAuth',9,'Provides a form for two-factor authentication where users can enter their verification code.',0),(83,'box',14,'Mantine Box component as a base for all Mantine components with style props support',1),(84,'center',14,'Mantine Center component for centering content',1),(85,'container',14,'Mantine Container component for responsive layout containers',1),(86,'flex',14,'Mantine Flex component for flexible layouts',1),(87,'group',14,'Mantine Group component for horizontal layouts',1),(88,'simple-grid',14,'Mantine simple-grid component for responsive grid layouts',1),(89,'space',14,'Mantine Space component for adding spacing',0),(90,'stack',14,'Mantine Stack component for vertical layouts',1),(91,'grid',14,'Mantine Grid component for responsive 12 columns grid system',0),(92,'grid-column',14,'Mantine Grid.Col component for grid column with span, offset, and order controls',1),(93,'tabs',14,'Mantine Tabs component for switching between different views',0),(94,'tab',14,'Mantine Tabs.Tab component for individual tab items within a tabs component. Can contain child components for tab panel content.',1),(95,'aspect-ratio',14,'Mantine AspectRatio component for maintaining aspect ratios',1),(96,'chip',14,'Mantine Chip component for selectable tags',0),(97,'color-input',14,'Mantine color-input component for color selection',0),(99,'color-picker',14,'Mantine color-picker component for color selection',0),(100,'fieldset',14,'Mantine Fieldset component for grouping form elements',1),(101,'file-input',14,'Mantine FileInput component for file uploads',0),(102,'number-input',14,'Mantine NumberInput component for numeric input',0),(103,'radio',14,'Unified Radio component that can render as single radio or radio group based on options',1),(104,'range-slider',14,'Mantine range-slider component for range selection',0),(105,'slider',14,'Mantine slider component for single value selection',0),(106,'rating',14,'Mantine Rating component for star ratings',0),(107,'segmented-control',14,'Mantine segmented-control component for segmented controls',0),(108,'switch',14,'Mantine Switch component for toggle switches',0),(109,'combobox',14,'Mantine Combobox component for advanced select inputs',0),(110,'action-icon',14,'Mantine ActionIcon component for interactive icons',0),(111,'notification',14,'Mantine Notification component for alerts and messages',0),(112,'alert',14,'Mantine Alert component for displaying important messages and notifications',1),(113,'accordion',14,'Mantine Accordion component for collapsible content',1),(114,'accordion-item',14,'Mantine Accordion.Item component for individual accordion items (accepts all children, panels handled in frontend)',1),(115,'avatar',14,'Mantine Avatar component for user profile images',0),(116,'background-image',14,'Mantine background-image component for background images',1),(117,'badge',14,'Mantine Badge component for status indicators and labels',0),(118,'indicator',14,'Mantine Indicator component for status indicators',1),(119,'kbd',14,'Mantine Kbd component for keyboard key display',0),(120,'spoiler',14,'Mantine Spoiler component for collapsible text',1),(121,'theme-icon',14,'Mantine ThemeIcon component for themed icon containers',0),(122,'timeline',14,'Mantine Timeline component for chronological displays',0),(123,'timeline-item',14,'Mantine Timeline.Item component for individual timeline entries',1),(124,'blockquote',14,'Mantine Blockquote component for quoted text',0),(125,'code',14,'Mantine Code component for inline code display',0),(126,'highlight',14,'Mantine Highlight component for text highlighting',0),(127,'title',14,'Mantine Title component for headings and titles',0),(128,'list',14,'Mantine List component for displaying ordered or unordered lists',0),(129,'list-item',14,'Mantine List.Item component for individual list items',1),(130,'typography',14,'Mantine Typography component for consistent typography styles',1),(131,'divider',14,'Mantine Divider component for visual separation',0),(132,'paper',14,'Mantine Paper component for elevated surfaces',1),(133,'scroll-area',14,'Mantine scroll-area component for custom scrollbars',1),(134,'card',14,'Card container component with Mantine styling',1),(135,'card-segment',14,'Card segment component for organizing card content',1),(136,'progress-root',14,'Mantine Progress.Root component for compound progress bars with multiple sections',0),(137,'progress',14,'Mantine Progress component for basic progress bars',0),(138,'progress-section',14,'Mantine Progress.Section component for individual progress sections',0),(139,'text',14,'Mantine Text component for displaying text with various styling options',0),(140,'carousel',14,'Mantine Carousel component for displaying content in a slideshow format',1),(141,'checkbox',14,'Mantine Checkbox component for boolean input with customizable styling',0),(142,'datepicker',14,'Mantine DatePicker component for date, time, and datetime input with comprehensive formatting options',0),(143,'text-input',14,'Mantine TextInput component for controlled text input with validation and sections',0),(144,'textarea',3,'Textarea component for multi-line text input with autosize and resize options. It supports Mantine styling.',0),(145,'input',3,'HTML input component for various input types (text, email, password, etc.). Renders as standard HTML input tag.',0),(146,'select',3,'HTML select component for dropdown selections. Supports single and multiple selections.',0),(147,'rich-text-editor',14,'Rich text editor component based on Tiptap with toolbar controls for formatting. It supports controlled input for form submission.',0),(148,'form-log',2,'Log form component that clears data after successful submission. Supports multiple entries and form validation.',1),(149,'form-record',2,'Record form component that preserves data and updates existing records. Pre-populates fields with existing data.',1),(150,'html-tag',4,'Raw HTML tag component for custom flexible UI designs - allows rendering any HTML element with children',1),(151,'profile',9,'User profile management component with account settings, password reset, and account deletion',0),(152,'validate',9,'User account validation form that accepts user ID and token from URL, validates and activates account. Can have children for additional form fields.',1);
/*!40000 ALTER TABLE `styles` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `styles_allowed_relationships`
--

LOCK TABLES `styles_allowed_relationships` WRITE;
/*!40000 ALTER TABLE `styles_allowed_relationships` DISABLE KEYS */;
INSERT INTO `styles_allowed_relationships` VALUES (91,92),(93,94),(113,114),(122,123),(128,129),(134,135),(136,138);
/*!40000 ALTER TABLE `styles_allowed_relationships` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `styles_fields`
--

LOCK TABLES `styles_fields` WRITE;
/*!40000 ALTER TABLE `styles_fields` DISABLE KEYS */;
INSERT INTO `styles_fields` VALUES (1,1,NULL,'The placeholder in the email input field.',0,0,''),(1,2,NULL,'The placeholder in the password input field.',0,0,''),(1,3,NULL,'The text on the login button.',0,0,''),(1,4,NULL,'The name of the password reset link.',0,0,''),(1,5,NULL,'This text is displayed in a danger-alert-box whenever the login fails.',0,0,''),(1,7,NULL,'The text displayed in the login card header.',0,0,''),(1,28,'dark','This allows to choose the colour scheme for the login form.',0,0,''),(1,243,'','Sets the margin of the Login component',0,0,'Margin'),(8,8,NULL,'The text to appear on the button.',0,0,''),(8,51,'','Cancel button label on the confirmation modal',0,0,''),(8,86,'0','If `openInNewTab` prop is set Button will open in a new tab. For more information check https://mantine.dev/core/button',0,0,'Open in New Tab'),(8,142,'0','If `disabled` prop is set Button will be disabled. For more information check https://mantine.dev/core/button',0,0,'Disabled'),(8,186,'','Confirmation title for the modal when the button is clicked',0,0,''),(8,187,'OK','Continue button for the modal when the button is clicked',0,0,''),(8,188,'Do you want to continue?','The message shown on the modal',0,0,''),(8,191,'#','Select a page keyword to link to. For more information check https://mantine.dev/core/button',0,0,'URL'),(8,243,'','Sets the margin of the Button component',0,0,'Margin'),(8,262,'filled','Select a variant for the button. For more information check https://mantine.dev/core/button',0,0,'Variant'),(8,263,'blue','Select color for the button. For more information check https://mantine.dev/core/button',0,0,'Color'),(8,264,'sm','Select size for the button. For more information check https://mantine.dev/core/button',0,0,'Size'),(8,265,'sm','Select border radius for the button. For more information check https://mantine.dev/core/button',0,0,'Radius'),(8,266,NULL,'`leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added, padding on the corresponding side is reduced. Note that `leftSection` and `rightSection` are flipped in RTL mode (`leftSection` is displayed on the right and `rightSection` is displayed on the left).',0,0,'Left Icon'),(8,267,NULL,'`leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added, padding on the corresponding side is reduced. Note that `leftSection` and `rightSection` are flipped in RTL mode (`leftSection` is displayed on the right and `rightSection` is displayed on the left).',0,0,'Right Icon'),(8,280,'0','If `fullWidth`	 prop is set Button will take 100% of parent width',0,0,'Full Width'),(8,281,'0','If `compact` prop is set Button will be smaller. Button supports xs – xl and compact-xs – compact-xl sizes. compact sizes have the same font-size as xs – xl but reduced padding and height.',0,0,'Compact'),(8,282,'0','If `autoContrast` prop is set Button will automatically adjust the contrast of the button to the background color. For more information check https://mantine.dev/core/button',0,0,'Auto Contrast'),(8,283,'0','If `isLink` prop is set Button will be a link. For more information check https://mantine.dev/core/button',0,0,'Is Link'),(8,284,'1','If `useMantineStyle` prop is set Button will use the Mantine style, otherwise it will be a clear element whcih can be styled with CSS and tailwind CSS classes. For more information check https://mantine.dev/core/button',0,1,'Use Mantine Style'),(13,49,NULL,'The title to be prepended to the text defined in filed `caption`.',0,0,''),(13,50,NULL,'The caption of the figure.',0,0,''),(13,243,'','Sets the margin of the Figure component',0,0,'Margin'),(15,22,NULL,'The text to be shown when hovering over the image.',0,0,''),(15,29,'1','If enabled the image scales responsively.',0,0,''),(15,30,NULL,'The alternative text to be shown if the image cannot be loaded.',0,0,''),(15,53,NULL,'The image source. If the image is an asset simply use the full name of the asset here.',0,0,''),(15,243,'','Sets the margin of the Image component',0,0,'Margin'),(15,265,'0','Sets the border radius of the image. For more information check https://mantine.dev/core/image',0,0,'Radius'),(15,273,NULL,'Sets the width of the image. Either a custom value or falls back to section-${style.id}',0,0,'Width'),(15,274,NULL,'Sets the height of the image. Either a custom value or falls back to section-${style.id}',0,0,'Height'),(15,284,'1','If enabled, uses Mantine Image component with advanced features. If disabled, falls back to standard HTML img element that can be styled with CSS.',0,0,'Use Mantine Style'),(15,343,'contain','Sets how the image should fit within its container. For more information check https://mantine.dev/core/image',0,0,'Object Fit'),(15,616,NULL,'The source URL of the image. For more information check https://mantine.dev/core/image',0,0,'Source'),(15,617,NULL,'Alt text for the image for accessibility. For more information check https://mantine.dev/core/image',0,0,'Alt Text'),(18,8,NULL,'Specifies the clickable text. If left empty the URL as specified in the field `url` will be used.',0,0,''),(18,27,NULL,'Links can refer to elements within SelfHelp\nUse the following syntax to achieve this:\n - link to back (browser functionality) `#back`\n - link to the last unique visited page `#last_user_page`\n - link to asset `%asset_name`\n - link to page `#page_name`\n - link to anchor on page `#page_name#wrapper_name`\n - link to root_section on a nav_page `#nav_page_name/nav_section_name`\n - link to anchor on root_section on nav_page `#nav_page_name/nav_section_name#wrapper_name`\n \nPlease use relative paths unless the `url` is an external link.',0,0,''),(18,86,NULL,'If checked the link will be opened in a new tab. If unchecked the link will open in the current tab.',0,0,''),(18,243,'','Sets the margin of the Link component',0,0,'Margin'),(27,29,'1',NULL,0,0,''),(27,30,NULL,'The alternative text to be displayed if the video cannot be loaded.',0,0,''),(27,240,NULL,NULL,0,0,'Video Source'),(27,243,'','Sets the margin of the Video component',0,0,'Margin'),(35,4,NULL,'The label on the submit button.',0,0,''),(35,25,NULL,'The description to be displayed on the page when a user wants to reset the password.',0,0,''),(35,28,NULL,'The bootstrap color of the submit button.',0,0,''),(35,35,NULL,'The success message to be shown when an email address was successfully stored in the database (if enabled) and the automatic emails were sent successfully.',0,0,''),(35,55,NULL,'The placeholder in the email input field.',0,0,''),(35,110,NULL,'The email to be sent to the the email address that was entered into the form. Use markdown syntax in conjunction with the field `is_html` if you want to send an email with html content. In addition to markdown, the following keyword is supported:\n- `@link` will be replaced by the activation link the user needs to reset the password.',0,0,''),(35,111,NULL,'The subject of the email to be sent to the the email address that was entered into the form. Use the following keywords to create dynamic content:\n- `@project` will be replaced by the project name.',0,0,''),(35,114,'0','If *checked*, the email will be parsed as markdown and sent as html. The unparsed email content will be sent as plaintext alternative. If left *unchecked* the emails will only be sent as plaintext',0,0,''),(35,242,'','Sets the margin and padding of the ResetPassword component',0,0,'Spacing'),(41,1,NULL,'The placeholder in the email input field.',0,0,''),(41,2,NULL,'The placeholder in the validation code input field.',0,0,''),(41,5,NULL,'This text is displayed in a danger-alert-box whenever the registration fails.',0,0,''),(41,22,NULL,'The text displayed in the register card header.',0,0,''),(41,28,'success','This allows to choose the colour scheme for the register form.',0,0,''),(41,35,NULL,'Upon successful registration the registration form is replaced with a `jumbotron` which hold this text.',0,0,''),(41,44,NULL,'Upon successful registration the registration form is replaced with a `jumbotron` which holds this text as a heading.',0,0,''),(41,90,NULL,'The text on the registration button.',0,0,''),(41,140,'0','If checked any user can register without a registration code. The code will be automatically generated upon registration',0,0,''),(41,143,'3','Select the default group in which evey new user is assigned.',0,0,''),(41,213,'Select security question 1','The label for the security question 1 when the anonymous registration is used',0,0,''),(41,214,'Select security question 2','The label for the security question 2 when the anonymous registration is used',0,0,''),(41,215,'Please describe the process to the user','The text is shown for the user when they register an anonymous user. Please use the field to describe the process to the user.',0,0,''),(41,242,'','Sets the margin and padding of the Register component',0,0,'Spacing'),(43,30,NULL,'The alternative text to be displayed if the audio cannot be loaded.',0,0,''),(43,71,NULL,'This field expects a [JSON](!https://www.json.org/json-en.html) list of source objects where each object has the following keys:\n - `source`: The source of the audio file. If it is an asset, simply use the full name of the asset.\n - `type`: The [type](!https://developer.mozilla.org/en-US/docs/Web/Media/Formats/Containers) of the audio file.\n\nFor example:\n```\n[{\n  \"source\": \"audio_name.mp3\",\n  \"type\": \"audio/mpeg\"\n}, {\n  \"source\":\"audio_name.ogg\",\n  \"type\": \"audio/ogg\"\n}]\n```\n',0,0,''),(43,243,'','Sets the margin of the Audio component',0,0,'Margin'),(63,170,'','Select a data table which will be linked to the style',0,0,''),(63,176,'1','If selected the entry list will load only the records entered by the user.',0,0,''),(63,181,NULL,'Filter the data source; Use SQL syntax',0,0,''),(63,222,'0','If enabled, the children are loaded inside a table.',0,0,''),(63,223,'','If the variable `scope` is defined, it serves as a prefix for naming the variables',0,0,''),(64,170,'','Select a data table which will be linked to the style',0,0,''),(64,176,'1','If selected the entry list will load only the records entered by the user.',0,0,''),(64,181,NULL,'Filter the data source; Use SQL syntax',0,0,''),(64,223,'','If the variable `scope` is defined, it serves as a prefix for naming the variables',0,0,''),(64,224,'record_id','The name of the url parameter that will be taken from the url. This parameter is used to filter the form based on the`record_id` and return one entry.',0,0,''),(75,190,NULL,'Json array object as each entry represnts a row which is passed to the children',0,0,''),(75,223,'','If the variable `scope` is defined, it serves as a prefix for naming the variables',0,0,''),(79,223,'','If the variable `scope` is defined, it serves as a prefix for naming the variables',0,0,''),(81,13,'Delete','The label for the delete button.',0,0,''),(81,28,'danger','The visual appearance of the button as predefined by [Bootstrap](!https://getbootstrap.com/docs/4.6/utilities/colors/).',0,0,''),(81,176,'1','If enabled the `entryRecordDelete` will be able to delete only entries that belong to the user.',0,0,''),(81,186,'','Confirmation title for the modal when the button is clicked',0,0,''),(81,187,'OK','Continue button for the modal when the button is clicked',0,0,''),(81,188,'Do you want to continue?','The message shown on the modal',0,0,''),(81,227,'','Cancel button label on the confirmation modal',0,0,''),(82,5,'Invalid verification code. Please try again.','The alert text that appears when the user enters invalid code.',0,0,''),(82,8,'Two-Factor Authentication','The main heading displayed at the top of the two-factor authentication form.',0,0,''),(82,25,'Please enter the 6-digit code sent to your email','The instruction text shown to users explaining what they need to do to complete the two-factor authentication process.',0,0,''),(82,233,'Code expires in','The text that appears before the timer showing how much time is left before the verification code expires.',0,0,''),(82,242,'','Sets the margin and padding of the TwoFactorAuth component',0,0,'Spacing'),(83,242,'','Sets the margin and padding of the Box component',0,0,'Spacing'),(83,284,'1','Use Mantine styling for the Box component. For more information check https://mantine.dev/core/box',0,1,'Use Mantine Style'),(83,287,'','Set text content over the children. For more information check https://mantine.dev/core/box',0,0,'Content'),(84,242,'','Sets the margin and padding of the Center component',0,0,'Spacing'),(84,273,NULL,'Sets the width of the Center component. Common values include percentages, auto, or content-based sizing. For more information check https://mantine.dev/core/center',0,0,'Width'),(84,274,NULL,'Sets the height of the Center component. Common values include percentages, auto, or content-based sizing. For more information check https://mantine.dev/core/center',0,0,'Height'),(84,309,'0','If `inline` prop is set, Center will use inline-flex instead of flex display. For more information check https://mantine.dev/core/center',0,0,'Inline'),(84,312,NULL,'Sets the minimum width of the Center component. For more information check https://mantine.dev/core/center',0,0,'Min Width'),(84,313,NULL,'Sets the minimum height of the Center component. For more information check https://mantine.dev/core/center',0,0,'Min Height'),(84,314,NULL,'Sets the maximum width of the Center component. For more information check https://mantine.dev/core/center',0,0,'Max Width'),(84,315,NULL,'Sets the maximum height of the Center component. For more information check https://mantine.dev/core/center',0,0,'Max Height'),(85,242,'','Sets the margin and padding of the Container component',0,0,'Spacing'),(85,264,NULL,'Sets the maximum width of the Container component. Choose from predefined responsive breakpoints or enter custom pixel values. For more information check https://mantine.dev/core/container',0,0,'Size'),(85,284,'1','If `useMantineStyle` prop is set Container will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/container',0,1,'Use Mantine Style'),(85,316,'0','If `fluid` prop is set Container will take 100% of parent width, ignoring size prop. For more information check https://mantine.dev/core/container',0,0,'Fluid'),(85,317,NULL,'Sets the horizontal padding of the Container component. Choose from predefined sizes or enter custom values. For more information check https://mantine.dev/core/container',0,0,'Horizontal Padding'),(85,318,NULL,'Sets the vertical padding of the Container component. Choose from predefined sizes or enter custom values. For more information check https://mantine.dev/core/container',0,0,'Vertical Padding'),(86,242,'','Sets the margin and padding of the Flex component',0,0,'Spacing'),(86,273,NULL,'Sets the width of the Flex component. For more information check https://mantine.dev/core/flex',0,0,'Width'),(86,274,NULL,'Sets the height of the Flex component. For more information check https://mantine.dev/core/flex',0,0,'Height'),(86,275,'sm','Sets the gap between flex items. For more information check https://mantine.dev/core/flex',0,0,'Gap'),(86,276,NULL,'Sets the justify-content property. For more information check https://mantine.dev/core/flex',0,0,'Justify'),(86,277,NULL,'Sets the align-items property. For more information check https://mantine.dev/core/flex',0,0,'Align Items'),(86,278,'row','Sets the flex-direction property. For more information check https://mantine.dev/core/flex',0,0,'Direction'),(86,279,'nowrap','Sets the flex-wrap property. For more information check https://mantine.dev/core/flex',0,0,'Wrap'),(86,284,'1','If `useMantineStyle` prop is set Flex will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/flex',0,1,'Use Mantine Style'),(87,242,'','Sets the margin and padding of the Group component',0,0,'Spacing'),(87,273,NULL,'Sets the width of the Group component. For more information check https://mantine.dev/core/group',0,0,'Width'),(87,274,NULL,'Sets the height of the Group component. For more information check https://mantine.dev/core/group',0,0,'Height'),(87,275,'sm','Sets the gap between group items. For more information check https://mantine.dev/core/group',0,0,'Gap'),(87,276,NULL,'Sets the justify-content property. For more information check https://mantine.dev/core/group',0,0,'Justify'),(87,277,NULL,'Sets the align-items property. For more information check https://mantine.dev/core/group',0,0,'Align Items'),(87,284,'1','If `useMantineStyle` prop is set Group will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/group',0,1,'Use Mantine Style'),(87,326,'0','If `wrap` prop is set Group will wrap items to the next line when there is not enough space. For more information check https://mantine.dev/core/group',0,0,'Wrap'),(87,327,'0','If `grow` prop is set Group will take all available space. For more information check https://mantine.dev/core/group',0,0,'Grow'),(88,242,'','Sets the margin and padding of the SimpleGrid component',0,0,'Spacing'),(88,273,NULL,'Sets the width of the simple-grid component. For more information check https://mantine.dev/core/simple-grid',0,0,'Width'),(88,274,NULL,'Sets the height of the simple-grid component. For more information check https://mantine.dev/core/simple-grid',0,0,'Height'),(88,284,'1','If `useMantineStyle` prop is set simple-grid will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/simple-grid',0,1,'Use Mantine Style'),(88,324,NULL,'Sets responsive breakpoints for different screen sizes. For more information check https://mantine.dev/core/simple-grid',0,0,'Breakpoints'),(88,325,'3','Sets the number of columns in the grid (1-6). For more information check https://mantine.dev/core/simple-grid',0,0,'Columns'),(88,328,NULL,'Sets the vertical spacing between grid items. For more information check https://mantine.dev/core/simple-grid',0,0,'Vertical Spacing'),(89,242,'','Sets the margin and padding of the Space component',0,0,'Spacing'),(89,264,'sm','Sets the size of the space. For more information check https://mantine.dev/core/space',0,0,'Size'),(89,284,'1','If `useMantineStyle` prop is set Space will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/space',0,1,'Use Mantine Style'),(89,329,'vertical','If `direction` prop is set Space will add horizontal spacing, otherwise it adds vertical spacing. For more information check https://mantine.dev/core/space',0,0,'Direction'),(90,242,'','Sets the margin and padding of the Stack component',0,0,'Spacing'),(90,273,NULL,'Sets the width of the Stack component. For more information check https://mantine.dev/core/stack',0,0,'Width'),(90,274,NULL,'Sets the height of the Stack component. For more information check https://mantine.dev/core/stack',0,0,'Height'),(90,275,'sm','Sets the gap between stack items. For more information check https://mantine.dev/core/stack',0,0,'Gap'),(90,276,NULL,'Sets the justify-content property. For more information check https://mantine.dev/core/stack',0,0,'Justify'),(90,277,NULL,'Sets the align-items property. For more information check https://mantine.dev/core/stack',0,0,'Align Items'),(90,284,'1','If `useMantineStyle` prop is set Stack will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/stack',0,1,'Use Mantine Style'),(91,242,'','Sets the margin and padding of the Grid component',0,0,'Spacing'),(91,273,NULL,'Sets the width of the Grid component. For more information check https://mantine.dev/core/grid',0,0,'Width'),(91,274,NULL,'Sets the height of the Grid component. For more information check https://mantine.dev/core/grid',0,0,'Height'),(91,275,'sm','Sets the gutter (spacing) between grid columns. For more information check https://mantine.dev/core/grid',0,0,'Gutter'),(91,276,NULL,'Sets the justify-content CSS property for the grid. For more information check https://mantine.dev/core/grid',0,0,'Justify'),(91,277,NULL,'Sets the align-items CSS property for the grid. For more information check https://mantine.dev/core/grid',0,0,'Align'),(91,284,'1','If `useMantineStyle` prop is set Grid will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/grid',0,1,'Use Mantine Style'),(91,325,'12','Sets the total number of columns in the grid (default 12). For more information check https://mantine.dev/core/grid',0,0,'Columns'),(91,330,'visible','Sets the overflow CSS property for the grid container. For more information check https://mantine.dev/core/grid',0,0,'Overflow'),(92,242,'','Sets the margin and padding of the GridColumn component',0,0,'Spacing'),(92,273,NULL,'Sets the width of the Grid.Col component. For more information check https://mantine.dev/core/grid',0,0,'Width'),(92,274,NULL,'Sets the height of the Grid.Col component. For more information check https://mantine.dev/core/grid',0,0,'Height'),(92,284,'1','If `useMantineStyle` prop is set Grid.Col will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/grid',0,1,'Use Mantine Style'),(92,331,'1','Sets the span (width) of the column. Number from 1-12 or \"auto\"/\"content\". For more information check https://mantine.dev/core/grid',0,0,'Span'),(92,332,'0','Sets the offset (left margin) of the column. Number from 0-11. For more information check https://mantine.dev/core/grid',0,0,'Offset'),(92,333,NULL,'Sets the order of the column for reordering. Number from 1-12. For more information check https://mantine.dev/core/grid',0,0,'Order'),(92,334,'0','If `grow` prop is set, column will grow to fill the remaining space in the row. For more information check https://mantine.dev/core/grid',0,0,'Grow'),(93,243,'','Sets the margin of the Tabs component',0,0,'Margin'),(93,263,'blue','Sets the color of the tabs. For more information check https://mantine.dev/core/tabs',0,0,'Color'),(93,265,'sm','Sets the border radius of the tabs. For more information check https://mantine.dev/core/tabs',0,0,'Radius'),(93,268,'horizontal','Sets the orientation of the tabs. For more information check https://mantine.dev/core/tabs',0,0,'Orientation'),(93,273,NULL,'Sets the width of the Tabs component. For more information check https://mantine.dev/core/tabs',0,0,'Width'),(93,274,NULL,'Sets the height of the Tabs component. For more information check https://mantine.dev/core/tabs',0,0,'Height'),(93,284,'1','If `useMantineStyle` prop is set Tabs will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/tabs',0,1,'Use Mantine Style'),(93,335,'default','Sets the variant of the tabs. For more information check https://mantine.dev/core/tabs',0,0,'Variant'),(94,8,NULL,'Sets the label/content of the tab that will be displayed to users. For more information check https://mantine.dev/core/tabs',0,0,'Label'),(94,243,'','Sets the margin of the Tab component',0,0,'Margin'),(94,266,NULL,'Sets the left section (icon) of the tab. For more information check https://mantine.dev/core/tabs',0,0,'Left Icon'),(94,267,NULL,'Sets the right section (icon) of the tab. For more information check https://mantine.dev/core/tabs',0,0,'Right Icon'),(94,273,NULL,'Sets the width of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs',0,0,'Width'),(94,274,NULL,'Sets the height of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs',0,0,'Height'),(94,284,'1','If `useMantineStyle` prop is set Tabs.Tab will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/tabs',0,1,'Use Mantine Style'),(94,337,'0','If `disabled` prop is set, tab will be disabled. For more information check https://mantine.dev/core/tabs',0,0,'Disabled'),(95,243,'','Sets the margin of the AspectRatio component',0,0,'Margin'),(95,284,'1','If `useMantineStyle` prop is set AspectRatio will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/aspect-ratio',0,1,'Use Mantine Style'),(95,338,'16/9','Sets the aspect ratio of the component. For more information check https://mantine.dev/core/aspect-ratio',0,0,'Ratio'),(96,8,NULL,'If this field is set, a this text will be rendered inside the chip.',0,0,'Label'),(96,56,'0','Makes the chip field required for form submission',0,0,'Required'),(96,57,NULL,'Field name for form submission. Either a custom value or falls back to section-${style.id}',0,0,'Field Name'),(96,58,NULL,'Default value for the chip field',0,0,'Value'),(96,142,'0','If `disabled` prop is set Chip will be disabled. For more information check https://mantine.dev/core/chip',0,0,'Disabled'),(96,241,'16','Sets the size of the chip icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more information check https://mantine.dev/core/chip',0,0,'Icon Size'),(96,243,'','Sets the margin of the Chip component',0,0,'Margin'),(96,261,'top','Sets the position where the tooltip will appear relative to the chip.',0,0,'Tooltip Position'),(96,263,'blue','Sets the color of the chip. For more information check https://mantine.dev/core/chip',0,0,'Color'),(96,264,'sm','Sets the size of the chip. For more information check https://mantine.dev/core/chip',0,0,'Size'),(96,265,'sm','Sets the border radius of the chip. For more information check https://mantine.dev/core/chip',0,0,'Radius'),(96,266,NULL,'Sets the icon for the chip. For more information check https://mantine.dev/core/chip',0,0,'Icon'),(96,284,'1','If `useMantineStyle` prop is set Chip will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/chip',0,1,'Use Mantine Style'),(96,339,'filled','Sets the variant of the chip. For more information check https://mantine.dev/core/chip',0,0,'Variant'),(96,340,'0','If `checked` prop is set, chip will be in checked state. For more information check https://mantine.dev/core/chip',0,0,'Checked'),(96,341,'1','Value to submit when chip is checked/selected. For more information check https://mantine.dev/core/chip',0,0,'On Value'),(96,342,'0','Value to submit when chip is unchecked/unselected. For more information check https://mantine.dev/core/chip',0,0,'Off Value'),(97,8,'','Sets the label of the input field. For more information check https://mantine.dev/core/color-input',0,0,'Label'),(97,55,'Pick a color','Sets the placeholder text for the color input. For more information check https://mantine.dev/core/color-input',0,0,'Placeholder'),(97,56,'0','If set, the color selection becomes required for form submission',0,0,'Required'),(97,57,'','Field name for form submission',0,0,'Name'),(97,58,'','Default color value for the color input. Supports hex, rgba, or hsl formats',0,0,'Value'),(97,106,'','Description text displayed below the input field',0,0,'Description'),(97,142,'0','If `disabled` prop is set color-input will be disabled. For more information check https://mantine.dev/core/color-input',0,0,'Disabled'),(97,243,'','Sets the margin of the ColorInput component',0,0,'Margin'),(97,264,'sm','Sets the size of the color input. For more information check https://mantine.dev/core/color-input',0,0,'Size'),(97,265,'sm','Sets the border radius of the color input. For more information check https://mantine.dev/core/color-input',0,0,'Radius'),(97,269,'hex','Sets the format of the color input. For more information check https://mantine.dev/core/color-input',0,0,'Format'),(97,284,'1','If `useMantineStyle` prop is set color-input will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/color-input',0,1,'Use Mantine Style'),(99,8,'','Sets the label of the input field. For more information check https://mantine.dev/core/color-picker',0,0,'Label'),(99,56,'0','If set, the color selection becomes required for form submission',0,0,'Required'),(99,57,'','Field name for form submission',0,0,'Name'),(99,58,'','Default color value for the color picker. Supports hex, rgba, or hsl formats',0,0,'Value'),(99,106,'','Description text displayed below the input field',0,0,'Description'),(99,243,'','Sets the margin of the ColorPicker component',0,0,'Margin'),(99,264,'sm','Sets the size of the color picker. For more information check https://mantine.dev/core/color-picker',0,0,'Size'),(99,269,'hex','Sets the format of the color picker. For more information check https://mantine.dev/core/color-picker',0,0,'Format'),(99,280,'0','If set, the color picker will take the full width of its container. For more information check https://mantine.dev/core/color-picker',0,0,'Full Width'),(99,284,'1','If `useMantineStyle` prop is set color-picker will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/color-picker',0,1,'Use Mantine Style'),(99,353,'7','Sets the number of swatches per row. For more information check https://mantine.dev/core/color-picker',0,0,'Swatches Per Row'),(99,354,'[\"#2e2e2e\", \"#868e96\", \"#fa5252\", \"#e64980\", \"#be4bdb\", \"#7950f2\", \"#4c6ef5\", \"#228be6\"]','Array of predefined color swatches. Enter as JSON array of hex color strings. For more information check https://mantine.dev/core/color-picker',0,0,'Color Swatches'),(99,355,'Saturation','Accessibility label for the saturation slider. For more information check https://mantine.dev/core/color-picker',0,0,'Saturation Label'),(99,356,'Hue','Accessibility label for the hue slider. For more information check https://mantine.dev/core/color-picker',0,0,'Hue Label'),(99,357,'Alpha','Accessibility label for the alpha slider. For more information check https://mantine.dev/core/color-picker',0,0,'Alpha Label'),(100,8,NULL,'Sets the legend/title of the fieldset. For more information check https://mantine.dev/core/fieldset',0,0,'Legend'),(100,142,'0','If set, disables all inputs and buttons inside the fieldset. For more information check https://mantine.dev/core/fieldset',0,0,'Disabled'),(100,242,'','Sets the margin and padding of the Fieldset component',0,0,'Spacing'),(100,265,'sm','Sets the border radius of the fieldset. For more information check https://mantine.dev/core/fieldset',0,0,'Radius'),(100,284,'1','If `useMantineStyle` prop is set Fieldset will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/fieldset',0,1,'Use Mantine Style'),(100,358,'default','Sets the variant of the fieldset. For more information check https://mantine.dev/core/fieldset',0,0,'Variant'),(101,8,NULL,'Sets the label for the file input. For more information check https://mantine.dev/core/file-input',0,0,'Label'),(101,55,'Select files','Sets the placeholder text for the file input. For more information check https://mantine.dev/core/file-input',0,0,'Placeholder'),(101,56,'0','If `is_required` prop is set FileInput will be required. For more information check https://mantine.dev/core/file-input',0,0,'Required'),(101,57,NULL,'Sets the name attribute for the file input, used for form submission. If not set, falls back to section-${style.id}. For more information check https://mantine.dev/core/file-input',0,0,'Name'),(101,106,NULL,'Sets the description for the file input. For more information check https://mantine.dev/core/file-input',0,0,'Description'),(101,142,'0','If `disabled` prop is set FileInput will be disabled. For more information check https://mantine.dev/core/file-input',0,0,'Disabled'),(101,243,'','Sets the margin of the FileInput component',0,0,'Margin'),(101,264,'sm','Sets the size of the file input. For more information check https://mantine.dev/core/file-input',0,0,'Size'),(101,265,'sm','Sets the border radius of the file input. For more information check https://mantine.dev/core/file-input',0,0,'Radius'),(101,266,NULL,'Sets the icon displayed in the left section of the file input. For more information check https://mantine.dev/core/file-input',0,0,'Left Icon'),(101,267,NULL,'Sets the icon displayed in the right section of the file input. For more information check https://mantine.dev/core/file-input',0,0,'Right Icon'),(101,284,'1','If `useMantineStyle` prop is set FileInput will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/file-input',0,1,'Use Mantine Style'),(101,359,'0','If `multiple` prop is set, multiple files can be selected. For more information check https://mantine.dev/core/file-input',0,0,'Multiple'),(101,360,NULL,'Sets the accepted file types for the file input. Choose from presets or enter custom MIME types separated by commas. For more information check https://mantine.dev/core/file-input',0,0,'Accept'),(101,361,'1','If set, displays a clear button when files are selected. For more information check https://mantine.dev/core/file-input',0,0,'Clearable'),(101,362,NULL,'Sets the maximum file size in bytes. Choose from presets or enter a custom value. For more information check https://mantine.dev/core/file-input',0,0,'Max File Size'),(101,363,NULL,'Sets the maximum number of files that can be selected when multiple is enabled. Choose from presets or enter a custom value. For more information check https://mantine.dev/core/file-input',0,0,'Max Files'),(101,364,'0','If set, enables drag and drop functionality for file uploads. For more information check https://mantine.dev/core/file-input',0,0,'Enable Drag & Drop'),(102,8,'','Sets the label of the input field. For more information check https://mantine.dev/core/number-input',0,0,'Label'),(102,55,'Enter number','Sets the placeholder text for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Placeholder'),(102,56,'0','If set, the number input becomes required for form submission',0,0,'Required'),(102,57,'','Field name for form submission',0,0,'Name'),(102,58,'','Default numeric value for the number input',0,0,'Value'),(102,106,'','Description text displayed below the input field',0,0,'Description'),(102,142,'0','If `disabled` prop is set NumberInput will be disabled. For more information check https://mantine.dev/core/number-input',0,0,'Disabled'),(102,243,'','Sets the margin of the NumberInput component',0,0,'Margin'),(102,264,'sm','Sets the size of the number input. For more information check https://mantine.dev/core/number-input',0,0,'Size'),(102,265,'sm','Sets the border radius of the number input. For more information check https://mantine.dev/core/number-input',0,0,'Radius'),(102,270,NULL,'Sets the minimum value for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Min'),(102,271,NULL,'Sets the maximum value for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Max'),(102,272,'1','Sets the step value for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Step'),(102,284,'1','If `useMantineStyle` prop is set NumberInput will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/number-input',0,1,'Use Mantine Style'),(102,368,'2','Sets the number of decimal places for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Decimal Scale'),(102,369,'strict','Sets the clamp behavior for the number input. For more information check https://mantine.dev/core/number-input',0,0,'Clamp Behavior'),(103,8,NULL,'Sets the label for the radio button or radio group. For more information check https://mantine.dev/core/radio',0,0,'Label'),(103,56,'0','Makes the radio button or radio group required for form submission. For more information check https://mantine.dev/core/radio',0,0,'Required'),(103,57,NULL,'Sets the form field name for the radio button or radio group. For more information check https://mantine.dev/core/radio',0,0,'Field Name'),(103,58,NULL,'Sets the initial selected value for the radio button or radio group. For more information check https://mantine.dev/core/radio',0,0,'Value'),(103,106,NULL,'Sets the description for the radio button. For more information check https://mantine.dev/core/radio',0,0,'Description'),(103,142,'0','If `disabled` prop is set Radio will be disabled. For more information check https://mantine.dev/core/radio',0,0,'Disabled'),(103,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(103,243,'','Sets the margin of the Radio component',0,0,'Margin'),(103,261,'top','Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip',0,0,'Tooltip Position'),(103,263,'blue','Sets the color of the radio button or radio group. For more information check https://mantine.dev/core/radio',0,0,'Color'),(103,264,'sm','Sets the size of the radio button or radio group. For more information check https://mantine.dev/core/radio',0,0,'Size'),(103,268,'vertical','Sets the orientation of the radio group (when options are provided). For more information check https://mantine.dev/core/radio',0,0,'Orientation'),(103,284,'1','If `useMantineStyle` prop is set Radio will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/radio',0,1,'Use Mantine Style'),(103,289,'[{\"value\":\"option1\",\"text\":\"Option 1\",\"description\":\"First choice description\"},{\"value\":\"option2\",\"text\":\"Option 2\",\"description\":\"Second choice description\"},{\"value\":\"option3\",\"text\":\"Option 3\",\"description\":\"Third choice description\"}]','Sets the options for the radio group as JSON array. If provided, renders as Radio.Group. Format: [{\"value\":\"1\",\"text\":\"Item1\",\"description\":\"Optional description\"}]. For more information check https://mantine.dev/core/radio',0,0,'Options'),(103,293,'1','When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the radio buttons.',0,0,'Use Input Wrapper'),(103,300,NULL,'Sets the tooltip text for the radio component. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip',0,0,'Tooltip Label'),(103,371,'right','Sets the position of the label relative to the radio button. For more information check https://mantine.dev/core/radio',0,0,'Label Position'),(103,372,'0','If set, renders radio options as card components instead of standard radio buttons. For more information check https://mantine.dev/core/radio',0,0,'Use Radio Card'),(103,373,'default','Sets the visual variant of the radio component. For more information check https://mantine.dev/core/radio',0,0,'Variant'),(104,8,'','Sets the label text for the range slider input field',0,0,'Label'),(104,57,'','Sets the name attribute for the range slider input field, used for form integration',0,0,'Name'),(104,58,'','Sets the value attribute for the range slider input field, used for form integration. Example: [20, 40]',0,0,'Value'),(104,106,'','Sets the description text displayed below the range slider input field',0,0,'Description'),(104,142,'0','If `disabled` prop is set range-slider will be disabled. For more information check https://mantine.dev/core/range-slider',0,0,'Disabled'),(104,243,'','Sets the margin of the RangeSlider component',0,0,'Margin'),(104,263,'blue','Sets the color of the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Color'),(104,264,'sm','Sets the size of the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Size'),(104,265,'sm','Sets the border radius of the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Radius'),(104,270,'0','Sets the minimum value for the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Min'),(104,271,'100','Sets the maximum value for the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Max'),(104,272,'1','Sets the step value for the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Step'),(104,284,'1','If `useMantineStyle` prop is set range-slider will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/range-slider',0,1,'Use Mantine Style'),(104,374,'','Translatable values for range slider marks in JSON format. Example: [{\"value\":25,\"label\":\"Low\"},{\"value\":50,\"label\":\"Medium\"},{\"value\":75,\"label\":\"High\"}]. For more information check https://mantine.dev/core/range-slider',0,0,'Marks Values'),(104,375,'1','If enabled, shows label on hover for range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Show Label on Hover'),(104,376,'0','If enabled, labels are always visible on the range slider. For more information check https://mantine.dev/core/range-slider',0,0,'Labels Always On'),(104,377,'0','If enabled, inverts the range slider track and thumb colors. For more information check https://mantine.dev/core/range-slider',0,0,'Inverted'),(105,8,'','Sets the label text for the slider input field',0,0,'Label'),(105,56,'0','If enabled the form can only be submitted if the slider has a value',0,0,'Required'),(105,57,'','Sets the name attribute for the slider input field, used for form integration',0,0,'Name'),(105,58,'','Sets the value attribute for the slider input field, used for form integration. Example: 50',0,0,'Value'),(105,106,'','Sets the description text displayed below the slider input field',0,0,'Description'),(105,142,'0','If set, the slider will be disabled. For more information check https://mantine.dev/core/slider',0,0,'Disabled'),(105,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(105,243,'','Sets the margin of the Slider component',0,0,'Margin'),(105,263,'blue','Sets the color of the slider. For more information check https://mantine.dev/core/slider',0,0,'Color'),(105,264,'sm','Sets the size of the slider. For more information check https://mantine.dev/core/slider',0,0,'Size'),(105,265,'sm','Sets the border radius of the slider. For more information check https://mantine.dev/core/slider',0,0,'Radius'),(105,270,'0','Sets the minimum value for the slider. For more information check https://mantine.dev/core/slider',0,0,'Min'),(105,271,'100','Sets the maximum value for the slider. For more information check https://mantine.dev/core/slider',0,0,'Max'),(105,272,'1','Sets the step value for the slider. For more information check https://mantine.dev/core/slider',0,0,'Step'),(105,284,'1','Use Mantine styling for the slider component',0,1,'Use Mantine Style'),(105,378,'','Translatable values for slider marks in JSON format. Example: [{\"value\":25,\"label\":\"Low\"},{\"value\":50,\"label\":\"Medium\"},{\"value\":75,\"label\":\"High\"}]. For more information check https://mantine.dev/core/slider',0,0,'Marks Values'),(105,379,'1','If enabled, shows label on hover for slider. For more information check https://mantine.dev/core/slider',0,0,'Show Label on Hover'),(105,380,'0','If enabled, labels are always visible on the slider. For more information check https://mantine.dev/core/slider',0,0,'Labels Always On'),(105,381,'0','If enabled, inverts the slider track and thumb colors. For more information check https://mantine.dev/core/slider',0,0,'Inverted'),(105,383,'0','If enabled, the slider will be required for form submission. For more information check https://mantine.dev/core/slider',0,0,'Required'),(106,8,NULL,'Label text for the rating input field',0,0,'Label'),(106,57,NULL,'Name attribute for the rating input field (required for form submission)',0,0,'Name'),(106,58,NULL,'Initial value for the rating (number between 0 and count)',0,0,'Value'),(106,106,NULL,'Description text for the rating input field',0,0,'Description'),(106,142,'0','If set, the rating will be disabled and cannot be interacted with',0,0,'Disabled'),(106,243,'','Sets the margin of the Rating component',0,0,'Margin'),(106,259,'0','If set, the rating will be read-only and cannot be changed',0,0,'Read Only'),(106,263,'yellow','Sets the color of the rating. For more information check https://mantine.dev/core/rating',0,0,'Color'),(106,264,'sm','Sets the size of the rating. For more information check https://mantine.dev/core/rating',0,0,'Size'),(106,284,'1','If `useMantineStyle` prop is set Rating will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/rating',0,1,'Use Mantine Style'),(106,384,'5','Sets the number of stars in the rating. For more information check https://mantine.dev/core/rating',0,0,'Count'),(106,385,'1','Sets the fraction precision for the rating. For more information check https://mantine.dev/core/rating',0,0,'Fractions'),(106,386,'0','If enabled, uses smiley face icons (sad to happy) instead of stars. When enabled, the count is automatically fixed to 5. For more information check https://mantine.dev/core/rating',0,0,'Use Smiles'),(106,387,NULL,'Sets the icon for unselected rating items. For more information check https://mantine.dev/core/rating',0,0,'Empty Icon'),(106,388,NULL,'Sets the icon for selected rating items. For more information check https://mantine.dev/core/rating',0,0,'Full Icon'),(106,389,'0','If enabled, only selected items will be highlighted, unselected items will be dimmed. For more information check https://mantine.dev/core/rating',0,0,'Highlight Selected Only'),(107,8,NULL,'Sets the label text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control',0,0,'Label'),(107,56,'0','If set, the segmented control will be required for form validation. For more information check https://mantine.dev/core/segmented-control',0,0,'Required'),(107,57,NULL,'Sets the name for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control',0,0,'Name'),(107,58,NULL,'Sets the default selected value for the segmented control. Either a custom value or falls back to section-${style.id}. For more information check https://mantine.dev/core/segmented-control',0,0,'Value'),(107,106,NULL,'Sets the description text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control',0,0,'Description'),(107,142,'0','If `disabled` prop is set segmented-control will be disabled. For more information check https://mantine.dev/core/segmented-control',0,0,'Disabled'),(107,243,'','Sets the margin of the SegmentedControl component',0,0,'Margin'),(107,259,'0','If set, the segmented control will be readonly. For more information check https://mantine.dev/core/segmented-control',0,0,'Read Only'),(107,263,'blue','Sets the color of the segmented control. For more information check https://mantine.dev/core/segmented-control',0,0,'Color'),(107,264,'sm','Sets the size of the segmented control. For more information check https://mantine.dev/core/segmented-control',0,0,'Size'),(107,265,'sm','Sets the border radius of the segmented control. For more information check https://mantine.dev/core/segmented-control',0,0,'Radius'),(107,268,'horizontal','Sets the orientation of the segmented control. For more information check https://mantine.dev/core/segmented-control',0,0,'Orientation'),(107,284,'1','If `useMantineStyle` prop is set segmented-control will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/segmented-control',0,1,'Use Mantine Style'),(107,290,'[{\"value\":\"option1\",\"label\":\"Option 1\"},{\"value\":\"option2\",\"label\":\"Option 2\"},{\"value\":\"option3\",\"label\":\"Option 3\"}]','Sets the data/options for the segmented control as JSON array. Format: [{\"value\":\"option1\",\"label\":\"Option 1\"}]. For more information check https://mantine.dev/core/segmented-control',0,0,'Data'),(107,391,'0','If set, adds border around each segmented control item. For more information check https://mantine.dev/core/segmented-control',0,0,'Item Border'),(108,8,NULL,'Sets the label for the switch. For more information check https://mantine.dev/core/switch',0,0,'Label'),(108,56,'0','If set, the switch will be marked as required for form validation. For more information check https://mantine.dev/core/switch',0,0,'Required'),(108,57,NULL,'Sets the name attribute for the switch input field, used for form integration. For more information check https://mantine.dev/core/switch',0,0,'Name'),(108,58,NULL,'Sets the current value of the switch for form integration. For more information check https://mantine.dev/core/switch',0,0,'Current Value'),(108,106,NULL,'Sets the description for the switch. For more information check https://mantine.dev/core/switch',0,0,'Description'),(108,142,'0','If `disabled` prop is set Switch will be disabled. For more information check https://mantine.dev/core/switch',0,0,'Disabled'),(108,243,'','Sets the margin of the Switch component',0,0,'Margin'),(108,263,'blue','Sets the color of the switch. For more information check https://mantine.dev/core/switch',0,0,'Color'),(108,264,'sm','Sets the size of the switch. For more information check https://mantine.dev/core/switch',0,0,'Size'),(108,265,'sm','Sets the border radius for the switch. For more information check https://mantine.dev/core/switch',0,0,'Radius'),(108,284,'1','If `useMantineStyle` prop is set Switch will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/switch',0,1,'Use Mantine Style'),(108,285,'On','Sets the label when switch is on. For more information check https://mantine.dev/core/switch',0,0,'On Label'),(108,286,'Off','Sets the label when switch is off. For more information check https://mantine.dev/core/switch',0,0,'Off Label'),(108,293,'0','When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the switch.',0,0,'Use Input Wrapper'),(108,395,'left','Sets the position of the label relative to the switch. For more information check https://mantine.dev/core/switch',0,0,'Label Position'),(108,396,'1','Sets the value that represents the on/checked state of the switch. When the current value equals this value, the switch will be checked. For more information check https://mantine.dev/core/switch',0,0,'On Value'),(109,8,'','Sets the label of the input field. For more information check https://mantine.dev/core/combobox',0,0,'Label'),(109,55,'Select option','Sets the placeholder text for the combobox. For more information check https://mantine.dev/core/combobox',0,0,'Placeholder'),(109,56,'0','If set, the selection becomes required for form submission',0,0,'Required'),(109,57,'','Field name for form submission',0,0,'Name'),(109,58,'','Default value for the combobox',0,0,'Value'),(109,106,'','Description text displayed below the input field',0,0,'Description'),(109,142,'0','If `disabled` prop is set Combobox will be disabled. For more information check https://mantine.dev/core/combobox',0,0,'Disabled'),(109,243,'','Sets the margin of the Combobox component',0,0,'Margin'),(109,284,'1','If `useMantineStyle` prop is set Combobox will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/combobox',0,1,'Use Mantine Style'),(109,397,'[{\"value\":\"option1\",\"text\":\"Option 1\"},{\"value\":\"option2\",\"text\":\"Option 2\"}]','Sets the data/options for the combobox as JSON array. Format: [{\"value\":\"option1\",\"text\":\"Option 1\"}]. For more information check https://mantine.dev/core/combobox',0,0,'Options'),(109,398,'0','If set, allows selecting multiple options. Values will be joined with the separator.',0,0,'Multi-Select'),(109,399,'1','If set, enables search functionality in the dropdown.',0,0,'Searchable'),(109,400,'0','If set, allows users to create new options not in the predefined list.',0,0,'Creatable'),(109,401,'0','If set, allows clearing the selection in single-select mode.',0,0,'Clearable'),(109,402,' ','Separator used to join multiple selected values (only applies when multi-select is enabled).',0,0,'Separator'),(109,403,NULL,'Sets the maximum number of values that can be selected. For more information check https://mantine.dev/core/multi-select',0,0,'Max Values'),(110,86,'0','If `openInNewTab` prop is set ActionIcon will open in a new tab. For more information check https://mantine.dev/core/action-icon',0,0,'Open in New Tab'),(110,142,'0','If `disabled` prop is set ActionIcon will be disabled. For more information check https://mantine.dev/core/action-icon',0,0,'Disabled'),(110,191,'#','Select a page keyword to link to. For more information check https://mantine.dev/core/action-icon',0,0,'URL'),(110,243,'','Sets the margin of the ActionIcon component',0,0,'Margin'),(110,262,'subtle','Sets the variant of the action icon. For more information check https://mantine.dev/core/action-icon',0,0,'Variant'),(110,263,'blue','Sets the color of the action icon. For more information check https://mantine.dev/core/action-icon',0,0,'Color'),(110,264,'sm','Sets the size of the action icon. For more information check https://mantine.dev/core/action-icon',0,0,'Size'),(110,265,'sm','Sets the border radius of the action icon. For more information check https://mantine.dev/core/action-icon',0,0,'Radius'),(110,266,NULL,'Sets the icon for the action icon. For more information check https://mantine.dev/core/action-icon',0,0,'Icon'),(110,283,'0','If `isLink` prop is set ActionIcon will be a link. For more information check https://mantine.dev/core/action-icon',0,0,'Is Link'),(110,284,'1','If `useMantineStyle` prop is set ActionIcon will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/action-icon',0,1,'Use Mantine Style'),(110,404,'0','If `loading` prop is set, action icon will show loading state. For more information check https://mantine.dev/core/action-icon',0,0,'Loading'),(111,22,NULL,'Sets the title for the notification. For more information check https://mantine.dev/core/notification',0,0,'Title'),(111,242,'','Sets the margin and padding of the Notification component',0,0,'Spacing'),(111,256,'0','If `withBorder` prop is set, notification will have a border. For more information check https://mantine.dev/core/notification',0,0,'With Border'),(111,263,'blue','Sets the color of the notification. For more information check https://mantine.dev/core/notification',0,0,'Color'),(111,265,'sm','Sets the border radius of the notification. For more information check https://mantine.dev/core/notification',0,0,'Radius'),(111,266,NULL,'Sets the icon for the notification. If no icon is selected, a default icon matching the color will be used. For more information check https://mantine.dev/core/notification',0,0,'Icon'),(111,284,'1','If `useMantineStyle` prop is set Notification will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/notification',0,1,'Use Mantine Style'),(111,287,NULL,'Sets the main content/message of the notification. For more information check https://mantine.dev/core/notification',0,0,'Content'),(111,405,'0','If `loading` prop is set, notification will show loading state. For more information check https://mantine.dev/core/notification',0,0,'Loading'),(111,406,'1','If `withCloseButton` prop is set, notification will have a close button. For more information check https://mantine.dev/core/notification',0,0,'With Close Button'),(112,58,NULL,'The content/message of the alert',0,0,'Message'),(112,242,'','Sets the margin and padding of the Alert component',0,0,'Spacing'),(112,262,'light','Sets the variant of the alert. For more information check https://mantine.dev/core/alert',0,0,'Variant'),(112,263,'blue','Sets the color of the alert. For more information check https://mantine.dev/core/alert',0,0,'Color'),(112,264,'md','Sets the size of the alert. For more information check https://mantine.dev/core/alert',0,0,'Size'),(112,265,'sm','Sets the border radius of the alert. For more information check https://mantine.dev/core/alert',0,0,'Radius'),(112,266,NULL,'Sets the icon for the alert. For more information check https://mantine.dev/core/alert',0,0,'Icon'),(112,284,'1','Use Mantine styling for the alert component',0,1,'Use Mantine Style'),(112,287,NULL,'Sets the main content/message of the alert. For more information check https://mantine.dev/core/alert',0,0,'Content'),(112,407,NULL,'Sets the title of the alert. For more information check https://mantine.dev/core/alert',0,0,'Title'),(112,408,'0','If set, the alert will have a close button. For more information check https://mantine.dev/core/alert',0,0,'With Close Button'),(112,611,'0','If `withCloseButton` prop is set, close button will be rendered. For more information check https://mantine.dev/core/alert',0,0,'With Close Button'),(113,242,'','Sets the margin and padding of the Accordion component',0,0,'Spacing'),(113,265,'sm','Sets the border radius of the accordion. For more information check https://mantine.dev/core/accordion',0,0,'Radius'),(113,284,'1','Use Mantine styling for the accordion component',0,1,'Use Mantine Style'),(113,409,'default','Sets the variant of the accordion. For more information check https://mantine.dev/core/accordion',0,0,'Variant'),(113,410,'0','If `multiple` prop is set, multiple panels can be opened simultaneously. For more information check https://mantine.dev/core/accordion',0,0,'Multiple'),(113,411,'right','Sets the position of the chevron icon. For more information check https://mantine.dev/core/accordion',0,0,'Chevron Position'),(113,412,'16','Sets the size of the chevron icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more information check https://mantine.dev/core/accordion',0,0,'Chevron Size'),(113,413,'0','If set, chevron icon will not rotate when item is opened. For more information check https://mantine.dev/core/accordion',0,0,'Disable Chevron Rotation'),(113,414,'1','If set, keyboard navigation will loop from last to first item and vice versa. For more information check https://mantine.dev/core/accordion',0,0,'Loop Navigation'),(113,415,'200','Sets the duration of expand/collapse transition in milliseconds. Choose from preset durations or enter a custom value (e.g., 100, 150, 200, 300, 400, 500). For more information check https://mantine.dev/core/accordion',0,0,'Transition Duration'),(113,416,NULL,'Sets the initially opened item(s). Use comma-separated values for multiple items (e.g., \"item-1,item-2\"). For more information check https://mantine.dev/core/accordion',0,0,'Default Open Items'),(114,8,NULL,'Sets the label text displayed in the accordion control. For more information check https://mantine.dev/core/accordion',0,0,'Label'),(114,142,'0','If set, the accordion item will be disabled and cannot be opened. For more information check https://mantine.dev/core/accordion',0,0,'Disabled'),(114,242,'','Sets the margin and padding of the AccordionItem component',0,0,'Spacing'),(114,284,'1','Use Mantine styling for the accordion item component',0,1,'Use Mantine Style'),(114,417,NULL,'Unique identifier for the accordion item. Either a custom value or falls back to section-${style.id}. This value is used to control which item is open. For more information check https://mantine.dev/core/accordion',0,0,'Item Value'),(114,418,NULL,'Sets the icon displayed next to the label in the accordion control. For more information check https://mantine.dev/core/accordion',0,0,'Icon'),(115,30,'Avatar','Sets the alt text for the avatar image. For more information check https://mantine.dev/core/avatar',0,0,'Alt Text'),(115,53,NULL,'Sets the image source for the avatar. Has priority over icon and initials fields. Either img_src, icon, or initials can be used. If img_src contains a URL, it will be used as the avatar image. If img_src contains text, it will generate initials. For more information check https://mantine.dev/core/avatar',0,0,'Image Source'),(115,243,'','Sets the margin of the Avatar component',0,0,'Margin'),(115,262,'light','Sets the variant of the avatar. For more information check https://mantine.dev/core/avatar',0,0,'Variant'),(115,263,'blue','Sets the color of the avatar. For more information check https://mantine.dev/core/avatar',0,0,'Color'),(115,264,'sm','Sets the size of the avatar. For more information check https://mantine.dev/core/avatar',0,0,'Size'),(115,265,'50%','Sets the border radius of the avatar. For more information check https://mantine.dev/core/avatar',0,0,'Radius'),(115,266,NULL,'Sets the icon for the avatar. Used only when img_src is empty. Either img_src, icon, or initials can be used. Icon will be displayed as the avatar content. For more information check https://mantine.dev/core/avatar',0,0,'Icon'),(115,284,'1','If `useMantineStyle` prop is set Avatar will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/avatar',0,1,'Use Mantine Style'),(115,419,'U','Sets custom text to generate initials for the avatar. Used only when neither img_src nor icon is set. Enter full names (e.g., \"Stefan Kod\" shows \"SK\", \"John Doe\" shows \"JD\", \"test\" shows \"T\"). Either img_src, icon, or initials can be used. For more information check https://mantine.dev/core/avatar',0,0,'Initials'),(116,53,NULL,'Sets the background image source. For more information check https://mantine.dev/core/background-image',0,0,'Image Source'),(116,242,'','Sets the margin and padding of the BackgroundImage component',0,0,'Spacing'),(116,265,'sm','Sets the border radius of the background image container. For more information check https://mantine.dev/core/background-image',0,0,'Radius'),(116,284,'1','If `useMantineStyle` prop is set background-image will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/background-image',0,1,'Use Mantine Style'),(117,8,NULL,'Sets the label for the badge. For more information check https://mantine.dev/core/badge',0,0,'Label'),(117,243,'','Sets the margin of the Badge component',0,0,'Margin'),(117,262,'filled','Sets the variant of the badge. For more information check https://mantine.dev/core/badge',0,0,'Variant'),(117,263,'blue','Sets the color of the badge. For more information check https://mantine.dev/core/badge',0,0,'Color'),(117,264,'sm','Sets the size of the badge. For more information check https://mantine.dev/core/badge',0,0,'Size'),(117,265,'xl','Sets the border radius of the badge. For more information check https://mantine.dev/core/badge',0,0,'Radius'),(117,266,NULL,'Sets the left section icon for the badge. For more information check https://mantine.dev/core/badge',0,0,'Left Icon'),(117,267,NULL,'Sets the right section icon for the badge. For more information check https://mantine.dev/core/badge',0,0,'Right Icon'),(117,282,'0','If `autoContrast` prop is set Badge will automatically adjust the contrast of the badge to the background color. For more information check https://mantine.dev/core/badge',0,0,'Auto Contrast'),(117,284,'1','If `useMantineStyle` prop is set Badge will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/badge',0,1,'Use Mantine Style'),(118,8,'','Sets the label text displayed in the indicator. For more information check https://mantine.dev/core/indicator',0,0,'Label'),(118,243,'','Sets the margin of the Indicator component',0,0,'Margin'),(118,256,'0','If set, adds a white border around the indicator. For more information check https://mantine.dev/core/indicator',0,0,'With Border'),(118,263,'red','Sets the color of the indicator. For more information check https://mantine.dev/core/indicator',0,0,'Color'),(118,265,'xl','Sets the border radius of the indicator. For more information check https://mantine.dev/core/indicator',0,0,'Radius'),(118,284,'1','If `useMantineStyle` prop is set Indicator will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/indicator',0,1,'Use Mantine Style'),(118,420,'0','If `processing` prop is set, indicator will show processing animation. For more information check https://mantine.dev/core/indicator',0,0,'Processing'),(118,421,'0','If `disabled` prop is set, indicator will be disabled. For more information check https://mantine.dev/core/indicator',0,0,'Disabled'),(118,422,'10','Sets the size of the indicator in pixels (6-40px). For more information check https://mantine.dev/core/indicator',0,0,'Size'),(118,423,'top-end','Sets the position of the indicator relative to its children. For more information check https://mantine.dev/core/indicator',0,0,'Position'),(118,425,'0','If set, the indicator will use inline-block display instead of block. For more information check https://mantine.dev/core/indicator',0,0,'Inline'),(118,426,'0','Sets the offset distance of the indicator from its position. Choose from preset values or enter a custom value (e.g., 5, 15, 20). For more information check https://mantine.dev/core/indicator',0,0,'Offset'),(119,8,'','Sets the label text displayed in the keyboard key. For more information check https://mantine.dev/core/kbd',0,0,'Label'),(119,243,'','Sets the margin of the Kbd component',0,0,'Margin'),(119,264,'sm','Sets the size of the keyboard key. For more information check https://mantine.dev/core/kbd',0,0,'Size'),(119,284,'1','If `useMantineStyle` prop is set Kbd will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/kbd',0,1,'Use Mantine Style'),(120,242,'','Sets the margin and padding of the Spoiler component',0,0,'Spacing'),(120,274,'200','Sets the maximum height before showing the spoiler. For more information check https://mantine.dev/core/spoiler',0,0,'Max Height'),(120,284,'1','If `useMantineStyle` prop is set Spoiler will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/spoiler',0,1,'Use Mantine Style'),(120,427,'Show more','Sets the label for the show button. For more information check https://mantine.dev/core/spoiler',0,0,'Show Label'),(120,428,'Hide','Sets the label for the hide button. For more information check https://mantine.dev/core/spoiler',0,0,'Hide Label'),(121,243,'','Sets the margin of the ThemeIcon component',0,0,'Margin'),(121,262,'filled','Sets the variant of the theme icon. For more information check https://mantine.dev/core/theme-icon',0,0,'Variant'),(121,263,'blue','Sets the color of the theme icon. For more information check https://mantine.dev/core/theme-icon',0,0,'Color'),(121,264,'sm','Sets the size of the theme icon. For more information check https://mantine.dev/core/theme-icon',0,0,'Size'),(121,265,'sm','Sets the border radius of the theme icon. For more information check https://mantine.dev/core/theme-icon',0,0,'Radius'),(121,266,NULL,'Sets the icon for the theme icon. For more information check https://mantine.dev/core/theme-icon',0,0,'Icon'),(121,284,'1','If `useMantineStyle` prop is set ThemeIcon will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/theme-icon',0,1,'Use Mantine Style'),(122,243,'','Sets the margin of the Timeline component',0,0,'Margin'),(122,263,'blue','Sets the color of the timeline. For more information check https://mantine.dev/core/timeline',0,0,'Color'),(122,284,'1','If `useMantineStyle` prop is set Timeline will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/timeline',0,1,'Use Mantine Style'),(122,429,'24','Sets the size of the timeline bullets. For more information check https://mantine.dev/core/timeline',0,0,'Bullet Size'),(122,430,'2','Sets the width of the timeline line. For more information check https://mantine.dev/core/timeline',0,0,'Line Width'),(122,431,'0','Index of current active element, all elements before this index will be highlighted with color. For more information check https://mantine.dev/core/timeline',0,0,'Active Index'),(122,432,'left','Defines line and bullets position relative to content, also sets text-align. For more information check https://mantine.dev/core/timeline',0,0,'Align'),(123,22,NULL,'Sets the title for the timeline item. For more information check https://mantine.dev/core/timeline',0,0,'Title'),(123,263,'gray','Sets the color of the timeline item. For more information check https://mantine.dev/core/timeline',0,0,'Color'),(123,284,'1','If `useMantineStyle` prop is set Timeline.Item will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/timeline',0,1,'Use Mantine Style'),(123,433,NULL,'Sets the bullet icon for the timeline item. For more information check https://mantine.dev/core/timeline',0,0,'Bullet Icon'),(123,434,'solid','Sets the line variant for the timeline item. For more information check https://mantine.dev/core/timeline',0,0,'Line Variant'),(124,241,'20','Sets the size of the blockquote icon in pixels. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/blockquote',0,0,'Icon Size'),(124,242,'','Sets the margin and padding of the Blockquote component',0,0,'Spacing'),(124,263,'gray','Sets the color of the blockquote. For more information check https://mantine.dev/core/blockquote',0,0,'Color'),(124,266,NULL,'Sets the icon for the blockquote. For more information check https://mantine.dev/core/blockquote',0,0,'Icon'),(124,284,'1','If `useMantineStyle` prop is set Blockquote will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/blockquote',0,1,'Use Mantine Style'),(124,287,NULL,'Sets the content for the blockquote. For more information check https://mantine.dev/core/blockquote',0,0,'Content'),(124,288,NULL,'Sets the citation for the blockquote. For more information check https://mantine.dev/core/blockquote',0,0,'Citation'),(125,242,'','Sets the margin and padding of the Code component',0,0,'Spacing'),(125,263,'gray','Sets the color of the code. For more information check https://mantine.dev/core/code',0,0,'Color'),(125,284,'1','If `useMantineStyle` prop is set Code will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/code',0,1,'Use Mantine Style'),(125,287,NULL,'Sets the content for the code. For more information check https://mantine.dev/core/code',0,0,'Content'),(125,435,'0','If `block` prop is set, code will be displayed as a block. For more information check https://mantine.dev/core/code',0,0,'Block'),(126,24,'Highlight some text in this content','The main text content where highlighting will be applied. This is translatable content.',0,0,'Content'),(126,242,'','Sets the margin and padding of the Highlight component',0,0,'Spacing'),(126,263,'yellow','Sets the highlight color. For more information check https://mantine.dev/core/highlight',0,0,'Color'),(126,284,'1','If `useMantineStyle` prop is set Highlight will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/highlight',0,1,'Use Mantine Style'),(126,436,'highlight','Sets the text to highlight within the content. This is translatable content that can be different in each language.',0,0,'Highlight Text'),(127,243,'','Sets the margin of the Title component',0,0,'Margin'),(127,264,'lg','Sets the size of the title. For more information check https://mantine.dev/core/title',0,0,'Size'),(127,284,'1','If `useMantineStyle` prop is set Title will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/title',0,1,'Use Mantine Style'),(127,287,NULL,'The text content of the title. This field supports multiple languages.',0,0,'Content'),(127,437,'1','Sets the heading level (1-6) for the title. For more information check https://mantine.dev/core/title',0,0,'Heading Level'),(127,438,'wrap','Sets the text-wrap CSS property for the title. For more information check https://mantine.dev/core/title',0,0,'Text Wrap'),(127,439,NULL,'Sets the number of lines after which the text will be truncated. For more information check https://mantine.dev/core/title',0,0,'Line Clamp'),(128,242,'','Sets the margin and padding of the List component',0,0,'Spacing'),(128,264,'sm','Sets the size of the list. For more information check https://mantine.dev/core/list',0,0,'Size'),(128,284,'1','Use Mantine styling for the list component',0,1,'Use Mantine Style'),(128,440,'disc','Sets custom bullet style for the list (e.g., \"disc\", \"circle\", \"square\", \"decimal\", \"lower-alpha\"). For more information check https://mantine.dev/core/list',0,0,'List Style Type'),(128,442,'0','If set, adds padding to nested lists for better hierarchy. For more information check https://mantine.dev/core/list',0,0,'With Padding'),(128,443,'0','If set, centers the list item content with the icon. For more information check https://mantine.dev/core/list',0,0,'Center Content'),(128,444,NULL,'Sets the default icon for all list items. For more information check https://mantine.dev/core/list',0,0,'Default Icon'),(128,619,'unordered','Sets the type of the list. For more information check https://mantine.dev/core/list',0,0,'List Type'),(128,620,'md','Sets the spacing between list items. For more information check https://mantine.dev/core/list',0,0,'Spacing'),(129,242,'','Sets the margin and padding of the ListItem component',0,0,'Spacing'),(129,284,'1','Use Mantine styling for the list item component',0,1,'Use Mantine Style'),(129,441,NULL,'The content text for this list item',0,0,'Content'),(129,445,NULL,'Sets the icon for this list item, overrides the parent list icon. For more information check https://mantine.dev/core/list',0,0,'Item Icon'),(130,242,'','Sets the margin and padding of the Typography component',0,0,'Spacing'),(130,284,'1','If `useMantineStyle` prop is set Typography will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/typography',0,1,'Use Mantine Style'),(131,243,'','Sets the margin of the Divider component',0,0,'Margin'),(131,263,'gray','Sets the color of the divider. For more information check https://mantine.dev/core/divider',0,0,'Color'),(131,264,'sm','Sets the thickness of the divider line. For more information check https://mantine.dev/core/divider',0,0,'Size'),(131,268,'horizontal','Sets the orientation of the divider. For more information check https://mantine.dev/core/divider',0,0,'Orientation'),(131,284,'1','If `useMantineStyle` prop is set Divider will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/divider',0,1,'Use Mantine Style'),(131,446,'solid','Sets the variant of the divider line. For more information check https://mantine.dev/core/divider',0,0,'Variant'),(131,447,NULL,'Sets the label text for the divider. For more information check https://mantine.dev/core/divider',0,0,'Label'),(131,448,'center','Sets the position of the divider label. For more information check https://mantine.dev/core/divider',0,0,'Label Position'),(132,242,'','Sets the margin and padding of the Paper component',0,0,'Spacing'),(132,256,'0','If set, the paper will have a border. For more information check https://mantine.dev/core/paper',0,0,'With Border'),(132,265,'sm','Sets the border radius of the paper. For more information check https://mantine.dev/core/paper',0,0,'Radius'),(132,284,'1','If `useMantineStyle` prop is set Paper will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/paper',0,1,'Use Mantine Style'),(132,317,NULL,'Sets the horizontal padding of the paper. For more information check https://mantine.dev/core/paper',0,0,'Horizontal Padding'),(132,318,NULL,'Sets the vertical padding of the paper. For more information check https://mantine.dev/core/paper',0,0,'Vertical Padding'),(132,449,'sm','Sets the shadow of the paper. For more information check https://mantine.dev/core/paper',0,0,'Shadow'),(133,242,'','Sets the margin and padding of the ScrollArea component',0,0,'Spacing'),(133,274,NULL,'Sets the height of the scroll area. For more information check https://mantine.dev/core/scroll-area',0,0,'Height'),(133,284,'1','If `useMantineStyle` prop is set scroll-area will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/scroll-area',0,1,'Use Mantine Style'),(133,450,'8','Sets the size of the scrollbar. For more information check https://mantine.dev/core/scroll-area',0,0,'Scrollbar Size'),(133,451,'hover','Sets when to show the scrollbar. For more information check https://mantine.dev/core/scroll-area',0,0,'Scrollbar Type'),(133,452,'0','If `offsetScrollbars` prop is set, scrollbars will be offset from the container edge. For more information check https://mantine.dev/core/scroll-area',0,0,'Offset Scrollbars'),(133,453,'1000','Sets the delay in milliseconds before hiding scrollbars after scrolling stops. Only applies when scrollbar type is hover. For more information check https://mantine.dev/core/scroll-area',0,0,'Scroll Hide Delay'),(134,242,'','Sets the margin and padding of the Card component',0,0,'Spacing'),(134,256,'0','If set, the card will have a border. For more information check https://mantine.dev/core/card',0,0,'With Border'),(134,265,'sm','Sets the border radius of the card. For more information check https://mantine.dev/core/card',0,0,'Radius'),(134,284,'1','If `useMantineStyle` prop is set Card will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/card',0,1,'Use Mantine Style'),(134,454,'sm','Sets the shadow of the card. For more information check https://mantine.dev/core/card',0,0,'Shadow'),(134,455,'sm','Sets the padding of the card. For more information check https://mantine.dev/core/card',0,0,'Padding'),(135,242,'','Sets the margin and padding of the CardSegment component',0,0,'Spacing'),(136,243,'','Sets the margin of the ProgressRoot component',0,0,'Margin'),(136,264,'sm','Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/progress',0,0,'Size'),(136,284,'1','Use Mantine styling for the progress component',0,1,'Use Mantine Style'),(136,298,'0','If set, colors will be adjusted for better contrast. For more information check https://mantine.dev/core/progress',0,0,'Auto Contrast'),(137,58,'0','Sets the progress value (0-100). For more information check https://mantine.dev/core/progress',0,0,'Progress Value'),(137,243,'','Sets the margin of the Progress component',0,0,'Margin'),(137,263,'blue','Sets the color of the progress bar. For more information check https://mantine.dev/core/progress',0,0,'Color'),(137,264,'sm','Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/progress',0,0,'Size'),(137,265,'sm','Sets the border radius of the progress bar. For more information check https://mantine.dev/core/progress',0,0,'Radius'),(137,284,'1','Use Mantine styling for the progress component',0,1,'Use Mantine Style'),(137,296,'0','If set, displays stripes on the progress bar. For more information check https://mantine.dev/core/progress',0,0,'Striped'),(137,297,'0','If set, animates the progress bar stripes. For more information check https://mantine.dev/core/progress',0,0,'Animated'),(137,299,'200','Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mantine.dev/core/progress',0,0,'Transition Duration'),(138,8,NULL,'Sets the label text for this progress section. For more information check https://mantine.dev/core/progress',0,0,'Label'),(138,58,'0','Sets the value for this progress section (0-100). For more information check https://mantine.dev/core/progress',0,0,'Section Value'),(138,243,'','Sets the margin of the ProgressSection component',0,0,'Margin'),(138,261,'top','Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip',0,0,'Tooltip Position'),(138,263,'blue','Sets the color of this progress section. For more information check https://mantine.dev/core/progress',0,0,'Color'),(138,284,'1','Use Mantine styling for the progress section component',0,1,'Use Mantine Style'),(138,296,'0','If set, displays stripes on this progress section. For more information check https://mantine.dev/core/progress',0,0,'Striped'),(138,297,'0','If set, animates this progress section stripes. For more information check https://mantine.dev/core/progress',0,0,'Animated'),(138,300,NULL,'Sets the tooltip text for this progress section. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip',0,0,'Tooltip Label'),(139,24,NULL,'The text content to display. For more information check https://mantine.dev/core/text',0,0,'Text Content'),(139,242,'','Sets the margin and padding of the Text component',0,0,'Spacing'),(139,263,'','Sets the color of the text. For more information check https://mantine.dev/core/text',0,0,'Color'),(139,264,'sm','Sets the font size of the text. For more information check https://mantine.dev/core/text',0,0,'Size'),(139,284,'1','Use Mantine styling for the text component',0,1,'Use Mantine Style'),(139,456,NULL,'Sets the font weight of the text. Choose from preset weights or enter a custom value (100-900). For more information check https://mantine.dev/core/text',0,0,'Font Weight'),(139,457,'normal','Sets the font style of the text. For more information check https://mantine.dev/core/text',0,0,'Font Style'),(139,458,'none','Sets the text decoration of the text. For more information check https://mantine.dev/core/text',0,0,'Text Decoration'),(139,459,'none','Sets the text transform of the text. For more information check https://mantine.dev/core/text',0,0,'Text Transform'),(139,460,'left','Sets the text alignment. For more information check https://mantine.dev/core/text',0,0,'Text Align'),(139,461,'default','Sets the text variant. Use \"gradient\" for gradient text. For more information check https://mantine.dev/core/text',0,0,'Variant'),(139,462,NULL,'Sets the gradient configuration for gradient variant. Only used when variant is \"gradient\". Format: {\"from\": \"blue\", \"to\": \"cyan\", \"deg\": 90}. For more information check https://mantine.dev/core/text',0,0,'Gradient'),(139,463,NULL,'Truncates the text with ellipsis. For more information check https://mantine.dev/core/text',0,0,'Truncate'),(139,464,NULL,'Limits the number of lines to display. Choose from preset values or enter a custom number. For more information check https://mantine.dev/core/text',0,0,'Line Clamp'),(139,465,'0','If set, Text will inherit parent styles (font-size, font-family, line-height). For more information check https://mantine.dev/core/text',0,0,'Inherit'),(139,466,'0','If set, Text will render as a span element instead of p. For more information check https://mantine.dev/core/text',0,0,'Span'),(140,99,'1','If set, displays navigation controls (previous/next buttons). For more information check https://mantine.dev/x/carousel',0,0,'Show Controls'),(140,100,'1','If set, displays slide indicators at the bottom. For more information check https://mantine.dev/x/carousel',0,0,'Show Indicators'),(140,242,'','Sets the margin and padding of the Carousel component',0,0,'Spacing'),(140,244,'0','If set, enables infinite loop navigation. For more information check https://mantine.dev/x/carousel',0,0,'Loop'),(140,245,'26','Sets the size of the navigation controls in pixels. Use the slider to adjust from 14px to 40px. For more information check https://mantine.dev/x/carousel',0,0,'Control Size'),(140,268,'horizontal','Sets the orientation of the carousel. For more information check https://mantine.dev/x/carousel',0,0,'Orientation'),(140,274,NULL,'Sets the height of the carousel. Choose from preset values or enter a custom value. For more information check https://mantine.dev/x/carousel',0,0,'Height'),(140,284,'1','Use Mantine styling for the carousel component',0,1,'Use Mantine Style'),(140,467,'0','If set, disables slide snap points allowing free dragging. For more information check https://mantine.dev/x/carousel',0,0,'Drag Free'),(140,468,'0','If set, allows skipping slides without snapping to them. For more information check https://mantine.dev/x/carousel',0,0,'Skip Snaps'),(140,470,'100','Sets the size of each slide as a percentage. Use the slider to adjust from 10% to 100%. For more information check https://mantine.dev/x/carousel',0,0,'Slide Size'),(140,471,'sm','Sets the gap between slides. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/x/carousel',0,0,'Slide Gap'),(140,472,'sm','Sets the offset of the navigation controls from the carousel edges. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/x/carousel',0,0,'Controls Offset'),(140,473,NULL,'Sets the icon for the next control button. For more information check https://mantine.dev/x/carousel',0,0,'Next Control Icon'),(140,474,NULL,'Sets the icon for the previous control button. For more information check https://mantine.dev/x/carousel',0,0,'Previous Control Icon'),(140,475,'start','Sets the alignment of slides. For more information check https://mantine.dev/x/carousel',0,0,'Align'),(140,476,'trimSnaps','Sets the contain scroll behavior. For more information check https://mantine.dev/x/carousel',0,0,'Contain Scroll'),(140,477,'0','Sets the threshold for slide visibility detection (0-1). Use the slider to adjust the percentage. For more information check https://mantine.dev/x/carousel',0,0,'In View Threshold'),(140,478,'25','Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mantine.dev/x/carousel',0,0,'Duration'),(140,479,NULL,'Sets advanced Embla carousel options as JSON. For more information check https://www.embla-carousel.com/api/options/',0,0,'Embla Options'),(141,8,NULL,'Sets the label text displayed next to the checkbox. For more information check https://mantine.dev/core/checkbox',0,0,'Label'),(141,56,'0','If set, the checkbox will be required for form submission. For more information check https://mantine.dev/core/checkbox',0,0,'Required'),(141,57,NULL,'Sets the name attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox',0,0,'Name'),(141,58,NULL,'Sets the value attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox',0,0,'Value'),(141,106,NULL,'Sets the description text displayed below the label. For more information check https://mantine.dev/core/checkbox',0,0,'Description'),(141,142,'0','If set, the checkbox will be disabled. For more information check https://mantine.dev/core/checkbox',0,0,'Disabled'),(141,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(141,217,'0','If enabled and the `type` of the input is `checkbox`, the input will be presented as a `toggle switch`',0,0,'Toggle Switch'),(141,218,'1','Sets the checkbox value when checked. For more information check https://mantine.dev/core/checkbox',0,0,'Checkbox Value'),(141,243,'','Sets the margin of the Checkbox component',0,0,'Margin'),(141,263,NULL,'Sets the color of the checkbox. Choose from theme colors or enter a custom color. For more information check https://mantine.dev/core/checkbox',0,0,'Color'),(141,264,'sm','Sets the size of the checkbox. Choose from preset sizes (xs, sm, md, lg, xl). For more information check https://mantine.dev/core/checkbox',0,0,'Size'),(141,265,'sm','Sets the border radius of the checkbox. Choose from preset values or enter a custom value. For more information check https://mantine.dev/core/checkbox',0,0,'Radius'),(141,284,'1','Use Mantine styling for the checkbox component',0,1,'Use Mantine Style'),(141,293,'0','When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the checkbox.',0,0,'Use Input Wrapper'),(141,480,NULL,'Sets a custom icon for the checkbox. For more information check https://mantine.dev/core/checkbox',0,0,'Icon'),(141,481,'right','Sets the position of the label relative to the checkbox. For more information check https://mantine.dev/core/checkbox',0,0,'Label Position'),(141,604,'0','If `checked` prop is set, checkbox will be in checked state. For more information check https://mantine.dev/core/checkbox',0,0,'Checked'),(141,605,'0','If `indeterminate` prop is set, checkbox will be in indeterminate state. For more information check https://mantine.dev/core/checkbox',0,0,'Indeterminate'),(142,8,NULL,'Sets the label text displayed above the date picker. For more information check https://mantine.dev/dates/getting-started',0,0,'Label'),(142,56,'0','If set, the date picker will be required for form submission. For more information check https://mantine.dev/dates/getting-started',0,0,'Required'),(142,57,NULL,'Sets the name attribute for form submission. For more information check https://mantine.dev/dates/getting-started',0,0,'Name'),(142,58,NULL,'Sets the initial date/time value. For more information check https://mantine.dev/dates/getting-started',0,0,'Value'),(142,106,NULL,'Sets the description text displayed below the label. For more information check https://mantine.dev/dates/getting-started',0,0,'Description'),(142,142,'0','If set, the date picker will be disabled. For more information check https://mantine.dev/dates/getting-started',0,0,'Disabled'),(142,243,'','Sets the margin of the DatePicker component',0,0,'Margin'),(142,482,'date','Sets the type of date picker (date only, time only, or date & time). For more information check https://mantine.dev/dates/getting-started',0,0,'Picker Type'),(142,483,NULL,'Sets the custom format string for date/time display. For more information check https://mantine.dev/dates/getting-started',0,0,'Format'),(142,484,'en','Sets the locale for date formatting and calendar display. For more information check https://mantine.dev/dates/getting-started',0,0,'Locale'),(142,485,NULL,'Sets the placeholder text for the input field. For more information check https://mantine.dev/dates/getting-started',0,0,'Placeholder'),(142,486,NULL,'Sets the minimum selectable date. For more information check https://mantine.dev/dates/getting-started',0,0,'Min Date'),(142,487,NULL,'Sets the maximum selectable date. For more information check https://mantine.dev/dates/getting-started',0,0,'Max Date'),(142,488,'1','Sets the first day of the week (0=Sunday, 1=Monday, etc.). For more information check https://mantine.dev/dates/getting-started',0,0,'First Day of Week'),(142,489,'[0,6]','Sets which days are considered weekends as a JSON array. For more information check https://mantine.dev/dates/getting-started',0,0,'Weekend Days'),(142,490,'0','If set, allows clearing the selected date/time. For more information check https://mantine.dev/dates/getting-started',0,0,'Clearable'),(142,491,'0','If set, allows deselecting the current date/time. For more information check https://mantine.dev/dates/getting-started',0,0,'Allow Deselect'),(142,492,'0','If set, the date picker will be readonly. For more information check https://mantine.dev/dates/getting-started',0,0,'Readonly'),(142,493,'0','If set, shows a time grid for time selection. For more information check https://mantine.dev/dates/getting-started',0,0,'With Time Grid'),(142,494,'0','If set, every month will have 6 weeks to avoid layout shifts. For more information check https://mantine.dev/dates/getting-started',0,0,'Consistent Weeks'),(142,495,'0','If set, hides dates from other months. For more information check https://mantine.dev/dates/getting-started',0,0,'Hide Outside Dates'),(142,496,'0','If set, hides weekend days from the calendar. For more information check https://mantine.dev/dates/getting-started',0,0,'Hide Weekends'),(142,497,'15','Sets the time step in minutes for time selection. For more information check https://mantine.dev/dates/getting-started',0,0,'Time Step'),(142,498,'24','Sets the time format (12-hour or 24-hour). For more information check https://mantine.dev/dates/getting-started',0,0,'Time Format'),(142,499,'YYYY-MM-DD','Sets the date format pattern for form submission. Choose from presets or enter a custom format. For more information check https://mantine.dev/dates/getting-started',0,0,'Date Format'),(142,500,NULL,'JSON configuration for TimeGrid layout (e.g., {\"cols\": {\"base\": 2, \"sm\": 3}, \"spacing\": \"xs\"}). For more information check https://mantine.dev/dates/time-grid',0,0,'Time Grid Config'),(142,501,'0','If set, includes seconds in time selection. For more information check https://mantine.dev/dates/getting-started',0,0,'With Seconds'),(143,8,NULL,'Sets the label text displayed above the input field. For more information check https://mantine.dev/core/text-input',0,0,'Label'),(143,55,NULL,'Sets the placeholder text for the input field. For more information check https://mantine.dev/core/text-input',0,0,'Placeholder'),(143,56,'0','If set, the input field will be required for form submission. For more information check https://mantine.dev/core/text-input',0,0,'Required'),(143,57,NULL,'Sets the name attribute for form submission. For more information check https://mantine.dev/core/text-input',0,0,'Name'),(143,58,NULL,'Sets the initial value of the input field. For more information check https://mantine.dev/core/text-input',0,0,'Value'),(143,106,NULL,'Sets the description text displayed below the label. For more information check https://mantine.dev/core/text-input',0,0,'Description'),(143,142,'0','If set, the input field will be disabled. For more information check https://mantine.dev/core/text-input',0,0,'Disabled'),(143,243,'','Sets the margin of the TextInput component',0,0,'Margin'),(143,260,'0','If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using tabs.',0,0,'Translatable'),(143,264,'sm','Sets the size of the input field. For more information check https://mantine.dev/core/text-input',0,0,'Size'),(143,265,'sm','Sets the border radius of the input field. For more information check https://mantine.dev/core/text-input',0,0,'Radius'),(143,266,NULL,'Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/text-input',0,0,'Left Section'),(143,267,NULL,'Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/text-input',0,0,'Right Section'),(143,284,'1','Use Mantine styling for the text-input component',0,1,'Use Mantine Style'),(143,502,'default','Sets the variant of the input field. For more information check https://mantine.dev/core/text-input',0,0,'Variant'),(144,8,NULL,'Sets the label text displayed above the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Label'),(144,55,NULL,'Sets the placeholder text for the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Placeholder'),(144,56,'0','If set, the textarea field will be required for form submission. For more information check https://mantine.dev/core/textarea',0,0,'Required'),(144,57,NULL,'Sets the name attribute for form submission. For more information check https://mantine.dev/core/textarea',0,0,'Name'),(144,58,NULL,'Sets the initial value of the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Value'),(144,106,NULL,'Sets the description text displayed below the label. For more information check https://mantine.dev/core/textarea',0,0,'Description'),(144,142,'0','If set, the textarea field will be disabled. For more information check https://mantine.dev/core/textarea',0,0,'Disabled'),(144,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(144,243,'','Sets the margin of the Textarea component',0,0,'Margin'),(144,260,'0','If enabled, this textarea field will support multi-language translations. When enabled, users can enter values for different languages using tabs.',0,0,'Translatable'),(144,262,'default','Sets the variant of the textarea. For more information check https://mantine.dev/core/textarea',0,0,'Variant'),(144,264,'sm','Sets the size of the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Size'),(144,265,'sm','Sets the border radius of the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Radius'),(144,266,NULL,'Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/textarea',0,0,'Left Section'),(144,267,NULL,'Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/textarea',0,0,'Right Section'),(144,284,'1','Use Mantine styling for the textarea component',0,0,'Use Mantine Style'),(144,503,'1','If set, the textarea will automatically adjust its height based on content. For more information check https://mantine.dev/core/textarea',0,0,'Autosize'),(144,504,'3','Sets the minimum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea',0,0,'Min Rows'),(144,505,'8','Sets the maximum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea',0,0,'Max Rows'),(144,506,'none','Sets the resize behavior of the textarea. For more information check https://mantine.dev/core/textarea',0,0,'Resize'),(144,507,'default','Sets the variant of the textarea field. For more information check https://mantine.dev/core/textarea',0,0,'Variant'),(144,606,'4','Sets the number of visible text lines for the textarea control. For more information check https://mantine.dev/core/textarea',0,0,'Rows'),(145,8,NULL,'If this field is set, a this text will be rendered next to the input.',0,0,'Label'),(145,55,NULL,'Sets the placeholder text for the input field',0,0,'Placeholder'),(145,56,'0','If set, the input field will be required for form submission',0,0,'Required'),(145,57,NULL,'Sets the name attribute for form submission',0,0,'Name'),(145,58,NULL,'Sets the initial value of the input field',0,0,'Value'),(145,69,NULL,'Sets the minimum value (for number inputs) or minimum length (for text inputs)',0,0,'Min Value'),(145,70,NULL,'Sets the maximum value (for number inputs) or maximum length (for text inputs)',0,0,'Max Value'),(145,142,'0','If set, the input field will be disabled',0,0,'Disabled'),(145,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(145,260,'0','If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using tabs.',0,0,'Translatable'),(145,262,'default','Sets the variant of the input. For more information check https://mantine.dev/core/input',0,0,'Variant'),(145,264,'sm','Sets the size of the input. For more information check https://mantine.dev/core/input',0,0,'Size'),(145,265,'sm','Sets the border radius of the input. For more information check https://mantine.dev/core/input',0,0,'Radius'),(145,266,NULL,'Sets the left icon of the input. For more information check https://mantine.dev/core/input',0,0,'Left Icon'),(145,267,NULL,'Sets the right icon of the input. For more information check https://mantine.dev/core/input',0,0,'Right Icon'),(145,284,'1','If `useMantineStyle` prop is set Input will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/input',0,0,'Use Mantine Style'),(145,294,'text','Sets the input type (text, email, password, number, checkbox, color, date, time, tel, url)',0,0,'Input Type'),(146,8,NULL,'If this field is set, a this text will be rendered next to the select.',0,0,'Label'),(146,55,'Select an option','Sets the placeholder text for the select field',0,0,'Placeholder'),(146,56,'0','If set, the select field will be required for form submission',0,0,'Required'),(146,57,NULL,'Sets the name attribute for form submission',0,0,'Name'),(146,58,NULL,'Sets the initial selected value of the select field',0,0,'Value'),(146,67,'0','If set, allows multiple selections',0,0,'Multiple Selection'),(146,70,NULL,'Sets the maximum number of selections allowed when multiple is enabled',0,0,'Max Selections'),(146,142,'0','If set, the select field will be disabled',0,0,'Disabled'),(146,179,'0','If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.',0,0,'Locked After Submit'),(146,264,'sm','Sets the size of the select. For more information check https://mantine.dev/core/select',0,0,'Size'),(146,265,'sm','Sets the border radius of the select. For more information check https://mantine.dev/core/select',0,0,'Radius'),(146,284,'1','If `useMantineStyle` prop is set Select will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/select',0,0,'Use Mantine Style'),(146,292,NULL,'JSON array of select options. Each option should have value and label properties.',0,0,'Select Options'),(146,295,'[{\"value\":\"option1\",\"label\":\"Option 1\"}, {\"value\":\"option2\",\"label\":\"Option 2\"}]','Sets the options for the select field as JSON array. Format: [{\"value\":\"option1\",\"label\":\"Option 1\"}]',0,0,'Options'),(146,608,'0','If `searchable` prop is set, user can filter options by typing. For more information check https://mantine.dev/core/select',0,0,'Searchable'),(146,609,'0','If `clearable` prop is set, user can clear selected value. For more information check https://mantine.dev/core/select',0,0,'Clearable'),(147,8,NULL,'Sets the label text displayed above the rich text editor. For more information check https://mantine.dev/x/tiptap',0,0,'Label'),(147,56,'0','If set, the rich text editor will be required for form submission.',0,0,'Required'),(147,57,NULL,'Sets the name attribute for form submission. For more information check https://mantine.dev/x/tiptap',0,0,'Name'),(147,58,NULL,'Sets the initial HTML content of the rich text editor. For more information check https://mantine.dev/x/tiptap',0,0,'Value'),(147,106,NULL,'Sets the description text displayed below the label. For more information check https://mantine.dev/x/tiptap',0,0,'Description'),(147,142,'0','If set, the rich text editor will be disabled.',0,0,'Disabled'),(147,243,'','Sets the margin of the RichTextEditor component',0,0,'Margin'),(147,260,'0','If enabled, this rich text editor field will support multi-language translations. When enabled, users can enter values for different languages using tabs.',0,0,'Translatable'),(147,284,'1','Use Mantine styling for the rich text editor component',0,1,'Use Mantine Style'),(147,508,'default','Sets the variant of the rich text editor.',0,0,'Variant'),(147,509,'Start writing...','Sets the placeholder text shown when the editor is empty. For more information check https://tiptap.dev/docs/editor/extensions/functionality/placeholder',0,0,'Placeholder Text'),(147,510,'0','If set, enables a bubble menu that appears when text is selected for quick formatting. For more information check https://tiptap.dev/docs/editor/extensions/functionality/bubble-menu',0,0,'Enable Bubble Menu'),(147,511,'0','If set, enables text color controls in the toolbar. For more information check https://tiptap.dev/docs/editor/extensions/functionality/color',0,0,'Enable Text Color'),(147,512,'0','If set, enables task list functionality with checkboxes. For more information check https://tiptap.dev/docs/editor/extensions/functionality/task-list',0,0,'Enable Task Lists'),(148,35,'','Success message displayed after form submission',0,0,'Success Message'),(148,57,NULL,'Sets the form name for identification and API calls',0,0,'Form Name'),(148,87,'1','Enables log mode - form clears after successful submission',0,1,'Log Mode'),(148,242,'','Sets the margin and padding of the FormLog component',0,0,'Spacing'),(148,284,'1','Use Mantine styling for the form component',0,1,'Use Mantine Style'),(148,513,'sm','Size of the form buttons',0,0,'Button Size'),(148,514,'sm','Border radius of the form buttons',0,0,'Button Radius'),(148,515,'filled','Visual style variant for the buttons',0,0,'Button Variant'),(148,516,'space-between','Positioning of the buttons container',0,0,'Button Position'),(148,517,'save-cancel','Order of buttons (which button appears first)',0,0,'Button Order'),(148,518,'Save','Text displayed on the save button for new records',0,0,'Save Button Label'),(148,520,'Cancel','Text displayed on the cancel button',0,0,'Cancel Button Label'),(148,521,'blue','Color theme for the save button',0,0,'Save Button Color'),(148,522,'gray','Color theme for the cancel button',0,0,'Cancel Button Color'),(148,524,NULL,'URL to redirect to after successful form submission',0,0,'Redirect URL'),(148,525,NULL,'URL to navigate to when cancel button is clicked',0,0,'Cancel URL'),(148,526,'An error occurred while submitting the form','Error message displayed when form submission fails',0,0,'Error Message'),(149,35,'','Success message displayed after form submission',0,0,'Success Message'),(149,57,NULL,'Sets the form name for identification and API calls',0,0,'Form Name'),(149,87,'0','Disables log mode - form preserves data for record updates',0,1,'Log Mode'),(149,242,'','Sets the margin and padding of the FormRecord component',0,0,'Spacing'),(149,284,'1','Use Mantine styling for the form component',0,0,'Use Mantine Style'),(149,513,'sm','Size of the form buttons',0,0,'Button Size'),(149,514,'sm','Border radius of the form buttons',0,0,'Button Radius'),(149,515,'filled','Visual style variant for the buttons',0,0,'Button Variant'),(149,516,'space-between','Positioning of the buttons container',0,0,'Button Position'),(149,517,'save-cancel','Order of buttons (which button appears first)',0,0,'Button Order'),(149,518,'Save','Text displayed on the save button for new records',0,0,'Save Button Label'),(149,519,'Update','Text displayed on the update button for existing records',0,0,'Update Button Label'),(149,520,'Cancel','Text displayed on the cancel button',0,0,'Cancel Button Label'),(149,521,'blue','Color theme for the save button',0,0,'Save Button Color'),(149,522,'gray','Color theme for the cancel button',0,0,'Cancel Button Color'),(149,523,'orange','Color theme for the update button',0,0,'Update Button Color'),(149,524,NULL,'URL to redirect to after successful form submission',0,0,'Redirect URL'),(149,525,NULL,'URL to navigate to when cancel button is clicked',0,0,'Cancel URL'),(149,526,'An error occurred while saving the record','Error message displayed when form submission fails',0,0,'Error Message'),(150,527,NULL,'Translatable content to display inside the HTML tag. This field supports multiple languages.',0,0,'Content'),(150,528,'div','Select the HTML tag to render. This provides raw HTML flexibility for custom UI designs.',0,0,'HTML Tag'),(151,242,'','Sets the margin and padding of the Profile component',0,0,'Spacing'),(151,284,'1','Use Mantine style for the profile container',0,1,'Use Mantine Style'),(151,529,'My Profile','Main title displayed at the top of the profile page',0,0,'Profile Title'),(151,530,'Email','Label for displaying user email',0,0,'Email Label'),(151,531,'Username','Label for displaying username',0,0,'Username Label'),(151,532,'Full Name','Label for displaying full name',0,0,'Name Label'),(151,533,'Account Created','Label for account creation date',0,0,'Created Label'),(151,534,'Last Login','Label for last login date',0,0,'Last Login Label'),(151,535,'Timezone','Label for timezone selection',0,0,'Timezone Label'),(151,537,'Account Information','Title for the account information section',0,0,'Account Info Title'),(151,538,'Change Display Name','Section title for name change',0,0,'Name Change Title'),(151,539,'<p>Update your display name. This will be visible to other users.</p>','Description explaining name change',0,0,'Name Change Description'),(151,540,'New Display Name','Label for name input field',0,0,'Name Input Label'),(151,541,'Enter new display name','Placeholder for name input',0,0,'Name Placeholder'),(151,542,'Update Display Name','Button text for name change',0,0,'Name Change Button'),(151,543,'Display name updated successfully!','Success message after name change',0,0,'Name Success Message'),(151,544,'Display name is required','Error when name field is empty',0,0,'Name Required Error'),(151,545,'Display name contains invalid characters','Error for invalid name format',0,0,'Name Invalid Error'),(151,546,'Failed to update display name. Please try again.','General name change error',0,0,'Name General Error'),(151,547,'Change Password','Section title for password change',0,0,'Password Reset Title'),(151,548,'<p>Set a new password for your account. Make sure it is strong and secure.</p>','Description explaining password change',0,0,'Password Reset Description'),(151,549,'Current Password','Label for current password field',0,0,'Current Password Label'),(151,550,'New Password','Label for new password field',0,0,'New Password Label'),(151,551,'Confirm New Password','Label for password confirmation field',0,0,'Confirm Password Label'),(151,552,'Enter current password','Placeholder for current password',0,0,'Current Password Placeholder'),(151,553,'Enter new password','Placeholder for new password',0,0,'New Password Placeholder'),(151,554,'Confirm new password','Placeholder for password confirmation',0,0,'Confirm Password Placeholder'),(151,555,'Update Password','Button text for password change',0,0,'Password Change Button'),(151,556,'Password updated successfully!','Success message after password change',0,0,'Password Success Message'),(151,557,'Current password is required','Error when current password is empty',0,0,'Current Password Required Error'),(151,558,'Current password is incorrect','Error when current password is wrong',0,0,'Current Password Wrong Error'),(151,559,'New password is required','Error when new password is empty',0,0,'New Password Required Error'),(151,560,'Password confirmation is required','Error when confirmation is empty',0,0,'Confirm Password Required Error'),(151,561,'New passwords do not match','Error when passwords don\'t match',0,0,'Password Mismatch Error'),(151,562,'Password is too weak. Please choose a stronger password.','Error for weak password',0,0,'Weak Password Error'),(151,563,'Failed to update password. Please try again.','General password change error',0,0,'Password General Error'),(151,564,'Delete Account','Section title for account deletion',0,0,'Delete Account Title'),(151,565,'<p>Permanently delete your account and all associated data. This action cannot be undone.</p>','Warning description for account deletion',0,0,'Delete Account Description'),(151,566,'This action cannot be undone. All your data will be permanently deleted.','Warning text in the delete account alert',0,0,'Delete Alert Text'),(151,567,'<p>Deleting your account will permanently remove all your data, including profile information, preferences, and any content you have created.</p>','Detailed warning text in the delete account modal',0,0,'Delete Modal Warning'),(151,568,'Confirm Email','Label for email confirmation field',0,0,'Email Confirmation Label'),(151,569,'Enter your email to confirm','Placeholder for email confirmation',0,0,'Email Confirmation Placeholder'),(151,570,'Delete Account','Button text for account deletion',0,0,'Delete Account Button'),(151,571,'Account deleted successfully.','Success message after account deletion',0,0,'Account Deletion Success'),(151,572,'Email confirmation is required','Error when email field is empty',0,0,'Email Required Error'),(151,573,'Email does not match your account email','Error when email doesn\'t match',0,0,'Email Mismatch Error'),(151,574,'Failed to delete account. Please try again.','General account deletion error',0,0,'Account Deletion General Error'),(151,575,'md','Spacing between profile sections',0,0,'Section Spacing'),(151,576,'0','Wrap profile sections in accordion for collapsible interface',0,0,'Use Accordion'),(151,577,'1','Allow multiple accordion sections to be open simultaneously',0,0,'Multiple Accordion'),(151,578,'user_info','Which accordion sections should be opened by default',0,0,'Default Opened Sections'),(151,579,'default','Visual style variant for the profile cards',0,0,'Card Variant'),(151,580,'sm','Border radius for profile cards',0,0,'Border Radius'),(151,581,'sm','Shadow effect for profile cards',0,0,'Shadow Effect'),(151,582,'2','Number of columns for non-accordion layout',0,0,'Layout Columns'),(152,2,'Password','Label for the password input field',0,0,'Password Field Label'),(152,5,'Validation failed. Please check your information and try again.','Error message displayed when validation fails',0,0,'Error Message'),(152,9,'Confirm Password','Label for the password confirmation field',0,0,'Confirm Password Label'),(152,22,'Account Validation','Main heading displayed at the top of the validation form',0,0,'Form Title'),(152,34,'Please complete your account setup to activate your account','Subtitle text displayed below the title',0,0,'Form Subtitle'),(152,35,'Account validated successfully! Welcome to our platform.','Success message displayed after successful validation',0,0,'Success Message'),(152,36,'Name','Label for the name input field',0,0,'Name Field Label'),(152,37,'Enter your full name','Placeholder text for the name input field',0,0,'Name Placeholder'),(152,38,NULL,'Help text displayed below the name field',0,0,'Name Description'),(152,42,'Activate Account','Text for the account activation button',0,0,'Activate Button Label'),(152,43,'Enter your password','Placeholder text for the password field',0,0,'Password Placeholder'),(152,51,'Cancel','Text for the cancel button',0,0,'Cancel Button Label'),(152,57,'validate_form','Sets the form name for identification and API calls',0,0,'Form Name'),(152,216,'This name will be visible to other users','Description text for anonymous user name field',0,0,'Anonymous User Description'),(152,242,'','Sets the margin and padding of the Validate component',0,0,'Spacing'),(152,256,'1','Show border around the validation card',0,0,'Card Border'),(152,265,'sm','Border radius for the validation card',0,0,'Card Radius'),(152,284,'1','Use Mantine styling for the validation form component',0,1,'Use Mantine Style'),(152,454,'sm','Shadow effect for the validation card',0,0,'Card Shadow'),(152,455,'lg','Padding inside the validation card',0,0,'Card Padding'),(152,513,'sm','Size of the form buttons',0,0,'Button Size'),(152,514,'sm','Border radius of the form buttons',0,0,'Button Radius'),(152,515,'filled','Visual style variant for the buttons',0,0,'Button Variant'),(152,516,'space-between','Positioning of the buttons container',0,0,'Button Position'),(152,517,'save-cancel','Order of buttons (activate appears first)',0,0,'Button Order'),(152,521,'blue','Color theme for the activate button',0,0,'Activate Button Color'),(152,522,'gray','Color theme for the cancel button',0,0,'Cancel Button Color'),(152,524,'login','URL to redirect to after successful account validation',0,0,'Redirect URL'),(152,525,NULL,'URL to navigate to when cancel button is clicked',0,0,'Cancel URL'),(152,583,'Timezone','Label for the timezone selection field',0,0,'Timezone Field Label'),(152,584,'Save','Text for the save button (fallback)',0,0,'Save Button Label'),(152,585,'Update','Text for the update button (fallback)',0,0,'Update Button Label');
/*!40000 ALTER TABLE `styles_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
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
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,'2020-10-05 08:45:52',35,42,2,'pages',53,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/action\\/3\\/update\\/6\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\\/action\\/3\\/update\\/6\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(2,'2020-10-05 08:45:55',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(3,'2020-10-05 08:46:02',35,42,2,'pages',49,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(4,'2020-10-05 08:46:04',35,42,2,'pages',52,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(5,'2020-10-05 08:46:06',35,42,2,'pages',52,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\\/insert\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\\/insert\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(6,'2020-10-05 08:46:10',35,42,2,'pages',52,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":67,\"sid\":67,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"chat_room\":null}}'),(7,'2020-11-06 09:46:00',35,42,2,'pages',14,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/user\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/user\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/179\\/1263\",\"chat_room\":11}}'),(8,'2020-11-06 09:46:08',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/179\\/1263\",\"chat_room\":11}}'),(9,'2020-11-06 09:46:13',35,42,2,'pages',45,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/data\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/data\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/user\",\"chat_room\":11}}'),(10,'2020-11-06 09:46:22',35,42,2,'pages',45,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/data\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/data\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"chat_room\":11}}'),(11,'2020-11-06 09:46:31',35,42,2,'pages',49,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/qualtrics\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"chat_room\":11}}'),(12,'2020-11-06 09:46:35',35,42,2,'pages',50,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/mailQueue\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/mailQueue\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/data\",\"chat_room\":11}}'),(13,'2020-11-06 09:46:39',35,42,2,'pages',55,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/mailQueue\\/composeEmail\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/mailQueue\\/composeEmail\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/qualtrics\",\"chat_room\":11}}'),(14,'2020-11-06 09:46:57',35,42,2,'pages',46,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_preferences\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":179,\"sid\":1263,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_preferences\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/mailQueue\",\"chat_room\":11}}'),(15,'2020-11-19 13:37:05',35,42,2,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":175,\"sid\":1255,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/175\\/1255\",\"chat_room\":11}}'),(16,'2020-11-19 13:37:09',35,42,2,'pages',31,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/impressum\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":175,\"sid\":1255,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/impressum\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/175\\/1255\",\"chat_room\":11}}'),(17,'2020-11-19 13:37:14',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":175,\"sid\":1255,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/\",\"chat_room\":11}}'),(18,'2020-11-19 13:37:17',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":175,\"sid\":1255,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/impressum\",\"chat_room\":11}}'),(19,'2020-11-19 13:37:22',35,42,2,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/[i:id]\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":5,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/[i:id]\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"chat_room\":11}}'),(20,'2020-11-19 13:37:27',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":5,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/5\",\"chat_room\":11}}'),(21,'2020-11-19 13:37:35',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/3\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":5,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/3\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"chat_room\":11}}'),(22,'2020-11-19 13:37:43',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/1\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":5,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/1\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/5\",\"chat_room\":11}}'),(23,'2020-11-19 13:38:24',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":1,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/3\",\"chat_room\":11}}'),(24,'2020-11-19 13:38:28',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/1\",\"chat_room\":11}}'),(25,'2020-11-19 13:38:32',35,42,2,'pages',58,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(26,'2020-11-19 13:39:58',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"chat_room\":11}}'),(27,'2020-11-19 13:40:01',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/3\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/3\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"chat_room\":11}}'),(28,'2020-11-19 13:40:02',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/5\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(29,'2020-11-19 13:40:05',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":5,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/3\",\"chat_room\":11}}'),(30,'2020-11-19 13:40:08',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/5\",\"chat_room\":11}}'),(31,'2020-11-19 13:40:28',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(32,'2020-11-19 13:41:52',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(33,'2020-11-19 13:41:58',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(34,'2020-11-19 13:42:03',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":null,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"chat_room\":11}}'),(35,'2020-11-19 13:42:05',35,42,2,'pages',58,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\",\"chat_room\":11}}'),(36,'2020-11-19 13:42:12',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":2,\"sid\":19,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/2\\/19\",\"chat_room\":11}}'),(37,'2020-11-19 13:44:01',35,42,2,'pages',58,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/1635\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":183,\"sid\":1635,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/1635\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/183\\/1635\",\"chat_room\":11}}'),(38,'2020-11-19 13:44:21',35,42,2,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/fsdf\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":183,\"sid\":1635,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/fsdf\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/183\\/1635\",\"chat_room\":11}}'),(39,'2020-11-19 13:44:25',35,42,2,'pages',58,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/1\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":183,\"sid\":1635,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/1\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/183\\/1635\",\"chat_room\":11}}'),(40,'2020-11-19 13:44:30',35,42,2,'pages',58,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/11111\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":183,\"sid\":1635,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms_export\\/section\\/11111\",\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\\/183\\/1635\",\"chat_room\":11}}'),(41,'2020-11-19 14:34:45',35,42,2,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":\"\\/selfhelp\\/admin\\/cms\",\"logged_in\":true,\"id_user\":\"0000000002\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/group_update_custom\\/3\",\"requests\":[]}}'),(42,'2021-03-16 13:34:20',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/user\\/60\",\"requests\":[]}}'),(43,'2021-03-16 13:34:25',35,42,3,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/user\\/60\",\"requests\":[]}}'),(44,'2021-03-16 13:34:27',35,42,3,'pages',50,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/scheduledJobs\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/\",\"requests\":[]}}'),(45,'2021-03-16 13:34:31',35,42,3,'pages',49,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"requests\":[]}}'),(46,'2021-03-16 13:34:32',35,42,3,'pages',51,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/project\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/scheduledJobs\",\"requests\":[]}}'),(47,'2021-03-16 13:34:32',35,42,3,'pages',52,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/qualtrics\",\"requests\":[]}}'),(48,'2021-03-16 13:34:33',35,42,3,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/qualtrics\\/project\",\"requests\":[]}}'),(49,'2021-03-16 13:34:35',35,42,3,'pages',11,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_insert\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/qualtrics\\/survey\",\"requests\":[]}}'),(50,'2021-03-16 13:34:37',35,42,3,'pages',14,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/user\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"requests\":[]}}'),(51,'2021-03-16 13:34:39',35,42,3,'pages',45,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/data\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms_insert\",\"requests\":[]}}'),(52,'2021-03-16 13:34:40',35,42,3,'pages',22,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/export\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/user\",\"requests\":[]}}'),(53,'2021-03-16 13:34:41',35,42,3,'pages',50,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/scheduledJobs\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/data\",\"requests\":[]}}'),(54,'2021-03-16 13:34:43',35,42,3,'pages',24,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/asset\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/export\",\"requests\":[]}}'),(55,'2021-03-16 13:34:44',35,42,3,'pages',37,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/email\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/scheduledJobs\",\"requests\":[]}}'),(56,'2021-03-16 13:34:45',35,42,3,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/asset\",\"requests\":[]}}'),(57,'2021-03-16 13:34:46',35,42,3,'pages',50,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/scheduledJobs\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/email\",\"requests\":[]}}'),(58,'2021-03-16 13:34:48',35,42,3,'pages',46,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms_preferences\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms\",\"requests\":[]}}'),(59,'2021-03-16 13:34:49',35,42,3,'pages',10,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/cms\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/scheduledJobs\",\"requests\":[]}}'),(60,'2021-03-16 13:35:21',35,42,1,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":false,\"id_user\":1}}'),(61,'2021-03-16 13:35:40',35,42,1,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":false,\"id_user\":1,\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\",\"requests\":[]}}'),(62,'2021-03-16 13:35:55',35,42,1,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":false,\"id_user\":1,\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\",\"requests\":[]}}'),(63,'2021-03-16 13:36:00',35,42,3,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/home\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":85,\"sid\":99,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/cms_preferences\",\"requests\":[]}}'),(64,'2021-03-16 13:36:06',35,42,1,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/home\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":false,\"id_user\":1,\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\",\"requests\":[]}}'),(65,'2022-01-25 16:58:01',35,42,3,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/home\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":70,\"sid\":68,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form2\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(66,'2022-01-25 16:58:03',35,42,3,'pages',31,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/impressum\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":70,\"sid\":68,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/impressum\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(67,'2022-03-02 10:53:29',35,42,3,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/home\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(68,'2022-03-02 10:53:29',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/js\\/ext\\/moment.min.js.map\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(69,'2022-03-02 10:53:29',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/server\\/component\\/style\\/qualtricsSurvey\\/js\\/iframeResizer.map\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(70,'2022-03-02 10:53:29',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/min-maps\\/vs\\/loader.js.map\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(71,'2022-03-02 10:53:31',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/request\\/AjaxLanguage\\/set_user_language\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/form\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(72,'2022-03-02 10:53:33',35,42,3,'pages',6,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/request\\/AjaxLanguage\\/set_user_language\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"all\",\"cms_edit_url\":{\"pid\":72,\"sid\":66,\"ssid\":null,\"did\":null,\"mode\":\"update\",\"type\":\"prop\"},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000003\",\"requests\":[],\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/home\",\"user_code\":\"tpf\",\"user_name\":\"TPF\"}}'),(73,'2022-03-09 16:43:46',35,42,2,'pages',2,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/home\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"user_name\":\"admin\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/user_update\\/2\\/rm_group\\/5\",\"user_code\":\"admin\"}}'),(74,'2022-03-09 16:43:48',35,42,2,'pages',18,'{\"verbal_log\":\"Transaction type: `select` from table: `pages` triggered by_user\",\"url\":\"\\/selfhelp\\/admin\\/group\",\"session\":{\"gender\":\"male\",\"user_gender\":\"male\",\"cms_gender\":\"male\",\"language\":\"de-CH\",\"user_language\":\"de-CH\",\"cms_language\":\"de-CH\",\"cms_edit_url\":{\"pid\":null,\"sid\":null,\"ssid\":null},\"active_section_id\":null,\"project\":\"Projekt Name\",\"target_url\":null,\"logged_in\":true,\"id_user\":\"0000000002\",\"requests\":[],\"user_name\":\"admin\",\"last_user_page\":\"http:\\/\\/localhost\\/selfhelp\\/admin\\/group\\/4\",\"user_code\":\"admin\"}}');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

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
  `timestamp` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
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
-- Dumping data for table `user_activity`
--

LOCK TABLES `user_activity` WRITE;
/*!40000 ALTER TABLE `user_activity` DISABLE KEYS */;
INSERT INTO `user_activity` VALUES (1,2,'/selfhelp/admin/export/user_input_form/all/55','2019-11-28 16:49:46',2,NULL,NULL,NULL,NULL),(2,2,'/selfhelp/admin/export/user_input_form/all/55','2020-01-24 14:32:37',2,NULL,NULL,NULL,NULL),(3,2,'/selfhelp/admin/data','2020-11-06 10:46:14',2,NULL,NULL,NULL,NULL),(4,2,'/selfhelp/admin/data','2020-11-06 10:46:22',2,NULL,NULL,NULL,NULL),(5,3,'/selfhelp/admin/data','2021-03-16 14:34:39',2,NULL,NULL,NULL,NULL),(6,3,'/selfhelp/home','2022-01-25 17:58:01',2,0.04770803,NULL,NULL,NULL),(7,3,'/selfhelp/impressum','2022-01-25 17:58:03',2,0.14982700,NULL,NULL,NULL),(8,3,'/selfhelp/home','2022-03-02 11:53:29',2,0.06554914,NULL,NULL,NULL),(9,2,'/selfhelp/home','2022-03-09 17:43:46',2,0.05483794,NULL,NULL,NULL),(10,2,'/selfhelp/admin/group','2022-03-09 17:43:48',2,0.03404498,NULL,NULL,NULL);
/*!40000 ALTER TABLE `user_activity` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'guest','Guest',NULL,0,NULL,1,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'guest',72,NULL),(2,'admin@unibe.ch','admin','$2y$10$lqb/Eieowq8lWTUxVrb1MOHrZ1ZDvbnU4RNvWxqP5pa8/QOdwFB8e',0,77,0,NULL,NULL,1,'2020-04-29',NULL,NULL,NULL,NULL,'admin',72,NULL),(3,'tpf@unibe.ch','TPF','$2y$10$VxLANpP09THlDIDDfvL7PurilxKZ8vU8WzdGdfCYkdeBgy7hUkiUu',0,77,0,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'tpf',72,NULL),(4,'sysadmin','sysadmin','$2y$10$H5MhmUF3cLLMNayuIQ4g.OXikV528bDOkConwtVBjdpj4rqrUtAXu',0,77,0,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'sysadmin',72,NULL),(5,'stefan.kodzhabashev@unibe.ch','Stefan Kodzhabashev','$2y$10$PKWjEEoCoTrr8SkKU9EkU.p.AC7qCZeFoEEcVPi3mrOXKGOBXn4vq',0,77,0,NULL,2,1,NULL,NULL,NULL,NULL,NULL,'stefan.kodzhabashev@unibe.ch',72,NULL),(6,'simon.maurer@unibe.ch','Simon Maurer',NULL,0,76,0,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,72,NULL),(7,'walter.siegenthaler@unibe.ch','Walter Siegenthaler',NULL,0,76,0,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,72,NULL),(8,'samuel.stucky@unibe.ch','Samuel Stucky@unibe',NULL,0,76,0,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,72,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

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
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `expires_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `is_used` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_65A1E404FA06E4D9` (`id_users`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users_2fa_codes`
--

LOCK TABLES `users_2fa_codes` WRITE;
/*!40000 ALTER TABLE `users_2fa_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `users_2fa_codes` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `users_groups`
--

LOCK TABLES `users_groups` WRITE;
/*!40000 ALTER TABLE `users_groups` DISABLE KEYS */;
INSERT INTO `users_groups` VALUES (2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1);
/*!40000 ALTER TABLE `users_groups` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `users_roles`
--

LOCK TABLES `users_roles` WRITE;
/*!40000 ALTER TABLE `users_roles` DISABLE KEYS */;
INSERT INTO `users_roles` VALUES (2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1);
/*!40000 ALTER TABLE `users_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `validation_codes`
--

DROP TABLE IF EXISTS `validation_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `validation_codes` (
  `code` varchar(16) NOT NULL,
  `id_users` int DEFAULT NULL,
  `created` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `consumed` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)',
  `id_groups` int DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `IDX_DBEC45EFA06E4D9` (`id_users`),
  KEY `IDX_DBEC45ED65A8C9D` (`id_groups`),
  CONSTRAINT `FK_DBEC45ED65A8C9D` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_DBEC45EFA06E4D9` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `validation_codes`
--

LOCK TABLES `validation_codes` WRITE;
/*!40000 ALTER TABLE `validation_codes` DISABLE KEYS */;
INSERT INTO `validation_codes` VALUES ('admin_samuel',8,'2026-04-07 12:23:49',NULL,NULL),('admin_simon',6,'2026-04-07 12:23:49',NULL,NULL),('admin_stefan',5,'2026-04-07 12:23:49',NULL,NULL),('admin_walter',7,'2026-04-07 12:23:49',NULL,NULL);
/*!40000 ALTER TABLE `validation_codes` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `version`
--

LOCK TABLES `version` WRITE;
/*!40000 ALTER TABLE `version` DISABLE KEYS */;
INSERT INTO `version` VALUES (1,'v8.0.0');
/*!40000 ALTER TABLE `version` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `view_dataTables`
--

DROP TABLE IF EXISTS `view_dataTables`;
/*!50001 DROP VIEW IF EXISTS `view_dataTables`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `view_dataTables` AS SELECT 
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
-- Dumping routines for database 'sym'
--
/*!50003 DROP FUNCTION IF EXISTS `build_dynamic_columns` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_dynamic_columns`(table_id_param INT) RETURNS text CHARSET utf8mb3
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
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `build_exclude_deleted_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_exclude_deleted_filter`(exclude_deleted_param BOOLEAN) RETURNS text CHARSET utf8mb3
    DETERMINISTIC
BEGIN
    IF exclude_deleted_param = TRUE THEN
        RETURN CONCAT(' AND IFNULL(r.id_actionTriggerTypes, 0) <> ', (SELECT id FROM lookups WHERE type_code = 'actionTriggerTypes' AND lookup_code = 'deleted' LIMIT 0,1));
    ELSE
        RETURN '';
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `build_language_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_language_filter`(language_id_param INT) RETURNS text CHARSET utf8mb3
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
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `build_time_period_filter` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `build_time_period_filter`(filter_param VARCHAR(1000)) RETURNS text CHARSET utf8mb3
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
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP FUNCTION IF EXISTS `convert_entry_date_timezone` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `convert_entry_date_timezone`(timestamp_value DATETIME, timezone_code VARCHAR(100)) RETURNS varchar(19) CHARSET utf8mb3
    DETERMINISTIC
BEGIN
    -- Convert timestamp from UTC to specified timezone and format as Y-m-d H:i:s
    RETURN DATE_FORMAT(CONVERT_TZ(timestamp_value, 'UTC', timezone_code), '%Y-%m-%d %H:%i:%s');
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `rename_table_column`(
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

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Final view structure for view `view_dataTables`
--

/*!50001 DROP VIEW IF EXISTS `view_dataTables`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_dataTables` AS select `dataTables`.`id` AS `id`,`dataTables`.`name` AS `name_id`,(case when (ifnull(`dataTables`.`displayName`,'') = '') then `datatables`.`name` else `dataTables`.`displayName` end) AS `name`,`dataTables`.`timestamp` AS `timestamp`,`dataTables`.`id` AS `value`,(case when (ifnull(`dataTables`.`displayName`,'') = '') then `dataTables`.`name` else `dataTables`.`displayName` end) AS `text` from `dataTables` */;
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
/*!50001 VIEW `view_fields` AS select `f`.`id` AS `field_id`,`f`.`name` AS `field_name`,`f`.`display` AS `display`,`ft`.`id` AS `field_type_id`,`ft`.`name` AS `field_type`,`ft`.`position` AS `position`,`f`.`config` AS `config` from (`fields` `f` left join `fieldType` `ft` on((`f`.`id_type` = `ft`.`id`))) */;
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
/*!50001 VIEW `view_styles` AS select cast(`s`.`id` as unsigned) AS `style_id`,`s`.`name` AS `style_name`,`s`.`description` AS `style_description`,cast(`sg`.`id` as unsigned) AS `style_group_id`,`sg`.`name` AS `style_group`,`sg`.`description` AS `style_group_description`,`sg`.`position` AS `style_group_position` from (`styles` `s` left join `styleGroup` `sg` on((`s`.`id_group` = `sg`.`id`))) */;
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

-- Dump completed on 2026-04-07 14:31:19
