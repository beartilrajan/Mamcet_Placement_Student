<?php
// MAMCET Placement & Learning Portal - Academic Sessions Management (Senior UI/UX Redesign)

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
                        $error = "Academic session '{$sessionName}' already exists.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO academic_sessions (session_name, start_year, end_year, start_date, end_date, status, description) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$sessionName, $startYear, $endYear, $startDate, $endDate, $status, $description]);
                        $success = "Academic session '{$sessionName}' created successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Create Session', "Created session $sessionName");
                        syncBatchAcademicSessions($db);
                    }
                } else { // edit
                    $chk = $db->prepare("SELECT session_id FROM academic_sessions WHERE session_name = ? AND session_id != ?");
                    $chk->execute([$sessionName, $sessionId]);
                    if ($chk->fetch()) {
                        $error = "Academic session '{$sessionName}' already exists on another record.";
                    } else {
                        $stmt = $db->prepare("
                            UPDATE academic_sessions 
                            SET session_name = ?, start_year = ?, end_year = ?, start_date = ?, end_date = ?, status = ?, description = ? 
                            WHERE session_id = ?
                        ");
                        $stmt->execute([$sessionName, $startYear, $endYear, $startDate, $endDate, $status, $description, $sessionId]);
                        $success = "Academic session '{$sessionName}' updated successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Update Session', "Updated session $sessionName");
                        syncBatchAcademicSessions($db);
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
                $db->exec("UPDATE academic_sessions SET is_active = 0");
                $stmt = $db->prepare("UPDATE academic_sessions SET is_active = 1, status = 'active' WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                
                $nameStmt = $db->prepare("SELECT session_name FROM academic_sessions WHERE session_id = ?");
                $nameStmt->execute([$sessionId]);
                $sName = $nameStmt->fetchColumn();
                
                $_SESSION['active_session_id'] = $sessionId;
                $_SESSION['active_session_name'] = $sName;
                
                $db->commit();
                $success = "Default active session successfully switched to '{$sName}'.";
                logActivity($db, $_SESSION['user_id'], 'Activate Session', "Activated session $sName");
                syncBatchAcademicSessions($db);
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to activate session: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId > 0) {
            try {
                $stmt = $db->prepare("SELECT session_name, is_active FROM academic_sessions WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                $targetSession = $stmt->fetch();

                if (!$targetSession) {
                    $error = 'Academic session not found.';
                } elseif ($targetSession['is_active'] == 1) {
                    $error = 'Cannot delete the currently active default academic session.';
                } else {
                    $totalSessions = (int)$db->query("SELECT COUNT(*) FROM academic_sessions")->fetchColumn();
                    if ($totalSessions <= 1) {
                        $error = 'Cannot delete the only academic session in the portal.';
                    } else {
                        $chkImports = $db->prepare("SELECT COUNT(*) FROM excel_imports WHERE session_id = ?");
                        $chkImports->execute([$sessionId]);
                        $importCount = (int)$chkImports->fetchColumn();

                        if ($importCount > 0) {
                            $error = "Cannot delete this session because {$importCount} student dataset import record(s) are linked to it.";
                        } else {
                            $delStmt = $db->prepare("DELETE FROM academic_sessions WHERE session_id = ?");
                            $delStmt->execute([$sessionId]);

                            if (isset($_SESSION['active_session_id']) && $_SESSION['active_session_id'] == $sessionId) {
                                $defStmt = $db->query("SELECT session_id, session_name FROM academic_sessions WHERE is_active = 1 LIMIT 1");
                                $defSession = $defStmt->fetch();
                                if ($defSession) {
                                    $_SESSION['active_session_id'] = $defSession['session_id'];
                                    $_SESSION['active_session_name'] = $defSession['session_name'];
                                } else {
                                    unset($_SESSION['active_session_id'], $_SESSION['active_session_name']);
                                }
                            }

                            $success = "Academic session '{$targetSession['session_name']}' deleted successfully.";
                            logActivity($db, $_SESSION['user_id'], 'Delete Session', "Deleted session {$targetSession['session_name']} (ID: $sessionId)");
                        }
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'foreign key') !== false) {
                    $error = 'Cannot delete this academic session because other records in the system depend on it.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            } catch (Exception $e) {
                $error = 'Error deleting session: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all academic sessions with linked counts
$sessions = $db->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM placement_opportunities po WHERE po.session_id = s.session_id) AS placement_count
    FROM academic_sessions s
    ORDER BY s.start_year DESC
")->fetchAll();

// KPI Stats
$totalSessions = count($sessions);
$activeSessionRecord = null;
$totalPlacementCycles = 0;
foreach ($sessions as $sess) {
    $totalPlacementCycles += (int)$sess['placement_count'];
    if ($sess['is_active'] == 1) {
        $activeSessionRecord = $sess;
    }
}
$activeSessionLabel = $activeSessionRecord['session_name'] ?? '2024–2025';
?>

<style>
.date-pill {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px 6px;
    font-size: 0.74rem;
    color: #334155;
    font-weight: 600;
}
.sess-kpi-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.sess-kpi-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.sess-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.sess-kpi-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    line-height: 1;
    margin-bottom: 4px;
}
.sess-kpi-value {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
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
                    <i class="fa-solid fa-calendar-days text-primary me-2"></i> Academic Sessions
                </h4>
                <p class="text-muted small mb-0 mt-1">Configure active operational cycles, academic years, and recruitment drive windows.</p>
            </div>
            <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#sessionModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> Add Academic Session
            </button>
        </div>

        <!-- Executive KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="sess-kpi-bar">
            <div class="sess-kpi-item">
                <div class="sess-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-star"></i>
                </div>
                <div>
                    <div class="sess-kpi-label">Active Operational Session</div>
                    <div class="sess-kpi-value text-primary"><?php echo esc($activeSessionLabel); ?></div>
                </div>
            </div>

            <div class="sess-kpi-item">
                <div class="sess-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-folder-tree"></i>
                </div>
                <div>
                    <div class="sess-kpi-label">Total Sessions</div>
                    <div class="sess-kpi-value text-success"><?php echo $totalSessions; ?> Cycles</div>
                </div>
            </div>

            <div class="sess-kpi-item">
                <div class="sess-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="sess-kpi-label">Placement Drives</div>
                    <div class="sess-kpi-value text-dark"><?php echo $totalPlacementCycles; ?> Linked</div>
                </div>
            </div>

            <div class="sess-kpi-item">
                <div class="sess-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div class="sess-kpi-label">Cycle Guard</div>
                    <div class="sess-kpi-value text-info">Protected Default</div>
                </div>
            </div>
        </div>

        <!-- Sessions Data Table Card -->
        <div class="mamcet-card border-0 shadow-sm" style="border-radius: 10px; overflow:hidden;">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-calendar-check text-primary me-2"></i> Academic Sessions Lifecycle
                </h6>
                <span class="badge bg-light text-secondary border small"><?php echo $totalSessions; ?> Total Configured</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ann-table" id="sessionsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Session Name</th>
                                <th>Duration</th>
                                <th>Operational Date Span</th>
                                <th>Placement Drives</th>
                                <th>Status</th>
                                <th>Default State</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): 
                                $isActive = $session['is_active'] == 1;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="ann-icon-badge <?php echo $isActive ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'; ?>">
                                                <i class="fa-solid fa-calendar-days"></i>
                                            </div>
                                            <div>
                                                <strong class="text-dark d-block" style="font-size:0.86rem;"><?php echo esc($session['session_name']); ?></strong>
                                                <small class="text-muted" style="font-size:0.72rem;">Session ID: #<?php echo $session['session_id']; ?></small>
                                            </div>
                                            <?php if ($isActive): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0 ms-1 fw-bold" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-star me-1"></i> Active Default
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="timeline-pill">
                                            <?php echo esc($session['start_year']); ?> – <?php echo esc($session['end_year']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="date-pill">
                                            <?php echo date('d M Y', strtotime($session['start_date'])); ?> <i class="fa-solid fa-arrow-right text-muted mx-1" style="font-size:0.65rem;"></i> <?php echo date('d M Y', strtotime($session['end_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-primary border px-2 py-1 font-monospace" style="font-size: 0.74rem;">
                                            <i class="fa-solid fa-briefcase me-1"></i> <?php echo (int)$session['placement_count']; ?> Drives
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($session['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                ● Active
                                            </span>
                                        <?php elseif ($session['status'] === 'inactive'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                ● Inactive
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                ● Archived
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge bg-success text-white px-2 py-1" style="font-size: 0.72rem;">
                                                <i class="fa-solid fa-circle-check me-1"></i> Current Operational Default
                                            </span>
                                        <?php else: ?>
                                            <form action="academic-sessions.php" method="POST" class="d-inline">
                                                <?php csrfInput(); ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-light border text-primary fw-semibold" style="font-size: 0.74rem;">
                                                    <i class="fa-regular fa-star me-1"></i> Make Active Default
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($session)); ?>)" title="Edit Academic Session">
                                                <i class="fa-solid fa-pencil text-secondary me-1"></i> Edit
                                            </button>
                                            <?php if (!$isActive): ?>
                                                <button type="button" class="btn btn-sm btn-light border text-danger" onclick="confirmDeleteSession(<?php echo $session['session_id']; ?>, '<?php echo esc(addslashes($session['session_name'])); ?>')" title="Delete Session">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-light border text-muted opacity-50" disabled title="Default active session is protected from deletion">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
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

<!-- Hidden Delete Form -->
<form id="deleteSessionForm" action="academic-sessions.php" method="POST" style="display: none;">
    <?php csrfInput(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="session_id" id="delete_session_id" value="0">
</form>

<!-- CREATE / EDIT ACADEMIC SESSION MODAL -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-dark border-0 shadow">
            <form action="academic-sessions.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="session_id" id="form_session_id" value="0">
                
                <div class="modal-header py-2 px-3 bg-light border-bottom">
                    <h6 class="modal-title fw-bold" id="sessionModalLabel">
                        <i class="fa-solid fa-calendar-days text-primary me-2"></i> Create Academic Session
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Session Label / Title <span class="text-danger">*</span></label>
                        <input type="text" name="session_name" id="modal_session_name" class="form-control form-control-sm" placeholder="e.g. 2026–2027" required>
                        <div class="form-text small" style="font-size:0.72rem;">Academic calendar year span format.</div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Start Year <span class="text-danger">*</span></label>
                            <input type="number" name="start_year" id="modal_start_year" class="form-control form-control-sm" placeholder="2026" required oninput="calcSessionDates()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">End Year <span class="text-danger">*</span></label>
                            <input type="number" name="end_year" id="modal_end_year" class="form-control form-control-sm" placeholder="2027" required>
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="modal_start_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="modal_end_date" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Lifecycle Status</label>
                        <select name="status" id="modal_status" class="form-select form-select-sm">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark mb-1">Session Description / Notes</label>
                        <textarea name="description" id="modal_description" class="form-control form-control-sm" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer py-2 px-3 bg-light border-top">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitForm">
                        <i class="fa-solid fa-plus me-1"></i> Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function calcSessionDates() {
    const startY = parseInt($('#modal_start_year').val()) || 0;
    if (startY > 0) {
        const endY = startY + 1;
        $('#modal_end_year').val(endY);
        if (!$('#modal_session_name').val() || $('#form_action').val() === 'create') {
            $('#modal_session_name').val(startY + '–' + endY);
        }
        if (!$('#modal_start_date').val()) {
            $('#modal_start_date').val(startY + '-06-01');
        }
        if (!$('#modal_end_date').val()) {
            $('#modal_end_date').val(endY + '-05-31');
        }
    }
}

function openCreateModal() {
    $('#sessionModalLabel').html('<i class="fa-solid fa-calendar-days text-primary me-2"></i> Create Academic Session');
    $('#form_action').val('create');
    $('#form_session_id').val('0');
    const currentYear = new Date().getFullYear();
    $('#modal_start_year').val(currentYear);
    $('#modal_end_year').val(currentYear + 1);
    $('#modal_session_name').val(currentYear + '–' + (currentYear + 1));
    $('#modal_start_date').val(currentYear + '-06-01');
    $('#modal_end_date').val((currentYear + 1) + '-05-31');
    $('#modal_status').val('active');
    $('#modal_description').val('');
    $('#btnSubmitForm').html('<i class="fa-solid fa-plus me-1"></i> Create Session');
}

function openEditModal(session) {
    $('#sessionModalLabel').html('<i class="fa-solid fa-pencil text-primary me-2"></i> Edit Academic Session');
    $('#form_action').val('edit');
    $('#form_session_id').val(session.session_id);
    $('#modal_session_name').val(session.session_name);
    $('#modal_start_year').val(session.start_year);
    $('#modal_end_year').val(session.end_year);
    $('#modal_start_date').val(session.start_date);
    $('#modal_end_date').val(session.end_date);
    $('#modal_status').val(session.status);
    $('#modal_description').val(session.description || '');
    $('#btnSubmitForm').html('<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('sessionModal'));
    modal.show();
}

function confirmDeleteSession(sessionId, sessionName) {
    if (confirm(`Are you sure you want to delete academic session "${sessionName}"? This action cannot be undone.`)) {
        $('#delete_session_id').val(sessionId);
        $('#deleteSessionForm').submit();
    }
}
</script>

