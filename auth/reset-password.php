<?php
// MAMCET Placement & Learning Portal - Self Service Password Reset Page

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

$error = '';
$success = '';
$step = 1;

$regNo = '';
$mobile = '';
$studentId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfRequest();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify') {
        $regNo = trim($_POST['registration_number'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        
        if (empty($regNo) || empty($mobile)) {
            $error = 'Both Registration Number and Mobile Number are required.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check if student exists and has an activated user account
                $stmt = $db->prepare("
                    SELECT s.student_id, s.mobile_number, s.user_id, s.student_name 
                    FROM students s 
                    WHERE s.registration_number = ?
                ");
                $stmt->execute([$regNo]);
                $student = $stmt->fetch();
                
                if ($student) {
                    $dbMobile = preg_replace('/[^0-9]/', '', $student['mobile_number']);
                    $inputMobile = preg_replace('/[^0-9]/', '', $mobile);
                    
                    if ($student['user_id'] === null) {
                        $error = 'This account has not been activated yet. Please run the first-time login process.';
                    } elseif ($dbMobile !== $inputMobile && strpos($dbMobile, $inputMobile) === false && strpos($inputMobile, $dbMobile) === false) {
                        $error = 'The mobile number does not match our records.';
                    } else {
                        $_SESSION['reset_verified_user_id'] = $student['user_id'];
                        $_SESSION['reset_verified_name'] = $student['student_name'];
                        $step = 2;
                    }
                } else {
                    $error = 'No student record matches the provided Registration Number.';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset') {
        $userId = $_SESSION['reset_verified_user_id'] ?? 0;
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if ($userId <= 0) {
            $error = 'Session expired. Please verify your details again.';
            $step = 1;
        } elseif (empty($password) || empty($confirmPassword)) {
            $error = 'Please fill out all fields.';
            $step = 2;
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $step = 2;
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
            $step = 2;
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmt->execute([$passHash, $userId]);
                
                // Clear reset session details
                unset($_SESSION['reset_verified_user_id']);
                unset($_SESSION['reset_verified_name']);
                
                // Log action
                logActivity($db, $userId, 'Password Reset', 'Password reset successfully via self-service verification.');
                
                $success = 'Password updated successfully! You can now log in.';
                $step = 3;
            } catch (Exception $e) {
                $error = 'System error: ' . $e->getMessage();
                $step = 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MAMCET Placement Portal</title>
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
        .reset-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            width: 100%;
            max-width: 450px;
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
        .header-section {
            text-align: center;
            margin-bottom: 30px;
        }
        .header-section i {
            color: #2563eb;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

<div class="reset-card">
    <div class="header-section">
        <i class="fa-solid fa-key fa-3x"></i>
        <h3>Reset Password</h3>
        <p class="text-white-50 mb-0">Recover Portal Credentials</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2" style="font-size: 0.9rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- STEP 1: Verify Identity -->
    <?php if ($step === 1): ?>
        <form action="reset-password.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="verify">
            
            <div class="mb-3">
                <label class="form-label text-white-50">Registration Number</label>
                <input type="text" name="registration_number" class="form-control" value="<?php echo esc($regNo); ?>" required>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-white-50">Registered Mobile Number</label>
                <input type="text" name="mobile_number" class="form-control" value="<?php echo esc($mobile); ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">Verify Details</button>
            
            <div class="text-center">
                <a href="student-login.php" class="text-white-50 text-decoration-none small"><i class="fa-solid fa-arrow-left me-2"></i> Back to Login</a>
            </div>
        </form>
    <?php endif; ?>
    
    <!-- STEP 2: Choose New Password -->
    <?php if ($step === 2): ?>
        <form action="reset-password.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="reset">
            
            <div class="alert alert-success py-2 mb-3" style="font-size: 0.9rem;">
                Identity Verified: <strong><?php echo esc($_SESSION['reset_verified_name']); ?></strong>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-white-50">New Password</label>
                <input type="password" name="password" class="form-control" required minlength="6">
            </div>
            
            <div class="mb-4">
                <label class="form-label text-white-50">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6">
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">Update Password</button>
        </form>
    <?php endif; ?>
    
    <!-- STEP 3: Success Screen -->
    <?php if ($step === 3): ?>
        <div class="text-center py-2">
            <i class="fa-solid fa-circle-check text-success fa-3x mb-3"></i>
            <p><?php echo $success; ?></p>
            <a href="student-login.php" class="btn btn-primary w-100 mt-2">Go to Login</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
