/*M!999999\- enable the sandbox mode */ 
-- RIVETIT_SCHEMA_VERSION: 2.6.100
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: itflow_dev
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0+deb12u2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(200) NOT NULL,
  `account_description` varchar(250) DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `account_currency_code` varchar(200) NOT NULL,
  `account_notes` text DEFAULT NULL,
  `account_type` int(6) DEFAULT NULL,
  `account_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `account_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_models`
--

DROP TABLE IF EXISTS `ai_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_models` (
  `ai_model_id` int(11) NOT NULL AUTO_INCREMENT,
  `ai_model_name` varchar(200) NOT NULL,
  `ai_model_prompt` text DEFAULT NULL,
  `ai_model_use_case` varchar(200) DEFAULT NULL,
  `ai_model_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ai_model_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ai_model_ai_provider_id` int(11) NOT NULL,
  PRIMARY KEY (`ai_model_id`),
  KEY `ai_model_ai_provider_id` (`ai_model_ai_provider_id`),
  CONSTRAINT `ai_models_ibfk_1` FOREIGN KEY (`ai_model_ai_provider_id`) REFERENCES `ai_providers` (`ai_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_providers`
--

DROP TABLE IF EXISTS `ai_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_providers` (
  `ai_provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `ai_provider_name` varchar(200) NOT NULL,
  `ai_provider_api_url` varchar(200) NOT NULL,
  `ai_provider_api_key` text DEFAULT NULL,
  `ai_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ai_provider_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`ai_provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_keys`
--

DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `api_key_id` int(11) NOT NULL AUTO_INCREMENT,
  `api_key_name` varchar(255) NOT NULL,
  `api_key_secret` varchar(255) NOT NULL,
  `api_key_decrypt_hash` varchar(200) NOT NULL,
  `api_key_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `api_key_expire` date NOT NULL,
  `api_key_client_id` int(11) NOT NULL DEFAULT 0,
  `api_key_permission` enum('read','write') NOT NULL DEFAULT 'read',
  `api_key_allow_delete` tinyint(1) NOT NULL DEFAULT 0,
  `api_key_allowed_ips` text DEFAULT NULL,
  PRIMARY KEY (`api_key_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `app_logs`
--

DROP TABLE IF EXISTS `app_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_logs` (
  `app_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `app_log_category` varchar(200) DEFAULT NULL,
  `app_log_type` enum('info','warning','error','debug') NOT NULL DEFAULT 'info',
  `app_log_details` text DEFAULT NULL,
  `app_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`app_log_id`)
) ENGINE=InnoDB AUTO_INCREMENT=225395 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE `api_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `token_user_id` int(11) NOT NULL,
  `token_name` varchar(100) NOT NULL DEFAULT 'Mobile App',
  `token_hash` char(64) NOT NULL,
  `token_fcm_token` text DEFAULT NULL,
  `token_enc_master_key` varchar(300) DEFAULT NULL,
  `token_enc_master_iv` char(32) DEFAULT NULL,
  `token_last_used_at` datetime DEFAULT NULL,
  `token_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `token_user_id` (`token_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `asset_credentials`
--

DROP TABLE IF EXISTS `asset_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_credentials` (
  `credential_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`credential_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `asset_credentials_ibfk_1` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_credentials_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_custom`
--

DROP TABLE IF EXISTS `asset_custom`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_custom` (
  `asset_custom_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_custom_field_value` int(11) NOT NULL,
  `asset_custom_field_id` int(11) NOT NULL,
  `asset_custom_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_custom_id`),
  KEY `asset_custom_asset_id` (`asset_custom_asset_id`),
  CONSTRAINT `asset_custom_ibfk_1` FOREIGN KEY (`asset_custom_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_documents`
--

DROP TABLE IF EXISTS `asset_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_documents` (
  `asset_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `asset_documents_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_files`
--

DROP TABLE IF EXISTS `asset_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_files` (
  `asset_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `asset_files_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_history`
--

DROP TABLE IF EXISTS `asset_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_history` (
  `asset_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_history_status` varchar(200) NOT NULL,
  `asset_history_description` varchar(255) NOT NULL,
  `asset_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_history_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_history_id`),
  KEY `asset_history_asset_id` (`asset_history_asset_id`),
  CONSTRAINT `asset_history_ibfk_1` FOREIGN KEY (`asset_history_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_interface_links`
--

DROP TABLE IF EXISTS `asset_interface_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_interface_links` (
  `interface_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `interface_a_id` int(11) NOT NULL,
  `interface_b_id` int(11) NOT NULL,
  `interface_link_type` varchar(100) DEFAULT NULL,
  `interface_link_status` varchar(50) DEFAULT NULL,
  `interface_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `interface_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`interface_link_id`),
  KEY `fk_interface_a` (`interface_a_id`),
  KEY `fk_interface_b` (`interface_b_id`),
  CONSTRAINT `fk_interface_a` FOREIGN KEY (`interface_a_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_interface_b` FOREIGN KEY (`interface_b_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_interfaces`
--

DROP TABLE IF EXISTS `asset_interfaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_interfaces` (
  `interface_id` int(11) NOT NULL AUTO_INCREMENT,
  `interface_name` varchar(200) NOT NULL,
  `interface_description` varchar(200) DEFAULT NULL,
  `interface_type` varchar(50) DEFAULT NULL,
  `interface_mac` varchar(200) DEFAULT NULL,
  `interface_ip` varchar(200) DEFAULT NULL,
  `interface_nat_ip` varchar(200) DEFAULT NULL,
  `interface_ipv6` varchar(200) DEFAULT NULL,
  `interface_notes` text DEFAULT NULL,
  `interface_primary` tinyint(1) DEFAULT 0,
  `interface_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `interface_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `interface_archived_at` datetime DEFAULT NULL,
  `interface_network_id` int(11) DEFAULT NULL,
  `interface_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`interface_id`),
  KEY `interface_asset_id` (`interface_asset_id`),
  CONSTRAINT `asset_interfaces_ibfk_1` FOREIGN KEY (`interface_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_notes`
--

DROP TABLE IF EXISTS `asset_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_notes` (
  `asset_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_note_type` varchar(200) NOT NULL,
  `asset_note` text DEFAULT NULL,
  `asset_note_created_by` int(11) NOT NULL,
  `asset_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `asset_note_archived_at` datetime DEFAULT NULL,
  `asset_note_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_note_id`),
  KEY `asset_note_asset_id` (`asset_note_asset_id`),
  CONSTRAINT `asset_notes_ibfk_1` FOREIGN KEY (`asset_note_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_rmm_links`
--

DROP TABLE IF EXISTS `asset_rmm_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_rmm_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `integration_id` int(11) NOT NULL,
  `tactical_agent_id` varchar(200) DEFAULT NULL,
  `mesh_node_id` varchar(200) DEFAULT NULL,
  `hostname` varchar(200) DEFAULT NULL,
  `rmm_status` varchar(20) DEFAULT 'unknown',
  `last_seen` datetime DEFAULT NULL,
  `os_name` varchar(200) DEFAULT NULL,
  `os_version` varchar(200) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `model` varchar(200) DEFAULT NULL,
  `cpu` varchar(300) DEFAULT NULL,
  `ram_gb` varchar(50) DEFAULT NULL,
  `logged_in_user` varchar(200) DEFAULT NULL,
  `last_sync` datetime DEFAULT NULL,
  `raw_data_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rmm_status_changed_at` datetime DEFAULT NULL,
  `automation_processed_at` datetime DEFAULT NULL,
  `rmm_cpu_percent` int(11) DEFAULT NULL,
  `rmm_ram_percent` int(11) DEFAULT NULL,
  `rmm_disk_percent` int(11) DEFAULT NULL,
  `rmm_needs_reboot` tinyint(1) DEFAULT 0,
  `rmm_last_boot` datetime DEFAULT NULL,
  `rmm_maintenance_mode` tinyint(1) DEFAULT 0,
  `rmm_health_updated_at` datetime DEFAULT NULL,
  `rmm_patches_pending` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_integration` (`asset_id`,`integration_id`),
  KEY `tactical_agent_id` (`tactical_agent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=807 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_tags`
--

DROP TABLE IF EXISTS `asset_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_tags` (
  `asset_tag_asset_id` int(11) NOT NULL,
  `asset_tag_tag_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_tag_asset_id`,`asset_tag_tag_id`),
  KEY `fk_tag` (`asset_tag_tag_id`),
  CONSTRAINT `fk_asset` FOREIGN KEY (`asset_tag_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tag` FOREIGN KEY (`asset_tag_tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `asset_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_type` varchar(200) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `asset_tag` varchar(100) DEFAULT NULL,
  `asset_description` varchar(255) DEFAULT NULL,
  `asset_make` varchar(200) NOT NULL,
  `asset_model` varchar(200) DEFAULT NULL,
  `asset_serial` varchar(200) DEFAULT NULL,
  `asset_pin` varchar(50) DEFAULT NULL,
  `asset_os` varchar(200) DEFAULT NULL,
  `asset_uri` varchar(500) DEFAULT NULL,
  `asset_uri_2` varchar(500) DEFAULT NULL,
  `asset_uri_client` varchar(500) DEFAULT NULL,
  `asset_anydesk_id` varchar(50) DEFAULT NULL,
  `asset_status` varchar(200) DEFAULT NULL,
  `asset_purchase_reference` varchar(200) DEFAULT NULL,
  `asset_purchase_date` date DEFAULT NULL,
  `asset_warranty_expire` date DEFAULT NULL,
  `asset_install_date` date DEFAULT NULL,
  `asset_photo` varchar(200) DEFAULT NULL,
  `asset_physical_location` varchar(200) DEFAULT NULL,
  `asset_notes` text DEFAULT NULL,
  `asset_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `asset_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `asset_archived_at` datetime DEFAULT NULL,
  `asset_accessed_at` datetime DEFAULT NULL,
  `asset_vendor_id` int(11) NOT NULL DEFAULT 0,
  `asset_location_id` int(11) NOT NULL DEFAULT 0,
  `asset_contact_id` int(11) NOT NULL DEFAULT 0,
  `asset_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`asset_id`),
  KEY `idx_assets_client_archived` (`asset_client_id`,`asset_archived_at`),
  KEY `idx_assets_serial` (`asset_serial`)
) ENGINE=InnoDB AUTO_INCREMENT=92 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_events`
--

DROP TABLE IF EXISTS `audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_events` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `metadata_json` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_events_type_created` (`event_type`,`created_at`),
  KEY `idx_audit_events_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_events_actor` (`actor_user_id`),
  KEY `idx_audit_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_logs`
--

DROP TABLE IF EXISTS `auth_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_logs` (
  `auth_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_log_status` tinyint(1) NOT NULL,
  `auth_log_details` varchar(200) DEFAULT NULL,
  `auth_log_ip` varchar(200) DEFAULT NULL,
  `auth_log_user_agent` varchar(250) DEFAULT NULL,
  `auth_log_user_id` int(11) NOT NULL DEFAULT 0,
  `auth_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`auth_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `budget`
--

DROP TABLE IF EXISTS `budget`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget` (
  `budget_id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_month` tinyint(4) NOT NULL,
  `budget_year` int(11) NOT NULL,
  `budget_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `budget_description` varchar(255) DEFAULT NULL,
  `budget_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `budget_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `budget_category_id` int(11) NOT NULL,
  PRIMARY KEY (`budget_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_event_attendees`
--

DROP TABLE IF EXISTS `calendar_event_attendees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_attendees` (
  `attendee_id` int(11) NOT NULL AUTO_INCREMENT,
  `attendee_name` varchar(200) DEFAULT NULL,
  `attendee_email` varchar(200) DEFAULT NULL,
  `attendee_invitation_status` tinyint(1) NOT NULL DEFAULT 0,
  `attendee_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attendee_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `attendee_archived_at` datetime DEFAULT NULL,
  `attendee_contact_id` int(11) NOT NULL DEFAULT 0,
  `attendee_event_id` int(11) NOT NULL,
  PRIMARY KEY (`attendee_id`),
  KEY `attendee_event_id` (`attendee_event_id`),
  CONSTRAINT `calendar_event_attendees_ibfk_1` FOREIGN KEY (`attendee_event_id`) REFERENCES `calendar_events` (`event_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_events`
--

DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_title` varchar(200) NOT NULL,
  `event_location` text DEFAULT NULL,
  `event_description` longtext DEFAULT NULL,
  `event_start` datetime NOT NULL,
  `event_end` datetime DEFAULT NULL,
  `event_repeat` varchar(200) DEFAULT NULL,
  `event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `event_archived_at` datetime DEFAULT NULL,
  `event_client_id` int(11) NOT NULL DEFAULT 0,
  `event_location_id` int(11) NOT NULL DEFAULT 0,
  `event_calendar_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_id`),
  KEY `event_calendar_id` (`event_calendar_id`),
  CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`event_calendar_id`) REFERENCES `calendars` (`calendar_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendars`
--

DROP TABLE IF EXISTS `calendars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendars` (
  `calendar_id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_name` varchar(200) NOT NULL,
  `calendar_color` varchar(200) NOT NULL,
  `calendar_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `calendar_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `calendar_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`calendar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `canned_responses`
--

DROP TABLE IF EXISTS `canned_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_responses` (
  `canned_response_id` int(11) NOT NULL AUTO_INCREMENT,
  `canned_response_name` varchar(255) NOT NULL,
  `canned_response_message` mediumtext NOT NULL,
  `canned_response_created_at` datetime DEFAULT current_timestamp(),
  `canned_response_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `canned_response_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`canned_response_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(200) NOT NULL,
  `category_description` varchar(255) DEFAULT NULL,
  `category_type` varchar(200) NOT NULL,
  `category_color` varchar(200) DEFAULT NULL,
  `category_icon` varchar(200) DEFAULT NULL,
  `category_order` int(11) NOT NULL DEFAULT 0,
  `category_parent` int(11) DEFAULT 0,
  `category_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `category_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `category_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificate_history`
--

DROP TABLE IF EXISTS `certificate_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificate_history` (
  `certificate_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_history_column` varchar(200) NOT NULL,
  `certificate_history_old_value` text NOT NULL,
  `certificate_history_new_value` text NOT NULL,
  `certificate_history_certificate_id` int(11) NOT NULL,
  `certificate_history_modified_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`certificate_history_id`),
  KEY `certificate_history_certificate_id` (`certificate_history_certificate_id`),
  CONSTRAINT `certificate_history_ibfk_1` FOREIGN KEY (`certificate_history_certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificates`
--

DROP TABLE IF EXISTS `certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificates` (
  `certificate_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_name` varchar(200) NOT NULL,
  `certificate_description` mediumtext DEFAULT NULL,
  `certificate_domain` varchar(200) DEFAULT NULL,
  `certificate_issued_by` varchar(200) NOT NULL,
  `certificate_expire` date DEFAULT NULL,
  `certificate_public_key` mediumtext DEFAULT NULL,
  `certificate_notes` mediumtext DEFAULT NULL,
  `certificate_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `certificate_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `certificate_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `certificate_archived_at` datetime DEFAULT NULL,
  `certificate_accessed_at` datetime DEFAULT NULL,
  `certificate_domain_id` int(11) NOT NULL DEFAULT 0,
  `certificate_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`certificate_id`),
  KEY `idx_certificates_client_archived_expire` (`certificate_client_id`,`certificate_archived_at`,`certificate_expire`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_notes`
--

DROP TABLE IF EXISTS `client_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_notes` (
  `client_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_note_type` varchar(200) NOT NULL,
  `client_note` text DEFAULT NULL,
  `client_note_created_by` int(11) NOT NULL,
  `client_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `client_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `client_note_archived_at` datetime DEFAULT NULL,
  `client_note_client_id` int(11) NOT NULL,
  PRIMARY KEY (`client_note_id`),
  KEY `client_note_client_id` (`client_note_client_id`),
  CONSTRAINT `client_notes_ibfk_1` FOREIGN KEY (`client_note_client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_payment_provider`
--

DROP TABLE IF EXISTS `client_payment_provider`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_payment_provider` (
  `client_id` int(11) NOT NULL,
  `payment_provider_id` int(11) NOT NULL,
  `payment_provider_client` varchar(200) NOT NULL,
  `client_payment_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`,`payment_provider_id`),
  KEY `payment_provider_id` (`payment_provider_id`),
  CONSTRAINT `client_payment_provider_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_payment_provider_ibfk_2` FOREIGN KEY (`payment_provider_id`) REFERENCES `payment_providers` (`payment_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_saved_payment_methods`
--

DROP TABLE IF EXISTS `client_saved_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_saved_payment_methods` (
  `saved_payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `saved_payment_provider_method` varchar(200) NOT NULL,
  `saved_payment_description` varchar(200) DEFAULT NULL,
  `saved_payment_client_id` int(11) NOT NULL,
  `saved_payment_provider_id` int(11) NOT NULL,
  `saved_payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `saved_payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `saved_payment_type` varchar(20) DEFAULT 'card',
  PRIMARY KEY (`saved_payment_id`),
  KEY `saved_payment_client_id` (`saved_payment_client_id`),
  KEY `saved_payment_provider_id` (`saved_payment_provider_id`),
  CONSTRAINT `client_saved_payment_methods_ibfk_1` FOREIGN KEY (`saved_payment_client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_saved_payment_methods_ibfk_2` FOREIGN KEY (`saved_payment_provider_id`) REFERENCES `payment_providers` (`payment_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_tags`
--

DROP TABLE IF EXISTS `client_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_tags` (
  `client_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`client_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `client_tags_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_parent_id` int(11) DEFAULT NULL,
  `client_head_contact_id` int(11) DEFAULT NULL,
  `client_cost_center` varchar(100) DEFAULT NULL,
  `client_status` varchar(50) NOT NULL DEFAULT 'Active',
  `client_security_classification` enum('General','Confidential','Restricted') NOT NULL DEFAULT 'General',
  `client_lead` tinyint(1) NOT NULL DEFAULT 0,
  `client_name` varchar(200) NOT NULL,
  `client_type` varchar(200) DEFAULT NULL,
  `client_website` varchar(200) DEFAULT NULL,
  `client_referral` varchar(200) DEFAULT NULL,
  `client_rate` decimal(15,2) DEFAULT NULL,
  `client_currency_code` varchar(200) NOT NULL,
  `client_net_terms` int(10) NOT NULL,
  `client_tax_id_number` varchar(255) DEFAULT NULL,
  `client_abbreviation` varchar(10) DEFAULT NULL,
  `client_notes` text DEFAULT NULL,
  `client_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `client_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `client_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `client_archived_at` datetime DEFAULT NULL,
  `client_accessed_at` datetime DEFAULT NULL,
  `client_ai_opt_out` tinyint(1) NOT NULL DEFAULT 0,
  `client_lead_source` varchar(60) DEFAULT NULL,
  `client_lead_status` varchar(40) DEFAULT NULL,
  `client_lead_owner` int(11) DEFAULT NULL,
  `client_lead_score` int(11) DEFAULT NULL,
  `client_support_issues_included_remote` int(11) DEFAULT NULL,
  `client_support_issues_included_onsite` int(11) DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `company_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(200) NOT NULL,
  `company_address` varchar(200) DEFAULT NULL,
  `company_city` varchar(200) DEFAULT NULL,
  `company_state` varchar(200) DEFAULT NULL,
  `company_zip` varchar(200) DEFAULT NULL,
  `company_country` varchar(200) DEFAULT NULL,
  `company_phone_country_code` varchar(10) DEFAULT NULL,
  `company_phone` varchar(200) DEFAULT NULL,
  `company_email` varchar(200) DEFAULT NULL,
  `company_website` varchar(200) DEFAULT NULL,
  `company_logo` varchar(250) DEFAULT NULL,
  `company_locale` varchar(200) DEFAULT NULL,
  `company_currency` varchar(200) DEFAULT 'USD',
  `company_tax_id` varchar(200) DEFAULT NULL,
  `company_ms_tenant_id` varchar(100) DEFAULT NULL,
  `company_default_email_domain` varchar(200) DEFAULT NULL,
  `company_security_contact_email` varchar(200) DEFAULT NULL,
  `company_hr_contact_email` varchar(200) DEFAULT NULL,
  `company_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `company_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_assets`
--

DROP TABLE IF EXISTS `contact_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_assets` (
  `contact_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `contact_assets_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_3` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_4` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_credentials`
--

DROP TABLE IF EXISTS `contact_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_credentials` (
  `contact_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `contact_credentials_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_documents`
--

DROP TABLE IF EXISTS `contact_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_documents` (
  `contact_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `contact_documents_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_files`
--

DROP TABLE IF EXISTS `contact_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_files` (
  `contact_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `contact_files_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_notes`
--

DROP TABLE IF EXISTS `contact_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_notes` (
  `contact_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_note_type` varchar(200) NOT NULL,
  `contact_note` text DEFAULT NULL,
  `contact_note_created_by` int(11) NOT NULL,
  `contact_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `contact_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contact_note_archived_at` datetime DEFAULT NULL,
  `contact_note_contact_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_note_id`),
  KEY `contact_note_contact_id` (`contact_note_contact_id`),
  CONSTRAINT `contact_notes_ibfk_1` FOREIGN KEY (`contact_note_contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_tags`
--

DROP TABLE IF EXISTS `contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tags` (
  `contact_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `contact_tags_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contacts`
--

DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `contact_id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_name` varchar(200) NOT NULL,
  `contact_employee_id` varchar(50) DEFAULT NULL,
  `contact_employee_type` varchar(30) NOT NULL DEFAULT 'employee',
  `contact_manager_id` int(11) DEFAULT NULL,
  `contact_employment_status` varchar(30) NOT NULL DEFAULT 'active',
  `contact_work_arrangement` varchar(20) DEFAULT NULL,
  `contact_start_date` date DEFAULT NULL,
  `contact_vacation_start` date DEFAULT NULL,
  `contact_vacation_end` date DEFAULT NULL,
  `contact_expected_end_date` date DEFAULT NULL,
  `contact_title` varchar(200) DEFAULT NULL,
  `contact_email` varchar(200) DEFAULT NULL,
  `contact_phone_country_code` varchar(10) DEFAULT NULL,
  `contact_phone` varchar(200) DEFAULT NULL,
  `contact_extension` varchar(200) DEFAULT NULL,
  `contact_mobile_country_code` varchar(10) DEFAULT NULL,
  `contact_mobile` varchar(200) DEFAULT NULL,
  `contact_photo` varchar(200) DEFAULT NULL,
  `contact_pin` varchar(255) DEFAULT NULL,
  `contact_notes` text DEFAULT NULL,
  `contact_primary` tinyint(1) NOT NULL DEFAULT 0,
  `contact_important` tinyint(1) NOT NULL DEFAULT 0,
  `contact_billing` tinyint(1) DEFAULT 0,
  `contact_technical` tinyint(1) DEFAULT 0,
  `contact_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `contact_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contact_archived_at` datetime DEFAULT NULL,
  `contact_accessed_at` datetime DEFAULT NULL,
  `contact_location_id` int(11) NOT NULL DEFAULT 0,
  `contact_vendor_id` int(11) NOT NULL DEFAULT 0,
  `contact_user_id` int(11) NOT NULL DEFAULT 0,
  `contact_department` varchar(200) DEFAULT NULL,
  `contact_client_id` int(11) NOT NULL DEFAULT 0,
  `contact_portal_role` enum('none','supervisor','manager') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`contact_id`),
  KEY `idx_contacts_client_archived` (`contact_client_id`,`contact_archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contract_templates`
--

DROP TABLE IF EXISTS `contract_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_templates` (
  `contract_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_template_name` varchar(255) NOT NULL,
  `contract_template_description` text DEFAULT NULL,
  `contract_template_type` varchar(50) DEFAULT NULL,
  `contract_template_sla_low_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_low_resolution_time` int(11) DEFAULT NULL,
  `contract_template_sla_medium_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_medium_resolution_time` int(11) DEFAULT NULL,
  `contract_template_sla_high_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_high_resolution_time` int(11) DEFAULT NULL,
  `contract_template_rate_standard` decimal(10,2) DEFAULT NULL,
  `contract_template_rate_after_hours` decimal(10,2) DEFAULT NULL,
  `contract_template_net_terms` varchar(50) DEFAULT NULL,
  `contract_template_support_hours` varchar(100) DEFAULT NULL,
  `contract_template_renewal_frequency` varchar(50) DEFAULT NULL,
  `contract_template_details` text DEFAULT NULL,
  `contract_template_created_at` datetime DEFAULT current_timestamp(),
  `contract_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contract_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`contract_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contracts`
--

DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contracts` (
  `contract_id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_name` varchar(255) NOT NULL,
  `contract_status` varchar(50) NOT NULL,
  `contract_type` varchar(50) NOT NULL,
  `contract_sla_low_response_time` int(11) DEFAULT NULL,
  `contract_sla_low_resolution_time` int(11) DEFAULT NULL,
  `contract_sla_medium_response_time` int(11) DEFAULT NULL,
  `contract_sla_medium_resolution_time` int(11) DEFAULT NULL,
  `contract_sla_high_response_time` int(11) DEFAULT NULL,
  `contract_sla_high_resolution_time` int(11) DEFAULT NULL,
  `contract_details` text DEFAULT NULL,
  `contract_client_id` int(11) DEFAULT NULL,
  `contract_client_name` varchar(255) DEFAULT NULL,
  `contract_client_address` text DEFAULT NULL,
  `contract_client_email` varchar(255) DEFAULT NULL,
  `contract_client_phone` varchar(100) DEFAULT NULL,
  `contract_contact_name` varchar(255) DEFAULT NULL,
  `contract_contact_signature` text DEFAULT NULL,
  `contract_contact_signature_date` datetime DEFAULT NULL,
  `contract_agent_name` varchar(255) DEFAULT NULL,
  `contract_agent_signature` text DEFAULT NULL,
  `contract_agent_signature_date` datetime DEFAULT NULL,
  `contract_rate_standard` decimal(10,2) DEFAULT NULL,
  `contract_rate_after_hours` decimal(10,2) DEFAULT NULL,
  `contract_net_terms` varchar(50) DEFAULT NULL,
  `contract_support_hours` varchar(100) DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `contract_renewal_frequency` varchar(50) DEFAULT NULL,
  `contract_created_at` datetime DEFAULT current_timestamp(),
  `contract_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contract_archived_at` datetime DEFAULT NULL,
  `contract_renewal_date` date DEFAULT NULL,
  `contract_value` decimal(10,2) DEFAULT NULL,
  `contract_created_by` int(11) NOT NULL DEFAULT 0,
  `contract_support_hours_included_remote` decimal(6,2) DEFAULT NULL,
  `contract_support_hours_included_onsite` decimal(6,2) DEFAULT NULL,
  PRIMARY KEY (`contract_id`),
  KEY `contract_client_id` (`contract_client_id`),
  CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`contract_client_id`) REFERENCES `clients` (`client_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credential_restore_staging`
--

DROP TABLE IF EXISTS `credential_restore_staging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_restore_staging` (
  `credential_id` int(11) NOT NULL AUTO_INCREMENT,
  `credential_name` varchar(200) NOT NULL,
  `credential_description` varchar(500) DEFAULT NULL,
  `credential_type` varchar(200) NOT NULL DEFAULT 'Login',
  `credential_uri` varchar(500) DEFAULT NULL,
  `credential_uri_2` varchar(500) DEFAULT NULL,
  `credential_username` varchar(500) DEFAULT NULL,
  `credential_password` varbinary(200) DEFAULT NULL,
  `credential_otp_secret` varchar(200) DEFAULT NULL,
  `credential_note` text DEFAULT NULL,
  `credential_important` tinyint(1) NOT NULL DEFAULT 0,
  `credential_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `credential_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `credential_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `credential_archived_at` datetime DEFAULT NULL,
  `credential_accessed_at` datetime DEFAULT NULL,
  `credential_password_changed_at` datetime DEFAULT current_timestamp(),
  `credential_folder_id` int(11) NOT NULL DEFAULT 0,
  `credential_contact_id` int(11) NOT NULL DEFAULT 0,
  `credential_asset_id` int(11) NOT NULL DEFAULT 0,
  `credential_vendor_id` int(11) NOT NULL DEFAULT 0,
  `credential_software_id` int(11) NOT NULL DEFAULT 0,
  `credential_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `credential_restore_staging_clients`
--

DROP TABLE IF EXISTS `credential_restore_staging_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_restore_staging_clients` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_lead` tinyint(1) NOT NULL DEFAULT 0,
  `client_name` varchar(200) NOT NULL,
  `client_type` varchar(200) DEFAULT NULL,
  `client_website` varchar(200) DEFAULT NULL,
  `client_referral` varchar(200) DEFAULT NULL,
  `client_rate` decimal(15,2) DEFAULT NULL,
  `client_currency_code` varchar(200) NOT NULL,
  `client_net_terms` int(10) NOT NULL,
  `client_tax_id_number` varchar(255) DEFAULT NULL,
  `client_abbreviation` varchar(10) DEFAULT NULL,
  `client_notes` text DEFAULT NULL,
  `client_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `client_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `client_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `client_archived_at` datetime DEFAULT NULL,
  `client_accessed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `credential_tags`
--

DROP TABLE IF EXISTS `credential_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_tags` (
  `credential_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`credential_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `credential_tags_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE,
  CONSTRAINT `credential_tags_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credentials`
--

DROP TABLE IF EXISTS `credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credentials` (
  `credential_id` int(11) NOT NULL AUTO_INCREMENT,
  `credential_rotation_due_at` date DEFAULT NULL,
  `credential_last_rotated_at` datetime DEFAULT NULL,
  `credential_name` varchar(200) NOT NULL,
  `credential_description` varchar(500) DEFAULT NULL,
  `credential_type` varchar(200) NOT NULL DEFAULT 'Login',
  `credential_uri` varchar(500) DEFAULT NULL,
  `credential_uri_2` varchar(500) DEFAULT NULL,
  `credential_username` varchar(500) DEFAULT NULL,
  `credential_password` varbinary(200) DEFAULT NULL,
  `credential_otp_secret` varchar(200) DEFAULT NULL,
  `credential_note` text DEFAULT NULL,
  `credential_important` tinyint(1) NOT NULL DEFAULT 0,
  `credential_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `credential_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `credential_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `credential_archived_at` datetime DEFAULT NULL,
  `credential_accessed_at` datetime DEFAULT NULL,
  `credential_password_changed_at` datetime DEFAULT current_timestamp(),
  `credential_folder_id` int(11) NOT NULL DEFAULT 0,
  `credential_contact_id` int(11) NOT NULL DEFAULT 0,
  `credential_asset_id` int(11) NOT NULL DEFAULT 0,
  `credential_vendor_id` int(11) NOT NULL DEFAULT 0,
  `credential_software_id` int(11) NOT NULL DEFAULT 0,
  `credential_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`credential_id`),
  KEY `idx_credentials_client_archived` (`credential_client_id`,`credential_archived_at`)
) ENGINE=InnoDB AUTO_INCREMENT=125 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credits`
--

DROP TABLE IF EXISTS `credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credits` (
  `credit_id` int(11) NOT NULL AUTO_INCREMENT,
  `credit_amount` decimal(15,2) NOT NULL,
  `credit_type` enum('prepaid','manual','refund','promotion','usage') NOT NULL DEFAULT 'manual',
  `credit_note` text DEFAULT NULL,
  `credit_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `credit_created_by` int(11) NOT NULL,
  `credit_expire_at` date DEFAULT NULL,
  `credit_invoice_id` int(11) DEFAULT NULL,
  `credit_client_id` int(11) NOT NULL,
  PRIMARY KEY (`credit_id`),
  KEY `credit_client_id` (`credit_client_id`),
  KEY `credit_invoice_id` (`credit_invoice_id`),
  KEY `credit_created_at` (`credit_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_fields`
--

DROP TABLE IF EXISTS `custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_fields` (
  `custom_field_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_field_table` varchar(255) NOT NULL,
  `custom_field_label` varchar(255) NOT NULL,
  `custom_field_type` varchar(255) NOT NULL DEFAULT 'text',
  `custom_field_location` int(11) NOT NULL DEFAULT 0,
  `custom_field_order` int(11) NOT NULL DEFAULT 999,
  PRIMARY KEY (`custom_field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_links`
--

DROP TABLE IF EXISTS `custom_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_links` (
  `custom_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_link_name` varchar(200) NOT NULL,
  `custom_link_description` text DEFAULT NULL,
  `custom_link_uri` varchar(500) NOT NULL,
  `custom_link_new_tab` tinyint(1) NOT NULL DEFAULT 0,
  `custom_link_icon` varchar(200) DEFAULT NULL,
  `custom_link_location` int(11) NOT NULL DEFAULT 1,
  `custom_link_order` int(11) NOT NULL DEFAULT 0,
  `custom_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `custom_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `custom_link_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`custom_link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_values`
--

DROP TABLE IF EXISTS `custom_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_values` (
  `custom_value_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_value_value` mediumtext NOT NULL,
  `custom_value_field` int(11) NOT NULL,
  PRIMARY KEY (`custom_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `department_sites`
--

DROP TABLE IF EXISTS `department_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `department_sites` (
  `department_site_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`department_site_id`),
  UNIQUE KEY `uniq_department_site` (`client_id`,`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `discount_codes`
--

DROP TABLE IF EXISTS `discount_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `discount_codes` (
  `discount_code_id` int(11) NOT NULL AUTO_INCREMENT,
  `discount_code_description` varchar(250) DEFAULT NULL,
  `discount_code_amount` decimal(15,2) NOT NULL,
  `discount_code` varchar(200) NOT NULL,
  `discount_code_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_code_created_by` int(11) NOT NULL,
  `discount_code_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `discount_code_archived_at` datetime DEFAULT NULL,
  `discount_code_expire_at` date DEFAULT NULL,
  PRIMARY KEY (`discount_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_files`
--

DROP TABLE IF EXISTS `document_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_files` (
  `document_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`document_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `document_files_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE,
  CONSTRAINT `document_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_templates`
--

DROP TABLE IF EXISTS `document_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_templates` (
  `document_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_template_name` varchar(200) NOT NULL,
  `document_template_description` text DEFAULT NULL,
  `document_template_content` longtext NOT NULL,
  `document_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `document_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `document_template_archived_at` datetime DEFAULT NULL,
  `document_template_created_by` int(11) NOT NULL DEFAULT 0,
  `document_template_updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`document_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_versions`
--

DROP TABLE IF EXISTS `document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_versions` (
  `document_version_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_version_name` varchar(200) NOT NULL,
  `document_version_description` text DEFAULT NULL,
  `document_version_content` longtext NOT NULL,
  `document_version_created_by` int(11) DEFAULT 0,
  `document_version_created_at` datetime NOT NULL,
  `document_version_document_id` int(11) NOT NULL,
  PRIMARY KEY (`document_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_name` varchar(200) NOT NULL,
  `document_description` text DEFAULT NULL,
  `document_content` longtext NOT NULL,
  `document_content_raw` longtext NOT NULL,
  `document_client_visible` int(11) NOT NULL DEFAULT 1,
  `document_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `document_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `document_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `document_archived_at` datetime DEFAULT NULL,
  `document_accessed_at` datetime DEFAULT NULL,
  `document_folder_id` int(11) NOT NULL DEFAULT 0,
  `document_created_by` int(11) NOT NULL DEFAULT 0,
  `document_updated_by` int(11) NOT NULL DEFAULT 0,
  `document_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`document_id`),
  FULLTEXT KEY `document_content_raw` (`document_content_raw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domain_history`
--

DROP TABLE IF EXISTS `domain_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_history` (
  `domain_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_history_column` varchar(200) NOT NULL,
  `domain_history_old_value` text NOT NULL,
  `domain_history_new_value` text NOT NULL,
  `domain_history_domain_id` int(11) NOT NULL,
  `domain_history_modified_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`domain_history_id`),
  KEY `domain_history_domain_id` (`domain_history_domain_id`),
  CONSTRAINT `domain_history_ibfk_1` FOREIGN KEY (`domain_history_domain_id`) REFERENCES `domains` (`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `domain_id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_name` varchar(200) NOT NULL,
  `domain_description` text DEFAULT NULL,
  `domain_expire` date DEFAULT NULL,
  `domain_registered_at` date DEFAULT NULL,
  `domain_ip` varchar(255) DEFAULT NULL,
  `domain_name_servers` varchar(255) DEFAULT NULL,
  `domain_mail_servers` varchar(255) DEFAULT NULL,
  `domain_txt` text DEFAULT NULL,
  `domain_raw_whois` text DEFAULT NULL,
  `domain_registrar_name` varchar(255) DEFAULT NULL,
  `domain_status` varchar(500) DEFAULT NULL,
  `domain_dnssec` varchar(50) DEFAULT NULL,
  `domain_notes` text DEFAULT NULL,
  `domain_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `domain_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `domain_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `domain_archived_at` datetime DEFAULT NULL,
  `domain_accessed_at` datetime DEFAULT NULL,
  `domain_registrar` int(11) NOT NULL DEFAULT 0,
  `domain_webhost` int(11) NOT NULL DEFAULT 0,
  `domain_dnshost` int(11) NOT NULL DEFAULT 0,
  `domain_mailhost` int(11) NOT NULL DEFAULT 0,
  `domain_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`domain_id`),
  KEY `idx_domains_client_archived_expire` (`domain_client_id`,`domain_archived_at`,`domain_expire`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_queue`
--

DROP TABLE IF EXISTS `email_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `email_id` int(11) NOT NULL AUTO_INCREMENT,
  `email_status` tinyint(1) NOT NULL DEFAULT 0,
  `email_recipient` varchar(255) NOT NULL,
  `email_recipient_name` varchar(255) DEFAULT NULL,
  `email_from` varchar(255) NOT NULL,
  `email_from_name` varchar(255) NOT NULL,
  `email_subject` varchar(255) NOT NULL,
  `email_content` longtext NOT NULL,
  `email_cal_str` varchar(1024) DEFAULT NULL,
  `email_queued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email_failed_at` datetime DEFAULT NULL,
  `email_attempts` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_description` text DEFAULT NULL,
  `expense_amount` decimal(15,2) NOT NULL,
  `expense_currency_code` varchar(200) NOT NULL,
  `expense_date` date NOT NULL,
  `expense_reference` varchar(200) DEFAULT NULL,
  `expense_payment_method` varchar(200) DEFAULT NULL,
  `expense_receipt` varchar(200) DEFAULT NULL,
  `expense_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expense_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `expense_archived_at` datetime DEFAULT NULL,
  `expense_vendor_id` int(11) NOT NULL DEFAULT 0,
  `expense_client_id` int(11) NOT NULL DEFAULT 0,
  `expense_category_id` int(11) NOT NULL DEFAULT 0,
  `expense_account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `files` (
  `file_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_reference_name` varchar(200) DEFAULT NULL,
  `file_name` varchar(200) NOT NULL,
  `file_description` varchar(250) DEFAULT NULL,
  `file_ext` varchar(10) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `file_mime_type` varchar(100) DEFAULT NULL,
  `file_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `file_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `file_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `file_archived_at` datetime DEFAULT NULL,
  `file_accessed_at` datetime DEFAULT NULL,
  `file_created_by` int(11) NOT NULL DEFAULT 0,
  `file_folder_id` int(11) NOT NULL DEFAULT 0,
  `file_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `folders`
--

DROP TABLE IF EXISTS `folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `folders` (
  `folder_id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(200) NOT NULL,
  `parent_folder` int(11) NOT NULL DEFAULT 0,
  `folder_location` int(11) DEFAULT 0,
  `folder_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `holidays` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_country` varchar(200) NOT NULL,
  `holiday_year` int(4) NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) NOT NULL,
  `holiday_is_custom` tinyint(1) NOT NULL DEFAULT 0,
  `holiday_created_by` int(11) NOT NULL DEFAULT 0,
  `holiday_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`holiday_id`),
  KEY `idx_holidays_country_year` (`holiday_country`,`holiday_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `history`
--

DROP TABLE IF EXISTS `history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `history_status` varchar(200) NOT NULL,
  `history_description` varchar(200) NOT NULL,
  `history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `history_invoice_id` int(11) NOT NULL DEFAULT 0,
  `history_recurring_invoice_id` int(11) NOT NULL DEFAULT 0,
  `history_quote_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `integration_jobs`
--

DROP TABLE IF EXISTS `integration_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_jobs` (
  `job_id` int(11) NOT NULL AUTO_INCREMENT,
  `integration_id` int(11) DEFAULT NULL,
  `job_type` varchar(100) NOT NULL,
  `resource_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','running','completed','failed','dead_letter') NOT NULL DEFAULT 'pending',
  `priority` int(11) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 5,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `heartbeat_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `payload` text DEFAULT NULL,
  `result` text DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`job_id`),
  KEY `idx_integration_jobs_status_available` (`status`,`available_at`),
  KEY `idx_integration_jobs_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_invoice_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_prefix` varchar(200) DEFAULT NULL,
  `invoice_number` int(11) NOT NULL,
  `invoice_scope` varchar(255) DEFAULT NULL,
  `invoice_status` varchar(200) NOT NULL,
  `invoice_date` date NOT NULL,
  `invoice_due` date NOT NULL,
  `invoice_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_credit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_currency_code` varchar(200) NOT NULL,
  `invoice_note` text DEFAULT NULL,
  `invoice_url_key` varchar(200) DEFAULT NULL,
  `invoice_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `invoice_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `invoice_archived_at` datetime DEFAULT NULL,
  `invoice_category_id` int(11) NOT NULL,
  `invoice_recurring_invoice_id` int(11) NOT NULL DEFAULT 0,
  `invoice_client_id` int(11) NOT NULL,
  PRIMARY KEY (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kb_articles`
--

DROP TABLE IF EXISTS `kb_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_articles` (
  `kb_article_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_title` varchar(255) NOT NULL,
  `kb_article_content` mediumtext DEFAULT NULL,
  `kb_article_content_raw` mediumtext DEFAULT NULL,
  `kb_article_client_id` int(11) NOT NULL DEFAULT 0,
  `kb_article_client_visible` tinyint(1) NOT NULL DEFAULT 1,
  `kb_article_training_visible` tinyint(1) NOT NULL DEFAULT 0,
  `kb_article_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `kb_article_created_by` int(11) NOT NULL DEFAULT 0,
  `kb_article_updated_by` int(11) NOT NULL DEFAULT 0,
  `kb_article_created_at` datetime DEFAULT current_timestamp(),
  `kb_article_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `kb_article_archived_at` datetime DEFAULT NULL,
  `kb_article_category_id` int(11) NOT NULL DEFAULT 0,
  `kb_article_review_due_at` date DEFAULT NULL,
  `kb_article_reviewer_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`kb_article_id`),
  KEY `kb_article_client_id` (`kb_article_client_id`),
  KEY `kb_article_category_id` (`kb_article_category_id`),
  FULLTEXT KEY `kb_article_content_raw` (`kb_article_content_raw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `labor_types`
--

DROP TABLE IF EXISTS `labor_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_types` (
  `labor_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `labor_type_name` varchar(100) NOT NULL,
  `labor_type_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `labor_type_color` varchar(20) NOT NULL DEFAULT '#6c757d',
  `labor_type_order` int(11) NOT NULL DEFAULT 0,
  `labor_type_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`labor_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `location_tags`
--

DROP TABLE IF EXISTS `location_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_tags` (
  `location_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`location_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `location_tags_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `location_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(200) NOT NULL,
  `location_type` varchar(50) DEFAULT NULL,
  `location_manager_contact_id` int(11) DEFAULT NULL,
  `location_description` text DEFAULT NULL,
  `location_country` varchar(200) DEFAULT NULL,
  `location_latitude` decimal(10,7) DEFAULT NULL,
  `location_longitude` decimal(10,7) DEFAULT NULL,
  `location_address` varchar(200) DEFAULT NULL,
  `location_city` varchar(200) DEFAULT NULL,
  `location_state` varchar(200) DEFAULT NULL,
  `location_zip` varchar(200) DEFAULT NULL,
  `location_phone_country_code` varchar(10) DEFAULT NULL,
  `location_phone` varchar(200) DEFAULT NULL,
  `location_phone_extension` varchar(10) DEFAULT NULL,
  `location_fax_country_code` varchar(10) DEFAULT NULL,
  `location_fax` varchar(200) DEFAULT NULL,
  `location_hours` text DEFAULT NULL,
  `location_emergency_contacts` text DEFAULT NULL,
  `location_shipping_instructions` text DEFAULT NULL,
  `location_photo` varchar(200) DEFAULT NULL,
  `location_primary` tinyint(1) NOT NULL DEFAULT 0,
  `location_notes` text DEFAULT NULL,
  `location_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `location_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `location_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `location_archived_at` datetime DEFAULT NULL,
  `location_accessed_at` datetime DEFAULT NULL,
  `location_contact_id` int(11) NOT NULL DEFAULT 0,
  `location_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `log_type` varchar(200) NOT NULL,
  `log_action` varchar(255) NOT NULL,
  `log_description` varchar(1000) NOT NULL,
  `log_ip` varchar(200) DEFAULT NULL,
  `log_user_agent` varchar(250) DEFAULT NULL,
  `log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `log_client_id` int(11) NOT NULL DEFAULT 0,
  `log_user_id` int(11) NOT NULL DEFAULT 0,
  `log_entity_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`log_id`),
  KEY `idx_logs_client_created` (`log_client_id`,`log_created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11225 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `module_id` int(11) NOT NULL AUTO_INCREMENT,
  `module_name` varchar(200) NOT NULL,
  `module_description` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `network_drives`
--

DROP TABLE IF EXISTS `network_drives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `network_drives` (
  `network_drive_id` int(11) NOT NULL AUTO_INCREMENT,
  `network_drive_client_id` int(11) NOT NULL DEFAULT 0,
  `network_drive_name` varchar(200) NOT NULL,
  `network_drive_letter` varchar(10) DEFAULT NULL,
  `network_drive_path` varchar(500) DEFAULT NULL,
  `network_drive_purpose` varchar(200) DEFAULT NULL,
  `network_drive_notes` text DEFAULT NULL,
  `network_drive_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `network_drive_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `network_drive_archived_at` datetime DEFAULT NULL,
  `network_drive_created_by` int(11) NOT NULL DEFAULT 0,
  `network_drive_updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`network_drive_id`),
  KEY `idx_network_drives_client_archived` (`network_drive_client_id`,`network_drive_archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `networks`
--

DROP TABLE IF EXISTS `networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `networks` (
  `network_id` int(11) NOT NULL AUTO_INCREMENT,
  `network_name` varchar(200) NOT NULL,
  `network_description` text DEFAULT NULL,
  `network_vlan` int(11) DEFAULT NULL,
  `network` varchar(200) NOT NULL,
  `network_subnet` varchar(200) DEFAULT NULL,
  `network_gateway` varchar(200) NOT NULL,
  `network_primary_dns` varchar(200) DEFAULT NULL,
  `network_secondary_dns` varchar(200) DEFAULT NULL,
  `network_dhcp_range` varchar(200) DEFAULT NULL,
  `network_notes` text DEFAULT NULL,
  `network_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `network_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `network_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `network_archived_at` datetime DEFAULT NULL,
  `network_accessed_at` datetime DEFAULT NULL,
  `network_location_id` int(11) NOT NULL DEFAULT 0,
  `network_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`network_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_type` varchar(200) NOT NULL,
  `notification` varchar(1000) NOT NULL,
  `notification_action` varchar(250) DEFAULT NULL,
  `notification_timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `notification_dismissed_at` datetime DEFAULT NULL,
  `notification_dismissed_by` int(11) DEFAULT NULL,
  `notification_client_id` int(11) NOT NULL DEFAULT 0,
  `notification_user_id` int(11) NOT NULL DEFAULT 0,
  `notification_entity_id` int(11) DEFAULT 0,
  PRIMARY KEY (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `payment_method_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_method_name` varchar(200) NOT NULL,
  `payment_method_description` varchar(250) DEFAULT NULL,
  `payment_method_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_method_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_method_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_providers`
--

DROP TABLE IF EXISTS `payment_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_providers` (
  `payment_provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_provider_name` varchar(200) NOT NULL,
  `payment_provider_description` varchar(250) DEFAULT NULL,
  `payment_provider_public_key` varchar(250) DEFAULT NULL,
  `payment_provider_private_key` varchar(250) DEFAULT NULL,
  `payment_provider_threshold` decimal(15,2) DEFAULT NULL,
  `payment_provider_active` tinyint(1) NOT NULL DEFAULT 1,
  `payment_provider_account` int(11) NOT NULL,
  `payment_provider_expense_vendor` int(11) NOT NULL DEFAULT 0,
  `payment_provider_expense_category` int(11) NOT NULL DEFAULT 0,
  `payment_provider_expense_percentage_fee` decimal(4,4) DEFAULT NULL,
  `payment_provider_expense_flat_fee` decimal(15,2) DEFAULT NULL,
  `payment_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_provider_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payment_provider_webhook_secret` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`payment_provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_date` date NOT NULL,
  `payment_amount` decimal(15,2) NOT NULL,
  `payment_currency_code` varchar(10) NOT NULL,
  `payment_method` varchar(200) DEFAULT NULL,
  `payment_reference` varchar(200) DEFAULT NULL,
  `payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payment_archived_at` datetime DEFAULT NULL,
  `payment_account_id` int(11) NOT NULL,
  `payment_invoice_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `people_import_runs`
--

DROP TABLE IF EXISTS `people_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `people_import_runs` (
  `import_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `imported_by_user_id` int(11) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `created_count` int(11) NOT NULL DEFAULT 0,
  `updated_count` int(11) NOT NULL DEFAULT 0,
  `skipped_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('previewed','approved','failed') NOT NULL DEFAULT 'previewed',
  `results_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`import_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_stock`
--

DROP TABLE IF EXISTS `product_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stock` (
  `stock_id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_qty` int(11) NOT NULL,
  `stock_note` text DEFAULT NULL,
  `stock_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stock_expense_id` int(11) DEFAULT NULL,
  `stock_item_id` int(11) DEFAULT NULL,
  `stock_product_id` int(11) NOT NULL,
  PRIMARY KEY (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(200) NOT NULL,
  `product_type` enum('service','product') NOT NULL DEFAULT 'service',
  `product_description` text DEFAULT NULL,
  `product_code` varchar(200) DEFAULT NULL,
  `product_location` varchar(250) DEFAULT NULL,
  `product_price` decimal(15,2) NOT NULL,
  `product_currency_code` varchar(200) NOT NULL,
  `product_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `product_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `product_archived_at` datetime DEFAULT NULL,
  `product_tax_id` int(11) NOT NULL DEFAULT 0,
  `product_category_id` int(11) NOT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_template_ticket_templates`
--

DROP TABLE IF EXISTS `project_template_ticket_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_template_ticket_templates` (
  `ticket_template_id` int(11) NOT NULL,
  `project_template_id` int(11) NOT NULL,
  `ticket_template_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_template_id`,`project_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_templates`
--

DROP TABLE IF EXISTS `project_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_templates` (
  `project_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `project_template_name` varchar(200) NOT NULL,
  `project_template_description` text DEFAULT NULL,
  `project_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `project_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `project_template_archived_at` datetime DEFAULT NULL,
  `project_template_default_contract_template_id` int(11) DEFAULT NULL,
  `project_template_is_onboarding` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`project_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL AUTO_INCREMENT,
  `project_prefix` varchar(200) DEFAULT NULL,
  `project_number` int(11) NOT NULL DEFAULT 1,
  `project_name` varchar(255) NOT NULL,
  `project_description` mediumtext DEFAULT NULL,
  `project_due` date DEFAULT NULL,
  `project_manager` int(11) NOT NULL DEFAULT 0,
  `project_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `project_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `project_completed_at` datetime DEFAULT NULL,
  `project_archived_at` datetime DEFAULT NULL,
  `project_client_id` int(11) NOT NULL DEFAULT 0,
  `project_start` date DEFAULT NULL,
  `project_estimated_hours` decimal(10,2) DEFAULT NULL,
  `project_budget_amount` decimal(12,2) DEFAULT NULL,
  `project_hourly_rate` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quote_files`
--

DROP TABLE IF EXISTS `quote_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_files` (
  `quote_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`quote_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `quote_files_ibfk_1` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`quote_id`) ON DELETE CASCADE,
  CONSTRAINT `quote_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quote_items`
--

DROP TABLE IF EXISTS `quote_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_quote_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quotes`
--

DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `quote_id` int(11) NOT NULL AUTO_INCREMENT,
  `quote_prefix` varchar(200) DEFAULT NULL,
  `quote_number` int(11) NOT NULL,
  `quote_scope` varchar(255) DEFAULT NULL,
  `quote_status` varchar(200) NOT NULL,
  `quote_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quote_date` date NOT NULL,
  `quote_expire` date DEFAULT NULL,
  `quote_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quote_currency_code` varchar(200) NOT NULL,
  `quote_note` text DEFAULT NULL,
  `quote_url_key` varchar(200) DEFAULT NULL,
  `quote_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `quote_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `quote_archived_at` datetime DEFAULT NULL,
  `quote_category_id` int(11) NOT NULL,
  `quote_client_id` int(11) NOT NULL,
  PRIMARY KEY (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rack_units`
--

DROP TABLE IF EXISTS `rack_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rack_units` (
  `unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_start_number` int(11) NOT NULL,
  `unit_end_number` int(11) NOT NULL,
  `unit_device` varchar(200) DEFAULT NULL,
  `unit_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `unit_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `unit_archived_at` datetime DEFAULT NULL,
  `unit_asset_id` int(11) DEFAULT NULL,
  `unit_rack_id` int(11) NOT NULL,
  PRIMARY KEY (`unit_id`),
  KEY `unit_rack_id` (`unit_rack_id`),
  CONSTRAINT `rack_units_ibfk_1` FOREIGN KEY (`unit_rack_id`) REFERENCES `racks` (`rack_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `racks`
--

DROP TABLE IF EXISTS `racks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `racks` (
  `rack_id` int(11) NOT NULL AUTO_INCREMENT,
  `rack_name` varchar(200) NOT NULL,
  `rack_description` text DEFAULT NULL,
  `rack_model` varchar(200) DEFAULT NULL,
  `rack_depth` varchar(50) DEFAULT NULL,
  `rack_type` varchar(50) DEFAULT NULL,
  `rack_units` int(11) NOT NULL,
  `rack_photo` varchar(200) DEFAULT NULL,
  `rack_physical_location` varchar(200) DEFAULT NULL,
  `rack_notes` text DEFAULT NULL,
  `rack_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `rack_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rack_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `rack_archived_at` datetime DEFAULT NULL,
  `rack_location_id` int(11) DEFAULT NULL,
  `rack_client_id` int(11) NOT NULL,
  PRIMARY KEY (`rack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `records`
--

DROP TABLE IF EXISTS `records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `records` (
  `record_id` int(11) NOT NULL AUTO_INCREMENT,
  `record_type` varchar(200) NOT NULL,
  `record` varchar(200) NOT NULL,
  `record_value` varchar(200) NOT NULL,
  `record_priority` int(11) DEFAULT NULL,
  `record_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `record_updated_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  `record_archived_at` datetime DEFAULT NULL,
  `record_domain_id` int(11) NOT NULL,
  PRIMARY KEY (`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_expenses`
--

DROP TABLE IF EXISTS `recurring_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_expenses` (
  `recurring_expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_expense_frequency` tinyint(1) NOT NULL,
  `recurring_expense_day` tinyint(4) DEFAULT NULL,
  `recurring_expense_month` tinyint(4) DEFAULT NULL,
  `recurring_expense_last_sent` date DEFAULT NULL,
  `recurring_expense_next_date` date NOT NULL,
  `recurring_expense_status` tinyint(1) NOT NULL DEFAULT 1,
  `recurring_expense_description` mediumtext DEFAULT NULL,
  `recurring_expense_amount` decimal(15,2) NOT NULL,
  `recurring_expense_payment_method` varchar(200) DEFAULT NULL,
  `recurring_expense_reference` varchar(255) DEFAULT NULL,
  `recurring_expense_currency_code` varchar(200) NOT NULL,
  `recurring_expense_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_expense_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_expense_archived_at` datetime DEFAULT NULL,
  `recurring_expense_vendor_id` int(11) NOT NULL,
  `recurring_expense_client_id` int(11) NOT NULL DEFAULT 0,
  `recurring_expense_category_id` int(11) NOT NULL,
  `recurring_expense_account_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_invoice_items`
--

DROP TABLE IF EXISTS `recurring_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_recurring_invoice_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_invoices`
--

DROP TABLE IF EXISTS `recurring_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_invoices` (
  `recurring_invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_invoice_prefix` varchar(200) DEFAULT NULL,
  `recurring_invoice_number` int(11) NOT NULL,
  `recurring_invoice_scope` varchar(255) DEFAULT NULL,
  `recurring_invoice_frequency` varchar(200) NOT NULL,
  `recurring_invoice_last_sent` date DEFAULT NULL,
  `recurring_invoice_next_date` date NOT NULL,
  `recurring_invoice_status` int(1) NOT NULL,
  `recurring_invoice_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `recurring_invoice_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `recurring_invoice_currency_code` varchar(200) NOT NULL,
  `recurring_invoice_note` text DEFAULT NULL,
  `recurring_invoice_email_notify` tinyint(1) NOT NULL DEFAULT 1,
  `recurring_invoice_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_invoice_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_invoice_archived_at` datetime DEFAULT NULL,
  `recurring_invoice_category_id` int(11) NOT NULL,
  `recurring_invoice_client_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_payments`
--

DROP TABLE IF EXISTS `recurring_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_payments` (
  `recurring_payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_payment_currency_code` varchar(10) NOT NULL,
  `recurring_payment_method` varchar(200) NOT NULL,
  `recurring_payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_payment_archived_at` datetime DEFAULT NULL,
  `recurring_payment_account_id` int(11) NOT NULL,
  `recurring_payment_recurring_expense_id` int(11) NOT NULL DEFAULT 0,
  `recurring_payment_recurring_invoice_id` int(11) NOT NULL,
  `recurring_payment_saved_payment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`recurring_payment_id`),
  KEY `fk_recurring_saved_payment` (`recurring_payment_saved_payment_id`),
  CONSTRAINT `fk_recurring_saved_payment` FOREIGN KEY (`recurring_payment_saved_payment_id`) REFERENCES `client_saved_payment_methods` (`saved_payment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_ticket_assets`
--

DROP TABLE IF EXISTS `recurring_ticket_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_ticket_assets` (
  `recurring_ticket_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_ticket_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `recurring_ticket_assets_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `recurring_ticket_assets_ibfk_2` FOREIGN KEY (`recurring_ticket_id`) REFERENCES `recurring_tickets` (`recurring_ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_tickets`
--

DROP TABLE IF EXISTS `recurring_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_tickets` (
  `recurring_ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_ticket_category` varchar(200) DEFAULT NULL,
  `recurring_ticket_subject` varchar(500) NOT NULL,
  `recurring_ticket_details` longtext NOT NULL,
  `recurring_ticket_priority` varchar(200) DEFAULT NULL,
  `recurring_ticket_frequency` varchar(10) NOT NULL,
  `recurring_ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `recurring_ticket_start_date` date NOT NULL,
  `recurring_ticket_next_run` date NOT NULL,
  `recurring_ticket_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_ticket_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_ticket_created_by` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_assigned_to` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_client_id` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_contact_id` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_asset_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`recurring_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remember_tokens` (
  `remember_token_id` int(11) NOT NULL AUTO_INCREMENT,
  `remember_token_token` varchar(255) NOT NULL,
  `remember_token_user_id` int(11) NOT NULL,
  `remember_token_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`remember_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rivet_core_migrations`
--

DROP TABLE IF EXISTS `rivet_core_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rivet_core_migrations` (
  `migration_id` varchar(100) NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rivet_core_migrations`
-- (audit_events is created by this file, so Core migration 0001 is already satisfied)
--

INSERT INTO `rivet_core_migrations` (`migration_id`, `applied_at`) VALUES ('0001_audit_events', current_timestamp()), ('0002_integration_jobs', current_timestamp()), ('0003_mcp_unlinked_identities', current_timestamp()), ('0004_problems_and_changes', current_timestamp()), ('0005_webhook_deliveries', current_timestamp()), ('0006_automation_rules', current_timestamp()), ('0007_workflow_tables', current_timestamp());

--
-- Table structure for table `revenues`
--

DROP TABLE IF EXISTS `revenues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenues` (
  `revenue_id` int(11) NOT NULL AUTO_INCREMENT,
  `revenue_date` date NOT NULL,
  `revenue_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `revenue_currency_code` varchar(200) NOT NULL,
  `revenue_payment_method` varchar(200) DEFAULT NULL,
  `revenue_reference` varchar(200) DEFAULT NULL,
  `revenue_description` varchar(200) DEFAULT NULL,
  `revenue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revenue_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `revenue_archived_at` datetime DEFAULT NULL,
  `revenue_category_id` int(11) NOT NULL DEFAULT 0,
  `revenue_account_id` int(11) NOT NULL,
  `revenue_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`revenue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rmm_alerts`
--

DROP TABLE IF EXISTS `rmm_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `integration_id` int(11) NOT NULL,
  `tactical_alert_id` varchar(200) DEFAULT NULL,
  `severity` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'new',
  `message` text DEFAULT NULL,
  `acknowledged_by` int(11) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `raw_data_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `ticket_id` int(11) DEFAULT NULL,
  `automation_processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_integration_alert` (`integration_id`,`tactical_alert_id`),
  KEY `asset_id` (`asset_id`),
  KEY `client_id` (`client_id`),
  KEY `tactical_alert_id` (`tactical_alert_id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rmm_check_deployments`
--

DROP TABLE IF EXISTS `rmm_check_deployments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_check_deployments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `policy_id` int(11) NOT NULL,
  `link_id` int(11) NOT NULL,
  `tactical_check_id` varchar(100) DEFAULT NULL,
  `deployed_at` datetime DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_link` (`policy_id`,`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `rmm_check_policies`
--

DROP TABLE IF EXISTS `rmm_check_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_check_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'any',
  `check_type` varchar(50) NOT NULL,
  `warning_threshold` int(11) DEFAULT NULL,
  `critical_threshold` int(11) DEFAULT NULL,
  `check_interval` int(11) NOT NULL DEFAULT 120,
  `check_params` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `rmm_integrations`
--

DROP TABLE IF EXISTS `rmm_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_integrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'tactical_rmm',
  `api_url` varchar(500) NOT NULL,
  `web_url` varchar(500) DEFAULT NULL,
  `default_client_id` int(11) DEFAULT NULL,
  `api_key_enc` text NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `rmm_remote_sessions`
--

DROP TABLE IF EXISTS `rmm_remote_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_remote_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `connection_type` varchar(50) DEFAULT NULL,
  `connection_url` varchar(1000) DEFAULT NULL,
  `source_ip` varchar(100) DEFAULT NULL,
  `user_agent` varchar(300) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `rmm_script_runs`
--

DROP TABLE IF EXISTS `rmm_script_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_script_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `script_id` int(11) DEFAULT NULL,
  `asset_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `tactical_job_id` varchar(200) DEFAULT NULL,
  `output` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `rmm_scripts`
--

DROP TABLE IF EXISTS `rmm_scripts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_scripts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `script_type` varchar(20) DEFAULT 'powershell',
  `script_body` longtext DEFAULT NULL,
  `tactical_script_id` int(11) DEFAULT NULL,
  `rmm_integration_id` int(11) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_integration_script` (`rmm_integration_id`,`tactical_script_id`)
) ENGINE=InnoDB AUTO_INCREMENT=145 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rmm_sync_log`
--

DROP TABLE IF EXISTS `rmm_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmm_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `assets_created` int(11) DEFAULT 0,
  `assets_updated` int(11) DEFAULT 0,
  `assets_matched` int(11) DEFAULT 0,
  `assets_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `service_assets`
--

DROP TABLE IF EXISTS `service_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_assets` (
  `service_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `service_assets_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_certificates`
--

DROP TABLE IF EXISTS `service_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_certificates` (
  `service_id` int(11) NOT NULL,
  `certificate_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `certificate_id` (`certificate_id`),
  CONSTRAINT `service_certificates_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_certificates_ibfk_2` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_contacts`
--

DROP TABLE IF EXISTS `service_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contacts` (
  `service_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `service_contacts_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_credentials`
--

DROP TABLE IF EXISTS `service_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_credentials` (
  `service_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `service_credentials_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_documents`
--

DROP TABLE IF EXISTS `service_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_documents` (
  `service_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `service_documents_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_domains`
--

DROP TABLE IF EXISTS `service_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_domains` (
  `service_id` int(11) NOT NULL,
  `domain_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_vendors`
--

DROP TABLE IF EXISTS `service_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_vendors` (
  `service_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `service_vendors_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_vendors_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `service_name` varchar(200) NOT NULL,
  `service_description` varchar(200) NOT NULL,
  `service_category` varchar(20) NOT NULL,
  `service_importance` varchar(10) NOT NULL,
  `service_backup` varchar(200) DEFAULT NULL,
  `service_notes` mediumtext NOT NULL,
  `service_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `service_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `service_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `service_accessed_at` datetime DEFAULT NULL,
  `service_review_due` date DEFAULT NULL,
  `service_client_id` int(11) NOT NULL,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `company_id` int(11) NOT NULL,
  `config_current_database_version` varchar(10) NOT NULL,
  `config_start_page` varchar(200) DEFAULT 'clients.php',
  `config_smtp_provider` varchar(200) DEFAULT NULL,
  `config_smtp_host` varchar(200) DEFAULT NULL,
  `config_smtp_port` int(5) DEFAULT NULL,
  `config_smtp_encryption` varchar(200) DEFAULT NULL,
  `config_smtp_username` varchar(200) DEFAULT NULL,
  `config_smtp_password` varchar(200) DEFAULT NULL,
  `config_mail_from_email` varchar(200) DEFAULT NULL,
  `config_mail_from_name` varchar(200) DEFAULT NULL,
  `config_imap_provider` varchar(200) DEFAULT NULL,
  `config_mail_oauth_client_id` varchar(255) DEFAULT NULL,
  `config_mail_oauth_client_secret` varchar(255) DEFAULT NULL,
  `config_mail_oauth_tenant_id` varchar(255) DEFAULT NULL,
  `config_mail_oauth_refresh_token` text DEFAULT NULL,
  `config_mail_oauth_access_token` text DEFAULT NULL,
  `config_mail_oauth_access_token_expires_at` datetime DEFAULT NULL,
  `config_imap_host` varchar(200) DEFAULT NULL,
  `config_imap_port` int(5) DEFAULT NULL,
  `config_imap_encryption` varchar(200) DEFAULT NULL,
  `config_imap_username` varchar(200) DEFAULT NULL,
  `config_imap_password` varchar(200) DEFAULT NULL,
  `config_default_transfer_from_account` int(11) DEFAULT NULL,
  `config_default_transfer_to_account` int(11) DEFAULT NULL,
  `config_default_payment_account` int(11) DEFAULT NULL,
  `config_default_expense_account` int(11) DEFAULT NULL,
  `config_default_payment_method` varchar(200) DEFAULT NULL,
  `config_default_expense_payment_method` varchar(200) DEFAULT NULL,
  `config_default_calendar` int(11) DEFAULT NULL,
  `config_default_net_terms` int(11) DEFAULT NULL,
  `config_default_hourly_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `config_project_prefix` varchar(200) NOT NULL DEFAULT 'PRJ-',
  `config_project_next_number` int(11) NOT NULL DEFAULT 1,
  `config_invoice_prefix` varchar(200) DEFAULT NULL,
  `config_invoice_next_number` int(11) DEFAULT NULL,
  `config_invoice_footer` text DEFAULT NULL,
  `config_invoice_from_name` varchar(200) DEFAULT NULL,
  `config_invoice_from_email` varchar(200) DEFAULT NULL,
  `config_invoice_late_fee_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_invoice_late_fee_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `config_invoice_paid_notification_email` varchar(200) DEFAULT NULL,
  `config_invoice_show_tax_id` tinyint(1) NOT NULL DEFAULT 0,
  `config_recurring_invoice_prefix` varchar(200) DEFAULT NULL,
  `config_recurring_invoice_next_number` int(11) NOT NULL DEFAULT 1,
  `config_quote_prefix` varchar(200) DEFAULT NULL,
  `config_quote_next_number` int(11) DEFAULT NULL,
  `config_quote_footer` text DEFAULT NULL,
  `config_quote_from_name` varchar(200) DEFAULT NULL,
  `config_quote_from_email` varchar(200) DEFAULT NULL,
  `config_quote_notification_email` varchar(200) DEFAULT NULL,
  `config_ticket_prefix` varchar(200) DEFAULT NULL,
  `config_ticket_next_number` int(11) DEFAULT NULL,
  `config_ticket_from_name` varchar(200) DEFAULT NULL,
  `config_ticket_from_email` varchar(200) DEFAULT NULL,
  `config_ticket_email_parse` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_email_parse_unknown_senders` int(1) NOT NULL DEFAULT 0,
  `config_ticket_client_general_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `config_ticket_autoclose_hours` int(5) NOT NULL DEFAULT 72,
  `config_ticket_csat_enable` tinyint(1) NOT NULL DEFAULT 1,
  `config_ticket_csat_reminder_days` int(5) NOT NULL DEFAULT 3,
  `config_ticket_csat_low_rating_threshold` tinyint(4) NOT NULL DEFAULT 2,
  `config_ticket_csat_google_review_url` varchar(255) DEFAULT NULL,
  `config_ticket_new_ticket_notification_email` varchar(200) DEFAULT NULL,
  `config_ticket_default_billable` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_timer_autostart` tinyint(1) NOT NULL DEFAULT 0,
  `config_enable_cron` tinyint(1) NOT NULL DEFAULT 0,
  `config_recurring_auto_send_invoice` tinyint(1) NOT NULL DEFAULT 1,
  `config_enable_alert_domain_expire` tinyint(1) NOT NULL DEFAULT 1,
  `config_send_invoice_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `config_invoice_overdue_reminders` varchar(200) DEFAULT NULL,
  `config_azure_client_id` varchar(200) DEFAULT NULL,
  `config_azure_client_secret` varchar(200) DEFAULT NULL,
  `config_oidc_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_oidc_issuer` varchar(255) DEFAULT NULL,
  `config_oidc_client_id` varchar(255) DEFAULT NULL,
  `config_oidc_client_secret` text DEFAULT NULL,
  `config_oidc_link_by_email` tinyint(1) NOT NULL DEFAULT 0,
  `config_oidc_require_verified_email` tinyint(1) NOT NULL DEFAULT 1,
  `config_oidc_agent_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_outlook_cal_client_id` varchar(200) DEFAULT NULL,
  `config_outlook_cal_client_secret` varchar(500) DEFAULT NULL,
  `config_outlook_cal_tenant_id` varchar(200) DEFAULT NULL,
  `config_module_enable_itdoc` tinyint(1) NOT NULL DEFAULT 1,
  `config_module_enable_accounting` tinyint(1) NOT NULL DEFAULT 0,
  `config_client_portal_enable` tinyint(1) NOT NULL DEFAULT 1,
  `config_login_message` text DEFAULT NULL,
  `config_login_key_required` tinyint(1) NOT NULL DEFAULT 0,
  `config_login_key_secret` varchar(255) DEFAULT NULL,
  `config_login_remember_me_expire` int(11) NOT NULL DEFAULT 3,
  `config_login_session_lifetime` int(11) NOT NULL DEFAULT 480,
  `config_log_retention` int(11) NOT NULL DEFAULT 90,
  `config_backup_auto_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_backup_frequency` varchar(20) NOT NULL DEFAULT 'daily',
  `config_backup_retain_count` int(11) NOT NULL DEFAULT 7,
  `config_backup_s3_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_backup_s3_endpoint` varchar(255) DEFAULT NULL,
  `config_backup_s3_region` varchar(100) NOT NULL DEFAULT 'us-east-1',
  `config_backup_s3_bucket` varchar(255) DEFAULT NULL,
  `config_backup_s3_access_key` varchar(255) DEFAULT NULL,
  `config_backup_s3_secret_key` text DEFAULT NULL,
  `config_backup_s3_path_style` tinyint(1) NOT NULL DEFAULT 1,
  `config_backup_s3_prefix` varchar(255) DEFAULT NULL,
  `config_backup_passphrase` text DEFAULT NULL,
  `config_module_enable_ticketing` tinyint(1) NOT NULL DEFAULT 1,
  `config_theme` varchar(200) DEFAULT 'blue',
  `config_telemetry` tinyint(1) DEFAULT 0,
  `config_timezone` varchar(200) NOT NULL DEFAULT 'America/New_York',
  `config_phone_default_country_code` varchar(10) NOT NULL DEFAULT '1',
  `config_whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_destructive_deletes_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_whitelabel_enabled` int(11) NOT NULL DEFAULT 0,
  `config_whitelabel_key` text DEFAULT NULL,
  `config_ticket_default_view` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_ordering` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_moving_columns` tinyint(1) NOT NULL DEFAULT 1,
  `config_comet_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_comet_server_url` varchar(500) NOT NULL DEFAULT 'http://10.1.0.35:8060',
  `config_comet_admin_user` varchar(200) NOT NULL DEFAULT '',
  `config_comet_admin_pass` varchar(200) NOT NULL DEFAULT '',
  `config_comet_auto_ticket` tinyint(1) NOT NULL DEFAULT 0,
  `config_comet_totp_secret` varchar(200) NOT NULL DEFAULT '',
  `config_comet_webhook_secret` varchar(200) NOT NULL DEFAULT '',
  `config_module_enable_rmm` tinyint(1) NOT NULL DEFAULT 0,
  `config_rmm_default_integration_id` int(11) DEFAULT NULL,
  `config_rmm_auto_ticket_severities` varchar(100) NOT NULL DEFAULT '',
  `config_module_enable_ticket_charges` tinyint(1) NOT NULL DEFAULT 1,
  `config_module_enable_kb` tinyint(1) NOT NULL DEFAULT 0,
  `config_module_enable_live_chat` tinyint(1) NOT NULL DEFAULT 0,
  `config_vault_canonical_key` varchar(255) DEFAULT NULL,
  `config_vault_canonical_key_set_at` datetime DEFAULT NULL,
  `config_module_enable_unifi` tinyint(1) NOT NULL DEFAULT 0,
  `config_unifi_default_integration_id` int(11) DEFAULT NULL,
  `config_push_enabled_types` text DEFAULT NULL,
  `config_ai_enable` tinyint(1) NOT NULL DEFAULT 1,
  `config_ai_max_input_chars` int(11) NOT NULL DEFAULT 12000,
  `config_ai_timeout_seconds` int(11) NOT NULL DEFAULT 25,
  `config_theme_accent_custom` varchar(7) DEFAULT NULL,
  `config_theme_card_radius` varchar(8) DEFAULT NULL,
  `config_theme_dark_default` tinyint(1) NOT NULL DEFAULT 0,
  `config_rmm_auto_close_on_clear` tinyint(1) NOT NULL DEFAULT 1,
  `config_rmm_prefer_tactical` tinyint(1) NOT NULL DEFAULT 1,
  `config_payroll_overtime_threshold_hours` decimal(6,2) NOT NULL DEFAULT 40.00,
  `config_payroll_overtime_multiplier` decimal(4,2) NOT NULL DEFAULT 1.50,
  `config_payroll_default_pay_frequency` varchar(20) NOT NULL DEFAULT 'biweekly',
  `config_module_enable_payroll` tinyint(1) NOT NULL DEFAULT 0,
  `config_module_enable_crm` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_default_technician_id` int(11) DEFAULT NULL,
  `config_ticket_default_category_id` int(11) NOT NULL DEFAULT 0,
  `config_ticket_default_status_id` int(11) NOT NULL DEFAULT 0,
  `config_avg_resolution_exclude_projects` tinyint(1) NOT NULL DEFAULT 1,
  `config_dashboard_avg_resolution_enable` tinyint(1) NOT NULL DEFAULT 1,
  `config_module_enable_intune` tinyint(1) NOT NULL DEFAULT 0,
  `config_portal_home_sections` varchar(120) NOT NULL DEFAULT 'requests,approvals,devices,onboarding,training,catalog',
  `config_portal_onboarding_requests` tinyint(1) NOT NULL DEFAULT 0,
  `config_portal_onboarding_template_id` int(11) NOT NULL DEFAULT 0,
  `config_entra_allow_writes` tinyint(1) NOT NULL DEFAULT 0,
  `config_slack_link_by_email` tinyint(1) NOT NULL DEFAULT 0,
  `config_slack_bot_token` text DEFAULT NULL,
  `config_slack_team_id` varchar(32) NOT NULL DEFAULT '',
  `config_webhook_allowed_networks` varchar(500) NOT NULL DEFAULT '',
  `config_enable_device_metrics` tinyint(1) NOT NULL DEFAULT 0,
  `config_metrics_collect_interval_seconds` int(11) NOT NULL DEFAULT 300,
  `config_metrics_raw_retention_days` int(11) NOT NULL DEFAULT 14,
  `config_metrics_hour_retention_days` int(11) NOT NULL DEFAULT 90,
  `config_kb_media_key` varchar(300) DEFAULT NULL,
  `config_module_enable_training` tinyint(1) NOT NULL DEFAULT 0,
  `config_training_languages` varchar(40) NOT NULL DEFAULT 'en,es',
  `config_training_default_pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 80,
  `config_training_default_max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `config_training_attestation_text` text DEFAULT NULL,
  `config_training_video_max_mb` smallint(5) unsigned NOT NULL DEFAULT 95,
  `config_training_pdf_max_mb` smallint(5) unsigned NOT NULL DEFAULT 50,
  `config_training_pdf_max_pages` smallint(5) unsigned NOT NULL DEFAULT 150,
  `config_training_image_max_mb` smallint(5) unsigned NOT NULL DEFAULT 15,
  `config_training_file_max_mb` smallint(5) unsigned NOT NULL DEFAULT 50,
  `config_training_media_budget_mb` int(10) unsigned NOT NULL DEFAULT 1024,
  `config_training_youtube_api_key` text DEFAULT NULL,
  `config_training_ledger_verified_at_utc` datetime(3) DEFAULT NULL,
  `config_training_ledger_verify_result` varchar(255) DEFAULT NULL,
  `config_training_due_soon_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `config_training_reissue_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `config_training_reopen_window_days` smallint(5) unsigned NOT NULL DEFAULT 90,
  `config_training_evidence_max_mb` smallint(5) unsigned NOT NULL DEFAULT 20,
  `config_training_compliance_target_pct` tinyint(3) unsigned NOT NULL DEFAULT 95,
  `config_training_reconciled_at_utc` datetime(3) DEFAULT NULL,
  `config_training_snapshot_last_on` date DEFAULT NULL,
  `config_training_odoo_sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_training_odoo_sync_last_on` date DEFAULT NULL,
  `config_training_odoo_sync_last_result` varchar(255) DEFAULT NULL,
  `config_training_odoo_link_checked_at_utc` datetime(3) DEFAULT NULL,
  `config_training_odoo_target_sha` char(64) DEFAULT NULL,
  `config_training_hire_fill_since` date DEFAULT NULL,
  `config_training_kiosk_idle_s` smallint(5) unsigned NOT NULL DEFAULT 180,
  `config_training_trainer_idle_s` smallint(5) unsigned NOT NULL DEFAULT 300,
  `config_training_checkin_idle_s` smallint(5) unsigned NOT NULL DEFAULT 1200,
  `config_training_learner_max_minutes` smallint(5) unsigned NOT NULL DEFAULT 60,
  `config_training_trainer_max_minutes` smallint(5) unsigned NOT NULL DEFAULT 240,
  `config_training_pin_soft_failures` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `config_training_pin_lock_minutes` smallint(5) unsigned NOT NULL DEFAULT 15,
  `config_training_pin_hard_failures` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `config_training_kiosk_fail_cap` smallint(5) unsigned NOT NULL DEFAULT 15,
  `config_training_global_fail_cap` smallint(5) unsigned NOT NULL DEFAULT 40,
  `config_training_kiosk_fail_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 60,
  `config_training_global_fail_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 200,
  `config_training_kiosk_distinct_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 20,
  `config_training_kiosk_search_per_min` smallint(5) unsigned NOT NULL DEFAULT 60,
  `config_training_setup_code_days` tinyint(3) unsigned NOT NULL DEFAULT 7,
  `config_training_device_code_days` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `config_training_odoo_pin_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_training_pin_pause_until_utc` datetime(3) DEFAULT NULL,
  `config_training_enroll_pause_until_utc` datetime(3) DEFAULT NULL,
  `config_training_odoo_breaker_errors` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `config_training_odoo_breaker_until_utc` datetime(3) DEFAULT NULL,
  `config_training_pin_sources_synced_at_utc` datetime(3) DEFAULT NULL,
  `config_automation_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_proxy_hops` tinyint(3) unsigned DEFAULT NULL,
  `config_behind_cloudflare` tinyint(1) NOT NULL DEFAULT 0,
  `config_login_logo_bg` varchar(7) DEFAULT NULL,
  `config_module_enable_mcp` tinyint(1) NOT NULL DEFAULT 0,
  `config_mcp_issuer` varchar(255) NOT NULL DEFAULT '',
  `config_mcp_audience` varchar(255) NOT NULL DEFAULT '',
  `config_redis_host` varchar(255) NOT NULL DEFAULT '',
  `config_redis_port` int(11) NOT NULL DEFAULT 0,
  `config_redis_password` varchar(1000) NOT NULL DEFAULT '',
  `config_redis_db` int(11) NOT NULL DEFAULT 0,
  `config_redis_username` varchar(128) NOT NULL DEFAULT '',
  `config_redis_tls` tinyint(1) NOT NULL DEFAULT 0,
  `config_redis_tls_verify` tinyint(1) NOT NULL DEFAULT 1,
  `config_redis_tls_ca_file` text DEFAULT NULL,
  `config_redis_tls_cert_file` text DEFAULT NULL,
  `config_redis_tls_key_file` text DEFAULT NULL,
  `config_api_rate_limit` int(11) NOT NULL DEFAULT 300,
  `config_compliance_profile` varchar(20) NOT NULL DEFAULT 'none',
  `config_audit_retention_days` int(11) NOT NULL DEFAULT 365,
  `config_release_channel` varchar(12) NOT NULL DEFAULT 'production',
  `config_lifecycle_auto_start` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shared_items`
--

DROP TABLE IF EXISTS `shared_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shared_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_active` int(1) NOT NULL,
  `item_key` varchar(255) NOT NULL,
  `item_type` varchar(255) NOT NULL,
  `item_related_id` int(11) NOT NULL,
  `item_encrypted_username` varchar(255) DEFAULT NULL,
  `item_encrypted_credential` varchar(255) DEFAULT NULL,
  `item_note` varchar(255) DEFAULT NULL,
  `item_recipient` varchar(250) DEFAULT NULL,
  `item_views` int(11) NOT NULL,
  `item_view_limit` int(11) DEFAULT NULL,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_expire_at` datetime DEFAULT NULL,
  `item_client_id` int(11) NOT NULL,
  `item_encrypted_otp` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `idx_shared_items_client_active_created` (`item_client_id`,`item_active`,`item_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software`
--

DROP TABLE IF EXISTS `software`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software` (
  `software_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_name` varchar(200) NOT NULL,
  `software_description` text DEFAULT NULL,
  `software_version` varchar(200) DEFAULT NULL,
  `software_type` varchar(200) NOT NULL,
  `software_license_type` varchar(200) DEFAULT NULL,
  `software_key` varchar(200) DEFAULT NULL,
  `software_seats` int(11) DEFAULT NULL,
  `software_purchase_reference` varchar(200) DEFAULT NULL,
  `software_purchase` date DEFAULT NULL,
  `software_expire` date DEFAULT NULL,
  `software_notes` text DEFAULT NULL,
  `software_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `software_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `software_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `software_archived_at` datetime DEFAULT NULL,
  `software_accessed_at` datetime DEFAULT NULL,
  `software_vendor_id` int(11) DEFAULT 0,
  `software_client_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`),
  KEY `idx_software_client_archived_expire` (`software_client_id`,`software_archived_at`,`software_expire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
--
-- Table structure for table `software_assets`
--

DROP TABLE IF EXISTS `software_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_assets` (
  `software_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `software_assets_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_contacts`
--

DROP TABLE IF EXISTS `software_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_contacts` (
  `software_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `software_contacts_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_credentials`
--

DROP TABLE IF EXISTS `software_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_credentials` (
  `software_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `software_credentials_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_documents`
--

DROP TABLE IF EXISTS `software_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_documents` (
  `software_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `software_documents_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_files`
--

DROP TABLE IF EXISTS `software_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_files` (
  `software_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `software_files_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_key_asset_assignments`
--

DROP TABLE IF EXISTS `software_key_asset_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_key_asset_assignments` (
  `software_key_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `software_key_assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`software_key_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `software_key_asset_assignments_ibfk_1` FOREIGN KEY (`software_key_id`) REFERENCES `software_keys` (`software_key_id`) ON DELETE CASCADE,
  CONSTRAINT `software_key_asset_assignments_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_key_contact_assignments`
--

DROP TABLE IF EXISTS `software_key_contact_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_key_contact_assignments` (
  `software_key_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `software_key_assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`software_key_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `software_key_contact_assignments_ibfk_1` FOREIGN KEY (`software_key_id`) REFERENCES `software_keys` (`software_key_id`) ON DELETE CASCADE,
  CONSTRAINT `software_key_contact_assignments_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_keys`
--

DROP TABLE IF EXISTS `software_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_keys` (
  `software_key_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_key` varchar(400) NOT NULL,
  `software_key_software_id` int(11) NOT NULL,
  PRIMARY KEY (`software_key_id`),
  KEY `software_key_software_id` (`software_key_software_id`),
  CONSTRAINT `software_keys_ibfk_1` FOREIGN KEY (`software_key_software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_templates`
--

DROP TABLE IF EXISTS `software_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_templates` (
  `software_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_template_name` varchar(200) NOT NULL,
  `software_template_description` text DEFAULT NULL,
  `software_template_version` varchar(200) DEFAULT NULL,
  `software_template_type` varchar(200) NOT NULL,
  `software_template_license_type` varchar(200) DEFAULT NULL,
  `software_template_notes` text DEFAULT NULL,
  `software_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `software_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `software_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`software_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(200) NOT NULL,
  `tag_type` int(11) NOT NULL,
  `tag_color` varchar(200) DEFAULT NULL,
  `tag_icon` varchar(200) DEFAULT NULL,
  `tag_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tag_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tag_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_approvals`
--

DROP TABLE IF EXISTS `task_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `approval_scope` enum('client','internal') NOT NULL,
  `approval_type` enum('any','technical','billing','specific') NOT NULL,
  `approval_required_user_id` int(11) DEFAULT NULL,
  `approval_status` enum('pending','approved','declined') NOT NULL,
  `approval_created_by` int(11) NOT NULL,
  `approval_approved_by` varchar(255) DEFAULT NULL,
  `approval_url_key` varchar(200) NOT NULL,
  `approval_task_id` int(11) NOT NULL,
  PRIMARY KEY (`approval_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_templates`
--

DROP TABLE IF EXISTS `task_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_templates` (
  `task_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_template_name` varchar(200) NOT NULL,
  `task_template_order` int(11) NOT NULL DEFAULT 0,
  `task_template_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `task_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `task_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `task_template_archived_at` datetime DEFAULT NULL,
  `task_template_ticket_template_id` int(11) NOT NULL,
  PRIMARY KEY (`task_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_name` varchar(255) NOT NULL,
  `task_status` varchar(255) DEFAULT NULL,
  `task_order` int(11) NOT NULL DEFAULT 0,
  `task_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `task_completed_at` datetime DEFAULT NULL,
  `task_completed_by` int(11) DEFAULT NULL,
  `task_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `task_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `task_ticket_id` int(11) DEFAULT NULL,
  `task_created_ticket_id` int(11) DEFAULT NULL,
  `task_project_id` int(11) DEFAULT NULL,
  `task_milestone_id` int(11) DEFAULT NULL,
  `task_assigned_to` int(11) DEFAULT NULL,
  `task_start` date DEFAULT NULL,
  `task_due` date DEFAULT NULL,
  `task_progress` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`task_id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `taxes`
--

DROP TABLE IF EXISTS `taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taxes` (
  `tax_id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_name` varchar(200) NOT NULL,
  `tax_percent` float NOT NULL,
  `tax_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tax_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tax_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_assets`
--

DROP TABLE IF EXISTS `ticket_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_assets` (
  `ticket_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `ticket_assets_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_assets_ibfk_2` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_attachments`
--

DROP TABLE IF EXISTS `ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_attachments` (
  `ticket_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_attachment_name` varchar(255) NOT NULL,
  `ticket_attachment_reference_name` varchar(255) NOT NULL,
  `ticket_attachment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_attachment_ticket_id` int(11) NOT NULL,
  `ticket_attachment_reply_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`ticket_attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_automation_rules`
--

DROP TABLE IF EXISTS `ticket_automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_automation_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(100) NOT NULL,
  `rule_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `rule_trigger` varchar(30) NOT NULL DEFAULT 'schedule',
  `rule_run_once` tinyint(1) NOT NULL DEFAULT 0,
  `rule_cond_field` varchar(40) NOT NULL DEFAULT 'age_hours',
  `rule_cond_op` varchar(20) NOT NULL DEFAULT 'greater_than',
  `rule_cond_value` varchar(255) NOT NULL DEFAULT '',
  `rule_conditions_json` longtext DEFAULT NULL,
  `rule_action` varchar(40) NOT NULL,
  `rule_action_value` varchar(255) NOT NULL DEFAULT '',
  `rule_actions_json` longtext DEFAULT NULL,
  `rule_order` int(11) NOT NULL DEFAULT 0,
  `rule_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_automation_runs`
--

DROP TABLE IF EXISTS `ticket_automation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_automation_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `rule_name` varchar(100) DEFAULT NULL,
  `trigger_type` varchar(30) NOT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rule_id` (`rule_id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_charges`
--

DROP TABLE IF EXISTS `ticket_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_charges` (
  `charge_id` int(11) NOT NULL AUTO_INCREMENT,
  `charge_ticket_id` int(11) NOT NULL,
  `charge_product_id` int(11) NOT NULL DEFAULT 0,
  `charge_labor_type_id` int(11) NOT NULL DEFAULT 0,
  `charge_name` varchar(200) NOT NULL,
  `charge_description` text DEFAULT NULL,
  `charge_quantity` decimal(15,2) NOT NULL DEFAULT 1.00,
  `charge_unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `charge_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `charge_tax_id` int(11) NOT NULL DEFAULT 0,
  `charge_invoiced_at` datetime DEFAULT NULL,
  `charge_created_by` int(11) NOT NULL DEFAULT 0,
  `charge_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `charge_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`charge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_chat_messages`
--

DROP TABLE IF EXISTS `ticket_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `sender_type` enum('agent','contact') NOT NULL,
  `sender_id` int(11) NOT NULL DEFAULT 0,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `ticket_id_id` (`ticket_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_outtake_forms`
--

DROP TABLE IF EXISTS `ticket_outtake_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_outtake_forms` (
  `outtake_id` int(11) NOT NULL AUTO_INCREMENT,
  `outtake_ticket_id` int(11) NOT NULL,
  `outtake_tech_notes` text DEFAULT NULL,
  `outtake_created_by` int(11) NOT NULL DEFAULT 0,
  `outtake_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `outtake_sign_token` varchar(64) DEFAULT NULL,
  `outtake_signed_name` varchar(200) DEFAULT NULL,
  `outtake_signed_at` datetime DEFAULT NULL,
  `outtake_signature` longtext DEFAULT NULL,
  PRIMARY KEY (`outtake_id`),
  KEY `outtake_ticket_id` (`outtake_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_saved_views`
--

DROP TABLE IF EXISTS `ticket_saved_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_saved_views` (
  `ticket_saved_view_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_saved_view_name` varchar(100) NOT NULL,
  `ticket_saved_view_icon` varchar(50) NOT NULL DEFAULT 'fa-filter',
  `ticket_saved_view_query` text NOT NULL,
  `ticket_saved_view_user_id` int(11) NOT NULL DEFAULT 0,
  `ticket_saved_view_order` int(11) NOT NULL DEFAULT 0,
  `ticket_saved_view_created_at` datetime DEFAULT current_timestamp(),
  `ticket_saved_view_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_saved_view_id`),
  KEY `ticket_saved_view_user_id` (`ticket_saved_view_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `ticket_saved_views`
  (`ticket_saved_view_name`, `ticket_saved_view_icon`, `ticket_saved_view_query`, `ticket_saved_view_order`) VALUES
  ('Default', 'fa-list', '', 0),
  ('On-Site', 'fa-truck', 'onsite=1&status=Open', 1),
  ('Assigned to me', 'fa-user', 'assigned=me&status=Open', 2),
  ('All Unresolved', 'fa-folder-open', 'status=Open', 3),
  ('Remote', 'fa-headset', 'onsite=0&status=Open', 5);

--
-- Table structure for table `kb_categories`
--

DROP TABLE IF EXISTS `kb_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_categories` (
  `kb_category_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_category_name` varchar(100) NOT NULL,
  `kb_category_parent_id` int(11) NOT NULL DEFAULT 0,
  `kb_category_client_id` int(11) NOT NULL DEFAULT 0,
  `kb_category_order` int(11) NOT NULL DEFAULT 0,
  `kb_category_created_at` datetime DEFAULT current_timestamp(),
  `kb_category_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`kb_category_id`),
  KEY `kb_category_client_id` (`kb_category_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kb_article_attachments`
--

DROP TABLE IF EXISTS `kb_article_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_article_attachments` (
  `kb_article_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_attachment_name` varchar(255) NOT NULL,
  `kb_article_attachment_reference_name` varchar(255) NOT NULL,
  `kb_article_attachment_kb_article_id` int(11) NOT NULL,
  `kb_article_attachment_created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`kb_article_attachment_id`),
  KEY `kb_article_attachment_kb_article_id` (`kb_article_attachment_kb_article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_history`
--

DROP TABLE IF EXISTS `ticket_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_history` (
  `ticket_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_history_status` varchar(200) NOT NULL,
  `ticket_history_description` varchar(255) NOT NULL,
  `ticket_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_history_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_replies`
--

DROP TABLE IF EXISTS `ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_replies` (
  `ticket_reply_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_reply` longtext NOT NULL,
  `ticket_reply_type` varchar(20) NOT NULL,
  `ticket_reply_time_worked` time DEFAULT NULL,
  `ticket_reply_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_reply_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_reply_archived_at` datetime DEFAULT NULL,
  `ticket_reply_by` int(11) NOT NULL,
  `ticket_reply_ticket_id` int(11) NOT NULL,
  `ticket_reply_onsite` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_reply_labor_type_id` int(11) DEFAULT NULL,
  `ticket_reply_emailed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_reply_id`),
  KEY `idx_ticket_replies_ticket_archived` (`ticket_reply_ticket_id`,`ticket_reply_archived_at`)
) ENGINE=InnoDB AUTO_INCREMENT=396 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_statuses`
--

DROP TABLE IF EXISTS `ticket_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_statuses` (
  `ticket_status_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_status_name` varchar(200) NOT NULL,
  `ticket_status_color` varchar(200) NOT NULL,
  `ticket_status_active` tinyint(1) NOT NULL DEFAULT 1,
  `ticket_status_order` int(11) NOT NULL DEFAULT 0,
  `ticket_status_pauses_sla` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_status_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_tags`
--

DROP TABLE IF EXISTS `ticket_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_tags` (
  `ticket_tag_ticket_id` int(11) NOT NULL,
  `ticket_tag_tag_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_tag_ticket_id`,`ticket_tag_tag_id`),
  KEY `fk_ticket_tag_tag` (`ticket_tag_tag_id`),
  CONSTRAINT `fk_ticket_tag_tag` FOREIGN KEY (`ticket_tag_tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_tag_ticket` FOREIGN KEY (`ticket_tag_ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_templates`
--

DROP TABLE IF EXISTS `ticket_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_templates` (
  `ticket_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_template_name` varchar(200) NOT NULL,
  `ticket_template_description` text DEFAULT NULL,
  `ticket_template_subject` varchar(500) DEFAULT NULL,
  `ticket_template_details` longtext DEFAULT NULL,
  `ticket_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_views`
--

DROP TABLE IF EXISTS `ticket_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_views` (
  `view_id` int(11) NOT NULL AUTO_INCREMENT,
  `view_ticket_id` int(11) NOT NULL,
  `view_user_id` int(11) NOT NULL,
  `view_timestamp` datetime NOT NULL,
  PRIMARY KEY (`view_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_watchers`
--

DROP TABLE IF EXISTS `ticket_watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_watchers` (
  `watcher_id` int(11) NOT NULL AUTO_INCREMENT,
  `watcher_name` varchar(255) DEFAULT NULL,
  `watcher_email` varchar(255) NOT NULL,
  `watcher_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`watcher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_worksheet_responses`
--

DROP TABLE IF EXISTS `ticket_worksheet_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_worksheet_responses` (
  `response_id` int(11) NOT NULL AUTO_INCREMENT,
  `response_worksheet_id` int(11) NOT NULL,
  `response_field_id` int(11) NOT NULL,
  `response_value` text DEFAULT NULL,
  PRIMARY KEY (`response_id`),
  KEY `response_worksheet_id` (`response_worksheet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `ticket_worksheets`
--

DROP TABLE IF EXISTS `ticket_worksheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_worksheets` (
  `worksheet_id` int(11) NOT NULL AUTO_INCREMENT,
  `worksheet_ticket_id` int(11) NOT NULL,
  `worksheet_template_id` int(11) DEFAULT NULL,
  `worksheet_created_by` int(11) NOT NULL DEFAULT 0,
  `worksheet_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `worksheet_completed_at` datetime DEFAULT NULL,
  `worksheet_signature` longtext DEFAULT NULL,
  `worksheet_signed_name` varchar(200) DEFAULT NULL,
  `worksheet_signed_at` datetime DEFAULT NULL,
  `worksheet_sign_token` varchar(64) DEFAULT NULL,
  `worksheet_is_outtake` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`worksheet_id`),
  KEY `worksheet_ticket_id` (`worksheet_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_prefix` varchar(200) DEFAULT NULL,
  `ticket_number` int(11) NOT NULL,
  `ticket_source` varchar(255) DEFAULT NULL COMMENT 'Where the Ticket Came from\r\nEmail, Client Portal, In-App, Project Template',
  `ticket_mailbox_id` int(11) DEFAULT NULL,
  `ticket_category` varchar(200) DEFAULT NULL,
  `ticket_subject` varchar(500) NOT NULL,
  `ticket_details` longtext NOT NULL,
  `ticket_priority` varchar(200) DEFAULT NULL,
  `ticket_status` int(11) NOT NULL,
  `ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_schedule` datetime DEFAULT NULL,
  `ticket_schedule_end` datetime DEFAULT NULL,
  `ticket_appointment_notes` text DEFAULT NULL,
  `ticket_onsite` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_vendor_ticket_number` varchar(255) DEFAULT NULL,
  `ticket_feedback` varchar(200) DEFAULT NULL,
  `ticket_csat_rating` tinyint(4) DEFAULT NULL COMMENT '1-5 CSAT star rating, NULL = not yet rated',
  `ticket_csat_comment` text DEFAULT NULL,
  `ticket_csat_rated_at` datetime DEFAULT NULL,
  `ticket_csat_public_approved` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_csat_reminded_at` datetime DEFAULT NULL,
  `ticket_url_key` varchar(200) DEFAULT NULL,
  `ticket_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_due_at` datetime DEFAULT NULL,
  `ticket_resolved_at` datetime DEFAULT NULL,
  `ticket_archived_at` datetime DEFAULT NULL,
  `ticket_first_response_at` datetime DEFAULT NULL,
  `ticket_closed_at` datetime DEFAULT NULL,
  `ticket_reopen_at` datetime DEFAULT NULL,
  `ticket_created_by` int(11) NOT NULL,
  `ticket_assigned_to` int(11) NOT NULL DEFAULT 0,
  `ticket_closed_by` int(11) NOT NULL DEFAULT 0,
  `ticket_merged_into_id` int(11) DEFAULT NULL,
  `ticket_vendor_id` int(11) NOT NULL DEFAULT 0,
  `ticket_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_contact_id` int(11) NOT NULL DEFAULT 0,
  `ticket_location_id` int(11) NOT NULL DEFAULT 0,
  `ticket_asset_id` int(11) NOT NULL DEFAULT 0,
  `ticket_quote_id` int(11) NOT NULL DEFAULT 0,
  `ticket_invoice_id` int(11) NOT NULL DEFAULT 0,
  `ticket_project_id` int(11) NOT NULL DEFAULT 0,
  `ticket_recurring_ticket_id` int(11) DEFAULT 0,
  `ticket_order` int(11) NOT NULL DEFAULT 0,
  `ticket_contract_id` int(11) DEFAULT NULL,
  `ticket_problem_id` int(11) DEFAULT NULL,
  `ticket_sla_response_due` datetime DEFAULT NULL,
  `ticket_sla_resolution_due` datetime DEFAULT NULL,
  `ticket_outlook_event_id` varchar(255) DEFAULT NULL,
  `ticket_initial_issue_reply_id` int(11) DEFAULT NULL,
  `ticket_sla_policy_id` int(11) DEFAULT NULL,
  `ticket_sla_paused_seconds` int(11) NOT NULL DEFAULT 0,
  `ticket_sla_paused_at` datetime DEFAULT NULL,
  `ticket_sla_response_met` tinyint(4) DEFAULT NULL,
  `ticket_sla_resolution_met` tinyint(4) DEFAULT NULL,
  `ticket_delivery_method` varchar(20) DEFAULT NULL,
  `ticket_catalog_item_id` int(11) DEFAULT NULL,
  `ticket_resolution_started_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  KEY `idx_tickets_client_archived_updated` (`ticket_client_id`,`ticket_archived_at`,`ticket_updated_at`),
  KEY `idx_tickets_problem` (`ticket_problem_id`),
  KEY `idx_tickets_vacation_return` (`ticket_closed_at`,`ticket_contact_id`),
  KEY `idx_tickets_catalog_item` (`ticket_catalog_item_id`,`ticket_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_achievement_awards`
--

DROP TABLE IF EXISTS `training_achievement_awards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_achievement_awards` (
  `taward_id` int(11) NOT NULL AUTO_INCREMENT,
  `taward_achievement_id` int(11) NOT NULL,
  `taward_achievement_uid` char(12) NOT NULL,
  `taward_contact_id` int(11) NOT NULL,
  `taward_rule_type` varchar(40) NOT NULL,
  `taward_scope_key` varchar(64) NOT NULL DEFAULT '',
  `taward_source` enum('rule','manual') NOT NULL,
  `taward_evidence_json` varchar(1000) DEFAULT NULL,
  `taward_reason` varchar(500) DEFAULT NULL,
  `taward_awarded_by_user_id` int(11) DEFAULT NULL,
  `taward_awarded_by_contact_id` int(11) DEFAULT NULL,
  `taward_snap_name` varchar(100) NOT NULL,
  `taward_snap_icon` varchar(40) NOT NULL,
  `taward_snap_color` char(7) NOT NULL,
  `taward_awarded_at_utc` datetime(3) NOT NULL,
  `taward_kiosk_id` int(11) DEFAULT NULL,
  `taward_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `taward_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`taward_id`),
  UNIQUE KEY `uq_training_taward` (`taward_achievement_id`,`taward_contact_id`,`taward_scope_key`),
  KEY `idx_training_taward_contact` (`taward_contact_id`,`taward_awarded_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_achievements`
--

DROP TABLE IF EXISTS `training_achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_achievements` (
  `achievement_id` int(11) NOT NULL AUTO_INCREMENT,
  `achievement_uid` char(12) NOT NULL,
  `achievement_name` varchar(100) NOT NULL,
  `achievement_description` varchar(500) DEFAULT NULL,
  `achievement_icon` varchar(40) NOT NULL DEFAULT 'award',
  `achievement_color` char(7) NOT NULL DEFAULT '#D97706',
  `achievement_rule_type` enum('manual','course_completed','category_completed','path_completed','perfect_score','first_attempt_pass','on_time_streak','courses_completed_count') NOT NULL DEFAULT 'manual',
  `achievement_rule_json` text DEFAULT NULL,
  `achievement_active` tinyint(1) NOT NULL DEFAULT 1,
  `achievement_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `achievement_version` int(10) unsigned NOT NULL DEFAULT 0,
  `achievement_created_by` int(11) NOT NULL,
  `achievement_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `achievement_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `achievement_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`achievement_id`),
  UNIQUE KEY `uq_training_achievement_uid` (`achievement_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_assignments`
--

DROP TABLE IF EXISTS `training_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_assignments` (
  `tassign_id` int(11) NOT NULL AUTO_INCREMENT,
  `tassign_contact_id` int(11) NOT NULL,
  `tassign_course_id` int(11) NOT NULL,
  `tassign_reason` enum('requirement','renewal','retrain','reissue') NOT NULL,
  `tassign_anchor` varchar(64) NOT NULL,
  `tassign_requirement_id` int(11) DEFAULT NULL,
  `tassign_required` tinyint(1) NOT NULL DEFAULT 1,
  `tassign_due_on` date NOT NULL,
  `tassign_original_due_on` date NOT NULL,
  `tassign_onboarding_from_on` date DEFAULT NULL,
  `tassign_status` enum('open','completed','waived','cancelled') NOT NULL DEFAULT 'open',
  `tassign_open_guard` tinyint(1) DEFAULT 1,
  `tassign_waived_until` date DEFAULT NULL,
  `tassign_completion_id` int(11) DEFAULT NULL,
  `tassign_created_at_utc` datetime(3) NOT NULL,
  `tassign_created_by` int(11) DEFAULT NULL,
  `tassign_closed_at_utc` datetime(3) DEFAULT NULL,
  `tassign_closed_by_user_id` int(11) DEFAULT NULL,
  `tassign_close_reason` varchar(40) DEFAULT NULL,
  `tassign_close_note` varchar(500) DEFAULT NULL,
  `tassign_reopened_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `tassign_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tassign_id`),
  UNIQUE KEY `uq_training_assign_open` (`tassign_contact_id`,`tassign_course_id`,`tassign_open_guard`),
  KEY `idx_training_assign_status_due` (`tassign_status`,`tassign_due_on`),
  KEY `idx_training_assign_course` (`tassign_course_id`,`tassign_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_attempt_answer_log`
--

DROP TABLE IF EXISTS `training_attempt_answer_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_attempt_answer_log` (
  `talog_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `talog_attempt_id` int(11) NOT NULL,
  `talog_question_uid` char(12) NOT NULL,
  `talog_selected` varchar(255) NOT NULL DEFAULT '',
  `talog_saved_at_utc` datetime(3) NOT NULL,
  `talog_ksess_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`talog_id`),
  KEY `idx_training_talog_attempt` (`talog_attempt_id`,`talog_question_uid`,`talog_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_attempt_answers`
--

DROP TABLE IF EXISTS `training_attempt_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_attempt_answers` (
  `tanswer_attempt_id` int(11) NOT NULL,
  `tanswer_question_uid` char(12) NOT NULL,
  `tanswer_position` smallint(5) unsigned NOT NULL,
  `tanswer_type` enum('single','multi','truefalse') NOT NULL,
  `tanswer_presented` varchar(255) NOT NULL,
  `tanswer_selected` varchar(255) NOT NULL DEFAULT '',
  `tanswer_is_correct` tinyint(1) NOT NULL,
  `tanswer_points_awarded` tinyint(3) unsigned NOT NULL,
  `tanswer_points_possible` tinyint(3) unsigned NOT NULL,
  `tanswer_critical` tinyint(1) NOT NULL,
  PRIMARY KEY (`tanswer_attempt_id`,`tanswer_question_uid`),
  KEY `idx_training_tanswer_question` (`tanswer_question_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_attempt_results`
--

DROP TABLE IF EXISTS `training_attempt_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_attempt_results` (
  `tresult_attempt_id` int(11) NOT NULL,
  `tresult_submitted_at_utc` datetime(3) NOT NULL,
  `tresult_points_earned` smallint(5) unsigned NOT NULL,
  `tresult_points_possible` smallint(5) unsigned NOT NULL,
  `tresult_score_pct` decimal(5,2) NOT NULL,
  `tresult_pass_mark_pct` tinyint(3) unsigned NOT NULL,
  `tresult_critical_missed` tinyint(3) unsigned NOT NULL,
  `tresult_passed` tinyint(1) NOT NULL,
  `tresult_timed_out` tinyint(1) NOT NULL DEFAULT 0,
  `tresult_duration_seconds` int(10) unsigned NOT NULL,
  `tresult_rapid_flag` tinyint(1) NOT NULL DEFAULT 0,
  `tresult_finalized_by` enum('learner','finalizer') NOT NULL DEFAULT 'learner',
  `tresult_answers_sha256` char(64) NOT NULL,
  `tresult_log_sha256` char(64) NOT NULL,
  `tresult_log_max_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tresult_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tresult_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`tresult_attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_attempts`
--

DROP TABLE IF EXISTS `training_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_attempts` (
  `tattempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `tattempt_run_id` int(11) NOT NULL,
  `tattempt_contact_id` int(11) NOT NULL,
  `tattempt_course_id` int(11) NOT NULL,
  `tattempt_revision_id` int(11) NOT NULL,
  `tattempt_lesson_uid` char(12) NOT NULL,
  `tattempt_quiz_uid` char(12) NOT NULL,
  `tattempt_kind` enum('standalone','exam','check') NOT NULL,
  `tattempt_number` smallint(5) unsigned NOT NULL,
  `tattempt_language` varchar(10) NOT NULL,
  `tattempt_draw_json` text NOT NULL,
  `tattempt_pass_mark_pct` tinyint(3) unsigned NOT NULL,
  `tattempt_time_limit_s` smallint(5) unsigned DEFAULT NULL,
  `tattempt_started_at_utc` datetime(3) NOT NULL,
  `tattempt_deadline_utc` datetime(3) DEFAULT NULL,
  `tattempt_ksess_id` int(11) DEFAULT NULL,
  `tattempt_kiosk_id` int(11) DEFAULT NULL,
  `tattempt_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tattempt_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`tattempt_id`),
  UNIQUE KEY `uq_training_attempt` (`tattempt_run_id`,`tattempt_lesson_uid`,`tattempt_number`),
  KEY `idx_training_attempt_contact` (`tattempt_contact_id`,`tattempt_course_id`),
  KEY `idx_training_attempt_deadline` (`tattempt_deadline_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_automation`
--

DROP TABLE IF EXISTS `training_automation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_automation` (
  `tauto_id` tinyint(3) unsigned NOT NULL,
  `tauto_odoo_push_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `tauto_odoo_mode` enum('resume','skill','note') NOT NULL DEFAULT 'resume',
  `tauto_odoo_send_resume` tinyint(1) NOT NULL DEFAULT 1,
  `tauto_odoo_send_skill` tinyint(1) NOT NULL DEFAULT 0,
  `tauto_odoo_send_note` tinyint(1) NOT NULL DEFAULT 0,
  `tauto_odoo_resume_type_id` int(11) DEFAULT NULL,
  `tauto_odoo_award_type_id` int(11) DEFAULT NULL,
  `tauto_odoo_skill_type_id` int(11) DEFAULT NULL,
  `tauto_odoo_skill_level_id` int(11) DEFAULT NULL,
  `tauto_odoo_skill_label` varchar(255) DEFAULT NULL,
  `tauto_odoo_push_awards` tinyint(1) NOT NULL DEFAULT 0,
  `tauto_odoo_push_since` date DEFAULT NULL,
  `tauto_odoo_target_key` char(16) DEFAULT NULL,
  `tauto_odoo_target_confirmed_at_utc` datetime(3) DEFAULT NULL,
  `tauto_odoo_discovery_json` mediumtext DEFAULT NULL,
  `tauto_odoo_discovered_at_utc` datetime(3) DEFAULT NULL,
  `tauto_odoo_key_expires_on` date DEFAULT NULL,
  `tauto_odoo_paused_reason` varchar(255) DEFAULT NULL,
  `tauto_odoo_last_run_at_utc` datetime(3) DEFAULT NULL,
  `tauto_odoo_last_result` varchar(255) DEFAULT NULL,
  `tauto_reminders_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `tauto_reminder_weekdays` varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
  `tauto_escalate_after_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `tauto_video_recheck_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `tauto_verify_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `tauto_cert_signer_name` varchar(200) DEFAULT NULL,
  `tauto_cert_signer_title` varchar(200) DEFAULT NULL,
  `tauto_cert_signer_png` mediumtext DEFAULT NULL,
  `tauto_daily_last_run_on` date DEFAULT NULL,
  `tauto_daily_last_result` varchar(255) DEFAULT NULL,
  `tauto_version` int(10) unsigned NOT NULL DEFAULT 0,
  `tauto_updated_by` int(11) DEFAULT NULL,
  `tauto_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tauto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT IGNORE INTO `training_automation` (`tauto_id`) VALUES (1);

--
-- Table structure for table `training_categories`
--

DROP TABLE IF EXISTS `training_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_categories` (
  `tcat_id` int(11) NOT NULL AUTO_INCREMENT,
  `tcat_name` varchar(100) NOT NULL,
  `tcat_color` char(7) NOT NULL DEFAULT '#0D9488',
  `tcat_icon` varchar(40) NOT NULL DEFAULT 'graduation-cap',
  `tcat_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `tcat_created_by` int(11) NOT NULL DEFAULT 0,
  `tcat_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tcat_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tcat_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tcat_id`),
  UNIQUE KEY `uq_training_tcat_name` (`tcat_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT IGNORE INTO `training_categories` (`tcat_name`, `tcat_color`, `tcat_icon`, `tcat_sort`, `tcat_created_by`) VALUES ('Safety','#DC2626','hard-hat',1,0), ('Equipment','#D97706','truck-loading',2,0), ('Quality','#2563EB','check-double',3,0), ('HR & Policy','#7C3AED','user-shield',4,0), ('IT','#0891B2','laptop',5,0), ('Other','#475569','graduation-cap',6,0);

--
-- Table structure for table `training_cert_counters`
--

DROP TABLE IF EXISTS `training_cert_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_cert_counters` (
  `certctr_year` smallint(5) unsigned NOT NULL,
  `certctr_last_seq` int(10) unsigned NOT NULL DEFAULT 0,
  `certctr_updated_at_utc` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`certctr_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT IGNORE INTO `training_cert_counters` (`certctr_year`, `certctr_last_seq`, `certctr_updated_at_utc`) VALUES (0, 0, NULL);

--
-- Table structure for table `training_cert_tokens`
--

DROP TABLE IF EXISTS `training_cert_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_cert_tokens` (
  `certtok_id` int(11) NOT NULL AUTO_INCREMENT,
  `certtok_completion_id` int(11) NOT NULL,
  `certtok_nonce` char(32) NOT NULL,
  `certtok_token_sha256` char(64) NOT NULL,
  `certtok_created_at_utc` datetime(3) NOT NULL,
  `certtok_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `certtok_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`certtok_id`),
  UNIQUE KEY `uq_training_certtok_completion` (`certtok_completion_id`),
  UNIQUE KEY `uq_training_certtok_token` (`certtok_token_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_completion_voids`
--

DROP TABLE IF EXISTS `training_completion_voids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_completion_voids` (
  `cvoid_id` int(11) NOT NULL AUTO_INCREMENT,
  `cvoid_completion_id` int(11) NOT NULL,
  `cvoid_reason` varchar(1000) NOT NULL,
  `cvoid_by_user_id` int(11) NOT NULL,
  `cvoid_at_utc` datetime(3) NOT NULL,
  `cvoid_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `cvoid_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`cvoid_id`),
  UNIQUE KEY `uq_training_cvoid` (`cvoid_completion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_completions`
--

DROP TABLE IF EXISTS `training_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_completions` (
  `completion_id` int(11) NOT NULL AUTO_INCREMENT,
  `completion_contact_id` int(11) NOT NULL,
  `completion_course_id` int(11) NOT NULL,
  `completion_course_kind` enum('training','document') NOT NULL,
  `completion_revision_id` int(11) DEFAULT NULL,
  `completion_revision_sha256` char(64) DEFAULT NULL,
  `completion_assignment_id` int(11) DEFAULT NULL,
  `completion_source_key` varchar(48) NOT NULL,
  `completion_method` enum('online','session','blended','evaluation','external','legacy_paper') NOT NULL,
  `completion_proof` enum('self_pin_signature','evaluator_signed','self_pin','portal_login','trainer_attested','document','agent_recorded') NOT NULL,
  `completion_completed_on` date NOT NULL,
  `completion_trained_on` date DEFAULT NULL,
  `completion_evaluated_on` date DEFAULT NULL,
  `completion_expires_on` date DEFAULT NULL,
  `completion_language` varchar(10) NOT NULL DEFAULT 'en',
  `completion_score_pct` decimal(5,2) DEFAULT NULL,
  `completion_pass_mark_pct` tinyint(3) unsigned DEFAULT NULL,
  `completion_attempts_used` tinyint(3) unsigned DEFAULT NULL,
  `completion_duration_minutes` smallint(5) unsigned DEFAULT NULL,
  `completion_run_id` int(11) DEFAULT NULL,
  `completion_attempt_id` int(11) DEFAULT NULL,
  `completion_tsession_id` int(11) DEFAULT NULL,
  `completion_tattendee_id` int(11) DEFAULT NULL,
  `completion_evaluation_id` int(11) DEFAULT NULL,
  `completion_trainer_contact_id` int(11) DEFAULT NULL,
  `completion_trainer_user_id` int(11) DEFAULT NULL,
  `completion_trainer_name` varchar(200) DEFAULT NULL,
  `completion_evaluator_name` varchar(200) DEFAULT NULL,
  `completion_learner_tsig_id` int(11) DEFAULT NULL,
  `completion_trainer_tsig_id` int(11) DEFAULT NULL,
  `completion_kiosk_id` int(11) DEFAULT NULL,
  `completion_asset_id` int(11) DEFAULT NULL,
  `completion_pin_source` enum('odoo','local') DEFAULT NULL,
  `completion_odoo_employee_id` int(11) DEFAULT NULL,
  `completion_external_issuer` varchar(200) DEFAULT NULL,
  `completion_external_ref` varchar(100) DEFAULT NULL,
  `completion_evidence_media_id` int(11) DEFAULT NULL,
  `completion_recorded_by_user_id` int(11) DEFAULT NULL,
  `completion_attestation_text` text DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `completion_cert_number` varchar(20) DEFAULT NULL,
  `completion_snap_contact_name` varchar(200) NOT NULL,
  `completion_snap_contact_title` varchar(200) DEFAULT NULL,
  `completion_snap_client_id` int(11) NOT NULL DEFAULT 0,
  `completion_snap_client_name` varchar(200) DEFAULT NULL,
  `completion_snap_course_name` varchar(200) NOT NULL,
  `completion_snap_course_code` varchar(40) DEFAULT NULL,
  `completion_snap_revision_number` smallint(5) unsigned DEFAULT NULL,
  `completion_snap_regulation_ref` varchar(100) DEFAULT NULL,
  `completion_supersedes_id` int(11) DEFAULT NULL,
  `completion_recorded_at_utc` datetime(3) NOT NULL,
  `completion_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `completion_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`completion_id`),
  UNIQUE KEY `uq_training_completion_source` (`completion_source_key`),
  UNIQUE KEY `uq_training_completion_cert` (`completion_cert_number`),
  KEY `idx_training_completion_contact` (`completion_contact_id`,`completion_course_id`,`completion_completed_on`),
  KEY `idx_training_completion_course_exp` (`completion_course_id`,`completion_expires_on`),
  KEY `idx_training_completion_exp` (`completion_expires_on`),
  KEY `idx_training_completion_recorded` (`completion_recorded_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_compliance_daily`
--

DROP TABLE IF EXISTS `training_compliance_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_compliance_daily` (
  `tdaily_date` date NOT NULL,
  `tdaily_client_id` int(11) NOT NULL,
  `tdaily_course_id` int(11) NOT NULL,
  `tdaily_eligible_people` int(11) NOT NULL DEFAULT 0,
  `tdaily_required_pairs` int(11) NOT NULL DEFAULT 0,
  `tdaily_current_pairs` int(11) NOT NULL DEFAULT 0,
  `tdaily_overdue_pairs` int(11) NOT NULL DEFAULT 0,
  `tdaily_overdue_people` int(11) NOT NULL DEFAULT 0,
  `tdaily_expiring_30` int(11) NOT NULL DEFAULT 0,
  `tdaily_waived_pairs` int(11) NOT NULL DEFAULT 0,
  `tdaily_completions` int(11) NOT NULL DEFAULT 0,
  `tdaily_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`tdaily_date`,`tdaily_client_id`,`tdaily_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_course_prereqs`
--

DROP TABLE IF EXISTS `training_course_prereqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_course_prereqs` (
  `prereq_course_id` int(11) NOT NULL,
  `prereq_requires_course_id` int(11) NOT NULL,
  `prereq_created_by` int(11) NOT NULL,
  `prereq_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`prereq_course_id`,`prereq_requires_course_id`),
  KEY `idx_training_prereq_requires` (`prereq_requires_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_course_sections`
--

DROP TABLE IF EXISTS `training_course_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_course_sections` (
  `csection_id` int(11) NOT NULL AUTO_INCREMENT,
  `csection_uid` char(12) NOT NULL,
  `csection_course_id` int(11) NOT NULL,
  `csection_title` varchar(200) NOT NULL,
  `csection_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `csection_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `csection_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`csection_id`),
  UNIQUE KEY `uq_training_csection_uid` (`csection_uid`),
  KEY `idx_training_csection_course` (`csection_course_id`,`csection_sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_courses`
--

DROP TABLE IF EXISTS `training_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_courses` (
  `course_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_uid` char(12) NOT NULL,
  `course_kind` enum('training','document') NOT NULL DEFAULT 'training',
  `course_code` varchar(40) DEFAULT NULL,
  `course_name` varchar(200) NOT NULL,
  `course_summary` varchar(500) DEFAULT NULL,
  `course_description_html` mediumtext DEFAULT NULL,
  `course_category_id` int(11) DEFAULT NULL,
  `course_cover_media_id` int(11) DEFAULT NULL,
  `course_color` char(7) DEFAULT NULL,
  `course_default_language` varchar(10) NOT NULL DEFAULT 'en',
  `course_languages` varchar(40) NOT NULL DEFAULT 'en',
  `course_required_languages` varchar(40) NOT NULL DEFAULT 'en',
  `course_regulation_ref` varchar(100) DEFAULT NULL,
  `course_responsible_user_id` int(11) DEFAULT NULL,
  `course_sequential` tinyint(1) NOT NULL DEFAULT 1,
  `course_est_minutes` smallint(5) unsigned DEFAULT NULL,
  `course_validity_months` smallint(5) unsigned DEFAULT NULL,
  `course_renewal_lead_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `course_requires_signature` tinyint(1) NOT NULL DEFAULT 1,
  `course_attestation_text` text DEFAULT NULL,
  `course_is_qualification` tinyint(1) NOT NULL DEFAULT 0,
  `course_needs_online` tinyint(1) NOT NULL DEFAULT 1,
  `course_needs_session` tinyint(1) NOT NULL DEFAULT 0,
  `course_needs_practical` tinyint(1) NOT NULL DEFAULT 0,
  `course_external_only` tinyint(1) NOT NULL DEFAULT 0,
  `course_component_window_days` smallint(5) unsigned NOT NULL DEFAULT 90,
  `course_allow_trainer_attest` tinyint(1) NOT NULL DEFAULT 1,
  `course_eval_checklist` text DEFAULT NULL,
  `course_template_key` varchar(40) DEFAULT NULL,
  `course_current_revision_id` int(11) DEFAULT NULL,
  `course_draft_updated_at_utc` datetime(3) DEFAULT NULL,
  `course_version` int(10) unsigned NOT NULL DEFAULT 0,
  `course_created_by` int(11) NOT NULL,
  `course_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `course_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `course_archived_at` datetime DEFAULT NULL,
  `course_archived_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `uq_training_course_uid` (`course_uid`),
  UNIQUE KEY `uq_training_course_code` (`course_code`),
  KEY `idx_training_course_archived` (`course_archived_at`,`course_draft_updated_at_utc`),
  KEY `idx_training_course_category` (`course_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_evaluations`
--

DROP TABLE IF EXISTS `training_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_source_key` varchar(48) NOT NULL,
  `evaluation_contact_id` int(11) NOT NULL,
  `evaluation_course_id` int(11) NOT NULL,
  `evaluation_revision_id` int(11) DEFAULT NULL,
  `evaluation_run_id` int(11) DEFAULT NULL,
  `evaluation_tsession_id` int(11) DEFAULT NULL,
  `evaluation_channel` enum('agent','kiosk','session') NOT NULL,
  `evaluation_evaluator_contact_id` int(11) DEFAULT NULL,
  `evaluation_evaluator_user_id` int(11) DEFAULT NULL,
  `evaluation_evaluator_name` varchar(200) NOT NULL,
  `evaluation_evaluated_on` date NOT NULL,
  `evaluation_result` enum('pass','fail') NOT NULL,
  `evaluation_equipment` varchar(200) DEFAULT NULL,
  `evaluation_checklist_json` text DEFAULT NULL,
  `evaluation_notes` text DEFAULT NULL,
  `evaluation_proof` enum('evaluator_signed','document','agent_recorded') NOT NULL,
  `evaluation_evaluator_tsig_id` int(11) DEFAULT NULL,
  `evaluation_evaluatee_tsig_id` int(11) DEFAULT NULL,
  `evaluation_evidence_media_id` int(11) DEFAULT NULL,
  `evaluation_kiosk_id` int(11) DEFAULT NULL,
  `evaluation_recorded_by_user_id` int(11) DEFAULT NULL,
  `evaluation_recorded_at_utc` datetime(3) NOT NULL,
  `evaluation_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `evaluation_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`evaluation_id`),
  UNIQUE KEY `uq_training_eval_source` (`evaluation_source_key`),
  KEY `idx_training_eval_contact` (`evaluation_contact_id`,`evaluation_course_id`,`evaluation_evaluated_on`),
  KEY `idx_training_eval_session` (`evaluation_tsession_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_events`
--

DROP TABLE IF EXISTS `training_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_events` (
  `tevent_seq` bigint(20) unsigned NOT NULL,
  `tevent_at_utc` datetime(3) NOT NULL,
  `tevent_type` varchar(64) NOT NULL,
  `tevent_actor_type` enum('user','contact','kiosk','system') NOT NULL,
  `tevent_actor_user_id` int(11) DEFAULT NULL,
  `tevent_actor_contact_id` int(11) DEFAULT NULL,
  `tevent_kiosk_id` int(11) DEFAULT NULL,
  `tevent_ksess_id` int(11) DEFAULT NULL,
  `tevent_subject_contact_id` int(11) DEFAULT NULL,
  `tevent_course_id` int(11) DEFAULT NULL,
  `tevent_entity_type` varchar(40) DEFAULT NULL,
  `tevent_entity_id` int(11) DEFAULT NULL,
  `tevent_entity_sha256` char(64) DEFAULT NULL,
  `tevent_payload_json` mediumtext NOT NULL,
  `tevent_user_agent` varchar(255) DEFAULT NULL,
  `tevent_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tevent_prev_hash` char(64) NOT NULL,
  `tevent_hash` char(64) NOT NULL,
  PRIMARY KEY (`tevent_seq`),
  UNIQUE KEY `uq_training_tevent_hash` (`tevent_hash`),
  KEY `idx_training_tevent_subject` (`tevent_subject_contact_id`,`tevent_at_utc`),
  KEY `idx_training_tevent_type` (`tevent_type`,`tevent_at_utc`),
  KEY `idx_training_tevent_entity` (`tevent_entity_type`,`tevent_entity_id`),
  KEY `idx_training_tevent_kiosk` (`tevent_kiosk_id`,`tevent_type`,`tevent_at_utc`),
  KEY `idx_training_tevent_course` (`tevent_course_id`,`tevent_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_i18n`
--

DROP TABLE IF EXISTS `training_i18n`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_i18n` (
  `ti18n_entity` varchar(20) NOT NULL,
  `ti18n_entity_id` int(11) NOT NULL,
  `ti18n_lang` varchar(10) NOT NULL,
  `ti18n_field` varchar(40) NOT NULL,
  `ti18n_value` mediumtext NOT NULL,
  `ti18n_updated_by` int(11) NOT NULL,
  `ti18n_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ti18n_entity`,`ti18n_entity_id`,`ti18n_lang`,`ti18n_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_job_group_members`
--

DROP TABLE IF EXISTS `training_job_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_job_group_members` (
  `jgmember_jobgroup_id` int(11) NOT NULL,
  `jgmember_contact_id` int(11) NOT NULL,
  `jgmember_added_by` int(11) NOT NULL,
  `jgmember_added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`jgmember_jobgroup_id`,`jgmember_contact_id`),
  KEY `idx_training_jgmember_contact` (`jgmember_contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_job_group_titles`
--

DROP TABLE IF EXISTS `training_job_group_titles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_job_group_titles` (
  `jgtitle_jobgroup_id` int(11) NOT NULL,
  `jgtitle_normalized` varchar(200) NOT NULL,
  PRIMARY KEY (`jgtitle_jobgroup_id`,`jgtitle_normalized`),
  KEY `idx_training_jgtitle` (`jgtitle_normalized`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_job_groups`
--

DROP TABLE IF EXISTS `training_job_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_job_groups` (
  `jobgroup_id` int(11) NOT NULL AUTO_INCREMENT,
  `jobgroup_name` varchar(100) NOT NULL,
  `jobgroup_description` varchar(255) DEFAULT NULL,
  `jobgroup_version` int(10) unsigned NOT NULL DEFAULT 0,
  `jobgroup_created_by` int(11) NOT NULL,
  `jobgroup_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `jobgroup_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `jobgroup_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`jobgroup_id`),
  UNIQUE KEY `uq_training_jobgroup_name` (`jobgroup_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_kiosk_sessions`
--

DROP TABLE IF EXISTS `training_kiosk_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_kiosk_sessions` (
  `ksess_id` int(11) NOT NULL AUTO_INCREMENT,
  `ksess_kiosk_id` int(11) NOT NULL,
  `ksess_contact_id` int(11) NOT NULL,
  `ksess_role` enum('learner','trainer','checkin','handoff') NOT NULL,
  `ksess_tsession_id` int(11) DEFAULT NULL,
  `ksess_token_hash` char(64) NOT NULL,
  `ksess_pin_source` enum('odoo','local','portal') NOT NULL,
  `ksess_odoo_employee_id` int(11) DEFAULT NULL,
  `ksess_language` varchar(10) NOT NULL DEFAULT 'en',
  `ksess_idle_limit_s` smallint(5) unsigned NOT NULL,
  `ksess_started_at_utc` datetime(3) NOT NULL,
  `ksess_last_seen_at_utc` datetime(3) NOT NULL,
  `ksess_absolute_until_utc` datetime(3) NOT NULL,
  `ksess_ended_at_utc` datetime(3) DEFAULT NULL,
  `ksess_end_reason` enum('done','idle','absolute','replaced','revoked','device','contact_ineligible','pin_locked','checkin_enter','checkin_exit','handoff_enter','handoff_exit','error') DEFAULT NULL,
  `ksess_open_guard` tinyint(1) DEFAULT 1,
  `ksess_user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ksess_id`),
  UNIQUE KEY `uq_training_ksess_token` (`ksess_token_hash`),
  UNIQUE KEY `uq_training_ksess_open` (`ksess_kiosk_id`,`ksess_open_guard`),
  KEY `idx_training_ksess_contact` (`ksess_contact_id`,`ksess_started_at_utc`),
  KEY `idx_training_ksess_open_seen` (`ksess_open_guard`,`ksess_last_seen_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_kiosks`
--

DROP TABLE IF EXISTS `training_kiosks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_kiosks` (
  `kiosk_id` int(11) NOT NULL AUTO_INCREMENT,
  `kiosk_asset_id` int(11) DEFAULT NULL,
  `kiosk_asset_type` varchar(200) DEFAULT NULL,
  `kiosk_asset_serial` varchar(200) DEFAULT NULL,
  `kiosk_personal_contact_id` int(11) DEFAULT NULL,
  `kiosk_force_shared` tinyint(1) NOT NULL DEFAULT 0,
  `kiosk_label` varchar(100) NOT NULL,
  `kiosk_default_client_id` int(11) NOT NULL DEFAULT 0,
  `kiosk_status` enum('pending','active','revoked') NOT NULL DEFAULT 'pending',
  `kiosk_enroll_method` enum('agent_device','setup_code','portal') DEFAULT NULL,
  `kiosk_enroll_code_hash` char(64) DEFAULT NULL,
  `kiosk_enroll_expires_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_enroll_failures` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `kiosk_token_hash` char(64) DEFAULT NULL,
  `kiosk_token_issued_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_enrolled_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_enrolled_by` int(11) DEFAULT NULL,
  `kiosk_expires_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_created_by` int(11) NOT NULL,
  `kiosk_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `kiosk_last_seen_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_last_user_agent` varchar(255) DEFAULT NULL,
  `kiosk_cooldown_until_utc` datetime(3) DEFAULT NULL,
  `kiosk_cooldown_reason` varchar(40) DEFAULT NULL,
  `kiosk_revoked_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_revoked_by` int(11) DEFAULT NULL,
  `kiosk_revoke_reason` varchar(255) DEFAULT NULL,
  `kiosk_hidden_at_utc` datetime(3) DEFAULT NULL,
  `kiosk_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`kiosk_id`),
  UNIQUE KEY `uq_training_kiosk_token` (`kiosk_token_hash`),
  UNIQUE KEY `uq_training_kiosk_code` (`kiosk_enroll_code_hash`),
  KEY `idx_training_kiosk_asset` (`kiosk_asset_id`,`kiosk_status`),
  KEY `idx_training_kiosk_expires` (`kiosk_status`,`kiosk_expires_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_learner_credentials`
--

DROP TABLE IF EXISTS `training_learner_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_learner_credentials` (
  `tcred_contact_id` int(11) NOT NULL,
  `tcred_source` enum('odoo','local') NOT NULL DEFAULT 'local',
  `tcred_source_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `tcred_odoo_integration_id` int(11) DEFAULT NULL,
  `tcred_odoo_employee_id` int(11) DEFAULT NULL,
  `tcred_odoo_confirmed_at_utc` datetime(3) DEFAULT NULL,
  `tcred_odoo_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `tcred_odoo_fp_hash` varchar(255) DEFAULT NULL,
  `tcred_odoo_fp_changed_at_utc` datetime(3) DEFAULT NULL,
  `tcred_pin_hash` varchar(255) DEFAULT NULL,
  `tcred_prev_pin_hash` varchar(255) DEFAULT NULL,
  `tcred_pepper_version` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tcred_set_method` enum('setup_code','trainer_assisted','self_change') DEFAULT NULL,
  `tcred_set_at_utc` datetime(3) DEFAULT NULL,
  `tcred_set_by_trainer_contact_id` int(11) DEFAULT NULL,
  `tcred_setup_code_hash` varchar(255) DEFAULT NULL,
  `tcred_setup_code_expires_at_utc` datetime(3) DEFAULT NULL,
  `tcred_setup_code_issued_by` int(11) DEFAULT NULL,
  `tcred_setup_code_issued_at_utc` datetime(3) DEFAULT NULL,
  `tcred_setup_token_hash` char(64) DEFAULT NULL,
  `tcred_setup_token_expires_at_utc` datetime(3) DEFAULT NULL,
  `tcred_failed_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `tcred_locked_until_utc` datetime(3) DEFAULT NULL,
  `tcred_hard_locked` tinyint(1) NOT NULL DEFAULT 0,
  `tcred_last_success_at_utc` datetime(3) DEFAULT NULL,
  `tcred_reset_notice` tinyint(1) NOT NULL DEFAULT 0,
  `tcred_reset_notice_at_utc` datetime(3) DEFAULT NULL,
  `tcred_reset_by_label` varchar(200) DEFAULT NULL,
  `tcred_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tcred_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tcred_contact_id`),
  KEY `idx_training_tcred_source` (`tcred_source`,`tcred_odoo_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_learner_prefs`
--

DROP TABLE IF EXISTS `training_learner_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_learner_prefs` (
  `tpref_contact_id` int(11) NOT NULL,
  `tpref_language` varchar(10) NOT NULL DEFAULT 'en',
  `tpref_updated_at_utc` datetime(3) NOT NULL,
  PRIMARY KEY (`tpref_contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_ledger_head`
--

DROP TABLE IF EXISTS `training_ledger_head`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_ledger_head` (
  `lhead_id` tinyint(3) unsigned NOT NULL,
  `lhead_last_seq` bigint(20) unsigned NOT NULL,
  `lhead_last_hash` char(64) NOT NULL,
  `lhead_updated_at_utc` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`lhead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT IGNORE INTO `training_ledger_head` (`lhead_id`, `lhead_last_seq`, `lhead_last_hash`, `lhead_updated_at_utc`) VALUES (1, 0, '0000000000000000000000000000000000000000000000000000000000000000', NULL);

--
-- Table structure for table `training_lesson_completions`
--

DROP TABLE IF EXISTS `training_lesson_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_lesson_completions` (
  `lcomp_id` int(11) NOT NULL AUTO_INCREMENT,
  `lcomp_run_id` int(11) NOT NULL,
  `lcomp_contact_id` int(11) NOT NULL,
  `lcomp_course_id` int(11) NOT NULL,
  `lcomp_revision_id` int(11) NOT NULL,
  `lcomp_lesson_uid` char(12) NOT NULL,
  `lcomp_lesson_type` varchar(20) NOT NULL,
  `lcomp_language` varchar(10) NOT NULL,
  `lcomp_opened_at_utc` datetime(3) NOT NULL,
  `lcomp_completed_at_utc` datetime(3) NOT NULL,
  `lcomp_server_seconds` int(10) unsigned NOT NULL,
  `lcomp_required_seconds` int(10) unsigned NOT NULL,
  `lcomp_max_position` int(10) unsigned DEFAULT NULL,
  `lcomp_coverage_json` varchar(1000) DEFAULT NULL,
  `lcomp_attempt_id` int(11) DEFAULT NULL,
  `lcomp_tsig_id` int(11) DEFAULT NULL,
  `lcomp_ksess_id` int(11) DEFAULT NULL,
  `lcomp_kiosk_id` int(11) DEFAULT NULL,
  `lcomp_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `lcomp_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`lcomp_id`),
  UNIQUE KEY `uq_training_lcomp` (`lcomp_run_id`,`lcomp_lesson_uid`),
  KEY `idx_training_lcomp_contact` (`lcomp_contact_id`,`lcomp_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_lesson_resources`
--

DROP TABLE IF EXISTS `training_lesson_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_lesson_resources` (
  `lres_id` int(11) NOT NULL AUTO_INCREMENT,
  `lres_uid` char(12) NOT NULL,
  `lres_lesson_id` int(11) NOT NULL,
  `lres_lang` varchar(10) DEFAULT NULL,
  `lres_kind` enum('file','link') NOT NULL,
  `lres_title` varchar(200) NOT NULL,
  `lres_media_id` int(11) DEFAULT NULL,
  `lres_url` varchar(500) DEFAULT NULL,
  `lres_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `lres_created_by` int(11) NOT NULL,
  `lres_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `lres_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`lres_id`),
  UNIQUE KEY `uq_training_lres_uid` (`lres_uid`),
  KEY `idx_training_lres_lesson` (`lres_lesson_id`,`lres_archived_at`,`lres_sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_lesson_variants`
--

DROP TABLE IF EXISTS `training_lesson_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_lesson_variants` (
  `lvar_lesson_id` int(11) NOT NULL,
  `lvar_lang` varchar(10) NOT NULL,
  `lvar_title` varchar(200) NOT NULL DEFAULT '',
  `lvar_description_html` mediumtext DEFAULT NULL,
  `lvar_body_html` mediumtext DEFAULT NULL,
  `lvar_word_count` int(10) unsigned NOT NULL DEFAULT 0,
  `lvar_media_id` int(11) DEFAULT NULL,
  `lvar_caption_media_id` int(11) DEFAULT NULL,
  `lvar_caption` varchar(500) DEFAULT NULL,
  `lvar_video_provider` enum('upload','youtube','vimeo') DEFAULT NULL,
  `lvar_video_ext_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lvar_video_ext_hash` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lvar_kb_source_article_id` int(11) DEFAULT NULL,
  `lvar_kb_source_sha256` char(64) DEFAULT NULL,
  `lvar_kb_import_body_sha256` char(64) DEFAULT NULL,
  `lvar_kb_imported_at_utc` datetime(3) DEFAULT NULL,
  `lvar_updated_by` int(11) DEFAULT NULL,
  `lvar_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lvar_lesson_id`,`lvar_lang`),
  KEY `idx_training_lvar_media` (`lvar_media_id`),
  KEY `idx_training_lvar_kb` (`lvar_kb_source_article_id`),
  KEY `idx_training_lvar_video` (`lvar_video_provider`,`lvar_video_ext_id`),
  KEY `idx_training_lvar_capmedia` (`lvar_caption_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_lessons`
--

DROP TABLE IF EXISTS `training_lessons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_lessons` (
  `lesson_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_uid` char(12) NOT NULL,
  `lesson_course_id` int(11) NOT NULL,
  `lesson_section_id` int(11) DEFAULT NULL,
  `lesson_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `lesson_type` enum('article','document','video','image','quiz','acknowledgment') NOT NULL,
  `lesson_required` tinyint(1) NOT NULL DEFAULT 1,
  `lesson_requires_previous` tinyint(1) NOT NULL DEFAULT 1,
  `lesson_duration_s` int(10) unsigned DEFAULT NULL,
  `lesson_allow_download` tinyint(1) NOT NULL DEFAULT 0,
  `lesson_preview_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `lesson_responsible_user_id` int(11) DEFAULT NULL,
  `lesson_thumb_media_id` int(11) DEFAULT NULL,
  `lesson_min_watch_pct` tinyint(3) unsigned NOT NULL DEFAULT 90,
  `lesson_ack_require_signature` tinyint(1) NOT NULL DEFAULT 1,
  `lesson_ack_require_pin` tinyint(1) NOT NULL DEFAULT 1,
  `lesson_version` int(10) unsigned NOT NULL DEFAULT 0,
  `lesson_created_by` int(11) NOT NULL,
  `lesson_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `lesson_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `lesson_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`lesson_id`),
  UNIQUE KEY `uq_training_lesson_uid` (`lesson_uid`),
  KEY `idx_training_lesson_course` (`lesson_course_id`,`lesson_archived_at`,`lesson_section_id`,`lesson_sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_media`
--

DROP TABLE IF EXISTS `training_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_media` (
  `media_id` int(11) NOT NULL AUTO_INCREMENT,
  `media_sha256` char(64) NOT NULL,
  `media_kind` enum('pdf','page','video','image','file','evidence','caption') NOT NULL,
  `media_mime` varchar(100) NOT NULL,
  `media_ext` varchar(10) NOT NULL,
  `media_bytes` bigint(20) unsigned NOT NULL,
  `media_path` varchar(255) NOT NULL,
  `media_original_name` varchar(255) DEFAULT NULL,
  `media_width` smallint(5) unsigned DEFAULT NULL,
  `media_height` smallint(5) unsigned DEFAULT NULL,
  `media_page_count` smallint(5) unsigned DEFAULT NULL,
  `media_duration_ms` int(10) unsigned DEFAULT NULL,
  `media_video_codec` varchar(8) DEFAULT NULL,
  `media_audio_codec` varchar(8) DEFAULT NULL,
  `media_faststart` tinyint(1) DEFAULT NULL,
  `media_uploaded_by` int(11) NOT NULL,
  `media_created_at_utc` datetime(3) NOT NULL,
  `media_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `media_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`media_id`),
  UNIQUE KEY `uq_training_media_sha_kind` (`media_sha256`,`media_kind`),
  KEY `idx_training_media_kind` (`media_kind`,`media_created_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_media_pages`
--

DROP TABLE IF EXISTS `training_media_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_media_pages` (
  `mpage_pdf_media_id` int(11) NOT NULL,
  `mpage_number` smallint(5) unsigned NOT NULL,
  `mpage_media_id` int(11) NOT NULL,
  `mpage_created_at_utc` datetime(3) NOT NULL,
  `mpage_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `mpage_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`mpage_pdf_media_id`,`mpage_number`),
  KEY `idx_training_mpage_media` (`mpage_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_odoo_map`
--

DROP TABLE IF EXISTS `training_odoo_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_odoo_map` (
  `tomap_entity` enum('course','achievement') NOT NULL,
  `tomap_entity_id` int(11) NOT NULL,
  `tomap_push` tinyint(1) NOT NULL,
  `tomap_target_key` char(16) DEFAULT NULL,
  `tomap_odoo_skill_id` int(11) DEFAULT NULL,
  `tomap_updated_by` int(11) NOT NULL,
  `tomap_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tomap_entity`,`tomap_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_odoo_outbox`
--

DROP TABLE IF EXISTS `training_odoo_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_odoo_outbox` (
  `todoo_id` int(11) NOT NULL AUTO_INCREMENT,
  `todoo_target_key` char(16) NOT NULL,
  `todoo_integration_id` int(11) NOT NULL,
  `todoo_source_type` enum('completion','award') NOT NULL,
  `todoo_source_id` int(11) NOT NULL,
  `todoo_action` enum('create','close') NOT NULL,
  `todoo_contact_id` int(11) NOT NULL,
  `todoo_mode` enum('resume','skill','note') NOT NULL,
  `todoo_marker` varchar(40) NOT NULL,
  `todoo_status` enum('pending','running','done','failed','dead','skipped') NOT NULL DEFAULT 'pending',
  `todoo_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `todoo_next_attempt_at_utc` datetime(3) NOT NULL,
  `todoo_lease_until_utc` datetime(3) DEFAULT NULL,
  `todoo_odoo_employee_id` int(11) DEFAULT NULL,
  `todoo_odoo_model` varchar(64) DEFAULT NULL,
  `todoo_odoo_res_id` int(11) DEFAULT NULL,
  `todoo_payload_json` text DEFAULT NULL,
  `todoo_error_class` enum('auth','config','transient','permanent','hold','policy') DEFAULT NULL,
  `todoo_last_error` varchar(500) DEFAULT NULL,
  `todoo_created_at_utc` datetime(3) NOT NULL,
  `todoo_done_at_utc` datetime(3) DEFAULT NULL,
  `todoo_updated_by` int(11) DEFAULT NULL,
  `todoo_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`todoo_id`),
  UNIQUE KEY `uq_training_todoo_source_mode` (`todoo_target_key`,`todoo_source_type`,`todoo_source_id`,`todoo_action`,`todoo_mode`),
  KEY `idx_training_todoo_due` (`todoo_target_key`,`todoo_status`,`todoo_next_attempt_at_utc`),
  KEY `idx_training_todoo_contact` (`todoo_contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_option_texts`
--

DROP TABLE IF EXISTS `training_option_texts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_option_texts` (
  `otext_option_id` int(11) NOT NULL,
  `otext_lang` varchar(10) NOT NULL,
  `otext_text` varchar(1000) NOT NULL,
  `otext_feedback` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`otext_option_id`,`otext_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_path_courses`
--

DROP TABLE IF EXISTS `training_path_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_path_courses` (
  `tpcourse_path_id` int(11) NOT NULL,
  `tpcourse_course_id` int(11) NOT NULL,
  `tpcourse_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `tpcourse_required` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`tpcourse_path_id`,`tpcourse_course_id`),
  KEY `idx_training_tpcourse_course` (`tpcourse_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_paths`
--

DROP TABLE IF EXISTS `training_paths`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_paths` (
  `tpath_id` int(11) NOT NULL AUTO_INCREMENT,
  `tpath_uid` char(12) NOT NULL,
  `tpath_name` varchar(200) NOT NULL,
  `tpath_description` varchar(1000) DEFAULT NULL,
  `tpath_color` char(7) DEFAULT NULL,
  `tpath_cover_media_id` int(11) DEFAULT NULL,
  `tpath_sequential` tinyint(1) NOT NULL DEFAULT 1,
  `tpath_achievement_id` int(11) DEFAULT NULL,
  `tpath_version` int(10) unsigned NOT NULL DEFAULT 0,
  `tpath_created_by` int(11) NOT NULL,
  `tpath_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tpath_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tpath_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tpath_id`),
  UNIQUE KEY `uq_training_tpath_uid` (`tpath_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_question_banks`
--

DROP TABLE IF EXISTS `training_question_banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_question_banks` (
  `qbank_id` int(11) NOT NULL AUTO_INCREMENT,
  `qbank_uid` char(12) NOT NULL,
  `qbank_parent_id` int(11) DEFAULT NULL,
  `qbank_name` varchar(150) NOT NULL,
  `qbank_description` varchar(500) DEFAULT NULL,
  `qbank_course_id` int(11) DEFAULT NULL,
  `qbank_quiz_lesson_id` int(11) DEFAULT NULL,
  `qbank_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `qbank_created_by` int(11) NOT NULL,
  `qbank_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `qbank_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `qbank_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`qbank_id`),
  UNIQUE KEY `uq_training_qbank_uid` (`qbank_uid`),
  KEY `idx_training_qbank_parent` (`qbank_parent_id`,`qbank_archived_at`,`qbank_sort`),
  KEY `idx_training_qbank_course` (`qbank_course_id`),
  KEY `idx_training_qbank_lesson` (`qbank_quiz_lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_question_options`
--

DROP TABLE IF EXISTS `training_question_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_question_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `option_uid` char(12) NOT NULL,
  `option_question_id` int(11) NOT NULL,
  `option_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  `option_is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `option_pinned` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `uq_training_option_uid` (`option_uid`),
  KEY `idx_training_option_question` (`option_question_id`,`option_sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_question_texts`
--

DROP TABLE IF EXISTS `training_question_texts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_question_texts` (
  `qtext_question_id` int(11) NOT NULL,
  `qtext_lang` varchar(10) NOT NULL,
  `qtext_text` text NOT NULL,
  `qtext_explanation` text DEFAULT NULL,
  `qtext_topic` varchar(100) DEFAULT NULL,
  `qtext_media_id` int(11) DEFAULT NULL,
  `qtext_updated_by` int(11) NOT NULL,
  `qtext_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`qtext_question_id`,`qtext_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_questions`
--

DROP TABLE IF EXISTS `training_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `question_uid` char(12) NOT NULL,
  `question_bank_id` int(11) NOT NULL,
  `question_type` enum('single','multi','truefalse') NOT NULL,
  `question_media_id` int(11) DEFAULT NULL,
  `question_points` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `question_critical` tinyint(1) NOT NULL DEFAULT 0,
  `question_sort` int(10) unsigned NOT NULL DEFAULT 0,
  `question_version` int(10) unsigned NOT NULL DEFAULT 0,
  `question_created_by` int(11) NOT NULL,
  `question_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `question_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `question_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`question_id`),
  UNIQUE KEY `uq_training_question_uid` (`question_uid`),
  KEY `idx_training_question_bank` (`question_bank_id`,`question_archived_at`,`question_sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_quiz_rules`
--

DROP TABLE IF EXISTS `training_quiz_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_quiz_rules` (
  `qrule_id` int(11) NOT NULL AUTO_INCREMENT,
  `qrule_uid` char(12) NOT NULL,
  `qrule_quiz_id` int(11) NOT NULL,
  `qrule_bank_id` int(11) NOT NULL,
  `qrule_include_descendants` tinyint(1) NOT NULL DEFAULT 1,
  `qrule_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `qrule_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`qrule_id`),
  UNIQUE KEY `uq_training_qrule_uid` (`qrule_uid`),
  KEY `idx_training_qrule_quiz` (`qrule_quiz_id`,`qrule_sort`),
  KEY `idx_training_qrule_bank` (`qrule_bank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_quizzes`
--

DROP TABLE IF EXISTS `training_quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_uid` char(12) NOT NULL,
  `quiz_lesson_id` int(11) NOT NULL,
  `quiz_role` enum('standalone','exam','check') NOT NULL,
  `quiz_pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 80,
  `quiz_max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `quiz_time_limit_s` smallint(5) unsigned DEFAULT NULL,
  `quiz_shuffle_questions` tinyint(1) NOT NULL DEFAULT 1,
  `quiz_shuffle_options` tinyint(1) NOT NULL DEFAULT 1,
  `quiz_feedback_mode` enum('score_only','missed_questions','answers_after_pass') NOT NULL DEFAULT 'missed_questions',
  `quiz_show_review` tinyint(1) NOT NULL DEFAULT 1,
  `quiz_must_pass` tinyint(1) NOT NULL DEFAULT 1,
  `quiz_intro` varchar(1000) DEFAULT NULL,
  `quiz_version` int(10) unsigned NOT NULL DEFAULT 0,
  `quiz_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `quiz_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`quiz_id`),
  UNIQUE KEY `uq_training_quiz_uid` (`quiz_uid`),
  UNIQUE KEY `uq_training_quiz_lesson` (`quiz_lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_rate_buckets`
--

DROP TABLE IF EXISTS `training_rate_buckets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_rate_buckets` (
  `trate_key` varchar(80) NOT NULL,
  `trate_window_start_utc` datetime NOT NULL,
  `trate_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`trate_key`,`trate_window_start_utc`),
  KEY `idx_training_trate_window` (`trate_window_start_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_reminder_log`
--

DROP TABLE IF EXISTS `training_reminder_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_reminder_log` (
  `trem_id` int(11) NOT NULL AUTO_INCREMENT,
  `trem_user_id` int(11) NOT NULL,
  `trem_date` date NOT NULL,
  `trem_kind` varchar(32) NOT NULL,
  `trem_counts_json` text NOT NULL,
  `trem_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`trem_id`),
  UNIQUE KEY `uq_training_trem` (`trem_user_id`,`trem_date`,`trem_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_requirement_criteria`
--

DROP TABLE IF EXISTS `training_requirement_criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_requirement_criteria` (
  `rcrit_requirement_id` int(11) NOT NULL,
  `rcrit_kind` enum('department','odoo_job','odoo_location','jobgroup','contact') NOT NULL,
  `rcrit_value_id` int(11) NOT NULL,
  `rcrit_value_label` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`rcrit_requirement_id`,`rcrit_kind`,`rcrit_value_id`),
  KEY `idx_training_rcrit_value` (`rcrit_kind`,`rcrit_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_requirements`
--

DROP TABLE IF EXISTS `training_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_requirements` (
  `requirement_id` int(11) NOT NULL AUTO_INCREMENT,
  `requirement_request_uid` char(32) DEFAULT NULL,
  `requirement_name` varchar(150) NOT NULL,
  `requirement_course_id` int(11) NOT NULL,
  `requirement_all_people` tinyint(1) NOT NULL DEFAULT 0,
  `requirement_required` tinyint(1) NOT NULL DEFAULT 1,
  `requirement_new_hires_only` tinyint(1) NOT NULL DEFAULT 0,
  `requirement_due_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `requirement_baseline_due_on` date NOT NULL,
  `requirement_due_days_from_hire` smallint(5) unsigned NOT NULL DEFAULT 7,
  `requirement_one_time` tinyint(1) NOT NULL DEFAULT 0,
  `requirement_is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `requirement_note` varchar(500) DEFAULT NULL,
  `requirement_effective_on` date NOT NULL,
  `requirement_criteria_sha256` char(64) NOT NULL,
  `requirement_version` int(10) unsigned NOT NULL DEFAULT 0,
  `requirement_created_by` int(11) NOT NULL,
  `requirement_created_at_utc` datetime(3) NOT NULL,
  `requirement_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `requirement_archived_at` datetime DEFAULT NULL,
  `requirement_archived_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`requirement_id`),
  UNIQUE KEY `uq_training_req_request` (`requirement_request_uid`),
  KEY `idx_training_req_course` (`requirement_course_id`,`requirement_archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_revision_media`
--

DROP TABLE IF EXISTS `training_revision_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_revision_media` (
  `rmedia_revision_id` int(11) NOT NULL,
  `rmedia_media_id` int(11) NOT NULL,
  `rmedia_media_sha256` char(64) NOT NULL,
  `rmedia_downloadable` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rmedia_revision_id`,`rmedia_media_id`),
  KEY `idx_training_rmedia_media` (`rmedia_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_revisions`
--

DROP TABLE IF EXISTS `training_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_revisions` (
  `revision_id` int(11) NOT NULL AUTO_INCREMENT,
  `revision_course_id` int(11) NOT NULL,
  `revision_number` smallint(5) unsigned NOT NULL,
  `revision_kind` enum('training','document') NOT NULL,
  `revision_schema` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `revision_json` longtext NOT NULL,
  `revision_sha256` char(64) NOT NULL,
  `revision_languages` varchar(40) NOT NULL,
  `revision_change_note` varchar(1000) NOT NULL,
  `revision_requires_retraining` tinyint(1) NOT NULL DEFAULT 0,
  `revision_retrain_due_days` smallint(5) unsigned DEFAULT NULL,
  `revision_published_by` int(11) NOT NULL,
  `revision_published_at_utc` datetime(3) NOT NULL,
  `revision_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `revision_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`revision_id`),
  UNIQUE KEY `uq_training_revision` (`revision_course_id`,`revision_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_roster`
--

DROP TABLE IF EXISTS `training_roster`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_roster` (
  `roster_contact_id` int(11) NOT NULL,
  `roster_state` enum('auto','include','exclude') NOT NULL DEFAULT 'auto',
  `roster_reason` varchar(255) DEFAULT NULL,
  `roster_updated_by` int(11) NOT NULL,
  `roster_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`roster_contact_id`),
  KEY `idx_training_roster_state` (`roster_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_runs`
--

DROP TABLE IF EXISTS `training_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_runs` (
  `trun_id` int(11) NOT NULL AUTO_INCREMENT,
  `trun_contact_id` int(11) NOT NULL,
  `trun_course_id` int(11) NOT NULL,
  `trun_revision_id` int(11) NOT NULL,
  `trun_revision_sha256` char(64) NOT NULL,
  `trun_assignment_id` int(11) DEFAULT NULL,
  `trun_language` varchar(10) NOT NULL,
  `trun_status` enum('in_progress','awaiting_signature','awaiting_session','awaiting_evaluation','completed','abandoned','superseded') NOT NULL DEFAULT 'in_progress',
  `trun_open_guard` tinyint(1) DEFAULT 1,
  `trun_channel` enum('kiosk','portal') NOT NULL DEFAULT 'kiosk',
  `trun_started_at_utc` datetime(3) NOT NULL,
  `trun_started_kiosk_id` int(11) DEFAULT NULL,
  `trun_current_lesson_uid` char(12) DEFAULT NULL,
  `trun_lesson_opened_at_utc` datetime(3) DEFAULT NULL,
  `trun_lesson_last_tick_at_utc` datetime(3) DEFAULT NULL,
  `trun_lesson_last_active` tinyint(1) NOT NULL DEFAULT 0,
  `trun_lesson_credit_s` int(10) unsigned NOT NULL DEFAULT 0,
  `trun_lesson_max_position` int(10) unsigned NOT NULL DEFAULT 0,
  `trun_lesson_pages_hex` varchar(64) DEFAULT NULL,
  `trun_lesson_rejected_ticks` smallint(5) unsigned NOT NULL DEFAULT 0,
  `trun_lesson_resume_at` int(10) unsigned DEFAULT NULL,
  `trun_progress_pct` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `trun_extra_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `trun_locked_at_utc` datetime(3) DEFAULT NULL,
  `trun_locked_lesson_uid` char(12) DEFAULT NULL,
  `trun_blocked_reason` enum('video_changed','video_unavailable') DEFAULT NULL,
  `trun_blocked_lesson_uid` char(12) DEFAULT NULL,
  `trun_passed_attempt_id` int(11) DEFAULT NULL,
  `trun_attested_at_utc` datetime(3) DEFAULT NULL,
  `trun_attest_tsig_id` int(11) DEFAULT NULL,
  `trun_attest_proof` enum('self_pin_signature','self_pin') DEFAULT NULL,
  `trun_attest_pin_source` enum('odoo','local') DEFAULT NULL,
  `trun_attest_odoo_employee_id` int(11) DEFAULT NULL,
  `trun_last_activity_at_utc` datetime(3) NOT NULL,
  `trun_ended_at_utc` datetime(3) DEFAULT NULL,
  `trun_completion_id` int(11) DEFAULT NULL,
  `trun_superseded_by_run_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`trun_id`),
  UNIQUE KEY `uq_training_run_open` (`trun_contact_id`,`trun_course_id`,`trun_open_guard`),
  KEY `idx_training_run_contact` (`trun_contact_id`,`trun_status`),
  KEY `idx_training_run_status` (`trun_status`,`trun_last_activity_at_utc`),
  KEY `idx_training_run_revision` (`trun_revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_session_attendees`
--

DROP TABLE IF EXISTS `training_session_attendees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_session_attendees` (
  `tattendee_id` int(11) NOT NULL AUTO_INCREMENT,
  `tattendee_tsession_id` int(11) NOT NULL,
  `tattendee_contact_id` int(11) NOT NULL,
  `tattendee_proof` enum('self_pin_signature','self_pin','trainer_attested','document','agent_recorded') NOT NULL,
  `tattendee_attest_reason` varchar(255) DEFAULT NULL,
  `tattendee_checked_in_at_utc` datetime(3) NOT NULL,
  `tattendee_kiosk_id` int(11) DEFAULT NULL,
  `tattendee_tsig_id` int(11) DEFAULT NULL,
  `tattendee_attendance` enum('present','partial','absent') NOT NULL DEFAULT 'present',
  `tattendee_practical` enum('not_evaluated','pass','fail') NOT NULL DEFAULT 'not_evaluated',
  `tattendee_notes` varchar(500) DEFAULT NULL,
  `tattendee_marked_by_contact_id` int(11) DEFAULT NULL,
  `tattendee_marked_by_user_id` int(11) DEFAULT NULL,
  `tattendee_removed_at_utc` datetime(3) DEFAULT NULL,
  `tattendee_removed_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`tattendee_id`),
  UNIQUE KEY `uq_training_tattendee` (`tattendee_tsession_id`,`tattendee_contact_id`),
  KEY `idx_training_tattendee_contact` (`tattendee_contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_sessions`
--

DROP TABLE IF EXISTS `training_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_sessions` (
  `tsession_id` int(11) NOT NULL AUTO_INCREMENT,
  `tsession_request_uid` char(32) DEFAULT NULL,
  `tsession_course_id` int(11) NOT NULL,
  `tsession_revision_id` int(11) DEFAULT NULL,
  `tsession_status` enum('open','finalized','cancelled') NOT NULL DEFAULT 'open',
  `tsession_held_on` date NOT NULL,
  `tsession_start_time` time DEFAULT NULL,
  `tsession_duration_minutes` smallint(5) unsigned DEFAULT NULL,
  `tsession_client_id` int(11) NOT NULL DEFAULT 0,
  `tsession_location` varchar(200) DEFAULT NULL,
  `tsession_topic` varchar(200) DEFAULT NULL,
  `tsession_notes` text DEFAULT NULL,
  `tsession_trainer_contact_id` int(11) DEFAULT NULL,
  `tsession_trainer_user_id` int(11) DEFAULT NULL,
  `tsession_trainer_name` varchar(200) NOT NULL,
  `tsession_channel` enum('agent','kiosk') NOT NULL DEFAULT 'agent',
  `tsession_is_backfill` tinyint(1) NOT NULL DEFAULT 0,
  `tsession_evidence_media_id` int(11) DEFAULT NULL,
  `tsession_created_by_user_id` int(11) DEFAULT NULL,
  `tsession_created_kiosk_id` int(11) DEFAULT NULL,
  `tsession_started_at_utc` datetime(3) DEFAULT NULL,
  `tsession_trainer_tsig_id` int(11) DEFAULT NULL,
  `tsession_finalized_at_utc` datetime(3) DEFAULT NULL,
  `tsession_finalized_by_user_id` int(11) DEFAULT NULL,
  `tsession_finalized_by_contact_id` int(11) DEFAULT NULL,
  `tsession_finalize_attest` varchar(255) DEFAULT NULL,
  `tsession_digest_v` tinyint(3) unsigned DEFAULT NULL,
  `tsession_sha256` char(64) DEFAULT NULL,
  `tsession_cancel_reason` varchar(255) DEFAULT NULL,
  `tsession_version` int(10) unsigned NOT NULL DEFAULT 0,
  `tsession_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tsession_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tsession_id`),
  UNIQUE KEY `uq_training_tsession_request` (`tsession_request_uid`),
  KEY `idx_training_tsession_course` (`tsession_course_id`,`tsession_held_on`),
  KEY `idx_training_tsession_status` (`tsession_status`,`tsession_held_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_signatures`
--

DROP TABLE IF EXISTS `training_signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_signatures` (
  `tsig_id` int(11) NOT NULL AUTO_INCREMENT,
  `tsig_purpose` enum('learner_attest','ack','attendee','trainer','evaluator','evaluatee') NOT NULL,
  `tsig_contact_id` int(11) DEFAULT NULL,
  `tsig_signer_name` varchar(200) NOT NULL,
  `tsig_png_base64` mediumtext NOT NULL,
  `tsig_png_sha256` char(64) NOT NULL,
  `tsig_width` smallint(5) unsigned NOT NULL,
  `tsig_height` smallint(5) unsigned NOT NULL,
  `tsig_ink_px` int(10) unsigned NOT NULL,
  `tsig_statement_sha256` char(64) DEFAULT NULL,
  `tsig_kiosk_id` int(11) DEFAULT NULL,
  `tsig_ksess_id` int(11) DEFAULT NULL,
  `tsig_run_id` int(11) DEFAULT NULL,
  `tsig_tsession_id` int(11) DEFAULT NULL,
  `tsig_captured_at_utc` datetime(3) NOT NULL,
  `tsig_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tsig_row_sha256` char(64) NOT NULL,
  PRIMARY KEY (`tsig_id`),
  KEY `idx_training_tsig_contact` (`tsig_contact_id`),
  KEY `idx_training_tsig_run` (`tsig_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_tag_links`
--

DROP TABLE IF EXISTS `training_tag_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_tag_links` (
  `ttlink_tag_id` int(11) NOT NULL,
  `ttlink_entity` enum('course','lesson') NOT NULL,
  `ttlink_entity_id` int(11) NOT NULL,
  PRIMARY KEY (`ttlink_entity`,`ttlink_entity_id`,`ttlink_tag_id`),
  KEY `idx_training_ttlink_tag` (`ttlink_tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_tags`
--

DROP TABLE IF EXISTS `training_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_tags` (
  `ttag_id` int(11) NOT NULL AUTO_INCREMENT,
  `ttag_name` varchar(60) NOT NULL,
  `ttag_color` char(7) DEFAULT NULL,
  `ttag_created_by` int(11) NOT NULL,
  `ttag_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ttag_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ttag_id`),
  UNIQUE KEY `uq_training_ttag_name` (`ttag_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_trainer_courses`
--

DROP TABLE IF EXISTS `training_trainer_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_trainer_courses` (
  `ttcourse_contact_id` int(11) NOT NULL,
  `ttcourse_course_id` int(11) NOT NULL,
  PRIMARY KEY (`ttcourse_contact_id`,`ttcourse_course_id`),
  KEY `idx_training_ttcourse_course` (`ttcourse_course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_trainer_departments`
--

DROP TABLE IF EXISTS `training_trainer_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_trainer_departments` (
  `ttdept_contact_id` int(11) NOT NULL,
  `ttdept_client_id` int(11) NOT NULL,
  PRIMARY KEY (`ttdept_contact_id`,`ttdept_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_trainers`
--

DROP TABLE IF EXISTS `training_trainers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_trainers` (
  `trainer_contact_id` int(11) NOT NULL,
  `trainer_user_id` int(11) DEFAULT NULL,
  `trainer_title` varchar(100) DEFAULT NULL,
  `trainer_can_train` tinyint(1) NOT NULL DEFAULT 1,
  `trainer_can_evaluate` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_can_setup_pins` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_can_unlock` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_can_view_team` tinyint(1) NOT NULL DEFAULT 1,
  `trainer_all_courses` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_all_departments` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_qualifications` text DEFAULT NULL,
  `trainer_active` tinyint(1) NOT NULL DEFAULT 1,
  `trainer_pin_hash` varchar(255) DEFAULT NULL,
  `trainer_pin_prev_hash` varchar(255) DEFAULT NULL,
  `trainer_pin_failed_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `trainer_pin_locked_until_utc` datetime(3) DEFAULT NULL,
  `trainer_pin_hard_locked` tinyint(1) NOT NULL DEFAULT 0,
  `trainer_pin_last_success_at_utc` datetime(3) DEFAULT NULL,
  `trainer_pin_set_at_utc` datetime(3) DEFAULT NULL,
  `trainer_pin_set_method` enum('self','admin') DEFAULT NULL,
  `trainer_pin_set_by_user_id` int(11) DEFAULT NULL,
  `trainer_version` int(10) unsigned NOT NULL DEFAULT 0,
  `trainer_added_by` int(11) NOT NULL,
  `trainer_added_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trainer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`trainer_contact_id`),
  KEY `idx_training_trainer_user` (`trainer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_video_checks`
--

DROP TABLE IF EXISTS `training_video_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_video_checks` (
  `vcheck_id` int(11) NOT NULL AUTO_INCREMENT,
  `vcheck_provider` enum('youtube','vimeo') NOT NULL,
  `vcheck_ext_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `vcheck_ext_hash` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `vcheck_title` varchar(255) DEFAULT NULL,
  `vcheck_author` varchar(255) DEFAULT NULL,
  `vcheck_thumb_media_id` int(11) DEFAULT NULL,
  `vcheck_status` enum('ok','not_found','private','embed_disabled','live','error') DEFAULT NULL,
  `vcheck_http` smallint(5) unsigned DEFAULT NULL,
  `vcheck_meta_duration_s` int(10) unsigned DEFAULT NULL,
  `vcheck_meta_duration_source` enum('oembed','data_api') DEFAULT NULL,
  `vcheck_checked_at_utc` datetime(3) DEFAULT NULL,
  `vcheck_play_duration_s` int(10) unsigned DEFAULT NULL,
  `vcheck_verified_at_utc` datetime(3) DEFAULT NULL,
  `vcheck_verified_by` int(11) DEFAULT NULL,
  `vcheck_last_error` varchar(40) DEFAULT NULL,
  `vcheck_last_error_at_utc` datetime(3) DEFAULT NULL,
  `vcheck_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vcheck_id`),
  UNIQUE KEY `uq_training_vcheck` (`vcheck_provider`,`vcheck_ext_id`,`vcheck_ext_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_video_watch`
--

DROP TABLE IF EXISTS `training_video_watch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_video_watch` (
  `tvwatch_provider` enum('youtube','vimeo') NOT NULL,
  `tvwatch_ext_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tvwatch_ext_hash` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tvwatch_status` enum('ok','not_found','private','embed_disabled','live','duration_changed') DEFAULT NULL,
  `tvwatch_http` smallint(5) unsigned DEFAULT NULL,
  `tvwatch_oembed_duration_s` int(10) unsigned DEFAULT NULL,
  `tvwatch_bad_streak` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `tvwatch_first_bad_at_utc` datetime(3) DEFAULT NULL,
  `tvwatch_last_bad_at_utc` datetime(3) DEFAULT NULL,
  `tvwatch_checked_at_utc` datetime(3) NOT NULL,
  `tvwatch_alerted_at_utc` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`tvwatch_provider`,`tvwatch_ext_id`,`tvwatch_ext_hash`),
  KEY `idx_training_tvwatch_checked` (`tvwatch_checked_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transfers`
--

DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_method` varchar(200) DEFAULT NULL,
  `transfer_notes` text DEFAULT NULL,
  `transfer_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `transfer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `transfer_archived_at` datetime DEFAULT NULL,
  `transfer_expense_id` int(11) NOT NULL,
  `transfer_revenue_id` int(11) NOT NULL,
  PRIMARY KEY (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trips`
--

DROP TABLE IF EXISTS `trips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trips` (
  `trip_id` int(11) NOT NULL AUTO_INCREMENT,
  `trip_date` date NOT NULL,
  `trip_purpose` varchar(200) NOT NULL,
  `trip_source` varchar(200) NOT NULL,
  `trip_destination` varchar(200) NOT NULL,
  `trip_start_odometer` int(11) DEFAULT NULL,
  `trip_end_odmeter` int(11) DEFAULT NULL,
  `trip_miles` float(15,1) NOT NULL,
  `round_trip` int(1) NOT NULL,
  `trip_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trip_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `trip_archived_at` datetime DEFAULT NULL,
  `trip_user_id` int(11) NOT NULL DEFAULT 0,
  `trip_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`trip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `unifi_integrations`
--

DROP TABLE IF EXISTS `unifi_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unifi_integrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'local',
  `host` varchar(255) NOT NULL,
  `port` int(11) NOT NULL DEFAULT 443,
  `api_key_enc` text NOT NULL,
  `verify_ssl` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `unifi_site_mappings`
--

DROP TABLE IF EXISTS `unifi_site_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unifi_site_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `integration_id` int(11) NOT NULL,
  `unifi_site_id` varchar(100) NOT NULL,
  `unifi_site_name` varchar(200) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_site` (`integration_id`,`unifi_site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `unifi_sync_log`
--

DROP TABLE IF EXISTS `unifi_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unifi_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `devices_created` int(11) DEFAULT 0,
  `devices_updated` int(11) DEFAULT 0,
  `devices_matched` int(11) DEFAULT 0,
  `devices_skipped` int(11) DEFAULT 0,
  `wifi_created` int(11) DEFAULT 0,
  `wifi_updated` int(11) DEFAULT 0,
  `wifi_skipped` int(11) DEFAULT 0,
  `networks_created` int(11) DEFAULT 0,
  `networks_updated` int(11) DEFAULT 0,
  `networks_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `user_client_permissions`
--

DROP TABLE IF EXISTS `user_client_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_client_permissions` (
  `user_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_role_permissions`
--

DROP TABLE IF EXISTS `user_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_role_permissions` (
  `user_role_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `user_role_permission_level` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(200) NOT NULL,
  `role_description` varchar(200) DEFAULT NULL,
  `role_type` tinyint(1) NOT NULL DEFAULT 1,
  `role_is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `role_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `role_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `role_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_settings`
--

DROP TABLE IF EXISTS `user_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `user_config_force_mfa` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_records_per_page` int(11) NOT NULL DEFAULT 10,
  `user_config_dashboard_financial_enable` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_dashboard_financial_chart_type` varchar(20) NOT NULL DEFAULT 'line',
  `user_config_dashboard_technical_enable` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_dashboard_technical_chart_type` varchar(20) NOT NULL DEFAULT 'bar',
  `user_config_calendar_first_day` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_signature` longtext DEFAULT NULL,
  `user_config_theme_dark` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_push_types` text DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(200) NOT NULL,
  `user_title` varchar(200) DEFAULT NULL,
  `user_phone` varchar(50) DEFAULT NULL,
  `user_email` varchar(200) NOT NULL,
  `user_password` varchar(200) NOT NULL,
  `user_auth_method` varchar(200) NOT NULL DEFAULT 'local',
  `user_oidc_issuer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `user_oidc_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `user_sso_issuer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `user_sso_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `user_type` tinyint(1) NOT NULL DEFAULT 1,
  `user_status` tinyint(1) NOT NULL DEFAULT 1,
  `user_token` varchar(200) DEFAULT NULL,
  `user_password_reset_token` varchar(200) DEFAULT NULL,
  `user_password_reset_token_expire` datetime DEFAULT NULL,
  `user_avatar` varchar(200) DEFAULT NULL,
  `user_specific_encryption_ciphertext` varchar(200) DEFAULT NULL,
  `user_php_session` varchar(255) DEFAULT NULL,
  `user_extension_key` varchar(18) DEFAULT NULL,
  `user_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `user_archived_at` datetime DEFAULT NULL,
  `user_role_id` int(11) DEFAULT 0,
  `user_outlook_refresh_token` text DEFAULT NULL,
  `user_outlook_access_token` text DEFAULT NULL,
  `user_outlook_token_expires` datetime DEFAULT NULL,
  `user_color` varchar(7) DEFAULT NULL,
  `user_failed_login_count` smallint(6) NOT NULL DEFAULT 0,
  `user_failed_login_at` datetime DEFAULT NULL,
  `user_passkey_enc_ciphertext` varchar(300) DEFAULT NULL,
  `user_passkey_enc_iv` varchar(64) DEFAULT NULL,
  `user_passkey_bootstrap_key` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_users_oidc_identity` (`user_oidc_issuer`,`user_oidc_subject`),
  UNIQUE KEY `idx_users_sso_identity` (`user_sso_issuer`,`user_sso_subject`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_credentials`
--

DROP TABLE IF EXISTS `vendor_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_credentials` (
  `vendor_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `vendor_credentials_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_documents`
--

DROP TABLE IF EXISTS `vendor_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_documents` (
  `vendor_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `vendor_documents_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_files`
--

DROP TABLE IF EXISTS `vendor_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_files` (
  `vendor_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `vendor_files_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_templates`
--

DROP TABLE IF EXISTS `vendor_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_templates` (
  `vendor_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_template_name` varchar(200) NOT NULL,
  `vendor_template_description` varchar(200) DEFAULT NULL,
  `vendor_template_contact_name` varchar(200) DEFAULT NULL,
  `vendor_template_phone_country_code` varchar(10) DEFAULT NULL,
  `vendor_template_phone` varchar(200) DEFAULT NULL,
  `vendor_template_extension` varchar(200) DEFAULT NULL,
  `vendor_template_email` varchar(200) DEFAULT NULL,
  `vendor_template_website` varchar(200) DEFAULT NULL,
  `vendor_template_hours` varchar(200) DEFAULT NULL,
  `vendor_template_sla` varchar(200) DEFAULT NULL,
  `vendor_template_code` varchar(200) DEFAULT NULL,
  `vendor_template_account_number` varchar(200) DEFAULT NULL,
  `vendor_template_notes` text DEFAULT NULL,
  `vendor_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `vendor_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `vendor_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`vendor_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendors` (
  `vendor_id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_name` varchar(200) NOT NULL,
  `vendor_description` varchar(200) DEFAULT NULL,
  `vendor_contact_name` varchar(200) DEFAULT NULL,
  `vendor_phone_country_code` varchar(10) DEFAULT NULL,
  `vendor_phone` varchar(200) DEFAULT NULL,
  `vendor_extension` varchar(200) DEFAULT NULL,
  `vendor_email` varchar(200) DEFAULT NULL,
  `vendor_website` varchar(200) DEFAULT NULL,
  `vendor_hours` varchar(200) DEFAULT NULL,
  `vendor_sla` varchar(200) DEFAULT NULL,
  `vendor_code` varchar(200) DEFAULT NULL,
  `vendor_account_number` varchar(200) DEFAULT NULL,
  `vendor_notes` text DEFAULT NULL,
  `vendor_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `vendor_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `vendor_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `vendor_archived_at` datetime DEFAULT NULL,
  `vendor_accessed_at` datetime DEFAULT NULL,
  `vendor_client_id` int(11) NOT NULL DEFAULT 0,
  `vendor_template_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
--
-- Table structure for table `compliance_attestations`
--

DROP TABLE IF EXISTS `compliance_attestations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance_attestations` (
  `attestation_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` varchar(64) NOT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewer_name` varchar(200) NOT NULL,
  `reviewed_on` date NOT NULL,
  `next_due_on` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `subject_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`attestation_id`),
  KEY `idx_compliance_attest_item` (`item_id`,`attestation_id`),
  KEY `idx_compliance_attestations_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_responsibilities`
--

DROP TABLE IF EXISTS `compliance_responsibilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance_responsibilities` (
  `assign_key` varchar(120) NOT NULL,
  `party_ref` int(11) DEFAULT NULL,
  `party_name` varchar(200) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assign_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_shared_report`
--

DROP TABLE IF EXISTS `compliance_shared_report`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance_shared_report` (
  `shared_id` tinyint(4) NOT NULL,
  `snapshot_id` int(11) NOT NULL,
  `note` text DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`shared_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_snapshots`
--

DROP TABLE IF EXISTS `compliance_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance_snapshots` (
  `snapshot_id` int(11) NOT NULL AUTO_INCREMENT,
  `taken_at` datetime NOT NULL DEFAULT current_timestamp(),
  `taken_by` int(11) DEFAULT NULL,
  `trigger_type` varchar(20) NOT NULL DEFAULT 'manual',
  `app_version` varchar(40) DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `results_json` longtext DEFAULT NULL,
  `subject_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`snapshot_id`),
  KEY `idx_compliance_snapshots_taken` (`taken_at`),
  KEY `idx_compliance_snapshots_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_subjects`
--

DROP TABLE IF EXISTS `compliance_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance_subjects` (
  `subject_id` int(11) NOT NULL,
  `frameworks` varchar(100) NOT NULL DEFAULT '',
  `shared_snapshot_id` int(11) DEFAULT NULL,
  `shared_note` text DEFAULT NULL,
  `shared_by` int(11) DEFAULT NULL,
  `shared_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mcp_unlinked_identities`
--

DROP TABLE IF EXISTS `mcp_unlinked_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mcp_unlinked_identities` (
  `mcp_unlinked_id` int(11) NOT NULL AUTO_INCREMENT,
  `issuer` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mcp_unlinked_id`),
  UNIQUE KEY `uniq_mcp_identity` (`issuer`,`subject`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


--
-- Device metrics subsystem (see docs/REDESIGN_ARCHITECTURE_REPORT.md sections K-Q)
--

-- Metric catalogue: one row per canonical metric key. metric_id is a 2-byte
-- surrogate so the samples table never repeats a 40-char key on every row.
CREATE TABLE `device_metric_defs` (
  `metric_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `metric_key` varchar(64) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `unit` varchar(24) NOT NULL,
  `metric_kind` enum('gauge','counter') NOT NULL DEFAULT 'gauge',
  `value_min` double DEFAULT NULL,
  `value_max` double DEFAULT NULL,
  `metric_dim` varchar(16) DEFAULT NULL,
  `display_precision` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`metric_id`),
  UNIQUE KEY `metric_key` (`metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dimension catalogue: the per-volume / per-core / per-adapter instance a sample
-- belongs to. instance_id 0 is the reserved host-level sentinel and has no row.
CREATE TABLE `device_metric_instances` (
  `instance_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `metric_dim` varchar(16) NOT NULL,
  `instance_key` varchar(96) NOT NULL,
  `instance_label` varchar(128) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`instance_id`),
  UNIQUE KEY `asset_dim_key` (`asset_id`,`metric_dim`,`instance_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Raw samples. Deliberately NO surrogate key: the PK is the series tuple, which
-- (a) clusters every chart read - one device, one metric, one instance, one time
-- range - into a contiguous leaf-page scan with no secondary lookup, (b) doubles
-- as the idempotency key so a retried collector batch dedupes via ON DUPLICATE
-- KEY UPDATE rather than needing a nullable fingerprint column, and (c) saves
-- ~45 bytes/row over a BIGINT id plus the secondary index it would have needed.
-- At ~1,000 active series the hot right-edge leaf pages total ~16MB and stay
-- resident; this trade would be wrong at 100,000 series, not at this scale.
-- sampled_at is UTC (see MetricIngestService) - a deliberate divergence from the
-- app's local-time convention, so hour bucketing is pure field extraction.
CREATE TABLE `device_metric_samples` (
  `asset_id` int(11) NOT NULL,
  `metric_id` smallint(5) unsigned NOT NULL,
  `instance_id` int(10) unsigned NOT NULL DEFAULT 0,
  `sampled_at` datetime NOT NULL,
  `metric_value` double NOT NULL,
  PRIMARY KEY (`asset_id`,`metric_id`,`instance_id`,`sampled_at`),
  KEY `idx_samples_sampled_at` (`sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rollups carry sum+count rather than a precomputed average so a coarser tier can
-- be re-aggregated from a finer one without compounding rounding error.
CREATE TABLE `device_metric_rollups` (
  `bucket` enum('hour','day') NOT NULL,
  `asset_id` int(11) NOT NULL,
  `metric_id` smallint(5) unsigned NOT NULL,
  `instance_id` int(10) unsigned NOT NULL DEFAULT 0,
  `period_start` datetime NOT NULL,
  `min_value` double NOT NULL,
  `max_value` double NOT NULL,
  `sum_value` double NOT NULL,
  `sample_count` int(10) unsigned NOT NULL,
  PRIMARY KEY (`bucket`,`asset_id`,`metric_id`,`instance_id`,`period_start`),
  KEY `idx_rollup_period` (`bucket`,`period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Watermark so rollups are incremental and can catch up after downtime without
-- rescanning history. One row per bucket tier.
CREATE TABLE `device_metric_rollup_state` (
  `bucket` enum('hour','day') NOT NULL,
  `rolled_through` datetime NOT NULL,
  `last_run_at` datetime DEFAULT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Per-device collection bookkeeping: what we last asked the provider for, and
-- whether the device is currently reporting at all.
CREATE TABLE `device_metric_collection_state` (
  `asset_id` int(11) NOT NULL,
  `integration_id` int(11) NOT NULL,
  `last_collected_at` datetime DEFAULT NULL,
  `last_sample_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0,
  `vendor_cursor_json` text DEFAULT NULL,
  PRIMARY KEY (`asset_id`,`integration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump completed on 2026-04-04 18:13:53

--
-- Table structure for table `comet_backup_alerts`
--
DROP TABLE IF EXISTS `comet_backup_alerts`;
CREATE TABLE `comet_backup_alerts` (
  `alert_id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_comet_username` varchar(200) NOT NULL,
  `alert_device_name` varchar(200) NOT NULL,
  `alert_type` enum('failed','missed') NOT NULL DEFAULT 'failed',
  `alert_severity` varchar(20) NOT NULL DEFAULT 'critical',
  `alert_message` text DEFAULT NULL,
  `alert_client_id` int(11) DEFAULT NULL,
  `alert_ticket_id` int(11) NOT NULL,
  `alert_status` varchar(20) NOT NULL DEFAULT 'new',
  `alert_acknowledged_by` int(11) DEFAULT NULL,
  `alert_acknowledged_at` datetime DEFAULT NULL,
  `alert_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `alert_resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`alert_id`),
  KEY `alert_lookup` (`alert_comet_username`,`alert_device_name`,`alert_resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `comet_client_map`
--
DROP TABLE IF EXISTS `comet_client_map`;
CREATE TABLE `comet_client_map` (
  `map_id` int(11) NOT NULL AUTO_INCREMENT,
  `map_client_id` int(11) NOT NULL,
  `map_comet_username` varchar(200) NOT NULL,
  `map_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`map_id`),
  UNIQUE KEY `map_client_id` (`map_client_id`),
  KEY `map_comet_username` (`map_comet_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `comet_session_cache`
--
DROP TABLE IF EXISTS `comet_session_cache`;
CREATE TABLE `comet_session_cache` (
  `id` tinyint(1) NOT NULL,
  `config_value` varchar(500) NOT NULL DEFAULT '',
  `config_expires` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `contract_documents`
--
DROP TABLE IF EXISTS `contract_documents`;
CREATE TABLE `contract_documents` (
  `doc_id` int(11) NOT NULL AUTO_INCREMENT,
  `doc_contract_id` int(11) NOT NULL,
  `doc_filename` varchar(500) NOT NULL,
  `doc_original_name` varchar(500) NOT NULL,
  `doc_mime_type` varchar(100) NOT NULL DEFAULT 'application/octet-stream',
  `doc_size` int(11) NOT NULL DEFAULT 0,
  `doc_uploaded_by` int(11) NOT NULL DEFAULT 0,
  `doc_uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`doc_id`),
  KEY `doc_contract_id` (`doc_contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `credential_history`
--
DROP TABLE IF EXISTS `credential_history`;
CREATE TABLE `credential_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `history_credential_id` int(11) NOT NULL,
  `history_user_id` int(11) NOT NULL DEFAULT 0,
  `history_user_name` varchar(200) NOT NULL DEFAULT '',
  `history_field` varchar(100) NOT NULL,
  `history_old_value` text DEFAULT NULL,
  `history_new_value` text DEFAULT NULL,
  `history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `history_credential_id` (`history_credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `user_passkeys`
--
DROP TABLE IF EXISTS `user_passkeys`;
CREATE TABLE `user_passkeys` (
  `passkey_id` int(11) NOT NULL AUTO_INCREMENT,
  `passkey_user_id` int(11) NOT NULL,
  `passkey_name` varchar(200) NOT NULL DEFAULT 'Passkey',
  `passkey_credential_id` varchar(1024) NOT NULL,
  `passkey_public_key` text NOT NULL,
  `passkey_sign_count` int(11) NOT NULL DEFAULT 0,
  `passkey_aaguid` varchar(36) NOT NULL DEFAULT '',
  `passkey_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `passkey_last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`passkey_id`),
  KEY `passkey_user_id` (`passkey_user_id`),
  KEY `passkey_credential_id` (`passkey_credential_id`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `webhooks`
--
DROP TABLE IF EXISTS `webhooks`;
CREATE TABLE `webhooks` (
  `webhook_id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_name` varchar(200) NOT NULL DEFAULT '',
  `webhook_url` varchar(2048) NOT NULL,
  `webhook_secret` varchar(255) NOT NULL DEFAULT '',
  `webhook_events` varchar(4000) NOT NULL DEFAULT '',
  `webhook_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `webhook_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `webhook_type` varchar(16) NOT NULL DEFAULT 'generic',
  `webhook_min_priority` varchar(20) NOT NULL DEFAULT '',
  `webhook_client_ids` varchar(500) NOT NULL DEFAULT '',
  `webhook_destination` varchar(40) NOT NULL DEFAULT '',
  `webhook_format` varchar(24) NOT NULL DEFAULT '',
  `webhook_method` varchar(4) NOT NULL DEFAULT 'POST',
  `webhook_template` text DEFAULT NULL,
  `webhook_auth_mode` varchar(12) NOT NULL DEFAULT 'none',
  `webhook_auth_enc` text DEFAULT NULL,
  `webhook_extra` text DEFAULT NULL,
  PRIMARY KEY (`webhook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `webhook_queue`
--
DROP TABLE IF EXISTS `webhook_queue`;
CREATE TABLE `webhook_queue` (
  `queue_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_webhook_id` int(11) NOT NULL,
  `queue_event` varchar(100) NOT NULL,
  `queue_payload` longtext NOT NULL,
  `queue_status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  `queue_attempts` tinyint(3) NOT NULL DEFAULT 0,
  `queue_response_code` smallint(6) DEFAULT NULL,
  `queue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`queue_id`),
  KEY `queue_status_next` (`queue_status`,`queue_next_attempt_at`),
  KEY `queue_webhook_id` (`queue_webhook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `worksheet_template_fields`
--

DROP TABLE IF EXISTS `worksheet_template_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `worksheet_template_fields` (
  `field_id` int(11) NOT NULL AUTO_INCREMENT,
  `field_template_id` int(11) NOT NULL,
  `field_name` varchar(200) NOT NULL,
  `field_type` enum('text','textarea','checkbox','select','signature','heading') NOT NULL DEFAULT 'text',
  `field_options` text DEFAULT NULL,
  `field_order` int(11) NOT NULL DEFAULT 0,
  `field_required` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`field_id`),
  KEY `field_template_id` (`field_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `worksheet_templates`
--

DROP TABLE IF EXISTS `worksheet_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `worksheet_templates` (
  `worksheet_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `worksheet_template_name` varchar(200) NOT NULL,
  `worksheet_template_description` text DEFAULT NULL,
  `worksheet_template_created_by` int(11) NOT NULL DEFAULT 0,
  `worksheet_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `worksheet_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`worksheet_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `accounting_entity_map`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_entity_map` (
  `map_id` int(11) NOT NULL AUTO_INCREMENT,
  `map_accounting_id` int(11) NOT NULL,
  `map_local_type` varchar(20) NOT NULL,
  `map_local_id` int(11) NOT NULL,
  `map_remote_id` varchar(64) DEFAULT NULL,
  `map_remote_sync_token` varchar(32) DEFAULT NULL,
  `map_last_synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`map_id`),
  UNIQUE KEY `uniq_map` (`map_accounting_id`,`map_local_type`,`map_local_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_integrations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_integrations` (
  `accounting_id` int(11) NOT NULL AUTO_INCREMENT,
  `accounting_provider` varchar(30) NOT NULL DEFAULT 'quickbooks_online',
  `accounting_realm_id` varchar(64) DEFAULT NULL,
  `accounting_client_id` varchar(255) DEFAULT NULL,
  `accounting_client_secret` text DEFAULT NULL,
  `accounting_environment` varchar(20) NOT NULL DEFAULT 'production',
  `accounting_refresh_token` text DEFAULT NULL,
  `accounting_access_token` text DEFAULT NULL,
  `accounting_token_expires_at` datetime DEFAULT NULL,
  `accounting_default_income_account_id` varchar(64) DEFAULT NULL,
  `accounting_auto_push` tinyint(4) NOT NULL DEFAULT 1,
  `accounting_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `accounting_connected_at` datetime DEFAULT NULL,
  `accounting_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`accounting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_sync_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_sync_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `log_accounting_id` int(11) NOT NULL,
  `log_local_type` varchar(20) DEFAULT NULL,
  `log_local_id` int(11) DEFAULT NULL,
  `log_status` varchar(20) DEFAULT NULL,
  `log_message` varchar(1000) DEFAULT NULL,
  `log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_sync_queue`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_sync_queue` (
  `queue_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_accounting_id` int(11) NOT NULL,
  `queue_local_type` varchar(20) NOT NULL,
  `queue_local_id` int(11) NOT NULL,
  `queue_op` varchar(10) NOT NULL DEFAULT 'push',
  `queue_status` enum('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
  `queue_attempts` tinyint(4) NOT NULL DEFAULT 0,
  `queue_last_error` varchar(500) DEFAULT NULL,
  `queue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`queue_id`),
  UNIQUE KEY `uniq_queue` (`queue_accounting_id`,`queue_local_type`,`queue_local_id`,`queue_op`),
  KEY `idx_status_next` (`queue_status`,`queue_next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_biometric_keys`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_biometric_keys` (
  `user_id` int(11) NOT NULL,
  `device_public_key_pem` text NOT NULL,
  `key_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `key_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_passkey_challenges`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_passkey_challenges` (
  `challenge_token` varchar(64) NOT NULL,
  `challenge_b64u` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`challenge_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_activities`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_activities` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_type` varchar(20) NOT NULL,
  `activity_subject` varchar(255) DEFAULT NULL,
  `activity_body` text DEFAULT NULL,
  `activity_related_type` varchar(12) NOT NULL,
  `activity_related_id` int(11) NOT NULL,
  `activity_at` datetime DEFAULT current_timestamp(),
  `activity_due_at` datetime DEFAULT NULL,
  `activity_reminder_at` datetime DEFAULT NULL,
  `activity_completed` tinyint(4) DEFAULT 0,
  `activity_owner` int(11) DEFAULT NULL,
  `activity_created_at` datetime DEFAULT current_timestamp(),
  `activity_reminded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `activity_related` (`activity_related_type`,`activity_related_id`),
  KEY `activity_reminder` (`activity_reminder_at`,`activity_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_campaign_recipients`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_campaign_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `sent` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_campaigns`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_campaigns` (
  `campaign_id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_name` varchar(150) DEFAULT NULL,
  `campaign_segment_id` int(11) DEFAULT NULL,
  `campaign_subject` varchar(255) DEFAULT NULL,
  `campaign_body` mediumtext DEFAULT NULL,
  `campaign_status` varchar(20) DEFAULT 'draft',
  `campaign_sent_at` datetime DEFAULT NULL,
  `campaign_created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_segments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_segments` (
  `segment_id` int(11) NOT NULL AUTO_INCREMENT,
  `segment_name` varchar(150) DEFAULT NULL,
  `segment_criteria_json` text DEFAULT NULL,
  `segment_created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`segment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mailboxes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailboxes` (
  `mailbox_id` int(11) NOT NULL AUTO_INCREMENT,
  `mailbox_name` varchar(200) NOT NULL,
  `mailbox_email` varchar(200) NOT NULL,
  `mailbox_from_name` varchar(200) DEFAULT NULL,
  `mailbox_type` varchar(20) NOT NULL DEFAULT 'standard_imap',
  `mailbox_imap_host` varchar(200) DEFAULT NULL,
  `mailbox_imap_port` int(5) DEFAULT NULL,
  `mailbox_imap_encryption` varchar(20) DEFAULT NULL,
  `mailbox_imap_username` varchar(200) DEFAULT NULL,
  `mailbox_imap_password_enc` text DEFAULT NULL,
  `mailbox_oauth_refresh_token_enc` text DEFAULT NULL,
  `mailbox_oauth_access_token_enc` text DEFAULT NULL,
  `mailbox_oauth_access_token_expires_at` datetime DEFAULT NULL,
  `mailbox_parse_unknown_senders` tinyint(1) NOT NULL DEFAULT 0,
  `mailbox_default_client_id` int(11) DEFAULT NULL,
  `mailbox_active` tinyint(1) NOT NULL DEFAULT 1,
  `mailbox_last_polled_at` datetime DEFAULT NULL,
  `mailbox_order` int(11) NOT NULL DEFAULT 0,
  `mailbox_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `mailbox_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`mailbox_id`),
  KEY `idx_mailbox_active` (`mailbox_active`,`mailbox_archived_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_log` (
  `mail_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_log_mailbox_id` int(11) DEFAULT NULL,
  `mail_log_from_email` varchar(200) DEFAULT NULL,
  `mail_log_from_name` varchar(200) DEFAULT NULL,
  `mail_log_subject` varchar(500) DEFAULT NULL,
  `mail_log_outcome` varchar(30) NOT NULL,
  `mail_log_detail` varchar(500) DEFAULT NULL,
  `mail_log_ticket_id` int(11) DEFAULT NULL,
  `mail_log_mail_request_id` int(11) DEFAULT NULL,
  `mail_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mail_log_id`),
  KEY `idx_mail_log_created` (`mail_log_created_at`),
  KEY `idx_mail_log_mailbox` (`mail_log_mailbox_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_request_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_request_attachments` (
  `mail_request_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_request_attachment_name` varchar(255) NOT NULL,
  `mail_request_attachment_reference_name` varchar(255) NOT NULL,
  `mail_request_attachment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `mail_request_attachment_mail_request_id` int(11) NOT NULL,
  PRIMARY KEY (`mail_request_attachment_id`),
  KEY `idx_mail_request_attachment_request` (`mail_request_attachment_mail_request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mail_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_requests` (
  `mail_request_id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_request_mailbox_id` int(11) NOT NULL,
  `mail_request_from_email` varchar(200) NOT NULL,
  `mail_request_from_name` varchar(200) DEFAULT NULL,
  `mail_request_subject` varchar(500) NOT NULL DEFAULT '',
  `mail_request_body` longtext DEFAULT NULL,
  `mail_request_ccs` text DEFAULT NULL,
  `mail_request_received_at` datetime NOT NULL,
  `mail_request_eml_reference_name` varchar(255) DEFAULT NULL,
  `mail_request_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `mail_request_converted_ticket_id` int(11) DEFAULT NULL,
  `mail_request_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`mail_request_id`),
  KEY `idx_mail_request_pending` (`mail_request_archived_at`,`mail_request_mailbox_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `opportunities`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `opportunities` (
  `opportunity_id` int(11) NOT NULL AUTO_INCREMENT,
  `opportunity_name` varchar(255) NOT NULL,
  `opportunity_client_id` int(11) DEFAULT NULL,
  `opportunity_contact_id` int(11) DEFAULT NULL,
  `opportunity_stage` varchar(40) NOT NULL DEFAULT 'Qualification',
  `opportunity_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opportunity_probability` tinyint(4) NOT NULL DEFAULT 0,
  `opportunity_close_date` date DEFAULT NULL,
  `opportunity_status` varchar(12) NOT NULL DEFAULT 'open',
  `opportunity_owner` int(11) DEFAULT NULL,
  `opportunity_notes` text DEFAULT NULL,
  `opportunity_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `opportunity_updated_at` datetime DEFAULT NULL,
  `opportunity_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`opportunity_id`),
  KEY `opportunity_client_id` (`opportunity_client_id`),
  KEY `opportunity_stage` (`opportunity_stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_webhook_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_provider_id` int(11) NOT NULL,
  `event_provider_ref` varchar(255) NOT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `event_status` enum('received','processed','ignored','error') NOT NULL DEFAULT 'received',
  `event_payload` longtext DEFAULT NULL,
  `event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_provider_ref` (`event_provider_id`,`event_provider_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_deduction_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_deduction_categories` (
  `payroll_deduction_category_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_deduction_category_name` varchar(100) NOT NULL,
  `payroll_deduction_category_description` varchar(255) DEFAULT NULL,
  `payroll_deduction_category_order` int(11) NOT NULL DEFAULT 0,
  `payroll_deduction_category_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payroll_deduction_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_employee_deductions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_employee_deductions` (
  `payroll_employee_deduction_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_employee_deduction_user_id` int(11) NOT NULL,
  `payroll_employee_deduction_category_id` int(11) NOT NULL,
  `payroll_employee_deduction_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_employee_deduction_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_employee_deduction_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payroll_employee_deduction_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payroll_employee_deduction_id`),
  KEY `idx_payroll_employee_deduction_user` (`payroll_employee_deduction_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_hours_entries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_hours_entries` (
  `payroll_hours_entry_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_hours_entry_user_id` int(11) NOT NULL,
  `payroll_hours_entry_week_start_date` date NOT NULL,
  `payroll_hours_entry_worked_time` time NOT NULL DEFAULT '00:00:00',
  `payroll_hours_entry_source` enum('manual','api') NOT NULL DEFAULT 'manual',
  `payroll_hours_entry_note` varchar(255) DEFAULT NULL,
  `payroll_hours_entry_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_hours_entry_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`payroll_hours_entry_id`),
  UNIQUE KEY `uq_payroll_hours_entry` (`payroll_hours_entry_user_id`,`payroll_hours_entry_week_start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_job_hours_entries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_job_hours_entries` (
  `payroll_job_hours_entry_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_job_hours_entry_period_id` int(11) NOT NULL,
  `payroll_job_hours_entry_user_id` int(11) NOT NULL,
  `payroll_job_hours_entry_ticket_id` int(11) NOT NULL,
  `payroll_job_hours_entry_worked_time` time NOT NULL DEFAULT '00:00:00',
  `payroll_job_hours_entry_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_job_hours_entry_note` varchar(255) DEFAULT NULL,
  `payroll_job_hours_entry_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_job_hours_entry_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`payroll_job_hours_entry_id`),
  KEY `idx_payroll_job_hours_entry_period` (`payroll_job_hours_entry_period_id`),
  KEY `idx_payroll_job_hours_entry_user` (`payroll_job_hours_entry_user_id`),
  KEY `idx_payroll_job_hours_entry_ticket` (`payroll_job_hours_entry_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_pay_rates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_pay_rates` (
  `payroll_pay_rate_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_pay_rate_user_id` int(11) NOT NULL,
  `payroll_pay_rate_type` enum('hourly','salary') NOT NULL DEFAULT 'hourly',
  `payroll_pay_rate_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_pay_rate_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_pay_rate_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payroll_pay_rate_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payroll_pay_rate_id`),
  KEY `idx_payroll_pay_rate_user` (`payroll_pay_rate_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_periods` (
  `payroll_period_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_period_start_date` date NOT NULL,
  `payroll_period_end_date` date NOT NULL,
  `payroll_period_status` enum('open','locked') NOT NULL DEFAULT 'open',
  `payroll_period_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_period_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payroll_period_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payroll_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_run_line_item_deductions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_run_line_item_deductions` (
  `payroll_run_line_item_deduction_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_line_item_deduction_line_item_id` int(11) NOT NULL,
  `payroll_run_line_item_deduction_category_id` int(11) DEFAULT NULL,
  `payroll_run_line_item_deduction_category_name_snapshot` varchar(200) NOT NULL,
  `payroll_run_line_item_deduction_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`payroll_run_line_item_deduction_id`),
  KEY `idx_payroll_run_line_item_deduction_item` (`payroll_run_line_item_deduction_line_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_run_line_item_jobs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_run_line_item_jobs` (
  `payroll_run_line_item_job_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_line_item_job_line_item_id` int(11) NOT NULL,
  `payroll_run_line_item_job_ticket_id` int(11) DEFAULT NULL,
  `payroll_run_line_item_job_ticket_label_snapshot` varchar(255) NOT NULL,
  `payroll_run_line_item_job_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_job_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_job_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`payroll_run_line_item_job_id`),
  KEY `idx_payroll_run_line_item_job_item` (`payroll_run_line_item_job_line_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_run_line_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_run_line_items` (
  `payroll_run_line_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_line_item_run_id` int(11) NOT NULL,
  `payroll_run_line_item_user_id` int(11) NOT NULL,
  `payroll_run_line_item_employee_name_snapshot` varchar(200) NOT NULL,
  `payroll_run_line_item_pay_type_snapshot` enum('hourly','salary') NOT NULL,
  `payroll_run_line_item_rate_snapshot` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_overtime_threshold_snapshot` decimal(6,2) NOT NULL DEFAULT 40.00,
  `payroll_run_line_item_overtime_multiplier_snapshot` decimal(4,2) NOT NULL DEFAULT 1.50,
  `payroll_run_line_item_regular_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_overtime_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_regular_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_overtime_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_gross_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_net_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_currency_code` varchar(200) NOT NULL DEFAULT 'USD',
  `payroll_run_line_item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_run_line_item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payroll_run_line_item_job_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `payroll_run_line_item_job_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`payroll_run_line_item_id`),
  KEY `idx_payroll_run_line_item_run` (`payroll_run_line_item_run_id`),
  KEY `idx_payroll_run_line_item_user` (`payroll_run_line_item_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_runs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_runs` (
  `payroll_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_period_id` int(11) NOT NULL,
  `payroll_run_status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `payroll_run_total_gross` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_total_net` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payroll_run_currency_code` varchar(200) NOT NULL DEFAULT 'USD',
  `payroll_run_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payroll_run_created_by` int(11) NOT NULL,
  `payroll_run_finalized_at` datetime DEFAULT NULL,
  `payroll_run_finalized_by` int(11) DEFAULT NULL,
  `payroll_run_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payroll_run_id`),
  KEY `idx_payroll_run_period` (`payroll_run_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_milestones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_milestones` (
  `milestone_id` int(11) NOT NULL AUTO_INCREMENT,
  `milestone_project_id` int(11) NOT NULL,
  `milestone_name` varchar(255) NOT NULL,
  `milestone_description` text DEFAULT NULL,
  `milestone_due` date DEFAULT NULL,
  `milestone_order` int(11) NOT NULL DEFAULT 0,
  `milestone_status` varchar(30) NOT NULL DEFAULT 'open',
  `milestone_completed_at` datetime DEFAULT NULL,
  `milestone_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`milestone_id`),
  KEY `milestone_project_id` (`milestone_project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_task_dependencies`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_task_dependencies` (
  `dependency_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `predecessor_id` int(11) NOT NULL,
  `dependency_type` varchar(10) NOT NULL DEFAULT 'FS',
  PRIMARY KEY (`dependency_id`),
  UNIQUE KEY `task_id` (`task_id`,`predecessor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `report_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_report` varchar(60) NOT NULL,
  `schedule_frequency` varchar(20) NOT NULL,
  `schedule_recipients` text DEFAULT NULL,
  `schedule_last_sent` datetime DEFAULT NULL,
  `schedule_active` tinyint(4) DEFAULT 1,
  `schedule_created_at` datetime DEFAULT current_timestamp(),
  `schedule_saved_report_id` int(11) DEFAULT NULL,
  `schedule_format` varchar(8) NOT NULL DEFAULT 'html',
  `schedule_owner_user_id` int(11) DEFAULT NULL,
  `schedule_last_run_at` datetime DEFAULT NULL,
  `schedule_last_status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `saved_reports`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `saved_reports` (
  `saved_report_id` int(11) NOT NULL AUTO_INCREMENT,
  `saved_report_user_id` int(11) NOT NULL,
  `saved_report_key` varchar(60) NOT NULL,
  `saved_report_name` varchar(100) NOT NULL,
  `saved_report_params` text DEFAULT NULL,
  `saved_report_shared` tinyint(1) NOT NULL DEFAULT 0,
  `saved_report_created_at` datetime DEFAULT current_timestamp(),
  `saved_report_updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`saved_report_id`),
  KEY `saved_report_user_key` (`saved_report_user_id`,`saved_report_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `report_exports`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_exports` (
  `export_id` int(11) NOT NULL AUTO_INCREMENT,
  `export_token_hash` char(64) NOT NULL,
  `export_schedule_id` int(11) DEFAULT NULL,
  `export_filename` varchar(150) NOT NULL,
  `export_content` longtext NOT NULL,
  `export_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `export_expires_at` datetime NOT NULL,
  PRIMARY KEY (`export_id`),
  UNIQUE KEY `export_token_hash` (`export_token_hash`),
  KEY `export_expires_at` (`export_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dashboard_layouts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dashboard_layouts` (
  `layout_user_id` int(11) NOT NULL,
  `layout_widgets` text DEFAULT NULL,
  `layout_updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`layout_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_business_hours`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_business_hours` (
  `calendar_id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_name` varchar(150) NOT NULL,
  `calendar_timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `calendar_is_default` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`calendar_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_business_hours_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_business_hours_periods` (
  `period_id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL,
  `open_time` time NOT NULL,
  `close_time` time NOT NULL,
  PRIMARY KEY (`period_id`),
  KEY `calendar_id` (`calendar_id`,`day_of_week`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_holidays`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_holidays` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`holiday_id`),
  KEY `calendar_id` (`calendar_id`,`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_policies`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_policies` (
  `policy_id` int(11) NOT NULL AUTO_INCREMENT,
  `policy_name` varchar(150) NOT NULL,
  `policy_calendar_id` int(11) DEFAULT NULL,
  `policy_pause_status_ids` varchar(255) DEFAULT NULL,
  `policy_low_response` int(11) DEFAULT NULL,
  `policy_low_resolution` int(11) DEFAULT NULL,
  `policy_medium_response` int(11) DEFAULT NULL,
  `policy_medium_resolution` int(11) DEFAULT NULL,
  `policy_high_response` int(11) DEFAULT NULL,
  `policy_high_resolution` int(11) DEFAULT NULL,
  `policy_is_default` tinyint(4) NOT NULL DEFAULT 0,
  `policy_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`policy_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_ai_summaries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_ai_summaries` (
  `ticket_id` int(11) NOT NULL,
  `summary_html` text DEFAULT NULL,
  `based_on_reply_id` int(11) DEFAULT NULL,
  `model_used` varchar(120) DEFAULT NULL,
  `tokens` int(11) DEFAULT NULL,
  `stale` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uq_ticket_ai_summaries_ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_metrics_daily`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_metrics_daily` (
  `metric_date` date NOT NULL,
  `company_id` int(11) NOT NULL,
  `opened` int(11) DEFAULT 0,
  `resolved` int(11) DEFAULT 0,
  `closed` int(11) DEFAULT 0,
  `backlog_open` int(11) DEFAULT 0,
  UNIQUE KEY `uniq_company_date` (`company_id`,`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_ticket_id` int(11) NOT NULL,
  `schedule_start` datetime NOT NULL,
  `schedule_end` datetime DEFAULT NULL,
  `schedule_onsite` tinyint(1) DEFAULT 0,
  `schedule_tech_id` int(11) DEFAULT 0,
  `schedule_notes` text DEFAULT NULL,
  `schedule_created_by` int(11) DEFAULT 0,
  `schedule_created_at` datetime DEFAULT current_timestamp(),
  `schedule_archived_at` datetime DEFAULT NULL,
  `schedule_outlook_event_id` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_ticket` (`schedule_ticket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_sla_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_sla_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `event_type` varchar(20) NOT NULL,
  `from_status` int(11) DEFAULT NULL,
  `to_status` int(11) DEFAULT NULL,
  `event_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  KEY `ticket_id` (`ticket_id`,`event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_techs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_techs` (
  `tech_id` int(11) NOT NULL AUTO_INCREMENT,
  `tech_ticket_id` int(11) NOT NULL,
  `tech_user_id` int(11) NOT NULL,
  `tech_created_by` int(11) DEFAULT 0,
  `tech_created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`tech_id`),
  UNIQUE KEY `uq_ticket_user` (`tech_ticket_id`,`tech_user_id`),
  KEY `idx_ticket` (`tech_ticket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `microsoft_integrations`
--

DROP TABLE IF EXISTS `microsoft_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `microsoft_integrations` (
  `microsoft_integration_id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(100) DEFAULT NULL,
  `client_id` varchar(100) DEFAULT NULL,
  `client_secret_enc` text DEFAULT NULL,
  `sync_scope` varchar(20) NOT NULL DEFAULT 'read_only',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `intune_sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `directory_sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_test_at` datetime DEFAULT NULL,
  `last_test_success` tinyint(1) DEFAULT NULL,
  `last_test_error` varchar(500) DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_directory_sync_at` datetime DEFAULT NULL,
  `token_cache_enc` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `last_test_error_code` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`microsoft_integration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `odoo_integrations`
--

DROP TABLE IF EXISTS `odoo_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `odoo_integrations` (
  `odoo_integration_id` int(11) NOT NULL AUTO_INCREMENT,
  `base_url` varchar(255) DEFAULT NULL,
  `database_name` varchar(100) DEFAULT NULL,
  `username` varchar(200) DEFAULT NULL,
  `api_key_enc` text DEFAULT NULL,
  `api_protocol` varchar(10) NOT NULL DEFAULT 'jsonrpc',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_test_at` datetime DEFAULT NULL,
  `last_test_success` tinyint(1) DEFAULT NULL,
  `last_test_error` varchar(500) DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `sso_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sso_client_id` varchar(200) DEFAULT NULL,
  `sso_secret_enc` text DEFAULT NULL,
  `sso_company_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`odoo_integration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_assignments`
--

DROP TABLE IF EXISTS `asset_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `returned_at` datetime DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `returned_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`assignment_id`),
  KEY `idx_asset_assignments_asset` (`asset_id`,`returned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_templates`
--

DROP TABLE IF EXISTS `workflow_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_templates` (
  `workflow_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `type` enum('onboarding','offboarding') NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`workflow_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_template_tasks`
--

DROP TABLE IF EXISTS `workflow_template_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_template_tasks` (
  `template_task_id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_template_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `default_owner` varchar(100) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `task_type` enum('manual','approval','action') NOT NULL DEFAULT 'manual',
  `depends_on` varchar(255) DEFAULT NULL,
  `assignee_user_id` int(11) DEFAULT NULL,
  `due_offset_days` int(11) DEFAULT NULL,
  `due_anchor` enum('run','start','end') NOT NULL DEFAULT 'run',
  `approver_type` enum('user','role','manager') DEFAULT NULL,
  `approver_user_id` int(11) DEFAULT NULL,
  `approver_role_id` int(11) DEFAULT NULL,
  `action_type` varchar(40) DEFAULT NULL,
  `action_config` text DEFAULT NULL,
  PRIMARY KEY (`template_task_id`),
  KEY `idx_template_task_template` (`workflow_template_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_runs`
--

DROP TABLE IF EXISTS `workflow_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_runs` (
  `run_id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_template_id` int(11) DEFAULT NULL,
  `contact_id` int(11) NOT NULL,
  `type` enum('onboarding','offboarding') NOT NULL,
  `status` enum('in_progress','completed_with_exceptions','completed','cancelled','paused') NOT NULL DEFAULT 'in_progress',
  `started_by` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  KEY `idx_workflow_runs_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_run_tasks`
--

DROP TABLE IF EXISTS `workflow_run_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_tasks` (
  `run_task_id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `default_owner` varchar(100) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','completed','skipped','blocked','running','action_failed','rejected') NOT NULL DEFAULT 'pending',
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `skip_reason` varchar(500) DEFAULT NULL,
  `template_task_id` int(11) DEFAULT NULL,
  `task_type` enum('manual','approval','action') NOT NULL DEFAULT 'manual',
  `depends_on` varchar(255) DEFAULT NULL,
  `assignee_user_id` int(11) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `reminder_state` varchar(12) DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `approver_type` enum('user','role','manager') DEFAULT NULL,
  `approver_user_id` int(11) DEFAULT NULL,
  `approver_role_id` int(11) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT NULL,
  `approval_notified_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_comment` varchar(500) DEFAULT NULL,
  `action_type` varchar(40) DEFAULT NULL,
  `action_config` text DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `running_since` datetime DEFAULT NULL,
  `secret_result_enc` text DEFAULT NULL,
  `secret_user_id` int(11) DEFAULT NULL,
  `secret_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`run_task_id`),
  KEY `idx_run_task_run` (`run_id`,`sort_order`),
  KEY `idx_run_task_due` (`status`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_task_log`
--

DROP TABLE IF EXISTS `workflow_task_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_task_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `run_task_id` int(11) NOT NULL,
  `event` varchar(30) NOT NULL,
  `action_type` varchar(40) DEFAULT NULL,
  `ok` tinyint(1) NOT NULL DEFAULT 1,
  `attempt` int(11) NOT NULL DEFAULT 0,
  `detail` varchar(1000) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_task_log_run` (`run_id`,`run_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credential_versions`
--

DROP TABLE IF EXISTS `credential_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_versions` (
  `version_id` int(11) NOT NULL AUTO_INCREMENT,
  `version_credential_id` int(11) NOT NULL,
  `version_changed_by` int(11) NOT NULL DEFAULT 0,
  `version_changed_by_name` varchar(200) NOT NULL DEFAULT '',
  `version_previous_username_enc` varbinary(500) DEFAULT NULL,
  `version_previous_password_enc` varbinary(500) DEFAULT NULL,
  `version_changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version_id`),
  KEY `idx_credential_versions_credential` (`version_credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kb_article_versions`
--

DROP TABLE IF EXISTS `kb_article_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_article_versions` (
  `kb_article_version_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_version_kb_article_id` int(11) NOT NULL,
  `kb_article_version_content` mediumtext DEFAULT NULL,
  `kb_article_version_content_raw` mediumtext DEFAULT NULL,
  `kb_article_version_edited_by` int(11) DEFAULT NULL,
  `kb_article_version_edited_at` datetime DEFAULT current_timestamp(),
  `kb_article_version_number` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`kb_article_version_id`),
  KEY `kb_article_version_kb_article_id` (`kb_article_version_kb_article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_catalog_items`
--

DROP TABLE IF EXISTS `service_catalog_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_catalog_items` (
  `catalog_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `ticket_subject_template` varchar(500) DEFAULT NULL,
  `ticket_category_id` int(11) DEFAULT NULL,
  `default_priority` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `risk_score` int(11) NOT NULL DEFAULT 0,
  `auto_approve_below` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`catalog_item_id`),
  KEY `ticket_category_id` (`ticket_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_catalog_fields`
--

DROP TABLE IF EXISTS `service_catalog_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_catalog_fields` (
  `field_id` int(11) NOT NULL AUTO_INCREMENT,
  `catalog_item_id` int(11) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `label` varchar(200) NOT NULL,
  `field_type` varchar(20) NOT NULL DEFAULT 'text',
  `options` text DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `placeholder` varchar(200) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `show_if` text DEFAULT NULL,
  PRIMARY KEY (`field_id`),
  UNIQUE KEY `uq_catalog_field_key` (`catalog_item_id`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_catalog_approval_steps`
--

DROP TABLE IF EXISTS `service_catalog_approval_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_catalog_approval_steps` (
  `step_id` int(11) NOT NULL AUTO_INCREMENT,
  `catalog_item_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 1,
  `approver_type` varchar(20) NOT NULL DEFAULT 'user',
  `approver_id` int(11) DEFAULT NULL,
  `mode` varchar(10) NOT NULL DEFAULT 'any',
  PRIMARY KEY (`step_id`),
  KEY `idx_catalog_step_item` (`catalog_item_id`,`step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_catalog_requests`
--

DROP TABLE IF EXISTS `service_catalog_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_catalog_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `catalog_item_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `contact_id` int(11) NOT NULL DEFAULT 0,
  `requested_by_user_id` int(11) NOT NULL DEFAULT 0,
  `field_values` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'not_required',
  `current_step` int(11) NOT NULL DEFAULT 0,
  `risk_score` int(11) NOT NULL DEFAULT 0,
  `rejection_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_catalog_request_ticket` (`ticket_id`),
  KEY `idx_catalog_request_item` (`catalog_item_id`),
  KEY `idx_catalog_request_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_catalog_request_approvals`
--

DROP TABLE IF EXISTS `service_catalog_request_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_catalog_request_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `step_mode` varchar(10) NOT NULL DEFAULT 'any',
  `approver_user_id` int(11) DEFAULT NULL,
  `approver_contact_id` int(11) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`approval_id`),
  KEY `idx_catalog_approval_request` (`request_id`,`step_order`),
  KEY `idx_catalog_approval_user` (`approver_user_id`,`status`),
  KEY `idx_catalog_approval_contact` (`approver_contact_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `changes`
--

DROP TABLE IF EXISTS `changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `changes` (
  `change_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `impact` text DEFAULT NULL,
  `risk` enum('low','medium','high') NOT NULL DEFAULT 'low',
  `implementation_plan` text DEFAULT NULL,
  `rollback_plan` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `status` enum('draft','awaiting_approval','approved','scheduled','in_progress','successful','failed','rolled_back','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`change_id`),
  KEY `idx_changes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `printers`
--

DROP TABLE IF EXISTS `printers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `printers` (
  `printer_id` int(11) NOT NULL AUTO_INCREMENT,
  `printer_client_id` int(11) NOT NULL DEFAULT 0,
  `printer_location_id` int(11) NOT NULL DEFAULT 0,
  `printer_name` varchar(200) NOT NULL,
  `printer_ip_address` varchar(200) DEFAULT NULL,
  `printer_physical_location` varchar(200) DEFAULT NULL,
  `printer_model` varchar(200) DEFAULT NULL,
  `printer_serial_number` varchar(200) DEFAULT NULL,
  `printer_mac_address` varchar(200) DEFAULT NULL,
  `printer_notes` text DEFAULT NULL,
  `printer_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `printer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `printer_archived_at` datetime DEFAULT NULL,
  `printer_created_by` int(11) NOT NULL DEFAULT 0,
  `printer_updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`printer_id`),
  KEY `idx_printers_client_archived` (`printer_client_id`,`printer_archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `problems`
--

DROP TABLE IF EXISTS `problems`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `problems` (
  `problem_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
  `change_problem_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`problem_id`),
  KEY `idx_problems_status` (`status`),
  KEY `idx_problems_change` (`change_problem_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webhook_deliveries`
--

DROP TABLE IF EXISTS `webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_deliveries` (
  `delivery_id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) NOT NULL,
  `event_type` varchar(150) NOT NULL,
  `http_status` smallint(6) DEFAULT NULL,
  `duration_ms` int(11) NOT NULL DEFAULT 0,
  `attempt_number` tinyint(3) NOT NULL DEFAULT 1,
  `request_payload_json` longtext DEFAULT NULL,
  `response_body_snippet` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`delivery_id`),
  KEY `idx_webhook_deliveries_webhook` (`webhook_id`,`created_at`),
  KEY `idx_webhook_deliveries_event` (`event_type`,`created_at`),
  KEY `idx_webhook_deliveries_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `slack_interactive_seen`
--

DROP TABLE IF EXISTS `slack_interactive_seen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `slack_interactive_seen` (
  `sig_hash` char(64) NOT NULL,
  `seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sig_hash`),
  KEY `idx_slack_seen_at` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_rules`
--

DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `trigger_event` varchar(150) NOT NULL,
  `condition_json` text DEFAULT NULL,
  `action_type` enum('create_ticket','send_webhook','notify_user','start_workflow','set_ticket_field','add_ticket_note','assign_ticket','send_mail','create_task') NOT NULL,
  `action_config_json` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `priority` int(11) NOT NULL DEFAULT 100,
  `stop_on_match` tinyint(1) NOT NULL DEFAULT 0,
  `rate_limit_per_min` int(11) NOT NULL DEFAULT 30,
  `rr_cursor` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rule_id`),
  KEY `idx_automation_rules_trigger` (`trigger_event`,`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_rule_runs`
--

DROP TABLE IF EXISTS `automation_rule_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rule_runs` (
  `run_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `event_type` varchar(150) NOT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL,
  `actions_json` text DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `duration_ms` int(11) NOT NULL DEFAULT 0,
  `chain_id` varchar(32) DEFAULT NULL,
  `chain_depth` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`run_id`),
  KEY `idx_automation_runs_rule` (`rule_id`,`created_at`),
  KEY `idx_automation_runs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_sla_marks`
--

DROP TABLE IF EXISTS `automation_sla_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_sla_marks` (
  `ticket_id` int(11) NOT NULL,
  `kind` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_id`,`kind`),
  KEY `idx_automation_sla_marks_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_intune_links`
--

DROP TABLE IF EXISTS `asset_intune_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_intune_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `microsoft_integration_id` int(11) NOT NULL,
  `intune_device_id` varchar(100) NOT NULL,
  `azure_ad_device_id` varchar(100) DEFAULT NULL,
  `hostname` varchar(200) DEFAULT NULL,
  `serial_number` varchar(200) DEFAULT NULL,
  `os_name` varchar(100) DEFAULT NULL,
  `os_version` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `model` varchar(200) DEFAULT NULL,
  `management_agent` varchar(100) DEFAULT NULL,
  `compliance_state` varchar(50) DEFAULT NULL,
  `is_encrypted` tinyint(1) DEFAULT NULL,
  `primary_user_upn` varchar(200) DEFAULT NULL,
  `enrolled_at` datetime DEFAULT NULL,
  `intune_last_sync_at` datetime DEFAULT NULL,
  `last_sync` datetime DEFAULT NULL,
  `raw_data_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_integration` (`asset_id`,`microsoft_integration_id`),
  KEY `intune_device_id` (`intune_device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `intune_sync_log`
--

DROP TABLE IF EXISTS `intune_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `intune_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `microsoft_integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `devices_created` int(11) DEFAULT 0,
  `devices_updated` int(11) DEFAULT 0,
  `devices_matched` int(11) DEFAULT 0,
  `devices_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `error_code` varchar(40) DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_odoo_links`
--

DROP TABLE IF EXISTS `client_odoo_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_odoo_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `odoo_integration_id` int(11) NOT NULL,
  `odoo_department_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_integration` (`client_id`,`odoo_integration_id`),
  KEY `odoo_department_id` (`odoo_department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_odoo_links`
--

DROP TABLE IF EXISTS `contact_odoo_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_odoo_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_id` int(11) NOT NULL,
  `odoo_integration_id` int(11) NOT NULL,
  `odoo_employee_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_integration` (`contact_id`,`odoo_integration_id`),
  KEY `odoo_employee_id` (`odoo_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_odoo_attributes`
--

DROP TABLE IF EXISTS `contact_odoo_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_odoo_attributes` (
  `coattr_contact_id` int(11) NOT NULL,
  `coattr_odoo_integration_id` int(11) NOT NULL,
  `coattr_odoo_employee_id` int(11) NOT NULL,
  `coattr_odoo_name` varchar(200) DEFAULT NULL,
  `coattr_odoo_target_sha` char(64) DEFAULT NULL,
  `coattr_job_id` int(11) DEFAULT NULL,
  `coattr_job_name` varchar(200) DEFAULT NULL,
  `coattr_work_location_id` int(11) DEFAULT NULL,
  `coattr_work_location_name` varchar(200) DEFAULT NULL,
  `coattr_odoo_create_date` date DEFAULT NULL,
  `coattr_odoo_pin_ok` tinyint(1) DEFAULT NULL,
  `coattr_attrs_synced_at_utc` datetime(3) DEFAULT NULL,
  `coattr_pin_synced_at_utc` datetime(3) DEFAULT NULL,
  `coattr_link_state` enum('unchecked','ok','repointed','mismatch','missing') NOT NULL DEFAULT 'unchecked',
  `coattr_link_detail` varchar(255) DEFAULT NULL,
  `coattr_link_seen_name` varchar(200) DEFAULT NULL,
  `coattr_link_suggested_employee_id` int(11) DEFAULT NULL,
  `coattr_link_checked_at_utc` datetime(3) DEFAULT NULL,
  `coattr_link_confirmed_by` int(11) DEFAULT NULL,
  `coattr_link_confirmed_at_utc` datetime(3) DEFAULT NULL,
  `coattr_link_dismissed_by` int(11) DEFAULT NULL,
  `coattr_link_dismissed_at_utc` datetime(3) DEFAULT NULL,
  `coattr_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`coattr_contact_id`),
  KEY `idx_coattr_job` (`coattr_job_id`),
  KEY `idx_coattr_location` (`coattr_work_location_id`),
  KEY `idx_coattr_employee` (`coattr_odoo_integration_id`,`coattr_odoo_employee_id`),
  KEY `idx_coattr_state` (`coattr_link_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `odoo_sync_log`
--

DROP TABLE IF EXISTS `odoo_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `odoo_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `odoo_integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `departments_created` int(11) DEFAULT 0,
  `departments_updated` int(11) DEFAULT 0,
  `departments_matched` int(11) DEFAULT 0,
  `departments_skipped` int(11) DEFAULT 0,
  `employees_created` int(11) DEFAULT 0,
  `employees_updated` int(11) DEFAULT 0,
  `employees_matched` int(11) DEFAULT 0,
  `employees_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `directory_field_mappings`
--

DROP TABLE IF EXISTS `directory_field_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `directory_field_mappings` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(20) NOT NULL,
  `source_field` varchar(60) NOT NULL,
  `target_field` varchar(60) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `provider_source_field` (`provider`,`source_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Seed rows so a fresh install's Odoo field mapping starts out identical to
-- what OdooDirectoryMapper::syncEmployee() hardcoded before this table
-- existed - see DB update 2.6.80 in admin/database_updates.php, which
-- inserts these same four rows for an install that is upgrading rather
-- than installing fresh from this file.
INSERT INTO `directory_field_mappings`
  (`provider`, `source_field`, `target_field`, `enabled`) VALUES
  ('odoo', 'job_title', 'contact_title', 1),
  ('odoo', 'work_phone', 'contact_phone', 1),
  ('odoo', 'mobile_phone', 'contact_mobile', 1),
  ('odoo', 'work_email', 'contact_email', 1);

--
-- Table structure for table `google_integrations`
--

DROP TABLE IF EXISTS `google_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_integrations` (
  `google_integration_id` int(11) NOT NULL AUTO_INCREMENT,
  `service_account_json_enc` text DEFAULT NULL,
  `delegated_admin_email` varchar(255) DEFAULT NULL,
  `workspace_domain` varchar(255) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_test_at` datetime DEFAULT NULL,
  `last_test_success` tinyint(1) DEFAULT NULL,
  `last_test_error` varchar(500) DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`google_integration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kb_article_progress`
--

DROP TABLE IF EXISTS `kb_article_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_article_progress` (
  `kb_article_progress_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_progress_kb_article_id` int(11) NOT NULL,
  `kb_article_progress_block_key` varchar(24) NOT NULL,
  `kb_article_progress_part_key` varchar(24) NOT NULL,
  `kb_article_progress_principal_type` char(1) NOT NULL,
  `kb_article_progress_principal_id` int(11) NOT NULL,
  `kb_article_progress_state` smallint(6) NOT NULL DEFAULT 0,
  `kb_article_progress_part_hash` char(16) NOT NULL DEFAULT '',
  `kb_article_progress_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kb_article_progress_id`),
  UNIQUE KEY `kb_article_progress_unique` (`kb_article_progress_kb_article_id`,`kb_article_progress_block_key`,`kb_article_progress_part_key`,`kb_article_progress_principal_type`,`kb_article_progress_principal_id`),
  KEY `kb_article_progress_reader` (`kb_article_progress_kb_article_id`,`kb_article_progress_principal_type`,`kb_article_progress_principal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `google_sync_log`
--

DROP TABLE IF EXISTS `google_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `google_integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `departments_created` int(11) DEFAULT 0,
  `departments_updated` int(11) DEFAULT 0,
  `departments_matched` int(11) DEFAULT 0,
  `departments_skipped` int(11) DEFAULT 0,
  `employees_created` int(11) DEFAULT 0,
  `employees_updated` int(11) DEFAULT 0,
  `employees_matched` int(11) DEFAULT 0,
  `employees_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `microsoft_directory_sync_log`
--

DROP TABLE IF EXISTS `microsoft_directory_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `microsoft_directory_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `microsoft_integration_id` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'running',
  `departments_created` int(11) DEFAULT 0,
  `departments_updated` int(11) DEFAULT 0,
  `departments_matched` int(11) DEFAULT 0,
  `departments_skipped` int(11) DEFAULT 0,
  `employees_created` int(11) DEFAULT 0,
  `employees_updated` int(11) DEFAULT 0,
  `employees_matched` int(11) DEFAULT 0,
  `employees_skipped` int(11) DEFAULT 0,
  `errors` text DEFAULT NULL,
  `triggered_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kb_article_embeds`
--
-- kb_article_embed_untrusted_html is the ONLY column in this database that
-- holds HTML no filter ever touched. It is never purified and never echoed
-- into an app page; it reaches a browser only through agent/kb_embed.php and
-- client/kb_embed.php, which serve it into an opaque-origin sandbox under
-- their own Content-Security-Policy. See agent/includes/kb_embed_serve.php.
--

DROP TABLE IF EXISTS `kb_article_embeds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_article_embeds` (
  `kb_article_embed_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_embed_kb_article_id` int(11) NOT NULL,
  `kb_article_embed_name` varchar(255) NOT NULL,
  `kb_article_embed_untrusted_html` longtext DEFAULT NULL,
  `kb_article_embed_text` mediumtext DEFAULT NULL,
  `kb_article_embed_sha256` char(64) NOT NULL DEFAULT '',
  `kb_article_embed_height` int(11) NOT NULL DEFAULT 480,
  `kb_article_embed_created_by` int(11) NOT NULL DEFAULT 0,
  `kb_article_embed_created_at` datetime DEFAULT current_timestamp(),
  `kb_article_embed_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`kb_article_embed_id`),
  KEY `kb_article_embed_kb_article_id` (`kb_article_embed_kb_article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Built-in endpoint agent / RMM module (server side), DB 2.6.145; the tables are owned by RivetCore migrations 0014 to 0016 since DB 2.6.147
--

CREATE TABLE IF NOT EXISTS `endpoint_agent_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `service_url` varchar(500) NOT NULL DEFAULT '',
  `integration_id` int(11) NOT NULL DEFAULT 0,
  `check_in_interval_s` int(11) NOT NULL DEFAULT 300,
  `collect_interval_s` int(11) NOT NULL DEFAULT 60,
  `offline_after_s` int(11) NOT NULL DEFAULT 900,
  `stale_after_s` int(11) NOT NULL DEFAULT 604800,
  `failure_debounce` int(11) NOT NULL DEFAULT 3,
  `recovery_debounce` int(11) NOT NULL DEFAULT 2,
  `retention_days` int(11) NOT NULL DEFAULT 30,
  `job_retention_days` int(11) NOT NULL DEFAULT 180,
  `job_output_max_bytes` int(11) NOT NULL DEFAULT 65536,
  `job_default_timeout_s` int(11) NOT NULL DEFAULT 300,
  `job_max_timeout_s` int(11) NOT NULL DEFAULT 3600,
  `job_expiry_s` int(11) NOT NULL DEFAULT 3600,
  `job_ack_timeout_s` int(11) NOT NULL DEFAULT 120,
  `job_max_attempts` int(11) NOT NULL DEFAULT 3,
  `enroll_max_ttl_h` int(11) NOT NULL DEFAULT 72,
  `unmatched_policy` varchar(20) NOT NULL DEFAULT 'approval',
  `checks_json` text DEFAULT NULL,
  `signing_key_id` varchar(32) NOT NULL DEFAULT '',
  `signing_public_key` varchar(100) NOT NULL DEFAULT '',
  `signing_private_key_enc` text DEFAULT NULL,
  `signing_key_created_at` datetime DEFAULT NULL,
  `mesh_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mesh_url` varchar(500) NOT NULL DEFAULT '',
  `mesh_domain` varchar(100) NOT NULL DEFAULT '',
  `mesh_login_key_enc` text DEFAULT NULL,
  `mesh_account_template` varchar(100) NOT NULL DEFAULT 'rivetit-support',
  `mesh_policy` varchar(20) NOT NULL DEFAULT 'unattended',
  `mesh_token_ttl_s` int(11) NOT NULL DEFAULT 300,
  `coexistence_policy` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ca_pem` text DEFAULT NULL,
  `features_json` text DEFAULT NULL,
  `limits_json` text DEFAULT NULL,
  `shed_level` tinyint(1) NOT NULL DEFAULT 0,
  `ingest_mode` varchar(10) NOT NULL DEFAULT 'sync',
  `max_devices` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_enrollment_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `token_selector` char(12) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `client_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `expires_at` datetime NOT NULL,
  `max_uses` int(11) NOT NULL DEFAULT 1,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uniq_selector` (`token_selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_enroll_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) NOT NULL,
  `ip_text` varchar(64) NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(40) NOT NULL DEFAULT '',
  `token_selector` varchar(12) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_ip_time` (`ip_hash`,`attempted_at`),
  KEY `idx_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_devices` (
  `device_id` int(11) NOT NULL AUTO_INCREMENT,
  `install_id` char(36) NOT NULL,
  `machine_guid` varchar(64) DEFAULT NULL,
  `hostname` varchar(200) NOT NULL DEFAULT '',
  `os` varchar(20) NOT NULL DEFAULT 'windows',
  `os_version` varchar(200) NOT NULL DEFAULT '',
  `arch` varchar(10) NOT NULL DEFAULT '',
  `serial` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `model` varchar(200) DEFAULT NULL,
  `mac_addresses` text DEFAULT NULL,
  `agent_version` varchar(40) NOT NULL DEFAULT '',
  `asset_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `link_state` varchar(20) NOT NULL DEFAULT 'pending_approval',
  `match_reason` varchar(60) NOT NULL DEFAULT '',
  `match_candidates_json` text DEFAULT NULL,
  `token_hash` char(64) NOT NULL DEFAULT '',
  `token_issued_at` datetime DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` varchar(100) DEFAULT NULL,
  `retired_at` datetime DEFAULT NULL,
  `enrolled_via_token_id` int(11) DEFAULT NULL,
  `enroll_count` int(11) NOT NULL DEFAULT 1,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_checkin_at` datetime DEFAULT NULL,
  `last_collected_at` datetime DEFAULT NULL,
  `last_inventory_at` datetime DEFAULT NULL,
  `last_ip` varchar(64) DEFAULT NULL,
  `last_seq` bigint(20) NOT NULL DEFAULT 0,
  `inventory_json` mediumtext DEFAULT NULL,
  `last_metrics_json` text DEFAULT NULL,
  `logged_in_user` varchar(200) DEFAULT NULL,
  `pending_reboot` tinyint(1) DEFAULT NULL,
  `uptime_s` bigint(20) DEFAULT NULL,
  `update_state_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `uniq_install` (`install_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_machine_guid` (`machine_guid`),
  KEY `idx_serial` (`serial`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_checkins` (
  `device_id` int(11) NOT NULL,
  `seq` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `collected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`seq`),
  KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_checks` (
  `device_id` int(11) NOT NULL,
  `check_key` varchar(100) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'unknown',
  `detail` varchar(500) NOT NULL DEFAULT '',
  `consecutive_failures` int(11) NOT NULL DEFAULT 0,
  `consecutive_ok` int(11) NOT NULL DEFAULT 0,
  `episode` int(11) NOT NULL DEFAULT 0,
  `alert_id` int(11) DEFAULT NULL,
  `last_reported_at` datetime DEFAULT NULL,
  `last_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_jobs` (
  `job_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL,
  `script` mediumtext DEFAULT NULL,
  `params_json` text DEFAULT NULL,
  `timeout_s` int(11) NOT NULL DEFAULT 300,
  `max_output_bytes` int(11) NOT NULL DEFAULT 65536,
  `destructive` tinyint(1) NOT NULL DEFAULT 0,
  `run_as` varchar(40) NOT NULL DEFAULT 'SYSTEM',
  `state` varchar(12) NOT NULL DEFAULT 'queued',
  `reason` varchar(60) DEFAULT NULL,
  `attempt` int(11) NOT NULL DEFAULT 1,
  `offered_count` int(11) NOT NULL DEFAULT 0,
  `last_offered_at` datetime DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `exit_code` int(11) DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `output_truncated` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`job_id`),
  KEY `idx_device_state` (`device_id`,`state`),
  KEY `idx_state_updated` (`state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_mesh_nodes` (
  `device_id` int(11) NOT NULL,
  `mesh_node_id` varchar(200) NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'manual',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`device_id`),
  KEY `idx_node` (`mesh_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `endpoint_agent_releases` (
  `release_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `url` varchar(500) NOT NULL,
  `sha256` char(64) NOT NULL,
  `min_version` varchar(40) NOT NULL DEFAULT '0.0.0',
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `rollout_pct` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `arch` varchar(10) NOT NULL DEFAULT '',
  `binary_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`release_id`),
  UNIQUE KEY `uniq_version_ring_arch` (`version`,`ring`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Endpoint agent binary hosting and per-department installers, DB 2.6.146
--

CREATE TABLE IF NOT EXISTS `endpoint_agent_binaries` (
  `binary_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `arch` varchar(10) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `storage_name` varchar(64) NOT NULL,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`binary_id`),
  UNIQUE KEY `uniq_version_arch` (`version`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `endpoint_agent_settings` (`id`) VALUES (1);
