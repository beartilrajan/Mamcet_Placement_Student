<?php
// MAMCET Placement & Learning Portal - Officer & Super Admin Login Page

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

// Redirect if already logged in
if (isLoggedIn()) {
    if ((int)$_SESSION['role_id'] === ROLE_STUDENT) {
        header("Location: ../student/dashboard.php");
    } else {
        header("Location: ../admin/dashboard.php");
    }
    exit;
}

$error = '';
$username = '';

// Check lockout duration and failed attempts
$maxAttempts = 5;
$lockoutMinutes = 15;
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfRequest();
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Check lockout: count failed attempts in the last 15 minutes
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM student_login_attempts 
                WHERE username = ? AND ip_address = ? AND is_successful = 0 
                AND attempt_time > NOW() - INTERVAL ? MINUTE
            ");
            $stmt->execute([$username, $ipAddress, $lockoutMinutes]);
            $failedCount = (int)$stmt->fetchColumn();
            
            if ($failedCount >= $maxAttempts) {
                $error = "This account is temporarily locked due to repeated failed logins. Please try again after $lockoutMinutes minutes.";
            } else {
                // Query user
                $stmt = $db->prepare("
                    SELECT u.*, r.role_name FROM users u 
                    JOIN roles r ON u.role_id = r.role_id 
                    WHERE u.username = ? AND u.role_id IN (1, 2)
                ");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    if ($user['status'] !== 'active') {
                        $error = 'Your account has been deactivated or suspended.';
                        // Log failed attempt
                        $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 0)");
                        $logStmt->execute([$username, $ipAddress]);
                    } else {
                        // Log successful attempt
                        $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 1)");
                        $logStmt->execute([$username, $ipAddress]);
                        
                        // Clear old failed attempts
                        $delStmt = $db->prepare("DELETE FROM student_login_attempts WHERE username = ? AND ip_address = ?");
                        $delStmt->execute([$username, $ipAddress]);
                        
                        // Setup Session
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role_id'] = $user['role_id'];
                        
                        // Load active academic session
                        $actStmt = $db->query("SELECT session_id, session_name FROM academic_sessions WHERE is_active = 1 LIMIT 1");
                        $actSession = $actStmt->fetch();
                        if ($actSession) {
                            $_SESSION['active_session_id'] = $actSession['session_id'];
                            $_SESSION['active_session_name'] = $actSession['session_name'];
                        } else {
                            $_SESSION['active_session_id'] = 1;
                            $_SESSION['active_session_name'] = '2026–2027';
                        }
                        
                        // Log activity
                        logActivity($db, $user['user_id'], 'Officer Login', 'Successfully logged in from IP ' . $ipAddress);
                        
                        header("Location: ../admin/dashboard.php");
                        exit;
                    }
                } else {
                    $error = 'Invalid username or password.';
                    // Log failed attempt
                    $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 0)");
                    $logStmt->execute([$username, $ipAddress]);
                }
            }
        } catch (Exception $e) {
            $error = 'System error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>Officer Login - MAMCET Placement Portal</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Plus Jakarta Sans', sans-serif;
            padding: 16px;
        }
        .login-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            width: 100%;
            max-width: 440px;
            padding: 32px 28px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }
        .login-header h3 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: clamp(1.35rem, 4vw, 1.65rem);
        }
        .form-control {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            padding: 12px 14px;
            font-size: 16px; /* Prevents iOS auto-zoom */
            border-radius: 10px;
        }
        .form-control:focus {
            background-color: rgba(15, 23, 42, 0.8);
            border-color: #2563eb;
            color: #f8fafc;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        .btn-primary {
            background-color: #2563eb;
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
            transform: translateY(-1px);
        }
        .login-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .login-header i {
            color: #2563eb;
            margin-bottom: 12px;
        }
        @media (max-width: 575px) {
            body {
                padding: 12px;
            }
            .login-card {
                padding: 24px 18px;
                border-radius: 16px;
            }
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <i class="fa-solid fa-user-shield fa-3x"></i>
        <h3>Officer Administration</h3>
        <p class="text-white-50 mb-0">Placement Officer & Admin Access</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2" style="font-size: 0.9rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>
    
    <form action="officer-login.php" method="POST">
        <?php csrfInput(); ?>
        
        <div class="mb-3">
            <label class="form-label text-white-50">Username / Email</label>
            <input type="text" name="username" class="form-control" value="<?php echo esc($username); ?>" required autocomplete="username">
        </div>
        
        <div class="mb-4">
            <label class="form-label text-white-50">Password</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        
        <button type="submit" class="btn btn-primary w-100 mb-3">Login to Dashboard</button>
        
        <div class="text-center">
            <a href="../index.php" class="text-white-50 text-decoration-none small"><i class="fa-solid fa-arrow-left me-2"></i> Back to Gateway</a>
        </div>
    </form>
</div>

</body>
</html>
