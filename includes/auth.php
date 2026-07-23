<?php
// MAMCET Placement & Learning Portal - Authentication & Session Helper

if (session_status() === PHP_SESSION_NONE) {
    $config = require(__DIR__ . '/../config/app.php');
    $sessionConfig = $config['session'] ?? [];
    
    session_set_cookie_params([
        'lifetime' => $sessionConfig['lifetime'] ?? 7200,
        'path' => $sessionConfig['path'] ?? '/',
        'domain' => $sessionConfig['domain'] ?? '',
        'secure' => $sessionConfig['secure'] ?? false,
        'httponly' => $sessionConfig['httponly'] ?? true,
        'samesite' => $sessionConfig['samesite'] ?? 'Lax'
    ]);
    
    session_name($sessionConfig['name'] ?? 'mamcet_portal_session');
    session_start();
}

/**
 * Regenerate session id to prevent hijacking
 */
function secureSessionRegenerate() {
    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) { // regenerate every 30 mins
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

secureSessionRegenerate();

/**
 * Check if a user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role_id']);
}

/**
 * Require login to access a page
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Find base path
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = dirname(dirname($scriptName));
        if ($dir === '\\' || $dir === '/') {
            $dir = '';
        }
        
        // Check if page requested is student panel or admin panel to redirect to appropriate login
        if (strpos($_SERVER['REQUEST_URI'], '/student/') !== false) {
            header("Location: " . $protocol . "://" . $host . $dir . "/auth/student-login.php");
        } else {
            header("Location: " . $protocol . "://" . $host . $dir . "/auth/officer-login.php");
        }
        exit;
    }
}

/**
 * Require a specific role
 */
function requireRole($allowedRoles) {
    requireLogin();
    
    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }
    
    $userRoleId = (int)$_SESSION['role_id'];
    
    if (!in_array($userRoleId, $allowedRoles)) {
        http_response_code(403);
        echo "<h3>403 Forbidden: Access Denied</h3>";
        echo "<p>You do not have permission to access this page.</p>";
        echo "<a href='../index.php'>Go Back</a>";
        exit;
    }
}

/**
 * Get active student details if logged in as student
 */
function getActiveStudent($db = null) {
    if (!isLoggedIn() || (int)$_SESSION['role_id'] !== 3) {
        return null;
    }
    
    if ($db === null) {
        require_once(__DIR__ . '/../config/database.php');
        $db = Database::getInstance()->getConnection();
    }
    
    $stmt = $db->prepare("
        SELECT s.*, sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears, b.batch_name, d.dept_name, d.dept_code, pr.programme_name, sec.section_name 
        FROM students s 
        JOIN batches b ON s.batch_id = b.batch_id 
        JOIN departments d ON s.dept_id = d.dept_id
        LEFT JOIN student_academics sa ON s.student_id = sa.student_id
        LEFT JOIN programmes pr ON b.programme_id = pr.programme_id
        LEFT JOIN sections sec ON s.section_id = sec.section_id
        WHERE s.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Check if the active session ID is set
 */
function getActiveAcademicSessionId() {
    return $_SESSION['active_session_id'] ?? 1; // Default fallback to 1
}
