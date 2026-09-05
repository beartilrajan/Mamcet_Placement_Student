<?php
// MAMCET Placement & Learning Portal - CSRF Protection

require_once(__DIR__ . '/auth.php');

/**
 * Generate CSRF Token and store in session if not present
 */
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF Input Field
 */
function csrfInput() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

/**
 * Validate CSRF Token
 */
function validateCsrfToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($sessionToken) || empty($token)) {
        return false;
    }
    
    return hash_equals($sessionToken, $token);
}

/**
 * Verify Request or terminate execution with 403 Forbidden
 */
function verifyCsrfRequest() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken()) {
            http_response_code(403);
            
            // Check if request is AJAX or expects JSON response
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false);
                
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Security token expired or invalid CSRF token. Please refresh the page and try again.'
                ]);
                exit;
            }
            
            die("CSRF validation failed. Security block triggered.");
        }
    }
}
