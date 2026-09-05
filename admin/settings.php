<?php
// MAMCET Placement & Learning Portal - System Settings & Configurations Page (Senior UI/UX Redesign)

$pageTitle = 'Portal Configurations';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle Settings Update / Sessions CRUD / Officer Registrations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'update_settings') {
        $minCgpa = !empty($_POST['min_cgpa']) ? (float)$_POST['min_cgpa'] : 6.00;
        $maxArrears = !empty($_POST['max_standing_arrears']) ? (int)$_POST['max_standing_arrears'] : 0;
        $alertPct = !empty($_POST['completion_alert_threshold']) ? (int)$_POST['completion_alert_threshold'] : 80;
        
        try {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'min_cgpa'");
            $stmt->execute([$minCgpa]);
            
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'max_standing_arrears'");
            $stmt->execute([$maxArrears]);
            
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'completion_alert_threshold'");
            $stmt->execute([$alertPct]);
            
            $success = 'System parameters updated successfully.';
            logActivity($db, $_SESSION['user_id'], 'Update Settings', 'Modified default eligibility metrics.');
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } elseif ($action === 'create_session') {
        if ($_SESSION['role_id'] !== ROLE_SUPER_ADMIN) {
            $error = 'Unauthorized operation.';
        } else {
            $sessionName = trim($_POST['session_name'] ?? '');
            $startYear = (int)($_POST['start_year'] ?? date('Y'));
            $endYear = (int)($_POST['end_year'] ?? ($startYear + 1));
            
            if (empty($sessionName)) {
                $error = 'Session name is required.';
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO academic_sessions (session_name, start_year, end_year, is_active) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$sessionName, $startYear, $endYear]);
                    $success = "Academic Session '{$sessionName}' created successfully.";
                    logActivity($db, $_SESSION['user_id'], 'Create Academic Session', "Created session $sessionName");
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'set_default_session') {
        if ($_SESSION['role_id'] !== ROLE_SUPER_ADMIN) {
            $error = 'Unauthorized operation.';
        } else {
            $sessId = (int)($_POST['session_id'] ?? 0);
            if ($sessId > 0) {
                try {
                    $db->beginTransaction();
                    $db->query("UPDATE academic_sessions SET is_active = 0");
                    $stmt = $db->prepare("UPDATE academic_sessions SET is_active = 1 WHERE session_id = ?");
                    $stmt->execute([$sessId]);
                    $db->commit();
                    
                    // Update active session name in actual session cache
                    $stmtSName = $db->prepare("SELECT session_name FROM academic_sessions WHERE session_id = ?");
                    $stmtSName->execute([$sessId]);
                    $_SESSION['active_session_name'] = $stmtSName->fetchColumn();
                    $_SESSION['active_session_id'] = $sessId;
                    
                    $success = 'Default system session updated successfully.';
                    logActivity($db, $_SESSION['user_id'], 'Set Default Session', "Updated active session ID to $sessId");
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_session') {
        if ($_SESSION['role_id'] !== ROLE_SUPER_ADMIN) {
            $error = 'Unauthorized operation.';
        } else {
            $sessId = (int)($_POST['session_id'] ?? 0);
            if ($sessId > 0) {
                try {
                    $stmt = $db->prepare("SELECT session_name, is_active FROM academic_sessions WHERE session_id = ?");
                    $stmt->execute([$sessId]);
                    $target = $stmt->fetch();

                    if (!$target) {
                        $error = 'Session not found.';
                    } elseif ($target['is_active'] == 1) {
                        $error = 'Cannot delete the default active session.';
                    } else {
                        $chkImports = $db->prepare("SELECT COUNT(*) FROM excel_imports WHERE session_id = ?");
                        $chkImports->execute([$sessId]);
                        if ($chkImports->fetchColumn() > 0) {
                            $error = 'Cannot delete session because imported student dataset files are linked to it.';
                        } else {
                            $delStmt = $db->prepare("DELETE FROM academic_sessions WHERE session_id = ?");
                            $delStmt->execute([$sessId]);
                            $success = "Academic session '{$target['session_name']}' deleted successfully.";
                            logActivity($db, $_SESSION['user_id'], 'Delete Session', "Deleted session {$target['session_name']} (ID: $sessId)");
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Failed to delete session: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'create_officer') {
        if ($_SESSION['role_id'] !== ROLE_SUPER_ADMIN) {
            $error = 'Unauthorized operation.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $mobile = trim($_POST['mobile_number'] ?? '');
            $deptId = (int)($_POST['dept_id'] ?? 0);
            
            if (empty($username) || empty($password) || empty($name) || empty($email)) {
                $error = 'Username, Password, Name, and Email are required.';
            } else {
                try {
                    $chk = $db->prepare("SELECT user_id FROM users WHERE username = ?");
                    $chk->execute([$username]);
                    if ($chk->fetch()) {
                        $error = 'Username already registered. Please choose another username.';
                    } else {
                        $db->beginTransaction();
                        
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmtU = $db->prepare("INSERT INTO users (username, password_hash, role_id, status) VALUES (?, ?, ?, 'active')");
                        $stmtU->execute([$username, $hash, ROLE_PLACEMENT_OFFICER]);
                        $newUserId = $db->lastInsertId();
                        
                        $stmtO = $db->prepare("INSERT INTO placement_officers (user_id, name, email, mobile_number, dept_id) VALUES (?, ?, ?, ?, ?)");
                        $stmtO->execute([$newUserId, $name, $email, $mobile, $deptId ?: null]);
                        
                        $db->commit();
                        $success = "Placement Officer account for '{$name}' registered successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Register Officer', "Created officer account $username ($name)");
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch system parameters
$settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch academic sessions
$sessionsList = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC")->fetchAll();

// Fetch placement officers list
$officersList = $db->query("
    SELECT po.*, u.username, u.status, d.dept_code, d.dept_name
    FROM placement_officers po
    JOIN users u ON po.user_id = u.user_id
    LEFT JOIN departments d ON po.dept_id = d.dept_id
    ORDER BY po.name ASC
")->fetchAll();

// Departments list
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();

// Active Session Info
$activeSessionId = getActiveAcademicSessionId();
$activeSessionName = '2024–2025';
foreach ($sessionsList as $s) {
    if ($s['is_active'] == 1) {
        $activeSessionName = $s['session_name'];
        break;
    }
}

$defaultMinCgpa = (float)($settings['min_cgpa'] ?? 6.00);
$defaultMaxArrears = (int)($settings['max_standing_arrears'] ?? 0);
$defaultTargetPct = (int)($settings['completion_alert_threshold'] ?? 80);
$activeTab = $_GET['tab'] ?? 'eligibility';
?>

<style>
/* Modern Settings Styling */
.settings-kpi-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}
.settings-kpi-item {
    flex: 1;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.settings-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.settings-kpi-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.settings-kpi-value {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}

/* Tab Navigation */
.settings-nav-tabs {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    gap: 2px;
    border: 1px solid #e2e8f0;
    margin-bottom: 16px;
    max-width: 100%;
    overflow-x: auto;
}
.settings-nav-link {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.settings-nav-link:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.6);
}
.settings-nav-link.active {
    background: #ffffff;
    color: #0b3d91;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* Card Sections */
.settings-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.settings-card-header {
    background: #f8fafc;
    padding: 12px 18px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.settings-card-body {
    padding: 18px;
}

/* Table Enhancements */
.settings-table thead th {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.settings-table tbody td {
    padding: 10px 14px;
    font-size: 0.84rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}
.settings-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Avatar Initial */
.officer-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #e0e7ff;
    color: #3730a3;
    font-weight: 700;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
</style>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container py-3">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-circle-check me-1"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h4 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-sliders text-primary me-2"></i> System & Placement Settings
                </h4>
                <p class="text-muted small mb-0 mt-1">Configure campus recruitment policies, minimum criteria, academic years, and access permissions.</p>
            </div>
            <div>
                <span class="badge bg-light text-primary border px-2 py-1 small fw-semibold">
                    <i class="fa-solid fa-shield-halved me-1"></i> <?php echo $_SESSION['role_id'] === ROLE_SUPER_ADMIN ? 'Super Admin Mode' : 'Officer Mode'; ?>
                </span>
            </div>
        </div>

        <!-- Executive KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="settings-kpi-bar">
            <div class="settings-kpi-item">
                <div class="settings-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div>
                    <div class="settings-kpi-label">Active Default Session</div>
                    <div class="settings-kpi-value text-primary"><?php echo esc($activeSessionName); ?></div>
                </div>
            </div>

            <div class="settings-kpi-item">
                <div class="settings-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div>
                    <div class="settings-kpi-label">Default CGPA Cutoff</div>
                    <div class="settings-kpi-value text-success">≥ <?php echo number_format($defaultMinCgpa, 2); ?></div>
                </div>
            </div>

            <div class="settings-kpi-item">
                <div class="settings-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-circle-exclamation"></i>
                </div>
                <div>
                    <div class="settings-kpi-label">Max Arrears Policy</div>
                    <div class="settings-kpi-value text-dark"><?php echo $defaultMaxArrears === 0 ? '0 Backlogs (Strict)' : '≤ ' . $defaultMaxArrears; ?></div>
                </div>
            </div>

            <div class="settings-kpi-item">
                <div class="settings-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="settings-kpi-label">Placement Officers</div>
                    <div class="settings-kpi-value text-info"><?php echo count($officersList); ?> Staff</div>
                </div>
            </div>
        </div>

        <!-- Segmented Tab Navigation -->
        <div class="settings-nav-tabs">
            <a href="settings.php?tab=eligibility" class="settings-nav-link <?php echo $activeTab === 'eligibility' ? 'active' : ''; ?>">
                <i class="fa-solid fa-bullseye text-primary"></i> Eligibility & Policy Thresholds
            </a>
            <a href="settings.php?tab=sessions" class="settings-nav-link <?php echo $activeTab === 'sessions' ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-days text-success"></i> Academic Sessions Manager (<?php echo count($sessionsList); ?>)
            </a>
            <a href="settings.php?tab=officers" class="settings-nav-link <?php echo $activeTab === 'officers' ? 'active' : ''; ?>">
                <i class="fa-solid fa-users-gear text-info"></i> Officer Team & Scopes (<?php echo count($officersList); ?>)
            </a>
            <a href="settings.php?tab=diagnostics" class="settings-nav-link <?php echo $activeTab === 'diagnostics' ? 'active' : ''; ?>">
                <i class="fa-solid fa-server text-secondary"></i> System Diagnostics
            </a>
        </div>

        <!-- TAB CONTENT -->

        <!-- TAB 1: Eligibility & Policy Parameters -->
        <?php if ($activeTab === 'eligibility'): ?>
            <div class="row g-3">
                <div class="col-lg-7 col-12">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-sliders text-primary me-2"></i> Placement Policy Default Parameters
                            </h6>
                            <span class="badge bg-light text-secondary border small">Global Defaults</span>
                        </div>
                        <div class="settings-card-body">
                            <form action="settings.php?tab=eligibility" method="POST">
                                <?php csrfInput(); ?>
                                <input type="hidden" name="action" value="update_settings">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-dark mb-1">1. Default Minimum CGPA Threshold</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-graduation-cap"></i></span>
                                        <input type="number" step="0.01" min="0" max="10" name="min_cgpa" id="inputCgpa" class="form-control" value="<?php echo esc($settings['min_cgpa'] ?? '6.00'); ?>" required>
                                        <span class="input-group-text bg-light text-muted">/ 10.00</span>
                                    </div>
                                    <div class="form-text small mt-1">Default minimum cumulative grade point average applied when creating new campus recruitment drives.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-dark mb-1">2. Default Maximum Standing Arrears Limit</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-ban"></i></span>
                                        <input type="number" min="0" max="20" name="max_standing_arrears" class="form-control" value="<?php echo (int)($settings['max_standing_arrears'] ?? 0); ?>" required>
                                        <span class="input-group-text bg-light text-muted">Active Arrears</span>
                                    </div>
                                    <div class="form-text small mt-1">Maximum active backlogs allowed for standard drive eligibility (Set to 0 for strict all-clear policy).</div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label small fw-bold text-dark mb-0">3. Profile Completion Target Requirement</label>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="targetBadge"><?php echo (int)($settings['completion_alert_threshold'] ?? 80); ?>% Completion</span>
                                    </div>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-chart-pie"></i></span>
                                        <input type="number" min="10" max="100" name="completion_alert_threshold" id="inputTarget" class="form-control" value="<?php echo (int)($settings['completion_alert_threshold'] ?? 80); ?>" required oninput="$('#targetBadge').text(this.value + '% Completion')">
                                        <span class="input-group-text bg-light text-muted">% Minimum</span>
                                    </div>
                                    <div class="form-text small mt-1">Student profiles below this completeness index will show action warning flags on their student dashboards.</div>
                                </div>
                                
                                <button type="submit" class="btn btn-sm btn-primary px-4 shadow-sm">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Policy Parameters
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 col-12">
                    <div class="settings-card h-100">
                        <div class="settings-card-header">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-circle-info text-info me-2"></i> Policy Enforcement Guide
                            </h6>
                        </div>
                        <div class="settings-card-body">
                            <div class="p-3 bg-light rounded border mb-3">
                                <h6 class="fw-bold small text-dark mb-1"><i class="fa-solid fa-filter text-primary me-1"></i> Automated Filtering</h6>
                                <p class="small text-muted mb-0">When an officer posts a Placement Drive, these parameters serve as initial preset defaults. Individual drives can override these cutoffs anytime.</p>
                            </div>
                            <div class="p-3 bg-light rounded border mb-3">
                                <h6 class="fw-bold small text-dark mb-1"><i class="fa-solid fa-user-check text-success me-1"></i> Student Willingness Rule</h6>
                                <p class="small text-muted mb-0">Students marked as <em>Unwilling for Placement</em> are excluded automatically from tier-1 company shortlists regardless of CGPA.</p>
                            </div>
                            <div class="p-3 bg-light rounded border">
                                <h6 class="fw-bold small text-dark mb-1"><i class="fa-solid fa-file-excel text-success me-1"></i> Data Import Policy</h6>
                                <p class="small text-muted mb-0">Whenever new academic marks or student roster sheets are imported via Excel Student Import, student CGPAs and arrear counts update instantly.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 2: Academic Sessions Lifecycle Manager -->
        <?php if ($activeTab === 'sessions'): ?>
            <div class="settings-card">
                <div class="settings-card-header">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-calendar-days text-success me-2"></i> Academic Sessions & Cycles
                        </h6>
                        <small class="text-muted">Manage multi-year academic sessions and switch the active default system year.</small>
                    </div>
                    <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                        <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#sessionModal">
                            <i class="fa-solid fa-plus me-1"></i> Add Academic Session
                        </button>
                    <?php endif; ?>
                </div>
                <div class="settings-card-body p-0">
                    <div class="table-responsive">
                        <table class="table settings-table align-middle mb-0" id="sessionsTable">
                            <thead>
                                <tr>
                                    <th>Session Name</th>
                                    <th>Academic Span Year</th>
                                    <th>Status & System Default</th>
                                    <th class="text-end" width="140">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessionsList as $s): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="settings-kpi-icon <?php echo $s['is_active'] == 1 ? 'bg-success-subtle text-success' : 'bg-light text-secondary'; ?>">
                                                    <i class="fa-solid fa-calendar"></i>
                                                </div>
                                                <div>
                                                    <strong class="text-dark d-block"><?php echo esc($s['session_name']); ?></strong>
                                                    <small class="text-muted" style="font-size: 0.72rem;">ID: #<?php echo $s['session_id']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <?php echo $s['start_year'] . ' – ' . $s['end_year']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($s['is_active'] == 1): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Active Default System
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border px-2 py-1">
                                                    ⚪ Archived / Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                                                <?php if ($s['is_active'] != 1): ?>
                                                    <form action="settings.php?tab=sessions" method="POST" class="d-inline">
                                                        <?php csrfInput(); ?>
                                                        <input type="hidden" name="action" value="set_default_session">
                                                        <input type="hidden" name="session_id" value="<?php echo $s['session_id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:0.75rem;" title="Make this the active system session">
                                                            <i class="fa-solid fa-star me-1"></i> Set Default
                                                        </button>
                                                    </form>
                                                    <form action="settings.php?tab=sessions" method="POST" class="d-inline ms-1" onsubmit="return confirm('Permanently remove session <?php echo esc(addslashes($s['session_name'])); ?>?');">
                                                        <?php csrfInput(); ?>
                                                        <input type="hidden" name="action" value="delete_session">
                                                        <input type="hidden" name="session_id" value="<?php echo $s['session_id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-light border text-danger py-0 px-2" style="font-size:0.75rem;" title="Delete Session">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border" style="font-size:0.7rem;">Primary Lock</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Read Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 3: Placement Officers Registry & Department Scopes -->
        <?php if ($activeTab === 'officers'): ?>
            <div class="settings-card">
                <div class="settings-card-header">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-users-gear text-info me-2"></i> Placement Officers Team & Delegations
                        </h6>
                        <small class="text-muted">Staff accounts with administrative placement and circular management privileges.</small>
                    </div>
                    <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                        <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#officerModal">
                            <i class="fa-solid fa-user-plus me-1"></i> Register New Officer
                        </button>
                    <?php endif; ?>
                </div>
                <div class="settings-card-body p-0">
                    <div class="table-responsive">
                        <table class="table settings-table align-middle mb-0" id="officersTable">
                            <thead>
                                <tr>
                                    <th>Officer Profile</th>
                                    <th>Login Username</th>
                                    <th>Contact Information</th>
                                    <th>Department Assignment</th>
                                    <th>Account Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officersList as $o): 
                                    $initials = strtoupper(substr($o['name'], 0, 2));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="officer-avatar"><?php echo $initials; ?></div>
                                                <div>
                                                    <strong class="text-dark d-block"><?php echo esc($o['name']); ?></strong>
                                                    <small class="text-muted" style="font-size: 0.72rem;">Officer ID: #<?php echo $o['officer_id']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-primary border px-2 py-1 font-monospace" style="font-size: 0.78rem;">
                                                <?php echo esc($o['username']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div>
                                                <a href="mailto:<?php echo esc($o['email']); ?>" class="text-decoration-none text-dark small d-block">
                                                    <i class="fa-regular fa-envelope text-secondary me-1"></i> <?php echo esc($o['email']); ?>
                                                </a>
                                                <?php if (!empty($o['mobile_number'])): ?>
                                                    <span class="text-muted small" style="font-size: 0.74rem;">
                                                        <i class="fa-solid fa-phone text-secondary me-1"></i> <?php echo esc($o['mobile_number']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($o['dept_code'])): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                                    <i class="fa-solid fa-building-user me-1"></i> <?php echo esc($o['dept_code']); ?> Scoped
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="fa-solid fa-globe me-1"></i> Global Access (All Depts)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($o['status'] === 'active'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    ● Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                    ● Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 4: System Diagnostics & Server Environment -->
        <?php if ($activeTab === 'diagnostics'): ?>
            <div class="row g-3">
                <div class="col-md-6 col-12">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-server text-primary me-2"></i> Server & Database Environment</h6>
                        </div>
                        <div class="settings-card-body p-0">
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">PHP Engine Runtime</span>
                                    <strong>PHP <?php echo phpversion(); ?></strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Database Server PDO</span>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Connected (MySQL)</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Server Software</span>
                                    <strong class="text-truncate" style="max-width: 250px;"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Web Server'; ?></strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Default Timezone</span>
                                    <strong><?php echo date_default_timezone_get(); ?> (<?php echo date('d M Y H:i'); ?>)</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">File Upload Limit</span>
                                    <strong><?php echo ini_get('upload_max_filesize'); ?> (Post: <?php echo ini_get('post_max_size'); ?>)</strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-shield-halved text-success me-2"></i> Security & Session Controls</h6>
                        </div>
                        <div class="settings-card-body p-0">
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">CSRF Token Verification</span>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Enforced on all POSTs</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Session Cookie Strictness</span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">HttpOnly • Lax SameSite</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Password Encryption Algo</span>
                                    <strong>BCRYPT / PASSWORD_DEFAULT</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="text-muted">Active Session SessionID</span>
                                    <span class="font-monospace text-muted"><?php echo session_id(); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- CREATE ACADEMIC SESSION MODAL -->
<?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
    <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-dark border-0 shadow">
                <form action="settings.php?tab=sessions" method="POST">
                    <?php csrfInput(); ?>
                    <input type="hidden" name="action" value="create_session">
                    
                    <div class="modal-header py-2 px-3 bg-light border-bottom">
                        <h6 class="modal-title fw-bold" id="sessionModalLabel">
                            <i class="fa-solid fa-calendar-plus text-primary me-2"></i> Create Academic Session
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark mb-1">Session Name / Label</label>
                            <input type="text" name="session_name" class="form-control form-control-sm" placeholder="e.g. 2026 - 2027" required>
                            <div class="form-text small">Standard academic year cycle format.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-dark mb-1">Start Year</label>
                                <input type="number" name="start_year" class="form-control form-control-sm" value="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-dark mb-1">End Year</label>
                                <input type="number" name="end_year" class="form-control form-control-sm" value="<?php echo date('Y') + 1; ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2 px-3 bg-light border-top">
                        <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i> Create Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- OFFICER REGISTRATION MODAL -->
    <div class="modal fade" id="officerModal" tabindex="-1" aria-labelledby="officerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content text-dark border-0 shadow">
                <form action="settings.php?tab=officers" method="POST">
                    <?php csrfInput(); ?>
                    <input type="hidden" name="action" value="create_officer">
                    
                    <div class="modal-header py-2 px-3 bg-light border-bottom">
                        <h6 class="modal-title fw-bold" id="officerModalLabel">
                            <i class="fa-solid fa-user-plus text-primary me-2"></i> Register Placement Officer Account
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-3">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Login Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control form-control-sm" placeholder="e.g. cse_placement" required>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Login Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Officer Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Dr. K. Ravi" required>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Official Email ID <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control form-control-sm" placeholder="e.g. ravi@mamcet.org" required>
                            </div>
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Mobile Phone Number</label>
                                <input type="text" name="mobile_number" class="form-control form-control-sm" placeholder="10 digit number">
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label small fw-bold text-dark mb-1">Department Scope Assignment</label>
                                <select name="dept_id" class="form-select form-select-sm">
                                    <option value="">All Departments (Global Placement Officer)</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_code']) . ' - ' . esc($d['dept_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small" style="font-size:0.72rem;">Restrict this officer to departmental data or leave as Global.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2 px-3 bg-light border-top">
                        <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-user-check me-1"></i> Register Officer Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

