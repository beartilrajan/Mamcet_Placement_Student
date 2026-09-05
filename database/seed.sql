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

-- 4. Seed Default Sections for all Departments (CSE, IT, ECE, EEE, MECH, CIVIL, AIDS)
INSERT INTO `sections` (`section_name`, `dept_id`) VALUES
('A', 1), ('B', 1),
('A', 2), ('B', 2),
('A', 3), ('B', 3),
('A', 4), ('B', 4),
('A', 5), ('B', 5),
('A', 6), ('B', 6),
('A', 7), ('B', 7);

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

-- 7. Seed standard Batches
INSERT INTO `batches` (`batch_name`, `admission_year`, `graduation_year`, `programme_id`, `programme_duration`, `current_year_of_study`, `status`, `description`) VALUES
('2020–2024', 2020, 2024, 1, 4, 'Final Year', 'active', 'Batch 2020-2024'),
('2021–2025', 2021, 2025, 1, 4, 'Final Year', 'active', 'Batch 2021-2025'),
('2022–2026', 2022, 2026, 1, 4, 'Final Year', 'active', 'Batch 2022-2026'),
('2023–2027', 2023, 2027, 1, 4, 'Final Year', 'active', 'Batch 2023-2027 (Final Year)'),
('2024–2027', 2024, 2027, 1, 3, 'Final Year', 'active', 'Batch 2024-2027 (Lateral Entry)'),
('2024–2028', 2024, 2028, 1, 4, 'Third Year', 'active', 'Batch 2024-2028 (Third Year)'),
('2025–2028', 2025, 2028, 1, 3, 'Third Year', 'active', 'Batch 2025-2028 (Lateral Entry)'),
('2025–2029', 2025, 2029, 1, 4, 'Second Year', 'active', 'Batch 2025-2029 (Second Year)'),
('2026–2030', 2026, 2030, 1, 4, 'First Year', 'active', 'Batch 2026-2030 (First Year)');

-- 8. Seed Batch academic session configuration
INSERT INTO `batch_academic_sessions` (`batch_id`, `session_id`, `year_of_study`, `semester`) VALUES
-- Session 1: 2026-2027
(4, 1, 'Final Year', 7),
(5, 1, 'Final Year', 7),
(6, 1, 'Third Year', 5),
(7, 1, 'Third Year', 5),
(8, 1, 'Second Year', 3),
(9, 1, 'First Year', 1);

