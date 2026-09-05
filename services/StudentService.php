<?php
// MAMCET Placement & Learning Portal - Central Student Service & Business Logic

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/excel-helper.php');

class StudentService {
    private $db;

    public function __construct($db = null) {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Reusable validation logic for both manual entry and Excel/CSV imports
     *
     * @param array $data Raw student input fields
     * @param bool $checkDuplicate Whether to check duplicate registration_number in DB
     * @param int|null $excludeStudentId Student ID to exclude from duplicate check (e.g. on update)
     * @return array ['isValid' => bool, 'errors' => string[], 'sanitized' => array]
     */
    public function validateStudentData(array $data, bool $checkDuplicate = true, ?int $excludeStudentId = null): array {
        $errors = [];
        $sanitized = [];

        // 1. Registration Number (Required, Unique, Alphanumeric format)
        $rawRegNo = strtoupper(trim((string)($data['registration_number'] ?? '')));
        $rawRegNo = trim($rawRegNo, " ='\"\t\r\n");
        $hasScientific = (bool)preg_match('/^[0-9.]+[eE]\+[0-9]+$/', $rawRegNo);
        $regNo = $this->normalizeNumberString($rawRegNo);

        if (empty($regNo)) {
            $errors['registration_number'] = 'Registration Number is required.';
        } elseif ($hasScientific && strlen($regNo) >= 10 && substr($regNo, -5) === '00000') {
            // Excel truncated roll number digits into zeros (e.g. 8.12421E+11 -> 812421000000)
            $errors['registration_number'] = "Registration Number '$rawRegNo' was truncated by Excel scientific notation into '$regNo'. Use .xlsx template or format the column as Text in Excel.";
        } elseif (!preg_match('/^[A-Z0-9\-_]{3,50}$/i', $regNo)) {
            $errors['registration_number'] = 'Registration Number must be 3-50 alphanumeric characters (letters, numbers, hyphens).';
        } else {
            // Check for duplicate in DB
            if ($checkDuplicate && $this->checkDuplicate($regNo, $excludeStudentId)) {
                $errors['registration_number'] = "Registration Number '$regNo' is already registered in the system.";
            }
        }
        $sanitized['registration_number'] = $regNo;

        // 2. Student Name (Required, Min 2 chars)
        $name = trim((string)($data['student_name'] ?? ''));
        if (empty($name)) {
            $errors['student_name'] = 'Student Name is required.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['student_name'] = 'Student Name must be between 2 and 150 characters.';
        }
        $sanitized['student_name'] = $name;

        // 3. Mobile Number (Required, 8-15 digits)
        $rawMobile = trim((string)($data['mobile_number'] ?? ''));
        $rawMobile = trim($rawMobile, " ='\"\t\r\n");
        $mobile = $this->normalizeNumberString($rawMobile);
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
        if (empty($cleanMobile)) {
            $errors['mobile_number'] = 'Mobile Number is required.';
        } elseif (strlen($cleanMobile) < 8 || strlen($cleanMobile) > 15) {
            $errors['mobile_number'] = 'Mobile Number must contain 8 to 15 digits.';
        }
        $sanitized['mobile_number'] = $cleanMobile;

        // 4. Alternate / Parent Mobile (Optional, 8-15 digits if present)
        $rawAltMobile = trim((string)($data['alt_mobile_number'] ?? ($data['parent_mobile'] ?? '')));
        $rawAltMobile = trim($rawAltMobile, " ='\"\t\r\n");
        $altMobile = $this->normalizeNumberString($rawAltMobile);
        if (!empty($altMobile)) {
            $cleanAltMobile = preg_replace('/[^0-9]/', '', $altMobile);
            if (strlen($cleanAltMobile) < 8 || strlen($cleanAltMobile) > 15) {
                $errors['alt_mobile_number'] = 'Alternate/Parent Mobile must contain 8 to 15 digits.';
            }
            $sanitized['alt_mobile_number'] = $cleanAltMobile;
            $sanitized['parent_mobile'] = $cleanAltMobile;
        } else {
            $sanitized['alt_mobile_number'] = null;
            $sanitized['parent_mobile'] = null;
        }

        // 5. Personal Email (Optional, valid email format)
        $email = trim((string)($data['email'] ?? ''));
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = "Personal Email format is invalid: '$email'";
            } elseif (strlen($email) > 150) {
                $errors['email'] = 'Personal Email cannot exceed 150 characters.';
            }
            $sanitized['email'] = strtolower($email);
        } else {
            $sanitized['email'] = null;
        }

        // 6. College Email (Optional, valid email format)
        $collegeEmail = trim((string)($data['college_email'] ?? ''));
        if (!empty($collegeEmail)) {
            if (!filter_var($collegeEmail, FILTER_VALIDATE_EMAIL)) {
                $errors['college_email'] = "College Email format is invalid: '$collegeEmail'";
            } elseif (strlen($collegeEmail) > 150) {
                $errors['college_email'] = 'College Email cannot exceed 150 characters.';
            }
            $sanitized['college_email'] = strtolower($collegeEmail);
        } else {
            $sanitized['college_email'] = null;
        }

        // 7. Date of Birth (Optional, valid date)
        $dob = trim((string)($data['dob'] ?? ''));
        if (!empty($dob)) {
            $parsedDate = $this->parseDate($dob);
            if (!$parsedDate) {
                $errors['dob'] = "Invalid Date of Birth: '$dob'. Use YYYY-MM-DD or DD-MM-YYYY.";
            } else {
                $sanitized['dob'] = $parsedDate;
            }
        } else {
            $sanitized['dob'] = null;
        }

        // 8. Gender (Optional, Male/Female/Other)
        $gender = trim((string)($data['gender'] ?? ''));
        if (!empty($gender)) {
            $normGender = ucfirst(strtolower($gender));
            if (in_array($normGender, ['M', 'Male'])) {
                $sanitized['gender'] = 'Male';
            } elseif (in_array($normGender, ['F', 'Female'])) {
                $sanitized['gender'] = 'Female';
            } else {
                $sanitized['gender'] = $gender;
            }
        } else {
            $sanitized['gender'] = null;
        }

        // 9. Blood Group (Optional)
        $bg = trim((string)($data['blood_group'] ?? ''));
        $sanitized['blood_group'] = !empty($bg) ? strtoupper($bg) : null;

        // 10. Aadhar Number (Optional, 12 digits)
        $rawAadhar = trim((string)($data['aadhar_number'] ?? ''));
        $rawAadhar = trim($rawAadhar, " ='\"\t\r\n");
        $aadhar = $this->normalizeNumberString($rawAadhar);
        if (!empty($aadhar)) {
            $cleanAadhar = preg_replace('/[^0-9]/', '', $aadhar);
            if (strlen($cleanAadhar) !== 12 && strlen($cleanAadhar) !== 0) {
                $errors['aadhar_number'] = 'Aadhar Number must be exactly 12 digits.';
            }
            $sanitized['aadhar_number'] = $cleanAadhar;
        } else {
            $sanitized['aadhar_number'] = null;
        }

        // 11. Department (Required, must exist)
        $deptId = (int)($data['dept_id'] ?? 0);
        if ($deptId <= 0 && !empty($data['dept_code'])) {
            $deptId = $this->lookupDeptIdByCode((string)$data['dept_code']);
        }
        if ($deptId <= 0 && !empty($data['dept_name'])) {
            $deptId = $this->lookupDeptIdByCode((string)$data['dept_name']);
        }
        if ($deptId <= 0 && !empty($data['department'])) {
            $deptId = $this->lookupDeptIdByCode((string)$data['department']);
        }
        if ($deptId <= 0) {
            $errors['dept_id'] = 'Department is required.';
        } else {
            $stmtDept = $this->db->prepare("SELECT dept_id FROM departments WHERE dept_id = ?");
            $stmtDept->execute([$deptId]);
            if (!$stmtDept->fetch()) {
                $errors['dept_id'] = 'Selected Department does not exist.';
            }
        }
        $sanitized['dept_id'] = $deptId;

        $batchId = (int)($data['batch_id'] ?? 0);
        if ($batchId <= 0 && !empty($data['batch_name'])) {
            $batchId = $this->lookupBatchIdByName((string)$data['batch_name'], $deptId);
        }
        if ($batchId <= 0 && !empty($data['batch'])) {
            $batchId = $this->lookupBatchIdByName((string)$data['batch'], $deptId);
        }
        if ($batchId <= 0 && isset($data['batch_start_yy']) && isset($data['batch_end_yy'])) {
            $sYy = (int)$data['batch_start_yy'];
            $eYy = (int)$data['batch_end_yy'];
            if ($sYy > 0 && $eYy > 0) {
                $adm = $sYy < 100 ? (2000 + $sYy) : $sYy;
                $grad = $eYy < 100 ? (2000 + $eYy) : $eYy;
                $batchId = $this->lookupBatchIdByName("$adm–$grad", $deptId);
            }
        }
        if ($batchId <= 0 && !empty($data['graduation_year'])) {
            $batchId = $this->lookupBatchIdByName((string)$data['graduation_year'], $deptId);
        }
        if ($batchId <= 0) {
            $errors['batch_id'] = 'Batch is required.';
        } else {
            $stmtBatch = $this->db->prepare("SELECT batch_id, admission_year, graduation_year, programme_duration, current_year_of_study FROM batches WHERE batch_id = ?");
            $stmtBatch->execute([$batchId]);
            $batchRow = $stmtBatch->fetch();
            if (!$batchRow) {
                $errors['batch_id'] = 'Selected Batch does not exist.';
            } else {
                $sanitized['admission_year'] = (int)$batchRow['admission_year'];
                $sanitized['graduation_year'] = (int)$batchRow['graduation_year'];

                // Dynamically compute cohort standing based on active session
                $actStart = 2026;
                $actStmt = $this->db->query("SELECT start_year FROM academic_sessions WHERE is_active = 1 LIMIT 1");
                if ($r = $actStmt->fetch()) {
                    $actStart = (int)$r['start_year'];
                }
                $duration = (int)($batchRow['programme_duration'] ?: 4);
                $yearNum = ($actStart - $sanitized['admission_year']) + (4 - $duration) + 1;
                if ($sanitized['graduation_year'] <= $actStart || $sanitized['graduation_year'] === ($actStart + 1) || $yearNum >= 4) {
                    $calcStudy = 'Final Year';
                } elseif ($yearNum === 3) {
                    $calcStudy = 'Third Year';
                } elseif ($yearNum === 2) {
                    $calcStudy = 'Second Year';
                } else {
                    $calcStudy = 'First Year';
                }

                $sanitized['year_of_study'] = !empty($data['year_of_study']) ? trim((string)$data['year_of_study']) : $calcStudy;
            }
        }
        $sanitized['batch_id'] = $batchId;

        // 13. Section (Optional, auto-create if new section for dept)
        $sectionId = (int)($data['section_id'] ?? 0);
        if ($sectionId <= 0 && !empty($data['section_name']) && $deptId > 0) {
            $sectionId = $this->lookupSectionIdByName($deptId, (string)$data['section_name']);
        }
        if ($sectionId > 0) {
            $stmtSec = $this->db->prepare("SELECT section_id FROM sections WHERE section_id = ?");
            $stmtSec->execute([$sectionId]);
            if (!$stmtSec->fetch()) {
                $errors['section_id'] = 'Selected Section does not exist.';
                $sectionId = null;
            }
        } else {
            $sectionId = null;
        }
        $sanitized['section_id'] = $sectionId;

        // 14. Academic Details (Optional percentages & CGPA)
        $tenth = isset($data['tenth_percentage']) && $data['tenth_percentage'] !== '' ? (float)$data['tenth_percentage'] : null;
        if ($tenth !== null && ($tenth < 0 || $tenth > 100)) {
            $errors['tenth_percentage'] = '10th Percentage must be between 0 and 100.';
        }
        $sanitized['tenth_percentage'] = $tenth;

        $twelfth = isset($data['twelfth_percentage']) && $data['twelfth_percentage'] !== '' ? (float)$data['twelfth_percentage'] : null;
        if ($twelfth !== null && ($twelfth < 0 || $twelfth > 100)) {
            $errors['twelfth_percentage'] = '12th Percentage must be between 0 and 100.';
        }
        $sanitized['twelfth_percentage'] = $twelfth;

        $diploma = isset($data['diploma_percentage']) && $data['diploma_percentage'] !== '' ? (float)$data['diploma_percentage'] : null;
        if ($diploma !== null && ($diploma < 0 || $diploma > 100)) {
            $errors['diploma_percentage'] = 'Diploma Percentage must be between 0 and 100.';
        }
        $sanitized['diploma_percentage'] = $diploma;

        $cgpa = isset($data['current_cgpa']) && $data['current_cgpa'] !== '' ? (float)$data['current_cgpa'] : null;
        if ($cgpa !== null && ($cgpa < 0 || $cgpa > 10)) {
            $errors['current_cgpa'] = 'Current CGPA must be a valid score between 0.00 and 10.00.';
        }
        $sanitized['current_cgpa'] = $cgpa;

        $sanitized['standing_arrears'] = isset($data['standing_arrears']) && $data['standing_arrears'] !== '' ? max(0, (int)$data['standing_arrears']) : 0;
        $sanitized['history_of_arrears'] = isset($data['history_of_arrears']) && $data['history_of_arrears'] !== '' ? max(0, (int)$data['history_of_arrears']) : 0;

        for ($i = 1; $i <= 8; $i++) {
            $key = "sem{$i}_gpa";
            $semGpa = isset($data[$key]) && $data[$key] !== '' ? (float)$data[$key] : null;
            if ($semGpa !== null && ($semGpa < 0 || $semGpa > 10)) {
                $errors[$key] = "Semester $i GPA must be between 0.00 and 10.00.";
            }
            $sanitized[$key] = $semGpa;
        }

        // 15. Placement Preferences & Status
        $willing = trim((string)($data['placement_willingness'] ?? 'Yes'));
        $sanitized['placement_willingness'] = in_array(ucfirst(strtolower($willing)), ['Yes', 'No']) ? ucfirst(strtolower($willing)) : 'Yes';

        $status = trim((string)($data['placement_status'] ?? 'Unplaced'));
        $allowedStatuses = ['Placed', 'Unplaced', 'Higher Studies', 'Entrepreneurship'];
        $sanitized['placement_status'] = in_array($status, $allowedStatuses) ? $status : 'Unplaced';

        $sanitized['hosteller_status'] = trim((string)($data['hosteller_status'] ?? '')) ?: null;
        $sanitized['licence_status'] = trim((string)($data['licence_status'] ?? '')) ?: null;
        $sanitized['passport_status'] = trim((string)($data['passport_status'] ?? '')) ?: null;
        if (!empty($data['current_semester']) && is_numeric($data['current_semester'])) {
            $sanitized['current_semester'] = (int)$data['current_semester'];
        } else {
            $yos = $sanitized['year_of_study'] ?? 'Third Year';
            if ($yos === 'First Year') {
                $sanitized['current_semester'] = 1;
            } elseif ($yos === 'Second Year') {
                $sanitized['current_semester'] = 3;
            } elseif ($yos === 'Third Year') {
                $sanitized['current_semester'] = 5;
            } else {
                $sanitized['current_semester'] = 7;
            }
        }

        // Profile attributes
        $sanitized['address'] = trim((string)($data['address'] ?? '')) ?: null;
        $sanitized['city'] = trim((string)($data['city'] ?? '')) ?: null;
        $sanitized['district'] = trim((string)($data['district'] ?? '')) ?: null;
        $sanitized['skills'] = trim((string)($data['skills'] ?? '')) ?: null;
        $sanitized['linkedin_url'] = trim((string)($data['linkedin_url'] ?? '')) ?: null;
        $sanitized['github_url'] = trim((string)($data['github_url'] ?? '')) ?: null;

        return [
            'isValid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized
        ];
    }

    /**
     * Check if a registration number already exists
     */
    public function checkDuplicate(string $registrationNumber, ?int $excludeStudentId = null): bool {
        $regNo = strtoupper(trim($registrationNumber));
        if (empty($regNo)) return false;

        $sql = "SELECT student_id FROM students WHERE UPPER(TRIM(registration_number)) = ?";
        $params = [$regNo];

        if ($excludeStudentId !== null && $excludeStudentId > 0) {
            $sql .= " AND student_id != ?";
            $params[] = $excludeStudentId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get student details by registration number
     */
    public function getStudentByRegNo(string $registrationNumber) {
        $stmt = $this->db->prepare("SELECT * FROM students WHERE UPPER(TRIM(registration_number)) = ?");
        $stmt->execute([strtoupper(trim($registrationNumber))]);
        return $stmt->fetch();
    }

    /**
     * Create a single student with linked profile and academic records in a transaction
     */
    public function createStudent(array $data, ?int $userId = null): int {
        $validation = $this->validateStudentData($data, true);
        if (!$validation['isValid']) {
            $msg = implode(' ', array_values($validation['errors']));
            throw new InvalidArgumentException($msg);
        }

        $s = $validation['sanitized'];

        $shouldCommit = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // 1. Insert into students table
            $stmtStudent = $this->db->prepare("
                INSERT INTO students (
                    registration_number, student_name, mobile_number, alt_mobile_number, email, college_email,
                    dob, gender, blood_group, aadhar_number, parent_mobile, hosteller_status, licence_status, passport_status,
                    dept_id, batch_id, section_id, admission_year, graduation_year, current_semester, year_of_study,
                    placement_willingness, placement_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtStudent->execute([
                $s['registration_number'],
                $s['student_name'],
                $s['mobile_number'],
                $s['alt_mobile_number'],
                $s['email'],
                $s['college_email'],
                $s['dob'],
                $s['gender'],
                $s['blood_group'],
                $s['aadhar_number'],
                $s['parent_mobile'],
                $s['hosteller_status'],
                $s['licence_status'],
                $s['passport_status'],
                $s['dept_id'],
                $s['batch_id'],
                $s['section_id'],
                $s['admission_year'],
                $s['graduation_year'],
                $s['current_semester'],
                $s['year_of_study'],
                $s['placement_willingness'],
                $s['placement_status']
            ]);
            $studentId = (int)$this->db->lastInsertId();

            // 2. Initialize student_profiles
            $stmtProfile = $this->db->prepare("
                INSERT INTO student_profiles (
                    student_id, address, city, district, skills, linkedin_url, github_url
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtProfile->execute([
                $studentId,
                $s['address'] ?? null,
                $s['city'] ?? null,
                $s['district'] ?? null,
                $s['skills'] ?? null,
                $s['linkedin_url'] ?? null,
                $s['github_url'] ?? null
            ]);

            // 3. Initialize student_academics
            $stmtAcademics = $this->db->prepare("
                INSERT INTO student_academics (
                    student_id, tenth_percentage, twelfth_percentage, diploma_percentage, current_cgpa,
                    standing_arrears, history_of_arrears,
                    sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa, sem7_gpa, sem8_gpa, gpa_up_to_6
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtAcademics->execute([
                $studentId,
                $s['tenth_percentage'],
                $s['twelfth_percentage'],
                $s['diploma_percentage'],
                $s['current_cgpa'],
                $s['standing_arrears'],
                $s['history_of_arrears'],
                $s['sem1_gpa'],
                $s['sem2_gpa'],
                $s['sem3_gpa'],
                $s['sem4_gpa'],
                $s['sem5_gpa'],
                $s['sem6_gpa'],
                $s['sem7_gpa'],
                $s['sem8_gpa'],
                $s['current_cgpa']
            ]);

            if ($userId) {
                logActivity($this->db, $userId, 'Create Student', "Added student {$s['registration_number']} - {$s['student_name']}");
            }

            if ($shouldCommit) {
                $this->db->commit();
            }

            return $studentId;
        } catch (Exception $e) {
            if ($shouldCommit && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing student and linked records
     */
    public function updateStudent(int $studentId, array $data, ?int $userId = null): bool {
        $validation = $this->validateStudentData($data, true, $studentId);
        if (!$validation['isValid']) {
            $msg = implode(' ', array_values($validation['errors']));
            throw new InvalidArgumentException($msg);
        }

        $s = $validation['sanitized'];

        $shouldCommit = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Update student
            $stmtStudent = $this->db->prepare("
                UPDATE students SET
                    registration_number = ?, student_name = ?, mobile_number = ?, alt_mobile_number = ?, email = ?, college_email = ?,
                    dob = ?, gender = ?, blood_group = ?, aadhar_number = ?, parent_mobile = ?, hosteller_status = ?, licence_status = ?, passport_status = ?,
                    dept_id = ?, batch_id = ?, section_id = ?, current_semester = ?, year_of_study = ?,
                    placement_willingness = ?, placement_status = ?
                WHERE student_id = ?
            ");
            $stmtStudent->execute([
                $s['registration_number'],
                $s['student_name'],
                $s['mobile_number'],
                $s['alt_mobile_number'],
                $s['email'],
                $s['college_email'],
                $s['dob'],
                $s['gender'],
                $s['blood_group'],
                $s['aadhar_number'],
                $s['parent_mobile'],
                $s['hosteller_status'],
                $s['licence_status'],
                $s['passport_status'],
                $s['dept_id'],
                $s['batch_id'],
                $s['section_id'],
                $s['current_semester'],
                $s['year_of_study'],
                $s['placement_willingness'],
                $s['placement_status'],
                $studentId
            ]);

            // Update academics
            $stmtAcademics = $this->db->prepare("
                INSERT INTO student_academics (
                    student_id, tenth_percentage, twelfth_percentage, diploma_percentage, current_cgpa,
                    standing_arrears, history_of_arrears,
                    sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa, sem7_gpa, sem8_gpa, gpa_up_to_6
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    tenth_percentage = VALUES(tenth_percentage),
                    twelfth_percentage = VALUES(twelfth_percentage),
                    diploma_percentage = VALUES(diploma_percentage),
                    current_cgpa = VALUES(current_cgpa),
                    standing_arrears = VALUES(standing_arrears),
                    history_of_arrears = VALUES(history_of_arrears),
                    sem1_gpa = VALUES(sem1_gpa),
                    sem2_gpa = VALUES(sem2_gpa),
                    sem3_gpa = VALUES(sem3_gpa),
                    sem4_gpa = VALUES(sem4_gpa),
                    sem5_gpa = VALUES(sem5_gpa),
                    sem6_gpa = VALUES(sem6_gpa),
                    sem7_gpa = VALUES(sem7_gpa),
                    sem8_gpa = VALUES(sem8_gpa),
                    gpa_up_to_6 = VALUES(gpa_up_to_6)
            ");
            $stmtAcademics->execute([
                $studentId,
                $s['tenth_percentage'],
                $s['twelfth_percentage'],
                $s['diploma_percentage'],
                $s['current_cgpa'],
                $s['standing_arrears'],
                $s['history_of_arrears'],
                $s['sem1_gpa'],
                $s['sem2_gpa'],
                $s['sem3_gpa'],
                $s['sem4_gpa'],
                $s['sem5_gpa'],
                $s['sem6_gpa'],
                $s['sem7_gpa'],
                $s['sem8_gpa'],
                $s['current_cgpa']
            ]);

            if ($userId) {
                logActivity($this->db, $userId, 'Update Student', "Updated student {$s['registration_number']}");
            }

            if ($shouldCommit) {
                $this->db->commit();
            }

            return true;
        } catch (Exception $e) {
            if ($shouldCommit && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete a student and all associated records, user account, and physical uploaded files
     *
     * @param int $studentId Student ID
     * @param int|null $performedByUserId User ID of admin/officer performing deletion
     * @return array ['success' => bool, 'message' => string]
     */
    public function deleteStudent(int $studentId, ?int $performedByUserId = null): array {
        if ($studentId <= 0) {
            return ['success' => false, 'message' => 'Invalid student ID.'];
        }

        try {
            // 1. Fetch student info
            $stmt = $this->db->prepare("SELECT student_id, user_id, registration_number, student_name FROM students WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                return ['success' => false, 'message' => 'Student record not found.'];
            }

            $userId = !empty($student['user_id']) ? (int)$student['user_id'] : null;
            $regNo = $student['registration_number'];
            $name = $student['student_name'];

            // 2. Fetch uploaded file paths before deleting database records to clean disk
            $filesToDelete = [];

            // Resume in student_profiles
            try {
                $stmtProfile = $this->db->prepare("SELECT resume_path FROM student_profiles WHERE student_id = ?");
                $stmtProfile->execute([$studentId]);
                $profResume = $stmtProfile->fetchColumn();
                if (!empty($profResume)) $filesToDelete[] = $profResume;
            } catch (Exception $e) {
                // Table or column might not exist
            }

            // Offer letters in student_placements
            try {
                $stmtOffers = $this->db->prepare("SELECT offer_letter_path FROM student_placements WHERE student_id = ?");
                $stmtOffers->execute([$studentId]);
                $offers = $stmtOffers->fetchAll(PDO::FETCH_COLUMN);
                foreach ($offers as $of) {
                    if (!empty($of)) $filesToDelete[] = $of;
                }
            } catch (Exception $e) {
                // Ignore
            }

            // Certifications in student_certifications
            try {
                $stmtCerts = $this->db->prepare("SELECT file_path FROM student_certifications WHERE student_id = ?");
                $stmtCerts->execute([$studentId]);
                $certs = $stmtCerts->fetchAll(PDO::FETCH_COLUMN);
                foreach ($certs as $cf) {
                    if (!empty($cf)) $filesToDelete[] = $cf;
                }
            } catch (Exception $e) {
                // Ignore
            }

            // Resume files in resume_files (Stage 2)
            try {
                $stmtResFiles = $this->db->prepare("SELECT file_path, resume_path FROM resume_files WHERE student_id = ?");
                $stmtResFiles->execute([$studentId]);
                $resFiles = $stmtResFiles->fetchAll(PDO::FETCH_ASSOC);
                foreach ($resFiles as $rf) {
                    if (!empty($rf['file_path'])) $filesToDelete[] = $rf['file_path'];
                    if (!empty($rf['resume_path'])) $filesToDelete[] = $rf['resume_path'];
                }
            } catch (\Throwable $e) {
                // Table doesn't exist or query failed, ignore
            }

            // Application documents
            try {
                $stmtAppDocs = $this->db->prepare("SELECT file_path FROM application_documents WHERE application_id IN (SELECT application_id FROM placement_applications WHERE student_id = ?)");
                $stmtAppDocs->execute([$studentId]);
                $appDocs = $stmtAppDocs->fetchAll(PDO::FETCH_COLUMN);
                foreach ($appDocs as $ad) {
                    if (!empty($ad)) $filesToDelete[] = $ad;
                }
            } catch (\Throwable $e) {}

            $this->db->beginTransaction();

            // 3. Clean up multi-level child and grandchild dependencies first
            try { $this->db->prepare("DELETE FROM application_stage_history WHERE application_id IN (SELECT application_id FROM placement_applications WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM application_documents WHERE application_id IN (SELECT application_id FROM placement_applications WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM interview_feedback WHERE attempt_id IN (SELECT attempt_id FROM interview_attempts WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM interview_answers WHERE attempt_id IN (SELECT attempt_id FROM interview_attempts WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM readiness_score_details WHERE score_id IN (SELECT score_id FROM placement_readiness_scores WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_analysis_sections WHERE analysis_id IN (SELECT analysis_id FROM resume_analysis WHERE resume_id IN (SELECT resume_id FROM resume_files WHERE student_id = ?))")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_analysis WHERE resume_id IN (SELECT resume_id FROM resume_files WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_extracted_text WHERE resume_id IN (SELECT resume_id FROM resume_files WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_versions WHERE resume_id IN (SELECT resume_id FROM resume_files WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_builder_sections WHERE builder_id IN (SELECT builder_id FROM resume_builder_profiles WHERE student_id = ?)")->execute([$studentId]); } catch (\Throwable $e) {}
            try { $this->db->prepare("DELETE FROM resume_builder_sections WHERE student_id = ?")->execute([$studentId]); } catch (\Throwable $e) {}

            // 4. Clean up all tables with direct student_id column
            $tablesWithStudentId = [
                'student_course_progress',
                'student_lesson_progress',
                'course_enrollments',
                'lesson_progress',
                'quiz_attempts',
                'student_badges',
                'student_certificates',
                'student_projects',
                'student_internships',
                'student_certifications',
                'student_skills',
                'officer_notes',
                'student_placements',
                'student_academics',
                'student_profiles',
                'student_update_logs',
                'eligibility_cache',
                'eligibility_overrides',
                'student_eligibility',
                'student_job_eligibility',
                'eligibility_history',
                'job_applications',
                'placement_applications',
                'student_opportunity_views',
                'mock_interview_sessions',
                'aptitude_daily_answers',
                'aptitude_daily_streaks',
                'aptitude_daily_progress',
                'aptitude_submissions',
                'aptitude_streaks',
                'aptitude_test_attempts',
                'leetcode_cache',
                'leetcode_profiles',
                'resume_job_matches',
                'resume_builder_profiles',
                'resume_files',
                'interview_attempts',
                'placement_readiness_scores',
                'readiness_score_details',
                'ai_usage_logs'
            ];

            foreach ($tablesWithStudentId as $tbl) {
                try {
                    $this->db->prepare("DELETE FROM `{$tbl}` WHERE student_id = ?")->execute([$studentId]);
                } catch (\Throwable $e) {
                    // Ignore if table does not exist
                }
            }

            // 5. Clean up records referencing registration number
            if (!empty($regNo)) {
                try {
                    $this->db->prepare("DELETE FROM excel_import_rows WHERE registration_number = ?")->execute([$regNo]);
                } catch (\Throwable $e) {}

                try {
                    $this->db->prepare("DELETE FROM student_login_attempts WHERE username = ?")->execute([$regNo]);
                } catch (\Throwable $e) {}
            }

            // 6. Unbind user_id from students to prevent foreign key constraint issues
            try {
                $this->db->prepare("UPDATE students SET user_id = NULL WHERE student_id = ?")->execute([$studentId]);
            } catch (\Throwable $e) {}

            // 7. Delete the student record permanently
            $delStmt = $this->db->prepare("DELETE FROM students WHERE student_id = ? OR (registration_number = ? AND registration_number != '')");
            $delStmt->execute([$studentId, $regNo]);

            // 8. Find and delete associated user account(s)
            $userIdsToDelete = [];
            if ($userId) {
                $userIdsToDelete[] = $userId;
            }
            if (!empty($regNo)) {
                try {
                    $uStmt = $this->db->prepare("SELECT user_id FROM users WHERE username = ?");
                    $uStmt->execute([$regNo]);
                    $foundUIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($foundUIds as $fId) {
                        if ($fId && !in_array((int)$fId, $userIdsToDelete)) {
                            $userIdsToDelete[] = (int)$fId;
                        }
                    }
                } catch (\Throwable $e) {}
            }

            foreach ($userIdsToDelete as $uId) {
                try { $this->db->prepare("DELETE FROM stage2_workflow_audit WHERE action_by = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM stage2_audit_trail WHERE action_by = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM notification_recipients WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM announcement_reads WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM activity_logs WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
                try { $this->db->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uId]); } catch (\Throwable $e) {}
            }

            $this->db->commit();

            // 6. Delete physical files from disk
            $baseDir = dirname(__DIR__) . '/';
            foreach ($filesToDelete as $relPath) {
                $relPath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relPath), DIRECTORY_SEPARATOR);
                $fullPath = $baseDir . $relPath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }

            // 7. Log activity
            if ($performedByUserId) {
                logActivity($this->db, $performedByUserId, 'Delete Student', "Deleted student $regNo ($name) [ID: $studentId]");
            }

            return [
                'success' => true,
                'message' => "Student '$name' ($regNo) has been permanently deleted from the database."
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Failed to delete student: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Bulk delete multiple students
     *
     * @param array $studentIds List of Student IDs
     * @param int|null $performedByUserId User ID of admin/officer
     * @return array ['success' => bool, 'deletedCount' => int, 'message' => string]
     */
    public function deleteMultipleStudents(array $studentIds, ?int $performedByUserId = null): array {
        $deletedCount = 0;
        $errors = [];

        foreach ($studentIds as $sId) {
            $id = (int)$sId;
            if ($id > 0) {
                $res = $this->deleteStudent($id, $performedByUserId);
                if ($res['success']) {
                    $deletedCount++;
                } else {
                    $errors[] = $res['message'];
                }
            }
        }

        if ($deletedCount > 0) {
            return [
                'success' => true,
                'deletedCount' => $deletedCount,
                'message' => "Successfully deleted $deletedCount student record(s)." . (!empty($errors) ? ' (' . count($errors) . ' failed)' : '')
            ];
        }

        return [
            'success' => false,
            'deletedCount' => 0,
            'message' => !empty($errors) ? implode(', ', $errors) : 'No students were deleted.'
        ];
    }

    /**
     * Parse and validate Sheet data (from Excel or CSV) for preview
     */
    public function validateCsvData(array $parsedRows, array $defaultConfig = []): array {
        if (count($parsedRows) <= 1) {
            return [
                'isValid' => false,
                'message' => 'The uploaded spreadsheet contains no data rows or only headers.',
                'summary' => [
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'duplicate_rows' => 0,
                    'existing_rows' => 0,
                    'invalid_rows' => 0
                ],
                'preview_rows' => [],
                'failed_rows' => [],
                'valid_records' => []
            ];
        }

        $headers = array_map(function($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
            return trim($h);
        }, $parsedRows[0]);

        $dataRows = array_slice($parsedRows, 1);
        $headerMap = ExcelHelper::autoMapColumns($headers);

        $seenInFile = [];
        $previewRows = [];
        $failedRows = [];
        $validRecords = [];
        $createdSessions = [];

        $totalCount = 0;
        $validCount = 0;
        $fileDuplicateCount = 0;
        $dbExistingCount = 0;
        $invalidCount = 0;

        foreach ($dataRows as $index => $row) {
            // Skip empty rows
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $totalCount++;
            $rowNumber = $index + 2; // 1-based + 1 for header
            $rowData = [];

            // Extract using header mapping
            foreach ($headerMap as $dbField => $colIdx) {
                $rowData[$dbField] = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : '';
            }

            // Detect and ensure Academic Session exists from row session, batch, default batch, or filename
            $sessionCandidate = $rowData['academic_session'] ?? '';
            if (empty($sessionCandidate) && !empty($rowData['batch_name'])) {
                $sessionCandidate = $rowData['batch_name'];
            }
            if (empty($sessionCandidate) && !empty($defaultConfig['batch_name'])) {
                $sessionCandidate = $defaultConfig['batch_name'];
            }
            if (empty($sessionCandidate) && !empty($defaultConfig['filename'])) {
                $sessionCandidate = $defaultConfig['filename'];
            }

            if (!empty($sessionCandidate)) {
                $sRes = ensureAcademicSessionExists($this->db, $sessionCandidate);
                if (!empty($sRes['is_new']) && !in_array($sRes['session_name'], $createdSessions, true)) {
                    $createdSessions[] = $sRes['session_name'];
                }
            }

            // Apply default configurations if not specified in row
            if (empty($rowData['dept_id']) && empty($rowData['dept_code']) && !empty($defaultConfig['dept_id'])) {
                $rowData['dept_id'] = $defaultConfig['dept_id'];
            }
            if (empty($rowData['batch_id']) && empty($rowData['batch_name']) && !empty($defaultConfig['batch_id'])) {
                $rowData['batch_id'] = $defaultConfig['batch_id'];
            }
            if (empty($rowData['section_id']) && empty($rowData['section_name']) && !empty($defaultConfig['section_id'])) {
                $rowData['section_id'] = $defaultConfig['section_id'];
            }

            // Auto-resolve batch_id from batch_name if provided
            if (empty($rowData['batch_id']) && !empty($rowData['batch_name'])) {
                $resolvedBatchId = $this->lookupBatchIdByName($rowData['batch_name'], (int)($rowData['dept_id'] ?? 0));
                if ($resolvedBatchId > 0) {
                    $rowData['batch_id'] = $resolvedBatchId;
                }
            }

            $rawRegNo = strtoupper(trim((string)($rowData['registration_number'] ?? '')));
            $rawRegNo = trim($rawRegNo, " ='\"\t\r\n");
            $regNo = $this->normalizeNumberString($rawRegNo);

            $rowStatus = 'valid';
            $rowErrors = [];

            // Run field-level validation (with checkDuplicate = false)
            $valResult = $this->validateStudentData($rowData, false);
            $regNo = $valResult['sanitized']['registration_number'] ?? $regNo;
            $rowData['registration_number'] = $regNo;

            if (!$valResult['isValid']) {
                foreach ($valResult['errors'] as $fld => $errMsg) {
                    $rowErrors[] = $errMsg;
                }
                $rowStatus = 'invalid';
                $invalidCount++;
            } else {
                // Check duplicate within the uploaded file
                if (!empty($regNo) && isset($seenInFile[$regNo])) {
                    $rowStatus = 'file_duplicate';
                    $fileDuplicateCount++;
                    $rowErrors[] = "Duplicate Registration Number in file (Row {$seenInFile[$regNo]} and Row {$rowNumber}).";
                } else {
                    if (!empty($regNo)) {
                        $seenInFile[$regNo] = $rowNumber;
                    }

                    // Check duplicate in database
                    if (!empty($regNo) && $this->checkDuplicate($regNo)) {
                        $rowStatus = 'db_duplicate';
                        $dbExistingCount++;
                        $validRecords[] = [
                            'row_number' => $rowNumber,
                            'data' => $valResult['sanitized'],
                            'is_update' => true
                        ];
                    } else {
                        $rowStatus = 'valid';
                        $validCount++;
                        $validRecords[] = [
                            'row_number' => $rowNumber,
                            'data' => $valResult['sanitized'],
                            'is_update' => false
                        ];
                    }
                }
            }

            if ($rowStatus === 'invalid' || $rowStatus === 'file_duplicate') {
                $failedRows[] = [
                    'row_number' => $rowNumber,
                    'raw_data' => $row,
                    'registration_number' => $regNo,
                    'student_name' => $rowData['student_name'] ?? '',
                    'errors' => $rowErrors,
                    'error_message' => implode(' | ', $rowErrors)
                ];
            }

            $previewRows[] = [
                'row_number' => $rowNumber,
                'registration_number' => $regNo ?: 'N/A',
                'student_name' => $rowData['student_name'] ?? 'N/A',
                'mobile_number' => $rowData['mobile_number'] ?? 'N/A',
                'dept_id' => $rowData['dept_id'] ?? '',
                'batch_id' => $rowData['batch_id'] ?? '',
                'current_cgpa' => $rowData['current_cgpa'] ?? 'N/A',
                'standing_arrears' => $rowData['standing_arrears'] ?? '0',
                'status' => $rowStatus,
                'errors' => $rowErrors
            ];
        }

        return [
            'isValid' => $totalCount > 0,
            'summary' => [
                'total_rows' => $totalCount,
                'valid_rows' => $validCount,
                'duplicate_rows' => $fileDuplicateCount,
                'existing_rows' => $dbExistingCount,
                'invalid_rows' => $invalidCount,
                'created_sessions' => $createdSessions
            ],
            'headers' => $headers,
            'preview_rows' => array_slice($previewRows, 0, 100),
            'failed_rows' => $failedRows,
            'valid_records' => $validRecords
        ];
    }

    /**
     * Batch import valid records using safe database transactions
     */
    public function batchImport(array $records, string $duplicateStrategy = 'skip', ?int $userId = null): array {
        $stats = [
            'total' => count($records),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_details' => []
        ];

        $chunks = array_chunk($records, 50);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            try {
                foreach ($chunk as $item) {
                    $data = $item['data'];
                    $isUpdate = $item['is_update'] ?? false;
                    $regNo = $data['registration_number'];

                    if ($isUpdate) {
                        if ($duplicateStrategy === 'overwrite') {
                            $existing = $this->getStudentByRegNo($regNo);
                            if ($existing) {
                                $this->updateStudent((int)$existing['student_id'], $data, $userId);
                                $stats['updated']++;
                            } else {
                                $this->createStudent($data, $userId);
                                $stats['imported']++;
                            }
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        $this->createStudent($data, $userId);
                        $stats['imported']++;
                    }
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                $stats['failed'] += count($chunk);
                $stats['failed_details'][] = "Batch write failed: " . $e->getMessage();
            }
        }

        if ($userId) {
            logActivity(
                $this->db,
                $userId,
                'Excel Student Import',
                "Imported: {$stats['imported']}, Updated: {$stats['updated']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}"
            );
        }

        // Always synchronize batch mappings across all academic sessions after import
        syncBatchAcademicSessions($this->db);

        return $stats;
    }

    /**
     * Get standard sample rows for templates
     */
    public static function getSampleTemplateRows(): array {
        $headers = [
            'registration_number',
            'student_name',
            'mobile_number',
            'alt_mobile_number',
            'email',
            'college_email',
            'dob',
            'gender',
            'blood_group',
            'aadhar_number',
            'dept_code',
            'batch_name',
            'section_name',
            'tenth_percentage',
            'twelfth_percentage',
            'diploma_percentage',
            'current_cgpa',
            'standing_arrears',
            'history_of_arrears',
            'sem1_gpa',
            'sem2_gpa',
            'sem3_gpa',
            'sem4_gpa',
            'sem5_gpa',
            'sem6_gpa',
            'sem7_gpa',
            'sem8_gpa',
            'placement_willingness'
        ];

        $sampleRow1 = [
            '812421104001',
            'Aravind Kumar R',
            '9876543210',
            '9876543211',
            'aravind.k@gmail.com',
            'aravind.21cse@mamcet.org',
            '2003-05-15',
            'Male',
            'O+',
            '123456789012',
            'CSE',
            '2023 - 2027',
            'A',
            '89.50',
            '86.20',
            '',
            '8.45',
            '0',
            '0',
            '8.20',
            '8.40',
            '8.60',
            '8.50',
            '8.55',
            '',
            '',
            '',
            'Yes'
        ];

        $sampleRow2 = [
            '812421104002',
            'Deepika S',
            '9123456780',
            '9123456781',
            'deepika.s@gmail.com',
            'deepika.21cse@mamcet.org',
            '2003-08-22',
            'Female',
            'B+',
            '987654321098',
            'CSE',
            '2023 - 2027',
            'A',
            '92.00',
            '90.50',
            '',
            '8.90',
            '0',
            '0',
            '8.80',
            '8.90',
            '9.00',
            '8.95',
            '8.85',
            '',
            '',
            '',
            'Yes'
        ];

        $sampleRow3 = [
            '812023103001',
            'Aryan K',
            '9977992299',
            '1122334455',
            'aryan@gmail.com',
            'aryan.24civil@mamcet.org',
            '2004-09-08',
            'Male',
            'A+',
            '555556666677',
            'CIVIL',
            '2024 - 2027',
            'A',
            '99.00',
            '99.00',
            '',
            '9.10',
            '0',
            '0',
            '9.00',
            '9.20',
            '',
            '',
            '',
            '',
            '',
            '',
            'Yes'
        ];

        return [$headers, $sampleRow1, $sampleRow2, $sampleRow3];
    }

    /**
     * Generate standard sample Excel (.xlsx) template file bytes
     */
    public static function generateSampleExcelTemplate(): string {
        $rows = self::getSampleTemplateRows();
        return ExcelHelper::createXlsx($rows);
    }

    /**
     * Generate standard sample CSV template string
     */
    public static function generateSampleCsvTemplate(): string {
        $rows = self::getSampleTemplateRows();
        $output = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($output, $r);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Generate downloadable Excel (.xlsx) file of failed rows
     */
    public static function generateFailedRowsXlsx(array $failedRows, array $headers = []): string {
        $grid = [];
        if (empty($headers)) {
            $grid[] = ['Row Number', 'Registration Number', 'Student Name', 'Error Reasons'];
            foreach ($failedRows as $item) {
                $grid[] = [
                    (string)($item['row_number'] ?? ''),
                    (string)($item['registration_number'] ?? ''),
                    (string)($item['student_name'] ?? ''),
                    (string)($item['error_message'] ?? implode('; ', $item['errors'] ?? []))
                ];
            }
        } else {
            $grid[] = array_merge($headers, ['Import Error Reasons']);
            foreach ($failedRows as $item) {
                $raw = $item['raw_data'] ?? [];
                $errMsg = (string)($item['error_message'] ?? implode('; ', $item['errors'] ?? []));
                $grid[] = array_merge($raw, [$errMsg]);
            }
        }

        return ExcelHelper::createXlsx($grid);
    }

    /**
     * Generate downloadable CSV of failed rows with error reasons
     */
    public static function generateFailedRowsCsv(array $failedRows, array $headers = []): string {
        $output = fopen('php://temp', 'r+');

        if (empty($headers)) {
            $headers = [
                'Row Number',
                'Registration Number',
                'Student Name',
                'Error Reasons'
            ];
            fputcsv($output, $headers);

            foreach ($failedRows as $item) {
                fputcsv($output, [
                    $item['row_number'] ?? '',
                    $item['registration_number'] ?? '',
                    $item['student_name'] ?? '',
                    $item['error_message'] ?? implode('; ', $item['errors'] ?? [])
                ]);
            }
        } else {
            // Append Error Reason to original headers
            $fullHeaders = array_merge($headers, ['Import Error Reasons']);
            fputcsv($output, $fullHeaders);

            foreach ($failedRows as $item) {
                $raw = $item['raw_data'] ?? [];
                $errMsg = $item['error_message'] ?? implode('; ', $item['errors'] ?? []);
                $row = array_merge($raw, [$errMsg]);
                fputcsv($output, $row);
            }
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    // Helper functions
    public function normalizeNumberString(string $val): string {
        $val = trim($val);
        $val = trim($val, " ='\"\t\r\n");
        if (empty($val)) return '';

        // If in scientific notation (e.g. 8.12E+11, 8.12421104001E+11, 9.88E+09)
        if (preg_match('/^[0-9]+(\.[0-9]+)?[eE]\+[0-9]+$/', $val)) {
            return sprintf('%.0f', (float)$val);
        }
        return $val;
    }

    public function lookupDeptIdByCode(string $code): int {
        $raw = trim($code);
        if (empty($raw)) return 0;
        if (is_numeric($raw) && (int)$raw > 0 && (int)$raw < 100) return (int)$raw;

        $clean = strtoupper($raw);
        $compact = preg_replace('/[^A-Z0-9]/', '', $clean);

        // 1. Direct code or short_name or dept_name
        $stmt = $this->db->prepare("
            SELECT dept_id FROM departments 
            WHERE UPPER(dept_code) = ? 
               OR UPPER(short_name) = ? 
               OR UPPER(dept_name) = ?
            LIMIT 1
        ");
        $stmt->execute([$clean, $clean, $clean]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        // 2. Compact matching (ignoring &, -, space, etc.)
        $stmtAll = $this->db->query("SELECT dept_id, dept_code, short_name, dept_name FROM departments");
        while ($d = $stmtAll->fetch()) {
            $dCodeCompact = preg_replace('/[^A-Z0-9]/', '', strtoupper($d['dept_code']));
            $dShortCompact = preg_replace('/[^A-Z0-9]/', '', strtoupper($d['short_name']));
            $dNameCompact = preg_replace('/[^A-Z0-9]/', '', strtoupper($d['dept_name']));

            if ($compact === $dCodeCompact || $compact === $dShortCompact || $compact === $dNameCompact) {
                return (int)$d['dept_id'];
            }
            if (strlen($compact) >= 3 && (strpos($dNameCompact, $compact) !== false || strpos($compact, $dCodeCompact) !== false)) {
                return (int)$d['dept_id'];
            }
        }

        return 0;
    }

    public function lookupBatchIdByName(string $name, int $deptId = 0): int {
        $raw = trim($name);
        if (empty($raw)) return 0;
        if (is_numeric($raw) && (int)$raw > 0 && (int)$raw < 1000) {
            return (int)$raw; // direct batch_id
        }

        // 1. Extract years (e.g. 2024–2028, 2024-2028, 24-28, 24–28, 2024/2028, 2024-28)
        if (preg_match('/(\d{2,4})\s*[-–—\/]\s*(\d{2,4})/u', $raw, $m)) {
            $adm = (int)$m[1];
            $grad = (int)$m[2];
            if ($adm < 100) $adm += 2000;
            if ($grad < 100) $grad += 2000;

            // Automatically ensure the final placement academic session exists in the app!
            ensureAcademicSessionExists($this->db, $grad);

            $stmt = $this->db->prepare("SELECT batch_id FROM batches WHERE admission_year = ? AND graduation_year = ? LIMIT 1");
            $stmt->execute([$adm, $grad]);
            $id = $stmt->fetchColumn();
            if ($id) {
                syncBatchAcademicSessions($this->db);
                return (int)$id;
            }

            // Auto-create batch on-the-fly if not found!
            $duration = max(1, $grad - $adm);
            
            // Determine year of study relative to active academic session
            $actStart = 2026;
            $actStmt = $this->db->query("SELECT start_year FROM academic_sessions WHERE is_active = 1 LIMIT 1");
            if ($actRow = $actStmt->fetch()) {
                $actStart = (int)$actRow['start_year'];
            }
            $yearNum = ($actStart - $adm) + (4 - $duration) + 1;
            if ($grad <= $actStart) {
                $yearOfStudy = 'Final Year';
            } elseif ($grad === ($actStart + 1) || $yearNum >= 4) {
                $yearOfStudy = 'Final Year';
            } elseif ($yearNum === 3) {
                $yearOfStudy = 'Third Year';
            } elseif ($yearNum === 2) {
                $yearOfStudy = 'Second Year';
            } else {
                $yearOfStudy = 'First Year';
            }
            $progId = 1;
            if ($deptId > 0) {
                $pStmt = $this->db->prepare("SELECT programme_id FROM departments WHERE dept_id = ?");
                $pStmt->execute([$deptId]);
                $progId = (int)$pStmt->fetchColumn() ?: 1;
            }
            $batchName = "$adm–$grad";
            $desc = $duration === 3 ? "Batch $batchName (Lateral Entry)" : "Batch $batchName";
            
            $insStmt = $this->db->prepare("
                INSERT INTO batches (batch_name, admission_year, graduation_year, programme_id, programme_duration, current_year_of_study, status, description)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $insStmt->execute([$batchName, $adm, $grad, $progId, $duration, $yearOfStudy, $desc]);
            $newBatchId = (int)$this->db->lastInsertId();

            // Automatically sync batch mappings across all academic sessions
            syncBatchAcademicSessions($this->db);

            return $newBatchId;
        }

        // 2. Single 4-digit year (e.g. 2027 or 2028)
        if (preg_match('/^\d{4}$/', $raw)) {
            $yr = (int)$raw;
            ensureAcademicSessionExists($this->db, $yr);
            $stmt = $this->db->prepare("SELECT batch_id FROM batches WHERE graduation_year = ? OR admission_year = ? LIMIT 1");
            $stmt->execute([$yr, $yr]);
            $id = $stmt->fetchColumn();
            if ($id) {
                syncBatchAcademicSessions($this->db);
                return (int)$id;
            }
        }

        // 3. Normalized string matching against batch_name
        $normalizedInput = preg_replace('/[\s–—−\-]/u', '', strtoupper($raw));
        $stmtAll = $this->db->query("SELECT batch_id, batch_name, admission_year, graduation_year, current_year_of_study FROM batches");
        while ($b = $stmtAll->fetch()) {
            $bNorm = preg_replace('/[\s–—−\-]/u', '', strtoupper($b['batch_name']));
            if ($normalizedInput === $bNorm) {
                return (int)$b['batch_id'];
            }
            if (!empty($b['current_year_of_study']) && stripos($raw, $b['current_year_of_study']) !== false) {
                return (int)$b['batch_id'];
            }
        }

        return 0;
    }

    public function lookupSectionIdByName(int $deptId, string $sectionName): int {
        $raw = trim($sectionName);
        if (empty($raw)) return 0;
        if (is_numeric($raw) && (int)$raw > 0 && (int)$raw < 100) {
            $stmt = $this->db->prepare("SELECT section_id FROM sections WHERE dept_id = ? AND section_id = ? LIMIT 1");
            $stmt->execute([$deptId, (int)$raw]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }

        $clean = strtoupper(trim(preg_replace('/^section\s*/i', '', $raw)));
        $stmt = $this->db->prepare("SELECT section_id FROM sections WHERE dept_id = ? AND UPPER(section_name) = ? LIMIT 1");
        $stmt->execute([$deptId, $clean]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        // Auto-create section for dept if not found!
        if ($deptId > 0 && !empty($clean) && strlen($clean) <= 10) {
            $insStmt = $this->db->prepare("INSERT INTO sections (section_name, dept_id) VALUES (?, ?)");
            $insStmt->execute([$clean, $deptId]);
            return (int)$this->db->lastInsertId();
        }

        return 0;
    }

    private function parseDate(string $dateStr): ?string {
        $dateStr = trim($dateStr);
        if (empty($dateStr)) return null;

        // Try Y-m-d
        $d = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($d && $d->format('Y-m-d') === $dateStr) {
            return $dateStr;
        }

        // Try d-m-Y or d/m/Y or d.m.Y or d-m-y (e.g. 15-05-03, 22-09-08)
        $formats = ['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d', 'm/d/Y', 'd-M-Y', 'j-M-Y', 'j/n/Y', 'd-m-y', 'd/m/y', 'd.m.y'];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $dateStr);
            if ($d) {
                // If 2-digit year (e.g. '03' -> '2003', '08' -> '2008')
                $year = (int)$d->format('Y');
                if ($year > 2050) {
                    $year -= 100;
                    return $d->setDate($year, (int)$d->format('m'), (int)$d->format('d'))->format('Y-m-d');
                }
                return $d->format('Y-m-d');
            }
        }

        // Try strtotime
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Fetch all certificates uploaded by a specific student.
     *
     * @param int $studentId
     * @return array
     */
    public function getStudentCertificates(int $studentId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT cert_id, student_id, title, issuing_organization, issue_date, 
                       credential_id, credential_url, skills, file_path, file_name, file_size, 
                       status, created_at
                FROM student_certifications
                WHERE student_id = ?
                ORDER BY created_at DESC, cert_id DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log("Failed to fetch student certificates: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of certificates uploaded by a specific student.
     *
     * @param int $studentId
     * @return int
     */
    public function getStudentCertificateCount(int $studentId): int {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM student_certifications WHERE student_id = ?");
            $stmt->execute([$studentId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Delete a certificate, enforcing student ownership unless caller is an admin.
     *
     * @param int $certId
     * @param int|null $studentId
     * @param bool $isAdmin
     * @return array ['success' => bool, 'message' => string]
     */
    public function deleteCertificate(int $certId, ?int $studentId = null, bool $isAdmin = false): array {
        try {
            $stmt = $this->db->prepare("SELECT cert_id, student_id, file_path, title FROM student_certifications WHERE cert_id = ?");
            $stmt->execute([$certId]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cert) {
                return ['success' => false, 'message' => 'Certificate record not found.'];
            }

            if (!$isAdmin && (!isset($studentId) || (int)$cert['student_id'] !== (int)$studentId)) {
                return ['success' => false, 'message' => 'Unauthorized: You can only delete your own certificates.'];
            }

            // Remove physical file from disk if it exists
            if (!empty($cert['file_path'])) {
                $absolutePath = __DIR__ . '/../' . ltrim($cert['file_path'], '/\\');
                if (file_exists($absolutePath) && is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            $delStmt = $this->db->prepare("DELETE FROM student_certifications WHERE cert_id = ?");
            $delStmt->execute([$certId]);

            return ['success' => true, 'message' => 'Certificate deleted successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}

