<?php
// MAMCET Placement & Learning Portal - Training Management & Attendance API

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/TrainingService.php');

function sendJsonResponse(array $data, int $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Handle JSON input payload for fetch/beacons
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];
$requestData = array_merge($_REQUEST, $jsonInput);

$action = $requestData['action'] ?? '';
$isTrainerTokenAction = in_array($action, [
    'get_trainer_attendance',
    'save_trainer_attendance_draft',
    'mark_trainer_attendance',
    'bulk_mark_trainer_attendance',
    'submit_trainer_attendance'
], true);

if (!isLoggedIn() && !$isTrainerTokenAction) {
    sendJsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
}

$userRoleId = (int)($_SESSION['role_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = in_array($userRoleId, [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER], true);
$isStudent = ($userRoleId === ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$trainingService = new TrainingService($db);

try {
    // =========================================================================
    // ADMIN ACTIONS
    // =========================================================================
    if ($action === 'create_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $deptIds = $requestData['departments'] ?? [];
        if (!is_array($deptIds)) {
            $deptIds = array_filter(explode(',', (string)$deptIds));
        }

        $res = $trainingService->createTraining($requestData, $deptIds, $userId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'update_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        if ($trainingId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid training ID.'], 400);
        }

        $deptIds = $requestData['departments'] ?? [];
        if (!is_array($deptIds)) {
            $deptIds = array_filter(explode(',', (string)$deptIds));
        }

        $res = $trainingService->updateTraining($trainingId, $requestData, $deptIds, $userId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'duplicate_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        if ($trainingId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid training ID.'], 400);
        }

        $res = $trainingService->duplicateTraining($trainingId, $userId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'archive_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        $res = $trainingService->archiveTraining($trainingId, $userId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'delete_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        $res = $trainingService->deleteTraining($trainingId, $userId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'get_training') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        $training = $trainingService->getTrainingById($trainingId);
        if (!$training) {
            sendJsonResponse(['success' => false, 'message' => 'Training not found.'], 404);
        }
        sendJsonResponse(['success' => true, 'training' => $training]);
    }

    if ($action === 'get_sessions') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }

        $attendanceId = (int)($requestData['attendance_id'] ?? 0);
        if ($attendanceId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid attendance ID.'], 400);
        }

        $sessions = $trainingService->getAttendanceSessions($attendanceId);
        sendJsonResponse(['success' => true, 'sessions' => $sessions]);
    }

    if ($action === 'adjust_attendance') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $attendanceId = (int)($requestData['attendance_id'] ?? 0);
        $trainingId = (int)($requestData['training_id'] ?? 0);
        $studentId = (int)($requestData['student_id'] ?? 0);
        $newStatus = trim($requestData['status'] ?? '');
        $reason = trim($requestData['reason'] ?? '');

        $res = $trainingService->manualAdjustAttendance($attendanceId, $newStatus, $reason, $userId, $trainingId, $studentId);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'quick_mark_attendance') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        $studentId = (int)($requestData['student_id'] ?? 0);
        $newStatus = trim($requestData['status'] ?? '');
        $reason = trim($requestData['reason'] ?? '');

        if ($trainingId <= 0 || $studentId <= 0 || $newStatus === '') {
            sendJsonResponse(['success' => false, 'message' => 'Invalid parameters provided.'], 400);
        }

        $res = $trainingService->quickMarkAttendance($trainingId, $studentId, $newStatus, $userId, $reason ?: null);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'bulk_mark_attendance') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }
        if (!validateCsrfToken($requestData['csrf_token'] ?? null)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        $studentIds = $requestData['student_ids'] ?? [];
        if (!is_array($studentIds)) {
            $studentIds = array_filter(explode(',', (string)$studentIds));
        }
        $newStatus = trim($requestData['status'] ?? '');
        $reason = trim($requestData['reason'] ?? '');

        if ($trainingId <= 0 || empty($studentIds) || $newStatus === '') {
            sendJsonResponse(['success' => false, 'message' => 'Training ID, student list, and status are required.'], 400);
        }

        $res = $trainingService->bulkMarkAttendance($trainingId, $studentIds, $newStatus, $userId, $reason ?: null);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'export_excel' || $action === 'export_xlsx' || $action === 'export_attendance') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        if ($trainingId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid training ID.'], 400);
        }

        $filters = [
            'status' => $requestData['status'] ?? '',
            'dept_id' => $requestData['dept_id'] ?? '',
            'search' => $requestData['search'] ?? ''
        ];

        $adminUser = $_SESSION['username'] ?? 'admin';
        $xlsxBytes = $trainingService->exportAttendanceExcel($trainingId, $filters, $adminUser);

        if (ob_get_length()) {
            ob_clean();
        }

        $filename = 'attendance_report_training_' . $trainingId . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xlsxBytes));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $xlsxBytes;
        exit;
    }

    if ($action === 'export_xml') {
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }

        $trainingId = (int)($requestData['training_id'] ?? 0);
        if ($trainingId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid training ID.'], 400);
        }

        $filters = [
            'status' => $requestData['status'] ?? '',
            'dept_id' => $requestData['dept_id'] ?? '',
            'search' => $requestData['search'] ?? ''
        ];

        $adminUser = $_SESSION['username'] ?? 'admin';
        $xmlContent = $trainingService->exportAttendanceXml($trainingId, $filters, $adminUser);

        if (ob_get_length()) {
            ob_clean();
        }

        $filename = 'attendance_report_training_' . $trainingId . '_' . date('Ymd_His') . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xmlContent));
        echo $xmlContent;
        exit;
    }

    // =====================================================================
    // TRAINER TOKEN ACTIONS
    // =====================================================================
    if ($action === 'get_trainer_attendance') {
        $token = trim((string)($requestData['trainer_token'] ?? $requestData['token'] ?? ''));
        if ($token === '') {
            sendJsonResponse(['success' => false, 'message' => 'Trainer attendance token is required.'], 400);
        }

        $filters = [
            'status' => trim((string)($requestData['status'] ?? '')),
            'dept_id' => (int)($requestData['dept_id'] ?? 0),
            'search' => trim((string)($requestData['search'] ?? ''))
        ];
        $data = $trainingService->getTrainerAttendanceDashboard($token, $filters);
        if (!$data) {
            sendJsonResponse(['success' => false, 'message' => 'This trainer attendance link is invalid or no longer active.'], 404);
        }

        // Never return the bearer token in the roster response.
        unset($data['training']['trainer_access_token']);
        sendJsonResponse(['success' => true, 'data' => $data]);
    }

    if (in_array($action, ['save_trainer_attendance_draft', 'mark_trainer_attendance'], true)) {
        $token = trim((string)($requestData['trainer_token'] ?? $requestData['token'] ?? ''));
        $isValidTrainerBearer = ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token));

        if (!validateCsrfToken($requestData['csrf_token'] ?? null) && !$isValidTrainerBearer) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $studentId = (int)($requestData['student_id'] ?? 0);
        $status = trim((string)($requestData['status'] ?? ''));
        $note = trim((string)($requestData['note'] ?? ''));
        if ($token === '' || $studentId <= 0 || $status === '') {
            sendJsonResponse(['success' => false, 'message' => 'Trainer token, student, and attendance status are required.'], 400);
        }

        $res = $trainingService->saveTrainerAttendanceDraft($token, $studentId, $status, $note);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'bulk_mark_trainer_attendance') {
        $token = trim((string)($requestData['trainer_token'] ?? $requestData['token'] ?? ''));
        $isValidTrainerBearer = ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token));

        if (!validateCsrfToken($requestData['csrf_token'] ?? null) && !$isValidTrainerBearer) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        $studentIds = $requestData['student_ids'] ?? [];
        if (!is_array($studentIds)) {
            $studentIds = array_filter(explode(',', (string)$studentIds));
        }
        $status = trim((string)($requestData['status'] ?? ''));
        $note = trim((string)($requestData['note'] ?? ''));

        if ($token === '' || empty($studentIds) || $status === '') {
            sendJsonResponse(['success' => false, 'message' => 'Trainer token, student IDs, and status are required.'], 400);
        }

        $res = $trainingService->bulkMarkTrainerAttendance($token, $studentIds, $status, $note);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    if ($action === 'submit_trainer_attendance') {
        $token = trim((string)($requestData['trainer_token'] ?? $requestData['token'] ?? ''));
        $isValidTrainerBearer = ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token));

        if (!validateCsrfToken($requestData['csrf_token'] ?? null) && !$isValidTrainerBearer) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 403);
        }

        if ($token === '') {
            sendJsonResponse(['success' => false, 'message' => 'Trainer attendance token is required.'], 400);
        }

        $res = $trainingService->submitTrainerAttendance($token);
        sendJsonResponse($res, $res['success'] ? 200 : 422);
    }

    // =========================================================================
    // STUDENT TRAINING ACTIONS
    // =========================================================================
    if ($action === 'start_session') {
        if (!$isStudent) {
            sendJsonResponse(['success' => false, 'message' => 'Student role required.'], 403);
        }
        sendJsonResponse(['success' => false, 'message' => 'Attendance is marked by the trainer and cannot be created from student engagement.'], 410);
    }

    if ($action === 'heartbeat') {
        if (!$isStudent) {
            sendJsonResponse(['success' => false, 'message' => 'Student role required.'], 403);
        }
        sendJsonResponse(['success' => false, 'message' => 'Student engagement heartbeats are no longer used for attendance.'], 410);
    }

    if ($action === 'end_session') {
        if (!$isStudent) {
            sendJsonResponse(['success' => false, 'message' => 'Student role required.'], 403);
        }
        sendJsonResponse(['success' => false, 'message' => 'Student engagement sessions are no longer used for attendance.'], 410);
    }

    sendJsonResponse(['success' => false, 'message' => "Unknown action '$action'."], 400);

} catch (\Throwable $e) {
    error_log("Training API Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Server error encountered: ' . $e->getMessage()
    ], 500);
}
