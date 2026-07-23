<?php
// MAMCET Placement & Learning Portal - Student Roster Page

$pageTitle = 'Student Roster';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    $studentId = (int)($_POST['student_id'] ?? 0);
    
    if ($action === 'toggle_login') {
        $newStatus = $_POST['status'] === 'active' ? 'active' : 'inactive';
        try {
            // Find user_id
            $stmt = $db->prepare("SELECT user_id, registration_number FROM students WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $sData = $stmt->fetch();
            
            if ($sData && $sData['user_id']) {
                $stmtUpd = $db->prepare("UPDATE users SET status = ? WHERE user_id = ?");
                $stmtUpd->execute([$newStatus, $sData['user_id']]);
                $success = "Login access for student {$sData['registration_number']} set to " . ($newStatus === 'active' ? 'Enabled' : 'Disabled') . ".";
                logActivity($db, $_SESSION['user_id'], 'Toggle Student Login', "Set login status of student {$sData['registration_number']} to $newStatus");
            } else {
                $error = "Student has not completed their first-time login setup yet.";
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } elseif ($action === 'reset_password') {
        try {
            $stmt = $db->prepare("SELECT user_id, registration_number FROM students WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $sData = $stmt->fetch();
            
            if ($sData && $sData['user_id']) {
                $tempPassword = 'MAMCET@' . substr($sData['registration_number'], -4); // temporary password
                $newHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                
                $stmtUpd = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmtUpd->execute([$newHash, $sData['user_id']]);
                $success = "Password reset successfully for student {$sData['registration_number']}. Temporary Password is: <strong>$tempPassword</strong>";
                logActivity($db, $_SESSION['user_id'], 'Reset Student Password', "Reset password of student {$sData['registration_number']}");
            } else {
                $error = "Student has not completed their first-time login setup yet.";
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } elseif ($action === 'add_note') {
        $noteText = trim($_POST['note_text'] ?? '');
        if (empty($noteText)) {
            $error = 'Note content cannot be empty.';
        } else {
            try {
                // Fetch officer_id
                $stmt = $db->prepare("SELECT officer_id FROM placement_officers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $officerId = $stmt->fetchColumn();
                
                if ($officerId) {
                    $stmtIns = $db->prepare("INSERT INTO officer_notes (student_id, officer_id, note_content) VALUES (?, ?, ?)");
                    $stmtIns->execute([$studentId, $officerId, $noteText]);
                    $success = 'Placement note added successfully.';
                } else {
                    $error = 'Only designated placement officers can add notes.';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'bulk_status') {
        $studentIds = $_POST['bulk_students'] ?? [];
        $newStatus = $_POST['bulk_status'] === 'active' ? 'active' : 'inactive';
        
        if (empty($studentIds)) {
            $error = 'No students selected for bulk operation.';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $stmt = $db->prepare("SELECT user_id FROM students WHERE student_id IN ($placeholders) AND user_id IS NOT NULL");
                $stmt->execute($studentIds);
                $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($userIds)) {
                    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                    $stmtUpd = $db->prepare("UPDATE users SET status = ? WHERE user_id IN ($userPlaceholders)");
                    $stmtUpd->execute(array_merge([$newStatus], $userIds));
                    $success = "Successfully updated login state for " . count($userIds) . " student accounts.";
                    logActivity($db, $_SESSION['user_id'], 'Bulk Login Toggle', "Updated status of " . count($userIds) . " user accounts to $newStatus");
                } else {
                    $error = "Selected students have not set up user accounts yet.";
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch filter datasets
$batches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$departments = $db->query("SELECT * FROM departments ORDER BY dept_code")->fetchAll();

// Construct Roster Query
$whereClause = ["s.student_id IS NOT NULL"];
$queryParams = [];

// Filter: Academic Session
if (isset($_SESSION['active_session_id'])) {
    // Only show students belonging to batches associated with this academic session
    $whereClause[] = "s.batch_id IN (SELECT batch_id FROM batch_academic_sessions WHERE session_id = ?)";
    $queryParams[] = $_SESSION['active_session_id'];
}

// Filter inputs
$filterBatch = $_GET['batch_id'] ?? '';
$filterDept = $_GET['dept_id'] ?? '';
$filterWilling = $_GET['willingness'] ?? '';
$filterStatus = $_GET['placement_status'] ?? '';
$filterCGPA = $_GET['cgpa_min'] ?? '';
$filterArrears = $_GET['arrears'] ?? '';

if (!empty($filterBatch)) {
    $whereClause[] = "s.batch_id = ?";
    $queryParams[] = $filterBatch;
}
if (!empty($filterDept)) {
    $whereClause[] = "s.dept_id = ?";
    $queryParams[] = $filterDept;
}
if ($filterWilling !== '') {
    $whereClause[] = "s.placement_willingness = ?";
    $queryParams[] = $filterWilling;
}
if (!empty($filterStatus)) {
    $whereClause[] = "s.placement_status = ?";
    $queryParams[] = $filterStatus;
}
if (is_numeric($filterCGPA)) {
    $whereClause[] = "sa.current_cgpa >= ?";
    $queryParams[] = (float)$filterCGPA;
}
if ($filterArrears === 'no') {
    $whereClause[] = "sa.standing_arrears = 0";
} elseif ($filterArrears === 'yes') {
    $whereClause[] = "sa.standing_arrears > 0";
}

$whereSql = implode(' AND ', $whereClause);

// Query Student Roster
$stmtRoster = $db->prepare("
    SELECT s.*, d.dept_code, b.batch_name, u.status AS login_status, sa.current_cgpa, sa.standing_arrears, sec.section_name
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    WHERE $whereSql
    ORDER BY s.registration_number
");
$stmtRoster->execute($queryParams);
$students = $stmtRoster->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Student Database</h4>
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
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- FILTER CONTROLS -->
        <div class="mamcet-card mb-4">
            <div class="card-header bg-light py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-filter me-2 text-primary"></i> Search and Filter Students</h6>
            </div>
            <div class="card-body">
                <form action="students.php" method="GET" class="row g-3">
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Target Batch</label>
                        <select name="batch_id" class="form-select form-select-sm">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $filterBatch == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Department</label>
                        <select name="dept_id" class="form-select form-select-sm">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $filterDept == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Willingness</label>
                        <select name="willingness" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Yes" <?php echo $filterWilling === 'Yes' ? 'selected' : ''; ?>>Willing</option>
                            <option value="No" <?php echo $filterWilling === 'No' ? 'selected' : ''; ?>>Not Willing</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Placement Status</label>
                        <select name="placement_status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Placed" <?php echo $filterStatus === 'Placed' ? 'selected' : ''; ?>>Placed</option>
                            <option value="Unplaced" <?php echo $filterStatus === 'Unplaced' ? 'selected' : ''; ?>>Unplaced</option>
                            <option value="Higher Studies" <?php echo $filterStatus === 'Higher Studies' ? 'selected' : ''; ?>>Higher Studies</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Min CGPA</label>
                        <input type="number" step="0.1" name="cgpa_min" class="form-control form-control-sm" placeholder="e.g. 7.5" value="<?php echo esc($filterCGPA); ?>">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small">Standing Arrears</label>
                        <select name="arrears" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="no" <?php echo $filterArrears === 'no' ? 'selected' : ''; ?>>No standing arrears</option>
                            <option value="yes" <?php echo $filterArrears === 'yes' ? 'selected' : ''; ?>>Has arrears</option>
                        </select>
                    </div>
                    
                    <div class="col-12 text-end">
                        <a href="students.php" class="btn btn-sm btn-light border me-2">Clear Filters</a>
                        <button type="submit" class="btn btn-sm btn-primary px-4"><i class="fa-solid fa-magnifying-glass me-1"></i> Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>

        <form action="students.php" method="POST" id="bulkForm">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" id="bulk_action" value="bulk_status">
            <input type="hidden" name="bulk_status" id="bulk_status_val" value="">

            <div class="mamcet-card">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <h5 class="card-title fw-bold mb-0"><i class="fa-solid fa-users text-primary me-2"></i> Student Roster</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted fw-bold d-none d-md-inline">Bulk Action:</span>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="executeBulk('active')"><i class="fa-solid fa-user-check me-1"></i> Enable selected</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="executeBulk('inactive')"><i class="fa-solid fa-user-xmark me-1"></i> Disable selected</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="studentsTable" style="border: none;">
                            <thead class="table-light">
                                <tr>
                                    <th width="30" class="text-center no-sort">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>Register No</th>
                                    <th>Name</th>
                                    <th>Dept</th>
                                    <th>Sec</th>
                                    <th>Batch</th>
                                    <th>CGPA</th>
                                    <th>Arrears</th>
                                    <th>Willingness</th>
                                    <th>Placement</th>
                                    <th>Login Access</th>
                                    <th class="text-end no-sort">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $s): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" name="bulk_students[]" value="<?php echo $s['student_id']; ?>" class="select-item">
                                        </td>
                                        <td><span class="fw-bold text-dark"><?php echo esc($s['registration_number']); ?></span></td>
                                        <td><?php echo esc($s['student_name']); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo esc($s['dept_code']); ?></span></td>
                                        <td><?php echo esc($s['section_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo esc($s['batch_name']); ?></td>
                                        <td><strong class="text-primary"><?php echo esc($s['current_cgpa'] ?? 'N/A'); ?></strong></td>
                                        <td>
                                            <?php if (($s['standing_arrears'] ?? 0) > 0): ?>
                                                <span class="text-danger fw-bold"><?php echo (int)$s['standing_arrears']; ?></span>
                                            <?php else: ?>
                                                <span class="text-success"><i class="fa-solid fa-circle-check"></i> 0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $s['placement_willingness'] === 'Yes' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>">
                                                <?php echo $s['placement_willingness']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $s['placement_status'] === 'Placed' ? 'bg-success' : ($s['placement_status'] === 'Unplaced' ? 'bg-warning text-dark' : 'bg-info'); ?>">
                                                <?php echo esc($s['placement_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($s['user_id'] === null): ?>
                                                <span class="badge bg-secondary">Not Activated</span>
                                            <?php elseif ($s['login_status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    Manage
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <li><a class="dropdown-item" href="student-view.php?student_id=<?php echo $s['student_id']; ?>"><i class="fa-solid fa-id-badge text-primary me-2"></i> View Profile</a></li>
                                                    <?php if ($s['user_id'] !== null): ?>
                                                        <li>
                                                            <button class="dropdown-item" type="button" onclick="toggleStudentLogin(<?php echo $s['student_id']; ?>, '<?php echo $s['login_status'] === 'active' ? 'inactive' : 'active'; ?>')">
                                                                <i class="fa-solid <?php echo $s['login_status'] === 'active' ? 'fa-user-slash text-warning' : 'fa-user-check text-success'; ?> me-2"></i> 
                                                                <?php echo $s['login_status'] === 'active' ? 'Disable Account' : 'Enable Account'; ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item" type="button" onclick="resetPassword(<?php echo $s['student_id']; ?>)">
                                                                <i class="fa-solid fa-key text-danger me-2"></i> Reset Password
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <button class="dropdown-item" type="button" onclick="openNoteModal(<?php echo $s['student_id']; ?>, '<?php echo esc($s['student_name']); ?>')">
                                                            <i class="fa-solid fa-comment-medical text-muted me-2"></i> Add Officer Note
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Forms for actions -->
<form id="actionForm" action="students.php" method="POST" class="d-none">
    <?php csrfInput(); ?>
    <input type="hidden" name="action" id="action_input" value="">
    <input type="hidden" name="student_id" id="student_id_input" value="0">
    <input type="hidden" name="status" id="status_input" value="">
</form>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="students.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="student_id" id="note_student_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="noteModalLabel">Add Officer Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">This note on student <strong id="note_student_name"></strong> will remain visible to placement officers only.</p>
                    <div class="mb-3">
                        <label class="form-label">Note Description</label>
                        <textarea name="note_text" class="form-control" rows="4" placeholder="Enter comments on placement readiness, mock interview status, etc." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#studentsTable').DataTable({
        "columnDefs": [
            { "orderable": false, "targets": 'no-sort' }
        ],
        "order": [[1, "asc"]],
        "pageLength": 25
    });

    // Select/Deselect All Checkboxes
    $('#selectAll').on('change', function() {
        $('.select-item').prop('checked', this.checked);
    });
});

function toggleStudentLogin(studentId, newStatus) {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This will change student account login privileges.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, modify!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#action_input').val('toggle_login');
            $('#student_id_input').val(studentId);
            $('#status_input').val(newStatus);
            $('#actionForm').submit();
        }
    });
}

function resetPassword(studentId) {
    Swal.fire({
        title: 'Reset student password?',
        text: 'This will set the student password back to a temporary credential.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, reset!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#action_input').val('reset_password');
            $('#student_id_input').val(studentId);
            $('#actionForm').submit();
        }
    });
}

function openNoteModal(studentId, studentName) {
    $('#note_student_id').val(studentId);
    $('#note_student_name').text(studentName);
    var modal = new bootstrap.Modal(document.getElementById('noteModal'));
    modal.show();
}

function executeBulk(status) {
    const checked = $('.select-item:checked').length;
    if (checked === 0) {
        Swal.fire('No Selection', 'Please select at least one student checkbox.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Bulk Modify Privilege?',
        text: 'Change login authorization for ' + checked + ' selected accounts?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: 'Yes, execute!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#bulk_status_val').val(status);
            $('#bulkForm').submit();
        }
    });
}
</script>

