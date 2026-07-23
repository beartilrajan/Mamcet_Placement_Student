<?php
// MAMCET Placement & Learning Portal - Departments Management Page

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
            $error = 'Department Code, Name, and Short Name are required.';
        } else {
            try {
                if ($action === 'create') {
                    // Check duplicate code
                    $chk = $db->prepare("SELECT dept_id FROM departments WHERE dept_code = ?");
                    $chk->execute([$deptCode]);
                    if ($chk->fetch()) {
                        $error = 'A department with this code already exists.';
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO departments (dept_code, dept_name, short_name, programme_id, head_of_dept, status) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$deptCode, $deptName, $shortName, $programmeId ?: null, $headOfDept ?: null, $status]);
                        $success = 'Department created successfully.';
                        logActivity($db, $_SESSION['user_id'], 'Create Department', "Created department $deptCode");
                    }
                } else { // edit
                    // Check duplicate code excluding current
                    $chk = $db->prepare("SELECT dept_id FROM departments WHERE dept_code = ? AND dept_id != ?");
                    $chk->execute([$deptCode, $deptId]);
                    if ($chk->fetch()) {
                        $error = 'A department with this code already exists.';
                    } else {
                        $stmt = $db->prepare("
                            UPDATE departments 
                            SET dept_code = ?, dept_name = ?, short_name = ?, programme_id = ?, head_of_dept = ?, status = ? 
                            WHERE dept_id = ?
                        ");
                        $stmt->execute([$deptCode, $deptName, $shortName, $programmeId ?: null, $headOfDept ?: null, $status, $deptId]);
                        $success = 'Department updated successfully.';
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

// Fetch all departments
$depts = $db->query("
    SELECT d.*, pr.programme_name 
    FROM departments d 
    LEFT JOIN programmes pr ON d.programme_id = pr.programme_id 
    ORDER BY d.dept_code
")->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Department Management</h4>
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
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title fw-bold">Departments</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-2"></i> Create Department
            </button>
        </div>

        <div class="mamcet-card">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-building-columns text-primary me-2"></i> Department Registries</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="deptsTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Department Name</th>
                                <th>Short Name</th>
                                <th>Programme</th>
                                <th>Department Head</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($depts as $dept): ?>
                                <tr>
                                    <td><span class="badge bg-dark px-3 py-2"><?php echo esc($dept['dept_code']); ?></span></td>
                                    <td><span class="fw-bold text-dark"><?php echo esc($dept['dept_name']); ?></span></td>
                                    <td><?php echo esc($dept['short_name']); ?></td>
                                    <td><?php echo esc($dept['programme_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo esc($dept['head_of_dept'] ?? 'Not Assigned'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $dept['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($dept['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($dept)); ?>)">
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
<div class="modal fade" id="deptModal" tabindex="-1" aria-labelledby="deptModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="departments.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="dept_id" id="form_dept_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="deptModalLabel">Create Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Department Code</label>
                        <input type="text" name="dept_code" id="modal_dept_code" class="form-control" placeholder="e.g. CSE" required>
                        <div class="form-text">Short unique code identifying the department (uppercase).</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department Name</label>
                        <input type="text" name="dept_name" id="modal_dept_name" class="form-control" placeholder="e.g. Computer Science and Engineering" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Short Name</label>
                        <input type="text" name="short_name" id="modal_short_name" class="form-control" placeholder="e.g. Comp. Sci." required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Associated Programme</label>
                        <select name="programme_id" id="modal_programme_id" class="form-select">
                            <option value="">Select Programme</option>
                            <?php foreach ($programmes as $pr): ?>
                                <option value="<?php echo $pr['programme_id']; ?>"><?php echo esc($pr['programme_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Head of Department (HoD)</label>
                        <input type="text" name="head_of_dept" id="modal_head_of_dept" class="form-control" placeholder="e.g. Dr. A. Rajesh">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="modal_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitForm">Create Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCreateModal() {
    $('#deptModalLabel').text('Create Department');
    $('#form_action').val('create');
    $('#form_dept_id').val('0');
    $('#modal_dept_code').val('');
    $('#modal_dept_name').val('');
    $('#modal_short_name').val('');
    $('#modal_programme_id').val('');
    $('#modal_head_of_dept').val('');
    $('#modal_status').val('active');
    $('#btnSubmitForm').text('Create Department');
}

function openEditModal(dept) {
    $('#deptModalLabel').text('Edit Department');
    $('#form_action').val('edit');
    $('#form_dept_id').val(dept.dept_id);
    $('#modal_dept_code').val(dept.dept_code);
    $('#modal_dept_name').val(dept.dept_name);
    $('#modal_short_name').val(dept.short_name);
    $('#modal_programme_id').val(dept.programme_id || '');
    $('#modal_head_of_dept').val(dept.head_of_dept || '');
    $('#modal_status').val(dept.status);
    $('#btnSubmitForm').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('deptModal'));
    modal.show();
}
</script>

