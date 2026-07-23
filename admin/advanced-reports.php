<?php
// MAMCET Placement & Learning Portal - Advanced Placement Reports & Exporter
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../config/database.php');

// Restrict to Officer / Admin
if (!isLoggedIn() || ($_SESSION['role_id'] != ROLE_PLACEMENT_OFFICER && $_SESSION['role_id'] != ROLE_SUPER_ADMIN)) {
    header("Location: ../index.php");
    exit;
}

$db = Database::getInstance()->getConnection();

// -----------------------------------------------------------------------------
// DYNAMIC EXPORT DISPATCHER (CSV)
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $reportType = $_GET['report_type'] ?? 'students';
    $deptId = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
    $batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

    $filename = $reportType . "_report_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    $output = fopen('php://output', 'w');
    
    // Build filter clause
    $filterSql = " WHERE 1=1 ";
    $params = [];
    if ($deptId > 0) {
        $filterSql .= " AND s.dept_id = ? ";
        $params[] = $deptId;
    }
    if ($batchId > 0) {
        $filterSql .= " AND s.batch_id = ? ";
        $params[] = $batchId;
    }

    if ($reportType === 'students') {
        // Headers
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Willingness', 'CGPA', 'Standing Arrears', 'Readiness Score']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name, s.placement_willingness,
                   sa.current_cgpa, sa.standing_arrears, COALESCE(prs.readiness_score, 0) AS readiness_score
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            JOIN student_academics sa ON s.student_id = sa.student_id
            LEFT JOIN placement_readiness_scores prs ON s.student_id = prs.student_id
            " . $filterSql . "
            ORDER BY s.student_name ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'resumes') {
        // Headers
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Resume Uploaded', 'ATS Score', 'Last Updated']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                   CASE WHEN rf.resume_id IS NULL THEN 'No' ELSE 'Yes' END AS has_resume,
                   COALESCE(ra.overall_score, 'N/A') AS ats_score,
                   COALESCE(rf.uploaded_at, 'N/A') AS uploaded_at
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
            LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
            " . $filterSql . "
            ORDER BY s.student_name ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'placed') {
        // Headers
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Company Placed', 'Package (LPA)', 'Placed Date']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                   sp.company_name, sp.package_lpa, sp.placed_date
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            JOIN student_placements sp ON s.student_id = sp.student_id
            " . $filterSql . "
            ORDER BY sp.placed_date DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

require_once(__DIR__ . '/../includes/header.php');

$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();

$selectedDept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$selectedBatch = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$selectedType = isset($_GET['report_type']) ? trim($_GET['report_type']) : 'students';
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-file-excel text-success"></i> Placement Reports Center</h1>

            <div class="card shadow mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="m-0 font-weight-bold">Configure Report & Exporters</h6>
                </div>
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-muted">Report Category</label>
                            <select class="form-select" name="report_type" required>
                                <option value="students" <?php echo $selectedType === 'students' ? 'selected' : ''; ?>>Full Candidate Roster</option>
                                <option value="resumes" <?php echo $selectedType === 'resumes' ? 'selected' : ''; ?>>Resume Completion Log</option>
                                <option value="placed" <?php echo $selectedType === 'placed' ? 'selected' : ''; ?>>Placed Students Ledger</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-muted">Branch / Department</label>
                            <select class="form-select" name="dept_id">
                                <option value="0">All Departments</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $selectedDept === (int)$d['dept_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-muted">Target Batch</label>
                            <select class="form-select" name="batch_id">
                                <option value="0">All Batches</option>
                                <?php foreach ($batches as $b): ?>
                                    <option value="<?php echo $b['batch_id']; ?>" <?php echo $selectedBatch === (int)$b['batch_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['batch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-50 font-weight-bold">
                                <i class="fa-solid fa-magnifying-glass"></i> Preview
                            </button>
                            <a href="?export=csv&report_type=<?php echo $selectedType; ?>&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" class="btn btn-success w-50 font-weight-bold">
                                <i class="fa-solid fa-file-csv"></i> Export CSV
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- PREVIEW DATA CARD -->
            <div class="card shadow mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="m-0 font-weight-bold">Report Preview Panel</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 500px; overflow-y: scroll;">
                        <?php
                        // Run Preview Query
                        $filterSql = " WHERE 1=1 ";
                        $params = [];
                        if ($selectedDept > 0) {
                            $filterSql .= " AND s.dept_id = ? ";
                            $params[] = $selectedDept;
                        }
                        if ($selectedBatch > 0) {
                            $filterSql .= " AND s.batch_id = ? ";
                            $params[] = $selectedBatch;
                        }

                        if ($selectedType === 'students') {
                            $sql = "
                                SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name, s.placement_willingness,
                                       sa.current_cgpa, sa.standing_arrears, COALESCE(prs.readiness_score, 0) AS readiness_score
                                FROM students s
                                JOIN departments d ON s.dept_id = d.dept_id
                                JOIN batches b ON s.batch_id = b.batch_id
                                JOIN student_academics sa ON s.student_id = sa.student_id
                                LEFT JOIN placement_readiness_scores prs ON s.student_id = prs.student_id
                                " . $filterSql . "
                                ORDER BY s.student_name ASC
                            ";
                            $stmt = $db->prepare($sql);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll();
                            
                            echo '<table class="table table-bordered table-striped table-sm" style="font-size:0.85rem;">';
                            echo '<thead><tr><th>Reg. No</th><th>Student Name</th><th>Branch</th><th>Batch</th><th>Willing</th><th>CGPA</th><th>Arrears</th><th>Readiness</th></tr></thead>';
                            echo '<tbody>';
                            if (empty($rows)) echo '<tr><td colspan="8" class="text-center">No records.</td></tr>';
                            foreach ($rows as $r) {
                                echo "<tr><td><code>{$r['registration_number']}</code></td><td>{$r['student_name']}</td><td>{$r['dept_code']}</td><td>{$r['batch_name']}</td><td>{$r['placement_willingness']}</td><td>{$r['current_cgpa']}</td><td>{$r['standing_arrears']}</td><td>{$r['readiness_score']}%</td></tr>";
                            }
                            echo '</tbody></table>';
                        } elseif ($selectedType === 'resumes') {
                            $sql = "
                                SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                                       CASE WHEN rf.resume_id IS NULL THEN 'No' ELSE 'Yes' END AS has_resume,
                                       COALESCE(ra.overall_score, 'N/A') AS ats_score,
                                       COALESCE(rf.uploaded_at, 'N/A') AS uploaded_at
                                FROM students s
                                JOIN departments d ON s.dept_id = d.dept_id
                                JOIN batches b ON s.batch_id = b.batch_id
                                LEFT JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
                                LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
                                " . $filterSql . "
                                ORDER BY s.student_name ASC
                            ";
                            $stmt = $db->prepare($sql);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll();
                            
                            echo '<table class="table table-bordered table-striped table-sm" style="font-size:0.85rem;">';
                            echo '<thead><tr><th>Reg. No</th><th>Student Name</th><th>Branch</th><th>Batch</th><th>Uploaded</th><th>ATS Score</th><th>Updated</th></tr></thead>';
                            echo '<tbody>';
                            if (empty($rows)) echo '<tr><td colspan="7" class="text-center">No records.</td></tr>';
                            foreach ($rows as $r) {
                                echo "<tr><td><code>{$r['registration_number']}</code></td><td>{$r['student_name']}</td><td>{$r['dept_code']}</td><td>{$r['batch_name']}</td><td>{$r['has_resume']}</td><td>{$r['ats_score']}</td><td>{$r['uploaded_at']}</td></tr>";
                            }
                            echo '</tbody></table>';
                        } elseif ($selectedType === 'placed') {
                            $sql = "
                                SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                                       sp.company_name, sp.package_lpa, sp.placed_date
                                FROM students s
                                JOIN departments d ON s.dept_id = d.dept_id
                                JOIN batches b ON s.batch_id = b.batch_id
                                JOIN student_placements sp ON s.student_id = sp.student_id
                                " . $filterSql . "
                                ORDER BY sp.placed_date DESC
                            ";
                            $stmt = $db->prepare($sql);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll();
                            
                            echo '<table class="table table-bordered table-striped table-sm" style="font-size:0.85rem;">';
                            echo '<thead><tr><th>Reg. No</th><th>Student Name</th><th>Branch</th><th>Batch</th><th>Company</th><th>Package</th><th>Placed Date</th></tr></thead>';
                            echo '<tbody>';
                            if (empty($rows)) echo '<tr><td colspan="7" class="text-center">No records.</td></tr>';
                            foreach ($rows as $r) {
                                echo "<tr><td><code>{$r['registration_number']}</code></td><td>{$r['student_name']}</td><td>{$r['dept_code']}</td><td>{$r['batch_name']}</td><td>{$r['company_name']}</td><td>{$r['package_lpa']} LPA</td><td>{$r['placed_date']}</td></tr>";
                            }
                            echo '</tbody></table>';
                        }
                        ?>
                    </div>
                </div>
            </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
