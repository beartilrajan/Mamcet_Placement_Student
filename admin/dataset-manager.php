<?php
// MAMCET Placement & Learning Portal - Dataset Manager Dashboard

$pageTitle = 'Dataset Manager';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle manual department reassignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dept') {
    verifyCsrfRequest();
    $fileId = (int)($_POST['file_id'] ?? 0);
    $deptId = (int)($_POST['dept_id'] ?? 0);
    
    if ($fileId > 0) {
        try {
            $stmt = $db->prepare("UPDATE dataset_files SET detected_dept_id = ? WHERE file_id = ?");
            $stmt->execute([$deptId ?: null, $fileId]);
            $success = 'Department mapping updated successfully.';
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch all departments
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();

// Fetch scanned files
$files = $db->query("
    SELECT f.*, d.dept_name, d.dept_code 
    FROM dataset_files f 
    LEFT JOIN departments d ON f.detected_dept_id = d.dept_id 
    ORDER BY f.filename
")->fetchAll();

// Helper to format bytes to human readable sizes
function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Excel Dataset Manager</h4>
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
            <h1 class="page-title fw-bold">Dataset Manager</h1>
            <button class="btn btn-primary" id="btnScanDatasets">
                <i class="fa-solid fa-arrows-rotate me-2" id="scanIcon"></i> Scan data/ Folder
            </button>
        </div>

        <div class="alert alert-info py-3 mb-4">
            <i class="fa-solid fa-circle-info me-2"></i> The system checks the <code>data/</code> folder for Excel files (<code>.xlsx</code>, <code>.xls</code>, <code>.csv</code>) and attempts to match them to a department based on their filenames. Re-run scan if you add new worksheets!
        </div>

        <div class="mamcet-card">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-folder-open text-primary me-2"></i> Scanned Placement Files</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="datasetsTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Detected Department</th>
                                <th>Validation Stats</th>
                                <th>Import Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($files)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-file-excel fa-3x mb-3 text-secondary" style="opacity:0.5;"></i>
                                        <p class="mb-0">No Excel worksheets detected in <code>data/</code> directory.</p>
                                        <small>Make sure Excel files exist in the project folders.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($files as $file): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <i class="fa-solid fa-file-excel fa-2x <?php echo $file['import_status'] === 'imported' ? 'text-success' : 'text-primary'; ?>"></i>
                                                <div>
                                                    <span class="fw-bold text-dark d-block"><?php echo esc($file['filename']); ?></span>
                                                    <small class="text-muted">Modified: <?php echo esc(date('d M Y h:i A', strtotime($session['last_modified'] ?? $file['last_modified']))); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo formatSizeUnits($file['filesize']); ?></td>
                                        <td>
                                            <form action="dataset-manager.php" method="POST" class="d-flex align-items-center gap-2">
                                                <?php csrfInput(); ?>
                                                <input type="hidden" name="action" value="update_dept">
                                                <input type="hidden" name="file_id" value="<?php echo $file['file_id']; ?>">
                                                
                                                <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; min-width: 140px;">
                                                    <option value="">Unassigned</option>
                                                    <?php foreach ($departments as $d): ?>
                                                        <option value="<?php echo $d['dept_id']; ?>" <?php echo $file['detected_dept_id'] == $d['dept_id'] ? 'selected' : ''; ?>>
                                                            <?php echo esc($d['dept_code']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($file['total_rows'] > 0): ?>
                                                <span class="small d-block">Rows: <strong><?php echo $file['total_rows']; ?></strong></span>
                                                <span class="small text-success">Valid: <strong><?php echo $file['valid_rows']; ?></strong></span> | 
                                                <span class="small text-danger">Invalid: <strong><?php echo $file['invalid_rows']; ?></strong></span> | 
                                                <span class="small text-warning">Dupes: <strong><?php echo $file['duplicate_rows']; ?></strong></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not verified yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($file['import_status'] === 'imported'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> Imported</span>
                                                <?php if ($file['last_imported_at']): ?>
                                                    <small class="d-block text-muted mt-1" style="font-size:0.7rem;"><?php echo date('d M Y', strtotime($file['last_imported_at'])); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="import-preview.php?file_id=<?php echo $file['file_id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fa-solid fa-file-import me-1"></i> Preview & Import
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    $('#btnScanDatasets').on('click', function() {
        const btn = $(this);
        const icon = $('#scanIcon');
        
        btn.prop('disabled', true);
        icon.addClass('fa-spin');
        
        $.ajax({
            url: '../api/scan-datasets.php',
            type: 'POST',
            data: {
                csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
            },
            dataType: 'json',
            success: function(response) {
                btn.prop('disabled', false);
                icon.removeClass('fa-spin');
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Scan Complete',
                        text: 'Discovered ' + response.count + ' files inside data/ directory.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Scan Failed', response.message || 'Error occurred during scan', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                icon.removeClass('fa-spin');
                Swal.fire('Error', 'Communication failure with the server', 'error');
            }
        });
    });
});
</script>

