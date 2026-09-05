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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>MAMCET Placement & Learning Portal</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            padding: 24px 16px;
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
            pointer-events: none;
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
            pointer-events: none;
        }

        .portal-container {
            position: relative;
            z-index: 2;
            max-width: 820px;
            width: 100%;
            padding: 10px;
        }
        .header-section {
            text-align: center;
            margin-bottom: 35px;
        }
        .header-section i {
            color: #2563eb;
            margin-bottom: 12px;
            font-size: clamp(2.5rem, 6vw, 3.5rem);
        }
        .header-section h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.85rem, 5vw, 2.5rem);
            font-weight: 800;
        }
        .portal-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 36px 30px;
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
            box-shadow: 0 12px 32px rgba(37, 99, 235, 0.2);
        }
        .portal-card h3 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.25rem, 3.5vw, 1.5rem);
        }
        .icon-box {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.65rem;
            transition: all 0.3s ease;
        }
        .portal-card:hover .icon-box {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
        }
        .btn-portal {
            margin-top: auto;
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
            width: 100%;
            font-size: 0.95rem;
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
            border: 2px solid rgba(255,255,255,0.2);
            color: white;
        }
        .btn-officer:hover {
            background: rgba(255,255,255,0.08);
            border-color: white;
        }
        .footer-text {
            text-align: center;
            margin-top: 40px;
            font-size: 0.8rem;
            color: #64748b;
        }

        @media (max-width: 767px) {
            body {
                padding: 20px 12px;
            }
            .portal-container {
                padding: 0;
            }
            .portal-card {
                padding: 26px 20px;
                border-radius: 16px;
            }
            .header-section {
                margin-bottom: 25px;
            }
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
