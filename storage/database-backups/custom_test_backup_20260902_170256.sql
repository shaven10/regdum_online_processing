-- Database backup
-- Database: regdum_credentials
-- Generated: 2026-09-02 17:02:57
-- Tables: academic_programs, app_settings

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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('1', 'BSIT', 'BS Information Technology', 'Information Technology program', '1', '1', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('2', 'BSCS', 'BS Computer Science', 'Computer Science program', '1', '2', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('3', 'BSBA', 'BS Business Administration', 'Business Administration program', '1', '3', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('4', 'BSA', 'BS Accountancy', 'Accountancy program', '1', '4', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('5', 'BSED', 'BS Education', 'Education program', '1', '5', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('6', 'BSN', 'BS Nursing', 'Nursing program', '1', '6', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('7', 'BSCPE', 'BS Computer Engineering', 'Computer Engineering program', '1', '7', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('8', 'BSHM', 'BS Hospitality Management', 'Hospitality Management program', '1', '8', '2026-07-27 10:13:06', '2026-07-27 10:13:06');
INSERT INTO `academic_programs` (`id`, `code`, `name`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES ('9', 'BSCRIM', 'Bachelor of Science in Criminology', 'Bachelor of Science in Criminology', '1', '9', '2026-09-01 15:27:43', '2026-09-01 15:27:43');

DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('auto_apply_requirement_defaults', '0', '2026-07-27 15:36:02');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('bank_transfer_details', '{\"bank_name\":\"Lanbank of the Philippines\",\"account_name\":\"J.H. CERILLES STATE COLLEGE\",\"account_number\":\"2842-1046-64\",\"branch\":\"JHCSC Dumingag Campus\",\"instructions\":\"\"}', '2026-07-27 16:02:38');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('other_enrollment_requirements_opt_in_migrated', '1', '2026-07-27 15:49:31');
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('theme_preset', 'green', '2026-07-27 16:36:30');

SET FOREIGN_KEY_CHECKS=1;
