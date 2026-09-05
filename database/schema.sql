-- MAMCET Placement & Learning Portal - Database Schema (Stage 1)

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Roles table
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `role_id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users table (credentials for admins, officers, and active student profiles)
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` INT NOT NULL,
  `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Placement Officers table
DROP TABLE IF EXISTS `placement_officers`;
CREATE TABLE `placement_officers` (
  `officer_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `mobile_number` VARCHAR(15) NOT NULL,
  `dept_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Academic Sessions table (operational academic years: e.g., 2026-2027)
DROP TABLE IF EXISTS `academic_sessions`;
CREATE TABLE `academic_sessions` (
  `session_id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_name` VARCHAR(50) NOT NULL UNIQUE,
  `start_year` INT NOT NULL,
  `end_year` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) DEFAULT 0,
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'inactive',
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Programmes table (e.g., B.Tech, M.Tech, BE, MBA)
DROP TABLE IF EXISTS `programmes`;
CREATE TABLE `programmes` (
  `programme_id` INT AUTO_INCREMENT PRIMARY KEY,
  `programme_name` VARCHAR(100) NOT NULL UNIQUE,
  `duration_years` INT NOT NULL DEFAULT 4,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Batches table (graduation batches: e.g., 2023-2027)
DROP TABLE IF EXISTS `batches`;
CREATE TABLE `batches` (
  `batch_id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_name` VARCHAR(50) NOT NULL UNIQUE,
  `admission_year` INT NOT NULL,
  `graduation_year` INT NOT NULL,
  `programme_id` INT NOT NULL,
  `programme_duration` INT NOT NULL DEFAULT 4,
  `current_year_of_study` ENUM('First Year', 'Second Year', 'Third Year', 'Final Year') DEFAULT 'Final Year',
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`programme_id`) REFERENCES `programmes` (`programme_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Departments table (CSE, IT, ECE, EEE, MECH, CIVIL, AIDS)
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `dept_id` INT AUTO_INCREMENT PRIMARY KEY,
  `dept_code` VARCHAR(20) NOT NULL UNIQUE,
  `dept_name` VARCHAR(150) NOT NULL,
  `short_name` VARCHAR(50) NOT NULL,
  `programme_id` INT DEFAULT NULL,
  `head_of_dept` VARCHAR(150),
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`programme_id`) REFERENCES `programmes` (`programme_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Sections table (A, B, C, etc. dynamic sections per department)
DROP TABLE IF EXISTS `sections`;
CREATE TABLE `sections` (
  `section_id` INT AUTO_INCREMENT PRIMARY KEY,
  `section_name` VARCHAR(10) NOT NULL,
  `dept_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `dept_section_unique` (`dept_id`, `section_name`),
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Students table
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `student_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL UNIQUE,
  `registration_number` VARCHAR(50) NOT NULL UNIQUE,
  `student_name` VARCHAR(150) NOT NULL,
  `mobile_number` VARCHAR(15) NOT NULL,
  `alt_mobile_number` VARCHAR(15),
  `email` VARCHAR(150),
  `college_email` VARCHAR(150),
  `dob` DATE,
  `gender` VARCHAR(10),
  `blood_group` VARCHAR(10),
  `aadhar_number` VARCHAR(20),
  `parent_mobile` VARCHAR(15),
  `hosteller_status` VARCHAR(10),
  `licence_status` VARCHAR(10),
  `passport_status` VARCHAR(10),
  `dept_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `section_id` INT DEFAULT NULL,
  `admission_year` INT,
  `graduation_year` INT,
  `current_semester` INT DEFAULT 7,
  `year_of_study` VARCHAR(20) DEFAULT 'Final Year',
  `placement_willingness` ENUM('Yes', 'No') DEFAULT 'Yes',
  `placement_status` ENUM('Placed', 'Unplaced', 'Higher Studies', 'Entrepreneurship') DEFAULT 'Unplaced',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Student Profiles table (editable portfolio / personal details)
DROP TABLE IF EXISTS `student_profiles`;
CREATE TABLE `student_profiles` (
  `profile_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL UNIQUE,
  `linkedin_url` VARCHAR(255),
  `github_url` VARCHAR(255),
  `portfolio_url` VARCHAR(255),
  `skills` TEXT,
  `projects` TEXT,
  `internships` TEXT,
  `certifications` TEXT,
  `address` TEXT,
  `city` VARCHAR(100),
  `district` VARCHAR(100),
  `resume_path` VARCHAR(255),
  `career_objective` TEXT,
  `preferred_job_role` VARCHAR(150),
  `preferred_location` VARCHAR(150),
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Student Academics table (marks, arrears, GPA tracks)
DROP TABLE IF EXISTS `student_academics`;
CREATE TABLE `student_academics` (
  `academic_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL UNIQUE,
  `tenth_percentage` DECIMAL(5,2),
  `twelfth_percentage` DECIMAL(5,2),
  `diploma_percentage` DECIMAL(5,2),
  `current_cgpa` DECIMAL(4,2),
  `standing_arrears` INT DEFAULT 0,
  `history_of_arrears` INT DEFAULT 0,
  `sem1_gpa` DECIMAL(4,2),
  `sem2_gpa` DECIMAL(4,2),
  `sem3_gpa` DECIMAL(4,2),
  `sem4_gpa` DECIMAL(4,2),
  `sem5_gpa` DECIMAL(4,2),
  `sem6_gpa` DECIMAL(4,2),
  `sem7_gpa` DECIMAL(4,2),
  `sem8_gpa` DECIMAL(4,2),
  `gpa_up_to_6` DECIMAL(4,2),
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Batch Academic Sessions history mapping (to track study year/semester for each academic year)
DROP TABLE IF EXISTS `batch_academic_sessions`;
CREATE TABLE `batch_academic_sessions` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `session_id` INT NOT NULL,
  `year_of_study` VARCHAR(20) NOT NULL,
  `semester` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `batch_session_unique` (`batch_id`, `session_id`),
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Student Login Attempts (rate limiting/throttling and security)
DROP TABLE IF EXISTS `student_login_attempts`;
CREATE TABLE `student_login_attempts` (
  `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempt_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_successful` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Student Update Logs
DROP TABLE IF EXISTS `student_update_logs`;
CREATE TABLE `student_update_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `field_name` VARCHAR(100) NOT NULL,
  `old_value` TEXT,
  `new_value` TEXT,
  `updated_by` INT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Dataset Files (metadata about files detected inside data/ directory)
DROP TABLE IF EXISTS `dataset_files`;
CREATE TABLE `dataset_files` (
  `file_id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL UNIQUE,
  `filepath` VARCHAR(255) NOT NULL,
  `filesize` INT NOT NULL,
  `last_modified` DATETIME NOT NULL,
  `detected_dept_id` INT DEFAULT NULL,
  `import_status` ENUM('pending', 'imported', 'failed') DEFAULT 'pending',
  `last_imported_at` DATETIME DEFAULT NULL,
  `total_rows` INT DEFAULT 0,
  `valid_rows` INT DEFAULT 0,
  `invalid_rows` INT DEFAULT 0,
  `duplicate_rows` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`detected_dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Excel Imports tracking
DROP TABLE IF EXISTS `excel_imports`;
CREATE TABLE `excel_imports` (
  `import_id` INT AUTO_INCREMENT PRIMARY KEY,
  `file_id` INT DEFAULT NULL,
  `imported_by` INT NOT NULL,
  `session_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `programme_id` INT DEFAULT NULL,
  `dept_id` INT NOT NULL,
  `section_id` INT DEFAULT NULL,
  `total_imported` INT DEFAULT 0,
  `status` VARCHAR(50) DEFAULT 'success',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`file_id`) REFERENCES `dataset_files` (`file_id`) ON DELETE SET NULL,
  FOREIGN KEY (`imported_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Excel Import Rows (raw details of rows parsed)
DROP TABLE IF EXISTS `excel_import_rows`;
CREATE TABLE `excel_import_rows` (
  `row_id` INT AUTO_INCREMENT PRIMARY KEY,
  `import_id` INT NOT NULL,
  `row_number` INT NOT NULL,
  `registration_number` VARCHAR(50),
  `data_json` TEXT,
  `is_valid` TINYINT(1) DEFAULT 1,
  `validation_errors` TEXT,
  `import_status` ENUM('success', 'skipped', 'updated', 'failed') DEFAULT 'success',
  FOREIGN KEY (`import_id`) REFERENCES `excel_imports` (`import_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Import Errors details
DROP TABLE IF EXISTS `import_errors`;
CREATE TABLE `import_errors` (
  `error_id` INT AUTO_INCREMENT PRIMARY KEY,
  `import_id` INT NOT NULL,
  `row_number` INT NOT NULL,
  `column_name` VARCHAR(100),
  `error_message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`import_id`) REFERENCES `excel_imports` (`import_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Announcements and Placement Updates
DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `announcement_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `announcement_type` ENUM('General', 'Placement Drive', 'Training', 'Test Schedule', 'Interview Schedule', 'Deadline Reminder', 'Circular') DEFAULT 'General',
  `company_name` VARCHAR(150),
  `job_role` VARCHAR(150),
  `package` VARCHAR(100),
  `job_location` VARCHAR(150),
  `eligibility_criteria` TEXT,
  `min_cgpa` DECIMAL(4,2) DEFAULT 0.00,
  `max_standing_arrears` INT DEFAULT 99,
  `max_history_arrears` INT DEFAULT 99,
  `placement_willingness_req` TINYINT(1) DEFAULT 1,
  `session_id` INT NOT NULL,
  `google_form_url` VARCHAR(255),
  `google_sheet_url` VARCHAR(255),
  `is_sheet_student_visible` TINYINT(1) DEFAULT 0,
  `jd_file_path` VARCHAR(255),
  `external_link` VARCHAR(255),
  `publish_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `priority` ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
  `status` ENUM('published', 'draft', 'expired') DEFAULT 'published',
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Announcement Departments mapping (target specific departments)
DROP TABLE IF EXISTS `announcement_departments`;
CREATE TABLE `announcement_departments` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `announcement_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Announcement Batches mapping (target specific batches)
DROP TABLE IF EXISTS `announcement_batches`;
CREATE TABLE `announcement_batches` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `announcement_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Courses table
DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `course_id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_title` VARCHAR(255) NOT NULL,
  `course_code` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT,
  `category` VARCHAR(100) NOT NULL,
  `thumbnail_path` VARCHAR(255),
  `instructor_name` VARCHAR(150),
  `difficulty` ENUM('Beginner', 'Intermediate', 'Advanced') DEFAULT 'Beginner',
  `session_id` INT NOT NULL,
  `start_date` DATE,
  `end_date` DATE,
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Course Modules table
DROP TABLE IF EXISTS `course_modules`;
CREATE TABLE `course_modules` (
  `module_id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `module_title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `module_order` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Course Lessons table (YouTube integration and PDF resources)
DROP TABLE IF EXISTS `course_lessons`;
CREATE TABLE `course_lessons` (
  `lesson_id` INT AUTO_INCREMENT PRIMARY KEY,
  `module_id` INT NOT NULL,
  `lesson_title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `youtube_url` VARCHAR(255),
  `youtube_video_id` VARCHAR(50),
  `duration_minutes` INT DEFAULT 0,
  `notes_text` TEXT,
  `pdf_resource_path` VARCHAR(255),
  `external_resource_url` VARCHAR(255),
  `lesson_order` INT NOT NULL DEFAULT 1,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`module_id`) REFERENCES `course_modules` (`module_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. Course Assignments table
DROP TABLE IF EXISTS `course_assignments`;
CREATE TABLE `course_assignments` (
  `assignment_id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `session_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Course Departments mapping
DROP TABLE IF EXISTS `course_departments`;
CREATE TABLE `course_departments` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. Course Batches mapping
DROP TABLE IF EXISTS `course_batches`;
CREATE TABLE `course_batches` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Student Course Progress details
DROP TABLE IF EXISTS `student_course_progress`;
CREATE TABLE `student_course_progress` (
  `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `course_id` INT NOT NULL,
  `completion_percentage` DECIMAL(5,2) DEFAULT 0.00,
  `status` ENUM('Not Started', 'In Progress', 'Completed') DEFAULT 'Not Started',
  `started_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `last_accessed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `student_course_unique` (`student_id`, `course_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. Student Lesson Progress (tracks marking lessons complete)
DROP TABLE IF EXISTS `student_lesson_progress`;
CREATE TABLE `student_lesson_progress` (
  `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `lesson_id` INT NOT NULL,
  `status` ENUM('Completed') DEFAULT 'Completed',
  `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `student_lesson_unique` (`student_id`, `lesson_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `course_lessons` (`lesson_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. Officer Notes on students
DROP TABLE IF EXISTS `officer_notes`;
CREATE TABLE `officer_notes` (
  `note_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `officer_id` INT NOT NULL,
  `note_content` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`officer_id`) REFERENCES `placement_officers` (`officer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31. Activity Logs
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `ip_address` VARCHAR(45),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. Global Settings
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 33. Mapping Templates (for saving custom Excel imports mapping per department/file)
DROP TABLE IF EXISTS `mapping_templates`;
CREATE TABLE `mapping_templates` (
  `template_id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_name` VARCHAR(100) NOT NULL UNIQUE,
  `dept_id` INT DEFAULT NULL,
  `mapping_json` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 34. Student Placements (package & offer letter track)
DROP TABLE IF EXISTS `student_placements`;
CREATE TABLE `student_placements` (
  `placement_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `company_name` VARCHAR(150) NOT NULL,
  `package_lpa` DECIMAL(5,2) NOT NULL,
  `offer_letter_path` VARCHAR(255) DEFAULT NULL,
  `placed_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 35. Student Certifications (Uploads & Verification Track)
DROP TABLE IF EXISTS `student_certifications`;
CREATE TABLE `student_certifications` (
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
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 36. College Events (Real-time Calendar Sync)
DROP TABLE IF EXISTS `college_events`;
CREATE TABLE `college_events` (
  `event_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `event_type` ENUM('Campus Event', 'Placement Drive', 'Academic Deadline', 'Exam Schedule', 'Holiday', 'Workshop') DEFAULT 'Campus Event',
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `session_id` INT DEFAULT 1,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 37. Trainings table
DROP TABLE IF EXISTS `trainings`;
CREATE TABLE `trainings` (
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

-- 38. Training Departments mapping table
DROP TABLE IF EXISTS `training_departments`;
CREATE TABLE `training_departments` (
  `map_id` INT AUTO_INCREMENT PRIMARY KEY,
  `training_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_training_dept` (`training_id`, `dept_id`),
  INDEX `idx_training_dept_dept` (`dept_id`),
  FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 39. Training Attendance table
DROP TABLE IF EXISTS `training_attendance`;
CREATE TABLE `training_attendance` (
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

-- 41. Trainer attendance audit history
DROP TABLE IF EXISTS `training_attendance_audit`;
CREATE TABLE `training_attendance_audit` (
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

-- 42. Trainer attendance drafts
DROP TABLE IF EXISTS `training_attendance_drafts`;
CREATE TABLE `training_attendance_drafts` (
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

-- 40. Training Sessions table
DROP TABLE IF EXISTS `training_sessions`;
CREATE TABLE `training_sessions` (
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



