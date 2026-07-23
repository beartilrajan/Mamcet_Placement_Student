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
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Report Center</h4>
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
        
        <!-- EXPORT FILTERS CARD -->
        <div class="mamcet-card mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-filter text-primary me-2"></i> Query Placement Directory</h5>
            </div>
            <div class="card-body">
                <form action="reports.php" method="GET" id="queryForm">
                    <div class="row g-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Department</label>
                            <select name="dept_id" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($allDepts as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $deptId == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Batch</label>
                            <select name="batch_id" class="form-select">
                                <option value="">All Batches</option>
                                <?php foreach ($allBatches as $b): ?>
                                    <option value="<?php echo $b['batch_id']; ?>" <?php echo $batchId == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Willingness</label>
                            <select name="placement_willingness" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="Yes" <?php echo $willingness === 'Yes' ? 'selected' : ''; ?>>Willing Only</option>
                                <option value="No" <?php echo $willingness === 'No' ? 'selected' : ''; ?>>Not Willing Only</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Placement status</label>
                            <select name="placement_status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="Placed" <?php echo $status === 'Placed' ? 'selected' : ''; ?>>Placed</option>
                                <option value="Unplaced" <?php echo $status === 'Unplaced' ? 'selected' : ''; ?>>Unplaced</option>
                                <option value="Higher Studies" <?php echo $status === 'Higher Studies' ? 'selected' : ''; ?>>Higher Studies</option>
                                <option value="Entrepreneurship" <?php echo $status === 'Entrepreneurship' ? 'selected' : ''; ?>>Entrepreneurship</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Minimum CGPA</label>
                            <input type="number" step="0.01" name="min_cgpa" class="form-control" placeholder="e.g. 7.50" value="<?php echo esc($minCgpa); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Max Standing Arrears</label>
                            <input type="number" name="max_standing_arrears" class="form-control" placeholder="e.g. 0" value="<?php echo esc($maxArrears); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">Skills keyword</label>
                            <input type="text" name="skills_search" class="form-control" placeholder="e.g. Python, SQL" value="<?php echo esc($skills); ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small text-muted">City / District</label>
                            <input type="text" name="city_search" class="form-control" placeholder="e.g. Trichy" value="<?php echo esc($city); ?>">
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-outline-primary px-4"><i class="fa-solid fa-magnifying-glass me-1"></i> Preview Query</button>
                        <button type="button" class="btn btn-success px-4" onclick="triggerCsvExport()"><i class="fa-solid fa-file-excel me-1"></i> Export formatted CSV</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- QUERY PREVIEW RESULT TABLE -->
        <div class="mamcet-card">
            <div class="card-header bg-white py-3">
                <h5 class="card-title fw-bold mb-0"><i class="fa-solid fa-eye text-primary me-2"></i> Query Preview (Max 100 shown on screen)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="previewTable" style="border: none;">
                        <thead class="table-light">
                            <tr>
                                <th>Registration</th>
                                <th>Name</th>
                                <th>Dept / Batch</th>
                                <th>CGPA</th>
                                <th>Arrears</th>
                                <th>Status / Willing</th>
                                <th>City</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($previewStudents)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No student records match the active query parameters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($previewStudents as $s): ?>
                                    <tr>
                                        <td><a href="student-view.php?student_id=<?php echo $s['student_id']; ?>" class="fw-bold text-decoration-none"><?php echo esc($s['registration_number']); ?></a></td>
                                        <td><span class="fw-bold text-dark"><?php echo esc($s['student_name']); ?></span></td>
                                        <td><?php echo esc($s['dept_code']); ?> / <?php echo esc($s['batch_name']); ?></td>
                                        <td><span class="fw-bold text-primary"><?php echo esc($s['current_cgpa'] ?? '0.00'); ?></span></td>
                                        <td><span class="fw-bold text-<?php echo ($s['standing_arrears'] ?? 0) > 0 ? 'danger' : 'success'; ?>"><?php echo (int)($s['standing_arrears'] ?? 0); ?></span></td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?php echo esc($s['placement_status']); ?></span>
                                            <span class="badge bg-light text-secondary border">Willing: <?php echo esc($s['placement_willingness']); ?></span>
                                        </td>
                                        <td><small class="text-muted"><?php echo esc($s['city'] ?: 'N/A'); ?></small></td>
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

