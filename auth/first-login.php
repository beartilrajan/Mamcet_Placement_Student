<?php
// MAMCET Placement & Learning Portal - First-time Student Account Setup Wizard

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
$success = '';
$step = 1;

$regNo = '';
$mobile = '';
$studentData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfRequest();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_student') {
        $regNo = trim($_POST['registration_number'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        
        if (empty($regNo) || empty($mobile)) {
            $error = 'Both Registration Number and Mobile Number are required.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Fetch student matching Registration and Mobile
                $stmt = $db->prepare("SELECT * FROM students WHERE registration_number = ?");
                $stmt->execute([$regNo]);
                $studentData = $stmt->fetch();
                
                if ($studentData) {
                    // Check mobile matching
                    $dbMobile = preg_replace('/[^0-9]/', '', $studentData['mobile_number']);
                    $inputMobile = preg_replace('/[^0-9]/', '', $mobile);
                    
                    // Allow partial matching or simple formatting variations
                    if ($dbMobile !== $inputMobile && strpos($dbMobile, $inputMobile) === false && strpos($inputMobile, $dbMobile) === false) {
                        $error = 'The mobile number does not match our records. Please contact the placement officer.';
                    } elseif ($studentData['user_id'] !== null) {
                        $error = 'This account has already been set up. Please log in using the standard login page.';
                    } else {
                        // Store verification details in session for step 2 safety
                        $_SESSION['setup_verified_student_id'] = $studentData['student_id'];
                        $_SESSION['setup_verified_reg_no'] = $studentData['registration_number'];
                        $_SESSION['setup_verified_name'] = $studentData['student_name'];
                        $step = 2;
                    }
                } else {
                    $error = 'No student record found matching this Registration Number. Make sure the placement officer has imported your department dataset.';
                }
            } catch (Exception $e) {
                $error = 'System error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'setup_password') {
        $studentId = $_SESSION['setup_verified_student_id'] ?? 0;
        $regNo = $_SESSION['setup_verified_reg_no'] ?? '';
        $studentName = $_SESSION['setup_verified_name'] ?? '';
        
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $agreeTerms = isset($_POST['agree_terms']) ? 1 : 0;
        
        if ($studentId <= 0 || empty($regNo)) {
            $error = 'Session expired. Please restart the verification process.';
            $step = 1;
        } elseif (empty($password) || empty($confirmPassword)) {
            $error = 'Please fill in both password fields.';
            $step = 2;
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $step = 2;
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
            $step = 2;
        } elseif (!$agreeTerms) {
            $error = 'You must agree to the portal terms and conditions.';
            $step = 2;
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $db->beginTransaction();
                
                // 1. Create user account
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, role_id, status) VALUES (?, ?, ?, 'active')");
                $stmt->execute([$regNo, $passHash, ROLE_STUDENT]);
                $newUserId = $db->lastInsertId();
                
                // 2. Link user to student
                $stmt = $db->prepare("UPDATE students SET user_id = ? WHERE student_id = ?");
                $stmt->execute([$newUserId, $studentId]);
                
                // 3. Ensure profile and academic rows exist
                $stmt = $db->prepare("SELECT profile_id FROM student_profiles WHERE student_id = ?");
                $stmt->execute([$studentId]);
                if (!$stmt->fetch()) {
                    $db->prepare("INSERT INTO student_profiles (student_id) VALUES (?)")->execute([$studentId]);
                }
                
                $stmt = $db->prepare("SELECT academic_id FROM student_academics WHERE student_id = ?");
                $stmt->execute([$studentId]);
                if (!$stmt->fetch()) {
                    $db->prepare("INSERT INTO student_academics (student_id) VALUES (?)")->execute([$studentId]);
                }
                
                $db->commit();
                
                // Cleanup temp sessions
                unset($_SESSION['setup_verified_student_id']);
                unset($_SESSION['setup_verified_reg_no']);
                unset($_SESSION['setup_verified_name']);
                
                // Automatically log in
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['student_id'] = $studentId;
                $_SESSION['username'] = $regNo;
                $_SESSION['role_id'] = ROLE_STUDENT;
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
                logActivity($db, $newUserId, 'Account Setup', 'First-time setup completed successfully.');
                
                setFlash('success', 'Welcome to your dashboard! Your account has been activated.');
                header("Location: ../student/dashboard.php");
                exit;
                
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error during account creation: ' . $e->getMessage();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>First Time Login Setup - MAMCET Placement Portal</title>
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
        .setup-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            padding: 32px 28px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }
        .header-section h3 {
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
        .header-section {
            text-align: center;
            margin-bottom: 24px;
        }
        .header-section i {
            color: #2563eb;
            margin-bottom: 12px;
        }
        @media (max-width: 575px) {
            body {
                padding: 12px;
            }
            .setup-card {
                padding: 24px 18px;
                border-radius: 16px;
            }
        }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="header-section">
        <i class="fa-solid fa-user-shield fa-3x"></i>
        <h3>Account Setup</h3>
        <p class="text-white-50 mb-0">Student Verification Wizard</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2" style="font-size: 0.9rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- STEP 1: Verification -->
    <?php if ($step === 1): ?>
        <form action="first-login.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="verify_student">
            
            <p class="text-white-50 mb-4" style="font-size: 0.9rem;">Enter your university registration number and the mobile number registered at college to verify your record.</p>
            
            <div class="mb-3">
                <label class="form-label text-white-50">Registration Number</label>
                <input type="text" name="registration_number" class="form-control" placeholder="e.g. 811823120001" value="<?php echo esc($regNo); ?>" required>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-white-50">Registered Mobile Number</label>
                <input type="text" name="mobile_number" class="form-control" placeholder="e.g. 9876543210" value="<?php echo esc($mobile); ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">Verify Record <i class="fa-solid fa-chevron-right ms-1"></i></button>
            
            <div class="text-center">
                <a href="student-login.php" class="text-white-50 text-decoration-none small"><i class="fa-solid fa-arrow-left me-2"></i> Return to Login</a>
            </div>
        </form>
    <?php endif; ?>
    
    <!-- STEP 2: Password Setup -->
    <?php if ($step === 2): ?>
        <form action="first-login.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="setup_password">
            
            <div class="alert alert-success py-2 mb-3" style="font-size: 0.9rem;">
                <i class="fa-solid fa-circle-check me-2"></i> Verified: <strong><?php echo esc($_SESSION['setup_verified_name']); ?></strong>
            </div>
            
            <p class="text-white-50 mb-4" style="font-size: 0.9rem;">Establish a personal secure password for future logins. Do not share this password.</p>
            
            <div class="mb-3">
                <label class="form-label text-white-50">Create Password</label>
                <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
            </div>
            
            <div class="mb-3">
                <label class="form-label text-white-50">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6">
            </div>
            
            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="agree_terms" id="agreeTerms" required>
                <label class="form-check-label text-white-50 small" for="agreeTerms">
                    I agree to the college placement portal terms and conditions, and declare that the profile data loaded and updated by me will be authentic.
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3"><i class="fa-solid fa-circle-check me-2"></i> Activate Account & Login</button>
            
            <div class="text-center">
                <a href="first-login.php" class="text-white-50 text-decoration-none small"><i class="fa-solid fa-arrow-left me-2"></i> Restart Verification</a>
            </div>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
