-- Database backup
-- Database: regdum_credentials
-- Generated: 2026-09-04 21:48:07
-- Tables: academic_programs, api_keys, api_request_logs, app_settings, appointments, audit_logs, campuses, chat_messages, clearance_departments, course_prospectuses, database_backups, document_requirements, document_type_enrollment_rules, document_type_requirement_defaults, document_types, faqs, feedback, notifications, payments, prospectus_subjects, request_assigned_requirements, request_authentication_items, request_clearances, request_compliance, request_compliance_summary, request_documents, request_items, request_purpose_document_suggestions, request_purpose_enrollment_settings, request_purposes, request_status_history, requests, requirement_definitions, requirement_subcategories, roles, schema_migrations, student_profiles, student_subject_grades, users

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

DROP TABLE IF EXISTS `academic_programs`;
CREATE TABLE `academic_programs` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'BSIT', 'BS Information Technology', 'Information Technology program', '1', '1', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('9', 'BSCRIM', 'BS Criminology', NULL, '1', '0', '2026-07-28 15:59:42', '2026-07-28 15:59:42');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('10', 'BSISM', 'BS Industrial Security Management', NULL, '1', '0', '2026-07-28 16:00:04', '2026-07-28 16:00:04');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('12', 'BSA', 'BS Agriculture', NULL, '1', '0', '2026-07-28 16:00:57', '2026-07-28 16:00:57');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('13', 'BEED', 'Bachelor of Elementary Education (General)', NULL, '1', '0', '2026-07-28 16:02:00', '2026-07-28 16:03:22');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('14', 'BSEDENGL', 'Bachelor of Secondary Education (English)', NULL, '1', '0', '2026-07-28 16:02:31', '2026-07-28 16:03:14');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('15', 'BSEDMATH', 'Bachelor of Secondary Education (Math)', NULL, '1', '0', '2026-07-28 16:03:01', '2026-07-28 16:03:01');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('17', 'BAELS', 'Bachelor of Arts in English Language Studies', NULL, '1', '0', '2026-08-05 13:38:36', '2026-08-05 13:38:36');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('18', 'BSEDFIL', 'Bachelor of Secondary Education (Filipino)', NULL, '1', '0', '2026-08-05 13:39:09', '2026-08-05 13:39:09');

DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `key_prefix` varchar(12) NOT NULL,
  `key_hash` varchar(255) NOT NULL,
  `key_encrypted` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_api_keys_prefix` (`key_prefix`),
  KEY `idx_api_keys_active` (`is_active`),
  KEY `fk_api_keys_created_by` (`created_by`),
  CONSTRAINT `fk_api_keys_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `api_request_logs`;
CREATE TABLE `api_request_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `api_key_id` int(10) unsigned DEFAULT NULL,
  `endpoint` varchar(120) NOT NULL,
  `http_method` varchar(10) NOT NULL DEFAULT 'GET',
  `query_string` text DEFAULT NULL,
  `status_code` smallint(5) unsigned NOT NULL,
  `response_ok` tinyint(1) NOT NULL DEFAULT 0,
  `result_count` int(10) unsigned DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `response_time_ms` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_request_logs_created` (`created_at`),
  KEY `idx_api_request_logs_key` (`api_key_id`),
  KEY `idx_api_request_logs_status` (`status_code`),
  CONSTRAINT `fk_api_request_logs_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('active_school_year', '2026-2027', '2026-09-04 21:47:43');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('active_semester', '1st_semester', '2026-09-04 21:47:43');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('auto_apply_requirement_defaults', '1', '2026-07-27 10:13:13');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('bank_transfer_details', '{\"bank_name\":\"Landbank of the Philippines\",\"account_name\":\"J.H. Cerilles State College\",\"account_number\":\"2842-1046-64\",\"branch\":\"JHCSC Dumingag Campus\",\"instructions\":\"\"}', '2026-07-27 16:44:52');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('external_api_cors_origins', '', '2026-09-04 21:47:06');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('external_api_enabled', '0', '2026-09-03 21:57:04');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('external_api_encryption_secret', '77931cbf1c89da9f2607868ab7543826b7cc4eeb8325819cf93aeb7c81467ef2', '2026-09-03 21:57:04');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('other_enrollment_requirements_opt_in_migrated', '1', '2026-07-27 16:41:30');

DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` enum('scheduled','confirmed','completed','cancelled','no_show') DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', '4', 'logout', 'users', '4', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:08:29');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', '4', 'login', 'users', '4', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:08:34');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', '4', 'logout', 'users', '4', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:09:58');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', '1', 'login', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:10:08');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('6', '1', 'logout', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:12:46');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('7', '4', 'login', 'users', '4', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:12:52');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('8', '4', 'logout', 'users', '4', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:14:51');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('9', '1', 'login', 'users', '1', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:14:56');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('10', '1', 'database_backup_create', 'database_backup', '1', NULL, '{\"type\":\"full\",\"filename\":\"full_full_database_backup_20260904_211813.sql\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:18:14');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('11', '1', 'database_backup_create', 'database_backup', '2', NULL, '{\"type\":\"custom\",\"tables\":[\"academic_programs\",\"api_keys\",\"api_request_logs\",\"app_settings\",\"appointments\",\"audit_logs\",\"campuses\",\"chat_messages\",\"clearance_departments\",\"course_prospectuses\",\"database_backups\",\"document_requirements\",\"document_type_enrollment_rules\",\"document_type_requirement_defaults\",\"document_types\",\"faqs\",\"feedback\",\"notifications\",\"payments\",\"prospectus_subjects\",\"request_assigned_requirements\",\"request_authentication_items\",\"request_clearances\",\"request_compliance\",\"request_compliance_summary\",\"request_documents\",\"request_items\",\"request_purpose_document_suggestions\",\"request_purpose_enrollment_settings\",\"request_purposes\",\"request_status_history\",\"requests\",\"requirement_definitions\",\"requirement_subcategories\",\"roles\",\"schema_migrations\",\"student_profiles\",\"student_subject_grades\",\"users\"],\"filename\":\"custom_backup_20260904_211910.sql\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:19:11');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('12', NULL, 'database_backup_delete', 'database_backup', '3', '{\"label\":\"Debug Restore Point\",\"filename\":\"restore_point_debug_restore_point_20260904_212201.sql\",\"type\":\"restore_point\"}', NULL, NULL, NULL, '2026-09-04 21:22:02');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('13', '1', 'database_restore_point_create', 'database_backup', '4', NULL, '{\"label\":\"rspoint\",\"filename\":\"restore_point_rspoint_20260904_212316.sql\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:23:16');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('14', '1', 'update_active_academic_term', 'app_settings', NULL, '{\"active_school_year\":\"2027-2028\",\"active_semester\":\"1st_semester\"}', '{\"active_school_year\":\"2026-2027\",\"active_semester\":\"1st_semester\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:47:43');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('15', '1', 'database_backup_delete', 'database_backup', '4', '{\"label\":\"rspoint\",\"filename\":\"restore_point_rspoint_20260904_212316.sql\",\"type\":\"restore_point\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:48:00');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('16', '1', 'database_backup_delete', 'database_backup', '2', '{\"label\":\"backup\",\"filename\":\"custom_backup_20260904_211910.sql\",\"type\":\"custom\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:48:02');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES ('17', '1', 'database_backup_delete', 'database_backup', '1', '{\"label\":\"Full Database Backup\",\"filename\":\"full_full_database_backup_20260904_211813.sql\",\"type\":\"full\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 21:48:04');

DROP TABLE IF EXISTS `campuses`;
CREATE TABLE `campuses` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `campuses` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'MAIN', 'Main Campus', 'Central registrar campus', '1', '1', '2026-07-27 10:13:07', '2026-07-27 10:13:07');
INSERT INTO `campuses` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', 'NORTH', 'North Campus', 'Northern extension campus', '0', '2', '2026-07-27 10:13:07', '2026-07-27 17:58:46');
INSERT INTO `campuses` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', 'SOUTH', 'South Campus', 'Southern extension campus', '0', '3', '2026-07-27 10:13:07', '2026-07-27 17:58:50');
INSERT INTO `campuses` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('4', 'DUM', 'Dumingag Campus', NULL, '1', '1', '2026-07-27 17:59:12', '2026-07-27 17:59:12');

DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `message` text NOT NULL,
  `is_staff` tinyint(1) DEFAULT 0,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `clearance_departments`;
CREATE TABLE `clearance_departments` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `icon` varchar(50) DEFAULT 'fa-check',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=256 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `clearance_departments` (`id`, `code`, `name`, `icon`, `sort_order`, `is_active`, `created_at`) VALUES ('1', 'guidance', 'Guidance Office', 'fa-hands-helping', '1', '1', '2026-07-27 10:13:14');
INSERT INTO `clearance_departments` (`id`, `code`, `name`, `icon`, `sort_order`, `is_active`, `created_at`) VALUES ('2', 'library', 'Library', 'fa-book', '2', '1', '2026-07-27 10:13:14');
INSERT INTO `clearance_departments` (`id`, `code`, `name`, `icon`, `sort_order`, `is_active`, `created_at`) VALUES ('3', 'student_affairs', 'Student Affairs', 'fa-users', '3', '1', '2026-07-27 10:13:14');
INSERT INTO `clearance_departments` (`id`, `code`, `name`, `icon`, `sort_order`, `is_active`, `created_at`) VALUES ('4', 'program_chair', 'Program Chair', 'fa-chalkboard-teacher', '4', '1', '2026-07-27 10:13:14');
INSERT INTO `clearance_departments` (`id`, `code`, `name`, `icon`, `sort_order`, `is_active`, `created_at`) VALUES ('5', 'campus_director', 'Campus Director', 'fa-user-tie', '5', '1', '2026-07-27 10:13:14');

DROP TABLE IF EXISTS `course_prospectuses`;
CREATE TABLE `course_prospectuses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int(10) unsigned NOT NULL,
  `curriculum_year` varchar(20) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prospectus_program_year` (`program_id`,`curriculum_year`),
  KEY `idx_prospectus_program` (`program_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `course_prospectuses` (`id`, `program_id`, `curriculum_year`, `title`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '9', '2026-2027', 'BS Criminology Course Prospectus', '1', NULL, '2026-09-03 22:13:04', '2026-09-03 22:13:04');

DROP TABLE IF EXISTS `database_backups`;
CREATE TABLE `database_backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `backup_type` enum('full','custom','restore_point') NOT NULL,
  `filename` varchar(255) NOT NULL,
  `tables_json` text DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_database_backups_filename` (`filename`),
  KEY `idx_database_backups_type` (`backup_type`),
  KEY `idx_database_backups_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `document_requirements`;
CREATE TABLE `document_requirements` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` tinyint(3) unsigned DEFAULT NULL,
  `requirement_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `document_type_id` (`document_type_id`),
  CONSTRAINT `document_requirements_ibfk_1` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('1', NULL, 'Valid School ID or Government ID', 'Upload a clear copy of valid identification', '1', '1', '2026-07-27 10:13:19');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('2', NULL, 'Student identity verified', 'Requester matches official student records', '1', '2', '2026-07-27 10:13:19');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('3', NULL, 'Complete request information', 'All required form fields are filled out correctly', '1', '3', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('4', NULL, 'Purpose of request indicated', 'Valid purpose selected and supported if required', '1', '4', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('5', '1', 'Supporting academic records uploaded', 'Previous TOR or grade slips if applicable', '1', '5', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('6', '2', 'Graduation clearance', 'Proof of graduation or clearance certificate', '1', '5', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('7', '8', 'Original document for certification', 'Copy of document to be authenticated', '1', '5', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('8', '9', 'Supporting documents uploaded', 'Relevant documents for the requested record', '1', '5', '2026-07-27 10:13:20');
INSERT INTO `document_requirements` (`id`, `document_type_id`, `requirement_name`, `description`, `is_required`, `sort_order`, `created_at`) VALUES ('9', NULL, 'Online clearance completed (all offices)', 'Guidance, Library, Student Affairs, Program Chair, and Campus Director must sign clearance', '1', '10', '2026-07-27 10:13:24');

DROP TABLE IF EXISTS `document_type_enrollment_rules`;
CREATE TABLE `document_type_enrollment_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` tinyint(3) unsigned NOT NULL,
  `enrollment_status` enum('enrolled','graduated','inactive') NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `max_copies` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_enrollment` (`document_type_id`,`enrollment_status`),
  CONSTRAINT `document_type_enrollment_rules_ibfk_1` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=184 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('1', '3', 'graduated', '0', '1', '2026-07-27 10:13:25', '2026-07-27 10:13:25');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('2', '3', 'enrolled', '1', '1', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('3', '3', 'inactive', '1', '1', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('4', '6', 'graduated', '1', '1', '2026-07-27 10:13:26', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('5', '6', 'enrolled', '0', '1', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('6', '6', 'inactive', '0', '1', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('7', '4', 'graduated', '0', '1', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('8', '4', 'enrolled', '1', '1', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('9', '4', 'inactive', '1', '1', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('10', '8', 'graduated', '1', '10', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('11', '8', 'enrolled', '1', '10', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('12', '8', 'inactive', '1', '10', '2026-07-27 10:13:26', '2026-07-27 15:04:28');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('13', '2', 'graduated', '1', '1', '2026-07-27 10:13:26', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('14', '2', 'enrolled', '0', '1', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('15', '2', 'inactive', '0', '1', '2026-07-27 10:13:26', '2026-07-27 10:13:26');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('16', '7', 'graduated', '1', '1', '2026-07-27 10:13:26', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('17', '7', 'enrolled', '1', '1', '2026-07-27 10:13:26', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('18', '7', 'inactive', '1', '1', '2026-07-27 10:13:26', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('19', '9', 'graduated', '1', '1', '2026-07-27 10:13:26', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('20', '9', 'enrolled', '1', '1', '2026-07-27 10:13:27', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('21', '9', 'inactive', '1', '1', '2026-07-27 10:13:27', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('22', '5', 'graduated', '0', '1', '2026-07-27 10:13:27', '2026-07-27 10:13:27');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('23', '5', 'enrolled', '1', '1', '2026-07-27 10:13:27', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('24', '5', 'inactive', '1', '1', '2026-07-27 10:13:27', '2026-07-28 11:53:03');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('25', '1', 'graduated', '1', '2', '2026-07-27 10:13:27', '2026-07-28 13:48:35');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('26', '1', 'enrolled', '1', '2', '2026-07-27 10:13:27', '2026-07-28 13:48:35');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('27', '1', 'inactive', '1', '2', '2026-07-27 10:13:27', '2026-07-28 13:48:35');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('82', '10', 'graduated', '1', '1', '2026-07-28 13:42:54', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('83', '10', 'enrolled', '1', '1', '2026-07-28 13:42:54', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('84', '10', 'inactive', '1', '1', '2026-07-28 13:42:54', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('118', '11', 'graduated', '1', '1', '2026-07-28 13:49:23', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('119', '11', 'enrolled', '1', '1', '2026-07-28 13:49:23', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('120', '11', 'inactive', '1', '1', '2026-07-28 13:49:23', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('124', '12', 'graduated', '1', '1', '2026-07-28 13:49:56', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('125', '12', 'enrolled', '1', '1', '2026-07-28 13:49:56', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('126', '12', 'inactive', '1', '1', '2026-07-28 13:49:56', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('130', '13', 'graduated', '1', '1', '2026-07-28 13:51:01', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('131', '13', 'enrolled', '1', '1', '2026-07-28 13:51:01', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('132', '13', 'inactive', '1', '1', '2026-07-28 13:51:01', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('136', '14', 'graduated', '1', '1', '2026-07-28 13:53:16', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('137', '14', 'enrolled', '1', '1', '2026-07-28 13:53:16', '2026-07-28 13:54:46');
INSERT INTO `document_type_enrollment_rules` (`id`, `document_type_id`, `enrollment_status`, `is_allowed`, `max_copies`, `created_at`, `updated_at`) VALUES ('138', '14', 'inactive', '1', '1', '2026-07-28 13:53:16', '2026-07-28 13:54:46');

DROP TABLE IF EXISTS `document_type_requirement_defaults`;
CREATE TABLE `document_type_requirement_defaults` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` tinyint(3) unsigned NOT NULL,
  `requirement_code` varchar(50) NOT NULL,
  `copy_request_type` enum('first_request','second_copy') NOT NULL DEFAULT 'first_request',
  `is_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_type_requirement_copy` (`document_type_id`,`requirement_code`,`copy_request_type`),
  KEY `idx_doc_type_requirement_defaults_doc` (`document_type_id`),
  CONSTRAINT `document_type_requirement_defaults_ibfk_1` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('70', '5', 'online_clearance', 'first_request', '1', '2026-07-27 14:44:30', '2026-07-27 14:44:30');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('71', '5', 'final_clearance', 'first_request', '1', '2026-07-27 14:44:30', '2026-07-27 14:44:30');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('73', '5', 'online_clearance', 'second_copy', '1', '2026-07-27 14:44:30', '2026-07-27 14:44:30');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('74', '5', 'final_clearance', 'second_copy', '1', '2026-07-27 14:44:30', '2026-07-27 14:44:30');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('76', '5', 'affidavit_second_copy', 'second_copy', '1', '2026-07-27 14:44:30', '2026-07-27 14:44:30');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('77', '3', 'online_clearance', 'first_request', '1', '2026-07-27 14:45:11', '2026-07-27 14:45:11');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('78', '3', 'final_clearance', 'first_request', '1', '2026-07-27 14:45:11', '2026-07-27 14:45:11');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('80', '3', 'online_clearance', 'second_copy', '1', '2026-07-27 14:45:11', '2026-07-27 14:45:11');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('81', '3', 'final_clearance', 'second_copy', '1', '2026-07-27 14:45:11', '2026-07-27 14:45:11');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('83', '3', 'affidavit_second_copy', 'second_copy', '1', '2026-07-27 14:45:11', '2026-07-27 14:45:11');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('84', '4', 'online_clearance', 'first_request', '1', '2026-07-27 14:45:23', '2026-07-27 14:45:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('85', '4', 'final_clearance', 'first_request', '1', '2026-07-27 14:45:23', '2026-07-27 14:45:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('87', '4', 'online_clearance', 'second_copy', '1', '2026-07-27 14:45:23', '2026-07-27 14:45:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('88', '4', 'final_clearance', 'second_copy', '1', '2026-07-27 14:45:23', '2026-07-27 14:45:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('90', '4', 'affidavit_second_copy', 'second_copy', '1', '2026-07-27 14:45:23', '2026-07-27 14:45:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('96', '8', 'online_clearance', 'first_request', '1', '2026-07-28 11:55:12', '2026-07-28 11:55:12');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('97', '8', 'final_clearance', 'first_request', '1', '2026-07-28 11:55:12', '2026-07-28 11:55:12');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('98', '8', 'online_clearance', 'second_copy', '1', '2026-07-28 11:55:12', '2026-07-28 11:55:12');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('99', '8', 'final_clearance', 'second_copy', '1', '2026-07-28 11:55:12', '2026-07-28 11:55:12');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('100', '8', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:55:12', '2026-07-28 11:55:12');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('104', '6', 'thesis_distribution_list', 'first_request', '1', '2026-07-28 11:56:07', '2026-07-28 11:56:07');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('105', '6', 'final_clearance', 'first_request', '1', '2026-07-28 11:56:07', '2026-07-28 11:56:07');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('106', '6', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:56:07', '2026-07-28 11:56:07');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('113', '2', 'thesis_distribution_list', 'first_request', '1', '2026-07-28 11:56:37', '2026-07-28 11:56:37');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('114', '2', 'final_clearance', 'first_request', '1', '2026-07-28 11:56:37', '2026-07-28 11:56:37');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('115', '2', 'final_clearance', 'second_copy', '1', '2026-07-28 11:56:37', '2026-07-28 11:56:37');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('116', '2', 'thesis_distribution_list', 'second_copy', '1', '2026-07-28 11:56:37', '2026-07-28 11:56:37');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('118', '2', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:56:37', '2026-07-28 11:56:37');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('125', '7', 'online_clearance', 'first_request', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('126', '7', 'thesis_distribution_list', 'first_request', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('127', '7', 'final_clearance', 'first_request', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('128', '7', 'final_clearance', 'second_copy', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('129', '7', 'online_clearance', 'second_copy', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('130', '7', 'thesis_distribution_list', 'second_copy', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('131', '7', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:57:53', '2026-07-28 11:57:53');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('132', '9', 'online_clearance', 'first_request', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('133', '9', 'thesis_distribution_list', 'first_request', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('134', '9', 'final_clearance', 'first_request', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('135', '9', 'other_enrollment_requirements', 'first_request', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('136', '9', 'affidavit_second_copy', 'first_request', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('137', '9', 'online_clearance', 'second_copy', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('138', '9', 'thesis_distribution_list', 'second_copy', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('139', '9', 'final_clearance', 'second_copy', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('140', '9', 'other_enrollment_requirements', 'second_copy', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('141', '9', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:58:08', '2026-07-28 11:58:08');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('142', '1', 'online_clearance', 'first_request', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('143', '1', 'thesis_distribution_list', 'first_request', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('144', '1', 'final_clearance', 'first_request', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('145', '1', 'final_clearance', 'second_copy', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('146', '1', 'online_clearance', 'second_copy', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('147', '1', 'thesis_distribution_list', 'second_copy', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('148', '1', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 11:58:32', '2026-07-28 11:58:32');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('149', '6', 'final_clearance', 'second_copy', '1', '2026-07-28 13:27:56', '2026-07-28 13:27:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('150', '10', 'online_clearance', 'first_request', '1', '2026-07-28 13:42:54', '2026-07-28 13:42:54');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('151', '10', 'final_clearance', 'first_request', '1', '2026-07-28 13:42:54', '2026-07-28 13:42:54');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('152', '10', 'online_clearance', 'second_copy', '1', '2026-07-28 13:42:54', '2026-07-28 13:42:54');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('153', '10', 'final_clearance', 'second_copy', '1', '2026-07-28 13:42:54', '2026-07-28 13:42:54');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('154', '10', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 13:42:54', '2026-07-28 13:42:54');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('155', '11', 'online_clearance', 'first_request', '1', '2026-07-28 13:49:23', '2026-07-28 13:49:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('156', '11', 'final_clearance', 'first_request', '1', '2026-07-28 13:49:23', '2026-07-28 13:49:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('157', '11', 'online_clearance', 'second_copy', '1', '2026-07-28 13:49:23', '2026-07-28 13:49:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('158', '11', 'final_clearance', 'second_copy', '1', '2026-07-28 13:49:23', '2026-07-28 13:49:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('159', '11', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 13:49:23', '2026-07-28 13:49:23');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('160', '12', 'online_clearance', 'first_request', '1', '2026-07-28 13:49:56', '2026-07-28 13:49:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('161', '12', 'final_clearance', 'first_request', '1', '2026-07-28 13:49:56', '2026-07-28 13:49:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('162', '12', 'online_clearance', 'second_copy', '1', '2026-07-28 13:49:56', '2026-07-28 13:49:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('163', '12', 'final_clearance', 'second_copy', '1', '2026-07-28 13:49:56', '2026-07-28 13:49:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('164', '12', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 13:49:56', '2026-07-28 13:49:56');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('165', '13', 'online_clearance', 'first_request', '1', '2026-07-28 13:51:01', '2026-07-28 13:51:01');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('166', '13', 'final_clearance', 'first_request', '1', '2026-07-28 13:51:01', '2026-07-28 13:51:01');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('167', '13', 'online_clearance', 'second_copy', '1', '2026-07-28 13:51:01', '2026-07-28 13:51:01');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('168', '13', 'final_clearance', 'second_copy', '1', '2026-07-28 13:51:01', '2026-07-28 13:51:01');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('169', '13', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 13:51:01', '2026-07-28 13:51:01');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('170', '14', 'online_clearance', 'first_request', '1', '2026-07-28 13:53:16', '2026-07-28 13:53:16');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('171', '14', 'final_clearance', 'first_request', '1', '2026-07-28 13:53:16', '2026-07-28 13:53:16');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('172', '14', 'online_clearance', 'second_copy', '1', '2026-07-28 13:53:16', '2026-07-28 13:53:16');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('173', '14', 'final_clearance', 'second_copy', '1', '2026-07-28 13:53:16', '2026-07-28 13:53:16');
INSERT INTO `document_type_requirement_defaults` (`id`, `document_type_id`, `requirement_code`, `copy_request_type`, `is_enabled`, `created_at`, `updated_at`) VALUES ('174', '14', 'affidavit_second_copy', 'second_copy', '1', '2026-07-28 13:53:16', '2026-07-28 13:53:16');

DROP TABLE IF EXISTS `document_types`;
CREATE TABLE `document_types` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `base_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `per_copy_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `processing_days` int(11) DEFAULT 3,
  `requires_upload` tinyint(1) DEFAULT 0,
  `requires_documentary_stamp` tinyint(1) NOT NULL DEFAULT 0,
  `fee_per_set` tinyint(1) NOT NULL DEFAULT 0,
  `requires_term_info` tinyint(1) NOT NULL DEFAULT 0,
  `requires_soa_info` tinyint(1) NOT NULL DEFAULT 0,
  `requires_auth_document_type` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `requirements_required` tinyint(1) NOT NULL DEFAULT 1,
  `second_copy_requirements_required` tinyint(1) NOT NULL DEFAULT 1,
  `assignment_office` varchar(30) NOT NULL DEFAULT 'registrar',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('1', 'Transcript of Records (TOR)', 'TOR', 'Official academic transcript', '150.00', '0.00', '3', '0', '1', '0', '0', '0', '0', '1', '1', '0', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('2', 'Diploma', 'DIPLOMA', 'Official diploma copy', '100.00', '0.00', '30', '0', '0', '0', '0', '0', '0', '1', '1', '0', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('3', 'Certificate of Enrollment', 'COE', 'Proof of current enrollment', '50.00', '0.00', '1', '0', '1', '0', '1', '0', '0', '1', '0', '0', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('4', 'Certificate of Grades', 'COG', 'Official certificate of grades for a specific school year and semester', '50.00', '0.00', '1', '0', '1', '0', '1', '0', '0', '1', '0', '0', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('5', 'Statement of Account', 'SOA', 'Official statement of account for a specific school year and semester', '50.00', '0.00', '1', '0', '1', '0', '1', '1', '0', '1', '0', '0', 'accounting', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('6', 'Certificate of Graduation', 'COGR', 'Proof of graduation', '50.00', '0.00', '1', '0', '1', '0', '1', '0', '0', '1', '1', '1', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('7', 'Good Moral Certificate', 'GMC', 'Certificate of good moral character', '50.00', '0.00', '1', '0', '1', '0', '0', '0', '0', '1', '1', '0', 'guidance', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('8', 'Authentication/Certified True Copy', 'CTC', 'Certified true copy of documents', '40.00', '0.00', '1', '1', '0', '1', '0', '0', '1', '1', '0', '0', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('9', 'Other Academic Records', 'OTHER', 'Other academic documents', '75.00', '25.00', '5', '1', '0', '0', '0', '0', '0', '0', '1', '1', 'registrar', '2026-07-27 10:13:07');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('10', 'Eligibility for Transfer / HD', 'HD', NULL, '100.00', '0.00', '3', '0', '1', '0', '0', '0', '0', '1', '1', '1', 'registrar', '2026-07-28 13:42:54');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('11', 'CAV Local', 'CAVLOCAL', NULL, '50.00', '0.00', '1', '0', '1', '0', '0', '0', '0', '1', '1', '1', 'registrar', '2026-07-28 13:49:23');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('12', 'CAV Abroad (DFA Apostile)', 'CAVABROAD', NULL, '50.00', '0.00', '3', '0', '1', '0', '0', '0', '0', '1', '1', '1', 'registrar', '2026-07-28 13:49:56');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('13', 'General Weighted Average (GWA)', 'GWA', NULL, '50.00', '0.00', '1', '0', '1', '0', '0', '0', '0', '1', '1', '1', 'registrar', '2026-07-28 13:51:01');
INSERT INTO `document_types` (`id`, `name`, `code`, `description`, `base_fee`, `per_copy_fee`, `processing_days`, `requires_upload`, `requires_documentary_stamp`, `fee_per_set`, `requires_term_info`, `requires_soa_info`, `requires_auth_document_type`, `is_active`, `requirements_required`, `second_copy_requirements_required`, `assignment_office`, `created_at`) VALUES ('14', 'Medium of Instruction (English)', 'COMEDIUM', NULL, '50.00', '0.00', '1', '0', '1', '0', '0', '0', '0', '1', '1', '1', 'registrar', '2026-07-28 13:53:16');

DROP TABLE IF EXISTS `faqs`;
CREATE TABLE `faqs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(100) DEFAULT 'General',
  `question` text NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `sort_order`, `is_active`, `created_at`) VALUES ('1', 'General', 'How long does it take to process my request?', 'Processing time varies by document type. TOR takes 5 business days, while Certificate of Enrollment takes 2 business days.', '1', '1', '2026-07-27 10:13:11');
INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `sort_order`, `is_active`, `created_at`) VALUES ('2', 'General', 'What payment methods are accepted?', 'We accept bank transfer and on-site payment at the cashier. For on-site payment, the app generates a 6-digit reference code for the cashier to locate your request.', '2', '1', '2026-07-27 10:13:11');
INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `sort_order`, `is_active`, `created_at`) VALUES ('3', 'Documents', 'What documents do I need to upload?', 'For TOR and Diploma requests, you need to upload a valid ID and authorization letter if applicable.', '3', '1', '2026-07-27 10:13:11');
INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `sort_order`, `is_active`, `created_at`) VALUES ('4', 'Pickup', 'How do I schedule a pickup appointment?', 'Once your request status is \"Ready for Pickup\", you can schedule an appointment from your request details page.', '4', '1', '2026-07-27 10:13:11');

DROP TABLE IF EXISTS `feedback`;
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `request_id` int(10) unsigned DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_email` tinyint(1) DEFAULT 0,
  `sent_sms` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `or_number` varchar(50) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected','refunded') DEFAULT 'pending',
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `verified_by` (`verified_by`),
  KEY `idx_payments_request` (`request_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `prospectus_subjects`;
CREATE TABLE `prospectus_subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `prospectus_id` int(10) unsigned NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `semester` enum('1st_semester','2nd_semester','summer') NOT NULL DEFAULT '1st_semester',
  `course_code` varchar(40) NOT NULL,
  `course_no` varchar(20) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL,
  `units` decimal(4,1) NOT NULL DEFAULT 3.0,
  `prereq` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prospectus_subjects_parent` (`prospectus_id`,`year_level`,`semester`,`sort_order`),
  CONSTRAINT `fk_prospectus_subjects_prospectus` FOREIGN KEY (`prospectus_id`) REFERENCES `course_prospectuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', '1', '1st Year', '1st_semester', 'GE', '105', 'Understanding the Self', '3.0', NULL, '1', '2026-09-03 22:13:04', '2026-09-03 22:13:04');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', '1', '1st Year', '1st_semester', 'GE', '106', 'Ethics', '3.0', NULL, '2', '2026-09-03 22:13:04', '2026-09-03 22:13:04');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', '1', '1st Year', '1st_semester', 'GEC', '107', 'The Contemporary World', '3.0', NULL, '3', '2026-09-03 22:13:04', '2026-09-03 22:13:04');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('4', '1', '1st Year', '1st_semester', 'CLJ', '1', 'Introduction to Philippine Criminal Justice System', '3.0', NULL, '4', '2026-09-03 22:13:04', '2026-09-03 22:13:04');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('5', '1', '1st Year', '1st_semester', 'CRIM', '1', 'Introduction to Criminology', '3.0', NULL, '5', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('6', '1', '1st Year', '1st_semester', 'AdGe', '001', 'General and Organic Chemistry', '3.0', NULL, '6', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('7', '1', '1st Year', '1st_semester', 'GEC', '108', 'Science, Technology and Society', '3.0', NULL, '7', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('8', '1', '1st Year', '1st_semester', 'IC', '101', 'JHCSC Civic Course', '1.0', NULL, '8', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('9', '1', '1st Year', '1st_semester', 'PATHFit', '1', 'Movement Competency Training', '2.0', NULL, '9', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('10', '1', '1st Year', '1st_semester', 'PE', '1', 'Fundamentals of Martial Arts', '2.0', NULL, '10', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('11', '1', '1st Year', '1st_semester', 'NSTP', '1', 'National Service Training Program 1 (ROTC)', '3.0', NULL, '11', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('12', '1', '1st Year', '2nd_semester', 'GEE', '101', 'Living in the IT Era', '3.0', NULL, '1', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('13', '1', '1st Year', '2nd_semester', 'GEC', '101', 'Purposive Communication', '3.0', NULL, '2', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('14', '1', '1st Year', '2nd_semester', 'GEC', '102', 'Readings in Philippine History', '3.0', NULL, '3', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('15', '1', '1st Year', '2nd_semester', 'GEC', '103', 'Mathematics in the Modern World', '3.0', NULL, '4', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('16', '1', '1st Year', '2nd_semester', 'GEC', '104', 'Art Appreciation', '3.0', NULL, '5', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('17', '1', '1st Year', '2nd_semester', 'LEA', '1', 'Law Enforcement Organization and Administration (Inter-Agency Approach)', '4.0', NULL, '6', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('18', '1', '1st Year', '2nd_semester', 'CLJ', '2', 'Human Rights Education', '3.0', NULL, '7', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('19', '1', '1st Year', '2nd_semester', 'PATHFit', '2', 'Exercise-Based Fitness Activities', '2.0', 'PATHFit 1', '8', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('20', '1', '1st Year', '2nd_semester', 'PE', '2', 'Arnis and Disarming Technique', '2.0', NULL, '9', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('21', '1', '1st Year', '2nd_semester', 'NSTP', '2', 'National Service Training Program 2 (ROTC)', '3.0', 'NSTP 1', '10', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('22', '1', '2nd Year', '1st_semester', 'CLJ', '3', 'Criminal Law (Book 1)', '3.0', NULL, '1', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('23', '1', '2nd Year', '1st_semester', 'FORENSIC', '1', 'Forensic Photography', '3.0', NULL, '2', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('24', '1', '2nd Year', '1st_semester', 'CRIM', '2', 'Theories of Crime Causation', '3.0', 'CRIM 1', '3', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('25', '1', '2nd Year', '1st_semester', 'LEA', '2', 'Comparative Models in Policing', '3.0', 'LEA 1', '4', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('26', '1', '2nd Year', '1st_semester', 'CDI', '1', 'Fundamentals of Criminal Investigation and Intelligence', '4.0', NULL, '5', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('27', '1', '2nd Year', '1st_semester', 'CA', '1', 'Institutional Correction', '3.0', 'CDI 1', '6', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('28', '1', '2nd Year', '1st_semester', 'CFLM', '1', 'Character Formation, Nationalism and Patriotism', '3.0', NULL, '7', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('29', '1', '2nd Year', '1st_semester', 'CRIM', '3', 'Human Behavior and Victimology', '3.0', 'CRIM 1', '8', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('30', '1', '2nd Year', '1st_semester', 'PE', '3', 'First Aid and Water Safety', '2.0', NULL, '9', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('31', '1', '2nd Year', '2nd_semester', 'CLJ', '4', 'Criminal Law (Book 2)', '4.0', 'CLJ 3', '1', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('32', '1', '2nd Year', '2nd_semester', 'CDI', '2', 'Specialized Crime Investigation 1 with Legal Medicine', '3.0', 'CDI 1', '2', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('33', '1', '2nd Year', '2nd_semester', 'FORENSIC', '2', 'Personal Identification Techniques', '3.0', NULL, '3', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('34', '1', '2nd Year', '2nd_semester', 'CRIM', '4', 'Professional Conduct and Ethical Standard', '3.0', 'GE 106', '4', '2026-09-03 22:13:05', '2026-09-03 22:13:05');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('35', '1', '2nd Year', '2nd_semester', 'LEA', '3', 'Introduction to Industrial Security Concept', '3.0', NULL, '5', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('36', '1', '2nd Year', '2nd_semester', 'GEE', '103', 'Entrepreneurial Mind', '3.0', NULL, '6', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('37', '1', '2nd Year', '2nd_semester', 'LEA', '4', 'Law Enforcement Operations and Planning with Crime Mapping', '3.0', NULL, '7', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('38', '1', '2nd Year', '2nd_semester', 'GEE', '105', 'Gender and Society', '3.0', NULL, '8', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('39', '1', '2nd Year', '2nd_semester', 'PE', '4', 'Fundamentals of Marksmanship', '2.0', NULL, '9', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('40', '1', '3rd Year', '1st_semester', 'CDI', '3', 'Specialized Crime Investigation 2 with Simulation or Role-Play', '3.0', 'CDI 2', '1', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('41', '1', '3rd Year', '1st_semester', 'CDI', '4', 'Traffic Management and Accident Investigation with Driving', '3.0', 'CDI 1', '2', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('42', '1', '3rd Year', '1st_semester', 'CRIM', '5', 'Juvenile Delinquency and Juvenile Justice System', '3.0', NULL, '3', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('43', '1', '3rd Year', '1st_semester', 'FORENSIC', '3', 'Forensic Chemistry and Toxicology', '5.0', 'AdGe 001', '4', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('44', '1', '3rd Year', '1st_semester', 'CA', '2', 'Non-Institutional Corrections', '3.0', 'CA 1', '5', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('45', '1', '3rd Year', '1st_semester', 'CRIM', '6', 'Dispute Resolution and Crisis/Incidents Management', '3.0', NULL, '6', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('46', '1', '3rd Year', '1st_semester', 'CDI', '5', 'Technical English 1 (Investigative Report Writing and Presentation)', '3.0', NULL, '7', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('47', '1', '3rd Year', '1st_semester', 'CRIM', '7', 'Criminological Research 1 (Research Methods with Applied Statistics)', '3.0', NULL, '8', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('48', '1', '3rd Year', '1st_semester', 'CLJ', '5', 'Evidence', '3.0', 'CLJ 4', '9', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('49', '1', '3rd Year', '2nd_semester', 'CFLM', '2', 'Character Formation 2 — Leadership, Decision Making, Management and Administration', '3.0', 'CFLM 1', '1', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('50', '1', '3rd Year', '2nd_semester', 'CDI', '6', 'Fire Protection and Arson Investigation', '3.0', NULL, '2', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('51', '1', '3rd Year', '2nd_semester', 'CDI', '7', 'Vice and Drug Education and Control', '3.0', NULL, '3', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('52', '1', '3rd Year', '2nd_semester', 'FORENSIC', '4', 'Questioned Documents Examination', '3.0', NULL, '4', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('53', '1', '3rd Year', '2nd_semester', 'FORENSIC', '5', 'Lie Detection Techniques', '3.0', NULL, '5', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('54', '1', '3rd Year', '2nd_semester', 'CDI', '8', 'Technical English 2 (Legal Forms)', '3.0', 'CDI 5', '6', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('55', '1', '3rd Year', '2nd_semester', 'CLJ', '6', 'Criminal Procedure and Court Testimony', '3.0', 'CLJ 5', '7', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('56', '1', '3rd Year', '2nd_semester', 'FORENSIC', '6', 'Forensic Ballistics', '3.0', NULL, '8', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('57', '1', '3rd Year', '2nd_semester', 'CRIM', '8', 'Criminological Research 2 (Thesis Administration and Presentation)', '3.0', 'CRIM 7', '9', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('58', '1', '3rd Year', '2nd_semester', 'CA', '3', 'Therapeutic Modalities', '2.0', 'CA 2', '10', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('59', '1', '4th Year', '1st_semester', 'Crim Pract', '1', 'Internship (On the Job Training 1)', '3.0', 'All Professional Subjects', '1', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('60', '1', '4th Year', '1st_semester', 'GEM', '101', 'Life and Works of Rizal', '3.0', NULL, '2', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('61', '1', '4th Year', '1st_semester', 'PS', '1', 'Professional Seminar 1', '3.0', NULL, '3', '2026-09-03 22:13:06', '2026-09-03 22:13:06');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('62', '1', '4th Year', '2nd_semester', 'Crim Pract', '2', 'Internship (On the Job Training 2)', '3.0', 'Crim Pract 1', '1', '2026-09-03 22:13:07', '2026-09-03 22:13:07');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('63', '1', '4th Year', '2nd_semester', 'CDI', '9', 'Introduction to Cybercrime and Environmental Laws and Protection', '3.0', NULL, '2', '2026-09-03 22:13:07', '2026-09-03 22:13:07');
INSERT INTO `prospectus_subjects` (`id`, `prospectus_id`, `year_level`, `semester`, `course_code`, `course_no`, `title`, `units`, `prereq`, `sort_order`, `created_at`, `updated_at`) VALUES ('64', '1', '4th Year', '2nd_semester', 'PS', '2', 'Professional Seminar 2', '3.0', 'PS 1', '3', '2026-09-03 22:13:07', '2026-09-03 22:13:07');

DROP TABLE IF EXISTS `request_assigned_requirements`;
CREATE TABLE `request_assigned_requirements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `request_item_id` int(10) unsigned DEFAULT NULL,
  `requirement_code` varchar(50) DEFAULT NULL,
  `subcategory_code` varchar(50) DEFAULT NULL,
  `requirement_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `requires_upload` tinyint(1) DEFAULT 1,
  `document_id` int(10) unsigned DEFAULT NULL,
  `is_met` tinyint(1) DEFAULT 0,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `request_item_id` (`request_item_id`),
  KEY `document_id` (`document_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `request_assigned_requirements_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_assigned_requirements_ibfk_2` FOREIGN KEY (`request_item_id`) REFERENCES `request_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_assigned_requirements_ibfk_3` FOREIGN KEY (`document_id`) REFERENCES `request_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `request_assigned_requirements_ibfk_4` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_authentication_items`;
CREATE TABLE `request_authentication_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `request_item_id` int(10) unsigned DEFAULT NULL,
  `auth_document_type` varchar(50) NOT NULL,
  `sets` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_auth_doc` (`request_id`,`auth_document_type`),
  KEY `request_item_id` (`request_item_id`),
  CONSTRAINT `request_authentication_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_authentication_items_ibfk_2` FOREIGN KEY (`request_item_id`) REFERENCES `request_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_clearances`;
CREATE TABLE `request_clearances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `department_id` tinyint(3) unsigned NOT NULL,
  `status` enum('pending','cleared','on_hold') DEFAULT 'pending',
  `cleared_by` int(10) unsigned DEFAULT NULL,
  `cleared_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_department` (`request_id`,`department_id`),
  KEY `department_id` (`department_id`),
  KEY `cleared_by` (`cleared_by`),
  CONSTRAINT `request_clearances_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_clearances_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `clearance_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_clearances_ibfk_3` FOREIGN KEY (`cleared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=211 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_compliance`;
CREATE TABLE `request_compliance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `requirement_id` tinyint(3) unsigned NOT NULL,
  `is_met` tinyint(1) DEFAULT 0,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_requirement` (`request_id`,`requirement_id`),
  KEY `requirement_id` (`requirement_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `request_compliance_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_compliance_ibfk_2` FOREIGN KEY (`requirement_id`) REFERENCES `document_requirements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_compliance_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_compliance_summary`;
CREATE TABLE `request_compliance_summary` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `compliance_status` enum('pending','compliant','non_compliant','needs_revision') DEFAULT 'pending',
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_id` (`request_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `request_compliance_summary_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_compliance_summary_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_documents`;
CREATE TABLE `request_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `document_category` varchar(50) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `request_documents_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_items`;
CREATE TABLE `request_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `document_type_id` tinyint(3) unsigned NOT NULL,
  `copies` int(11) NOT NULL DEFAULT 1,
  `request_school_year` varchar(20) DEFAULT NULL,
  `request_semester` varchar(30) DEFAULT NULL,
  `request_soa_assessment_scope` varchar(30) DEFAULT NULL,
  `request_soa_remarks` varchar(255) DEFAULT NULL,
  `item_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `item_status` enum('pending_assignment','processing','ready_for_pickup','completed') DEFAULT 'pending_assignment',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `release_time` time DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `pickup_time` time DEFAULT NULL,
  `verification_code` varchar(64) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `document_type_id` (`document_type_id`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `request_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_items_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `request_items_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `request_purpose_document_suggestions`;
CREATE TABLE `request_purpose_document_suggestions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `purpose_id` tinyint(3) unsigned NOT NULL,
  `document_type_id` tinyint(3) unsigned NOT NULL,
  `enrollment_status` enum('enrolled','graduated','inactive') NOT NULL DEFAULT 'enrolled',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purpose_document_status` (`purpose_id`,`document_type_id`,`enrollment_status`),
  KEY `document_type_id` (`document_type_id`),
  KEY `idx_purpose_suggestions_purpose` (`purpose_id`),
  CONSTRAINT `request_purpose_document_suggestions_ibfk_1` FOREIGN KEY (`purpose_id`) REFERENCES `request_purposes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_purpose_document_suggestions_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=156 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('37', '4', '1', 'graduated', '10', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('38', '4', '2', 'graduated', '20', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('39', '4', '6', 'graduated', '30', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('40', '4', '4', 'graduated', '40', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('41', '4', '1', 'enrolled', '10', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('42', '4', '2', 'enrolled', '20', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('43', '4', '6', 'enrolled', '30', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('44', '4', '4', 'enrolled', '40', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('45', '4', '1', 'inactive', '10', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('46', '4', '2', 'inactive', '20', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('47', '4', '6', 'inactive', '30', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('48', '4', '4', 'inactive', '40', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('49', '5', '1', 'graduated', '10', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('50', '5', '4', 'graduated', '20', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('51', '5', '3', 'graduated', '30', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('52', '5', '5', 'graduated', '40', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('53', '5', '1', 'enrolled', '10', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('54', '5', '4', 'enrolled', '20', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('55', '5', '3', 'enrolled', '30', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('56', '5', '5', 'enrolled', '40', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('57', '5', '1', 'inactive', '10', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('58', '5', '4', 'inactive', '20', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('59', '5', '3', 'inactive', '30', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('60', '5', '5', 'inactive', '40', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('61', '6', '1', 'graduated', '10', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('62', '6', '2', 'graduated', '20', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('63', '6', '8', 'graduated', '30', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('64', '6', '1', 'enrolled', '10', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('65', '6', '2', 'enrolled', '20', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('66', '6', '8', 'enrolled', '30', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('67', '6', '1', 'inactive', '10', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('68', '6', '2', 'inactive', '20', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('69', '6', '8', 'inactive', '30', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('70', '1', '2', 'graduated', '10', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('71', '1', '7', 'graduated', '20', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('72', '1', '1', 'graduated', '30', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('73', '1', '7', 'enrolled', '10', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('74', '1', '1', 'enrolled', '20', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('75', '1', '7', 'inactive', '10', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('76', '1', '1', 'inactive', '20', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('112', '2', '3', 'enrolled', '10', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('113', '2', '4', 'enrolled', '20', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('114', '2', '5', 'enrolled', '30', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('115', '2', '3', 'inactive', '10', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('116', '2', '4', 'inactive', '20', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('117', '2', '5', 'inactive', '30', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('118', '2', '3', 'graduated', '10', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('119', '2', '4', 'graduated', '20', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('120', '2', '5', 'graduated', '30', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('147', '3', '10', 'graduated', '10', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('148', '3', '7', 'graduated', '20', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('149', '3', '1', 'graduated', '30', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('150', '3', '10', 'enrolled', '10', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('151', '3', '7', 'enrolled', '20', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('152', '3', '1', 'enrolled', '30', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('153', '3', '10', 'inactive', '10', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('154', '3', '7', 'inactive', '20', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_document_suggestions` (`id`, `purpose_id`, `document_type_id`, `enrollment_status`, `sort_order`, `created_at`) VALUES ('155', '3', '1', 'inactive', '30', '2026-07-28 13:43:57');

DROP TABLE IF EXISTS `request_purpose_enrollment_settings`;
CREATE TABLE `request_purpose_enrollment_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `purpose_id` tinyint(3) unsigned NOT NULL,
  `enrollment_status` enum('enrolled','graduated','inactive') NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `hint` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purpose_enrollment` (`purpose_id`,`enrollment_status`),
  CONSTRAINT `request_purpose_enrollment_settings_ibfk_1` FOREIGN KEY (`purpose_id`) REFERENCES `request_purposes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7378 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('1', '1', 'graduated', '1', 'Employers often ask for your transcript, graduation proof, diploma, or good moral certificate.', '2026-07-27 10:13:30', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('2', '1', 'enrolled', '1', 'Employers often ask for your transcript, graduation proof, diploma, or good moral certificate.', '2026-07-27 10:13:30', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('3', '1', 'inactive', '1', 'Employers often ask for your transcript, graduation proof, diploma, or good moral certificate.', '2026-07-27 10:13:31', '2026-07-28 13:38:45');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('4', '2', 'graduated', '1', 'Scholarship applications usually require your transcript, grades, enrollment proof, statement of account, or good moral certificate.', '2026-07-27 10:13:32', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('5', '2', 'enrolled', '1', 'Scholarship applications usually require your transcript, grades, enrollment proof, statement of account, or good moral certificate.', '2026-07-27 10:13:32', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('6', '2', 'inactive', '1', 'Scholarship applications usually require your transcript, grades, enrollment proof, statement of account, or good moral certificate.', '2026-07-27 10:13:32', '2026-07-28 13:40:56');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('7', '3', 'graduated', '1', 'School transfers typically need your transcript, grades, and enrollment certificate.', '2026-07-27 10:13:33', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('8', '3', 'enrolled', '1', 'School transfers typically need your transcript, grades, and enrollment certificate.', '2026-07-27 10:13:33', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('9', '3', 'inactive', '1', 'School transfers typically need your transcript, grades, and enrollment certificate.', '2026-07-27 10:13:34', '2026-07-28 13:43:57');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('10', '4', 'graduated', '1', 'Graduate or further studies applications commonly require transcript, diploma, graduation proof, and grades.', '2026-07-27 10:13:34', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('11', '4', 'enrolled', '1', 'Graduate or further studies applications commonly require transcript, diploma, graduation proof, and grades.', '2026-07-27 10:13:34', '2026-07-27 10:13:34');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('12', '4', 'inactive', '1', 'Graduate or further studies applications commonly require transcript, diploma, graduation proof, and grades.', '2026-07-27 10:13:35', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('13', '5', 'graduated', '1', 'Common personal requests include transcript, grades, enrollment certificate, or statement of account.', '2026-07-27 10:13:35', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('14', '5', 'enrolled', '1', 'Common personal requests include transcript, grades, enrollment certificate, or statement of account.', '2026-07-27 10:13:35', '2026-07-27 10:13:35');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('15', '5', 'inactive', '1', 'Common personal requests include transcript, grades, enrollment certificate, or statement of account.', '2026-07-27 10:13:36', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('16', '6', 'graduated', '1', 'Legal purposes often need authenticated copies of your transcript, diploma, or certified true copies.', '2026-07-27 10:13:36', '2026-07-27 10:13:36');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('17', '6', 'enrolled', '1', 'Legal purposes often need authenticated copies of your transcript, diploma, or certified true copies.', '2026-07-27 10:13:37', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('18', '6', 'inactive', '1', 'Legal purposes often need authenticated copies of your transcript, diploma, or certified true copies.', '2026-07-27 10:13:37', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('19', '7', 'graduated', '1', 'Select the documents that match your specific need below.', '2026-07-27 10:13:37', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('20', '7', 'enrolled', '1', 'Select the documents that match your specific need below.', '2026-07-27 10:13:37', '2026-07-27 10:13:37');
INSERT INTO `request_purpose_enrollment_settings` (`id`, `purpose_id`, `enrollment_status`, `is_enabled`, `hint`, `created_at`, `updated_at`) VALUES ('21', '7', 'inactive', '1', 'Select the documents that match your specific need below.', '2026-07-27 10:13:37', '2026-07-27 10:13:37');

DROP TABLE IF EXISTS `request_purposes`;
CREATE TABLE `request_purposes` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(150) NOT NULL,
  `hint` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'employment', 'Employment', 'Employers often ask for your transcript, graduation proof, diploma, or good moral certificate.', '1', '10', '2026-07-27 10:13:30', '2026-07-27 10:13:30');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', 'scholarship', 'Scholarship', 'Scholarship applications usually require your transcript, grades, enrollment proof, statement of account, or good moral certificate.', '1', '20', '2026-07-27 10:13:31', '2026-07-27 10:13:31');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', 'transfer', 'Transfer', 'School transfers typically need your transcript, grades, and enrollment certificate.', '1', '30', '2026-07-27 10:13:32', '2026-07-27 10:13:32');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('4', 'further_studies', 'Further Studies', 'Graduate or further studies applications commonly require transcript, diploma, graduation proof, and grades.', '0', '40', '2026-07-27 10:13:34', '2026-07-28 13:41:35');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('5', 'personal', 'Personal', 'Common personal requests include transcript, grades, enrollment certificate, or statement of account.', '0', '50', '2026-07-27 10:13:35', '2026-07-28 13:41:40');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('6', 'legal', 'Legal', 'Legal purposes often need authenticated copies of your transcript, diploma, or certified true copies.', '0', '60', '2026-07-27 10:13:36', '2026-07-28 13:41:45');
INSERT INTO `request_purposes` (`id`, `code`, `label`, `hint`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('7', 'other', 'Other', 'Select the documents that match your specific need below.', '1', '70', '2026-07-27 10:13:37', '2026-07-27 10:13:37');

DROP TABLE IF EXISTS `request_status_history`;
CREATE TABLE `request_status_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `request_status_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_number` varchar(20) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `document_type_id` tinyint(3) unsigned DEFAULT NULL,
  `purpose` varchar(50) NOT NULL,
  `purpose_other` varchar(255) DEFAULT NULL,
  `copy_request_type` enum('first_request','second_copy') NOT NULL DEFAULT 'first_request',
  `request_school_year` varchar(20) DEFAULT NULL,
  `request_semester` varchar(30) DEFAULT NULL,
  `request_soa_assessment_scope` varchar(30) DEFAULT NULL,
  `request_soa_remarks` varchar(255) DEFAULT NULL,
  `authentication_document_type` varchar(50) DEFAULT NULL,
  `copies` int(11) NOT NULL DEFAULT 1,
  `status` enum('submitted','under_review','awaiting_requirements','requirements_submitted','needs_revision','requirements_verified','payment_verified','processing','ready_for_pickup','shipped','completed','rejected') DEFAULT 'submitted',
  `delivery_method` enum('pickup','courier','authorized_representative') DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `pickup_time` time DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `release_time` time DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `delivery_city` varchar(100) DEFAULT NULL,
  `delivery_province` varchar(100) DEFAULT NULL,
  `delivery_postal_code` varchar(10) DEFAULT NULL,
  `representative_name` varchar(150) DEFAULT NULL,
  `representative_relationship` varchar(100) DEFAULT NULL,
  `representative_phone` varchar(20) DEFAULT NULL,
  `representative_id_number` varchar(50) DEFAULT NULL,
  `courier_tracking` varchar(100) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `verification_code` varchar(64) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `digital_signature` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `request_channel` enum('online','onsite') NOT NULL DEFAULT 'online',
  `created_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_number` (`request_number`),
  KEY `document_type_id` (`document_type_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `idx_requests_user` (`user_id`),
  KEY `idx_requests_status` (`status`),
  KEY `idx_requests_number` (`request_number`),
  KEY `fk_requests_created_by` (`created_by`),
  CONSTRAINT `fk_requests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `requests_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `requests_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `requirement_definitions`;
CREATE TABLE `requirement_definitions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `requires_upload` tinyint(1) NOT NULL DEFAULT 1,
  `is_optional` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_requirement_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `requirement_definitions` (`id`, `code`, `name`, `description`, `requires_upload`, `is_optional`, `is_system`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'online_clearance', 'Online Clearance', 'Complete online clearance from all offices: Guidance, Library, Student Affairs, Program Chair, and Campus Director.', '0', '0', '1', '1', '1', '2026-07-27 10:13:12', '2026-07-27 10:13:12');
INSERT INTO `requirement_definitions` (`id`, `code`, `name`, `description`, `requires_upload`, `is_optional`, `is_system`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', 'thesis_distribution_list', 'Thesis Distribution List', 'Upload the thesis distribution list document.', '1', '0', '1', '1', '2', '2026-07-27 10:13:12', '2026-07-27 10:13:12');
INSERT INTO `requirement_definitions` (`id`, `code`, `name`, `description`, `requires_upload`, `is_optional`, `is_system`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', 'final_clearance', 'Final Clearance', 'Upload your final clearance document from the Registrar or relevant office.', '1', '0', '1', '1', '3', '2026-07-27 10:13:12', '2026-07-27 10:13:12');
INSERT INTO `requirement_definitions` (`id`, `code`, `name`, `description`, `requires_upload`, `is_optional`, `is_system`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('4', 'other_enrollment_requirements', 'Other Enrollment Requirements', 'Upload any other enrollment-related documents required for your request.', '1', '0', '1', '1', '4', '2026-07-27 10:13:12', '2026-07-27 10:13:12');
INSERT INTO `requirement_definitions` (`id`, `code`, `name`, `description`, `requires_upload`, `is_optional`, `is_system`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('5', 'affidavit_second_copy', 'Affidavit to issue Second Copy or Loss', 'Required for 2nd request of documents — upload affidavit of second copy or loss.', '1', '0', '1', '1', '5', '2026-07-27 10:13:12', '2026-07-28 11:54:06');

DROP TABLE IF EXISTS `requirement_subcategories`;
CREATE TABLE `requirement_subcategories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `requirement_code` varchar(50) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_req_subcode` (`requirement_code`,`code`),
  KEY `idx_req_sub_parent` (`requirement_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `requirement_subcategories` (`id`, `requirement_code`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'other_enrollment_requirements', 'hs_card', 'HS Card', 'Upload a clear copy of your High School Card.', '1', '1', '2026-07-27 10:13:13', '2026-07-27 10:13:13');
INSERT INTO `requirement_subcategories` (`id`, `requirement_code`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', 'other_enrollment_requirements', 'live_birth_psa_photocopy', 'Live Birth PSA Photocopy', 'Upload a photocopy of your PSA Live Birth Certificate.', '1', '2', '2026-07-27 10:13:13', '2026-07-27 10:13:13');
INSERT INTO `requirement_subcategories` (`id`, `requirement_code`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', 'other_enrollment_requirements', 'f137a', 'F137A', 'Upload your Form 137-A (Secondary Student Permanent Record).', '1', '3', '2026-07-27 10:13:13', '2026-07-27 10:13:13');

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'student', 'Student/Requester', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('2', 'staff', 'Registrar Staff', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('3', 'registrar', 'Registrar Officer', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('4', 'admin', 'System Administrator', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('5', 'cashier', 'Payment Verification Cashier', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('6', 'clearance_officer', 'Clearance Signing Officer', '2026-07-27 10:13:06');
INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES ('7', 'accounting', 'Accounting Office — SOA document assignment only', '2026-08-12 08:19:44');

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration` (`migration`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('1', 'add_cashier_role.sql', '2026-09-03 22:12:45');
INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('2', 'add_external_api.sql', '2026-09-03 22:12:45');
INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('3', 'add_online_clearance.sql', '2026-09-03 22:12:45');
INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('4', 'add_onsite_request.sql', '2026-09-03 22:12:45');
INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('5', 'add_registrar_compliance.sql', '2026-09-03 22:12:46');
INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES ('6', 'update_workflow_steps.sql', '2026-09-03 22:12:47');

DROP TABLE IF EXISTS `student_profiles`;
CREATE TABLE `student_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `course` varchar(150) DEFAULT NULL,
  `course_id` tinyint(3) unsigned DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `current_academic_year` varchar(20) DEFAULT NULL,
  `current_semester` enum('1st_semester','2nd_semester','summer') DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `major` varchar(150) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `valid_id_original_name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_relationship` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `emergency_address` text DEFAULT NULL,
  `enrollment_status` enum('enrolled','graduated','inactive') DEFAULT 'enrolled',
  `graduation_date` date DEFAULT NULL,
  `origin_campus_id` tinyint(3) unsigned DEFAULT NULL,
  `year_graduated` smallint(5) unsigned DEFAULT NULL,
  `last_school_year` varchar(20) DEFAULT NULL,
  `employment_status` enum('employed','self_employed','unemployed','seeking_employment','further_studies') DEFAULT NULL,
  `employer_name` varchar(200) DEFAULT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `employer_address` text DEFAULT NULL,
  `employment_start_date` date DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `student_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `student_subject_grades`;
CREATE TABLE `student_subject_grades` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `prospectus_subject_id` int(10) unsigned NOT NULL,
  `grade` varchar(20) DEFAULT NULL,
  `remarks` varchar(40) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st_semester','2nd_semester','summer') DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_student_subject_grade` (`user_id`,`prospectus_subject_id`),
  KEY `idx_student_grades_user` (`user_id`),
  KEY `fk_student_subject_grades_subject` (`prospectus_subject_id`),
  CONSTRAINT `fk_student_subject_grades_subject` FOREIGN KEY (`prospectus_subject_id`) REFERENCES `prospectus_subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_subject_grades_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `clearance_department_id` tinyint(3) unsigned DEFAULT NULL,
  `clearance_program_id` tinyint(3) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `email_verified` tinyint(1) DEFAULT 0,
  `mfa_enabled` tinyint(1) DEFAULT 0,
  `mfa_secret` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `privacy_consent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('1', '4', NULL, NULL, 'admin@regdum.edu.ph', '$2y$10$k6xZiz3dpyV5X2XcLPRmP.WIUNiCEpyzvIRclczeMO3sATqq1cDmO', NULL, 'System', 'Administrator', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-09-04 21:14:56', NULL, '2026-07-27 10:13:18', '2026-09-04 21:14:56');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('3', '5', NULL, NULL, 'cashier@regdum.edu.ph', '$2y$10$hP384gYD1P1vnDCA9wuR9Ow2otMNIG7r85xn1EQgrUDpuiQ7ZZUtu', NULL, 'KRICHELLE', 'VILLARUBIA', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-12 22:33:27', NULL, '2026-07-27 10:13:18', '2026-08-13 13:33:27');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('4', '3', NULL, NULL, 'registrar@regdum.edu.ph', '$2y$10$OimvVWX1tl76ZAbxRDWxD.3SA6uVDuk.ihC01a6C/ABVAXp4rY.aC', NULL, 'ELEONOR', 'OCAY', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-09-04 21:12:52', NULL, '2026-07-27 10:13:18', '2026-09-04 21:12:52');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('5', '6', '1', NULL, 'guidance@regdum.edu.ph', '$2y$10$mzLO6PpVVmOh67Dy99LTwuTqFW7CQirabBU0mjXMNV4IALggL88/u', NULL, 'Guidance', 'Officer', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-11 23:15:06', NULL, '2026-07-27 10:13:24', '2026-08-12 14:15:06');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('6', '6', '2', NULL, 'library@regdum.edu.ph', '$2y$10$mzLO6PpVVmOh67Dy99LTwuTqFW7CQirabBU0mjXMNV4IALggL88/u', NULL, 'Library', 'Officer', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-11 17:22:56', NULL, '2026-07-27 10:13:24', '2026-08-12 08:22:56');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('7', '6', '3', NULL, 'studentaffairs@regdum.edu.ph', '$2y$10$mzLO6PpVVmOh67Dy99LTwuTqFW7CQirabBU0mjXMNV4IALggL88/u', NULL, 'Student Affairs', 'Officer', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-11 22:51:29', NULL, '2026-07-27 10:13:24', '2026-08-12 13:51:29');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('8', '6', '4', '1', 'programchair@regdum.edu.ph', '$2y$10$mzLO6PpVVmOh67Dy99LTwuTqFW7CQirabBU0mjXMNV4IALggL88/u', NULL, 'PROGRAM CHAIR', 'BSIT', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-11 17:13:32', NULL, '2026-07-27 10:13:24', '2026-08-12 08:13:32');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('9', '6', '5', NULL, 'campusdirector@regdum.edu.ph', '$2y$10$mzLO6PpVVmOh67Dy99LTwuTqFW7CQirabBU0mjXMNV4IALggL88/u', NULL, 'Campus', 'Director', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, NULL, NULL, '2026-07-27 10:13:24', '2026-07-27 10:13:24');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('20', '3', NULL, NULL, 'melesagalvez@gmail.com', '$2y$10$yLqMCUJzGMZ.oyZg7Ubv5.uK1xGgb12slGOusLzBVIRjWc9fl0m6K', NULL, 'MELESA', 'GALVEZ', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, NULL, NULL, '2026-08-10 11:51:58', '2026-08-10 11:51:58');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('21', '3', NULL, NULL, 'jen@gmail.com', '$2y$10$Y2rjr5jNCNljFfNNRjiGs.1c1BCGFaj2CVXNYI9WLfWXWsx7ZrQVq', NULL, 'JENNIFER', 'BESTUDIO', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, NULL, NULL, '2026-08-10 11:52:30', '2026-08-10 11:52:30');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('22', '3', NULL, NULL, 'jessie@gmail.com', '$2y$10$Vqox7MD48vqYM3PjMnhFu.I1Ac2fKzbgvAApFvzM6LlPY6RY2WqQu', NULL, 'JESSIE', 'TORMIS-IDIAS', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, NULL, NULL, '2026-08-10 11:52:57', '2026-08-10 11:52:57');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('23', '3', NULL, NULL, 'l.daluag11@gmail.com', '$2y$10$ogDMPk7rFFTOlhhFomGzMeGZIg8QkgEQ7HL9ccdRo3n7GhlkxhAyG', NULL, 'LOVELY', 'DALUAG-GORRE', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-09 20:54:19', NULL, '2026-08-10 11:53:31', '2026-08-10 11:54:19');
INSERT INTO `users` (`id`, `role_id`, `clearance_department_id`, `clearance_program_id`, `email`, `password`, `student_id`, `first_name`, `last_name`, `middle_name`, `phone`, `is_active`, `email_verified`, `mfa_enabled`, `mfa_secret`, `reset_token`, `reset_token_expires`, `last_login`, `privacy_consent_at`, `created_at`, `updated_at`) VALUES ('24', '7', NULL, NULL, 'accounting@regdum.edu.ph', '$2y$10$KugPkGJpXUYAC6UgxCY3P.TzEhXuq.G/6AaxMszRHLGaqS/wgoxaq', NULL, 'ACCOUNTING', 'MODULE', NULL, NULL, '1', '1', '0', NULL, NULL, NULL, '2026-08-11 17:20:47', NULL, '2026-08-12 08:20:41', '2026-08-12 08:20:47');

SET FOREIGN_KEY_CHECKS=1;
