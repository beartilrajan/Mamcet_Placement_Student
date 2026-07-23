<?php
// MAMCET Placement & Learning Portal - System Settings & Configurations Page

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
        // Restrict to Super Admin
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
                    $success = 'Academic Session created successfully.';
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
                    // Check duplicate user
                    $chk = $db->prepare("SELECT user_id FROM users WHERE username = ?");
                    $chk->execute([$username]);
                    if ($chk->fetch()) {
                        $error = 'Username already registered.';
                    } else {
                        $db->beginTransaction();
                        
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmtU = $db->prepare("INSERT INTO users (username, password_hash, role_id, email, display_name, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmtU->execute([$username, $hash, ROLE_PLACEMENT_OFFICER, $email, $name]);
                        $newUserId = $db->lastInsertId();
                        
                        $stmtO = $db->prepare("INSERT INTO placement_officers (user_id, name, email, mobile_number, dept_id) VALUES (?, ?, ?, ?, ?)");
                        $stmtO->execute([$newUserId, $name, $email, $mobile, $deptId ?: null]);
                        
                        $db->commit();
                        $success = 'Placement Officer account registered successfully.';
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
    SELECT po.*, u.username, u.status, d.dept_code 
    FROM placement_officers po
    JOIN users u ON po.user_id = u.user_id
    LEFT JOIN departments d ON po.dept_id = d.dept_id
    ORDER BY po.name ASC
")->fetchAll();

// Departments list
$departments = $db->query("SELECT dept_id, dept_code FROM departments ORDER BY dept_code")->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Portal Configurations</h4>
        </div>
        
        <div class="navbar-right">
            <div class="session-selector-container">
                <span class="small text-muted fw-bold d-none d-md-inline">Active Session:</span>
                <select class="session-selector-select" id="globalSessionSelector">
                    <?php foreach ($allSessions as $s): ?>
                        <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                            <?php echo esc($s['session_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-profile-dropdown">
                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:36px;height:36px;border-radius:50%;font-weight:bold;">
                    <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex">
                    <span class="user-name"><?php echo esc($loggedInUser['display_name'] ?? 'User'); ?></span>
                    <span class="user-role"><?php echo $_SESSION['role_id'] === 1 ? 'Super Admin' : 'Placement Officer'; ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="page-container">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- 1. System Parameters Config -->
            <div class="col-lg-6 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-sliders text-primary me-2"></i> Placement Eligibility Parameters</h5>
                    </div>
                    <div class="card-body">
                        <form action="settings.php" method="POST">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="update_settings">
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary small">Default Minimum CGPA Threshold</label>
                                <input type="number" step="0.01" name="min_cgpa" class="form-control" value="<?php echo esc($settings['min_cgpa'] ?? '6.00'); ?>" required>
                                <div class="form-text small">Used as default check during student placements filters.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary small">Default Maximum Standing Arrears Limit</label>
                                <input type="number" name="max_standing_arrears" class="form-control" value="<?php echo (int)($settings['max_standing_arrears'] ?? 0); ?>" required>
                                <div class="form-text small">Used to isolate clean profiles from students with backlogs.</div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label text-secondary small">Profile Completion Target (%)</label>
                                <input type="number" name="completion_alert_threshold" class="form-control" value="<?php echo (int)($settings['completion_alert_threshold'] ?? 80); ?>" required>
                                <div class="form-text small">Profiles below this completeness index show flags.</div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-sm px-4">Update Default Criteria</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 2. Academic Sessions Settings -->
            <div class="col-lg-6 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-calendar-days text-primary me-2"></i> Academic Sessions</h5>
                        <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sessionModal"><i class="fa-solid fa-plus"></i> Add</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem; border:none;">
                                <thead class="table-light">
                                    <tr>
                                        <th>Session Name</th>
                                        <th>Span Year</th>
                                        <th>Status</th>
                                        <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                                            <th class="text-end">Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessionsList as $s): ?>
                                        <tr>
                                            <td><span class="fw-bold text-dark"><?php echo esc($s['session_name']); ?></span></td>
                                            <td><?php echo $s['start_year'] . ' - ' . $s['end_year']; ?></td>
                                            <td>
                                                <?php if ($s['is_active'] == 1): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success">Default System</span>
                                                <?php else: ?>
                                                    <span class="text-muted small">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                                                <td class="text-end">
                                                    <?php if ($s['is_active'] != 1): ?>
                                                        <form action="settings.php" method="POST" class="d-inline">
                                                            <?php csrfInput(); ?>
                                                            <input type="hidden" name="action" value="set_default_session">
                                                            <input type="hidden" name="session_id" value="<?php echo $s['session_id']; ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-success py-1" style="font-size:0.75rem;"><i class="fa-solid fa-star"></i> Set Default</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Placement Officers Accounts (Limited to Super Admin view, or listing) -->
        <div class="mamcet-card mt-2">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-user-shield text-primary me-2"></i> Placement Officers registry</h5>
                <?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#officerModal"><i class="fa-solid fa-user-plus"></i> Register Officer</button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem; border:none;">
                        <thead class="table-light">
                            <tr>
                                <th>Officer Name</th>
                                <th>Username</th>
                                <th>Contact Email</th>
                                <th>Mobile</th>
                                <th>Dept assignment</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($officersList as $o): ?>
                                <tr>
                                    <td><strong class="text-dark"><?php echo esc($o['name']); ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo esc($o['username']); ?></span></td>
                                    <td><?php echo esc($o['email']); ?></td>
                                    <td><?php echo esc($o['mobile_number']); ?></td>
                                    <td><span class="fw-bold"><?php echo esc($o['dept_code'] ?: 'All Departments'); ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success border-success border"><?php echo strtoupper($o['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- SESSION MODAL (Super Admin) -->
<?php if ($_SESSION['role_id'] === ROLE_SUPER_ADMIN): ?>
    <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content text-dark">
                <form action="settings.php" method="POST">
                    <?php csrfInput(); ?>
                    <input type="hidden" name="action" value="create_session">
                    
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="sessionModalLabel">Add Academic Session</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Session Name</label>
                            <input type="text" name="session_name" class="form-control" placeholder="e.g. 2026 - 2027" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Start Year</label>
                                <input type="number" name="start_year" class="form-control" value="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">End Year</label>
                                <input type="number" name="end_year" class="form-control" value="<?php echo date('Y') + 1; ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Create Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- OFFICER REGISTRATION MODAL -->
    <div class="modal fade" id="officerModal" tabindex="-1" aria-labelledby="officerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content text-dark">
                <form action="settings.php" method="POST">
                    <?php csrfInput(); ?>
                    <input type="hidden" name="action" value="create_officer">
                    
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="officerModalLabel">Register Placement Officer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username (Officer Login)</label>
                                <input type="text" name="username" class="form-control" placeholder="e.g. cse_placement" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Login Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Dr. K. Ravi" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email ID</label>
                                <input type="email" name="email" class="form-control" placeholder="e.g. ravi@mamcet.org" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mobile Number</label>
                                <input type="text" name="mobile_number" class="form-control" placeholder="10 digit number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department assignment</label>
                                <select name="dept_id" class="form-select">
                                    <option value="">All Departments (General Officer)</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_code']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">Restrict this officer to view/modify CSE or ECE data only, or leave blank for global access.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Register Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

