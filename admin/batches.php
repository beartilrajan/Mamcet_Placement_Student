<?php
// MAMCET Placement & Learning Portal - Batches Management Page

$pageTitle = 'Programme Batches';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$activeSessionId = getActiveAcademicSessionId();

// Handle Create / Edit Batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $batchName = trim($_POST['batch_name'] ?? '');
        $admissionYear = (int)($_POST['admission_year'] ?? 0);
        $graduationYear = (int)($_POST['graduation_year'] ?? 0);
        $programmeId = (int)($_POST['programme_id'] ?? 0);
        $programmeDuration = (int)($_POST['programme_duration'] ?? 4);
        $currentYearOfStudy = $_POST['current_year_of_study'] ?? 'Final Year';
        $status = $_POST['status'] ?? 'active';
        $description = trim($_POST['description'] ?? '');
        $batchId = (int)($_POST['batch_id'] ?? 0);
        
        if (empty($batchName) || $admissionYear <= 0 || $graduationYear <= 0 || $programmeId <= 0) {
            $error = 'All fields except description are required.';
        } elseif ($graduationYear <= $admissionYear) {
            $error = 'Graduation year must be greater than admission year.';
        } else {
            try {
                $db->beginTransaction();
                
                if ($action === 'create') {
                    // Check duplicate name
                    $chk = $db->prepare("SELECT batch_id FROM batches WHERE batch_name = ?");
                    $chk->execute([$batchName]);
                    if ($chk->fetch()) {
                        $error = 'A batch with this name already exists.';
                    } else {
                        // Insert batch
                        $stmt = $db->prepare("
                            INSERT INTO batches (batch_name, admission_year, graduation_year, programme_id, programme_duration, current_year_of_study, status, description) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$batchName, $admissionYear, $graduationYear, $programmeId, $programmeDuration, $currentYearOfStudy, $status, $description]);
                        $newBatchId = $db->lastInsertId();
                        
                        // Map to active academic session
                        $stmtSession = $db->prepare("
                            INSERT INTO batch_academic_sessions (batch_id, session_id, year_of_study) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE year_of_study = VALUES(year_of_study)
                        ");
                        $stmtSession->execute([$newBatchId, $activeSessionId, $currentYearOfStudy]);
                        
                        $success = 'Batch created successfully.';
                        logActivity($db, $_SESSION['user_id'], 'Create Batch', "Created batch $batchName");
                    }
                } else { // edit
                    // Check duplicate name excluding current
                    $chk = $db->prepare("SELECT batch_id FROM batches WHERE batch_name = ? AND batch_id != ?");
                    $chk->execute([$batchName, $batchId]);
                    if ($chk->fetch()) {
                        $error = 'A batch with this name already exists.';
                    } else {
                        // Update batch
                        $stmt = $db->prepare("
                            UPDATE batches 
                            SET batch_name = ?, admission_year = ?, graduation_year = ?, programme_id = ?, programme_duration = ?, current_year_of_study = ?, status = ?, description = ? 
                            WHERE batch_id = ?
                        ");
                        $stmt->execute([$batchName, $admissionYear, $graduationYear, $programmeId, $programmeDuration, $currentYearOfStudy, $status, $description, $batchId]);
                        
                        // Update/insert active session mapping
                        $stmtSession = $db->prepare("
                            INSERT INTO batch_academic_sessions (batch_id, session_id, year_of_study) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE year_of_study = VALUES(year_of_study)
                        ");
                        $stmtSession->execute([$batchId, $activeSessionId, $currentYearOfStudy]);
                        
                        $success = 'Batch updated successfully.';
                        logActivity($db, $_SESSION['user_id'], 'Update Batch', "Updated batch $batchName");
                    }
                }
                
                if (empty($error)) {
                    $db->commit();
                } else {
                    $db->rollBack();
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all programmes for selector
$programmes = $db->query("SELECT * FROM programmes ORDER BY programme_name")->fetchAll();

// Fetch batches joined with programme name and active session mapping
$stmt = $db->prepare("
    SELECT b.*, pr.programme_name, bas.year_of_study AS active_session_year_of_study 
    FROM batches b 
    JOIN programmes pr ON b.programme_id = pr.programme_id 
    LEFT JOIN batch_academic_sessions bas ON b.batch_id = bas.batch_id AND bas.session_id = ?
    ORDER BY b.graduation_year DESC
");
$stmt->execute([$activeSessionId]);
$batches = $stmt->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Batch Management</h4>
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
            <h1 class="page-title fw-bold">Programme Batches</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#batchModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-2"></i> Create Batch
            </button>
        </div>

        <div class="mamcet-card">
            <div class="card-header">
                <h5 class="card-title fw-bold">
                    <i class="fa-solid fa-graduation-cap text-primary me-2"></i> Batches for Session: <?php echo esc($activeSessionName); ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="batchesTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>Batch Name</th>
                                <th>Programme</th>
                                <th>Duration</th>
                                <th>Admission Year</th>
                                <th>Graduation Year</th>
                                <th>Year of Study (Active Session)</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td><span class="fw-bold text-dark"><?php echo esc($batch['batch_name']); ?></span></td>
                                    <td><?php echo esc($batch['programme_name']); ?></td>
                                    <td><?php echo esc($batch['programme_duration']) . ' Years'; ?></td>
                                    <td><?php echo esc($batch['admission_year']); ?></td>
                                    <td><?php echo esc($batch['graduation_year']); ?></td>
                                    <td>
                                        <span class="text-primary fw-bold">
                                            <?php echo esc($batch['active_session_year_of_study'] ?? $batch['current_year_of_study'] ?? 'Not Configured'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $batch['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($batch['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($batch)); ?>)">
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
<div class="modal fade" id="batchModal" tabindex="-1" aria-labelledby="batchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="batches.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="batch_id" id="form_batch_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="batchModalLabel">Create Batch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Batch Name</label>
                        <input type="text" name="batch_name" id="modal_batch_name" class="form-control" placeholder="e.g. 2023–2027" required>
                        <div class="form-text">Typically represents complete duration (e.g. 2023–2027).</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Programme</label>
                        <select name="programme_id" id="modal_programme_id" class="form-select" required>
                            <option value="">Select Programme</option>
                            <?php foreach ($programmes as $pr): ?>
                                <option value="<?php echo $pr['programme_id']; ?>"><?php echo esc($pr['programme_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Admission Year</label>
                            <input type="number" name="admission_year" id="modal_admission_year" class="form-control" placeholder="e.g. 2023" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Graduation Year</label>
                            <input type="number" name="graduation_year" id="modal_graduation_year" class="form-control" placeholder="e.g. 2027" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Duration (Years)</label>
                            <input type="number" name="programme_duration" id="modal_programme_duration" class="form-control" value="4" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Study status (Active session)</label>
                            <select name="current_year_of_study" id="modal_current_year_of_study" class="form-select">
                                <option value="First Year">First Year</option>
                                <option value="Second Year">Second Year</option>
                                <option value="Third Year">Third Year</option>
                                <option value="Final Year" selected>Final Year</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="modal_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="modal_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitForm">Create Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCreateModal() {
    $('#batchModalLabel').text('Create Batch');
    $('#form_action').val('create');
    $('#form_batch_id').val('0');
    $('#modal_batch_name').val('');
    $('#modal_programme_id').val('');
    $('#modal_admission_year').val('');
    $('#modal_graduation_year').val('');
    $('#modal_programme_duration').val('4');
    $('#modal_current_year_of_study').val('Final Year');
    $('#modal_status').val('active');
    $('#modal_description').val('');
    $('#btnSubmitForm').text('Create Batch');
}

function openEditModal(batch) {
    $('#batchModalLabel').text('Edit Batch');
    $('#form_action').val('edit');
    $('#form_batch_id').val(batch.batch_id);
    $('#modal_batch_name').val(batch.batch_name);
    $('#modal_programme_id').val(batch.programme_id);
    $('#modal_admission_year').val(batch.admission_year);
    $('#modal_graduation_year').val(batch.graduation_year);
    $('#modal_programme_duration').val(batch.programme_duration);
    $('#modal_current_year_of_study').val(batch.active_session_year_of_study || batch.current_year_of_study);
    $('#modal_status').val(batch.status);
    $('#modal_description').val(batch.description);
    $('#btnSubmitForm').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('batchModal'));
    modal.show();
}
</script>

