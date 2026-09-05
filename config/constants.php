<?php
// MAMCET Placement & Learning Portal - Global Constants

// User Roles
define('ROLE_SUPER_ADMIN', 1);
define('ROLE_PLACEMENT_OFFICER', 2);
define('ROLE_STUDENT', 3);

// User Statuses
define('STATUS_ACTIVE', 'active');
define('STATUS_INACTIVE', 'inactive');
define('STATUS_SUSPENDED', 'suspended');

// Course Difficulties
define('DIFFICULTY_BEGINNER', 'Beginner');
define('DIFFICULTY_INTERMEDIATE', 'Intermediate');
define('DIFFICULTY_ADVANCED', 'Advanced');

// Announcement Types
define('ANNOUNCEMENT_GENERAL', 'General');
define('ANNOUNCEMENT_PLACEMENT_DRIVE', 'Placement Drive');
define('ANNOUNCEMENT_TRAINING', 'Training');
define('ANNOUNCEMENT_TEST_SCHEDULE', 'Test Schedule');
define('ANNOUNCEMENT_INTERVIEW_SCHEDULE', 'Interview Schedule');
define('ANNOUNCEMENT_DEADLINE_REMINDER', 'Deadline Reminder');
define('ANNOUNCEMENT_CIRCULAR', 'Circular');

// Course Categories
define('COURSE_CATEGORIES', [
    'Aptitude',
    'Logical Reasoning',
    'Verbal Ability',
    'Communication Skills',
    'Soft Skills',
    'Resume Preparation',
    'Group Discussion',
    'HR Interview',
    'Python',
    'Java',
    'C Programming',
    'C++',
    'Web Development',
    'SQL',
    'Data Structures',
    'Company-Specific Preparation',
    'Department Core Subjects'
]);

// Excel Header Equivalents Mapping Definition
define('EXCEL_COLUMN_MAPPINGS', [
    'registration_number' => [
        'registration number', 'register number', 'reg no', 'register no', 
        'university register number', 'student id', 'univ. regn. no.', 'univ regn no',
        'registration_number', 'reg_no', 'regno', 'reg. no', 'registratio'
    ],
    'student_name' => [
        'student name', 'name', 'candidate name', 'full name', 'candidate_name',
        'student_name', 'name of the student', 'student'
    ],
    'mobile_number' => [
        'mobile', 'mobile number', 'phone', 'phone number', 'contact number', 'mobile no', 'mobile no.',
        'mobile_number', 'student mobile', 'student_mobile', 'mobile_nu'
    ],
    'alt_mobile_number' => [
        'alternate mobile number', 'alt mobile', 'alternate mobile no', 'parent mobile', 'parent mobile (not your no.)', 'parent mobile no',
        'alt_mobile_number', 'parent_mobile', 'parent contact', 'alt_mobile'
    ],
    'email' => [
        'email', 'email id', 'email_id', 'personal email', 'mail id', 'mail', 'personal_email'
    ],
    'college_email' => [
        'college email', 'official email', 'college_email', 'college email id', 'institutional email', 'college_er'
    ],
    'dob' => [
        'date of birth', 'dob', 'birth date', 'date of birth dd.mm.yy', 'date of birth (dd.mm.yyyy)', 'date_of_birth'
    ],
    'gender' => [
        'gender', 'sex', 'm/f'
    ],
    'blood_group' => [
        'blood group', 'blood_group', 'bg', 'blood', 'blood_gro'
    ],
    'aadhar_number' => [
        'aadhar no', 'aadhar number', 'aadhar no.', 'aadhar', 'aadhar_number', 'aadhar_no', 'aadhaar', 'uid', 'aadhar_nu'
    ],
    'hosteller_status' => [
        'hosteller yes / no', 'hosteller', 'hostel status', 'hosteller (yes/no)', 'hosteller_status', 'dayscholar/hosteller'
    ],
    'licence_status' => [
        'licence yes / no', 'licence', 'driving licence', 'licence (yes/no)', 'licence_status', 'driving_license', 'license'
    ],
    'passport_status' => [
        'passport yes / no', 'passport', 'passport (yes/no)', 'passport_status'
    ],
    'dept_code' => [
        'dept_code', 'dept code', 'department', 'dept', 'department code', 'dept name', 'department_name', 'branch', 'branch code', 'dept_id'
    ],
    'academic_session' => [
        'academic session', 'academic_session', 'session', 'academic year', 'session name', 'session_name',
        'academic_year', 'placement session', 'target session', 'session_id', 'batch session', 'sess'
    ],
    'batch_name' => [
        'batch_name', 'batch name', 'batch', 'programme batch', 'target batch', 'graduation batch', 'academic batch', 'batch_id', 'graduation_year', 'admission_batch', 'batch_nan'
    ],
    'section_name' => [
        'section_name', 'section name', 'section', 'sec', 'sec_name', 'class section', 'class_section', 'section_na'
    ],
    'tenth_percentage' => [
        '10th percentage', '10th %', 'x %', 'x  %', '10th mark', '10th_percentage', 'sslc percentage', 'sslc %', '10th', 'tenth_percentage', 'tenth_per'
    ],
    'twelfth_percentage' => [
        '12th percentage', '12th %', 'xii %', 'xii  %', '12th mark', '12th_percentage', 'hsc percentage', 'hsc %', '12th', 'twelfth_percentage', 'twelfth_pe'
    ],
    'diploma_percentage' => [
        'diploma percentage', 'diploma %', 'dip %', 'dip. %', 'diploma mark', 'diploma_percentage', 'diploma', 'diploma_p'
    ],
    'current_cgpa' => [
        'current cgpa', 'cgpa', 'gpa', 'gpa(up to 6)', 'cgpa up to 6', 'current_cgpa', 'overall cgpa', 'overall gpa'
    ],
    'standing_arrears' => [
        'no. of standing arrears', 'standing arrears', 'current arrears', 'number of standing arrears',
        'standing_arrears', 'no of standing arrears', 'arrears', 'current backlog', 'standing backlogs'
    ],
    'history_of_arrears' => [
        'no. of history of arrears', 'history of arrears', 'arrear history', 'number of history of arrears',
        'history_of_arrears', 'no of history of arrears', 'history backlogs'
    ],
    'sem1_gpa' => ['sem1 gpa', 'sem 1 gpa', 'sem 1', 'sem1', 'sem1_gpa', 'semester 1 gpa', 'semester 1', 'gpa sem 1'],
    'sem2_gpa' => ['sem2 gpa', 'sem 2 gpa', 'sem 2', 'sem2', 'sem2_gpa', 'semester 2 gpa', 'semester 2', 'gpa sem 2'],
    'sem3_gpa' => ['sem3 gpa', 'sem 3 gpa', 'sem 3', 'sem3', 'sem3_gpa', 'semester 3 gpa', 'semester 3', 'gpa sem 3'],
    'sem4_gpa' => ['sem4 gpa', 'sem 4 gpa', 'sem 4', 'sem4', 'sem4_gpa', 'semester 4 gpa', 'semester 4', 'gpa sem 4'],
    'sem5_gpa' => ['sem5 gpa', 'sem 5 gpa', 'sem 5', 'sem5', 'sem5_gpa', 'semester 5 gpa', 'semester 5', 'gpa sem 5'],
    'sem6_gpa' => ['sem6 gpa', 'sem 6 gpa', 'sem 6', 'sem6', 'sem6_gpa', 'semester 6 gpa', 'semester 6', 'gpa sem 6'],
    'sem7_gpa' => ['sem7 gpa', 'sem 7 gpa', 'sem 7', 'sem7', 'sem7_gpa', 'semester 7 gpa', 'semester 7', 'gpa sem 7'],
    'sem8_gpa' => ['sem8 gpa', 'sem 8 gpa', 'sem 8', 'sem8', 'sem8_gpa', 'semester 8 gpa', 'semester 8', 'gpa sem 8'],
    'placement_willingness' => ['placement_willingness', 'placement willingness', 'willingness', 'willing', 'willing (yes/no)', 'placement willing'],
    'placement_status' => ['placement_status', 'placement status', 'placed status', 'status']
]);
