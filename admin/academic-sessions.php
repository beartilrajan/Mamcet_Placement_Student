<?php
// MAMCET Placement & Learning Portal - Academic Sessions Management Page

$pageTitle = 'Academic Sessions';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle Create / Edit Session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $sessionName = trim($_POST['session_name'] ?? '');
        $startYear = (int)($_POST['start_year'] ?? 0);
        $endYear = (int)($_POST['end_year'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $status = $_POST['status'] ?? 'inactive';
        $description = trim($_POST['description'] ?? '');
        $sessionId = (int)($_POST['session_id'] ?? 0);
        
        if (empty($sessionName) || $startYear <= 0 || $endYear <= 0 || empty($startDate) || empty($endDate)) {
            $error = 'All fields except description are required.';
        } elseif ($endYear <= $startYear) {
            $error = 'End year must be greater than start year.';
        } elseif (strtotime($endDate) <= strtotime($startDate)) {
            $error = 'End date must be after start date.';
        } else {
            try {
                if ($action === 'create') {
                    // Check duplicate name
                    $chk = $db->prepare("SELECT session_id FROM academic_sessions WHERE session_name = ?");
                    $chk->execute([$sessionName]);
                    if ($chk->fetch()) {
                        $error = 'An academic session with this name already exists.';
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO academic_sessions (session_name, start_year, end_year, start_date, end_date, status, description) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$sessionName, $startYear, $endYear, $startDate, $endDate, $status, $description]);
                        $success = 'Academic session created successfully.';
                        logActivity($db, $_SESSION['user_id'], 'Create Session', "Created session $sessionName");
                    }
                } else { // edit
                    // Check duplicate name excluding current
                    $chk = $db->prepare("SELECT session_id FROM academic_sessions WHERE session_name = ? AND session_id != ?");
                    $chk->execute([$sessionName, $sessionId]);
                    if ($chk->fetch()) {
                        $error = 'An academic session with this name already exists.';
                    } else {
                        $stmt = $db->prepare("
                            UPDATE academic_sessions 
                            SET session_name = ?, start_year = ?, end_year = ?, start_date = ?, end_date = ?, status = ?, description = ? 
                            WHERE session_id = ?
                        ");
                        $stmt->execute([$sessionName, $startYear, $endYear, $startDate, $endDate, $status, $description, $sessionId]);
                        $success = 'Academic session updated successfully.';
                        logActivity($db, $_SESSION['user_id'], 'Update Session', "Updated session $sessionName");
                    }
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'activate') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId > 0) {
            try {
                $db->beginTransaction();
                // Deactivate all
                $db->exec("UPDATE academic_sessions SET is_active = 0");
                // Activate selected
                $stmt = $db->prepare("UPDATE academic_sessions SET is_active = 1, status = 'active' WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                
                // Get session name to store in user's active session state
                $nameStmt = $db->prepare("SELECT session_name FROM academic_sessions WHERE session_id = ?");
                $nameStmt->execute([$sessionId]);
                $sName = $nameStmt->fetchColumn();
                
                $_SESSION['active_session_id'] = $sessionId;
                $_SESSION['active_session_name'] = $sName;
                
                $db->commit();
                $success = 'Academic session activated successfully.';
                logActivity($db, $_SESSION['user_id'], 'Activate Session', "Activated session $sName");
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to activate session: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all academic sessions
$sessions = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC")->fetchAll();
?>

<div class="main-content">
    <!-- Top header navbar -->
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Academic Session Management</h4>
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
        <!-- Display Success / Error alerts -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title fw-bold">Academic Sessions</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sessionModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-2"></i> Create Session
            </button>
        </div>

        <div class="mamcet-card">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-calendar-days text-primary me-2"></i> Available Sessions</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="sessionsTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>Session Name</th>
                                <th>Duration</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Operational state</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark"><?php echo esc($session['session_name']); ?></span>
                                        <?php if ($session['is_active'] == 1): ?>
                                            <span class="badge bg-success ms-2">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc($session['start_year']) . ' – ' . esc($session['end_year']); ?></td>
                                    <td><?php echo esc(date('d M Y', strtotime($session['start_date']))); ?></td>
                                    <td><?php echo esc(date('d M Y', strtotime($session['end_date']))); ?></td>
                                    <td>
                                        <span class="badge <?php echo $session['status'] === 'active' ? 'bg-success' : ($session['status'] === 'inactive' ? 'bg-warning' : 'bg-secondary'); ?>">
                                            <?php echo ucfirst($session['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($session['is_active'] == 1): ?>
                                            <span class="text-success fw-bold small"><i class="fa-solid fa-check-double me-1"></i> Current Active Session</span>
                                        <?php else: ?>
                                            <form action="academic-sessions.php" method="POST" class="d-inline">
                                                <?php csrfInput(); ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    Make Active
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                            <i class="fa-solid fa-pencil text-secondary"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="academic-sessions.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="session_id" id="form_session_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="sessionModalLabel">Create Academic Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Session Name</label>
                        <input type="text" name="session_name" id="modal_session_name" class="form-control" placeholder="e.g. 2026–2027" required>
                        <div class="form-text">Must be a unique name identifying the academic year.</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Start Year</label>
                            <input type="number" name="start_year" id="modal_start_year" class="form-control" placeholder="e.g. 2026" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">End Year</label>
                            <input type="number" name="end_year" id="modal_end_year" class="form-control" placeholder="e.g. 2027" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="modal_start_date" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" id="modal_end_date" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="modal_status" class="form-select">
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" id="modal_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitForm">Create Session</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCreateModal() {
    $('#sessionModalLabel').text('Create Academic Session');
    $('#form_action').val('create');
    $('#form_session_id').val('0');
    $('#modal_session_name').val('');
    $('#modal_start_year').val('');
    $('#modal_end_year').val('');
    $('#modal_start_date').val('');
    $('#modal_end_date').val('');
    $('#modal_status').val('inactive');
    $('#modal_description').val('');
    $('#btnSubmitForm').text('Create Session');
}

function openEditModal(session) {
    $('#sessionModalLabel').text('Edit Academic Session');
    $('#form_action').val('edit');
    $('#form_session_id').val(session.session_id);
    $('#modal_session_name').val(session.session_name);
    $('#modal_start_year').val(session.start_year);
    $('#modal_end_year').val(session.end_year);
    $('#modal_start_date').val(session.start_date);
    $('#modal_end_date').val(session.end_date);
    $('#modal_status').val(session.status);
    $('#modal_description').val(session.description);
    $('#btnSubmitForm').text('Save Changes');
    
    // Open modal
    var modal = new bootstrap.Modal(document.getElementById('sessionModal'));
    modal.show();
}
</script>

