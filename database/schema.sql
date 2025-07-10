-- Task Management System Database Schema
-- Compatible with MariaDB 10.x

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

USE `task_management_db`;

-- Table structure for table `users`
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(100) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('admin','manager','mechanic') NOT NULL DEFAULT 'mechanic',
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_role` (`role`),
  KEY `idx_email` (`email`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `tasks`
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text,
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
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_assigned_by` (`assigned_by`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_due_date` (`due_date`),
  CONSTRAINT `fk_task_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `notifications`
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `type` enum('task_assigned','task_updated','task_completed','deadline_warning','comment_added') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_is_read` (`is_read`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample users with hashed passwords
INSERT INTO `users` (`username`, `email`, `password`, `first_name`, `last_name`, `role`, `phone`, `department`) VALUES
('admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'admin', '+1234567890', 'IT'),
('manager1', 'manager1@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Manager', 'manager', '+1234567891', 'Operations'),
('mechanic1', 'mechanic1@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike', 'Smith', 'mechanic', '+1234567893', 'Field Operations'),
('mechanic2', 'mechanic2@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Alex', 'Brown', 'mechanic', '+1234567894', 'Field Operations');

-- Note: Default password for all users is "password123"

-- Insert sample tasks
INSERT INTO `tasks` (`title`, `description`, `priority`, `status`, `assigned_to`, `assigned_by`, `category`, `location`, `equipment`, `estimated_hours`, `due_date`) VALUES
('Engine Oil Change - Unit 101', 'Perform routine engine oil change on excavator unit 101. Check oil levels, replace filter, and document maintenance.', 'medium', 'pending', 3, 2, 'Preventive Maintenance', 'Workshop Bay 1', 'Excavator CAT 320', 2.00, DATE_ADD(NOW(), INTERVAL 2 DAY)),
('Hydraulic System Inspection', 'Complete hydraulic system inspection on crane unit. Check for leaks, pressure levels, and hose condition.', 'high', 'in_progress', 4, 2, 'Inspection', 'Yard Area B', 'Mobile Crane', 4.00, DATE_ADD(NOW(), INTERVAL 1 DAY)),
('Brake System Repair', 'Replace brake pads and check brake fluid levels on dump truck fleet.', 'urgent', 'pending', 3, 1, 'Repair', 'Workshop Bay 2', 'Dump Truck Fleet', 6.00, DATE_ADD(NOW(), INTERVAL 4 HOUR));

COMMIT;
