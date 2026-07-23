<?php
// MAMCET Placement & Learning Portal - Installation Setup Wizard

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$configFile = __DIR__ . '/config/db_config.php';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// 1. Requirements Check
$requirements = [
    'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'zip' => extension_loaded('zip'),
    'xml' => extension_loaded('xml'),
    'gd' => extension_loaded('gd'),
    'config_writable' => is_writable(__DIR__ . '/config') || !file_exists($configFile),
    'uploads_writable' => is_writable(__DIR__ . '/assets') || is_writable(__DIR__),
];

$requirements_passed = !in_array(false, $requirements, true);

// Handle AJAX Database Connection Test
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    $host = $_POST['host'] ?? 'localhost';
    $port = $_POST['port'] ?? '3306';
    $dbname = $_POST['dbname'] ?? '';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    
    try {
        $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3];
        $pdo = new PDO($dsn, $user, $pass, $options);
        
        // Check if database exists or can be created
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbname'");
        $exists = $stmt->fetch();
        
        if ($exists) {
            echo json_encode(['success' => true, 'message' => 'Connected successfully! Database exists.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Connected successfully! Database does not exist, but can be created.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
    }
    exit;
}

$error_msg = '';
if (isset($_GET['error']) && $_GET['error'] === 'db_conn') {
    $error_msg = "Database Connection Failure: " . ($_SESSION['db_connection_error'] ?? 'Verify credentials.');
    $step = 2;
}
$success_msg = '';

// Handle Step Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_step'])) {
    $post_step = (int)$_POST['submit_step'];
    
    if ($post_step === 2) {
        // Save Database config and verify connection
        $host = $_POST['host'] ?? 'localhost';
        $port = $_POST['port'] ?? '3306';
        $dbname = $_POST['dbname'] ?? '';
        $user = $_POST['user'] ?? '';
        $pass = $_POST['pass'] ?? '';
        
        try {
            $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            
            // Create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            $_SESSION['install_db'] = [
                'host' => $host,
                'port' => $port,
                'dbname' => $dbname,
                'user' => $user,
                'pass' => $pass
            ];
            
            header("Location: install.php?step=3");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Database Connection Failed: " . $e->getMessage();
        }
    }
    
    if ($post_step === 3) {
        // Save Super Admin User credentials
        $admin_user = trim($_POST['admin_user'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_pass = $_POST['admin_pass'] ?? '';
        $admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';
        
        if (empty($admin_user) || empty($admin_email) || empty($admin_pass)) {
            $error_msg = "Admin user details are required.";
        } elseif ($admin_pass !== $admin_pass_confirm) {
            $error_msg = "Admin passwords do not match.";
        } elseif (strlen($admin_pass) < 6) {
            $error_msg = "Password must be at least 6 characters.";
        } else {
            $_SESSION['install_admin'] = [
                'username' => $admin_user,
                'email' => $admin_email,
                'password' => $admin_pass
            ];
            
            $_SESSION['install_branding'] = [
                'college_name' => trim($_POST['college_name'] ?? 'M.A.M. College of Engineering and Technology'),
                'college_short' => trim($_POST['college_short'] ?? 'MAMCET'),
                'portal_name' => trim($_POST['portal_name'] ?? 'MAMCET Placement & Learning Portal'),
                'college_logo' => trim($_POST['college_logo'] ?? 'assets/images/logo.png'),
                'placement_logo' => trim($_POST['placement_logo'] ?? 'assets/images/placement_logo.png'),
                'primary_color' => trim($_POST['primary_color'] ?? '#0B3D91'),
                'secondary_color' => trim($_POST['secondary_color'] ?? '#F4B400'),
                'brand_font' => trim($_POST['brand_font'] ?? 'Inter'),
                'college_address' => trim($_POST['college_address'] ?? 'Trichy-Chennai Trunk Road, Siruganur, Trichy - 621105'),
                'placement_email' => trim($_POST['placement_email'] ?? 'placement@mamcet.org'),
                'placement_phone' => trim($_POST['placement_phone'] ?? '0431-2650500'),
                'website_url' => trim($_POST['website_url'] ?? 'http://www.mamcet.org'),
                'ai_api_key' => trim($_POST['ai_api_key'] ?? 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc')
            ];
            
            header("Location: install.php?step=4");
            exit;
        }
    }
    
    if ($post_step === 4) {
        // Execute Installation
        $db_info = $_SESSION['install_db'] ?? null;
        $admin_info = $_SESSION['install_admin'] ?? null;
        
        if (!$db_info || !$admin_info) {
            $error_msg = "Session expired or steps incomplete. Please restart setup.";
            $step = 1;
        } else {
            try {
                $dsn = "mysql:host={$db_info['host']};dbname={$db_info['dbname']};port={$db_info['port']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_info['user'], $db_info['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
                
                // 1. Execute Schema
                $schemaSql = file_get_contents(__DIR__ . '/database/schema.sql');
                // Remove comment blocks
                $schemaSql = preg_replace('/--.*\n/', '', $schemaSql);
                $queries = array_filter(array_map('trim', explode(';', $schemaSql)));
                
                foreach ($queries as $q) {
                    if (!empty($q)) {
                        $pdo->exec($q);
                    }
                }
                
                // 2. Execute Seed
                $seedSql = file_get_contents(__DIR__ . '/database/seed.sql');
                $seedSql = preg_replace('/--.*\n/', '', $seedSql);
                $seedQueries = array_filter(array_map('trim', explode(';', $seedSql)));
                
                foreach ($seedQueries as $q) {
                    if (!empty($q)) {
                        $pdo->exec($q);
                    }
                }
                
                // 3. Create initial super admin user (Role ID = 1)
                $passHash = password_hash($admin_info['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role_id, status) VALUES (?, ?, 1, 'active')");
                $stmt->execute([$admin_info['username'], $passHash]);
                
                // 4. Create directory structure for uploads
                $dirs = [
                    __DIR__ . '/assets/uploads',
                    __DIR__ . '/assets/uploads/resumes',
                    __DIR__ . '/assets/uploads/courses',
                    __DIR__ . '/assets/uploads/thumbnails',
                    __DIR__ . '/assets/uploads/attachments',
                    __DIR__ . '/data/import-reports'
                ];
                foreach ($dirs as $dir) {
                    if (!file_exists($dir)) {
                        mkdir($dir, 0777, true);
                    }
                }
                
                // 5. Scan available Excel files in data/ directory and insert into dataset_files
                $dataDir = __DIR__ . '/data';
                if (file_exists($dataDir)) {
                    $files = scandir($dataDir);
                    foreach ($files as $file) {
                        $ext = pathinfo($file, PATHINFO_EXTENSION);
                        if (in_array(strtolower($ext), ['xlsx', 'xls', 'csv'])) {
                            $filePath = $dataDir . '/' . $file;
                            $fileSize = filesize($filePath);
                            $modTime = date('Y-m-d H:i:s', filemtime($filePath));
                            
                            // Detect department from filename
                            $detectedDeptId = null;
                            $cleanName = strtolower($file);
                            
                            // Load department ids
                            $stmtDept = $pdo->query("SELECT dept_id, dept_code FROM departments");
                            $depts = $stmtDept->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($depts as $d) {
                                $code = strtolower($d['dept_code']);
                                if (strpos($cleanName, $code) !== false) {
                                    $detectedDeptId = $d['dept_id'];
                                    break;
                                }
                            }
                            
                            // Check if file is already registered
                            $chk = $pdo->prepare("SELECT file_id FROM dataset_files WHERE filename = ?");
                            $chk->execute([$file]);
                            if (!$chk->fetch()) {
                                $insFile = $pdo->prepare("INSERT INTO dataset_files (filename, filepath, filesize, last_modified, detected_dept_id, import_status) VALUES (?, ?, ?, ?, ?, 'pending')");
                                $insFile->execute([$file, $filePath, $fileSize, $modTime, $detectedDeptId]);
                            }
                        }
                    }
                }
                
                // 6. Run Stage 2 database migrations
                $stage2SqlFile = __DIR__ . '/database/migrations/stage2.sql';
                if (file_exists($stage2SqlFile)) {
                    $stage2Sql = file_get_contents($stage2SqlFile);
                    $stage2Sql = preg_replace('/--.*\n/', '', $stage2Sql);
                    $stage2Queries = array_filter(array_map('trim', explode(';', $stage2Sql)));
                    foreach ($stage2Queries as $q) {
                        if (!empty($q)) {
                            $pdo->exec($q);
                        }
                    }
                }

                // 7. Insert branding settings
                $brand_info = $_SESSION['install_branding'] ?? [];
                if (!empty($brand_info)) {
                    $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    foreach ($brand_info as $key => $val) {
                        if ($key !== 'ai_api_key') {
                            $stmtSet->execute([$key, $val]);
                        }
                    }

                    // Save AI Settings into database
                    $aiKey = $brand_info['ai_api_key'] ?? 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc';
                    $pdo->exec("INSERT IGNORE INTO ai_providers (provider_id, provider_name, is_active) VALUES 
                        (1, 'gemini', 1),
                        (2, 'openai', 0)
                    ");
                    $stmtAi = $pdo->prepare("
                        INSERT INTO ai_settings (setting_id, provider_id, model_name, api_key, temperature, max_tokens) 
                        VALUES (1, 1, 'gemini-1.5-flash', ?, 0.20, 2048)
                        ON DUPLICATE KEY UPDATE api_key = VALUES(api_key)
                    ");
                    $stmtAi->execute([$aiKey]);

                    $stmtAi2 = $pdo->prepare("
                        INSERT INTO ai_settings (setting_id, provider_id, model_name, api_key, temperature, max_tokens) 
                        VALUES (2, 2, 'gpt-4o-mini', '', 0.20, 2048)
                        ON DUPLICATE KEY UPDATE api_key = VALUES(api_key)
                    ");
                    $stmtAi2->execute();
                    
                    // Create default template
                    $pdo->exec("INSERT IGNORE INTO prompt_templates (template_id, template_name, system_prompt, user_prompt_format) VALUES 
                        (1, 'ats_analysis', 'You are a professional ATS resume analyzer for placement services.', 'Please analyze the following extracted resume text...'),
                        (2, 'job_matching', 'You are an ATS Match Engine. Compare the candidate\'s resume and the job description.', 'Compare resume against job...')
                    ");
                }

                // 8. Write config/db_config.php file
                $configContent = "<?php\n// Generated by install.php\nreturn [\n" .
                    "    'host' => '" . addslashes($db_info['host']) . "',\n" .
                    "    'port' => '" . addslashes($db_info['port']) . "',\n" .
                    "    'dbname' => '" . addslashes($db_info['dbname']) . "',\n" .
                    "    'user' => '" . addslashes($db_info['user']) . "',\n" .
                    "    'pass' => '" . addslashes($db_info['pass']) . "',\n" .
                    "    'charset' => 'utf8mb4'\n];\n";
                
                file_put_contents($configFile, $configContent);

                // 9. Write config/stage2.php config settings file
                $stage2ConfigFile = __DIR__ . '/config/stage2.php';
                if (file_exists($stage2ConfigFile) && !empty($brand_info)) {
                    $configData = [
                        'installed' => true,
                        'ai' => [
                            'active_provider' => 'gemini',
                            'gemini' => [
                                'api_key' => $brand_info['ai_api_key'] ?? 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc',
                                'model_name' => 'gemini-1.5-flash',
                                'api_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/',
                                'temperature' => 0.20,
                                'max_tokens' => 2048,
                            ],
                            'openai' => [
                                'api_key' => '',
                                'model_name' => 'gpt-4o-mini',
                                'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
                                'temperature' => 0.20,
                                'max_tokens' => 2048,
                            ],
                            'limits' => [
                                'daily_limit_per_student' => 3,
                                'monthly_limit_per_student' => 10,
                                'cooldown_seconds' => 60
                            ]
                        ],
                        'smtp' => [
                            'host' => '',
                            'port' => 587,
                            'encryption' => 'tls',
                            'username' => '',
                            'password' => '',
                            'sender_name' => $brand_info['portal_name'] ?? 'MAMCET Placement Cell',
                            'sender_email' => $brand_info['placement_email'] ?? 'placement@mamcet.org',
                            'batch_size' => 25
                        ],
                        'whatsapp' => [
                            'provider' => 'click_to_chat',
                            'webhook_url' => '',
                            'api_token' => '',
                            'phone_number_id' => ''
                        ],
                        'modules' => [
                            'resume_management' => true,
                            'ats_analysis' => true,
                            'resume_builder' => true,
                            'job_matching' => true,
                            'interview_preparation' => true,
                            'mock_interview' => true,
                            'eligibility_automation' => true,
                            'application_tracking' => true,
                            'notifications' => true,
                            'advanced_reports' => true
                        ],
                        'resume' => [
                            'max_file_size_mb' => 5,
                            'allowed_types' => ['pdf', 'docx']
                        ]
                    ];
                    $configText = "<?php\nreturn " . var_export($configData, true) . ";\n";
                    file_put_contents($stage2ConfigFile, $configText);
                }
                
                // Success! Clear session and head to step 5
                unset($_SESSION['install_db']);
                unset($_SESSION['install_admin']);
                unset($_SESSION['install_branding']);
                
                header("Location: install.php?step=5");
                exit;
                
            } catch (Exception $e) {
                $error_msg = "Installation Execution Failed: " . $e->getMessage();
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
    <title>MAMCET Placement Portal - Installation Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #311042 100%);
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 0;
            margin: 0;
        }
        .install-container {
            max-width: 800px;
            width: 95%;
            margin: 20px auto;
        }
        .card {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            background: rgba(30, 41, 59, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            color: #f1f5f9;
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 40px 40px 20px 40px;
            text-align: center;
        }
        .card-body {
            padding: 40px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            position: relative;
            padding: 0 20px;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 4px;
            background-color: rgba(255,255,255,0.1);
            transform: translateY(-50%);
            z-index: 1;
        }
        .step-dot {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: #1e293b;
            border: 2px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            color: #94a3b8;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        .step-dot.active {
            background-color: #2563eb;
            color: white;
            border-color: #60a5fa;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.6);
            transform: scale(1.1);
        }
        .step-dot.completed {
            background-color: #10b981;
            color: white;
            border-color: #34d399;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.4);
        }
        .form-control, .form-select {
            background-color: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #f1f5f9 !important;
            border-radius: 12px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: rgba(15, 23, 42, 0.8) !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
            outline: none !important;
        }
        .form-label {
            font-weight: 500;
            color: #cbd5e1;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .text-muted {
            color: #94a3b8 !important;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            border: none !important;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.4);
        }
        .btn-outline-secondary {
            border: 1px solid rgba(255,255,255,0.15) !important;
            color: #cbd5e1 !important;
            border-radius: 12px;
            padding: 12px 24px;
            transition: all 0.3s ease;
        }
        .btn-outline-secondary:hover {
            background-color: rgba(255,255,255,0.05);
            color: white !important;
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            border: none !important;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
        }
        .btn-light {
            background-color: rgba(255,255,255,0.1) !important;
            border: none !important;
            color: #f1f5f9 !important;
            border-radius: 12px;
            padding: 12px 24px;
            transition: all 0.3s ease;
        }
        .btn-light:hover {
            background-color: rgba(255,255,255,0.15) !important;
            color: white !important;
        }
        .card.shadow-sm.border {
            background-color: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255,255,255,0.08) !important;
        }
        .card-header.bg-dark, .card-header.bg-info, .card-header.bg-primary {
            background-color: rgba(15, 23, 42, 0.6) !important;
            border-bottom: 1px solid rgba(255,255,255,0.08) !important;
        }
        .list-group-item {
            background-color: rgba(15, 23, 42, 0.3) !important;
            border: 1px solid rgba(255,255,255,0.05) !important;
            color: #cbd5e1 !important;
            padding: 16px 20px;
        }
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.15) !important;
            border: 1px solid rgba(239, 68, 68, 0.3) !important;
            color: #fca5a5 !important;
            border-radius: 12px;
        }
        .alert-info {
            background-color: rgba(59, 130, 246, 0.15) !important;
            border: 1px solid rgba(59, 130, 246, 0.3) !important;
            color: #93c5fd !important;
            border-radius: 12px;
        }
        .form-control-color {
            padding: 6px !important;
            height: 48px;
        }
    </style>
</head>
<body>

<div class="install-container">
    <div class="card">
        <div class="card-header">
            <i class="fa-solid fa-graduation-cap fa-3x mb-3 text-info"></i>
            <h2>MAMCET Placement Portal</h2>
            <p class="mb-0 text-white-50">Stage 2 Installation Wizard</p>
        </div>
        
        <div class="card-body">
            <!-- Progress indicator -->
            <div class="step-indicator">
                <div class="step-dot <?php echo $step === 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>">
                    <?php echo $step > 1 ? '<i class="fa-solid fa-check"></i>' : '1'; ?>
                </div>
                <div class="step-dot <?php echo $step === 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>">
                    <?php echo $step > 2 ? '<i class="fa-solid fa-check"></i>' : '2'; ?>
                </div>
                <div class="step-dot <?php echo $step === 3 ? 'active' : ($step > 3 ? 'completed' : ''); ?>">
                    <?php echo $step > 3 ? '<i class="fa-solid fa-check"></i>' : '3'; ?>
                </div>
                <div class="step-dot <?php echo $step === 4 ? 'active' : ($step > 4 ? 'completed' : ''); ?>">
                    <?php echo $step > 4 ? '<i class="fa-solid fa-check"></i>' : '4'; ?>
                </div>
                <div class="step-dot <?php echo $step === 5 ? 'active' : ''; ?>">5</div>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            
            <!-- STEP 1: Requirements Check -->
            <?php if ($step === 1): ?>
                <h4 class="mb-3">System Requirements Check</h4>
                <p class="text-muted">Verifying server environment checks before database mapping configuration.</p>
                
                <ul class="list-group mb-4">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        PHP Version (>= 8.0.0)
                        <span>
                            <?php if ($requirements['php_version']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i> (PHP <?php echo PHP_VERSION; ?>)
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Requires >= 8.0.0
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        PDO MySQL Extension Loaded
                        <span>
                            <?php if ($requirements['pdo_mysql']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Missing
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Zip Archive Extension (Excel loading)
                        <span>
                            <?php if ($requirements['zip']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Missing
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        XML Parser Extension (Excel structure)
                        <span>
                            <?php if ($requirements['xml']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Missing
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        GD Graphics Extension (Thumbnails creation)
                        <span>
                            <?php if ($requirements['gd']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Missing
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Config Folder Writable
                        <span>
                            <?php if ($requirements['config_writable']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Make config/ writable
                            <?php endif; ?>
                        </span>
                    </li>
                </ul>

                <?php if ($requirements_passed): ?>
                    <div class="d-grid">
                        <a href="install.php?step=2" class="btn btn-primary btn-lg">Next: Configure Database <i class="fa-solid fa-arrow-right ms-2"></i></a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        Please resolve environment failures listed above to proceed.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- STEP 2: Database Configurations -->
            <?php if ($step === 2): ?>
                <h4 class="mb-3">Configure Database Connection</h4>
                <p class="text-muted">Enter database credentials. We will create the schema tables automatically.</p>
                
                <form action="install.php?step=2" method="POST" id="dbForm">
                    <div class="mb-3">
                        <label class="form-label">Database Host</label>
                        <input type="text" name="host" id="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" name="dbname" id="db_name" class="form-control" value="mamcet_placement" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port</label>
                            <input type="text" name="port" id="db_port" class="form-control" value="3306" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="user" id="db_user" class="form-control" value="root" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <input type="password" name="pass" id="db_pass" class="form-control">
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <button type="button" id="btnTestConnection" class="btn btn-outline-secondary w-100"><i class="fa-solid fa-plug me-2"></i> Test Link</button>
                        </div>
                        <div class="col-6">
                            <button type="submit" name="submit_step" value="2" class="btn btn-primary w-100">Next Step <i class="fa-solid fa-chevron-right ms-2"></i></button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <!-- STEP 3: Admin & Branding Configuration -->
            <?php if ($step === 3): ?>
                <h4 class="mb-3">Super Admin & Branding Setup</h4>
                <p class="text-muted">Configure default credentials, active AI API keys, and institutional branding details.</p>
                
                <form action="install.php?step=3" method="POST">
                    <!-- Admin credentials card -->
                    <div class="card shadow-sm border mb-4">
                        <div class="card-header bg-dark text-white p-3 font-weight-bold" style="font-size:1rem; text-align: left;">
                            <i class="fa-solid fa-user-shield me-2"></i> Super Admin Credentials
                        </div>
                        <div class="card-body p-3">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="admin_user" class="form-control" value="admin" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="admin_email" class="form-control" value="placement@mamcet.org" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="admin_pass" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" name="admin_pass_confirm" class="form-control" required minlength="6">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI API Configuration card -->
                    <div class="card shadow-sm border mb-4">
                        <div class="card-header bg-info text-white p-3 font-weight-bold" style="font-size:1rem; text-align: left;">
                            <i class="fa-solid fa-robot me-2"></i> AI API Configuration
                        </div>
                        <div class="card-body p-3">
                            <div class="mb-3">
                                <label class="form-label">Google Gemini API Key</label>
                                <input type="text" name="ai_api_key" class="form-control font-monospace" value="AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc" placeholder="Enter Gemini API key" required>
                                <small class="text-muted">A valid API Key is required to run automated resume evaluations, ATS scores, and mock interview preparations.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Branding details card -->
                    <div class="card shadow-sm border mb-4">
                        <div class="card-header bg-primary text-white p-3 font-weight-bold" style="font-size:1rem; text-align: left;">
                            <i class="fa-solid fa-graduation-cap me-2"></i> College Branding details
                        </div>
                        <div class="card-body p-3">
                            <div class="mb-3">
                                <label class="form-label">College Full Name</label>
                                <input type="text" name="college_name" class="form-control" value="M.A.M. College of Engineering and Technology" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Short Name / Code</label>
                                    <input type="text" name="college_short" class="form-control" value="MAMCET" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Portal System Name</label>
                                    <input type="text" name="portal_name" class="form-control" value="MAMCET Placement & Learning Portal" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Primary Color</label>
                                    <input type="color" name="primary_color" class="form-control form-control-color w-100" value="#0B3D91" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Secondary Color</label>
                                    <input type="color" name="secondary_color" class="form-control form-control-color w-100" value="#F4B400" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Official Font Preference</label>
                                    <select name="brand_font" class="form-select">
                                        <option value="Inter" selected>Inter (Recommended)</option>
                                        <option value="Roboto">Roboto</option>
                                        <option value="Segoe UI">Segoe UI</option>
                                        <option value="Outfit">Outfit</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Website URL</label>
                                    <input type="url" name="website_url" class="form-control" value="http://www.mamcet.org" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">College Logo Path</label>
                                    <input type="text" name="college_logo" class="form-control" value="assets/images/logo.png" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Placement Logo Path</label>
                                    <input type="text" name="placement_logo" class="form-control" value="assets/images/placement_logo.png" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Placement Cell Email</label>
                                    <input type="email" name="placement_email" class="form-control" value="placement@mamcet.org" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Placement Phone Number</label>
                                    <input type="text" name="placement_phone" class="form-control" value="0431-2650500" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">College Address</label>
                                <textarea name="college_address" class="form-control" rows="2" required>Trichy-Chennai Trunk Road, Siruganur, Trichy - 621105</textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" name="submit_step" value="3" class="btn btn-primary btn-lg">Next: Confirm & Run <i class="fa-solid fa-arrow-right ms-2"></i></button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- STEP 4: Confirm & Install Execution -->
            <?php if ($step === 4): ?>
                <h4 class="mb-3">Confirm and Run Setup</h4>
                <p class="text-muted">The system is ready to compile tables and database objects.</p>
                
                <div class="alert alert-info py-3 mb-4">
                    <strong>Tables to Create:</strong> 33 Normalised database schemas<br>
                    <strong>Seed Files:</strong> Seeding roles, sections, parameters, active academic session<br>
                    <strong>Excel Auto Scan:</strong> Search worksheets inside <code>data/</code> folder
                </div>
                
                <form action="install.php?step=4" method="POST">
                    <div class="d-grid gap-2">
                        <button type="submit" name="submit_step" value="4" class="btn btn-success btn-lg"><i class="fa-solid fa-circle-play me-2"></i> Run Installation</button>
                        <a href="install.php?step=3" class="btn btn-light">Go Back</a>
                    </div>
                </form>
            <?php endif; ?>

            <!-- STEP 5: Success & Redirect -->
            <?php if ($step === 5): ?>
                <div class="text-center py-4">
                    <i class="fa-solid fa-circle-check text-success fa-5x mb-4"></i>
                    <h3 class="mb-3">Setup Completed Successfully!</h3>
                    <p class="text-muted mb-4">The MAMCET placement portal is fully configured and the database has been constructed. Excel files inside the <code>data/</code> folder have been scanned.</p>
                    
                    <div class="alert alert-warning mb-4 text-start">
                        <strong>Security Reminder:</strong> For production hosting, please delete the <code>install.php</code> file from your server directory immediately.
                    </div>
                    
                    <div class="d-grid">
                        <a href="auth/officer-login.php" class="btn btn-primary btn-lg">Go to Login <i class="fa-solid fa-right-to-bracket ms-2"></i></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('#btnTestConnection').on('click', function() {
        const btn = $(this);
        const origText = btn.html();
        btn.html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Testing...').prop('disabled', true);
        
        $.ajax({
            url: 'install.php?action=test_db',
            type: 'POST',
            data: {
                host: $('#db_host').val(),
                port: $('#db_port').val(),
                dbname: $('#db_name').val(),
                user: $('#db_user').val(),
                pass: $('#db_pass').val()
            },
            dataType: 'json',
            success: function(response) {
                btn.html(origText).prop('disabled', false);
                if (response.success) {
                    alert(response.message);
                } else {
                    alert(response.message);
                }
            },
            error: function(xhr, status, error) {
                btn.html(origText).prop('disabled', false);
                alert("Connection failed with script error: " + error);
            }
        });
    });
});
</script>

</body>
</html>
