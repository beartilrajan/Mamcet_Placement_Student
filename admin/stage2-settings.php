<?php
// MAMCET Placement & Learning Portal - Stage 2 Settings & Migration Installer
require_once(__DIR__ . '/../includes/header.php');

// Restrict access to Super Admin only
if ($roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Check if Stage 2 is installed by querying a Stage 2 specific table
$tablesInstalled = false;
try {
    $stmtCheck = $db->query("SHOW TABLES LIKE 'ai_providers'");
    $tablesInstalled = $stmtCheck->rowCount() > 0;
} catch (Exception $e) {
    $tablesInstalled = false;
}

// 1. Process Database Migration Installer Request
if (isset($_POST['run_migration'])) {
    try {
        $migrationFile = __DIR__ . '/../database/migrations/stage2.sql';
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file 'database/migrations/stage2.sql' is missing.");
        }

        $sql = file_get_contents($migrationFile);
        
        // Execute migration SQL queries
        $db->exec($sql);
        
        // Populate default prompt templates and AI settings
        $db->exec("INSERT IGNORE INTO ai_providers (provider_id, provider_name, is_active) VALUES 
            (1, 'gemini', 1),
            (2, 'openai', 0)
        ");
        
        $db->exec("INSERT IGNORE INTO ai_settings (setting_id, provider_id, model_name, api_key, temperature, max_tokens) VALUES 
            (1, 1, 'gemini-1.5-flash', 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc', 0.20, 2048),
            (2, 2, 'gpt-4o-mini', '', 0.20, 2048)
        ");

        $db->exec("INSERT IGNORE INTO prompt_templates (template_id, template_name, system_prompt, user_prompt_format) VALUES 
            (1, 'ats_analysis', 'You are a professional ATS resume analyzer for placement services.', 'Please analyze the following extracted resume text...'),
            (2, 'job_matching', 'You are an ATS Match Engine. Compare the candidate\'s resume and the job description.', 'Compare resume against job...')
        ");

        $tablesInstalled = true;
        $message = "Stage 2 database migrations successfully completed! All tables, indexes, and initial records are ready.";
    } catch (Exception $e) {
        $error = "Migration Failed: " . $e->getMessage();
    }
}

// 2. Process Configuration Saving Request
if (isset($_POST['save_config']) && $tablesInstalled) {
    try {
        $activeProvider = $_POST['active_provider'];
        
        // Validate active provider in DB
        $db->prepare("UPDATE ai_providers SET is_active = CASE WHEN provider_name = ? THEN 1 ELSE 0 END")->execute([$activeProvider]);

        // Save Gemini settings
        $geminiKey = $_POST['gemini_api_key'];
        // If the key is redacted, do not overwrite the existing key
        if (strpos($geminiKey, '••••') !== false) {
            $stmtGet = $db->prepare("SELECT api_key FROM ai_settings WHERE provider_id = 1");
            $stmtGet->execute();
            $geminiKey = $stmtGet->fetchColumn() ?: 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc';
        }
        $stmtGemini = $db->prepare("
            UPDATE ai_settings 
            SET model_name = ?, api_key = ?, temperature = ?, max_tokens = ? 
            WHERE provider_id = 1
        ");
        $stmtGemini->execute([
            $_POST['gemini_model'],
            $geminiKey,
            (float)$_POST['gemini_temp'],
            (int)$_POST['gemini_max_tokens']
        ]);

        // Save OpenAI settings
        $openaiKey = $_POST['openai_api_key'];
        if (strpos($openaiKey, '••••') !== false) {
            $stmtGet = $db->prepare("SELECT api_key FROM ai_settings WHERE provider_id = 2");
            $stmtGet->execute();
            $openaiKey = $stmtGet->fetchColumn();
        }
        $stmtOpenai = $db->prepare("
            UPDATE ai_settings 
            SET model_name = ?, api_key = ?, temperature = ?, max_tokens = ? 
            WHERE provider_id = 2
        ");
        $stmtOpenai->execute([
            $_POST['openai_model'],
            $openaiKey,
            (float)$_POST['openai_temp'],
            (int)$_POST['openai_max_tokens']
        ]);

        // Update stage2 config file parameters dynamically
        $configPath = __DIR__ . '/../config/stage2.php';
        if (file_exists($configPath)) {
            $configData = [
                'installed' => true,
                'ai' => [
                    'active_provider' => $activeProvider,
                    'gemini' => [
                        'api_key' => $geminiKey,
                        'model_name' => $_POST['gemini_model'],
                        'api_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/',
                        'temperature' => (float)$_POST['gemini_temp'],
                        'max_tokens' => (int)$_POST['gemini_max_tokens'],
                    ],
                    'openai' => [
                        'api_key' => $openaiKey,
                        'model_name' => $_POST['openai_model'],
                        'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
                        'temperature' => (float)$_POST['openai_temp'],
                        'max_tokens' => (int)$_POST['openai_max_tokens'],
                    ],
                    'limits' => [
                        'daily_limit_per_student' => (int)$_POST['daily_limit'],
                        'monthly_limit_per_student' => (int)$_POST['monthly_limit'],
                        'cooldown_seconds' => (int)$_POST['cooldown_seconds']
                    ]
                ],
                'smtp' => [
                    'host' => $_POST['smtp_host'],
                    'port' => (int)$_POST['smtp_port'],
                    'encryption' => $_POST['smtp_encryption'],
                    'username' => $_POST['smtp_username'],
                    'password' => $_POST['smtp_password'],
                    'sender_name' => $_POST['smtp_sender_name'],
                    'sender_email' => $_POST['smtp_sender_email'],
                    'batch_size' => (int)$_POST['smtp_batch_size']
                ],
                'whatsapp' => [
                    'provider' => 'click_to_chat',
                    'webhook_url' => '',
                    'api_token' => '',
                    'phone_number_id' => ''
                ],
                'modules' => [
                    'resume_management' => isset($_POST['mod_resume']) ? true : false,
                    'ats_analysis' => isset($_POST['mod_ats']) ? true : false,
                    'resume_builder' => isset($_POST['mod_builder']) ? true : false,
                    'job_matching' => isset($_POST['mod_match']) ? true : false,
                    'interview_preparation' => isset($_POST['mod_prep']) ? true : false,
                    'mock_interview' => isset($_POST['mod_mock']) ? true : false,
                    'eligibility_automation' => isset($_POST['mod_eligibility']) ? true : false,
                    'application_tracking' => isset($_POST['mod_tracking']) ? true : false,
                    'notifications' => isset($_POST['mod_notifications']) ? true : false,
                    'advanced_reports' => isset($_POST['mod_reports']) ? true : false
                ],
                'resume' => [
                    'max_file_size_mb' => (int)$_POST['max_file_size'],
                    'allowed_types' => ['pdf', 'docx']
                ]
            ];
            
            // Format configuration string
            $configText = "<?php\nreturn " . var_export($configData, true) . ";\n";
            file_put_contents($configPath, $configText);
        }

        $message = "Configurations successfully updated and synced with server storage.";
    } catch (Exception $e) {
        $error = "Failed to update configs: " . $e->getMessage();
    }
}

// 3. Load configurations from database & file
$activeProvider = 'gemini';
$gemini = ['model_name' => 'gemini-1.5-flash', 'api_key' => '', 'temperature' => 0.2, 'max_tokens' => 2048];
$openai = ['model_name' => 'gpt-4o-mini', 'api_key' => '', 'temperature' => 0.2, 'max_tokens' => 2048];
$limits = ['daily_limit_per_student' => 3, 'monthly_limit_per_student' => 10, 'cooldown_seconds' => 60];
$smtp = ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'sender_name' => '', 'sender_email' => '', 'batch_size' => 25];
$modules = ['resume_management' => true, 'ats_analysis' => true, 'resume_builder' => true, 'job_matching' => true, 'interview_preparation' => true, 'mock_interview' => true, 'eligibility_automation' => true, 'application_tracking' => true, 'notifications' => true, 'advanced_reports' => true];
$maxFileSize = 5;

if ($tablesInstalled) {
    try {
        $stmtAct = $db->query("SELECT provider_name FROM ai_providers WHERE is_active = 1 LIMIT 1");
        $activeProvider = $stmtAct->fetchColumn() ?: 'gemini';

        $stmtSet = $db->query("SELECT * FROM ai_settings");
        $settings = $stmtSet->fetchAll();
        foreach ($settings as $s) {
            if ($s['provider_id'] == 1) {
                $gemini = $s;
            } elseif ($s['provider_id'] == 2) {
                $openai = $s;
            }
        }
    } catch (Exception $e) {
        // Silently skip
    }

    // Load dynamic parameters from file
    $configFile = __DIR__ . '/../config/stage2.php';
    if (file_exists($configFile)) {
        $fileConfig = require($configFile);
        $limits = $fileConfig['ai']['limits'] ?? $limits;
        $smtp = $fileConfig['smtp'] ?? $smtp;
        $modules = $fileConfig['modules'] ?? $modules;
        $maxFileSize = $fileConfig['resume']['max_file_size_mb'] ?? $maxFileSize;
    }
}

// Helper to redact key
function redactApiKey(?string $key): string {
    if (empty($key)) return '';
    if (strlen($key) < 8) return '••••••••';
    return substr($key, 0, 4) . str_repeat('•', 12) . substr($key, -4);
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-gears text-primary"></i> Stage 2 Portal Settings</h1>
                <span class="badge <?php echo $tablesInstalled ? 'bg-success' : 'bg-warning'; ?> p-2">
                    <?php echo $tablesInstalled ? 'Database Migration Installed' : 'Action Required: Database Schema Offline'; ?>
                </span>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$tablesInstalled): ?>
                <!-- SCHEMA INSTALLATION MODAL BLOCK -->
                <div class="card shadow border-left-warning mb-4">
                    <div class="card-body">
                        <h5 class="text-warning"><i class="fa-solid fa-database"></i> Database Migration Installer Required</h5>
                        <p>The Stage 2 database structure and normalized tables (totaling 39 analytics, scoring, application, and notification tables) are currently not configured. Click the button below to migrate the schema safely.</p>
                        <form method="POST">
                            <button type="submit" name="run_migration" class="btn btn-warning btn-icon-split">
                                <span class="icon text-white-50"><i class="fa-solid fa-upload"></i></span>
                                <span class="text">Execute Database Schema Migration (stage2.sql)</span>
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- CONFIGURATIONS PANEL -->
                <form method="POST">
                    <div class="row">
                        <!-- Left column: AI Configurations -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-robot"></i> AI Engine Providers Config</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label font-weight-bold">Active AI Service Provider</label>
                                        <select class="form-select" name="active_provider" id="activeProvider">
                                            <option value="gemini" <?php echo $activeProvider === 'gemini' ? 'selected' : ''; ?>>Google Gemini API (Recommended)</option>
                                            <option value="openai" <?php echo $activeProvider === 'openai' ? 'selected' : ''; ?>>OpenAI Chat Completion API</option>
                                        </select>
                                    </div>

                                    <hr>

                                    <!-- Gemini Parameters -->
                                    <div class="ai-provider-settings" id="geminiSettings">
                                        <h6 class="text-primary font-weight-bold">Google Gemini API Configuration</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Model Name</label>
                                            <input type="text" class="form-control" name="gemini_model" value="<?php echo htmlspecialchars($gemini['model_name'] ?? 'gemini-1.5-flash'); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">API Key</label>
                                            <input type="text" class="form-control font-monospace" name="gemini_api_key" value="<?php echo htmlspecialchars(redactApiKey($gemini['api_key'] ?? '')); ?>" placeholder="Enter Gemini Key">
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Temperature</label>
                                                <input type="number" step="0.1" max="1" min="0" class="form-control" name="gemini_temp" value="<?php echo (float)($gemini['temperature'] ?? 0.2); ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Max Output Tokens</label>
                                                <input type="number" class="form-control" name="gemini_max_tokens" value="<?php echo (int)($gemini['max_tokens'] ?? 2048); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <!-- OpenAI Parameters -->
                                    <div class="ai-provider-settings" id="openaiSettings">
                                        <h6 class="text-secondary font-weight-bold">OpenAI GPT Configuration</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Model Name</label>
                                            <input type="text" class="form-control" name="openai_model" value="<?php echo htmlspecialchars($openai['model_name'] ?? 'gpt-4o-mini'); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">API Key</label>
                                            <input type="text" class="form-control font-monospace" name="openai_api_key" value="<?php echo htmlspecialchars(redactApiKey($openai['api_key'] ?? '')); ?>" placeholder="Enter OpenAI Key sk-...">
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Temperature</label>
                                                <input type="number" step="0.1" max="1" min="0" class="form-control" name="openai_temp" value="<?php echo (float)($openai['temperature'] ?? 0.2); ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Max Tokens</label>
                                                <input type="number" class="form-control" name="openai_max_tokens" value="<?php echo (int)($openai['max_tokens'] ?? 2048); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <h6 class="text-dark font-weight-bold">AI Usage & Rate Limits (Per Student)</h6>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Daily Limit</label>
                                            <input type="number" class="form-control" name="daily_limit" value="<?php echo (int)($limits['daily_limit_per_student'] ?? 3); ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Monthly Limit</label>
                                            <input type="number" class="form-control" name="monthly_limit" value="<?php echo (int)($limits['monthly_limit_per_student'] ?? 10); ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Cooldown (Seconds)</label>
                                            <input type="number" class="form-control" name="cooldown_seconds" value="<?php echo (int)($limits['cooldown_seconds'] ?? 60); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Email SMTP Configurations -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-secondary text-white">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-envelope"></i> SMTP Email Queue Settings</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">SMTP Host</label>
                                            <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($smtp['host'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">SMTP Port</label>
                                            <input type="number" class="form-control" name="smtp_port" value="<?php echo (int)($smtp['port'] ?? 587); ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Encryption</label>
                                            <select class="form-select" name="smtp_encryption">
                                                <option value="tls" <?php echo ($smtp['encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                <option value="ssl" <?php echo ($smtp['encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                <option value="none" <?php echo ($smtp['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">SMTP Username</label>
                                            <input type="text" class="form-control" name="smtp_username" value="<?php echo htmlspecialchars($smtp['username'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">SMTP Password</label>
                                            <input type="password" class="form-control" name="smtp_password" value="<?php echo htmlspecialchars($smtp['password'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Sender Name</label>
                                            <input type="text" class="form-control" name="smtp_sender_name" value="<?php echo htmlspecialchars($smtp['sender_name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Sender Email</label>
                                            <input type="email" class="form-control" name="smtp_sender_email" value="<?php echo htmlspecialchars($smtp['sender_email'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Batch Process Size (Emails per cycle)</label>
                                        <input type="number" class="form-control" name="smtp_batch_size" value="<?php echo (int)($smtp['batch_size'] ?? 25); ?>">
                                        <small class="text-muted">Recommended: 20 to 50 to avoid Hostinger PHP timeouts.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right column: Modules Toggles -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-info text-white">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-cubes"></i> Enabled Modules</h6>
                                </div>
                                <div class="card-body">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_resume" id="modResume" <?php echo ($modules['resume_management'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modResume">Resume Upload Manager</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_ats" id="modAts" <?php echo ($modules['ats_analysis'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modAts">ATS Score Estimator</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_builder" id="modBuilder" <?php echo ($modules['resume_builder'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modBuilder">ATS Resume Builder</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_match" id="modMatch" <?php echo ($modules['job_matching'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modMatch">Job Description Match</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_prep" id="modPrep" <?php echo ($modules['interview_preparation'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modPrep">HR Q&A Question Bank</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_mock" id="modMock" <?php echo ($modules['mock_interview'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modMock">AI Mock Interview Chat</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_eligibility" id="modElig" <?php echo ($modules['eligibility_automation'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modElig">Eligibility Auto Engine</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_tracking" id="modTrack" <?php echo ($modules['application_tracking'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modTrack">Recruitment Tracking</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_notifications" id="modNotif" <?php echo ($modules['notifications'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modNotif">Multi-Channel Alerts</label>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="mod_reports" id="modRep" <?php echo ($modules['advanced_reports'] ?? true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label font-weight-bold" for="modRep">Advanced Placement Reports</label>
                                    </div>

                                    <hr>

                                    <h6 class="text-dark font-weight-bold">File Upload Limitations</h6>
                                    <div class="mb-3">
                                        <label class="form-label">Max Resume File Size (MB)</label>
                                        <input type="number" class="form-control" name="max_file_size" value="<?php echo (int)$maxFileSize; ?>">
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="save_config" class="btn btn-success btn-block py-2 font-weight-bold shadow-sm mb-4">
                                <i class="fa-solid fa-floppy-disk"></i> Save Portal Configuration
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
