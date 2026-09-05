<?php
// MAMCET Placement & Learning Portal - Remove Student Resume API Endpoint

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

verifyCsrfRequest();

if (!isLoggedIn() || (int)$_SESSION['role_id'] !== ROLE_STUDENT) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

$studentId = (int)$student['student_id'];
$userId = (int)$_SESSION['user_id'];

try {
    // Fetch current resume path
    $stmt = $db->prepare("SELECT resume_path FROM student_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();

    $resumePath = $profile['resume_path'] ?? '';

    if (!empty($resumePath)) {
        // Delete physical file if exists
        $fileName = basename($resumePath);
        $filePath1 = __DIR__ . '/../' . $resumePath;
        $filePath2 = __DIR__ . '/../assets/uploads/resumes/' . $fileName;

        if (file_exists($filePath1) && is_file($filePath1)) {
            @unlink($filePath1);
        }
        if (file_exists($filePath2) && is_file($filePath2)) {
            @unlink($filePath2);
        }
    }

    $db->beginTransaction();

    // Clear resume_path in student_profiles
    $stmtClearProfile = $db->prepare("UPDATE student_profiles SET resume_path = NULL WHERE student_id = ?");
    $stmtClearProfile->execute([$studentId]);

    // Mark resume_files as non-current
    $stmtClearFiles = $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?");
    $stmtClearFiles->execute([$studentId]);

    $db->commit();

    logActivity($db, $userId, 'Remove Resume', 'Removed student profile CV/Resume document.');

    echo json_encode([
        'success' => true,
        'message' => 'CV / Resume has been successfully removed.'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to remove CV: ' . $e->getMessage()]);
}
