<?php
// MAMCET Placement & Learning Portal - Certificate Actions API Endpoint
// Handles deleting certificates, updating status (admin), and fetching lists

header('Content-Type: application/json');
require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/StudentService.php');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$roleId = (int)($_SESSION['role_id'] ?? 0);
$isAdmin = ($roleId === ROLE_SUPER_ADMIN || $roleId === ROLE_PLACEMENT_OFFICER);
$isStudent = ($roleId === ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);
$studentService = new StudentService($db);

$action = $_POST['action'] ?? $_GET['action'] ?? $_REQUEST['action'] ?? '';

switch ($action) {
    case 'delete':
        verifyCsrfRequest();

        $certId = (int)($_POST['cert_id'] ?? 0);
        if ($certId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid certificate ID.']);
            exit;
        }

        $studentId = null;
        if ($isStudent) {
            $student = getActiveStudent($db);
            if (!$student) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Student record not found.']);
                exit;
            }
            $studentId = (int)$student['student_id'];
        } elseif (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }

        // Fetch certificate details before deletion to determine student_id for count update
        $stmtOwner = $db->prepare("SELECT student_id, title FROM student_certifications WHERE cert_id = ?");
        $stmtOwner->execute([$certId]);
        $certRow = $stmtOwner->fetch(PDO::FETCH_ASSOC);

        if (!$certRow) {
            echo json_encode(['success' => false, 'message' => 'Certificate not found.']);
            exit;
        }

        $ownerStudentId = (int)$certRow['student_id'];
        $result = $studentService->deleteCertificate($certId, $studentId, $isAdmin);

        if ($result['success']) {
            $remainingCount = $studentService->getStudentCertificateCount($ownerStudentId);
            $result['total_count'] = $remainingCount;
            if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
                logActivity($db, $_SESSION['user_id'], 'Delete Certificate', "Deleted certificate ID $certId ('{$certRow['title']}')");
            }
        }

        echo json_encode($result);
        break;

    case 'update_status':
        verifyCsrfRequest();

        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin privileges required to update certificate status.']);
            exit;
        }

        $certId = (int)($_POST['cert_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $allowedStatuses = ['Pending Verification', 'Verified', 'Rejected'];

        if ($certId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid certificate ID or status value.']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE student_certifications SET status = ? WHERE cert_id = ?");
            $stmt->execute([$newStatus, $certId]);

            if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
                logActivity($db, $_SESSION['user_id'], 'Update Certificate Status', "Updated certificate ID $certId status to $newStatus");
            }

            echo json_encode([
                'success' => true,
                'message' => "Certificate status updated to $newStatus.",
                'status' => $newStatus
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'list':
        $targetStudentId = 0;
        if ($isStudent) {
            $student = getActiveStudent($db);
            $targetStudentId = (int)($student['student_id'] ?? 0);
        } elseif ($isAdmin) {
            $targetStudentId = (int)($_GET['student_id'] ?? 0);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }

        if ($targetStudentId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Target student ID is required.']);
            exit;
        }

        $certs = $studentService->getStudentCertificates($targetStudentId);
        echo json_encode([
            'success' => true,
            'certificates' => $certs,
            'total_count' => count($certs)
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}
