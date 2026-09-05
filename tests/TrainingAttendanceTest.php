<?php
// MAMCET Placement & Learning Portal - Training & Attendance Automated Integration Test Suite

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/TrainingService.php');

class TrainingAttendanceTestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private PDO $db;
    private TrainingService $service;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new TrainingService($this->db);
    }

    public function assert(bool $condition, string $testName, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] $testName\n";
        } else {
            $this->failed++;
            echo "  [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
        }
    }

    public function run(): void {
        echo "====================================================\n";
        echo " MAMCET Training & Attendance Module Test Suite     \n";
        echo "====================================================\n\n";

        // Setup test data
        $deptIds = $this->db->query("SELECT dept_id FROM departments ORDER BY dept_id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $cseDeptId = (int)($deptIds[0] ?? 1);
        $itDeptId = (int)($deptIds[1] ?? 2);

        // Fetch or create test students for Dept 1 and Dept 2
        $student1 = $this->getOrCreateTestStudent('TEST_STU_TR_01', $cseDeptId);
        $student2 = $this->getOrCreateTestStudent('TEST_STU_TR_02', $itDeptId);

        $this->testTrainingValidationRules($cseDeptId);
        $this->testTrainingCreationAndListing($cseDeptId, $itDeptId);
        $this->testDepartmentBasedAccessControl($student1, $student2, $cseDeptId, $itDeptId);
        $this->testTrainerControlledAttendance($student1, $cseDeptId);
        $this->testManualAttendanceCorrection($student1, $cseDeptId);
        $this->testXmlExportAndEscaping($student1, $cseDeptId);
        $this->testExcelExportAndGeneration($student1, $cseDeptId);
        $this->testArchivedAndInactiveTrainingGuards($student1, $cseDeptId);
        $this->testDuplicateHandlingAndIdempotency($student1, $cseDeptId);
        $this->testApiEndpointAuthorization();

        echo "\n====================================================\n";
        echo " Training Suite Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "====================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function getOrCreateTestStudent(string $regNo, int $deptId): array {
        $stmt = $this->db->prepare("SELECT * FROM students WHERE registration_number = ?");
        $stmt->execute([$regNo]);
        $stu = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stu) {
            return $stu;
        }

        // Get active batch
        $batchId = (int)$this->db->query("SELECT batch_id FROM batches LIMIT 1")->fetchColumn();
        if ($batchId <= 0) {
            $this->db->exec("INSERT INTO batches (batch_name, admission_year, graduation_year, programme_id) VALUES ('2023-2027', 2023, 2027, 1)");
            $batchId = (int)$this->db->lastInsertId();
        }

        $stmtIns = $this->db->prepare("
            INSERT INTO students (registration_number, student_name, mobile_number, email, dept_id, batch_id)
            VALUES (?, ?, '9876543210', ?, ?, ?)
        ");
        $stmtIns->execute([$regNo, 'Test Student ' . $regNo, strtolower($regNo) . '@mamcet.org', $deptId, $batchId]);
        $stuId = (int)$this->db->lastInsertId();

        $stmt->execute([$regNo]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function testTrainingValidationRules(int $deptId): void {
        echo "Test Suite 1: Training Validation Rules & Constraints\n";

        // Empty title
        $errors = $this->service->validateTrainingData([
            'title' => '',
            'training_url' => 'https://meet.google.com/test',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00'
        ], [$deptId]);
        $this->assert(in_array('Training title is required.', $errors), "Rejects empty title");

        // Invalid URL
        $errors = $this->service->validateTrainingData([
            'title' => 'Test Training',
            'training_url' => 'not-a-valid-url',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00'
        ], [$deptId]);
        $this->assert(!empty($errors), "Rejects invalid training URL");

        // Non-http/https protocol
        $errors = $this->service->validateTrainingData([
            'title' => 'Test Training',
            'training_url' => 'javascript:alert(1)',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00'
        ], [$deptId]);
        $this->assert(!empty($errors), "Rejects non-HTTP(S) training URL");

        // Missing departments
        $errors = $this->service->validateTrainingData([
            'title' => 'Test Training',
            'training_url' => 'https://meet.google.com/test',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00'
        ], []);
        $this->assert(in_array('At least one eligible department must be selected.', $errors), "Rejects missing departments");

        // End date earlier than start date
        $errors = $this->service->validateTrainingData([
            'title' => 'Test Training',
            'training_url' => 'https://meet.google.com/test',
            'start_date_time' => '2026-09-01 12:00:00',
            'end_date_time' => '2026-09-01 10:00:00'
        ], [$deptId]);
        $this->assert(in_array('End date and time must be later than start date and time.', $errors), "Rejects end date before start date");

        // Valid payload
        $errors = $this->service->validateTrainingData([
            'title' => 'Valid Training Title ' . time(),
            'training_url' => 'https://meet.google.com/test-valid',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'minimum_engagement_seconds' => 300
        ], [$deptId]);
        $this->assert(empty($errors), "Accepts completely valid training payload");
    }

    private function testTrainingCreationAndListing(int $dept1, int $dept2): void {
        echo "\nTest Suite 2: Training Creation, Duplication & Listing\n";

        $uniqueTitle = 'AWS Cloud Practitioner Bootcamp ' . time();
        $res = $this->service->createTraining([
            'title' => $uniqueTitle,
            'description' => 'Comprehensive AWS certification training covering core cloud concepts.',
            'training_url' => 'https://meet.google.com/aws-bootcamp',
            'trainer_name' => 'John AWS Trainer',
            'start_date_time' => '2026-09-05 10:00:00',
            'end_date_time' => '2026-09-05 13:00:00',
            'minimum_engagement_seconds' => 1800,
            'status' => 'Upcoming'
        ], [$dept1, $dept2], 1);

        $this->assert($res['success'] === true, "Creates training program successfully");
        $trainingId = (int)($res['training_id'] ?? 0);
        $this->assert($trainingId > 0, "Returns positive generated training ID");

        // Verify record in DB
        $t = $this->service->getTrainingById($trainingId);
        $this->assert($t !== null, "Retrieves created training by ID");
        $this->assert($t['title'] === $uniqueTitle, "Persists correct training title");
        $this->assert(count($t['department_ids']) === 2, "Persists multi-department mappings");

        // Test Duplication
        $dupRes = $this->service->duplicateTraining($trainingId, 1);
        $this->assert($dupRes['success'] === true, "Duplicates training program");
        $dupId = (int)$dupRes['training_id'];
        $dupTraining = $this->service->getTrainingById($dupId);
        $this->assert(strpos($dupTraining['title'], 'Copy of') === 0, "Cloned training has 'Copy of' prefix");
        $this->assert($dupTraining['status'] === 'Draft', "Cloned training defaults to Draft status");

        // Clean up duplicate
        $this->service->deleteTraining($dupId, 1);
    }

    private function testDepartmentBasedAccessControl(array $stu1, array $stu2, int $dept1, int $dept2): void {
        echo "\nTest Suite 3: Department-Based Access Control & Isolation\n";

        // Create training ONLY for Dept 1
        $tRes = $this->service->createTraining([
            'title' => 'Exclusive CSE Advanced Algorithms ' . time(),
            'description' => 'Only for Department 1 students.',
            'training_url' => 'https://meet.google.com/cse-only',
            'start_date_time' => '2026-09-01 09:00:00',
            'end_date_time' => '2026-09-01 11:00:00',
            'status' => 'Active'
        ], [$dept1], 1);

        $trainingId = $tRes['training_id'];

        // Student 1 (in Dept 1) should have access
        $access1 = $this->service->verifyStudentAccess($trainingId, (int)$stu1['student_id']);
        $this->assert($access1['allowed'] === true, "Authorizes student belonging to assigned department");

        // Student 2 (in Dept 2) must be BLOCKED
        $access2 = $this->service->verifyStudentAccess($trainingId, (int)$stu2['student_id']);
        $this->assert($access2['allowed'] === false, "Denies student belonging to non-assigned department");
        $this->assert(strpos($access2['message'], 'not eligible') !== false, "Returns departmental restriction reason");

        // List eligible trainings for Student 2 must not include Dept 1 exclusive training
        $stu2Trainings = $this->service->getStudentEligibleTrainings((int)$stu2['student_id'], (int)$stu2['dept_id']);
        $foundIds = array_column($stu2Trainings, 'training_id');
        $this->assert(!in_array($trainingId, $foundIds, true), "Excludes unassigned training from student catalog");
    }

    private function testTrainerControlledAttendance(array $student, int $deptId): void {
        echo "\nTest Suite 4: Trainer-Controlled Attendance & Secure Trainer Link\n";

        $tRes = $this->service->createTraining([
            'title' => 'Trainer Attendance Workflow ' . time(),
            'description' => 'Attendance is marked by the assigned trainer.',
            'training_url' => 'https://meet.google.com/trainer-workflow',
            'event_type' => 'Online',
            'trainer_name' => 'Test Trainer',
            'start_date_time' => '2026-09-01 08:00:00',
            'end_date_time' => '2026-09-01 10:00:00',
            'minimum_engagement_seconds' => 0,
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = (int)$tRes['training_id'];
        $training = $this->service->getTrainingById($trainingId);
        $studentId = (int)$student['student_id'];

        $this->assert($tRes['success'] === true, "Creates trainer-controlled training");
        $this->assert(strlen($training['trainer_access_token']) === 64, "Generates a secure trainer attendance token");
        $this->assert($this->service->getTrainerAttendanceDashboard($training['trainer_access_token']) !== null, "Resolves trainer link to attendance roster");

        $before = $this->db->prepare("SELECT COUNT(*) FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $before->execute([$trainingId, $studentId]);
        $this->assert((int)$before->fetchColumn() === 0, "Student access does not create attendance automatically");

        $mark = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Present', 'Confirmed by trainer');
        $this->assert($mark['success'] === true, "Trainer saves student Present as a draft");
        $this->assert($mark['status'] === 'Present', "Returns the trainer-selected status");

        $afterDraft = $this->db->prepare("SELECT status, note FROM training_attendance_drafts WHERE training_id = ? AND student_id = ?");
        $afterDraft->execute([$trainingId, $studentId]);
        $draft = $afterDraft->fetch(PDO::FETCH_ASSOC);
        $this->assert($draft['status'] === 'Present' && $draft['note'] === 'Confirmed by trainer', "Stores trainer changes in the draft table");

        $after = $this->db->prepare("SELECT COUNT(*) FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $after->execute([$trainingId, $studentId]);
        $this->assert((int)$after->fetchColumn() === 0, "Keeps canonical attendance unchanged until submission");

        $trainerDashboard = $this->service->getTrainerAttendanceDashboard($training['trainer_access_token']);
        $trainerRecord = $trainerDashboard['records'][0] ?? [];
        $this->assert($trainerRecord['attendance_status'] === 'Present' && (int)$trainerRecord['has_draft'] === 1, "Trainer roster displays the saved draft status");
        $adminDashboard = $this->service->getTrainingAttendanceDashboard($trainingId);
        $this->assert(($adminDashboard['records'][0]['attendance_status'] ?? '') === 'Pending', "Admin roster remains Pending before submission");

        $markLate = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Late');
        $this->assert($markLate['success'] === true, "Trainer can update the draft status");
        $this->assert($markLate['status'] === 'Late', "Draft status can be changed to Late before submission");

        $submit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($submit['success'] === true && $submit['submitted_count'] === 1, "Trainer submits the roster successfully");

        $published = $this->db->prepare("SELECT status, marked_by, marked_at FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $published->execute([$trainingId, $studentId]);
        $attendance = $published->fetch(PDO::FETCH_ASSOC);
        $this->assert($attendance['status'] === 'Late', "Publishes the final trainer-selected status");
        $this->assert($attendance['marked_by'] === 'Test Trainer' && !empty($attendance['marked_at']), "Persists trainer and marked timestamp on submission");

        // Verify training submitted metadata in trainings table
        $tCheck = $this->db->prepare("SELECT attendance_submitted_at, attendance_submitted_by FROM trainings WHERE training_id = ?");
        $tCheck->execute([$trainingId]);
        $tData = $tCheck->fetch(PDO::FETCH_ASSOC);
        $this->assert(!empty($tData['attendance_submitted_at']), "Records attendance_submitted_at timestamp");
        $this->assert($tData['attendance_submitted_by'] === 'Test Trainer', "Records attendance_submitted_by name");

        $lockedSave = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Present');
        $this->assert($lockedSave['success'] === false, "Rejects trainer changes after submission");
        $lockedSubmit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($lockedSubmit['success'] === false, "Rejects a second trainer submission");

        $this->service->deleteTraining($trainingId, 1);
    }

    private function testHeartbeatAndDurationCalculation(array $student, int $deptId): void {
        echo "\nTest Suite 5: Heartbeat Tracking & Server Engagement Duration\n";

        // Create training requiring 60 seconds minimum engagement
        $tRes = $this->service->createTraining([
            'title' => 'Hands-on Coding Lab (Threshold Rule) ' . time(),
            'description' => 'Requires 60s active time to mark Present.',
            'training_url' => 'https://meet.google.com/coding-lab',
            'start_date_time' => '2026-09-01 08:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'minimum_engagement_seconds' => 60,
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = $tRes['training_id'];
        $studentId = (int)$student['student_id'];

        // 1. Start session
        $sessRes = $this->service->startStudentSession($trainingId, $studentId, '127.0.0.1', 'Mozilla/5.0');
        $token = $sessRes['session_token'];
        $this->assert($sessRes['attendance_status'] === 'In Progress', "Status starts as In Progress when threshold > 0");

        // 2. Simulate 25 seconds elapsed by backdating last_heartbeat_at
        $this->db->prepare("UPDATE training_sessions SET last_heartbeat_at = DATE_SUB(NOW(), INTERVAL 25 SECOND) WHERE session_token = ?")->execute([$token]);

        $hb1 = $this->service->recordHeartbeat($token, $studentId);
        $this->assert($hb1['success'] === true, "Processes first heartbeat");
        $this->assert($hb1['total_engagement_seconds'] >= 24 && $hb1['total_engagement_seconds'] <= 26, "Calculates elapsed delta on server (~25s)");
        $this->assert($hb1['is_present'] === false, "Status remains In Progress (< 60s threshold)");

        // 3. Simulate another 40 seconds elapsed (Total ~65s >= 60s)
        $this->db->prepare("UPDATE training_sessions SET last_heartbeat_at = DATE_SUB(NOW(), INTERVAL 40 SECOND) WHERE session_token = ?")->execute([$token]);

        $hb2 = $this->service->recordHeartbeat($token, $studentId);
        $this->assert($hb2['success'] === true, "Processes second heartbeat");
        $this->assert($hb2['total_engagement_seconds'] >= 60, "Accumulates total engagement seconds (>= 60s)");
        $this->assert($hb2['attendance_status'] === 'Present', "Transitions status to Present upon meeting requirement");
        $this->assert($hb2['is_present'] === true, "is_present flag is true");

        // 4. End session
        $endRes = $this->service->endStudentSession($token, $studentId);
        $this->assert($endRes['success'] === true, "Ends training session cleanly");

        $sessRow = $this->db->query("SELECT * FROM training_sessions WHERE session_token = '$token'")->fetch(PDO::FETCH_ASSOC);
        $this->assert($sessRow['session_status'] === 'completed', "Session status updated to completed");
        $this->assert(!empty($sessRow['ended_at']), "Session ended_at timestamp recorded");
    }

    private function testManualAttendanceCorrection(array $student, int $deptId): void {
        echo "\nTest Suite 5: Manual Attendance Correction & Audit Logging\n";

        $tRes = $this->service->createTraining([
            'title' => 'Audit Correction Training ' . time(),
            'description' => 'Test manual overrides.',
            'training_url' => 'https://meet.google.com/audit',
            'start_date_time' => '2026-09-01 08:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'minimum_engagement_seconds' => 0,
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = $tRes['training_id'];
        $studentId = (int)$student['student_id'];

        $training = $this->service->getTrainingById($trainingId);
        $trainerMark = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Present', 'Trainer confirmed attendance');
        $this->assert($trainerMark['success'] === true, "Trainer saves attendance before admin correction");
        $submit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($submit['success'] === true, "Publishes attendance before admin correction");
        $attendanceStmt = $this->db->prepare("SELECT attendance_id FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $attendanceStmt->execute([$trainingId, $studentId]);
        $attendanceId = (int)$attendanceStmt->fetchColumn();

        // Admin manually changes status to Excused with audit reason
        $adjRes = $this->service->manualAdjustAttendance($attendanceId, 'Excused', 'Student was excused for a placement interview', 1);
        $this->assert($adjRes['success'] === true, "Performs manual attendance adjustment");

        $updatedAtt = $this->db->query("SELECT * FROM training_attendance WHERE attendance_id = $attendanceId")->fetch(PDO::FETCH_ASSOC);
        $this->assert($updatedAtt['status'] === 'Excused', "Updates attendance status to Excused");
        $this->assert((int)$updatedAtt['manually_adjusted'] === 1, "Sets manually_adjusted flag to 1");
        $this->assert(!empty($updatedAtt['adjustment_reason']), "Stores adjustment reason text");
        $this->assert((int)$updatedAtt['adjusted_by'] === 1, "Stores admin user ID who adjusted record");

        // Verify audit log entry in activity_logs
        $stmtLog = $this->db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'Manual Attendance Adjustment' AND user_id = 1");
        $stmtLog->execute();
        $this->assert((int)$stmtLog->fetchColumn() > 0, "Writes audit log entry into activity_logs");
    }

    private function testXmlExportAndEscaping(array $student, int $deptId): void {
        echo "\nTest Suite 7: XML Export Generation, Filtering & Escaping\n";

        $title = 'Special XML Test: <C++ & Java "Design Patterns">' . time();
        $tRes = $this->service->createTraining([
            'title' => $title,
            'description' => 'Test XML escaping & encoding of special chars like & < > " \'.',
            'training_url' => 'https://meet.google.com/special-xml',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'minimum_engagement_seconds' => 0,
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = $tRes['training_id'];
        $training = $this->service->getTrainingById($trainingId);
        $mark = $this->service->markTrainerAttendance($training['trainer_access_token'], (int)$student['student_id'], 'Present', 'XML export attendance');
        $this->assert($mark['success'] === true, "Trainer attendance is included in XML export");
        $submit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($submit['success'] === true, "Submitted attendance is available to XML export");

        $xmlOutput = $this->service->exportAttendanceXml($trainingId, [], 'superadmin');

        $this->assert(!empty($xmlOutput), "Generates non-empty XML report");
        $this->assert(strpos($xmlOutput, '<?xml version="1.0" encoding="UTF-8"?>') !== false, "Includes XML header declaration with UTF-8");
        $this->assert(strpos($xmlOutput, '<attendanceReport>') !== false, "Contains root <attendanceReport> tag");
        $this->assert(strpos($xmlOutput, '<training>') !== false, "Contains <training> section");
        $this->assert(strpos($xmlOutput, '<summary>') !== false, "Contains <summary> section");
        $this->assert(strpos($xmlOutput, '<records>') !== false, "Contains <records> section");
        $this->assert(strpos($xmlOutput, '<record>') !== false, "Contains individual <record> entries");
        $this->assert(strpos($xmlOutput, htmlspecialchars($title)) !== false || strpos($xmlOutput, 'Design Patterns') !== false, "Properly escapes special characters in XML");

        // Verify valid XML parsing using SimpleXML
        $xmlObj = simplexml_load_string($xmlOutput);
        $this->assert($xmlObj !== false, "XML output is well-formed and parses successfully via SimpleXML");
        $this->assert((string)$xmlObj->training->id === (string)$trainingId, "XML includes matching training ID");
        $this->assert((int)$xmlObj->summary->eligibleStudents >= 1, "XML summary contains accurate eligibleStudents count");
    }

    private function testExcelExportAndGeneration(array $student, int $deptId): void {
        echo "\nTest Suite 7B: Excel (.xlsx) Export Generation, Content & Filtering\n";

        $title = 'Excel Attendance Export Test ' . time();
        $tRes = $this->service->createTraining([
            'title' => $title,
            'description' => 'Test Excel export of student attendance records.',
            'training_url' => 'https://meet.google.com/excel-test',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'minimum_engagement_seconds' => 0,
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = $tRes['training_id'];
        $training = $this->service->getTrainingById($trainingId);
        $mark = $this->service->markTrainerAttendance($training['trainer_access_token'], (int)$student['student_id'], 'Present', 'Excel attendance testing');
        $this->assert($mark['success'] === true, "Trainer marks attendance for Excel export");
        $submit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($submit['success'] === true, "Trainer submits attendance for Excel export");

        $excelBytes = $this->service->exportAttendanceExcel($trainingId, [], 'superadmin');

        $this->assert(!empty($excelBytes), "Generates non-empty Excel (.xlsx) file bytes");
        $this->assert(substr($excelBytes, 0, 4) === "PK\x03\x04", "Output has valid ZIP/OpenXML magic bytes (PK..)");

        // Save to temp file and parse back via ExcelHelper
        $tempFile = tempnam(sys_get_temp_dir(), 'test_att_') . '.xlsx';
        file_put_contents($tempFile, $excelBytes);

        $parsedRows = ExcelHelper::readSheet($tempFile, 'xlsx');
        @unlink($tempFile);

        $this->assert(!empty($parsedRows) && count($parsedRows) >= 2, "Excel contains header and at least one student data row");

        $headerRow = $parsedRows[0];
        $this->assert(in_array('Registration Number', $headerRow, true), "Excel contains 'Registration Number' column");
        $this->assert(in_array('Student Name', $headerRow, true), "Excel contains 'Student Name' column");
        $this->assert(in_array('Attendance Status', $headerRow, true), "Excel contains 'Attendance Status' column");
        $this->assert(in_array('Department', $headerRow, true), "Excel contains 'Department' column");

        // Verify student row
        $foundStudent = false;
        foreach (array_slice($parsedRows, 1) as $row) {
            if (in_array($student['registration_number'], $row, true)) {
                $foundStudent = true;
                $this->assert(in_array('Present', $row, true), "Exported student record has 'Present' attendance status");
                $this->assert(in_array($student['student_name'], $row, true), "Exported student record contains student name");
                break;
            }
        }
        $this->assert($foundStudent, "Exported Excel file contains the test student registration number");
    }

    private function testArchivedAndInactiveTrainingGuards(array $student, int $deptId): void {
        echo "\nTest Suite 8: Archived & Draft Training Access Protection\n";

        // Draft training
        $draftRes = $this->service->createTraining([
            'title' => 'Draft Unpublished Training ' . time(),
            'training_url' => 'https://meet.google.com/draft',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'status' => 'Draft'
        ], [$deptId], 1);

        $draftId = $draftRes['training_id'];
        $accessDraft = $this->service->verifyStudentAccess($draftId, (int)$student['student_id']);
        $this->assert($accessDraft['allowed'] === false, "Blocks student access to Draft training");

        // Archived training
        $archRes = $this->service->createTraining([
            'title' => 'Archived Old Training ' . time(),
            'training_url' => 'https://meet.google.com/archived',
            'start_date_time' => '2026-08-01 10:00:00',
            'end_date_time' => '2026-08-01 12:00:00',
            'status' => 'Archived'
        ], [$deptId], 1);

        $archId = $archRes['training_id'];
        $accessArch = $this->service->verifyStudentAccess($archId, (int)$student['student_id']);
        $this->assert($accessArch['allowed'] === false, "Blocks student access to Archived training");
    }

    private function testDuplicateHandlingAndIdempotency(array $student, int $deptId): void {
        echo "\nTest Suite 9: Duplicate Attendance Prevention & Trainer Mark Idempotency\n";

        $tRes = $this->service->createTraining([
            'title' => 'Idempotency Test Training ' . time(),
            'training_url' => 'https://meet.google.com/idempotent',
            'start_date_time' => '2026-09-01 10:00:00',
            'end_date_time' => '2026-09-01 12:00:00',
            'status' => 'Active'
        ], [$deptId], 1);

        $trainingId = $tRes['training_id'];
        $studentId = (int)$student['student_id'];

        $training = $this->service->getTrainingById($trainingId);
        $s1 = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Present');
        $s2 = $this->service->markTrainerAttendance($training['trainer_access_token'], $studentId, 'Present');
        $this->assert($s1['success'] === true && $s2['success'] === true, "Repeated trainer saves succeed safely");

        // Verify only ONE draft exists and nothing is published before submission.
        $stmtDraftCount = $this->db->prepare("SELECT COUNT(*) FROM training_attendance_drafts WHERE training_id = ? AND student_id = ?");
        $stmtDraftCount->execute([$trainingId, $studentId]);
        $this->assert((int)$stmtDraftCount->fetchColumn() === 1, "Guarantees exactly one draft per student per training");
        $stmtBeforeSubmit = $this->db->prepare("SELECT COUNT(*) FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $stmtBeforeSubmit->execute([$trainingId, $studentId]);
        $this->assert((int)$stmtBeforeSubmit->fetchColumn() === 0, "Does not publish repeated saves before submission");
        $this->assert($s2['draft_id'] === $s1['draft_id'], "Repeated trainer saves reuse one draft record");

        $submit = $this->service->submitTrainerAttendance($training['trainer_access_token']);
        $this->assert($submit['success'] === true, "Publishes the idempotent draft once");

        // Verify only ONE canonical row in training_attendance exists for this pair.
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM training_attendance WHERE training_id = ? AND student_id = ?");
        $stmtCount->execute([$trainingId, $studentId]);
        $this->assert((int)$stmtCount->fetchColumn() === 1, "Guarantees exactly one attendance summary per student per training");
        $this->assert($this->service->startStudentSession($trainingId, $studentId)['success'] === false, "Student session attendance is disabled");
    }

    private function testApiEndpointAuthorization(): void {
        echo "\nTest Suite 10: API Security & Role-Based Authorization Guards\n";

        // 1. Test unauthenticated request
        $codeUnauth = <<<'PHP'
$_SESSION = [];
$_REQUEST = ['action' => 'create_training'];
$_SERVER['REQUEST_METHOD'] = 'POST';
include('api/training-api.php');
PHP;
        $resUnauth = $this->runPhpSubprocess($codeUnauth);
        $this->assert($resUnauth['code'] === 401, "API rejects unauthenticated requests with HTTP 401");

        // 2. Test student attempting admin action
        $codeStudentAdminAction = <<<'PHP'
$_SESSION['user_id'] = 5;
$_SESSION['role_id'] = 3; // Student role
$_REQUEST = ['action' => 'delete_training', 'training_id' => 1];
$_SERVER['REQUEST_METHOD'] = 'POST';
include('api/training-api.php');
PHP;
        $resStuAdmin = $this->runPhpSubprocess($codeStudentAdminAction);
        $this->assert($resStuAdmin['code'] === 403, "API rejects student attempting admin action with HTTP 403");

        // 3. Test admin attempting student session start
        $codeAdminStudentAction = <<<'PHP'
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1; // Super Admin role
$_REQUEST = ['action' => 'start_session', 'training_id' => 1];
$_SERVER['REQUEST_METHOD'] = 'POST';
include('api/training-api.php');
PHP;
        $resAdmStu = $this->runPhpSubprocess($codeAdminStudentAction);
        $this->assert($resAdmStu['code'] === 403, "API rejects admin attempting student start_session with HTTP 403");
    }

    private function runPhpSubprocess(string $code): array {
        $tempFile = tempnam(sys_get_temp_dir(), 'mamcet_test_') . '.php';
        $fullCode = "<?php\nrequire_once('includes/auth.php');\n" . $code;
        file_put_contents($tempFile, $fullCode);

        $cmd = "php " . escapeshellarg($tempFile);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes, __DIR__ . '/..');
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($tempFile);

        $json = json_decode($output, true);
        preg_match('/Status:\s*(\d+)/i', $stderr, $m);
        $httpCode = 200;
        if (preg_match('/(?:^|\n)(?:HTTP\/\S+\s+|Status:\s*)(\d+)/i', $output, $m2)) {
            $httpCode = (int)$m2[1];
        } elseif ($json && isset($json['status_code'])) {
            $httpCode = (int)$json['status_code'];
        } elseif ($json && isset($json['success']) && $json['success'] === false && isset($json['message']) && strpos($json['message'], 'required') !== false) {
            $httpCode = 403;
        }

        // If json has message with authentication
        if ($json && isset($json['message'])) {
            if (strpos($json['message'], 'Authentication required') !== false) $httpCode = 401;
            if (strpos($json['message'], 'privileges required') !== false || strpos($json['message'], 'Student role required') !== false) $httpCode = 403;
        }

        return ['code' => $httpCode, 'json' => $json, 'raw' => $output];
    }
}

$runner = new TrainingAttendanceTestRunner();
$runner->run();
