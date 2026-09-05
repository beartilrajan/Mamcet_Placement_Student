-- MAMCET Placement & Learning Portal - Database Migrations (Stage 2)
-- Safe to execute alongside existing Stage 1 tables without data loss.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. AI Providers table
CREATE TABLE IF NOT EXISTS `ai_providers` (
  `provider_id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_name` VARCHAR(50) NOT NULL UNIQUE,
  `api_endpoint` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. AI Settings table
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id` INT NOT NULL,
  `model_name` VARCHAR(100) NOT NULL,
  `api_key` TEXT DEFAULT NULL,
  `temperature` DECIMAL(3,2) DEFAULT 0.20,
  `max_tokens` INT DEFAULT 2048,
  `daily_limit` INT DEFAULT 3,
  `monthly_limit` INT DEFAULT 3,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enforce the fixed student quota for installations that already have AI settings.
UPDATE `ai_settings` SET `daily_limit` = 3, `monthly_limit` = 3;

-- 3. AI Usage Logs table
CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT DEFAULT NULL,
  `request_type` VARCHAR(50) NOT NULL,
  `prompt_tokens` INT DEFAULT 0,
  `completion_tokens` INT DEFAULT 0,
  `model_name` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_student_created` (`student_id`, `created_at`),
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Prompt Templates table
CREATE TABLE IF NOT EXISTS `prompt_templates` (
  `template_id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_name` VARCHAR(100) NOT NULL UNIQUE,
  `system_prompt` TEXT NOT NULL,
  `user_prompt_format` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Resume Files table
CREATE TABLE IF NOT EXISTS `resume_files` (
  `resume_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) DEFAULT NULL,
  `resume_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(100) NOT NULL,
  `file_size` INT NOT NULL,
  `is_current` TINYINT(1) DEFAULT 0,
  `status` VARCHAR(20) DEFAULT 'uploaded',
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_analyzed_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Resume Versions table
CREATE TABLE IF NOT EXISTS `resume_versions` (
  `version_id` INT AUTO_INCREMENT PRIMARY KEY,
  `resume_id` INT NOT NULL,
  `version_number` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resume_id`) REFERENCES `resume_files` (`resume_id`) ON DELETE CASCADE,
  UNIQUE KEY `idx_resume_version` (`resume_id`, `version_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Resume Extracted Text table
CREATE TABLE IF NOT EXISTS `resume_extracted_text` (
  `text_id` INT AUTO_INCREMENT PRIMARY KEY,
  `resume_id` INT NOT NULL,
  `extracted_text` LONGTEXT NOT NULL,
  `text_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_resume_text` (`resume_id`),
  FOREIGN KEY (`resume_id`) REFERENCES `resume_files` (`resume_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Resume Analysis table
CREATE TABLE IF NOT EXISTS `resume_analysis` (
  `analysis_id` INT AUTO_INCREMENT PRIMARY KEY,
  `resume_id` INT NOT NULL,
  `overall_score` INT NOT NULL,
  `rule_score` INT NOT NULL,
  `ai_score` INT NOT NULL,
  `assessment_text` TEXT DEFAULT NULL,
  `strengths` TEXT DEFAULT NULL,
  `critical_issues` TEXT DEFAULT NULL,
  `missing_sections` TEXT DEFAULT NULL,
  `missing_keywords` TEXT DEFAULT NULL,
  `grammar_suggestions` TEXT DEFAULT NULL,
  `action_verb_suggestions` TEXT DEFAULT NULL,
  `priority_actions` TEXT DEFAULT NULL,
  `model_name` VARCHAR(100) DEFAULT NULL,
  `prompt_version` VARCHAR(50) DEFAULT NULL,
  `analyzed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resume_id`) REFERENCES `resume_files` (`resume_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Resume Analysis Sections table
CREATE TABLE IF NOT EXISTS `resume_analysis_sections` (
  `section_analysis_id` INT AUTO_INCREMENT PRIMARY KEY,
  `analysis_id` INT NOT NULL,
  `section_name` VARCHAR(50) NOT NULL,
  `score` INT NOT NULL,
  `max_score` INT NOT NULL,
  `feedback` TEXT DEFAULT NULL,
  FOREIGN KEY (`analysis_id`) REFERENCES `resume_analysis` (`analysis_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Resume Builder Profiles table
CREATE TABLE IF NOT EXISTS `resume_builder_profiles` (
  `profile_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL UNIQUE,
  `career_objective` TEXT DEFAULT NULL,
  `languages` VARCHAR(255) DEFAULT NULL,
  `declaration` TEXT DEFAULT NULL,
  `skills_json` TEXT DEFAULT NULL,
  `projects_json` TEXT DEFAULT NULL,
  `internships_json` TEXT DEFAULT NULL,
  `certifications_json` TEXT DEFAULT NULL,
  `achievements_json` TEXT DEFAULT NULL,
  `workshops_json` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Resume Builder Sections table
CREATE TABLE IF NOT EXISTS `resume_builder_sections` (
  `section_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `section_key` VARCHAR(50) NOT NULL,
  `section_title` VARCHAR(100) NOT NULL,
  `is_visible` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  UNIQUE KEY `idx_student_section` (`student_id`, `section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Resume Templates table
CREATE TABLE IF NOT EXISTS `resume_templates` (
  `template_id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `css_class` VARCHAR(50) NOT NULL UNIQUE,
  `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Job Descriptions table
CREATE TABLE IF NOT EXISTS `job_descriptions` (
  `job_id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_name` VARCHAR(150) NOT NULL,
  `company_logo` VARCHAR(255) DEFAULT NULL,
  `job_title` VARCHAR(150) NOT NULL,
  `job_role` VARCHAR(100) NOT NULL,
  `employment_type` VARCHAR(50) DEFAULT NULL,
  `package_lpa` DECIMAL(5,2) DEFAULT NULL,
  `job_location` VARCHAR(150) DEFAULT NULL,
  `work_mode` VARCHAR(50) DEFAULT 'On-Site',
  `job_summary` TEXT DEFAULT NULL,
  `responsibilities` TEXT DEFAULT NULL,
  `required_skills` TEXT DEFAULT NULL,
  `preferred_skills` TEXT DEFAULT NULL,
  `min_cgpa` DECIMAL(4,2) DEFAULT 0.00,
  `max_standing_arrears` INT DEFAULT 0,
  `max_history_arrears` INT DEFAULT 0,
  `graduation_year` INT NOT NULL,
  `required_certifications` TEXT DEFAULT NULL,
  `application_deadline` DATE DEFAULT NULL,
  `drive_date` DATE DEFAULT NULL,
  `google_form_url` VARCHAR(255) DEFAULT NULL,
  `job_file_path` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'draft',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Job Description Departments table
CREATE TABLE IF NOT EXISTS `job_description_departments` (
  `job_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  PRIMARY KEY (`job_id`, `dept_id`),
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Job Description Batches table
CREATE TABLE IF NOT EXISTS `job_description_batches` (
  `job_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  PRIMARY KEY (`job_id`, `batch_id`),
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Job Description Skills table
CREATE TABLE IF NOT EXISTS `job_description_skills` (
  `job_id` INT NOT NULL,
  `skill_name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`job_id`, `skill_name`),
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Resume Job Matches table
CREATE TABLE IF NOT EXISTS `resume_job_matches` (
  `match_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `resume_id` INT NOT NULL,
  `job_id` INT NOT NULL,
  `match_score` INT NOT NULL,
  `matching_skills` TEXT DEFAULT NULL,
  `missing_skills` TEXT DEFAULT NULL,
  `matching_keywords` TEXT DEFAULT NULL,
  `missing_keywords` TEXT DEFAULT NULL,
  `recommendations` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`resume_id`) REFERENCES `resume_files` (`resume_id`) ON DELETE CASCADE,
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Interview Question Sets table
CREATE TABLE IF NOT EXISTS `interview_question_sets` (
  `set_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `resume_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`resume_id`) REFERENCES `resume_files` (`resume_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Interview Questions table
CREATE TABLE IF NOT EXISTS `interview_questions` (
  `question_id` INT AUTO_INCREMENT PRIMARY KEY,
  `set_id` INT NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `question` TEXT NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `answer_points` TEXT DEFAULT NULL,
  `sample_structure` TEXT DEFAULT NULL,
  `follow_up_questions` TEXT DEFAULT NULL,
  `mistakes_to_avoid` TEXT DEFAULT NULL,
  FOREIGN KEY (`set_id`) REFERENCES `interview_question_sets` (`set_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Interview Attempts table
CREATE TABLE IF NOT EXISTS `interview_attempts` (
  `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `attempt_type` VARCHAR(20) NOT NULL, -- 'written' or 'mock_chat'
  `total_questions` INT DEFAULT 5,
  `is_completed` TINYINT(1) DEFAULT 0,
  `visibility_allowed` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Interview Answers table
CREATE TABLE IF NOT EXISTS `interview_answers` (
  `answer_id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT NOT NULL,
  `question_id` INT NOT NULL,
  `student_answer` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`attempt_id`) REFERENCES `interview_attempts` (`attempt_id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `interview_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Interview Feedback table
CREATE TABLE IF NOT EXISTS `interview_feedback` (
  `feedback_id` INT AUTO_INCREMENT PRIMARY KEY,
  `answer_id` INT NOT NULL UNIQUE,
  `relevance` INT DEFAULT NULL,
  `clarity` INT DEFAULT NULL,
  `structure` INT DEFAULT NULL,
  `grammar` TEXT DEFAULT NULL,
  `suggestions` TEXT DEFAULT NULL,
  `overall_comments` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`answer_id`) REFERENCES `interview_answers` (`answer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Eligibility Rules table
CREATE TABLE IF NOT EXISTS `eligibility_rules` (
  `rule_id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT NOT NULL UNIQUE,
  `min_cgpa` DECIMAL(4,2) DEFAULT 0.00,
  `min_tenth` DECIMAL(5,2) DEFAULT 0.00,
  `min_twelfth` DECIMAL(5,2) DEFAULT 0.00,
  `min_diploma` DECIMAL(5,2) DEFAULT 0.00,
  `max_standing_arrears` INT DEFAULT 0,
  `max_history_arrears` INT DEFAULT 0,
  `placement_willingness` VARCHAR(10) DEFAULT 'Yes',
  `require_resume` TINYINT(1) DEFAULT 1,
  `require_profile_completion` INT DEFAULT 80,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Eligibility Rule Departments table
CREATE TABLE IF NOT EXISTS `eligibility_rule_departments` (
  `rule_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  PRIMARY KEY (`rule_id`, `dept_id`),
  FOREIGN KEY (`rule_id`) REFERENCES `eligibility_rules` (`rule_id`) ON DELETE CASCADE,
  FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. Student Eligibility table
CREATE TABLE IF NOT EXISTS `student_eligibility` (
  `eligibility_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `job_id` INT NOT NULL,
  `is_eligible` TINYINT(1) DEFAULT 0,
  `status` VARCHAR(25) DEFAULT 'Pending Verification', -- Eligible, Not Eligible, Officer Override
  `ineligibility_reasons` TEXT DEFAULT NULL,
  `override_reason` TEXT DEFAULT NULL,
  `override_by` INT DEFAULT NULL,
  `is_locked` TINYINT(1) DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE,
  FOREIGN KEY (`override_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  UNIQUE KEY `idx_student_job_elig` (`student_id`, `job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Eligibility History table
CREATE TABLE IF NOT EXISTS `eligibility_history` (
  `history_id` INT AUTO_INCREMENT PRIMARY KEY,
  `eligibility_id` INT NOT NULL,
  `previous_status` VARCHAR(25) NOT NULL,
  `new_status` VARCHAR(25) NOT NULL,
  `action_reason` TEXT DEFAULT NULL,
  `action_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`eligibility_id`) REFERENCES `student_eligibility` (`eligibility_id`) ON DELETE CASCADE,
  FOREIGN KEY (`action_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. Placement Opportunities table
CREATE TABLE IF NOT EXISTS `placement_opportunities` (
  `opportunity_id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT NOT NULL UNIQUE,
  `session_id` INT NOT NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`job_id`) REFERENCES `job_descriptions` (`job_id`) ON DELETE CASCADE,
  FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Placement Applications table
CREATE TABLE IF NOT EXISTS `placement_applications` (
  `application_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `opportunity_id` INT NOT NULL,
  `stage` VARCHAR(50) DEFAULT 'Applied', -- Applied, Aptitude Test, Shortlisted, Selected...
  `is_eligible` TINYINT(1) DEFAULT 1,
  `registration_confirmation` VARCHAR(255) DEFAULT NULL,
  `application_date` DATE NOT NULL,
  `officer_notes` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`opportunity_id`) REFERENCES `placement_opportunities` (`opportunity_id`) ON DELETE CASCADE,
  UNIQUE KEY `idx_student_opp` (`student_id`, `opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. Application Stage History table
CREATE TABLE IF NOT EXISTS `application_stage_history` (
  `history_id` INT AUTO_INCREMENT PRIMARY KEY,
  `application_id` INT NOT NULL,
  `stage` VARCHAR(50) NOT NULL,
  `action_by` INT NOT NULL,
  `comments` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`application_id`) REFERENCES `placement_applications` (`application_id`) ON DELETE CASCADE,
  FOREIGN KEY (`action_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. Application Documents table
CREATE TABLE IF NOT EXISTS `application_documents` (
  `doc_id` INT AUTO_INCREMENT PRIMARY KEY,
  `application_id` INT NOT NULL,
  `document_name` VARCHAR(150) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`application_id`) REFERENCES `placement_applications` (`application_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31. Notification Templates table
CREATE TABLE IF NOT EXISTS `notification_templates` (
  `template_id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_name` VARCHAR(100) NOT NULL UNIQUE,
  `channel` VARCHAR(20) NOT NULL, -- 'portal', 'email', 'whatsapp'
  `subject` VARCHAR(255) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. Notifications table
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `sender_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `channel` VARCHAR(20) DEFAULT 'portal',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 33. Notification Recipients table
CREATE TABLE IF NOT EXISTS `notification_recipients` (
  `notification_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`notification_id`, `user_id`),
  FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`notification_id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 34. Email Queue table
CREATE TABLE IF NOT EXISTS `email_queue` (
  `queue_id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(150) NOT NULL,
  `recipient_name` VARCHAR(150) DEFAULT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(20) DEFAULT 'pending', -- pending, sent, failed
  `attempts` INT DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `scheduled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 35. Email Logs table
CREATE TABLE IF NOT EXISTS `email_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(150) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `error_message` TEXT DEFAULT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 36. WhatsApp Logs table
CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_mobile` VARCHAR(15) NOT NULL,
  `message_body` TEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 37. Placement Readiness Scores table
CREATE TABLE IF NOT EXISTS `placement_readiness_scores` (
  `score_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL UNIQUE,
  `readiness_score` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 38. Readiness Score Details table
CREATE TABLE IF NOT EXISTS `readiness_score_details` (
  `detail_id` INT AUTO_INCREMENT PRIMARY KEY,
  `score_id` INT NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `score` INT NOT NULL,
  `max_score` INT NOT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  FOREIGN KEY (`score_id`) REFERENCES `placement_readiness_scores` (`score_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 39. Stage 2 Activity Logs table
CREATE TABLE IF NOT EXISTS `stage2_activity_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alter students table to add Stage 2 AI consent and privacy fields
ALTER TABLE `students` ADD COLUMN `ai_consent` TINYINT(1) DEFAULT 0;
ALTER TABLE `students` ADD COLUMN `consent_timestamp` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `students` ADD COLUMN `officer_visibility` TINYINT(1) DEFAULT 1;

SET FOREIGN_KEY_CHECKS = 1;
