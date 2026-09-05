<?php
// MAMCET Placement & Learning Portal - Execute Excel Import API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed.']);
    exit;
}

$importId = (int)($_POST['import_id'] ?? 0);
$onDuplicate = $_POST['on_duplicate'] ?? 'skip'; // skip, overwrite
$overwriteStudentFields = isset($_POST['overwrite_student_fields']) && $_POST['overwrite_student_fields'] == '1';

if ($importId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid import session ID.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Fetch import settings
    $stmt = $db->prepare("SELECT * FROM excel_imports WHERE import_id = ?");
    $stmt->execute([$importId]);
    $import = $stmt->fetch();
    
    if (!$import) {
        echo json_encode(['success' => false, 'message' => 'Import batch record not found.']);
        exit;
    }
    
    if ($import['status'] === 'completed') {
        echo json_encode(['success' => false, 'message' => 'This import batch has already been executed.']);
        exit;
    }
    
    // Fetch all cached rows for validation check
    $stmtRows = $db->prepare("SELECT * FROM excel_import_rows WHERE import_id = ? AND is_valid = 1");
    $stmtRows->execute([$importId]);
    $importRows = $stmtRows->fetchAll();
    
    $importedCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    
    $db->beginTransaction();
    
    foreach ($importRows as $row) {
        $data = json_decode($row['data_json'], true);
        $regNo = $row['registration_number'];
        
        if (empty($regNo) || empty($data)) continue;
        
        // Check if student exists in database
        $stmtExist = $db->prepare("SELECT student_id, user_id, mobile_number, alt_mobile_number, email FROM students WHERE registration_number = ?");
        $stmtExist->execute([$regNo]);
        $existingStudent = $stmtExist->fetch();
        
        $name = $data['student_name'] ?? '';
        $mobile = $data['mobile_number'] ?? '';
        $altMobile = $data['alt_mobile_number'] ?? '';
        $email = $data['email'] ?? '';
        $collegeEmail = $data['college_email'] ?? '';
        $dob = !empty($data['dob']) ? date('Y-m-d', strtotime($data['dob'])) : null;
        $gender = $data['gender'] ?? '';
        $bloodGroup = $data['blood_group'] ?? '';
        $aadhar = $data['aadhar_number'] ?? '';
        $parentMobile = $data['alt_mobile_number'] ?? ''; // or parent mobile
        $hosteller = $data['hosteller_status'] ?? '';
        $licence = $data['licence_status'] ?? '';
        $passport = $data['passport_status'] ?? '';
        
        // Grades
        $tenth = !empty($data['tenth_percentage']) ? (float)$data['tenth_percentage'] : null;
        $twelfth = !empty($data['twelfth_percentage']) ? (float)$data['twelfth_percentage'] : null;
        $diploma = !empty($data['diploma_percentage']) ? (float)$data['diploma_percentage'] : null;
        $cgpa = !empty($data['current_cgpa']) ? (float)$data['current_cgpa'] : null;
        $standingArrears = isset($data['standing_arrears']) ? (int)$data['standing_arrears'] : 0;
        $historyArrears = isset($data['history_of_arrears']) ? (int)$data['history_of_arrears'] : 0;
        
        $sem1 = !empty($data['sem1_gpa']) ? (float)$data['sem1_gpa'] : null;
        $sem2 = !empty($data['sem2_gpa']) ? (float)$data['sem2_gpa'] : null;
        $sem3 = !empty($data['sem3_gpa']) ? (float)$data['sem3_gpa'] : null;
        $sem4 = !empty($data['sem4_gpa']) ? (float)$data['sem4_gpa'] : null;
        $sem5 = !empty($data['sem5_gpa']) ? (float)$data['sem5_gpa'] : null;
        $sem6 = !empty($data['sem6_gpa']) ? (float)$data['sem6_gpa'] : null;
        $sem7 = !empty($data['sem7_gpa']) ? (float)$data['sem7_gpa'] : null;
        $sem8 = !empty($data['sem8_gpa']) ? (float)$data['sem8_gpa'] : null;
        
        // Fetch active batch details for admission/graduation years
        $stmtBatch = $db->prepare("SELECT admission_year, graduation_year, current_year_of_study FROM batches WHERE batch_id = ?");
        $stmtBatch->execute([$import['batch_id']]);
        $batchDetails = $stmtBatch->fetch();
        
        $admissionYear = $batchDetails['admission_year'] ?? date('Y') - 3;
        $graduationYear = $batchDetails['graduation_year'] ?? date('Y') + 1;
        $studyYear = $batchDetails['current_year_of_study'] ?? 'Final Year';
        
        if ($existingStudent) {
            // DUPLICATE RECORD FOUND
            if ($onDuplicate === 'skip') {
                $skippedCount++;
                // Update row status
                $stmtUpdRow = $db->prepare("UPDATE excel_import_rows SET import_status = 'skipped' WHERE row_id = ?");
                $stmtUpdRow->execute([$row['row_id']]);
                continue;
            }
            
            // Overwrite logic
            $studentId = $existingStudent['student_id'];
            $hasActivatedUser = $existingStudent['user_id'] !== null;
            
            // Determine fields to update based on overwrite policy
            $updateFields = [
                'student_name = ?',
                'dept_id = ?',
                'batch_id = ?',
                'section_id = ?',
                'admission_year = ?',
                'graduation_year = ?',
                'year_of_study = ?'
            ];
            $params = [
                $name,
                $import['dept_id'],
                $import['batch_id'],
                $import['section_id'] ?: null,
                $admissionYear,
                $graduationYear,
                $studyYear
            ];
            
            // Check student-updated locks
            if (!$hasActivatedUser || $overwriteStudentFields) {
                // Safe to update email, mobile, and secondary personal details
                $updateFields[] = 'mobile_number = ?';
                $updateFields[] = 'alt_mobile_number = ?';
                $updateFields[] = 'email = ?';
                $updateFields[] = 'college_email = ?';
                $updateFields[] = 'dob = ?';
                $updateFields[] = 'gender = ?';
                $updateFields[] = 'blood_group = ?';
                $updateFields[] = 'aadhar_number = ?';
                $updateFields[] = 'hosteller_status = ?';
                $updateFields[] = 'licence_status = ?';
                $updateFields[] = 'passport_status = ?';
                
                array_push($params, $mobile, $altMobile, $email, $collegeEmail, $dob, $gender, $bloodGroup, $aadhar, $hosteller, $licence, $passport);
            }
            
            // Execute student update
            $params[] = $studentId;
            $stmtUpdStudent = $db->prepare("UPDATE students SET " . implode(', ', $updateFields) . " WHERE student_id = ?");
            $stmtUpdStudent->execute($params);
            
            // Update Academics
            $stmtUpdAcademics = $db->prepare("
                UPDATE student_academics 
                SET tenth_percentage = ?, twelfth_percentage = ?, diploma_percentage = ?, current_cgpa = ?, 
                    standing_arrears = ?, history_of_arrears = ?, 
                    sem1_gpa = ?, sem2_gpa = ?, sem3_gpa = ?, sem4_gpa = ?, sem5_gpa = ?, sem6_gpa = ?, sem7_gpa = ?, sem8_gpa = ?, gpa_up_to_6 = ? 
                WHERE student_id = ?
            ");
            $stmtUpdAcademics->execute([
                $tenth, $twelfth, $diploma, $cgpa,
                $standingArrears, $historyArrears,
                $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8, $cgpa,
                $studentId
            ]);
            
            $updatedCount++;
            
            $stmtUpdRow = $db->prepare("UPDATE excel_import_rows SET import_status = 'updated' WHERE row_id = ?");
            $stmtUpdRow->execute([$row['row_id']]);
            
        } else {
            // NEW RECORD - INSERT
            $stmtInsStudent = $db->prepare("
                INSERT INTO students (
                    registration_number, student_name, mobile_number, alt_mobile_number, email, college_email, 
                    dob, gender, blood_group, aadhar_number, parent_mobile, hosteller_status, licence_status, passport_status, 
                    dept_id, batch_id, section_id, admission_year, graduation_year, year_of_study, placement_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unplaced')
            ");
            $stmtInsStudent->execute([
                $regNo, $name, $mobile, $altMobile, $email, $collegeEmail,
                $dob, $gender, $bloodGroup, $aadhar, $parentMobile, $hosteller, $licence, $passport,
                $import['dept_id'], $import['batch_id'], $import['section_id'] ?: null, $admissionYear, $graduationYear, $studyYear
            ]);
            $studentId = $db->lastInsertId();
            
            // Create Profile
            $db->prepare("INSERT INTO student_profiles (student_id) VALUES (?)")->execute([$studentId]);
            
            // Create Academics
            $stmtInsAcademics = $db->prepare("
                INSERT INTO student_academics (
                    student_id, tenth_percentage, twelfth_percentage, diploma_percentage, current_cgpa, 
                    standing_arrears, history_of_arrears, 
                    sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa, sem7_gpa, sem8_gpa, gpa_up_to_6
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsAcademics->execute([
                $studentId, $tenth, $twelfth, $diploma, $cgpa,
                $standingArrears, $historyArrears,
                $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8, $cgpa
            ]);
            
            $importedCount++;
            
            $stmtUpdRow = $db->prepare("UPDATE excel_import_rows SET import_status = 'success' WHERE row_id = ?");
            $stmtUpdRow->execute([$row['row_id']]);
        }
    }
    
    // Update Import batch record to completed
    $stmtUpdImport = $db->prepare("UPDATE excel_imports SET total_imported = ?, status = 'completed' WHERE import_id = ?");
    $stmtUpdImport->execute([$importedCount + $updatedCount, $importId]);
    
    // Update main File status to imported
    if ($import['file_id']) {
        $stmtUpdFile = $db->prepare("UPDATE dataset_files SET import_status = 'imported', last_imported_at = NOW() WHERE file_id = ?");
        $stmtUpdFile->execute([$import['file_id']]);
    }
    
    $db->commit();
    
    // Ensure batch mapping is synchronized for current session
    syncBatchAcademicSessions($db);
    
    // Log Activity
    logActivity($db, $_SESSION['user_id'], 'Execute Import', "Completed import batch ID $importId. Imported: $importedCount, Updated: $updatedCount, Skipped: $skippedCount");
    
    echo json_encode([
        'success' => true,
        'message' => 'Import executed successfully.',
        'stats' => [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
