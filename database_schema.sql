/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: task_management_db
-- ------------------------------------------------------
-- Server version	10.11.11-MariaDB-0+deb12u1

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
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES
(1,1,'User logged in successfully',NULL,NULL,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-08 11:24:44'),
(2,2,'Task status updated',NULL,NULL,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-08 10:24:44'),
(3,3,'User logged in successfully',NULL,NULL,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-08 09:24:44'),
(4,5,'Problem reported: Hydraulic leak in Unit A','problems',1,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-10 05:47:36'),
(5,5,'Problem reported: Unusual noise from conveyor belt','problems',2,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-10 05:47:36'),
(6,5,'Problem reported: Engine overheating warning','problems',3,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-10 05:47:36'),
(7,5,'Problem reported: Brake system soft pedal','problems',4,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-10 05:47:36'),
(8,5,'Problem reported: Oil pressure warning light','problems',5,NULL,NULL,'192.168.2.15','Mozilla/5.0','2025-07-10 05:47:36');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#007bff',
  `icon` varchar(50) DEFAULT 'fas fa-tag',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`),
  KEY `idx_active` (`is_active`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES
(1,'Preventive Maintenance','Scheduled maintenance tasks','#28a745','fas fa-calendar-check',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30'),
(2,'Repair','Equipment repair tasks','#dc3545','fas fa-tools',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30'),
(3,'Inspection','Safety and quality inspections','#17a2b8','fas fa-search',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30'),
(4,'Installation','New equipment installation','#6f42c1','fas fa-cogs',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30'),
(5,'Emergency','Urgent emergency repairs','#fd7e14','fas fa-exclamation-triangle',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30'),
(6,'Testing','Equipment testing and diagnostics','#20c997','fas fa-flask',1,0,'2025-07-10 05:11:30','2025-07-10 05:11:30');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `problem_id` int(11) DEFAULT NULL,
  `type` enum('task_assigned','task_updated','task_completed','deadline_warning','comment_added','problem_reported','problem_assigned','problem_updated','problem_resolved') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_notifications_user_read` (`user_id`,`is_read`),
  KEY `problem_id` (`problem_id`),
  CONSTRAINT `fk_notification_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES
(1,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Hydraulic System Inspection\' was marked as completed by Mike Smith',0,'2025-07-08 10:54:19','normal',NULL),
(2,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' was marked as in_progress by Mike Smith',0,'2025-07-08 10:54:27','normal',NULL),
(3,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Engine Oil Change - Unit 101\' was marked as in_progress by Mike Smith',0,'2025-07-08 10:54:34','normal',NULL),
(4,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Weekly Safety Inspection\' was marked as in_progress by Mike Smith',0,'2025-07-08 10:54:40','normal',NULL),
(5,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Generator Maintenance\' was marked as in_progress by Mike Smith',0,'2025-07-08 11:11:53','normal',NULL),
(6,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to completed',0,'2025-07-09 06:52:16','normal',NULL),
(7,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Engine Oil Change - Unit 101\' status changed to in_progress by Mike Smith',0,'2025-07-09 07:36:31','normal',NULL),
(8,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to in_progress by Mike Smith',0,'2025-07-09 07:36:34','normal',NULL),
(9,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 06:42:05\' status changed to in_progress by Mike Smith',0,'2025-07-09 07:36:38','normal',NULL),
(10,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to completed by Mike Smith',0,'2025-07-09 07:37:38','normal',NULL),
(11,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Engine Oil Change - Unit 101\' status changed to completed by Mike Smith',0,'2025-07-09 07:37:44','normal',NULL),
(12,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Engine Oil Change - Unit 101\' status changed to completed by Mike Smith',0,'2025-07-09 07:37:50','normal',NULL),
(13,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 06:42:05\' status changed to completed by Mike Smith',0,'2025-07-09 07:37:56','normal',NULL),
(14,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Weekly Safety Inspection\' status changed to completed by Mike Smith',0,'2025-07-09 07:38:01','normal',NULL),
(15,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Generator Maintenance\' status changed to completed by Mike Smith',0,'2025-07-09 07:38:05','normal',NULL),
(16,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to pending by Mike Smith',0,'2025-07-09 07:40:21','normal',NULL),
(17,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to in_progress by Mike Smith',0,'2025-07-09 08:20:09','normal',NULL),
(18,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Brake System Repair\' status changed to completed by Mike Smith',0,'2025-07-09 08:22:28','normal',NULL),
(19,2,NULL,NULL,'task_updated','Task Updated','Task \'Debug Test Task - 2025-07-09 06:42:05\' status: pending',0,'2025-07-09 08:31:02','normal',NULL),
(20,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'Frontend Debug Test - 2025-07-09T09:17:48.757Z\'',0,'2025-07-09 09:17:48','normal',NULL),
(21,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'Frontend Debug Test - 2025-07-09T09:18:10.639Z\'',0,'2025-07-09 09:18:11','normal',NULL),
(22,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Frontend Debug Test - 2025-07-09T09:18:10.639Z\' status changed to in_progress',0,'2025-07-09 10:09:43','normal',NULL),
(23,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Frontend Debug Test - 2025-07-09T09:17:48.757Z\' status changed to in_progress',0,'2025-07-09 10:09:47','normal',NULL),
(24,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 06:42:05\' status changed to in_progress',0,'2025-07-09 10:09:52','normal',NULL),
(25,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 09:17:47\' status changed to in_progress',0,'2025-07-09 10:09:56','normal',NULL),
(26,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'Test Task via API\'',0,'2025-07-09 10:13:27','normal',NULL),
(27,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Frontend Debug Test - 2025-07-09T09:18:10.639Z\' status changed to completed',0,'2025-07-09 10:37:13','normal',NULL),
(28,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 06:42:05\' status changed to completed',0,'2025-07-09 10:37:19','normal',NULL),
(29,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Frontend Debug Test - 2025-07-09T09:17:48.757Z\' status changed to completed',0,'2025-07-09 10:37:24','normal',NULL),
(30,2,NULL,NULL,'task_updated','Task Status Updated','Task \'Debug Test Task - 2025-07-09 09:17:47\' status changed to completed',0,'2025-07-09 10:37:34','normal',NULL),
(31,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Test Task via API\' status changed to in_progress',0,'2025-07-09 10:37:41','normal',NULL),
(32,1,NULL,NULL,'task_updated','Task Status Updated','Task \'Test Task via API\' status changed to on_hold',0,'2025-07-09 10:37:58','normal',NULL),
(33,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'fgfgfgf\'',0,'2025-07-09 12:08:10','normal',NULL),
(34,2,NULL,NULL,'task_updated','Task Status Updated','Task \'fgfgfgf\' status changed to in_progress',0,'2025-07-09 12:10:48','normal',NULL),
(35,2,NULL,NULL,'task_updated','Task Status Updated','Task \'fgfgfgf\' status changed to completed',0,'2025-07-09 12:10:56','normal',NULL),
(36,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'Testa darbs Mikam\'',0,'2025-07-09 12:25:04','normal',NULL),
(37,3,28,NULL,'task_assigned','New Task Assigned','New task: \'Testa darbs vēlreiz Mikam\'',0,'2025-07-09 12:48:16','normal',NULL),
(38,1,28,NULL,'task_updated','Task Status Updated','Task \'Testa darbs vēlreiz Mikam\' status changed to in_progress',0,'2025-07-09 12:50:03','normal',NULL),
(39,3,NULL,NULL,'task_assigned','New Task Assigned','New task: \'Testa darbs\'',0,'2025-07-10 04:44:58','normal',NULL),
(40,1,28,NULL,'task_updated','Task Status Updated','Task \'Testa darbs vēlreiz Mikam\' status changed to completed',0,'2025-07-10 05:15:50','normal',NULL);
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `problem_comments`
--

DROP TABLE IF EXISTS `problem_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `problem_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `problem_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_problem_id` (`problem_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `problem_comments_ibfk_1` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `problem_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `problem_comments`
--

LOCK TABLES `problem_comments` WRITE;
/*!40000 ALTER TABLE `problem_comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `problem_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `problems`
--

DROP TABLE IF EXISTS `problems`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('reported','assigned','in_progress','resolved','closed') NOT NULL DEFAULT 'reported',
  `category` varchar(100) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `equipment` varchar(200) DEFAULT NULL,
  `reported_by` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `severity` enum('minor','moderate','major','critical') NOT NULL DEFAULT 'moderate',
  `impact` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `urgency` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `estimated_resolution_time` int(11) DEFAULT NULL COMMENT 'in hours',
  `actual_resolution_time` int(11) DEFAULT NULL COMMENT 'in hours',
  `resolution_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `task_id` (`task_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_reported_by` (`reported_by`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status_priority` (`status`,`priority`),
  CONSTRAINT `problems_ibfk_1` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `problems_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `problems_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `problems_ibfk_4` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `problems`
--

LOCK TABLES `problems` WRITE;
/*!40000 ALTER TABLE `problems` DISABLE KEYS */;
INSERT INTO `problems` VALUES
(1,'Hydraulic leak in Unit A','Noticed hydraulic fluid pooling under Unit A during morning inspection. Appears to be from main cylinder.','high','reported','Hydraulic System','Workshop Bay 1','Excavator Unit A',5,NULL,NULL,NULL,'major','high','high',NULL,NULL,NULL,NULL,'2025-07-10 05:47:36','2025-07-10 05:47:36',NULL),
(2,'Unusual noise from conveyor belt','Grinding noise coming from conveyor belt motor. Getting progressively worse over past 2 days.','medium','reported','Mechanical','Production Line 2','Conveyor Belt #3',5,NULL,NULL,NULL,'moderate','medium','medium',NULL,NULL,NULL,NULL,'2025-07-10 05:47:36','2025-07-10 05:47:36',NULL),
(3,'Engine overheating warning','Temperature gauge showing red, automatic shutdown triggered twice today.','urgent','reported','Engine','Yard Area B','Mobile Crane MC-01',5,NULL,NULL,NULL,'critical','critical','urgent',NULL,NULL,NULL,NULL,'2025-07-10 05:47:36','2025-07-10 05:47:36',NULL),
(4,'Brake system soft pedal','Brake pedal goes to floor, requires pumping to get stopping power. Safety concern.','urgent','reported','Brake System','Vehicle Fleet','Forklift FL-05',5,NULL,NULL,NULL,'major','critical','urgent',NULL,NULL,NULL,NULL,'2025-07-10 05:47:36','2025-07-10 05:47:36',NULL),
(5,'Oil pressure warning light','Oil pressure warning light comes on intermittently during operation.','high','reported','Engine','Workshop Bay 3','Generator Set GS-02',5,NULL,NULL,NULL,'moderate','high','high',NULL,NULL,NULL,NULL,'2025-07-10 05:47:36','2025-07-10 05:47:36',NULL);
/*!40000 ALTER TABLE `problems` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `task_comments`
--

DROP TABLE IF EXISTS `task_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_comment_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `task_comments`
--

LOCK TABLES `task_comments` WRITE;
/*!40000 ALTER TABLE `task_comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `task_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','completed','cancelled','on_hold') NOT NULL DEFAULT 'pending',
  `assigned_to` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `equipment` varchar(200) DEFAULT NULL,
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `actual_hours` decimal(5,2) DEFAULT NULL,
  `start_date` timestamp NULL DEFAULT NULL,
  `due_date` timestamp NULL DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `progress_percentage` int(3) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `tags` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_assigned_by` (`assigned_by`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_tasks_status_priority` (`status`,`priority`),
  KEY `idx_tasks_assigned_status` (`assigned_to`,`status`),
  CONSTRAINT `fk_task_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasks`
--

LOCK TABLES `tasks` WRITE;
/*!40000 ALTER TABLE `tasks` DISABLE KEYS */;
INSERT INTO `tasks` VALUES
(28,'Testa darbs vēlreiz Mikam','ss','medium','completed',3,1,'Repair','M2','Skrūves',NULL,NULL,'2025-07-09 12:50:03','2025-07-10 13:47:00','2025-07-10 05:15:50',100,NULL,'2025-07-09 12:48:15','2025-07-10 05:15:50',NULL,NULL);
/*!40000 ALTER TABLE `tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('admin','manager','mechanic','operator') NOT NULL DEFAULT 'mechanic',
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profile_image` varchar(255) DEFAULT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_email` (`email`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin','admin@company.com','admin123','System','Administrator','admin','+1234567890','IT',1,'2025-07-10 05:53:02','2025-07-08 09:55:10','2025-07-10 05:53:02',NULL,NULL),
(2,'manager1','manager1@company.com','manager123','John','Manager','manager','+1234567891','Operations',1,'2025-07-10 05:54:24','2025-07-08 09:55:10','2025-07-10 05:54:24',NULL,NULL),
(3,'mechanic1','mechanic1@company.com','mechanic123','Mike','Smith','mechanic','+1234567893','Field Operations',1,'2025-07-10 05:54:04','2025-07-08 09:55:10','2025-07-10 05:54:04',NULL,NULL),
(4,'mechanic2','mechanic2@company.com','mechanic123','Alex','Brown','mechanic','+1234567894','Field Operations',1,NULL,'2025-07-08 09:55:10','2025-07-08 10:33:13',NULL,NULL),
(5,'operator1','operator1@company.com','operator123','Sarah','Wilson','operator',NULL,'Operations',1,'2025-07-10 05:53:44','2025-07-10 05:47:36','2025-07-10 05:53:44',NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'task_management_db'
--

--
-- Dumping routines for database 'task_management_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-07-10  8:54:58
