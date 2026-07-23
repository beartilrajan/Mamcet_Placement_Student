-- MAMCET Placement & Learning Portal - Database Seed Script (Stage 1)

-- 1. Seed Roles
INSERT INTO `roles` (`role_id`, `role_name`, `description`) VALUES
(1, 'super_admin', 'Super Administrator with full portal configuration privileges'),
(2, 'placement_officer', 'Placement Officer with access to student profiles, imports, announcements, and courses'),
(3, 'student', 'Final year college student with profile access and learning dashboards');

-- 2. Seed Programmes
INSERT INTO `programmes` (`programme_id`, `programme_name`, `duration_years`) VALUES
(1, 'B.Tech', 4),
(2, 'B.E.', 4),
(3, 'MBA', 2),
(4, 'MCA', 2);

-- 3. Seed Departments
INSERT INTO `departments` (`dept_id`, `dept_code`, `dept_name`, `short_name`, `programme_id`, `head_of_dept`, `status`) VALUES
(1, 'CSE', 'Computer Science and Engineering', 'CSE', 1, 'Dr. A. Rajesh', 'active'),
(2, 'IT', 'Information Technology', 'IT', 1, 'Dr. M. Dev', 'active'),
(3, 'ECE', 'Electronics and Communication Engineering', 'ECE', 2, 'Dr. S. Kavin', 'active'),
(4, 'EEE', 'Electrical and Electronics Engineering', 'EEE', 2, 'Prof. K. Mani', 'active'),
(5, 'MECH', 'Mechanical Engineering', 'MECH', 2, 'Dr. P. Vel', 'active'),
(6, 'CIVIL', 'Civil Engineering', 'CIVIL', 2, 'Prof. R. Raja', 'active'),
(7, 'AIDS', 'Artificial Intelligence and Data Science', 'AIDS', 1, 'Dr. T. Nisha', 'active');

-- 4. Seed Default Sections for CSE and IT
INSERT INTO `sections` (`section_id`, `section_name`, `dept_id`) VALUES
(1, 'A', 1),
(2, 'B', 1),
(3, 'A', 2);

-- 5. Seed System Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('portal_name', 'MAMCET Placement & Learning Portal'),
('college_name', 'M.A.M. College of Engineering and Technology'),
('admin_email', 'placement@mamcet.org'),
('allow_profile_edits', '1'),
('max_upload_size_mb', '5'),
('login_max_attempts', '5'),
('login_lockout_minutes', '15');

-- 6. Seed default active Academic Session: 2026–2027
INSERT INTO `academic_sessions` (`session_id`, `session_name`, `start_year`, `end_year`, `start_date`, `end_date`, `is_active`, `status`, `description`) VALUES
(1, '2026–2027', 2026, 2027, '2026-06-01', '2027-05-31', 1, 'active', 'Academic Session for year 2026-2027');

-- 7. Seed default Batches
INSERT INTO `batches` (`batch_id`, `batch_name`, `admission_year`, `graduation_year`, `programme_id`, `programme_duration`, `current_year_of_study`, `status`, `description`) VALUES
(1, '2023–2027', 2023, 2027, 1, 4, 'Final Year', 'active', 'Batch 2023-2027 (Final Year)');

-- 8. Seed Batch academic session configuration
INSERT INTO `batch_academic_sessions` (`batch_id`, `session_id`, `year_of_study`, `semester`) VALUES
(1, 1, 'Final Year', 7);
