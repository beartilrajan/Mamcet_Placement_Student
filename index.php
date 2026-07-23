<?php
// MAMCET Placement & Learning Portal - Root Gateway Landing Page

$configFile = __DIR__ . '/config/db_config.php';

if (!file_exists($configFile)) {
    header("Location: install.php");
    exit;
}

require_once(__DIR__ . '/includes/auth.php');
require_once(__DIR__ . '/includes/functions.php');

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    $roleId = (int)$_SESSION['role_id'];
    if ($roleId === ROLE_STUDENT) {
        header("Location: student/dashboard.php");
    } else {
        header("Location: admin/dashboard.php");
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAMCET Placement & Learning Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        /* Decorative Background Elements */
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, rgba(15, 23, 42, 0) 70%);
            top: -100px;
            right: -100px;
            z-index: 1;
        }
        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(13, 148, 136, 0.15) 0%, rgba(15, 23, 42, 0) 70%);
            bottom: -200px;
            left: -200px;
            z-index: 1;
        }

        .portal-container {
            position: relative;
            z-index: 2;
            max-width: 800px;
            width: 100%;
            padding: 20px;
        }
        .header-section {
            text-align: center;
            margin-bottom: 40px;
        }
        .header-section i {
            color: #2563eb;
            margin-bottom: 15px;
        }
        .portal-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 40px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .portal-card:hover {
            transform: translateY(-5px);
            border-color: #2563eb;
            box-shadow: 0 10px 30px rgba(37, 99, 235, 0.15);
        }
        .icon-box {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            font-size: 1.75rem;
            transition: all 0.3s ease;
        }
        .portal-card:hover .icon-box {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
        }
        .btn-portal {
            margin-top: auto;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-student {
            background: #2563eb;
            border-color: #2563eb;
            color: white;
        }
        .btn-student:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }
        .btn-officer {
            background: transparent;
            border: 2px solid rgba(255,255,255,0.15);
            color: white;
        }
        .btn-officer:hover {
            background: rgba(255,255,255,0.05);
            border-color: white;
        }
        .footer-text {
            text-align: center;
            margin-top: 50px;
            font-size: 0.8rem;
            color: #64748b;
        }
    </style>
</head>
<body>

<div class="portal-container">
    <div class="header-section">
        <i class="fa-solid fa-graduation-cap fa-4x"></i>
        <h1 class="fw-bold">MAMCET</h1>
        <p class="text-secondary-emphasis text-white-50">Placement & Learning Portal</p>
    </div>
    
    <div class="row g-4 justify-content-center">
        <!-- Student Panel Card -->
        <div class="col-md-5">
            <div class="portal-card">
                <div class="icon-box">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <h3 class="fw-bold mb-2">Student Portal</h3>
                <p class="text-white-50 mb-4" style="font-size: 0.9rem;">View your placement eligibility, apply to open jobs, and complete course modules.</p>
                <a href="auth/student-login.php" class="btn btn-portal btn-student">Access Student Portal</a>
            </div>
        </div>
        
        <!-- Officer Panel Card -->
        <div class="col-md-5">
            <div class="portal-card">
                <div class="icon-box" style="color: #0d9488; background: rgba(13, 148, 136, 0.1);">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <h3 class="fw-bold mb-2">Placement Officer</h3>
                <p class="text-white-50 mb-4" style="font-size: 0.9rem;">Manage placement databases, publish drives, configure learning, and export statistics.</p>
                <a href="auth/officer-login.php" class="btn btn-portal btn-officer">Access Officer Portal</a>
            </div>
        </div>
    </div>
    
    <p class="footer-text">© <?php echo date('Y'); ?> M.A.M. College of Engineering and Technology. All Rights Reserved.</p>
</div>

</body>
</html>
