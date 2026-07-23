<?php
// MAMCET Placement & Learning Portal - Validate Dataset Excel API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/excel-helper.php');

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

$fileId = (int)($_POST['file_id'] ?? 0);
$sessionId = (int)($_POST['session_id'] ?? 0);
$batchId = (int)($_POST['batch_id'] ?? 0);
$programmeId = (int)($_POST['programme_id'] ?? 0);
$deptId = (int)($_POST['dept_id'] ?? 0);
$sectionId = (int)($_POST['section_id'] ?? 0);
$mappings = $_POST['mappings'] ?? []; // Array mapping db_field => excel_column_index

if ($fileId <= 0 || $sessionId <= 0 || $batchId <= 0 || $deptId <= 0 || empty($mappings)) {
    echo json_encode(['success' => false, 'message' => 'Missing required validation parameters.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Fetch file path
    $stmt = $db->prepare("SELECT filename, filepath FROM dataset_files WHERE file_id = ?");
    $stmt->execute([$fileId]);
    $fileRow = $stmt->fetch();
    
    if (!$fileRow) {
        echo json_encode(['success' => false, 'message' => 'Scanned file record not found.']);
        exit;
    }
    
    $filePath = $fileRow['filepath'];
    $extension = pathinfo($fileRow['filename'], PATHINFO_EXTENSION);
    
    // Load Excel sheet
    $allRows = ExcelHelper::readSheet($filePath, $extension);
    
    if (count($allRows) <= 1) {
        echo json_encode(['success' => false, 'message' => 'The Excel sheet is empty or contains headers only.']);
        exit;
    }
    
    // Assume first row is header
    $headers = $allRows[0];
    $dataRows = array_slice($allRows, 1);
    
    $totalRows = count($dataRows);
    $validCount = 0;
    $invalidCount = 0;
    $duplicateCount = 0;
    $existingCount = 0;
    
    // Create new Import process record
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        INSERT INTO excel_imports (file_id, imported_by, session_id, batch_id, programme_id, dept_id, section_id, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'validating')
    ");
    $stmt->execute([
        $fileId, 
        $_SESSION['user_id'], 
        $sessionId, 
        $batchId, 
        $programmeId ?: null, 
        $deptId, 
        $sectionId ?: null
    ]);
    $importId = $db->lastInsertId();
    
    // Keep track of registration numbers parsed in this sheet to detect duplicates inside the file
    $seenInFile = [];
    $rowsData = [];
    
    foreach ($dataRows as $index => $row) {
        $rowNumber = $index + 2; // 1-based index + header offset
        $rowData = [];
        $rowErrors = [];
        
        // Extract fields using mapped columns
        foreach ($mappings as $dbField => $colIdx) {
            $colIdx = (int)$colIdx;
            $rowData[$dbField] = isset($row[$colIdx]) ? trim($row[$colIdx]) : '';
        }
        
        $regNo = $rowData['registration_number'] ?? '';
        $name = $rowData['student_name'] ?? '';
        $mobile = $rowData['mobile_number'] ?? '';
        $email = $rowData['email'] ?? '';
        $cgpa = $rowData['current_cgpa'] ?? '';
        
        $isRowValid = true;
        $importStatus = 'success';
        
        // 1. Validation: Registration Number
        if (empty($regNo)) {
            $isRowValid = false;
            $rowErrors[] = "Registration Number is missing.";
            $importStatus = 'failed';
        } else {
            // Check duplicates in file
            if (isset($seenInFile[$regNo])) {
                $isRowValid = false;
                $rowErrors[] = "Duplicate Registration Number in Excel file (Row {$seenInFile[$regNo]} and Row {$rowNumber}).";
                $importStatus = 'failed';
                $duplicateCount++;
            } else {
                $seenInFile[$regNo] = $rowNumber;
                
                // Check duplicates in database
                $stmtCheck = $db->prepare("SELECT student_id, student_name FROM students WHERE registration_number = ?");
                $stmtCheck->execute([$regNo]);
                $dbStudent = $stmtCheck->fetch();
                if ($dbStudent) {
                    $existingCount++;
                    $importStatus = 'skipped'; // Mark for skip or update check
                }
            }
        }
        
        // 2. Validation: Student Name
        if (empty($name)) {
            $isRowValid = false;
            $rowErrors[] = "Student Name is missing.";
            $importStatus = 'failed';
        }
        
        // 3. Validation: Mobile Number
        if (empty($mobile)) {
            $isRowValid = false;
            $rowErrors[] = "Mobile Number is missing.";
            $importStatus = 'failed';
        } elseif (!preg_match('/^[0-9]{8,15}$/', preg_replace('/[^0-9]/', '', $mobile))) {
            // Let's make it a warning or invalid based on strictness. Let's make it invalid if less than 8 digits.
            $isRowValid = false;
            $rowErrors[] = "Mobile Number format is invalid: '$mobile'";
            $importStatus = 'failed';
        }
        
        // 4. Validation: Email format
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Allow warnings instead of blocking imports, but let's record it
            $rowErrors[] = "Personal email has invalid format: '$email'";
        }
        
        // 5. Validation: CGPA
        if (!empty($cgpa)) {
            if (!is_numeric($cgpa) || (float)$cgpa < 0 || (float)$cgpa > 10) {
                $isRowValid = false;
                $rowErrors[] = "CGPA must be a numeric decimal between 0 and 10. Passed: '$cgpa'";
                $importStatus = 'failed';
            }
        }
        
        // Add verification flags
        if ($importStatus === 'failed') {
            $invalidCount++;
        } elseif ($importStatus === 'skipped') {
            // Valid data structure but exists in DB
            $validCount++;
        } else {
            $validCount++;
        }
        
        // Insert Row metadata cache
        $stmtInsRow = $db->prepare("
            INSERT INTO excel_import_rows (import_id, `row_number`, registration_number, data_json, is_valid, validation_errors, import_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsRow->execute([
            $importId,
            $rowNumber,
            $regNo ?: null,
            json_encode($rowData),
            $isRowValid ? 1 : 0,
            json_encode($rowErrors),
            $importStatus
        ]);
        
        // Save Errors into table
        foreach ($rowErrors as $err) {
            $stmtErr = $db->prepare("INSERT INTO import_errors (import_id, `row_number`, column_name, error_message) VALUES (?, ?, ?, ?)");
            $stmtErr->execute([$importId, $rowNumber, '', $err]);
        }
        
        // Append row stats for response previewing
        $rowsData[] = [
            'row_number' => $rowNumber,
            'data' => $rowData,
            'errors' => $rowErrors,
            'is_valid' => $isRowValid,
            'status' => $importStatus
        ];
    }
    
    // Update main File metadata
    $stmtUpdFile = $db->prepare("
        UPDATE dataset_files 
        SET total_rows = ?, valid_rows = ?, invalid_rows = ?, duplicate_rows = ?, import_status = 'pending' 
        WHERE file_id = ?
    ");
    $stmtUpdFile->execute([$totalRows, $validCount, $invalidCount, $duplicateCount, $fileId]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'import_id' => $importId,
        'summary' => [
            'total_rows' => $totalRows,
            'valid_rows' => $validCount - $existingCount, // new valid
            'invalid_rows' => $invalidCount,
            'duplicate_rows' => $duplicateCount,
            'existing_rows' => $existingCount
        ],
        'preview_rows' => array_slice($rowsData, 0, 50) // Return first 50 rows for grid preview
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Validation error: ' . $e->getMessage()]);
}
