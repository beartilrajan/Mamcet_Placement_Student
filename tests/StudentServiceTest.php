<?php
// MAMCET Placement & Learning Portal - Automated Test Suite for Student Management & CSV Import

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/StudentService.php');

class TestRunner {
    private $passed = 0;
    private $failed = 0;
    private $db;
    private $studentService;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->studentService = new StudentService($this->db);
    }

    public function assert($condition, $testName, $detail = '') {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] $testName\n";
        } else {
            $this->failed++;
            echo "  [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
        }
    }

    public function run() {
        echo "====================================================\n";
        echo " MAMCET Student Management & CSV Import Test Suite \n";
        echo "====================================================\n\n";

        $this->setupTestData();

        $this->testValidationRules();
        $this->testDuplicateDetection();
        $this->testManualStudentCreation();
        $this->testCsvValidationAndPreview();
        $this->testBatchImportExecution();
        $this->testCsvTemplateAndFailedRowsExport();
        $this->testAuthorizationGuards();
        $this->testExcelCsvWithScientificNotationAndBOM();

        $this->cleanupTestData();

        echo "\n====================================================\n";
        echo " Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "====================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function testExcelCsvWithScientificNotationAndBOM() {
        echo "Test Suite 8: Multi-department CSV, Lateral Batches & Scientific Notation\n";

        $headersWithBOM = ["\xEF\xBB\xBFregistration_number", "student_name", "mobile_number", "alt_mobile_number", "email", "college_email", "dob", "gender", "blood_group", "aadhar_number", "dept_code", "batch_name", "section_name", "tenth_percentage", "twelfth_percentage", "diploma_percentage", "current_cgpa"];
        $row1 = ['812421104001', 'Aravind Kumar R', '9876543210', '9876543211', 'aravind.k@gmail.com', 'aravind.21cse@mamcet.org', '15-05-03', 'Male', 'O+', '123456789012', 'CSE', '2023 - 2027', 'A', '89.5', '86.2', '', '8.45'];
        $row2 = ['812421104002', 'Deepika S', '9123456780', '9123456781', 'deepika.s@gmail.com', 'deepika.21cse@mamcet.org', '22-08-03', 'Female', 'B+', '987654321098', 'CSE', '2023 - 2027', 'A', '92', '90.5', '', '8.9'];
        $row3 = ['812023103001', 'Aryan', '9977992299', '1122334455', 'aryan@gmail.com', 'aryan@mamacet.com', '22-09-08', 'Male', 'A+', '555556666677', 'CIVIL', '2024-2027', 'A', '99', '99', '', '9.10'];

        $valResult = $this->studentService->validateCsvData([$headersWithBOM, $row1, $row2, $row3]);

        $this->assert($valResult['isValid'], "Multi-dept CSV with BOM is valid");
        $this->assert($valResult['summary']['total_rows'] === 3, "CSV identified 3 rows");
        $this->assert($valResult['summary']['valid_rows'] === 3, "All 3 distinct rows successfully parsed as valid");
        $this->assert($valResult['summary']['invalid_rows'] === 0, "0 invalid rows");

        // Verify CIVIL and 2024-2027 batch resolution
        $thirdRecord = $valResult['valid_records'][2]['data'];
        $this->assert($thirdRecord['registration_number'] === '812023103001', "Registration number 812023103001 preserved correctly");
        $this->assert($thirdRecord['dept_id'] === 6, "CIVIL department resolved to dept_id = 6");
        $this->assert($thirdRecord['batch_id'] > 0, "Lateral Batch '2024-2027' resolved to batch_id");

        // Verify that corrupted scientific notation is flagged with guidance
        $corruptRow = ['8.12421E+11', 'Corrupt User', '9876543210', '', '', '', '', '', '', '', 'CSE', '2023-2027', 'A', '', '', '', ''];
        $corruptResult = $this->studentService->validateCsvData([$headersWithBOM, $corruptRow]);
        $this->assert($corruptResult['summary']['invalid_rows'] === 1, "Corrupted scientific notation flagged as invalid");
        $this->assert(strpos($corruptResult['failed_rows'][0]['error_message'], 'truncated by Excel scientific notation') !== false, "Error message contains Excel text format guidance");

        echo "\n";
    }

    private function setupTestData() {
        // Ensure at least one department and batch exists
        $stmtDept = $this->db->query("SELECT dept_id FROM departments WHERE dept_code = 'CSE' LIMIT 1");
        $deptId = $stmtDept->fetchColumn();
        if (!$deptId) {
            $this->db->exec("INSERT INTO departments (dept_code, dept_name, short_name, status) VALUES ('CSE', 'Computer Science and Engineering', 'CSE', 'active')");
        }

        $stmtBatch = $this->db->query("SELECT batch_id FROM batches WHERE batch_name = '2023 - 2027' LIMIT 1");
        $batchId = $stmtBatch->fetchColumn();
        if (!$batchId) {
            $stmtProg = $this->db->query("SELECT programme_id FROM programmes LIMIT 1");
            $progId = $stmtProg->fetchColumn() ?: 1;
            $this->db->exec("INSERT INTO batches (batch_name, admission_year, graduation_year, programme_id, current_year_of_study, status) VALUES ('2023 - 2027', 2023, 2027, $progId, 'Final Year', 'active')");
        }
    }

    private function testValidationRules() {
        echo "Test Suite 1: Validation Rules & Constraints\n";

        // 1. Missing required fields
        $val1 = $this->studentService->validateStudentData([]);
        $this->assert(!$val1['isValid'], "Fails when all required fields are missing");
        $this->assert(isset($val1['errors']['registration_number']), "Errors include registration_number");
        $this->assert(isset($val1['errors']['student_name']), "Errors include student_name");
        $this->assert(isset($val1['errors']['mobile_number']), "Errors include mobile_number");
        $this->assert(isset($val1['errors']['dept_id']), "Errors include dept_id");
        $this->assert(isset($val1['errors']['batch_id']), "Errors include batch_id");

        // 2. Invalid Phone formats
        $valPhoneBad = $this->studentService->validateStudentData([
            'registration_number' => 'TEST_REG_01',
            'student_name' => 'John Doe',
            'mobile_number' => '1234', // too short
            'dept_code' => 'CSE',
            'batch_name' => '2023 - 2027'
        ]);
        $this->assert(!$valPhoneBad['isValid'] && isset($valPhoneBad['errors']['mobile_number']), "Rejects phone number with fewer than 8 digits");

        // 3. Invalid Email formats
        $valEmailBad = $this->studentService->validateStudentData([
            'registration_number' => 'TEST_REG_02',
            'student_name' => 'Jane Doe',
            'mobile_number' => '9876543210',
            'email' => 'invalid-email-address',
            'dept_code' => 'CSE',
            'batch_name' => '2023 - 2027'
        ]);
        $this->assert(!$valEmailBad['isValid'] && isset($valEmailBad['errors']['email']), "Rejects malformed personal email");

        // 4. Invalid CGPA range
        $valCgpaBad = $this->studentService->validateStudentData([
            'registration_number' => 'TEST_REG_03',
            'student_name' => 'Jane Doe',
            'mobile_number' => '9876543210',
            'current_cgpa' => '11.50',
            'dept_code' => 'CSE',
            'batch_name' => '2023 - 2027'
        ]);
        $this->assert(!$valCgpaBad['isValid'] && isset($valCgpaBad['errors']['current_cgpa']), "Rejects CGPA greater than 10.00");

        // 5. Invalid Percentage range
        $valPctBad = $this->studentService->validateStudentData([
            'registration_number' => 'TEST_REG_04',
            'student_name' => 'Jane Doe',
            'mobile_number' => '9876543210',
            'tenth_percentage' => '105.00',
            'dept_code' => 'CSE',
            'batch_name' => '2023 - 2027'
        ]);
        $this->assert(!$valPctBad['isValid'] && isset($valPctBad['errors']['tenth_percentage']), "Rejects percentage greater than 100.00");

        // 6. Valid Data Pass
        $valValid = $this->studentService->validateStudentData([
            'registration_number' => 'TEST_VALID_01',
            'student_name' => 'Rajesh Sharma',
            'mobile_number' => '9876543210',
            'email' => 'rajesh.s@example.com',
            'dept_code' => 'CSE',
            'batch_name' => '2023 - 2027',
            'current_cgpa' => '8.75',
            'tenth_percentage' => '91.20',
            'twelfth_percentage' => '88.50',
            'standing_arrears' => 0
        ]);
        $this->assert($valValid['isValid'], "Accepts completely valid student payload");
        echo "\n";
    }

    private function testDuplicateDetection() {
        echo "Test Suite 2: Duplicate Detection\n";

        // Clean any existing test records first
        $this->db->exec("DELETE FROM students WHERE registration_number LIKE 'TEST_%'");

        $isDup1 = $this->studentService->checkDuplicate('TEST_NONEXISTENT_99');
        $this->assert(!$isDup1, "Reports non-existent registration number as available");

        // Insert a student directly to test duplicate detection
        $deptId = $this->db->query("SELECT dept_id FROM departments LIMIT 1")->fetchColumn();
        $batchId = $this->db->query("SELECT batch_id FROM batches LIMIT 1")->fetchColumn();

        $studentId = $this->studentService->createStudent([
            'registration_number' => 'TEST_DUP_CHECK_01',
            'student_name' => 'Pooja V',
            'mobile_number' => '9876543210',
            'dept_id' => $deptId,
            'batch_id' => $batchId
        ]);

        $this->assert($studentId > 0, "Initial student created successfully for duplicate tests");

        $isDup2 = $this->studentService->checkDuplicate('TEST_DUP_CHECK_01');
        $this->assert($isDup2, "Identifies registered student as a duplicate");

        // Case insensitivity
        $isDupCase = $this->studentService->checkDuplicate('test_dup_check_01');
        $this->assert($isDupCase, "Duplicate check is case-insensitive");

        // Exclude current student ID (for update forms)
        $isDupExcluded = $this->studentService->checkDuplicate('TEST_DUP_CHECK_01', $studentId);
        $this->assert(!$isDupExcluded, "Allows same student to keep their own registration number on update");

        echo "\n";
    }

    private function testManualStudentCreation() {
        echo "Test Suite 3: Manual Student Creation & Linked Tables\n";

        $deptId = $this->db->query("SELECT dept_id FROM departments LIMIT 1")->fetchColumn();
        $batchId = $this->db->query("SELECT batch_id FROM batches LIMIT 1")->fetchColumn();

        $studentPayload = [
            'registration_number' => 'TEST_MANUAL_01',
            'student_name' => 'Sanjay Vignesh',
            'mobile_number' => '9840123456',
            'alt_mobile_number' => '9840123457',
            'email' => 'sanjay.v@example.com',
            'college_email' => 'sanjay.21cse@mamcet.org',
            'dob' => '2003-04-12',
            'gender' => 'Male',
            'blood_group' => 'A+',
            'aadhar_number' => '123456789012',
            'dept_id' => $deptId,
            'batch_id' => $batchId,
            'tenth_percentage' => '92.40',
            'twelfth_percentage' => '89.60',
            'current_cgpa' => '8.65',
            'standing_arrears' => 0,
            'history_of_arrears' => 0,
            'sem1_gpa' => '8.50',
            'sem2_gpa' => '8.60',
            'sem3_gpa' => '8.80',
            'placement_willingness' => 'Yes',
            'placement_status' => 'Unplaced',
            'city' => 'Trichy',
            'district' => 'Tiruchirappalli'
        ];

        $studentId = $this->studentService->createStudent($studentPayload);
        $this->assert($studentId > 0, "createStudent returns positive integer student_id");

        // Verify students row
        $stmtS = $this->db->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmtS->execute([$studentId]);
        $sRow = $stmtS->fetch();
        $this->assert($sRow && $sRow['registration_number'] === 'TEST_MANUAL_01', "students row populated with correct registration_number");
        $this->assert($sRow['student_name'] === 'Sanjay Vignesh', "students row populated with correct student_name");
        $this->assert($sRow['mobile_number'] === '9840123456', "students row populated with correct mobile_number");

        // Verify student_profiles row
        $stmtP = $this->db->prepare("SELECT * FROM student_profiles WHERE student_id = ?");
        $stmtP->execute([$studentId]);
        $pRow = $stmtP->fetch();
        $this->assert((bool)$pRow, "student_profiles row automatically created for student");
        $this->assert($pRow['city'] === 'Trichy', "student_profiles row contains city");

        // Verify student_academics row
        $stmtA = $this->db->prepare("SELECT * FROM student_academics WHERE student_id = ?");
        $stmtA->execute([$studentId]);
        $aRow = $stmtA->fetch();
        $this->assert((bool)$aRow, "student_academics row automatically created for student");
        $this->assert((float)$aRow['current_cgpa'] === 8.65, "student_academics row contains current_cgpa = 8.65");
        $this->assert((float)$aRow['tenth_percentage'] === 92.40, "student_academics row contains tenth_percentage = 92.40");
        $this->assert((float)$aRow['sem1_gpa'] === 8.50, "student_academics row contains sem1_gpa = 8.50");

        echo "\n";
    }

    private function testCsvValidationAndPreview() {
        echo "Test Suite 4: CSV Parsing, Validation & Preview\n";

        $deptId = $this->db->query("SELECT dept_id FROM departments LIMIT 1")->fetchColumn();
        $batchId = $this->db->query("SELECT batch_id FROM batches LIMIT 1")->fetchColumn();

        $csvRows = [
            ['registration_number', 'student_name', 'mobile_number', 'email', 'current_cgpa', 'standing_arrears'],
            ['TEST_CSV_01', 'Kavitha M', '9876543210', 'kavitha@gmail.com', '8.40', '0'], // Valid new
            ['TEST_CSV_02', 'Manoj K', '9876543211', 'manoj@gmail.com', '7.80', '1'],    // Valid new
            ['TEST_CSV_01', 'Kavitha Duplicate', '9876543210', '', '8.40', '0'],          // Duplicate in file
            ['TEST_MANUAL_01', 'Existing Sanjay', '9840123456', '', '8.65', '0'],        // Matches DB
            ['TEST_CSV_BAD', '', '123', 'bad-email', '15.00', '-1']                       // Invalid fields
        ];

        $valResult = $this->studentService->validateCsvData($csvRows, [
            'dept_id' => $deptId,
            'batch_id' => $batchId
        ]);

        $this->assert($valResult['isValid'], "CSV parsing and validation returns isValid = true");
        $this->assert($valResult['summary']['total_rows'] === 5, "Identified total 5 data rows (excluding header)");
        $this->assert($valResult['summary']['valid_rows'] === 2, "Identified 2 new valid rows");
        $this->assert($valResult['summary']['duplicate_rows'] === 1, "Identified 1 duplicate in file");
        $this->assert($valResult['summary']['existing_rows'] === 1, "Identified 1 existing DB record");
        $this->assert($valResult['summary']['invalid_rows'] === 1, "Identified 1 invalid record");
        $this->assert(count($valResult['failed_rows']) === 2, "Collected failed rows (invalid + duplicate)");

        echo "\n";
    }

    private function testBatchImportExecution() {
        echo "Test Suite 5: Batch Import Execution & Duplicate Handling\n";

        $deptId = $this->db->query("SELECT dept_id FROM departments LIMIT 1")->fetchColumn();
        $batchId = $this->db->query("SELECT batch_id FROM batches LIMIT 1")->fetchColumn();

        $recordsToImport = [
            [
                'row_number' => 2,
                'is_update' => false,
                'data' => [
                    'registration_number' => 'TEST_BATCH_01',
                    'student_name' => 'Vigneshwaran R',
                    'mobile_number' => '9789012345',
                    'dept_id' => $deptId,
                    'batch_id' => $batchId,
                    'current_cgpa' => 8.10,
                    'tenth_percentage' => 85.00,
                    'twelfth_percentage' => 80.00,
                    'standing_arrears' => 0,
                    'history_of_arrears' => 0,
                    'placement_willingness' => 'Yes',
                    'placement_status' => 'Unplaced'
                ]
            ],
            [
                'row_number' => 3,
                'is_update' => false,
                'data' => [
                    'registration_number' => 'TEST_BATCH_02',
                    'student_name' => 'Meena K',
                    'mobile_number' => '9789012346',
                    'dept_id' => $deptId,
                    'batch_id' => $batchId,
                    'current_cgpa' => 9.05,
                    'tenth_percentage' => 94.00,
                    'twelfth_percentage' => 91.00,
                    'standing_arrears' => 0,
                    'history_of_arrears' => 0,
                    'placement_willingness' => 'Yes',
                    'placement_status' => 'Unplaced'
                ]
            ],
            [
                'row_number' => 4,
                'is_update' => true,
                'data' => [
                    'registration_number' => 'TEST_MANUAL_01', // Existing
                    'student_name' => 'Sanjay Vignesh Updated',
                    'mobile_number' => '9840123456',
                    'dept_id' => $deptId,
                    'batch_id' => $batchId,
                    'current_cgpa' => 8.95,
                    'standing_arrears' => 0,
                    'placement_willingness' => 'Yes',
                    'placement_status' => 'Unplaced'
                ]
            ]
        ];

        // 1. Batch Import with 'skip' duplicate strategy
        $statsSkip = $this->studentService->batchImport($recordsToImport, 'skip');
        $this->assert($statsSkip['imported'] === 2, "Imported 2 new records with 'skip' strategy");
        $this->assert($statsSkip['skipped'] === 1, "Skipped 1 duplicate record with 'skip' strategy");
        $this->assert($statsSkip['failed'] === 0, "0 failures during batch import");

        // Verify newly imported students in database
        $check1 = $this->studentService->getStudentByRegNo('TEST_BATCH_01');
        $this->assert($check1 && $check1['student_name'] === 'Vigneshwaran R', "TEST_BATCH_01 persisted properly in DB");

        // 2. Batch Import with 'overwrite' duplicate strategy
        $recordsOverwrite = [
            [
                'row_number' => 2,
                'is_update' => true,
                'data' => [
                    'registration_number' => 'TEST_MANUAL_01',
                    'student_name' => 'Sanjay Vignesh Updated Overwrite',
                    'mobile_number' => '9840123456',
                    'dept_id' => $deptId,
                    'batch_id' => $batchId,
                    'current_cgpa' => 9.20,
                    'standing_arrears' => 0,
                    'history_of_arrears' => 0,
                    'placement_willingness' => 'Yes',
                    'placement_status' => 'Placed'
                ]
            ]
        ];

        $statsOverwrite = $this->studentService->batchImport($recordsOverwrite, 'overwrite');
        $this->assert($statsOverwrite['updated'] === 1, "Updated 1 record with 'overwrite' strategy");

        // Verify updated record in DB
        $updatedStudent = $this->studentService->getStudentByRegNo('TEST_MANUAL_01');
        $this->assert($updatedStudent['student_name'] === 'Sanjay Vignesh Updated Overwrite', "Student name updated via overwrite");
        $this->assert($updatedStudent['placement_status'] === 'Placed', "Placement status updated via overwrite");

        echo "\n";
    }

    private function testCsvTemplateAndFailedRowsExport() {
        echo "Test Suite 6: Excel & CSV Templates and Failed Rows Export\n";

        // Sample Excel .xlsx template
        $xlsxBytes = StudentService::generateSampleExcelTemplate();
        $this->assert(!empty($xlsxBytes), "generateSampleExcelTemplate returns non-empty content");
        $this->assert(substr($xlsxBytes, 0, 2) === "PK", "Excel template is a valid zip/xlsx archive (PK header)");

        // Verify we can read the generated Excel template back
        $tempXlsx = tempnam(sys_get_temp_dir(), 'test_tpl_') . '.xlsx';
        file_put_contents($tempXlsx, $xlsxBytes);
        $parsedTpl = ExcelHelper::readSheet($tempXlsx, 'xlsx');
        @unlink($tempXlsx);

        $this->assert(count($parsedTpl) >= 4, "Parsed Excel template has headers and sample rows");
        $this->assert($parsedTpl[0][0] === 'registration_number', "Parsed Excel header 0 is 'registration_number'");
        $this->assert($parsedTpl[1][0] === '812421104001', "Parsed Excel sample row has intact 12-digit registration_number");
        $this->assert($parsedTpl[2][0] === '812421104002', "Parsed Excel second row has intact registration_number");

        // Sample CSV template fallback
        $template = StudentService::generateSampleCsvTemplate();
        $this->assert(!empty($template), "generateSampleCsvTemplate returns non-empty content");
        $this->assert(strpos($template, 'registration_number') !== false, "CSV template contains 'registration_number' header");

        // Failed rows Excel export
        $failedRowsSample = [
            [
                'row_number' => 5,
                'registration_number' => '812421104099',
                'student_name' => 'Test Broken Row',
                'error_message' => 'Invalid email format | Mobile number is too short'
            ]
        ];

        $failedXlsx = StudentService::generateFailedRowsXlsx($failedRowsSample);
        $this->assert(!empty($failedXlsx), "generateFailedRowsXlsx returns non-empty XLSX");
        $this->assert(substr($failedXlsx, 0, 2) === "PK", "Failed XLSX is a valid zip archive");

        $failedCsv = StudentService::generateFailedRowsCsv($failedRowsSample);
        $this->assert(!empty($failedCsv), "generateFailedRowsCsv returns non-empty CSV");
        $this->assert(strpos($failedCsv, 'Error Reasons') !== false, "Failed CSV contains Error Reasons column");

        echo "\n";
    }

    private function testAuthorizationGuards() {
        echo "Test Suite 7: Authorization Guards & Role Access\n";

        // Super Admin (Role 1) and Placement Officer (Role 2) are permitted
        $allowedRoles = [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER];
        $this->assert(in_array(ROLE_SUPER_ADMIN, $allowedRoles), "Super Admin has permission");
        $this->assert(in_array(ROLE_PLACEMENT_OFFICER, $allowedRoles), "Placement Officer has permission");
        $this->assert(!in_array(ROLE_STUDENT, $allowedRoles), "Student Role (Role 3) is denied access");
        $this->assert(!in_array(0, $allowedRoles), "Guest / Unauthenticated is denied access");

        echo "\n";
    }

    private function cleanupTestData() {
        // Remove test students and cascade deletes to profiles/academics
        $this->db->exec("DELETE FROM students WHERE registration_number LIKE 'TEST_%'");
    }
}

// Execute tests
$runner = new TestRunner();
$runner->run();
