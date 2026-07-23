<?php
// MAMCET Placement & Learning Portal - Student Login Page

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
$regNo = '';

// Lockout tracking parameters
$maxAttempts = 5;
$lockoutMinutes = 15;
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfRequest();
    
    $regNo = trim($_POST['registration_number'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($regNo) || empty($password)) {
        $error = 'Please enter both your Registration Number and Password.';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Check lockout: count failed attempts in the last 15 minutes
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM student_login_attempts 
                WHERE username = ? AND ip_address = ? AND is_successful = 0 
                AND attempt_time > NOW() - INTERVAL ? MINUTE
            ");
            $stmt->execute([$regNo, $ipAddress, $lockoutMinutes]);
            $failedCount = (int)$stmt->fetchColumn();
            
            if ($failedCount >= $maxAttempts) {
                $error = "This account is temporarily locked due to repeated failed logins. Please try again after $lockoutMinutes minutes.";
            } else {
                // Query student and joined user record
                $stmt = $db->prepare("
                    SELECT s.*, u.password_hash, u.status, u.role_id 
                    FROM students s 
                    JOIN users u ON s.user_id = u.user_id 
                    WHERE s.registration_number = ?
                ");
                $stmt->execute([$regNo]);
                $student = $stmt->fetch();
                
                if ($student && password_verify($password, $student['password_hash'])) {
                    if ($student['status'] !== 'active') {
                        $error = 'Your student account has been deactivated or suspended.';
                        // Log failed attempt
                        $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 0)");
                        $logStmt->execute([$regNo, $ipAddress]);
                    } else {
                        // Log successful attempt
                        $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 1)");
                        $logStmt->execute([$regNo, $ipAddress]);
                        
                        // Clear old failed attempts
                        $delStmt = $db->prepare("DELETE FROM student_login_attempts WHERE username = ? AND ip_address = ?");
                        $delStmt->execute([$regNo, $ipAddress]);
                        
                        // Setup Session
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $student['user_id'];
                        $_SESSION['student_id'] = $student['student_id'];
                        $_SESSION['username'] = $student['registration_number'];
                        $_SESSION['role_id'] = ROLE_STUDENT;
                        
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
                        logActivity($db, $student['user_id'], 'Student Login', 'Logged in successfully.');
                        
                        header("Location: ../student/dashboard.php");
                        exit;
                    }
                } else {
                    // Check if registration number even exists as an unactivated record
                    $chkStmt = $db->prepare("SELECT user_id FROM students WHERE registration_number = ?");
                    $chkStmt->execute([$regNo]);
                    $existsStudent = $chkStmt->fetch();
                    
                    if ($existsStudent && $existsStudent['user_id'] === null) {
                        $error = 'Your account is not activated yet. Please click "First Time Login / Setup Account" below to register.';
                    } else {
                        $error = 'Invalid Registration Number or Password.';
                    }
                    
                    // Log failed attempt
                    $logStmt = $db->prepare("INSERT INTO student_login_attempts (username, ip_address, is_successful) VALUES (?, ?, 0)");
                    $logStmt->execute([$regNo, $ipAddress]);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - MAMCET Placement Portal</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            padding: 35px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        .form-control {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            padding: 12px;
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
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header i {
            color: #2563eb;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <i class="fa-solid fa-user-graduate fa-3x"></i>
        <h3>Student Login</h3>
        <p class="text-white-50 mb-0">MAMCET Learning & Placements Access</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2" style="font-size: 0.9rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>
    
    <form action="student-login.php" method="POST">
        <?php csrfInput(); ?>
        
        <div class="mb-3">
            <label class="form-label text-white-50">Registration Number</label>
            <input type="text" name="registration_number" class="form-control" placeholder="e.g. 811823120001" value="<?php echo esc($regNo); ?>" required autocomplete="username">
        </div>
        
        <div class="mb-4">
            <label class="form-label text-white-50">Password</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        </div>
        
        <button type="submit" class="btn btn-primary w-100 mb-3">Sign In</button>
        
        <div class="text-center mb-3">
            <a href="first-login.php" class="text-primary text-decoration-none small fw-bold"><i class="fa-solid fa-user-plus me-1"></i> First Time Login / Setup Account</a>
        </div>
        
        <div class="text-center">
            <a href="../index.php" class="text-white-50 text-decoration-none small"><i class="fa-solid fa-arrow-left me-2"></i> Back to Gateway</a>
        </div>
    </form>
</div>

</body>
</html>
