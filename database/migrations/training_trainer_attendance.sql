-- Training attendance workflow migration
-- Run once after the original training_attendance.sql migration.
-- Attendance is now recorded by the trainer through a secure bearer link.

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `trainings`
  MODIFY `training_url` VARCHAR(500) NULL,
  ADD COLUMN `event_type` ENUM('Online', 'Offline') NOT NULL DEFAULT 'Online' AFTER `training_url`,
  ADD COLUMN `venue_location` VARCHAR(500) DEFAULT NULL AFTER `event_type`,
  ADD COLUMN `trainer_access_token` VARCHAR(64) DEFAULT NULL AFTER `trainer_name`;

UPDATE `trainings`
SET `trainer_access_token` = SHA2(CONCAT(UUID(), RAND(), NOW()), 256)
WHERE `trainer_access_token` IS NULL OR `trainer_access_token` = '';

ALTER TABLE `trainings`
  MODIFY `trainer_access_token` VARCHAR(64) NOT NULL,
  ADD UNIQUE KEY `uk_trainings_trainer_access_token` (`trainer_access_token`);

ALTER TABLE `training_attendance`
  MODIFY `status` ENUM('Pending', 'Present', 'Absent', 'Late', 'Excused', 'Partial', 'In Progress') NOT NULL DEFAULT 'Pending',
  ADD COLUMN `marked_by` VARCHAR(150) DEFAULT NULL AFTER `status`,
  ADD COLUMN `marked_at` DATETIME DEFAULT NULL AFTER `marked_by`,
  ADD COLUMN `attendance_note` TEXT DEFAULT NULL AFTER `marked_at`;

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

SET FOREIGN_KEY_CHECKS = 1;
