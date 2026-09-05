-- MAMCET Placement & Learning Portal - Training Management & Attendance Module Migration
-- Safe to execute alongside existing tables without data loss.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Trainings table
CREATE TABLE IF NOT EXISTS `trainings` (
  `training_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `training_url` VARCHAR(500) DEFAULT NULL,
  `thumbnail_url` VARCHAR(255) DEFAULT NULL,
  `trainer_name` VARCHAR(150) DEFAULT NULL,
  `trainer_access_token` VARCHAR(64) NOT NULL,
  `attendance_submitted_at` DATETIME DEFAULT NULL,
  `attendance_submitted_by` VARCHAR(150) DEFAULT NULL,
  `event_type` ENUM('Online', 'Offline') NOT NULL DEFAULT 'Online',
  `venue_location` VARCHAR(500) DEFAULT NULL,
  `start_date_time` DATETIME NOT NULL,
  `end_date_time` DATETIME NOT NULL,
  `minimum_engagement_seconds` INT DEFAULT 0,
  `status` ENUM('Draft', 'Upcoming', 'Active', 'Completed', 'Archived') DEFAULT 'Draft',
  `attendance_rule` VARCHAR(50) DEFAULT 'minimum_duration',
  `session_id` INT DEFAULT 1,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived_at` DATETIME DEFAULT NULL,
  INDEX `idx_trainings_status` (`status`),
  INDEX `idx_trainings_dates` (`start_date_time`, `end_date_time`),
  UNIQUE KEY `uk_trainings_trainer_access_token` (`trainer_access_token`),
  FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Training Departments mapping table
CREATE TABLE IF NOT EXISTS `training_departments` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `training_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_training_dept` (`training_id`, `dept_id`),
  INDEX `idx_training_dept_dept` (`dept_id`),
  FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Training Attendance table (Summary per student per training)
CREATE TABLE IF NOT EXISTS `training_attendance` (
  `attendance_id` INT AUTO_INCREMENT PRIMARY KEY,
  `training_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `status` ENUM('Pending', 'Present', 'Absent', 'Late', 'Excused', 'Partial', 'In Progress') NOT NULL DEFAULT 'Pending',
  `marked_by` VARCHAR(150) DEFAULT NULL,
  `marked_at` DATETIME DEFAULT NULL,
  `attendance_note` TEXT DEFAULT NULL,
  `first_accessed_at` DATETIME DEFAULT NULL,
  `last_activity_at` DATETIME DEFAULT NULL,
  `total_engagement_seconds` INT DEFAULT 0,
  `attended_at` DATETIME DEFAULT NULL,
  `completion_percentage` DECIMAL(5,2) DEFAULT 0.00,
  `session_count` INT DEFAULT 1,
  `manually_adjusted` TINYINT(1) DEFAULT 0,
  `adjustment_reason` TEXT DEFAULT NULL,
  `adjusted_by` INT DEFAULT NULL,
  `adjusted_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_student_training` (`training_id`, `student_id`),
  INDEX `idx_attendance_status` (`training_id`, `status`),
  INDEX `idx_attendance_student` (`student_id`),
  FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Trainer attendance audit history
CREATE TABLE IF NOT EXISTS `training_attendance_audit` (
  `audit_id` INT AUTO_INCREMENT PRIMARY KEY,
  `attendance_id` INT DEFAULT NULL,
  `training_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `old_status` VARCHAR(30) DEFAULT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `source` ENUM('trainer', 'admin') NOT NULL,
  `changed_by_user_id` INT DEFAULT NULL,
  `changed_by_name` VARCHAR(150) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_training_attendance_audit_training` (`training_id`, `changed_at`),
  INDEX `idx_training_attendance_audit_student` (`student_id`, `changed_at`),
  FOREIGN KEY (`attendance_id`) REFERENCES `training_attendance` (`attendance_id`) ON DELETE SET NULL,
  FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Trainer attendance drafts (published only after trainer submission)
CREATE TABLE IF NOT EXISTS `training_attendance_drafts` (
  `draft_id` INT AUTO_INCREMENT PRIMARY KEY,
  `training_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `status` ENUM('Pending', 'Present', 'Absent', 'Late', 'Excused') NOT NULL DEFAULT 'Pending',
  `note` TEXT DEFAULT NULL,
  `drafted_by` VARCHAR(150) DEFAULT NULL,
  `drafted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_training_attendance_draft` (`training_id`, `student_id`),
  INDEX `idx_training_attendance_draft_training` (`training_id`, `updated_at`),
  INDEX `idx_training_attendance_draft_student` (`student_id`),
  FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Legacy Training Sessions table (retained for historical data only)
CREATE TABLE IF NOT EXISTS `training_sessions` (
  `session_id` INT AUTO_INCREMENT PRIMARY KEY,
  `attendance_id` INT NOT NULL,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `started_at` DATETIME NOT NULL,
  `last_heartbeat_at` DATETIME NOT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `engagement_seconds` INT DEFAULT 0,
  `session_status` ENUM('active', 'paused', 'completed', 'abandoned') DEFAULT 'active',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sessions_attendance` (`attendance_id`),
  INDEX `idx_sessions_status` (`session_status`),
  FOREIGN KEY (`attendance_id`) REFERENCES `training_attendance` (`attendance_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
