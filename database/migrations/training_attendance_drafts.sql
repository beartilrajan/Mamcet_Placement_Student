-- Trainer attendance draft and one-time submission lock migration.
-- Run after training_trainer_attendance.sql on existing installations.

ALTER TABLE `trainings`
  ADD COLUMN `attendance_submitted_at` DATETIME DEFAULT NULL AFTER `trainer_access_token`,
  ADD COLUMN `attendance_submitted_by` VARCHAR(150) DEFAULT NULL AFTER `attendance_submitted_at`;

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
