<?php
// MAMCET Placement & Learning Portal - Dataset Manager (Deprecated - Redirecting to Student Roster)
require_once(__DIR__ . '/../includes/auth.php');
header("Location: students.php");
exit;
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
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

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
            <div>
                <h1 class="page-title"><i class="fa-solid fa-database text-primary me-2"></i>Dataset Worksheet Manager</h1>
                <p class="text-muted small mb-0 mt-1">Manage departmental student datasets, auto-detected Excel files, and roster sync.</p>
            </div>
            <button class="btn btn-sm btn-primary shadow-sm" id="btnScanDatasets">
                <i class="fa-solid fa-arrows-rotate me-1" id="scanIcon"></i> Scan data/ Folder
            </button>
        </div>

        <!-- Micro-KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="compact-kpi-bar">
            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-file-excel"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Discovered Files</div>
                    <div class="compact-kpi-value text-primary"><?php echo count($files); ?> Worksheets</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Imported to Roster</div>
                    <div class="compact-kpi-value text-success"><?php echo count(array_filter($files, fn($f) => $f['import_status'] === 'imported')); ?> Files</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Pending Ingestion</div>
                    <div class="compact-kpi-value text-warning"><?php echo count(array_filter($files, fn($f) => $f['import_status'] !== 'imported')); ?> Worksheets</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Departments</div>
                    <div class="compact-kpi-value text-info"><?php echo count($departments); ?> Configured</div>
                </div>
            </div>
        </div>

        <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size:0.82rem; border-radius: 8px;">
            <i class="fa-solid fa-circle-info text-info"></i>
            <div>The system checks the <code>data/</code> folder for Excel files (<code>.xlsx</code>, <code>.csv</code>) and matches them to a department based on filename. Click <strong>Scan data/ Folder</strong> if you add new worksheets!</div>
        </div>

        <div class="mamcet-card">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="fa-solid fa-folder-open text-primary me-2"></i>Scanned Placement Worksheets</h6>
                <span class="badge bg-light text-muted border"><?php echo count($files); ?> Worksheets</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="datasetsTable">
                        <thead>
                            <tr>
                                <th class="ps-3">File Name</th>
                                <th>Size</th>
                                <th>Detected Department</th>
                                <th>Validation Stats</th>
                                <th>Import Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($files)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-file-excel fa-2x mb-2 opacity-50"></i>
                                        <p class="small mb-0">No Excel worksheets detected in <code>data/</code> directory.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($files as $file): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-file-excel <?php echo $file['import_status'] === 'imported' ? 'text-success' : 'text-primary'; ?>" style="font-size: 1.25rem;"></i>
                                                <div>
                                                    <span class="fw-bold text-dark d-block"><?php echo esc($file['filename']); ?></span>
                                                    <small class="text-muted" style="font-size: 0.72rem;">Modified: <?php echo esc(date('d M Y, h:i A', strtotime($file['last_modified'] ?? 'now'))); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-muted small"><?php echo formatSizeUnits($file['filesize']); ?></td>
                                        <td>
                                            <form action="dataset-manager.php" method="POST" class="d-flex align-items-center gap-2">
                                                <?php csrfInput(); ?>
                                                <input type="hidden" name="action" value="update_dept">
                                                <input type="hidden" name="file_id" value="<?php echo $file['file_id']; ?>">
                                                
                                                <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; min-width: 130px; font-size: 0.8rem;">
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
                                                <span class="badge bg-light text-dark border" style="font-size:0.72rem;">Rows: <?php echo $file['total_rows']; ?></span>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size:0.72rem;">Valid: <?php echo $file['valid_rows']; ?></span>
                                                <?php if ($file['invalid_rows'] > 0): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" style="font-size:0.72rem;">Invalid: <?php echo $file['invalid_rows']; ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Not verified yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($file['import_status'] === 'imported'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Imported
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                    <i class="fa-solid fa-clock me-1"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
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

