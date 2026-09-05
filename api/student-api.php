<?php
// MAMCET Placement & Learning Portal - Student Management API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/StudentService.php');

// Restrict to Super Admin and Placement Officer
function sendJsonResponse(array $data, int $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized access. Administrator privileges required.'], 403);
}

$action = $_REQUEST['action'] ?? '';

try {
    $db = Database::getInstance()->getConnection();
    $studentService = new StudentService($db);

    if ($action === 'check_duplicate') {
        $regNo = trim($_REQUEST['registration_number'] ?? '');
        $excludeId = (int)($_REQUEST['exclude_id'] ?? 0);

        if (empty($regNo)) {
            sendJsonResponse(['success' => true, 'exists' => false, 'message' => '']);
        }

        $exists = $studentService->checkDuplicate($regNo, $excludeId);
        sendJsonResponse([
            'success' => true,
            'exists' => $exists,
            'message' => $exists ? "Registration number '$regNo' is already taken." : "Registration number is available."
        ]);
    }

    if ($action === 'get_sections') {
        $deptId = (int)($_REQUEST['dept_id'] ?? 0);
        if ($deptId <= 0) {
            sendJsonResponse(['success' => true, 'sections' => []]);
        }

        $stmt = $db->prepare("SELECT section_id, section_name FROM sections WHERE dept_id = ? ORDER BY section_name");
        $stmt->execute([$deptId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendJsonResponse(['success' => true, 'sections' => $sections]);
    }

    if ($action === 'create_student') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        if (!validateCsrfToken()) {
            sendJsonResponse(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page and try again.'], 400);
        }

        $validation = $studentService->validateStudentData($_POST);
        if (!$validation['isValid']) {
            sendJsonResponse([
                'success' => false,
                'message' => 'Please resolve the highlighted validation errors.',
                'errors' => $validation['errors']
            ]);
        }

        $studentId = $studentService->createStudent($_POST, (int)$_SESSION['user_id']);

        sendJsonResponse([
            'success' => true,
            'student_id' => $studentId,
            'registration_number' => $validation['sanitized']['registration_number'],
            'student_name' => $validation['sanitized']['student_name'],
            'message' => "Student '{$validation['sanitized']['student_name']}' ({$validation['sanitized']['registration_number']}) created successfully."
        ]);
    }

    if ($action === 'delete_student') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        if (!validateCsrfToken()) {
            sendJsonResponse(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page and try again.'], 400);
        }

        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid student ID.']);
        }

        $result = $studentService->deleteStudent($studentId, (int)$_SESSION['user_id']);
        sendJsonResponse($result);
    }

    if ($action === 'bulk_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        if (!validateCsrfToken()) {
            sendJsonResponse(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page and try again.'], 400);
        }

        $studentIds = $_POST['student_ids'] ?? [];
        if (empty($studentIds) || !is_array($studentIds)) {
            sendJsonResponse(['success' => false, 'message' => 'No students selected for deletion.']);
        }

        $result = $studentService->deleteMultipleStudents($studentIds, (int)$_SESSION['user_id']);
        sendJsonResponse($result);
    }

    sendJsonResponse(['success' => false, 'message' => "Unknown action '$action'."]);
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
