-- MAMCET Placement & Learning Portal - Database Rollback (Stage 2)
-- Drops only Stage 2 specific tables. Run with caution.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `stage2_activity_logs`;
DROP TABLE IF EXISTS `readiness_score_details`;
DROP TABLE IF EXISTS `placement_readiness_scores`;
DROP TABLE IF EXISTS `whatsapp_logs`;
DROP TABLE IF EXISTS `email_logs`;
DROP TABLE IF EXISTS `email_queue`;
DROP TABLE IF EXISTS `notification_recipients`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `notification_templates`;
DROP TABLE IF EXISTS `application_documents`;
DROP TABLE IF EXISTS `application_stage_history`;
DROP TABLE IF EXISTS `placement_applications`;
DROP TABLE IF EXISTS `placement_opportunities`;
DROP TABLE IF EXISTS `eligibility_history`;
DROP TABLE IF EXISTS `student_eligibility`;
DROP TABLE IF EXISTS `eligibility_rule_departments`;
DROP TABLE IF EXISTS `eligibility_rules`;
DROP TABLE IF EXISTS `interview_feedback`;
DROP TABLE IF EXISTS `interview_answers`;
DROP TABLE IF EXISTS `interview_attempts`;
DROP TABLE IF EXISTS `interview_questions`;
DROP TABLE IF EXISTS `interview_question_sets`;
DROP TABLE IF EXISTS `resume_job_matches`;
DROP TABLE IF EXISTS `job_description_skills`;
DROP TABLE IF EXISTS `job_description_batches`;
DROP TABLE IF EXISTS `job_description_departments`;
DROP TABLE IF EXISTS `job_descriptions`;
DROP TABLE IF EXISTS `resume_templates`;
DROP TABLE IF EXISTS `resume_builder_sections`;
DROP TABLE IF EXISTS `resume_builder_profiles`;
DROP TABLE IF EXISTS `resume_analysis_sections`;
DROP TABLE IF EXISTS `resume_analysis`;
DROP TABLE IF EXISTS `resume_extracted_text`;
DROP TABLE IF EXISTS `resume_versions`;
DROP TABLE IF EXISTS `resume_files`;
DROP TABLE IF EXISTS `prompt_templates`;
DROP TABLE IF EXISTS `ai_usage_logs`;
DROP TABLE IF EXISTS `ai_settings`;
DROP TABLE IF EXISTS `ai_providers`;

ALTER TABLE `students` DROP COLUMN `ai_consent`;
ALTER TABLE `students` DROP COLUMN `consent_timestamp`;
ALTER TABLE `students` DROP COLUMN `officer_visibility`;

SET FOREIGN_KEY_CHECKS = 1;
