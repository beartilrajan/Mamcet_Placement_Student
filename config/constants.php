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
        'university register number', 'student id', 'univ. regn. no.', 'univ regn no'
    ],
    'student_name' => [
        'student name', 'name', 'candidate name', 'full name', 'candidate_name'
    ],
    'mobile_number' => [
        'mobile', 'mobile number', 'phone', 'phone number', 'contact number', 'mobile no', 'mobile no.'
    ],
    'alt_mobile_number' => [
        'alternate mobile number', 'alt mobile', 'alternate mobile no', 'parent mobile', 'parent mobile (not your no.)', 'parent mobile no'
    ],
    'email' => [
        'email', 'email id', 'email_id', 'personal email', 'mail id', 'mail'
    ],
    'college_email' => [
        'college email', 'official email', 'college_email', 'college email id'
    ],
    'dob' => [
        'date of birth', 'dob', 'birth date', 'date of birth dd.mm.yy', 'date of birth (dd.mm.yyyy)'
    ],
    'gender' => [
        'gender', 'sex', 'm/f'
    ],
    'blood_group' => [
        'blood group', 'blood_group', 'bg', 'blood'
    ],
    'aadhar_number' => [
        'aadhar no', 'aadhar number', 'aadhar no.', 'aadhar'
    ],
    'hosteller_status' => [
        'hosteller yes / no', 'hosteller', 'hostel status', 'hosteller (yes/no)'
    ],
    'licence_status' => [
        'licence yes / no', 'licence', 'driving licence', 'licence (yes/no)'
    ],
    'passport_status' => [
        'passport yes / no', 'passport', 'passport (yes/no)'
    ],
    'tenth_percentage' => [
        '10th percentage', '10th %', 'x %', 'x  %', '10th mark', '10th percentage'
    ],
    'twelfth_percentage' => [
        '12th percentage', '12th %', 'xii %', 'xii  %', '12th mark'
    ],
    'diploma_percentage' => [
        'diploma percentage', 'diploma %', 'dip %', 'dip. %', 'diploma mark'
    ],
    'current_cgpa' => [
        'current cgpa', 'cgpa', 'gpa', 'gpa(up to 6)', 'cgpa up to 6'
    ],
    'standing_arrears' => [
        'no. of standing arrears', 'standing arrears', 'current arrears', 'no. of standing arrears', 'number of standing arrears'
    ],
    'history_of_arrears' => [
        'no. of history of arrears', 'history of arrears', 'arrear history', 'number of history of arrears'
    ],
    'sem1_gpa' => ['sem1 gpa', 'sem 1 gpa', 'sem 1', 'sem1'],
    'sem2_gpa' => ['sem2 gpa', 'sem 2 gpa', 'sem 2', 'sem2'],
    'sem3_gpa' => ['sem3 gpa', 'sem 3 gpa', 'sem 3', 'sem3'],
    'sem4_gpa' => ['sem4 gpa', 'sem 4 gpa', 'sem 4', 'sem4'],
    'sem5_gpa' => ['sem5 gpa', 'sem 5 gpa', 'sem 5', 'sem5'],
    'sem6_gpa' => ['sem6 gpa', 'sem 6 gpa', 'sem 6', 'sem6']
]);
