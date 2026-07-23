<?php
// MAMCET Placement & Learning Portal - Mark Lesson Completed API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn() || (int)$_SESSION['role_id'] !== ROLE_STUDENT) {
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

$courseId = (int)($_POST['course_id'] ?? 0);
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$studentId = $_SESSION['student_id'] ?? 0;

if ($courseId <= 0 || $lessonId <= 0 || $studentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Verify lesson belongs to this course
    $stmtVerify = $db->prepare("
        SELECT cl.lesson_id FROM course_lessons cl 
        JOIN course_modules cm ON cl.module_id = cm.module_id 
        WHERE cl.lesson_id = ? AND cm.course_id = ?
    ");
    $stmtVerify->execute([$lessonId, $courseId]);
    if (!$stmtVerify->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Lesson does not belong to the selected course.']);
        exit;
    }
    
    $db->beginTransaction();
    
    // 1. Mark lesson as complete in student_lesson_progress
    $stmtIns = $db->prepare("
        INSERT INTO student_lesson_progress (student_id, lesson_id, status, completed_at) 
        VALUES (?, ?, 'Completed', NOW())
        ON DUPLICATE KEY UPDATE status = 'Completed', completed_at = NOW()
    ");
    $stmtIns->execute([$studentId, $lessonId]);
    
    // 2. Compute course completion percentage
    // Get total lessons count in the course
    $stmtTotal = $db->prepare("
        SELECT COUNT(cl.lesson_id) FROM course_lessons cl 
        JOIN course_modules cm ON cl.module_id = cm.module_id 
        WHERE cm.course_id = ? AND cl.status = 'active'
    ");
    $stmtTotal->execute([$courseId]);
    $totalLessons = (int)$stmtTotal->fetchColumn();
    
    // Get completed lessons count in this course
    $stmtCompleted = $db->prepare("
        SELECT COUNT(slp.progress_id) FROM student_lesson_progress slp 
        JOIN course_lessons cl ON slp.lesson_id = cl.lesson_id 
        JOIN course_modules cm ON cl.module_id = cm.module_id 
        WHERE cm.course_id = ? AND slp.student_id = ? AND slp.status = 'Completed'
    ");
    $stmtCompleted->execute([$courseId, $studentId]);
    $completedLessons = (int)$stmtCompleted->fetchColumn();
    
    $completionPercentage = 0.00;
    if ($totalLessons > 0) {
        $completionPercentage = round(($completedLessons / $totalLessons) * 100, 2);
    }
    
    $status = 'In Progress';
    $completedAt = null;
    if ($completionPercentage >= 100) {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    }
    
    // 3. Update course progress details
    $stmtProgress = $db->prepare("
        INSERT INTO student_course_progress (student_id, course_id, completion_percentage, status, started_at, completed_at) 
        VALUES (?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE 
            completion_percentage = VALUES(completion_percentage), 
            status = VALUES(status), 
            completed_at = COALESCE(completed_at, VALUES(completed_at))
    ");
    $stmtProgress->execute([$studentId, $courseId, $completionPercentage, $status, $completedAt]);
    
    $db->commit();
    
    // Log Activity
    logActivity($db, $_SESSION['user_id'], 'Complete Lesson', "Marked lesson ID $lessonId complete on course $courseId. Overall progress: $completionPercentage%");
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
