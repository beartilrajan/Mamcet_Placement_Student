<?php
// MAMCET Placement & Learning Portal - Excel / Spreadsheet Student Import API

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/excel-helper.php');
require_once(__DIR__ . '/../services/StudentService.php');

$action = $_REQUEST['action'] ?? '';

// Download Sample Template (Excel .xlsx by default)
if ($action === 'download_template') {
    if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
        http_response_code(403);
        die("403 Forbidden: Unauthorized access.");
    }

    $format = strtolower($_GET['format'] ?? 'xlsx');

    if ($format === 'csv') {
        $csv = StudentService::generateSampleCsvTemplate();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mamcet_student_import_template.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo $csv;
        exit;
    }

    // Default: Microsoft Excel (.xlsx)
    $xlsxBytes = StudentService::generateSampleExcelTemplate();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="mamcet_student_import_template.xlsx"');
    header('Content-Length: ' . strlen($xlsxBytes));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xlsxBytes;
    exit;
}

// Download Failed Rows (Excel .xlsx by default)
if ($action === 'download_failed') {
    if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
        http_response_code(403);
        die("403 Forbidden: Unauthorized access.");
    }

    $token = $_GET['import_token'] ?? '';
    $importSession = $_SESSION['csv_import_' . $token] ?? null;

    if (!$importSession || empty($importSession['failed_rows'])) {
        http_response_code(404);
        die("No failed records found for this import session.");
    }

    $failedRows = $importSession['failed_rows'];
    $headers = $importSession['headers'] ?? [];
    $format = strtolower($_GET['format'] ?? 'xlsx');

    if ($format === 'csv') {
        $csv = StudentService::generateFailedRowsCsv($failedRows, $headers);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="failed_student_records_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo "\xEF\xBB\xBF";
        echo $csv;
        exit;
    }

    // Default: Excel .xlsx
    $xlsxBytes = StudentService::generateFailedRowsXlsx($failedRows, $headers);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="failed_student_records_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . strlen($xlsxBytes));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xlsxBytes;
    exit;
}

// All subsequent actions return JSON
header('Content-Type: application/json');

// Restrict to Super Admin and Placement Officer
if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Administrator privileges required.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $studentService = new StudentService($db);

    if ($action === 'upload_and_preview') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
            exit;
        }

        if (!validateCsrfToken()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please reload the page.']);
            exit;
        }

        $fileKey = isset($_FILES['excel_file']) ? 'excel_file' : (isset($_FILES['csv_file']) ? 'csv_file' : '');
        if (empty($fileKey) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            $errorCode = !empty($fileKey) ? ($_FILES[$fileKey]['error'] ?? 'missing') : 'missing';
            echo json_encode(['success' => false, 'message' => 'Please select a valid Excel (.xlsx / .xls) or CSV file to upload. (Error: ' . $errorCode . ')']);
            exit;
        }

        $file = $_FILES[$fileKey];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls', 'csv', 'txt', 'xml'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file format. Please upload an Excel spreadsheet (.xlsx / .xls), CSV, or XML file.']);
            exit;
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File size exceeds maximum allowable limit of 20MB.']);
            exit;
        }

        // Read sheet rows using ExcelHelper
        try {
            $rows = ExcelHelper::readSheet($file['tmp_name'], $ext);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error reading spreadsheet: ' . $e->getMessage()]);
            exit;
        }

        if (empty($rows)) {
            echo json_encode(['success' => false, 'message' => 'The uploaded spreadsheet is empty or has no recognizable data rows.']);
            exit;
        }

        $defaultConfig = [
            'dept_id' => (int)($_POST['default_dept_id'] ?? 0),
            'batch_id' => (int)($_POST['default_batch_id'] ?? 0),
            'section_id' => (int)($_POST['default_section_id'] ?? 0),
            'filename' => $file['name']
        ];

        $valResult = $studentService->validateCsvData($rows, $defaultConfig);

        if (!$valResult['isValid'] && $valResult['summary']['total_rows'] === 0) {
            echo json_encode([
                'success' => false,
                'message' => $valResult['message'] ?? 'No valid student records could be parsed from the file.'
            ]);
            exit;
        }

        // Store validated import in session
        $importToken = bin2hex(random_bytes(16));
        $_SESSION['csv_import_' . $importToken] = [
            'valid_records' => $valResult['valid_records'],
            'failed_rows' => $valResult['failed_rows'],
            'headers' => $valResult['headers'],
            'summary' => $valResult['summary'],
            'filename' => $file['name'],
            'created_at' => time()
        ];

        echo json_encode([
            'success' => true,
            'import_token' => $importToken,
            'summary' => $valResult['summary'],
            'preview_rows' => $valResult['preview_rows'],
            'headers' => $valResult['headers'],
            'filename' => $file['name']
        ]);
        exit;
    }

    if ($action === 'execute_import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
            exit;
        }

        if (!validateCsrfToken()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please reload the page.']);
            exit;
        }

        $importToken = $_POST['import_token'] ?? '';
        $strategy = in_array($_POST['on_duplicate'] ?? '', ['skip', 'overwrite']) ? $_POST['on_duplicate'] : 'skip';

        $sessionKey = 'csv_import_' . $importToken;
        if (empty($importToken) || !isset($_SESSION[$sessionKey])) {
            echo json_encode([
                'success' => false,
                'message' => 'Import session expired or invalid. Please re-upload your Excel spreadsheet.'
            ]);
            exit;
        }

        $importSession = $_SESSION[$sessionKey];
        $records = $importSession['valid_records'] ?? [];

        if (empty($records)) {
            echo json_encode([
                'success' => false,
                'message' => 'No valid records to import from this file.'
            ]);
            exit;
        }

        $currentUserId = (int)$_SESSION['user_id'];
        $stats = $studentService->batchImport($records, $strategy, $currentUserId);

        // Keep session for downloading failed rows if needed
        $stats['has_failed_rows'] = !empty($importSession['failed_rows']);

        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'filename' => $importSession['filename'] ?? 'uploaded_students.xlsx',
            'import_token' => $importToken
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during import processing: ' . $e->getMessage()
    ]);
}
