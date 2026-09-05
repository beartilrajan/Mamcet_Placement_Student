<?php
// MAMCET Placement & Learning Portal - Logout Script

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');


$roleId = 0;
if (isLoggedIn()) {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    try {
        $db = Database::getInstance()->getConnection();
        logActivity($db, $_SESSION['user_id'], 'Logout', 'Logged out successfully.');
    } catch (Exception $e) {
        // Silently proceed
    }
}

// Clear all session variables
$_SESSION = [];

// Destroy session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect based on role: officers to officer login, students/default to student login
if ($roleId === ROLE_SUPER_ADMIN || $roleId === ROLE_PLACEMENT_OFFICER) {
    header("Location: officer-login.php");
} else {
    header("Location: student-login.php");
}
exit;
