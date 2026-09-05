-- LeetCode Integration Migration
-- Adds tables for student LeetCode profile linking and API response caching

CREATE TABLE IF NOT EXISTS `leetcode_profiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `leetcode_username` VARCHAR(100) NOT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `layout_config` JSON DEFAULT NULL,
    `linked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_leetcode` (`student_id`),
    KEY `idx_username` (`leetcode_username`),
    CONSTRAINT `fk_leetcode_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leetcode_cache` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `cache_key` VARCHAR(100) NOT NULL,
    `cache_data` LONGTEXT NOT NULL,
    `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_cache` (`student_id`, `cache_key`),
    KEY `idx_fetched` (`fetched_at`),
    CONSTRAINT `fk_leetcode_cache_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
