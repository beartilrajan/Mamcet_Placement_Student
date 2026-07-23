<?php
// MAMCET Placement & Learning Portal - Placement Announcements Management Page

$pageTitle = 'Placement Announcements';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$activeSessionId = getActiveAcademicSessionId();

// Handle Create / Edit / Delete Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['announcement_type'] ?? 'General';
        $companyName = trim($_POST['company_name'] ?? '');
        $jobRole = trim($_POST['job_role'] ?? '');
        $package = trim($_POST['package'] ?? '');
        $location = trim($_POST['job_location'] ?? '');
        $minCgpa = !empty($_POST['min_cgpa']) ? (float)$_POST['min_cgpa'] : 0.00;
        $maxArrears = !empty($_POST['max_standing_arrears']) ? (int)$_POST['max_standing_arrears'] : 99;
        $maxHistory = !empty($_POST['max_history_arrears']) ? (int)$_POST['max_history_arrears'] : 99;
        $willingnessReq = isset($_POST['placement_willingness_req']) ? 1 : 0;
        $googleFormUrl = trim($_POST['google_form_url'] ?? '');
        $googleSheetUrl = trim($_POST['google_sheet_url'] ?? '');
        $sheetVisible = isset($_POST['is_sheet_student_visible']) ? 1 : 0;
        $externalLink = trim($_POST['external_link'] ?? '');
        $expiryDate = $_POST['expiry_date'] ?? '';
        $priority = $_POST['priority'] ?? 'Medium';
        $status = $_POST['status'] ?? 'published';
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        
        $targetDepts = $_POST['departments'] ?? [];
        $targetBatches = $_POST['batches'] ?? [];
        
        if (empty($title) || empty($description) || empty($expiryDate) || empty($targetDepts) || empty($targetBatches)) {
            $error = 'Title, Description, Expiry Date, target Departments, and Batches are required.';
        } else {
            try {
                $db->beginTransaction();
                
                // Process File upload (Job description attachment) if exists
                $jdFilePath = '';
                if (isset($_FILES['jd_file']) && $_FILES['jd_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['jd_file'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($file['tmp_name']);
                        
                        $uploadDir = __DIR__ . '/../assets/uploads/attachments/';
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        
                        $safeFilename = 'jd_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file['name']);
                        if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
                            $jdFilePath = 'assets/uploads/attachments/' . $safeFilename;
                        }
                    }
                }
                
                if ($action === 'create') {
                    $stmt = $db->prepare("
                        INSERT INTO announcements (
                            title, description, announcement_type, company_name, job_role, package, job_location, 
                            min_cgpa, max_standing_arrears, max_history_arrears, placement_willingness_req,
                            session_id, google_form_url, google_sheet_url, is_sheet_student_visible, jd_file_path, external_link, 
                            publish_date, expiry_date, priority, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title, $description, $type, $companyName, $jobRole, $package, $location,
                        $minCgpa, $maxArrears, $maxHistory, $willingnessReq,
                        $activeSessionId, $googleFormUrl, $googleSheetUrl, $sheetVisible, $jdFilePath, $externalLink,
                        $expiryDate, $priority, $status, $_SESSION['user_id']
                    ]);
                    $newAnnId = $db->lastInsertId();
                    $annIdForMapping = $newAnnId;
                } else {
                    $annIdForMapping = $announcementId;
                    
                    // Retain old JD attachment if no new file is uploaded
                    if (empty($jdFilePath)) {
                        $stmtJd = $db->prepare("SELECT jd_file_path FROM announcements WHERE announcement_id = ?");
                        $stmtJd->execute([$announcementId]);
                        $jdFilePath = $stmtJd->fetchColumn() ?: '';
                    }
                    
                    $stmt = $db->prepare("
                        UPDATE announcements 
                        SET title = ?, description = ?, announcement_type = ?, company_name = ?, job_role = ?, package = ?, job_location = ?, 
                            min_cgpa = ?, max_standing_arrears = ?, max_history_arrears = ?, placement_willingness_req = ?,
                            google_form_url = ?, google_sheet_url = ?, is_sheet_student_visible = ?, jd_file_path = ?, external_link = ?, 
                            expiry_date = ?, priority = ?, status = ?
                        WHERE announcement_id = ?
                    ");
                    $stmt->execute([
                        $title, $description, $type, $companyName, $jobRole, $package, $location,
                        $minCgpa, $maxArrears, $maxHistory, $willingnessReq,
                        $googleFormUrl, $googleSheetUrl, $sheetVisible, $jdFilePath, $externalLink,
                        $expiryDate, $priority, $status, $announcementId
                    ]);
                    
                    // Clear old mappings
                    $db->prepare("DELETE FROM announcement_departments WHERE announcement_id = ?")->execute([$announcementId]);
                    $db->prepare("DELETE FROM announcement_batches WHERE announcement_id = ?")->execute([$announcementId]);
                }
                
                // Insert Department & Batch mappings
                $stmtDept = $db->prepare("INSERT INTO announcement_departments (announcement_id, dept_id) VALUES (?, ?)");
                foreach ($targetDepts as $dId) {
                    $stmtDept->execute([$annIdForMapping, $dId]);
                }
                
                $stmtBatch = $db->prepare("INSERT INTO announcement_batches (announcement_id, batch_id) VALUES (?, ?)");
                foreach ($targetBatches as $bId) {
                    $stmtBatch->execute([$annIdForMapping, $bId]);
                }
                
                $db->commit();
                $success = 'Announcement ' . ($action === 'create' ? 'published' : 'updated') . ' successfully.';
                logActivity($db, $_SESSION['user_id'], 'Manage Announcement', "Published/Updated announcement $title");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        if ($announcementId > 0) {
            try {
                $db->prepare("DELETE FROM announcements WHERE announcement_id = ?")->execute([$announcementId]);
                $success = 'Announcement deleted successfully.';
                logActivity($db, $_SESSION['user_id'], 'Delete Announcement', "Deleted announcement ID $announcementId");
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch helper resources
$departments = $db->query("SELECT dept_id, dept_code FROM departments ORDER BY dept_code")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY graduation_year DESC")->fetchAll();

// Fetch announcements for active session
$stmtAnn = $db->prepare("
    SELECT a.*, GROUP_CONCAT(DISTINCT d.dept_code SEPARATOR ', ') AS target_depts, GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR ', ') AS target_batches
    FROM announcements a
    LEFT JOIN announcement_departments ad ON a.announcement_id = ad.announcement_id
    LEFT JOIN departments d ON ad.dept_id = d.dept_id
    LEFT JOIN announcement_batches ab ON a.announcement_id = ab.announcement_id
    LEFT JOIN batches b ON ab.batch_id = b.batch_id
    WHERE a.session_id = ?
    GROUP BY a.announcement_id
    ORDER BY a.publish_date DESC
");
$stmtAnn->execute([$activeSessionId]);
$announcements = $stmtAnn->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Placement Announcement Dashboard</h4>
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
            <h1 class="page-title fw-bold">Announcements</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#announcementModal" onclick="openCreateModal()">
                <i class="fa-solid fa-bullhorn me-2"></i> Publish Drive / Notice
            </button>
        </div>

        <div class="mamcet-card">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold mb-0"><i class="fa-solid fa-list-check text-primary me-2"></i> Published Placements & Alerts</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="annTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>Announcement</th>
                                <th>Targets (Dept / Batch)</th>
                                <th>Eligibility Criteria</th>
                                <th>Form Links</th>
                                <th>Expiry</th>
                                <th>Priority</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark d-block"><?php echo esc($ann['title']); ?></span>
                                        <span class="badge bg-light text-primary border mb-1"><?php echo esc($ann['announcement_type']); ?></span>
                                        <?php if (!empty($ann['company_name'])): ?>
                                            <span class="small text-muted d-block">Company: <strong><?php echo esc($ann['company_name']); ?></strong> (<?php echo esc($ann['job_role']); ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="small d-block text-muted">Depts: <strong class="text-dark"><?php echo esc($ann['target_depts']); ?></strong></span>
                                        <span class="small d-block text-muted">Batches: <strong class="text-dark"><?php echo esc($ann['target_batches']); ?></strong></span>
                                    </td>
                                    <td>
                                        <span class="small d-block text-muted">Min CGPA: <strong><?php echo esc($ann['min_cgpa']); ?></strong></span>
                                        <span class="small d-block text-muted">Arrears: <strong><= <?php echo (int)$ann['max_standing_arrears']; ?></strong></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($ann['google_form_url'])): ?>
                                            <a href="<?php echo esc($ann['google_form_url']); ?>" target="_blank" class="badge bg-primary-subtle text-primary border text-decoration-none d-inline-block mb-1"><i class="fa-solid fa-square-poll-horizontal me-1"></i> Form Link</a>
                                        <?php endif; ?>
                                        <?php if (!empty($ann['google_sheet_url'])): ?>
                                            <a href="<?php echo esc($ann['google_sheet_url']); ?>" target="_blank" class="badge bg-success-subtle text-success border text-decoration-none d-inline-block" title="Google Sheet is visible to Officers only"><i class="fa-solid fa-table me-1"></i> Responses</a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="small text-dark fw-bold"><?php echo esc(date('d M Y', strtotime($ann['expiry_date']))); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $ann['priority'] === 'High' ? 'bg-danger' : ($ann['priority'] === 'Medium' ? 'bg-primary' : 'bg-secondary'); ?>">
                                            <?php echo $ann['priority']; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ann)); ?>)">
                                            <i class="fa-solid fa-pencil text-secondary"></i> Edit
                                        </button>
                                        <form action="announcements.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this announcement?')">
                                            <?php csrfInput(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="announcement_id" value="<?php echo $ann['announcement_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light border"><i class="fa-solid fa-trash text-danger"></i></button>
                                        </form>
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

<!-- Modal Form -->
<div class="modal fade" id="announcementModal" tabindex="-1" aria-labelledby="annModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content text-dark">
            <form action="announcements.php" method="POST" enctype="multipart/form-data">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="announcement_id" id="form_announcement_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="annModalLabel">Publish Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body" style="max-height: 520px; overflow-y: auto;">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Announcement Title</label>
                            <input type="text" name="title" id="modal_title" class="form-control" placeholder="e.g. TCS Ninja Recruitment Drive 2027" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Announcement Type</label>
                            <select name="announcement_type" id="modal_type" class="form-select">
                                <option value="General">General Notice</option>
                                <option value="Placement Drive" selected>Placement Drive</option>
                                <option value="Training">Training Circular</option>
                                <option value="Test Schedule">Test Schedule</option>
                                <option value="Interview Schedule">Interview Schedule</option>
                                <option value="Deadline Reminder">Deadline Reminder</option>
                                <option value="Circular">College Circular</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="modal_company" class="form-control" placeholder="e.g. TCS">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Job Role</label>
                            <input type="text" name="job_role" id="modal_role" class="form-control" placeholder="e.g. Systems Engineer">
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <label class="form-label">Package (LPA)</label>
                            <input type="text" name="package" id="modal_package" class="form-control" placeholder="e.g. 3.6 LPA">
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" name="job_location" id="modal_location" class="form-control" placeholder="e.g. Chennai">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Job Description / Circular Body</label>
                        <textarea name="description" id="modal_desc" class="form-control" rows="4" placeholder="Enter circular details, registration processes, etc." required></textarea>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Eligibility Constraints</h6>
                    <div class="row">
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">Minimum CGPA</label>
                            <input type="number" step="0.01" name="min_cgpa" id="modal_cgpa" class="form-control" value="0.00">
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">Max Standing Arrears</label>
                            <input type="number" name="max_standing_arrears" id="modal_max_arrears" class="form-control" value="99">
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">Max History Arrears</label>
                            <input type="number" name="max_history_arrears" id="modal_max_history" class="form-control" value="99">
                        </div>
                        <div class="col-md-3 col-6 mb-3 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="placement_willingness_req" id="modal_willing" value="1" checked>
                                <label class="form-check-label" for="modal_willing">Willing Students Only</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Target Departments & Batches</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Eligible Departments</label>
                            <div class="p-2 border rounded" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($departments as $d): ?>
                                    <div class="form-check">
                                        <input class="form-check-input dept-chk" type="checkbox" name="departments[]" value="<?php echo $d['dept_id']; ?>" id="chk_d_<?php echo $d['dept_id']; ?>">
                                        <label class="form-check-label" for="chk_d_<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_code']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Eligible Batches</label>
                            <div class="p-2 border rounded" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($batches as $b): ?>
                                    <div class="form-check">
                                        <input class="form-check-input batch-chk" type="checkbox" name="batches[]" value="<?php echo $b['batch_id']; ?>" id="chk_b_<?php echo $b['batch_id']; ?>">
                                        <label class="form-check-label" for="chk_b_<?php echo $b['batch_id']; ?>"><?php echo esc($b['batch_name']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Google Links & Assets</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Google Form Registration URL</label>
                            <input type="url" name="google_form_url" id="modal_form_url" class="form-control" placeholder="https://docs.google.com/forms/...">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Google Response Sheet Link (Internal)</label>
                            <input type="url" name="google_sheet_url" id="modal_sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/...">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="is_sheet_student_visible" id="modal_sheet_visible" value="1">
                                <label class="form-check-label small" for="modal_sheet_visible">Visible to students</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Upload Job Description File (PDF only)</label>
                            <input type="file" name="jd_file" class="form-control" accept="application/pdf">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">External Website Link</label>
                            <input type="url" name="external_link" id="modal_external" class="form-control" placeholder="https://careers.tcs.com">
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expiry / Registration Deadline</label>
                            <input type="date" name="expiry_date" id="modal_expiry" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Priority Alert</label>
                            <select name="priority" id="modal_priority" class="form-select">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Publishing Status</label>
                            <select name="status" id="modal_status" class="form-select">
                                <option value="published" selected>Published</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitForm">Publish Announcement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    $('#annTable').DataTable({
        "order": [[4, "asc"]],
        "pageLength": 10
    });
});

function openCreateModal() {
    $('#annModalLabel').text('Publish Announcement');
    $('#form_action').val('create');
    $('#form_announcement_id').val('0');
    
    $('#modal_title').val('');
    $('#modal_type').val('Placement Drive');
    $('#modal_company').val('');
    $('#modal_role').val('');
    $('#modal_package').val('');
    $('#modal_location').val('');
    $('#modal_desc').val('');
    
    $('#modal_cgpa').val('0.00');
    $('#modal_max_arrears').val('99');
    $('#modal_max_history').val('99');
    $('#modal_willing').prop('checked', true);
    
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    $('#modal_form_url').val('');
    $('#modal_sheet_url').val('');
    $('#modal_sheet_visible').prop('checked', false);
    $('#modal_external').val('');
    $('#modal_expiry').val('');
    $('#modal_priority').val('Medium');
    $('#modal_status').val('published');
    
    $('#btnSubmitForm').text('Publish Announcement');
}

function openEditModal(ann) {
    $('#annModalLabel').text('Edit Announcement');
    $('#form_action').val('edit');
    $('#form_announcement_id').val(ann.announcement_id);
    
    $('#modal_title').val(ann.title);
    $('#modal_type').val(ann.announcement_type);
    $('#modal_company').val(ann.company_name || '');
    $('#modal_role').val(ann.job_role || '');
    $('#modal_package').val(ann.package || '');
    $('#modal_location').val(ann.job_location || '');
    $('#modal_desc').val(ann.description);
    
    $('#modal_cgpa').val(ann.min_cgpa);
    $('#modal_max_arrears').val(ann.max_standing_arrears);
    $('#modal_max_history').val(ann.max_history_arrears);
    $('#modal_willing').prop('checked', ann.placement_willingness_req == 1);
    
    // Clear check boxes
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    // Fetch and check target mappings via AJax or parse the concatenated list.
    // Fetching department and batch mappings from the parsed concatenation
    if (ann.target_depts) {
        ann.target_depts.split(', ').forEach(code => {
            $('.dept-chk').each(function() {
                if ($(this).next('label').text().trim() === code) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    if (ann.target_batches) {
        ann.target_batches.split(', ').forEach(name => {
            $('.batch-chk').each(function() {
                if ($(this).next('label').text().trim() === name) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    
    $('#modal_form_url').val(ann.google_form_url || '');
    $('#modal_sheet_url').val(ann.google_sheet_url || '');
    $('#modal_sheet_visible').prop('checked', ann.is_sheet_student_visible == 1);
    $('#modal_external').val(ann.external_link || '');
    $('#modal_expiry').val(ann.expiry_date);
    $('#modal_priority').val(ann.priority);
    $('#modal_status').val(ann.status);
    
    $('#btnSubmitForm').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('announcementModal'));
    modal.show();
}
</script>

