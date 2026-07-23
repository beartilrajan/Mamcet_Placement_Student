<?php
// MAMCET Placement & Learning Portal - Global Header Template

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/csrf.php');
require_once(__DIR__ . '/functions.php');
require_once(__DIR__ . '/../config/database.php');

// Fetch user information if logged in
$loggedInUser = null;
$activeSessionName = '2026–2027'; // Fallback
$allSessions = [];
$roleId = (int)($_SESSION['role_id'] ?? 0);

// Branding defaults
$portalName = 'MAMCET Placement & Learning Portal';
$primaryColor = '#0B3D91';
$secondaryColor = '#F4B400';
$brandFont = 'Inter';
$collegeLogo = 'assets/images/logo.png';
$placementLogo = 'assets/images/placement_logo.png';

try {
    $db = Database::getInstance()->getConnection();
    
    if (isLoggedIn()) {
        $stmt = $db->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $loggedInUser = $stmt->fetch();
        
        // Fetch specific details based on role
        if ($loggedInUser && $loggedInUser['role_id'] == ROLE_PLACEMENT_OFFICER) {
            $stmt = $db->prepare("SELECT name FROM placement_officers WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $officer = $stmt->fetch();
            $loggedInUser['display_name'] = $officer['name'] ?? 'Placement Officer';
        } elseif ($loggedInUser && $loggedInUser['role_id'] == ROLE_STUDENT) {
            $stmt = $db->prepare("SELECT student_name FROM students WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $student = $stmt->fetch();
            $loggedInUser['display_name'] = $student['student_name'] ?? 'Student';
        } else {
            $loggedInUser['display_name'] = 'Super Admin';
        }
    }
    
    // Fetch all academic sessions
    $stmt = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC");
    $allSessions = $stmt->fetchAll();
    
    // Determine active session
    if (!isset($_SESSION['active_session_id'])) {
        foreach ($allSessions as $s) {
            if ($s['is_active'] == 1) {
                $_SESSION['active_session_id'] = $s['session_id'];
                $_SESSION['active_session_name'] = $s['session_name'];
                break;
            }
        }
    }
    
    $activeSessionId = $_SESSION['active_session_id'] ?? 1;
    $stmt = $db->prepare("SELECT session_name FROM academic_sessions WHERE session_id = ?");
    $stmt->execute([$activeSessionId]);
    $sessionRow = $stmt->fetch();
    $activeSessionName = $sessionRow['session_name'] ?? '2026–2027';
    
    // Fetch all portal branding settings
    $stmtSet = $db->query("SELECT setting_key, setting_value FROM settings");
    $settingsRows = $stmtSet->fetchAll();
    $brandingSettings = [];
    foreach ($settingsRows as $row) {
        $brandingSettings[$row['setting_key']] = $row['setting_value'];
    }
    
    $portalName = $brandingSettings['portal_name'] ?? $portalName;
    $primaryColor = $brandingSettings['primary_color'] ?? $primaryColor;
    $secondaryColor = $brandingSettings['secondary_color'] ?? $secondaryColor;
    $brandFont = $brandingSettings['brand_font'] ?? $brandFont;
    $collegeLogo = $brandingSettings['college_logo'] ?? $collegeLogo;
    $placementLogo = $brandingSettings['placement_logo'] ?? $placementLogo;
    
} catch (Exception $e) {
    // Database connection not configured yet, skip silently so install.php can run
}

$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/student/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/auth/') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    $baseDir = '../';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? esc($pageTitle) . ' - ' : ''; ?><?php echo esc($portalName); ?></title>
    
    <!-- Dynamic Branding Styles -->
    <style>
        :root {
            --primary-color: <?php echo esc($primaryColor); ?>;
            --secondary-color: <?php echo esc($secondaryColor); ?>;
            --brand-font: '<?php echo esc($brandFont); ?>', sans-serif;
        }
        body {
            font-family: var(--brand-font) !important;
        }
        .btn-primary, .btn-primary:hover, .btn-primary:focus {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }
        .sidebar {
            background-color: var(--primary-color) !important;
        }
        .text-primary {
            color: var(--primary-color) !important;
        }
        .bg-primary {
            background-color: var(--primary-color) !important;
        }
        .border-left-primary {
            border-left: 0.25rem solid var(--primary-color) !important;
        }
    </style>
    
    <!-- Meta tags for SEO -->
    <meta name="description" content="MAMCET Placement and Learning Portal for student records management, placement announcements, and learning tracking.">
    <meta name="author" content="MAMCET">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Custom Style CSS -->
    <link href="<?php echo $baseDir; ?>assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php if (isLoggedIn()): ?>
<div class="app-wrapper">
    <!-- Sidebar template will be loaded here -->
<?php endif; ?>
