<?php
// MAMCET Placement & Learning Portal - Departments Management (Senior UI/UX Redesign)

$pageTitle = 'Departments';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle Create / Edit Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $deptCode = strtoupper(trim($_POST['dept_code'] ?? ''));
        $deptName = trim($_POST['dept_name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $programmeId = (int)($_POST['programme_id'] ?? 0);
        $headOfDept = trim($_POST['head_of_dept'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $deptId = (int)($_POST['dept_id'] ?? 0);
        
        if (empty($deptCode) || empty($deptName) || empty($shortName)) {
            $error = 'Department Code, Full Name, and Short Name are required.';
        } else {
            try {
                if ($action === 'create') {
                    // Check duplicate code
                    $chk = $db->prepare("SELECT dept_id FROM departments WHERE dept_code = ?");
                    $chk->execute([$deptCode]);
                    if ($chk->fetch()) {
                        $error = "Department code '{$deptCode}' already exists.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO departments (dept_code, dept_name, short_name, programme_id, head_of_dept, status) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$deptCode, $deptName, $shortName, $programmeId ?: null, $headOfDept ?: null, $status]);
                        $success = "Department '{$deptCode} - {$deptName}' created successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Create Department', "Created department $deptCode");
                    }
                } else { // edit
                    $chk = $db->prepare("SELECT dept_id FROM departments WHERE dept_code = ? AND dept_id != ?");
                    $chk->execute([$deptCode, $deptId]);
                    if ($chk->fetch()) {
                        $error = "Department code '{$deptCode}' already exists on another department.";
                    } else {
                        $stmt = $db->prepare("
                            UPDATE departments 
                            SET dept_code = ?, dept_name = ?, short_name = ?, programme_id = ?, head_of_dept = ?, status = ? 
                            WHERE dept_id = ?
                        ");
                        $stmt->execute([$deptCode, $deptName, $shortName, $programmeId ?: null, $headOfDept ?: null, $status, $deptId]);
                        $success = "Department '{$deptCode}' updated successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Update Department', "Updated department $deptCode");
                    }
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch programmes for selector
$programmes = $db->query("SELECT * FROM programmes ORDER BY programme_name")->fetchAll();

// Fetch all departments with student count
$depts = $db->query("
    SELECT d.*, pr.programme_name,
           (SELECT COUNT(*) FROM students s WHERE s.dept_id = d.dept_id) AS student_count
    FROM departments d 
    LEFT JOIN programmes pr ON d.programme_id = pr.programme_id 
    ORDER BY d.dept_code ASC
")->fetchAll();

// KPI Stats
$totalDepts = count($depts);
$totalProgrammes = (int)$db->query("SELECT COUNT(*) FROM programmes")->fetchColumn();
$assignedHods = (int)$db->query("SELECT COUNT(*) FROM departments WHERE head_of_dept IS NOT NULL AND head_of_dept != ''")->fetchColumn();
$totalStudentsInDepts = (int)$db->query("SELECT COUNT(*) FROM students WHERE dept_id IS NOT NULL")->fetchColumn();

// Dept Code color mapping
function getDeptBadgeClass($code) {
    $c = strtoupper($code);
    switch ($c) {
        case 'AIDS': return 'bg-purple-subtle text-purple border-purple-subtle';
        case 'CSE':  return 'bg-primary-subtle text-primary border-primary-subtle';
        case 'ECE':  return 'bg-info-subtle text-info border-info-subtle';
        case 'EEE':  return 'bg-warning-subtle text-warning border-warning-subtle';
        case 'IT':   return 'bg-cyan-subtle text-cyan border-cyan-subtle';
        case 'MECH': return 'bg-danger-subtle text-danger border-danger-subtle';
        case 'CIVIL':return 'bg-teal-subtle text-teal border-teal-subtle';
        default:     return 'bg-light text-dark border';
    }
}
?>

<style>
/* Custom Department Badges */
.dept-code-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 52px;
    padding: 3px 6px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    letter-spacing: 0.5px;
    border: 1px solid transparent;
}
.bg-purple-subtle { background-color: #f3e8ff; }
.text-purple { color: #7e22ce; }
.border-purple-subtle { border-color: #e9d5ff; }
.bg-cyan-subtle { background-color: #ecfeff; }
.text-cyan { color: #0e7490; }
.border-cyan-subtle { border-color: #cffafe; }
.bg-teal-subtle { background-color: #f0fdfa; }
.text-teal { color: #0f766e; }
.border-teal-subtle { border-color: #ccfbf1; }

.dept-kpi-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}
.dept-kpi-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.dept-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.dept-kpi-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.dept-kpi-value {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}

.hod-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 700;
    color: #334155;
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
                    <i class="fa-solid fa-building-columns text-primary me-2"></i> Academic Departments
                </h4>
                <p class="text-muted small mb-0 mt-1">Manage departmental faculties, programmes, and student enrollment cohorts.</p>
            </div>
            <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> Create Department
            </button>
        </div>

        <!-- Executive KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="dept-kpi-bar">
            <div class="dept-kpi-item">
                <div class="dept-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <div>
                    <div class="dept-kpi-label">Total Departments</div>
                    <div class="dept-kpi-value text-primary"><?php echo $totalDepts; ?> Branches</div>
                </div>
            </div>

            <div class="dept-kpi-item">
                <div class="dept-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <div class="dept-kpi-label">Programmes Offered</div>
                    <div class="dept-kpi-value text-success"><?php echo $totalProgrammes; ?> Degrees</div>
                </div>
            </div>

            <div class="dept-kpi-item">
                <div class="dept-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <div>
                    <div class="dept-kpi-label">Assigned HoDs</div>
                    <div class="dept-kpi-value text-info"><?php echo $assignedHods; ?> / <?php echo $totalDepts; ?> Assigned</div>
                </div>
            </div>

            <div class="dept-kpi-item">
                <div class="dept-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="dept-kpi-label">Student Enrollment</div>
                    <div class="dept-kpi-value text-dark"><?php echo number_format($totalStudentsInDepts); ?> Students</div>
                </div>
            </div>
        </div>

        <!-- Departments Data Table Card -->
        <div class="mamcet-card border-0 shadow-sm" style="border-radius: 10px; overflow:hidden;">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-list-check text-primary me-2"></i> Department Registries
                </h6>
                <span class="badge bg-light text-secondary border small"><?php echo $totalDepts; ?> Configured</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ann-table" id="deptsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 100px;">Code</th>
                                <th>Department Name</th>
                                <th>Short Name</th>
                                <th>Programme</th>
                                <th>Department Head</th>
                                <th>Enrolled Students</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($depts as $dept): 
                                $hod = trim($dept['head_of_dept'] ?? '');
                                $hodInitials = !empty($hod) ? strtoupper(substr($hod, 0, 2)) : '--';
                            ?>
                                <tr>
                                    <td>
                                        <span class="dept-code-pill <?php echo getDeptBadgeClass($dept['dept_code']); ?>">
                                            <?php echo esc($dept['dept_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="text-dark d-block" style="font-size:0.86rem;"><?php echo esc($dept['dept_name']); ?></strong>
                                        <small class="text-muted" style="font-size:0.72rem;">Department ID: #<?php echo $dept['dept_id']; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border px-2 py-1 font-monospace" style="font-size: 0.76rem;">
                                            <?php echo esc($dept['short_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.74rem;">
                                            <i class="fa-solid fa-graduation-cap me-1"></i> <?php echo esc($dept['programme_name'] ?? 'B.Tech / B.E.'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($hod)): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="hod-avatar"><?php echo $hodInitials; ?></div>
                                                <span class="small fw-semibold text-dark"><?php echo esc($hod); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border" style="font-size: 0.72rem;">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size:0.76rem;">
                                            <i class="fa-solid fa-users text-primary me-1"></i> <?php echo (int)$dept['student_count']; ?> Students
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($dept['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                ● Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                ● Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($dept)); ?>)" title="Edit Department Details">
                                                <i class="fa-solid fa-pencil text-secondary me-1"></i> Edit
                                            </button>
                                            <a href="students.php?dept=<?php echo $dept['dept_id']; ?>" class="btn btn-sm btn-light border" title="View Enrolled Students">
                                                <i class="fa-solid fa-user-group text-primary"></i>
                                            </a>
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

<!-- CREATE / EDIT DEPARTMENT MODAL -->
<div class="modal fade" id="deptModal" tabindex="-1" aria-labelledby="deptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-dark border-0 shadow">
            <form action="departments.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="dept_id" id="form_dept_id" value="0">
                
                <div class="modal-header py-2 px-3 bg-light border-bottom">
                    <h6 class="modal-title fw-bold" id="deptModalLabel">
                        <i class="fa-solid fa-building-columns text-primary me-2"></i> Create Department
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-3">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Department Code <span class="text-danger">*</span></label>
                            <input type="text" name="dept_code" id="modal_dept_code" class="form-control form-control-sm text-uppercase" placeholder="e.g. CSE" required maxlength="10">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Short Name <span class="text-danger">*</span></label>
                            <input type="text" name="short_name" id="modal_short_name" class="form-control form-control-sm" placeholder="e.g. CSE" required maxlength="20">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Department Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="dept_name" id="modal_dept_name" class="form-control form-control-sm" placeholder="e.g. Computer Science and Engineering" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Programme Degree</label>
                        <select name="programme_id" id="modal_programme_id" class="form-select form-select-sm">
                            <option value="">-- Choose Programme --</option>
                            <?php foreach ($programmes as $pr): ?>
                                <option value="<?php echo $pr['programme_id']; ?>"><?php echo esc($pr['programme_name']); ?> (<?php echo esc($pr['programme_code']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small fw-bold text-dark mb-1">Head of Department (HoD)</label>
                            <input type="text" name="head_of_dept" id="modal_head_of_dept" class="form-control form-control-sm" placeholder="e.g. Dr. A. Rajesh">
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-bold text-dark mb-1">Status</label>
                            <select name="status" id="modal_status" class="form-select form-select-sm">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer py-2 px-3 bg-light border-top">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitForm">
                        <i class="fa-solid fa-plus me-1"></i> Create Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCreateModal() {
    $('#deptModalLabel').html('<i class="fa-solid fa-building-columns text-primary me-2"></i> Create Department');
    $('#form_action').val('create');
    $('#form_dept_id').val('0');
    $('#modal_dept_code').val('');
    $('#modal_dept_name').val('');
    $('#modal_short_name').val('');
    $('#modal_programme_id').val('');
    $('#modal_head_of_dept').val('');
    $('#modal_status').val('active');
    $('#btnSubmitForm').html('<i class="fa-solid fa-plus me-1"></i> Create Department');
}

function openEditModal(dept) {
    $('#deptModalLabel').html('<i class="fa-solid fa-pencil text-primary me-2"></i> Edit Department Details');
    $('#form_action').val('edit');
    $('#form_dept_id').val(dept.dept_id);
    $('#modal_dept_code').val(dept.dept_code);
    $('#modal_dept_name').val(dept.dept_name);
    $('#modal_short_name').val(dept.short_name);
    $('#modal_programme_id').val(dept.programme_id || '');
    $('#modal_head_of_dept').val(dept.head_of_dept || '');
    $('#modal_status').val(dept.status);
    $('#btnSubmitForm').html('<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('deptModal'));
    modal.show();
}
</script>
