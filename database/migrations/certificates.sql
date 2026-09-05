-- MAMCET Placement & Learning Portal - Certificate Management Migration
-- Ensures student_certifications table and required columns exist

CREATE TABLE IF NOT EXISTS `student_certifications` (
  `cert_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `issuing_organization` VARCHAR(255) NOT NULL,
  `issue_date` DATE DEFAULT NULL,
  `credential_id` VARCHAR(100) DEFAULT NULL,
  `credential_url` VARCHAR(255) DEFAULT NULL,
  `skills` VARCHAR(255) DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_size` INT DEFAULT NULL,
  `status` ENUM('Pending Verification', 'Verified', 'Rejected') DEFAULT 'Pending Verification',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_student_cert_student_id` (`student_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
