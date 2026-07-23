<?php
// MAMCET Placement & Learning Portal - Change Academic Session API

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

$sessionId = (int)($_POST['session_id'] ?? 0);

if ($sessionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid session selected.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Verify session exists
    $stmt = $db->prepare("SELECT session_name FROM academic_sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Academic session not found.']);
        exit;
    }
    
    // Save to user session
    $_SESSION['active_session_id'] = $sessionId;
    $_SESSION['active_session_name'] = $session['session_name'];
    
    // Log activity
    logActivity($db, $_SESSION['user_id'], 'Session Switch', 'Switched active academic session to ' . $session['session_name']);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
