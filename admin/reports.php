<?php
// MAMCET Placement & Learning Portal - Advanced Report Exporter Page

$db = Database::getInstance()->getConnection();

// -------------------------------------------------------------
// HANDLE CSV DOWNLOAD EXPORT TRIGGER
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once(__DIR__ . '/../includes/auth.php');
    if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
        exit('Unauthorized access.');
    }
    
    // Get filter inputs
    $deptId = $_GET['dept_id'] ?? '';
    $batchId = $_GET['batch_id'] ?? '';
    $willingness = $_GET['placement_willingness'] ?? '';
    $status = $_GET['placement_status'] ?? '';
    $minCgpa = $_GET['min_cgpa'] ?? '';
    $maxArrears = $_GET['max_standing_arrears'] ?? '';
    $skills = trim($_GET['skills_search'] ?? '');
    $city = trim($_GET['city_search'] ?? '');
    
    // Build dynamic SQL
    $sql = "
        SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name, sec.section_name,
               s.mobile_number, s.email, s.alt_mobile_number, s.gender,
               sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears,
               s.placement_status, s.placement_willingness,
               sp.linkedin_url, sp.github_url, sp.skills, sp.city, sp.district
        FROM students s
        JOIN departments d ON s.dept_id = d.dept_id
        JOIN batches b ON s.batch_id = b.batch_id
        LEFT JOIN sections sec ON s.section_id = sec.section_id
        LEFT JOIN student_academics sa ON s.student_id = sa.student_id
        LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
        WHERE 1=1
    ";
    $params = [];
    
    if (!empty($deptId)) {
        $sql .= " AND s.dept_id = ?";
        $params[] = $deptId;
    }
    if (!empty($batchId)) {
        $sql .= " AND s.batch_id = ?";
        $params[] = $batchId;
    }
    if (!empty($willingness)) {
        $sql .= " AND s.placement_willingness = ?";
        $params[] = $willingness;
    }
    if (!empty($status)) {
        $sql .= " AND s.placement_status = ?";
        $params[] = $status;
    }
    if ($minCgpa !== '') {
        $sql .= " AND sa.current_cgpa >= ?";
        $params[] = (float)$minCgpa;
    }
    if ($maxArrears !== '') {
        $sql .= " AND sa.standing_arrears <= ?";
        $params[] = (int)$maxArrears;
    }
    if ($skills !== '') {
        $sql .= " AND sp.skills LIKE ?";
        $params[] = '%' . $skills . '%';
    }
    if ($city !== '') {
        $sql .= " AND sp.city LIKE ?";
        $params[] = '%' . $city . '%';
    }
    
    $sql .= " ORDER BY s.registration_number ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Output headers for CSV download
        $filename = "mamcet_placement_report_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Registration Number', 'Student Name', 'Department', 'Batch', 'Section',
            'Mobile Number', 'Email Address', 'Alternate Mobile', 'Gender',
            'Current CGPA', 'Standing Arrears', 'History of Arrears',
            'Placement Status', 'Placement Willingness',
            'LinkedIn URL', 'GitHub URL', 'Skills', 'City', 'District'
        ]);
        
        foreach ($records as $row) {
            fputcsv($output, [
                $row['registration_number'], $row['student_name'], $row['dept_code'], $row['batch_name'], $row['section_name'],
                $row['mobile_number'], $row['email'], $row['alt_mobile_number'], $row['gender'],
                $row['current_cgpa'], $row['standing_arrears'], $row['history_of_arrears'],
                $row['placement_status'], $row['placement_willingness'],
                $row['linkedin_url'], $row['github_url'], $row['skills'], $row['city'], $row['district']
            ]);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        exit('Export Error: ' . $e->getMessage());
    }
}

// -------------------------------------------------------------
// STANDARD PAGE LOAD & PREVIEW
// -------------------------------------------------------------
$pageTitle = 'Reports Exporter';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$activeSessionId = getActiveAcademicSessionId();

// Preview table data (limit 50 for performance preview)
$deptId = $_GET['dept_id'] ?? '';
$batchId = $_GET['batch_id'] ?? '';
$willingness = $_GET['placement_willingness'] ?? '';
$status = $_GET['placement_status'] ?? '';
$minCgpa = $_GET['min_cgpa'] ?? '';
$maxArrears = $_GET['max_standing_arrears'] ?? '';
$skills = trim($_GET['skills_search'] ?? '');
$city = trim($_GET['city_search'] ?? '');

$sql = "
    SELECT s.student_id, s.registration_number, s.student_name, d.dept_code, b.batch_name,
           sa.current_cgpa, sa.standing_arrears, s.placement_status, s.placement_willingness, sp.skills, sp.city
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
    WHERE 1=1
";
$params = [];

if (!empty($deptId)) {
    $sql .= " AND s.dept_id = ?";
    $params[] = $deptId;
}
if (!empty($batchId)) {
    $sql .= " AND s.batch_id = ?";
    $params[] = $batchId;
}
if (!empty($willingness)) {
    $sql .= " AND s.placement_willingness = ?";
    $params[] = $willingness;
}
if (!empty($status)) {
    $sql .= " AND s.placement_status = ?";
    $params[] = $status;
}
if ($minCgpa !== '') {
    $sql .= " AND sa.current_cgpa >= ?";
    $params[] = (float)$minCgpa;
}
if ($maxArrears !== '') {
    $sql .= " AND sa.standing_arrears <= ?";
    $params[] = (int)$maxArrears;
}
if ($skills !== '') {
    $sql .= " AND sp.skills LIKE ?";
    $params[] = '%' . $skills . '%';
}
if ($city !== '') {
    $sql .= " AND sp.city LIKE ?";
    $params[] = '%' . $city . '%';
}

$sql .= " ORDER BY s.registration_number ASC LIMIT 100"; // limit 100 for screen view

$stmt = $db->prepare($sql);
$stmt->execute($params);
$previewStudents = $stmt->fetchAll();

// Dropdowns
$allBatches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$allDepts = $db->query("SELECT * FROM departments ORDER BY dept_code")->fetchAll();
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fa-solid fa-file-chart-column text-primary me-2"></i>Placement Directory & Custom Reports</h1>
                <p class="text-muted small mb-0 mt-1">Generate multi-parameter recruitment lists and export formatted CSV reports for employers.</p>
            </div>
            <button type="button" class="btn btn-sm btn-success shadow-sm" onclick="triggerCsvExport()">
                <i class="fa-solid fa-file-csv me-1"></i> Export Filtered CSV
            </button>
        </div>

        <!-- EXPORT FILTERS CARD -->
        <div class="mamcet-card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="fa-solid fa-filter text-primary me-2"></i>Query Placement Directory</h6>
                <span class="badge bg-light text-muted border"><?php echo count($previewStudents); ?> Matches</span>
            </div>
            <div class="card-body">
                <form action="reports.php" method="GET" id="queryForm">
                    <div class="row g-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label">Department</label>
                            <select name="dept_id" class="form-select form-select-sm">
                                <option value="">All Departments</option>
                                <?php foreach ($allDepts as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $deptId == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Batch</label>
                            <select name="batch_id" class="form-select form-select-sm">
                                <option value="">All Batches</option>
                                <?php foreach ($allBatches as $b): ?>
                                    <option value="<?php echo $b['batch_id']; ?>" <?php echo $batchId == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Willingness</label>
                            <select name="placement_willingness" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <option value="Yes" <?php echo $willingness === 'Yes' ? 'selected' : ''; ?>>Willing Only</option>
                                <option value="No" <?php echo $willingness === 'No' ? 'selected' : ''; ?>>Not Willing Only</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Placement Status</label>
                            <select name="placement_status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <option value="Placed" <?php echo $status === 'Placed' ? 'selected' : ''; ?>>Placed</option>
                                <option value="Unplaced" <?php echo $status === 'Unplaced' ? 'selected' : ''; ?>>Unplaced</option>
                                <option value="Higher Studies" <?php echo $status === 'Higher Studies' ? 'selected' : ''; ?>>Higher Studies</option>
                                <option value="Entrepreneurship" <?php echo $status === 'Entrepreneurship' ? 'selected' : ''; ?>>Entrepreneurship</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-3 col-6">
                            <label class="form-label">Minimum CGPA</label>
                            <input type="number" step="0.01" name="min_cgpa" class="form-control form-control-sm" placeholder="e.g. 7.50" value="<?php echo esc($minCgpa); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Max Standing Arrears</label>
                            <input type="number" name="max_standing_arrears" class="form-control form-control-sm" placeholder="e.g. 0" value="<?php echo esc($maxArrears); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Skills Keyword</label>
                            <input type="text" name="skills_search" class="form-control form-control-sm" placeholder="e.g. Python, Java, SQL" value="<?php echo esc($skills); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">City / District</label>
                            <input type="text" name="city_search" class="form-control form-control-sm" placeholder="e.g. Trichy, Chennai" value="<?php echo esc($city); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-2 border-top">
                        <a href="reports.php" class="btn btn-sm btn-light border">
                            <i class="fa-solid fa-rotate-left me-1"></i> Reset
                        </a>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> Preview Query Results
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- QUERY PREVIEW RESULT TABLE -->
        <div class="mamcet-card">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="fa-solid fa-eye text-primary me-2"></i>Query Preview (Up to 100 students shown on screen)</h6>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="triggerCsvExport()">
                    <i class="fa-solid fa-download me-1"></i> Download Complete CSV
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="previewTable">
                        <thead>
                            <tr>
                                <th class="ps-3">Registration & Student</th>
                                <th>Dept / Batch</th>
                                <th>CGPA</th>
                                <th>Arrears</th>
                                <th>Placement Status</th>
                                <th>City / District</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($previewStudents)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-filter-circle-xmark fa-2x mb-2 opacity-50"></i>
                                        <p class="small mb-0">No student records match the active query parameters.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($previewStudents as $s): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <a href="student-view.php?student_id=<?php echo $s['student_id']; ?>" class="fw-bold text-dark text-decoration-none d-block">
                                                <?php echo esc($s['student_name']); ?>
                                            </a>
                                            <span class="badge bg-light text-muted border" style="font-size:0.72rem; font-family: monospace;">
                                                <?php echo esc($s['registration_number']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">
                                                <?php echo esc($s['dept_code']); ?>
                                            </span>
                                            <small class="text-muted d-block mt-1">Batch <?php echo esc($s['batch_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle fw-bold">
                                                <?php echo esc($s['current_cgpa'] ?? '0.00'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php $arr = (int)($s['standing_arrears'] ?? 0); ?>
                                            <span class="badge bg-<?php echo $arr > 0 ? 'danger' : 'success'; ?>-subtle text-<?php echo $arr > 0 ? 'danger' : 'success'; ?> border border-<?php echo $arr > 0 ? 'danger' : 'success'; ?>-subtle">
                                                <?php echo $arr; ?> Arrears
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?php echo esc($s['placement_status']); ?></span>
                                            <?php if ($s['placement_willingness'] === 'Yes'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Willing</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small">
                                            <?php echo esc($s['city'] ?: ($s['district'] ?: '-')); ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="student-view.php?student_id=<?php echo $s['student_id']; ?>" class="btn btn-sm btn-light border" title="View Profile">
                                                <i class="fa-solid fa-eye text-primary"></i>
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
function triggerCsvExport() {
    // Get active values and redirect directly to CSV export endpoint
    const form = document.getElementById('queryForm');
    const params = new URLSearchParams(new FormData(form)).toString();
    window.location.href = 'reports.php?export=csv&' + params;
}
</script>

