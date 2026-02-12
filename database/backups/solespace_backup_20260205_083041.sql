-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: solespace
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) unsigned DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `batch_uuid` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_delegations`
--

DROP TABLE IF EXISTS `approval_delegations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_delegations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delegated_by_id` bigint(20) unsigned NOT NULL,
  `delegate_to_id` bigint(20) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_delegations_delegated_by_id_index` (`delegated_by_id`),
  KEY `approval_delegations_delegate_to_id_index` (`delegate_to_id`),
  KEY `approval_delegations_is_active_start_date_end_date_index` (`is_active`,`start_date`,`end_date`),
  CONSTRAINT `approval_delegations_delegate_to_id_foreign` FOREIGN KEY (`delegate_to_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_delegations_delegated_by_id_foreign` FOREIGN KEY (`delegated_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_delegations`
--

LOCK TABLES `approval_delegations` WRITE;
/*!40000 ALTER TABLE `approval_delegations` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_delegations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_history`
--

DROP TABLE IF EXISTS `approval_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `approval_id` bigint(20) unsigned NOT NULL,
  `level` int(11) NOT NULL,
  `reviewer_id` bigint(20) unsigned NOT NULL,
  `action` enum('approved','rejected') NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_history_reviewer_id_foreign` (`reviewer_id`),
  KEY `approval_history_approval_id_index` (`approval_id`),
  CONSTRAINT `approval_history_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `approvals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_history_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_history`
--

LOCK TABLES `approval_history` WRITE;
/*!40000 ALTER TABLE `approval_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approvals`
--

DROP TABLE IF EXISTS `approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `approvable_type` varchar(255) DEFAULT NULL,
  `approvable_id` bigint(20) unsigned DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `requested_by` bigint(20) unsigned NOT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `current_level` int(11) NOT NULL DEFAULT 1,
  `total_levels` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approvals_requested_by_foreign` (`requested_by`),
  KEY `approvals_reviewed_by_foreign` (`reviewed_by`),
  KEY `approvals_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `approvals_approvable_type_approvable_id_index` (`approvable_type`,`approvable_id`),
  KEY `approvals_reference_index` (`reference`),
  CONSTRAINT `approvals_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approvals_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approvals_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approvals`
--

LOCK TABLES `approvals` WRITE;
/*!40000 ALTER TABLE `approvals` DISABLE KEYS */;
/*!40000 ALTER TABLE `approvals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_records`
--

DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day') NOT NULL DEFAULT 'present',
  `biometric_id` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `working_hours` decimal(4,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(4,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_records_employee_id_date_unique` (`employee_id`,`date`),
  KEY `attendance_records_employee_id_date_index` (`employee_id`,`date`),
  KEY `attendance_records_shop_owner_id_index` (`shop_owner_id`),
  KEY `attendance_records_date_index` (`date`),
  KEY `attendance_records_status_index` (`status`),
  CONSTRAINT `attendance_records_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_records_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_records`
--

LOCK TABLES `attendance_records` WRITE;
/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `object_type` varchar(255) DEFAULT NULL,
  `target_type` varchar(255) DEFAULT NULL,
  `object_id` bigint(20) unsigned DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_shop_owner_id_action_index` (`shop_owner_id`,`action`),
  KEY `audit_logs_target_type_target_id_index` (`target_type`,`target_id`),
  KEY `audit_logs_user_id_index` (`user_id`),
  KEY `audit_logs_action_index` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `budgets`
--

DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `budgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `category` varchar(255) NOT NULL,
  `budgeted` decimal(18,2) NOT NULL,
  `spent` decimal(18,2) NOT NULL DEFAULT 0.00,
  `trend` varchar(255) NOT NULL DEFAULT 'stable',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budgets_shop_owner_id_index` (`shop_owner_id`),
  CONSTRAINT `budgets_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `budgets`
--

LOCK TABLES `budgets` WRITE;
/*!40000 ALTER TABLE `budgets` DISABLE KEYS */;
/*!40000 ALTER TABLE `budgets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `calendar_events`
--

DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `calendar` enum('Danger','Success','Primary','Warning') NOT NULL DEFAULT 'Primary',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendar_events_shop_owner_id_index` (`shop_owner_id`),
  KEY `calendar_events_start_date_index` (`start_date`),
  KEY `calendar_events_end_date_index` (`end_date`),
  CONSTRAINT `calendar_events_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `calendar_events`
--

LOCK TABLES `calendar_events` WRITE;
/*!40000 ALTER TABLE `calendar_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `calendar_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cart_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `size` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `stock_quantity` int(11) DEFAULT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cart_items_product_id_foreign` (`product_id`),
  KEY `cart_items_user_id_product_id_index` (`user_id`,`product_id`),
  CONSTRAINT `cart_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cost_centers`
--

DROP TABLE IF EXISTS `cost_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cost_centers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('department','project','location','division') NOT NULL DEFAULT 'department',
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `budget_limit` decimal(18,2) DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `manager_email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cost_centers_code_unique` (`code`),
  KEY `cost_centers_parent_id_foreign` (`parent_id`),
  KEY `cost_centers_shop_owner_id_index` (`shop_owner_id`),
  KEY `cost_centers_code_index` (`code`),
  CONSTRAINT `cost_centers_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cost_centers_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cost_centers`
--

LOCK TABLES `cost_centers` WRITE;
/*!40000 ALTER TABLE `cost_centers` DISABLE KEYS */;
/*!40000 ALTER TABLE `cost_centers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_shop_owner_id_name_unique` (`shop_owner_id`,`name`),
  KEY `departments_shop_owner_id_index` (`shop_owner_id`),
  KEY `departments_name_index` (`name`),
  KEY `departments_is_active_index` (`is_active`),
  CONSTRAINT `departments_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `zip_code` varchar(255) DEFAULT NULL,
  `emergency_contact` varchar(255) DEFAULT NULL,
  `emergency_phone` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `branch` varchar(255) DEFAULT NULL,
  `functional_role` varchar(255) DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `status` enum('active','inactive','on_leave','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_email_unique` (`email`),
  KEY `employees_shop_owner_id_index` (`shop_owner_id`),
  KEY `employees_email_index` (`email`),
  KEY `employees_status_index` (`status`),
  KEY `employees_department_index` (`department`),
  CONSTRAINT `employees_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_allocations`
--

DROP TABLE IF EXISTS `expense_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `cost_center_id` bigint(20) unsigned NOT NULL,
  `finance_journal_line_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `allocation_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_allocations_shop_owner_id_index` (`shop_owner_id`),
  KEY `expense_allocations_cost_center_id_index` (`cost_center_id`),
  KEY `expense_allocations_finance_journal_line_id_index` (`finance_journal_line_id`),
  KEY `expense_allocations_allocation_date_index` (`allocation_date`),
  CONSTRAINT `ea_cc_id_fk` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`),
  CONSTRAINT `ea_fjl_id_fk` FOREIGN KEY (`finance_journal_line_id`) REFERENCES `finance_journal_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ea_so_id_fk` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_allocations`
--

LOCK TABLES `expense_allocations` WRITE;
/*!40000 ALTER TABLE `expense_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_accounts`
--

DROP TABLE IF EXISTS `finance_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `normal_balance` varchar(255) NOT NULL DEFAULT 'Debit',
  `group` varchar(255) DEFAULT NULL,
  `balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `shop_owner_id` bigint(20) unsigned DEFAULT NULL,
  `shop_id` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_accounts_code_unique` (`code`),
  KEY `finance_accounts_parent_id_foreign` (`parent_id`),
  KEY `finance_accounts_shop_owner_id_index` (`shop_owner_id`),
  KEY `finance_accounts_shop_id_index` (`shop_id`),
  KEY `finance_accounts_code_index` (`code`),
  KEY `finance_accounts_type_index` (`type`),
  CONSTRAINT `finance_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `finance_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_accounts`
--

LOCK TABLES `finance_accounts` WRITE;
/*!40000 ALTER TABLE `finance_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_expenses`
--

DROP TABLE IF EXISTS `finance_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_order_id` bigint(20) unsigned DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `category` varchar(255) NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','posted','rejected') NOT NULL DEFAULT 'submitted',
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approval_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `payment_account_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `receipt_original_name` varchar(255) DEFAULT NULL,
  `receipt_mime_type` varchar(255) DEFAULT NULL,
  `receipt_size` bigint(20) unsigned DEFAULT NULL,
  `shop_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_expenses_reference_unique` (`reference`),
  KEY `finance_expenses_approval_id_foreign` (`approval_id`),
  KEY `finance_expenses_created_by_foreign` (`created_by`),
  KEY `finance_expenses_job_order_id_index` (`job_order_id`),
  CONSTRAINT `finance_expenses_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `approvals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_expenses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_expenses_job_order_id_foreign` FOREIGN KEY (`job_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_expenses`
--

LOCK TABLES `finance_expenses` WRITE;
/*!40000 ALTER TABLE `finance_expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_invoice_items`
--

DROP TABLE IF EXISTS `finance_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(18,2) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_invoice_items_invoice_id_foreign` (`invoice_id`),
  KEY `finance_invoice_items_account_id_foreign` (`account_id`),
  CONSTRAINT `finance_invoice_items_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `finance_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `finance_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_invoice_items`
--

LOCK TABLES `finance_invoice_items` WRITE;
/*!40000 ALTER TABLE `finance_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_invoices`
--

DROP TABLE IF EXISTS `finance_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_order_id` bigint(20) unsigned DEFAULT NULL,
  `job_reference` varchar(255) DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','sent','posted','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approval_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `shop_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_invoices_reference_unique` (`reference`),
  KEY `finance_invoices_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `finance_invoices_reference_index` (`reference`),
  KEY `finance_invoices_status_index` (`status`),
  KEY `finance_invoices_date_index` (`date`),
  KEY `finance_invoices_approval_id_foreign` (`approval_id`),
  KEY `finance_invoices_created_by_foreign` (`created_by`),
  KEY `finance_invoices_job_order_id_index` (`job_order_id`),
  CONSTRAINT `finance_invoices_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `approvals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_invoices_job_order_id_foreign` FOREIGN KEY (`job_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_invoices_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `finance_journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_invoices`
--

LOCK TABLES `finance_invoices` WRITE;
/*!40000 ALTER TABLE `finance_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_journal_entries`
--

DROP TABLE IF EXISTS `finance_journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','posted','void') NOT NULL DEFAULT 'draft',
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approval_id` bigint(20) unsigned DEFAULT NULL,
  `posted_by` varchar(255) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_journal_entries_reference_index` (`reference`),
  KEY `finance_journal_entries_approval_id_foreign` (`approval_id`),
  CONSTRAINT `finance_journal_entries_approval_id_foreign` FOREIGN KEY (`approval_id`) REFERENCES `approvals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_journal_entries`
--

LOCK TABLES `finance_journal_entries` WRITE;
/*!40000 ALTER TABLE `finance_journal_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_journal_lines`
--

DROP TABLE IF EXISTS `finance_journal_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_journal_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `account_code` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `debit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `memo` varchar(255) DEFAULT NULL,
  `tax` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_journal_lines_journal_entry_id_index` (`journal_entry_id`),
  KEY `finance_journal_lines_account_id_index` (`account_id`),
  CONSTRAINT `finance_journal_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `finance_accounts` (`id`),
  CONSTRAINT `finance_journal_lines_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `finance_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_journal_lines`
--

LOCK TABLES `finance_journal_lines` WRITE;
/*!40000 ALTER TABLE `finance_journal_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_journal_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_tax_rates`
--

DROP TABLE IF EXISTS `finance_tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_tax_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `fixed_amount` decimal(18,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `applies_to` enum('all','expenses','invoices','journal_entries') NOT NULL DEFAULT 'all',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_inclusive` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `shop_id` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_tax_rates_code_unique` (`code`),
  KEY `finance_tax_rates_shop_id_is_active_index` (`shop_id`,`is_active`),
  KEY `finance_tax_rates_code_shop_id_index` (`code`,`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_tax_rates`
--

LOCK TABLES `finance_tax_rates` WRITE;
/*!40000 ALTER TABLE `finance_tax_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_tax_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_audit_logs`
--

DROP TABLE IF EXISTS `hr_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `request_url` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shop_date` (`shop_owner_id`,`created_at`),
  KEY `idx_user_date` (`user_id`,`created_at`),
  KEY `idx_employee_date` (`employee_id`,`created_at`),
  KEY `idx_module_action` (`module`,`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `hr_audit_logs_severity_index` (`severity`),
  CONSTRAINT `hr_audit_logs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_audit_logs_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_audit_logs`
--

LOCK TABLES `hr_audit_logs` WRITE;
/*!40000 ALTER TABLE `hr_audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_certifications`
--

DROP TABLE IF EXISTS `hr_certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_certifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `training_enrollment_id` bigint(20) unsigned DEFAULT NULL,
  `certificate_name` varchar(255) NOT NULL,
  `certificate_number` varchar(255) NOT NULL,
  `issuing_organization` varchar(255) NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','expired','revoked','pending_renewal') NOT NULL DEFAULT 'active',
  `certificate_file_path` varchar(255) DEFAULT NULL,
  `verification_url` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_certifications_certificate_number_unique` (`certificate_number`),
  KEY `hr_certifications_training_enrollment_id_foreign` (`training_enrollment_id`),
  KEY `hr_certifications_employee_id_status_index` (`employee_id`,`status`),
  KEY `hr_certifications_shop_owner_id_expiry_date_index` (`shop_owner_id`,`expiry_date`),
  KEY `hr_certifications_expiry_date_index` (`expiry_date`),
  CONSTRAINT `hr_certifications_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_certifications_training_enrollment_id_foreign` FOREIGN KEY (`training_enrollment_id`) REFERENCES `hr_training_enrollments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_certifications`
--

LOCK TABLES `hr_certifications` WRITE;
/*!40000 ALTER TABLE `hr_certifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_certifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_competency_evaluations`
--

DROP TABLE IF EXISTS `hr_competency_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_competency_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `review_id` bigint(20) unsigned NOT NULL,
  `cycle_id` bigint(20) unsigned DEFAULT NULL,
  `competency_name` varchar(100) NOT NULL,
  `competency_description` text DEFAULT NULL,
  `self_rating` int(11) DEFAULT NULL COMMENT 'Employee self-rating 1-5',
  `manager_rating` int(11) DEFAULT NULL COMMENT 'Manager rating 1-5',
  `calibrated_rating` int(11) DEFAULT NULL COMMENT 'Final calibrated rating 1-5',
  `self_comments` text DEFAULT NULL,
  `manager_comments` text DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Competency weight in percentage',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_competency_evaluations_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_competency_evaluations_review_id_index` (`review_id`),
  KEY `hr_competency_evaluations_cycle_id_index` (`cycle_id`),
  KEY `hr_competency_evaluations_review_id_competency_name_index` (`review_id`,`competency_name`),
  KEY `hr_competency_evaluations_shop_owner_id_competency_name_index` (`shop_owner_id`,`competency_name`),
  CONSTRAINT `hr_competency_evaluations_cycle_id_foreign` FOREIGN KEY (`cycle_id`) REFERENCES `hr_performance_cycles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_competency_evaluations_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_competency_evaluations_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_competency_evaluations`
--

LOCK TABLES `hr_competency_evaluations` WRITE;
/*!40000 ALTER TABLE `hr_competency_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_competency_evaluations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_employee_documents`
--

DROP TABLE IF EXISTS `hr_employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_employee_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `document_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `file_size` int(11) NOT NULL COMMENT 'Size in bytes',
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 0,
  `requires_renewal` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_days` int(11) NOT NULL DEFAULT 30 COMMENT 'Days before expiry to send reminder',
  `status` enum('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `last_reminder_sent_at` timestamp NULL DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_employee_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `hr_employee_documents_verified_by_foreign` (`verified_by`),
  KEY `hr_employee_documents_employee_id_document_type_index` (`employee_id`,`document_type`),
  KEY `hr_employee_documents_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `hr_employee_documents_expiry_date_status_index` (`expiry_date`,`status`),
  KEY `hr_employee_documents_employee_id_expiry_date_index` (`employee_id`,`expiry_date`),
  CONSTRAINT `hr_employee_documents_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_employee_documents_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_employee_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `hr_employee_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_employee_documents`
--

LOCK TABLES `hr_employee_documents` WRITE;
/*!40000 ALTER TABLE `hr_employee_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_employee_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_employee_onboarding`
--

DROP TABLE IF EXISTS `hr_employee_onboarding`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_employee_onboarding` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `checklist_id` bigint(20) unsigned NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_task_unique` (`employee_id`,`task_id`),
  KEY `hr_employee_onboarding_completed_by_foreign` (`completed_by`),
  KEY `hr_employee_onboarding_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_employee_onboarding_employee_id_index` (`employee_id`),
  KEY `hr_employee_onboarding_checklist_id_index` (`checklist_id`),
  KEY `hr_employee_onboarding_task_id_index` (`task_id`),
  KEY `hr_employee_onboarding_employee_id_status_index` (`employee_id`,`status`),
  KEY `hr_employee_onboarding_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `hr_employee_onboarding_due_date_index` (`due_date`),
  CONSTRAINT `hr_employee_onboarding_checklist_id_foreign` FOREIGN KEY (`checklist_id`) REFERENCES `hr_onboarding_checklists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_employee_onboarding_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_employee_onboarding_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_employee_onboarding_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_employee_onboarding_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `hr_onboarding_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_employee_onboarding`
--

LOCK TABLES `hr_employee_onboarding` WRITE;
/*!40000 ALTER TABLE `hr_employee_onboarding` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_employee_onboarding` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_leave_approval_hierarchy`
--

DROP TABLE IF EXISTS `hr_leave_approval_hierarchy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_leave_approval_hierarchy` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `approver_id` bigint(20) unsigned NOT NULL,
  `approval_level` int(11) NOT NULL DEFAULT 1,
  `approver_type` enum('manager','hr','department_head','custom') NOT NULL DEFAULT 'manager',
  `applies_for_days_greater_than` int(11) DEFAULT NULL,
  `applies_for_leave_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applies_for_leave_types`)),
  `delegated_to` bigint(20) unsigned DEFAULT NULL,
  `delegation_start_date` timestamp NULL DEFAULT NULL,
  `delegation_end_date` timestamp NULL DEFAULT NULL,
  `delegation_reason` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` timestamp NULL DEFAULT NULL,
  `effective_to` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_approval_hierarchy_unique` (`shop_owner_id`,`employee_id`,`approval_level`),
  KEY `hr_leave_approval_hierarchy_shop_owner_id_employee_id_index` (`shop_owner_id`,`employee_id`),
  KEY `hr_leave_approval_hierarchy_approver_id_is_active_index` (`approver_id`,`is_active`),
  KEY `hr_leave_approval_hierarchy_delegated_to_index` (`delegated_to`),
  KEY `hr_leave_approval_hierarchy_employee_id_approval_level_index` (`employee_id`,`approval_level`),
  CONSTRAINT `hr_leave_approval_hierarchy_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_leave_approval_hierarchy_delegated_to_foreign` FOREIGN KEY (`delegated_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_leave_approval_hierarchy_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_leave_approval_hierarchy_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_leave_approval_hierarchy`
--

LOCK TABLES `hr_leave_approval_hierarchy` WRITE;
/*!40000 ALTER TABLE `hr_leave_approval_hierarchy` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_leave_approval_hierarchy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_leave_policies`
--

DROP TABLE IF EXISTS `hr_leave_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_leave_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `leave_type` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `accrual_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `accrual_frequency` enum('monthly','quarterly','annually','on_joining') NOT NULL DEFAULT 'monthly',
  `max_balance` int(11) DEFAULT NULL,
  `max_carry_forward` int(11) NOT NULL DEFAULT 0,
  `carry_forward_expires` tinyint(1) NOT NULL DEFAULT 0,
  `carry_forward_expiry_months` int(11) DEFAULT NULL,
  `min_service_days` int(11) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `requires_approval` tinyint(1) NOT NULL DEFAULT 1,
  `min_notice_days` int(11) NOT NULL DEFAULT 0,
  `min_days` int(11) NOT NULL DEFAULT 1,
  `max_days` int(11) NOT NULL DEFAULT 365,
  `allow_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `requires_document` tinyint(1) NOT NULL DEFAULT 0,
  `document_required_after_days` int(11) DEFAULT NULL,
  `allow_negative_balance` tinyint(1) NOT NULL DEFAULT 0,
  `negative_balance_limit` decimal(5,2) NOT NULL DEFAULT 0.00,
  `applicable_gender` enum('all','male','female') NOT NULL DEFAULT 'all',
  `applicable_departments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applicable_departments`)),
  `is_encashable` tinyint(1) NOT NULL DEFAULT 0,
  `encashable_after_days` int(11) DEFAULT NULL,
  `encashment_percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_leave_policies_shop_owner_id_leave_type_unique` (`shop_owner_id`,`leave_type`),
  KEY `hr_leave_policies_shop_owner_id_leave_type_index` (`shop_owner_id`,`leave_type`),
  KEY `hr_leave_policies_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`),
  CONSTRAINT `hr_leave_policies_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_leave_policies`
--

LOCK TABLES `hr_leave_policies` WRITE;
/*!40000 ALTER TABLE `hr_leave_policies` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_leave_policies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_onboarding_checklists`
--

DROP TABLE IF EXISTS `hr_onboarding_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_onboarding_checklists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_onboarding_checklists_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_onboarding_checklists_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`),
  CONSTRAINT `hr_onboarding_checklists_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_onboarding_checklists`
--

LOCK TABLES `hr_onboarding_checklists` WRITE;
/*!40000 ALTER TABLE `hr_onboarding_checklists` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_onboarding_checklists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_onboarding_tasks`
--

DROP TABLE IF EXISTS `hr_onboarding_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_onboarding_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `checklist_id` bigint(20) unsigned NOT NULL,
  `task_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` enum('employee','hr','manager','it') NOT NULL DEFAULT 'hr',
  `due_days` int(11) NOT NULL DEFAULT 7 COMMENT 'Days after hire date',
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `order` int(11) NOT NULL DEFAULT 0 COMMENT 'Task display order',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_onboarding_tasks_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_onboarding_tasks_checklist_id_index` (`checklist_id`),
  KEY `hr_onboarding_tasks_checklist_id_order_index` (`checklist_id`,`order`),
  KEY `hr_onboarding_tasks_assigned_to_index` (`assigned_to`),
  CONSTRAINT `hr_onboarding_tasks_checklist_id_foreign` FOREIGN KEY (`checklist_id`) REFERENCES `hr_onboarding_checklists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_onboarding_tasks_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_onboarding_tasks`
--

LOCK TABLES `hr_onboarding_tasks` WRITE;
/*!40000 ALTER TABLE `hr_onboarding_tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_onboarding_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_overtime_requests`
--

DROP TABLE IF EXISTS `hr_overtime_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_overtime_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shift_schedule_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Link to shift schedule if applicable',
  `overtime_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `hours` decimal(5,2) NOT NULL,
  `rate_multiplier` decimal(3,2) NOT NULL DEFAULT 1.50 COMMENT 'Overtime pay multiplier',
  `calculated_amount` decimal(10,2) DEFAULT NULL COMMENT 'Calculated overtime pay',
  `overtime_type` enum('weekday','weekend','holiday','emergency') NOT NULL DEFAULT 'weekday',
  `reason` text NOT NULL,
  `work_description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `payroll_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Link to payroll when processed',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_overtime_requests_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_overtime_requests_employee_id_index` (`employee_id`),
  KEY `hr_overtime_requests_shift_schedule_id_index` (`shift_schedule_id`),
  KEY `hr_overtime_requests_overtime_date_index` (`overtime_date`),
  KEY `hr_overtime_requests_status_index` (`status`),
  KEY `hr_overtime_requests_employee_id_overtime_date_index` (`employee_id`,`overtime_date`),
  KEY `hr_overtime_requests_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `hr_overtime_requests_shop_owner_id_overtime_date_index` (`shop_owner_id`,`overtime_date`),
  KEY `hr_overtime_requests_is_paid_index` (`is_paid`),
  KEY `hr_overtime_requests_approved_by_index` (`approved_by`),
  CONSTRAINT `hr_overtime_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_overtime_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_overtime_requests_shift_schedule_id_foreign` FOREIGN KEY (`shift_schedule_id`) REFERENCES `hr_shift_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hr_overtime_requests_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_overtime_requests`
--

LOCK TABLES `hr_overtime_requests` WRITE;
/*!40000 ALTER TABLE `hr_overtime_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_overtime_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_payroll_components`
--

DROP TABLE IF EXISTS `hr_payroll_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_payroll_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `component_type` enum('earning','deduction','benefit') NOT NULL,
  `component_name` varchar(100) NOT NULL,
  `component_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `calculation_method` enum('fixed','percentage','formula','attendance_based','hourly') NOT NULL DEFAULT 'fixed',
  `calculation_value` decimal(10,2) DEFAULT NULL,
  `calculation_base` varchar(50) DEFAULT NULL,
  `formula` text DEFAULT NULL,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 1,
  `is_statutory` tinyint(1) NOT NULL DEFAULT 0,
  `affects_gross` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `show_on_payslip` tinyint(1) NOT NULL DEFAULT 1,
  `category` varchar(50) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_payroll_components_payroll_id_component_type_index` (`payroll_id`,`component_type`),
  KEY `hr_payroll_components_shop_owner_id_component_code_index` (`shop_owner_id`,`component_code`),
  KEY `hr_payroll_components_component_type_is_statutory_index` (`component_type`,`is_statutory`),
  KEY `hr_payroll_components_component_type_index` (`component_type`),
  CONSTRAINT `hr_payroll_components_payroll_id_foreign` FOREIGN KEY (`payroll_id`) REFERENCES `payrolls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_payroll_components_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_payroll_components`
--

LOCK TABLES `hr_payroll_components` WRITE;
/*!40000 ALTER TABLE `hr_payroll_components` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_payroll_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_performance_cycles`
--

DROP TABLE IF EXISTS `hr_performance_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_performance_cycles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `cycle_type` enum('annual','quarterly','monthly') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `self_review_deadline` date NOT NULL,
  `manager_review_deadline` date NOT NULL,
  `status` enum('draft','active','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_performance_cycles_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_performance_cycles_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `hr_performance_cycles_shop_owner_id_cycle_type_index` (`shop_owner_id`,`cycle_type`),
  KEY `hr_performance_cycles_start_date_index` (`start_date`),
  KEY `hr_performance_cycles_end_date_index` (`end_date`),
  CONSTRAINT `hr_performance_cycles_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_performance_cycles`
--

LOCK TABLES `hr_performance_cycles` WRITE;
/*!40000 ALTER TABLE `hr_performance_cycles` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_performance_cycles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_performance_goals`
--

DROP TABLE IF EXISTS `hr_performance_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_performance_goals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `cycle_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `goal_description` text NOT NULL,
  `target_value` varchar(100) DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Goal weight in percentage',
  `status` enum('not_started','in_progress','achieved','not_achieved','cancelled') NOT NULL DEFAULT 'not_started',
  `due_date` date DEFAULT NULL,
  `progress_notes` text DEFAULT NULL,
  `actual_value` decimal(10,2) DEFAULT NULL COMMENT 'Actual achievement value',
  `self_rating` int(11) DEFAULT NULL COMMENT 'Employee self-rating 1-5',
  `manager_rating` int(11) DEFAULT NULL COMMENT 'Manager rating 1-5',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_performance_goals_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_performance_goals_cycle_id_index` (`cycle_id`),
  KEY `hr_performance_goals_employee_id_index` (`employee_id`),
  KEY `hr_performance_goals_employee_id_cycle_id_index` (`employee_id`,`cycle_id`),
  KEY `hr_performance_goals_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  KEY `hr_performance_goals_status_index` (`status`),
  CONSTRAINT `hr_performance_goals_cycle_id_foreign` FOREIGN KEY (`cycle_id`) REFERENCES `hr_performance_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_performance_goals_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_performance_goals_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_performance_goals`
--

LOCK TABLES `hr_performance_goals` WRITE;
/*!40000 ALTER TABLE `hr_performance_goals` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_performance_goals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_shift_schedules`
--

DROP TABLE IF EXISTS `hr_shift_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_shift_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shift_id` bigint(20) unsigned NOT NULL,
  `scheduled_date` date NOT NULL,
  `actual_start_time` time DEFAULT NULL,
  `actual_end_time` time DEFAULT NULL,
  `actual_break_duration` int(11) DEFAULT NULL COMMENT 'Actual break taken in minutes',
  `total_hours` decimal(5,2) DEFAULT NULL COMMENT 'Total hours worked',
  `regular_hours` decimal(5,2) DEFAULT NULL COMMENT 'Regular hours within shift',
  `overtime_hours` decimal(5,2) DEFAULT NULL COMMENT 'Overtime hours beyond shift',
  `status` enum('scheduled','in_progress','completed','absent','late','cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_date_unique` (`employee_id`,`scheduled_date`),
  KEY `hr_shift_schedules_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_shift_schedules_employee_id_index` (`employee_id`),
  KEY `hr_shift_schedules_shift_id_index` (`shift_id`),
  KEY `hr_shift_schedules_scheduled_date_index` (`scheduled_date`),
  KEY `hr_shift_schedules_employee_id_scheduled_date_index` (`employee_id`,`scheduled_date`),
  KEY `hr_shift_schedules_shop_owner_id_scheduled_date_status_index` (`shop_owner_id`,`scheduled_date`,`status`),
  KEY `hr_shift_schedules_status_index` (`status`),
  CONSTRAINT `hr_shift_schedules_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_shift_schedules_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `hr_shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_shift_schedules_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_shift_schedules`
--

LOCK TABLES `hr_shift_schedules` WRITE;
/*!40000 ALTER TABLE `hr_shift_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_shift_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_shifts`
--

DROP TABLE IF EXISTS `hr_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `break_duration` int(11) NOT NULL DEFAULT 0 COMMENT 'Break duration in minutes',
  `grace_period` int(11) NOT NULL DEFAULT 15 COMMENT 'Late arrival grace period in minutes',
  `is_overnight` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Shift spans midnight',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `overtime_multiplier` decimal(3,2) NOT NULL DEFAULT 1.50 COMMENT 'Overtime pay multiplier',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shift_code_unique` (`shop_owner_id`,`code`),
  KEY `hr_shifts_shop_owner_id_index` (`shop_owner_id`),
  KEY `hr_shifts_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`),
  KEY `hr_shifts_code_index` (`code`),
  CONSTRAINT `hr_shifts_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_shifts`
--

LOCK TABLES `hr_shifts` WRITE;
/*!40000 ALTER TABLE `hr_shifts` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_tax_brackets`
--

DROP TABLE IF EXISTS `hr_tax_brackets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_tax_brackets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `bracket_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `min_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_amount` decimal(10,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `fixed_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `calculation_type` enum('progressive','flat') NOT NULL DEFAULT 'progressive',
  `standard_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  `personal_allowance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_type` enum('income_tax','social_security','pension','other') NOT NULL DEFAULT 'income_tax',
  `filing_status` enum('single','married','all') NOT NULL DEFAULT 'all',
  `tax_year` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 0,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_tax_brackets_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`),
  KEY `hr_tax_brackets_tax_type_filing_status_is_active_index` (`tax_type`,`filing_status`,`is_active`),
  KEY `hr_tax_brackets_min_amount_max_amount_index` (`min_amount`,`max_amount`),
  KEY `hr_tax_brackets_effective_from_effective_to_index` (`effective_from`,`effective_to`),
  KEY `hr_tax_brackets_priority_index` (`priority`),
  CONSTRAINT `hr_tax_brackets_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_tax_brackets`
--

LOCK TABLES `hr_tax_brackets` WRITE;
/*!40000 ALTER TABLE `hr_tax_brackets` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_tax_brackets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_training_enrollments`
--

DROP TABLE IF EXISTS `hr_training_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_training_enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `training_program_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `training_session_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('enrolled','in_progress','completed','failed','cancelled','no_show') NOT NULL DEFAULT 'enrolled',
  `enrolled_date` date NOT NULL,
  `start_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `progress_percentage` int(11) NOT NULL DEFAULT 0,
  `assessment_score` decimal(5,2) DEFAULT NULL,
  `passed` tinyint(1) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `attendance_hours` int(11) NOT NULL DEFAULT 0,
  `enrolled_by` bigint(20) unsigned DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_training_enrollments_training_session_id_foreign` (`training_session_id`),
  KEY `hr_training_enrollments_employee_id_status_index` (`employee_id`,`status`),
  KEY `hr_training_enrollments_training_program_id_status_index` (`training_program_id`,`status`),
  KEY `hr_training_enrollments_shop_owner_id_status_index` (`shop_owner_id`,`status`),
  CONSTRAINT `hr_training_enrollments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_training_enrollments_training_program_id_foreign` FOREIGN KEY (`training_program_id`) REFERENCES `hr_training_programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hr_training_enrollments_training_session_id_foreign` FOREIGN KEY (`training_session_id`) REFERENCES `hr_training_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_training_enrollments`
--

LOCK TABLES `hr_training_enrollments` WRITE;
/*!40000 ALTER TABLE `hr_training_enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_training_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_training_programs`
--

DROP TABLE IF EXISTS `hr_training_programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_training_programs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('technical','soft_skills','compliance','leadership','safety','product','other') NOT NULL,
  `delivery_method` enum('classroom','online','hybrid','workshop','seminar','self_paced') NOT NULL,
  `duration_hours` int(11) DEFAULT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_participants` int(11) DEFAULT NULL,
  `prerequisites` text DEFAULT NULL,
  `learning_objectives` text DEFAULT NULL,
  `instructor_name` varchar(255) DEFAULT NULL,
  `instructor_email` varchar(255) DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `issues_certificate` tinyint(1) NOT NULL DEFAULT 0,
  `certificate_validity_months` int(11) DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_training_programs_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`),
  KEY `hr_training_programs_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_training_programs`
--

LOCK TABLES `hr_training_programs` WRITE;
/*!40000 ALTER TABLE `hr_training_programs` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_training_programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_training_sessions`
--

DROP TABLE IF EXISTS `hr_training_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_training_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `training_program_id` bigint(20) unsigned NOT NULL,
  `session_name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `online_meeting_link` text DEFAULT NULL,
  `available_seats` int(11) DEFAULT NULL,
  `enrolled_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `instructor_name` varchar(255) DEFAULT NULL,
  `session_notes` text DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_training_sessions_training_program_id_status_index` (`training_program_id`,`status`),
  KEY `hr_training_sessions_shop_owner_id_start_date_index` (`shop_owner_id`,`start_date`),
  KEY `hr_training_sessions_start_date_end_date_index` (`start_date`,`end_date`),
  CONSTRAINT `hr_training_sessions_training_program_id_foreign` FOREIGN KEY (`training_program_id`) REFERENCES `hr_training_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_training_sessions`
--

LOCK TABLES `hr_training_sessions` WRITE;
/*!40000 ALTER TABLE `hr_training_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `hr_training_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_balances`
--

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `year` year(4) NOT NULL,
  `vacation_days` int(11) NOT NULL DEFAULT 15,
  `sick_days` int(11) NOT NULL DEFAULT 10,
  `personal_days` int(11) NOT NULL DEFAULT 5,
  `maternity_days` int(11) NOT NULL DEFAULT 60,
  `paternity_days` int(11) NOT NULL DEFAULT 7,
  `used_vacation` int(11) NOT NULL DEFAULT 0,
  `used_sick` int(11) NOT NULL DEFAULT 0,
  `used_personal` int(11) NOT NULL DEFAULT 0,
  `used_maternity` int(11) NOT NULL DEFAULT 0,
  `used_paternity` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_balances_employee_id_year_unique` (`employee_id`,`year`),
  KEY `leave_balances_employee_id_index` (`employee_id`),
  KEY `leave_balances_shop_owner_id_index` (`shop_owner_id`),
  KEY `leave_balances_year_index` (`year`),
  CONSTRAINT `leave_balances_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_balances_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

LOCK TABLES `leave_balances` WRITE;
/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `leave_type` enum('vacation','sick','personal','maternity','paternity','unpaid') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `no_of_days` int(11) NOT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `reason` text NOT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approval_level` int(11) NOT NULL DEFAULT 1,
  `approver_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approval_date` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_requests_approved_by_foreign` (`approved_by`),
  KEY `leave_requests_employee_id_index` (`employee_id`),
  KEY `leave_requests_shop_owner_id_index` (`shop_owner_id`),
  KEY `leave_requests_status_index` (`status`),
  KEY `leave_requests_leave_type_index` (`leave_type`),
  KEY `leave_requests_start_date_end_date_index` (`start_date`,`end_date`),
  KEY `leave_requests_approver_id_foreign` (`approver_id`),
  CONSTRAINT `leave_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_requests_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_consolidated_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2025_08_29_150940_create_personal_access_tokens_table',1),(5,'2026_01_14_205002_create_shop_owners_consolidated_table',1),(6,'2026_01_14_205010_create_shop_documents_table',1),(7,'2026_01_15_110000_create_super_admins_table',1),(8,'2026_01_15_150000_create_roles_table',1),(9,'2026_01_15_150001_create_employees_consolidated_table',1),(10,'2026_01_15_150002_create_calendar_events_table',1),(11,'2026_01_15_150003_create_orders_table',1),(12,'2026_01_24_190000_add_description_to_roles_table',1),(13,'2026_01_24_200100_create_audit_logs_consolidated_table',1),(14,'2026_01_28_000000_create_finance_accounts_consolidated_table',1),(15,'2026_01_28_000002_create_finance_journal_entries_table',1),(16,'2026_01_28_000003_create_finance_journal_lines_table',1),(17,'2026_01_30_000000_create_budgets_table',1),(18,'2026_01_30_000000_create_sessions_table',1),(19,'2026_01_30_000001_create_recurring_transactions_table',1),(20,'2026_01_30_000002_create_recurring_transaction_lines_table',1),(21,'2026_01_30_000003_create_recurring_transaction_executions_table',1),(22,'2026_01_30_100000_create_finance_invoices_table',1),(23,'2026_01_30_120000_create_finance_expenses_table',1),(24,'2026_01_30_create_cost_centers_table',1),(25,'2026_01_30_create_expense_allocations_table',1),(26,'2026_01_31_100000_create_reconciliations_table',1),(27,'2026_01_31_110000_create_approvals_table',1),(28,'2026_01_31_120000_add_approval_fields_to_finance_tables',1),(29,'2026_01_31_120001_add_finance_roles_to_users',1),(30,'2026_01_31_140000_add_receipt_attachment_to_finance_expenses',1),(31,'2026_01_31_150000_create_finance_tax_rates_table',1),(32,'2026_02_01_054211_create_notifications_table',1),(33,'2026_02_01_055752_create_hr_training_programs_table',1),(34,'2026_02_01_055753_create_hr_training_sessions_table',1),(35,'2026_02_01_055754_create_hr_training_enrollments_table',1),(36,'2026_02_01_055755_create_hr_certifications_table',1),(37,'2026_02_01_100000_create_hr_attendance_records_table',1),(38,'2026_02_01_100001_create_hr_leave_requests_table',1),(39,'2026_02_01_100002_create_hr_payrolls_table',1),(40,'2026_02_01_100003_create_hr_performance_reviews_table',1),(41,'2026_02_01_100004_create_hr_departments_table',1),(42,'2026_02_01_100005_create_hr_leave_balances_table',1),(43,'2026_02_01_100007_create_hr_employee_documents_table',1),(44,'2026_02_01_100008_create_hr_audit_logs_table',1),(45,'2026_02_01_100009_create_hr_leave_policies_table',1),(46,'2026_02_01_100010_create_hr_leave_approval_hierarchy_table',1),(47,'2026_02_01_100011_add_approval_fields_to_leave_requests_table',1),(48,'2026_02_01_100012_create_hr_payroll_components_table',1),(49,'2026_02_01_100013_create_hr_tax_brackets_table',1),(50,'2026_02_01_100014_create_hr_shifts_table',1),(51,'2026_02_01_100015_create_hr_shift_schedules_table',1),(52,'2026_02_01_100016_create_hr_overtime_requests_table',1),(53,'2026_02_01_100020_create_hr_performance_cycles_table',1),(54,'2026_02_01_100021_create_hr_performance_goals_table',1),(55,'2026_02_01_100022_create_hr_competency_evaluations_table',1),(56,'2026_02_01_100030_create_hr_onboarding_checklists_table',1),(57,'2026_02_01_100031_create_hr_onboarding_tasks_table',1),(58,'2026_02_01_100032_create_hr_employee_onboarding_table',1),(59,'2026_02_01_134556_add_gross_salary_to_payrolls_table',1),(60,'2026_02_02_000000_add_shipped_status_to_orders',1),(61,'2026_02_02_091459_add_job_order_link_to_finance_invoices_table',1),(62,'2026_02_02_094831_create_approval_delegations_table',1),(63,'2026_02_02_094847_add_job_order_id_to_finance_expenses_table',1),(64,'2026_02_02_100000_create_products_table',1),(65,'2026_02_02_120000_create_notifications_table',1),(66,'2026_02_03_000000_create_order_items_table',1),(67,'2026_02_03_000001_add_customer_info_to_orders_table',1),(68,'2026_02_03_002001_create_cart_items_table',1),(69,'2026_02_03_020000_add_tracking_fields_to_orders_table',1),(70,'2026_02_03_100000_create_product_variants_table',1),(71,'2026_02_04_120047_create_activity_log_table',1),(72,'2026_02_04_120048_add_event_column_to_activity_log_table',1),(73,'2026_02_04_120049_add_batch_uuid_column_to_activity_log_table',1),(75,'2026_02_04_134459_rename_old_roles_table_to_legacy_roles',3),(79,'2026_02_04_134236_create_permission_tables',4),(80,'2026_02_04_remove_unused_finance_permissions',4);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_preferences`
--

DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `email_expense_approval` tinyint(1) NOT NULL DEFAULT 1,
  `email_leave_approval` tinyint(1) NOT NULL DEFAULT 1,
  `email_invoice_created` tinyint(1) NOT NULL DEFAULT 0,
  `email_delegation_assigned` tinyint(1) NOT NULL DEFAULT 1,
  `browser_expense_approval` tinyint(1) NOT NULL DEFAULT 1,
  `browser_leave_approval` tinyint(1) NOT NULL DEFAULT 1,
  `browser_invoice_created` tinyint(1) NOT NULL DEFAULT 1,
  `browser_delegation_assigned` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_preferences_user_id_unique` (`user_id`),
  CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_preferences`
--

LOCK TABLES `notification_preferences` WRITE;
/*!40000 ALTER TABLE `notification_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `shop_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_is_read_index` (`user_id`,`is_read`),
  KEY `notifications_shop_id_index` (`shop_id`),
  KEY `notifications_created_at_index` (`created_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_slug` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `size` varchar(255) DEFAULT NULL,
  `color` varchar(255) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_order_id_index` (`order_id`),
  KEY `order_items_product_id_index` (`product_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(255) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `tracking_number` varchar(255) DEFAULT NULL,
  `carrier_company` varchar(255) DEFAULT NULL,
  `carrier_name` varchar(255) DEFAULT NULL,
  `carrier_phone` varchar(50) DEFAULT NULL,
  `tracking_link` varchar(500) DEFAULT NULL,
  `eta` varchar(255) DEFAULT NULL,
  `order_number` varchar(255) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  `invoice_generated` tinyint(1) NOT NULL DEFAULT 0,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_number_unique` (`order_number`),
  KEY `orders_shop_owner_id_index` (`shop_owner_id`),
  KEY `orders_customer_id_index` (`customer_id`),
  KEY `orders_order_number_index` (`order_number`),
  KEY `orders_status_index` (`status`),
  KEY `orders_invoice_id_index` (`invoice_id`),
  CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payrolls`
--

DROP TABLE IF EXISTS `payrolls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payrolls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `payroll_period` varchar(255) NOT NULL,
  `base_salary` decimal(12,2) NOT NULL,
  `gross_salary` decimal(10,2) DEFAULT NULL,
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL,
  `status` enum('pending','processed','paid') NOT NULL DEFAULT 'pending',
  `payment_date` timestamp NULL DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer','check') NOT NULL DEFAULT 'bank_transfer',
  `tax_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss_contributions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `philhealth` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pag_ibig` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payrolls_employee_id_payroll_period_unique` (`employee_id`,`payroll_period`),
  KEY `payrolls_employee_id_index` (`employee_id`),
  KEY `payrolls_shop_owner_id_index` (`shop_owner_id`),
  KEY `payrolls_payroll_period_index` (`payroll_period`),
  KEY `payrolls_status_index` (`status`),
  CONSTRAINT `payrolls_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payrolls_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payrolls`
--

LOCK TABLES `payrolls` WRITE;
/*!40000 ALTER TABLE `payrolls` DISABLE KEYS */;
/*!40000 ALTER TABLE `payrolls` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_reviews`
--

DROP TABLE IF EXISTS `performance_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `reviewer_name` varchar(255) NOT NULL,
  `review_date` date NOT NULL,
  `review_period` varchar(255) NOT NULL,
  `overall_rating` int(11) NOT NULL DEFAULT 1,
  `communication_skills` int(11) NOT NULL DEFAULT 1,
  `teamwork_collaboration` int(11) NOT NULL DEFAULT 1,
  `reliability_responsibility` int(11) NOT NULL DEFAULT 1,
  `productivity_efficiency` int(11) NOT NULL DEFAULT 1,
  `comments` text DEFAULT NULL,
  `goals` text DEFAULT NULL,
  `improvement_areas` text DEFAULT NULL,
  `status` enum('draft','submitted','completed') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `performance_reviews_employee_id_index` (`employee_id`),
  KEY `performance_reviews_shop_owner_id_index` (`shop_owner_id`),
  KEY `performance_reviews_review_date_index` (`review_date`),
  KEY `performance_reviews_status_index` (`status`),
  KEY `performance_reviews_review_period_index` (`review_period`),
  CONSTRAINT `performance_reviews_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_reviews_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_reviews`
--

LOCK TABLES `performance_reviews` WRITE;
/*!40000 ALTER TABLE `performance_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `size` varchar(255) DEFAULT NULL,
  `color` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_variant` (`product_id`,`size`,`color`),
  KEY `product_variants_product_id_index` (`product_id`),
  KEY `product_variants_product_id_is_active_index` (`product_id`,`is_active`),
  CONSTRAINT `product_variants_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `compare_at_price` decimal(10,2) DEFAULT NULL,
  `brand` varchar(255) DEFAULT NULL,
  `category` varchar(255) NOT NULL DEFAULT 'shoes',
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `main_image` varchar(255) DEFAULT NULL,
  `additional_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_images`)),
  `sizes_available` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sizes_available`)),
  `colors_available` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`colors_available`)),
  `sku` varchar(255) DEFAULT NULL,
  `weight` decimal(8,2) DEFAULT NULL,
  `views_count` int(11) NOT NULL DEFAULT 0,
  `sales_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_slug_unique` (`slug`),
  KEY `products_shop_owner_id_index` (`shop_owner_id`),
  KEY `products_slug_index` (`slug`),
  KEY `products_category_index` (`category`),
  KEY `products_is_active_index` (`is_active`),
  KEY `products_shop_owner_id_is_active_index` (`shop_owner_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reconciliations`
--

DROP TABLE IF EXISTS `reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reconciliations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `journal_entry_line_id` bigint(20) unsigned NOT NULL,
  `bank_transaction_reference` varchar(255) DEFAULT NULL,
  `statement_date` date NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reconciled_by` bigint(20) unsigned NOT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','matched','reconciled','discrepancy') NOT NULL DEFAULT 'pending',
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reconciliations_journal_entry_line_id_foreign` (`journal_entry_line_id`),
  KEY `reconciliations_reconciled_by_foreign` (`reconciled_by`),
  KEY `reconciliations_account_id_index` (`account_id`),
  KEY `reconciliations_statement_date_index` (`statement_date`),
  KEY `reconciliations_status_index` (`status`),
  KEY `reconciliations_shop_owner_id_index` (`shop_owner_id`),
  CONSTRAINT `reconciliations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `finance_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reconciliations_journal_entry_line_id_foreign` FOREIGN KEY (`journal_entry_line_id`) REFERENCES `finance_journal_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reconciliations_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reconciliations`
--

LOCK TABLES `reconciliations` WRITE;
/*!40000 ALTER TABLE `reconciliations` DISABLE KEYS */;
/*!40000 ALTER TABLE `reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recurring_transaction_executions`
--

DROP TABLE IF EXISTS `recurring_transaction_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recurring_transaction_executions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recurring_transaction_id` bigint(20) unsigned NOT NULL,
  `finance_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `execution_date` date NOT NULL,
  `status` enum('pending','executed','skipped','failed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `executed_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rt_exec_fje_id_fk` (`finance_journal_entry_id`),
  KEY `recurring_transaction_executions_recurring_transaction_id_index` (`recurring_transaction_id`),
  KEY `recurring_transaction_executions_status_index` (`status`),
  CONSTRAINT `rt_exec_fje_id_fk` FOREIGN KEY (`finance_journal_entry_id`) REFERENCES `finance_journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rt_exec_rt_id_fk` FOREIGN KEY (`recurring_transaction_id`) REFERENCES `recurring_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recurring_transaction_executions`
--

LOCK TABLES `recurring_transaction_executions` WRITE;
/*!40000 ALTER TABLE `recurring_transaction_executions` DISABLE KEYS */;
/*!40000 ALTER TABLE `recurring_transaction_executions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recurring_transaction_lines`
--

DROP TABLE IF EXISTS `recurring_transaction_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recurring_transaction_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recurring_transaction_id` bigint(20) unsigned NOT NULL,
  `chart_of_account_id` bigint(20) unsigned NOT NULL,
  `debit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `cost_center` varchar(255) DEFAULT NULL,
  `line_number` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recurring_transaction_lines_recurring_transaction_id_index` (`recurring_transaction_id`),
  KEY `recurring_transaction_lines_chart_of_account_id_index` (`chart_of_account_id`),
  CONSTRAINT `rt_lines_coa_id_fk` FOREIGN KEY (`chart_of_account_id`) REFERENCES `finance_accounts` (`id`),
  CONSTRAINT `rt_lines_rt_id_fk` FOREIGN KEY (`recurring_transaction_id`) REFERENCES `recurring_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recurring_transaction_lines`
--

LOCK TABLES `recurring_transaction_lines` WRITE;
/*!40000 ALTER TABLE `recurring_transaction_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `recurring_transaction_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recurring_transactions`
--

DROP TABLE IF EXISTS `recurring_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recurring_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `frequency` enum('daily','weekly','monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
  `day_of_month` int(11) DEFAULT NULL,
  `month` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `total_debit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_credit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recurring_transactions_shop_owner_id_index` (`shop_owner_id`),
  CONSTRAINT `recurring_transactions_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recurring_transactions`
--

LOCK TABLES `recurring_transactions` WRITE;
/*!40000 ALTER TABLE `recurring_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `recurring_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('5O7PBfiLxxzFhSiAoy2yb0nCbVVrRwQTxtz0t6YR',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0','YTozOntzOjY6Il90b2tlbiI7czo0MDoiWUd6aVRlZjVxRnFFR3VXbUoyWGRKV2wxUGp4OVJWSXFYczZEQTlqdCI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzI6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMC91c2VyL2xvZ2luIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==',1770251402);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_documents`
--

DROP TABLE IF EXISTS `shop_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shop_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_owner_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_documents_shop_owner_id_foreign` (`shop_owner_id`),
  CONSTRAINT `shop_documents_shop_owner_id_foreign` FOREIGN KEY (`shop_owner_id`) REFERENCES `shop_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_documents`
--

LOCK TABLES `shop_documents` WRITE;
/*!40000 ALTER TABLE `shop_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_owners`
--

DROP TABLE IF EXISTS `shop_owners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shop_owners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `business_name` varchar(255) NOT NULL,
  `business_address` varchar(255) NOT NULL,
  `business_type` varchar(255) NOT NULL,
  `registration_type` varchar(255) NOT NULL,
  `operating_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`operating_hours`)),
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `rejection_reason` varchar(500) DEFAULT NULL,
  `suspension_reason` text DEFAULT NULL,
  `monthly_target` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_owners_email_unique` (`email`),
  KEY `shop_owners_email_index` (`email`),
  KEY `shop_owners_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_owners`
--

LOCK TABLES `shop_owners` WRITE;
/*!40000 ALTER TABLE `shop_owners` DISABLE KEYS */;
/*!40000 ALTER TABLE `shop_owners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `super_admins`
--

DROP TABLE IF EXISTS `super_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `role` enum('admin','super_admin') NOT NULL DEFAULT 'admin',
  `status` enum('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `super_admins_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `super_admins`
--

LOCK TABLES `super_admins` WRITE;
/*!40000 ALTER TABLE `super_admins` DISABLE KEYS */;
INSERT INTO `super_admins` VALUES (1,'Super','Administrator','admin@thesis.com','$2y$12$w0lsD0rONPNeLNsvbGaiyOk0WR0fLcRRaf82E.OZJxNClEN1Z8gcW','09123456789','super_admin','active',NULL,NULL,NULL,'2026-02-04 16:23:52','2026-02-04 16:23:52');
/*!40000 ALTER TABLE `super_admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `role` enum('HR','FINANCE_STAFF','FINANCE_MANAGER','CRM','MANAGER','STAFF','SUPER_ADMIN') DEFAULT NULL,
  `approval_limit` decimal(15,2) DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `shop_owner_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_email_index` (`email`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`),
  KEY `users_shop_owner_id_index` (`shop_owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-05  8:30:42
