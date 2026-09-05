<?php
// MAMCET Placement & Learning Portal - Advanced Placement Reports & Exporter (Executive Redesign)
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
    $willingnessFilter = $_GET['willingness'] ?? '';
    $arrearsFilter = $_GET['arrears'] ?? '';

    $filename = "MAMCET_" . strtoupper($reportType) . "_REPORT_" . date('Ymd_His') . ".csv";
    
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
    if (!empty($willingnessFilter)) {
        $filterSql .= " AND s.placement_willingness = ? ";
        $params[] = $willingnessFilter;
    }
    if ($arrearsFilter === '0') {
        $filterSql .= " AND sa.standing_arrears = 0 ";
    } elseif ($arrearsFilter === 'gt0') {
        $filterSql .= " AND sa.standing_arrears > 0 ";
    }

    if ($reportType === 'students') {
        // Headers
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Placement Willingness', 'Current CGPA', 'Standing Arrears', 'Readiness Score (%)', 'Email', 'Mobile']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name, s.placement_willingness,
                   sa.current_cgpa, sa.standing_arrears, COALESCE(prs.readiness_score, 0) AS readiness_score,
                   s.email, s.mobile_number
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
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
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Resume Uploaded', 'ATS Score', 'Rule Score', 'AI Score', 'Last Uploaded Date']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                   CASE WHEN rf.resume_id IS NULL THEN 'No' ELSE 'Yes' END AS has_resume,
                   COALESCE(ra.overall_score, 'N/A') AS ats_score,
                   COALESCE(ra.rule_score, 'N/A') AS rule_score,
                   COALESCE(ra.ai_score, 'N/A') AS ai_score,
                   COALESCE(rf.uploaded_at, 'N/A') AS uploaded_at
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
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
        fputcsv($output, ['Register Number', 'Student Name', 'Department', 'Batch', 'Company Placed', 'Role / Remarks', 'Package (LPA)', 'Placed Date']);
        
        $sql = "
            SELECT s.registration_number, s.student_name, d.dept_code, b.batch_name,
                   sp.company_name, COALESCE(NULLIF(sp.notes, ''), 'Placed') AS designation, sp.package_lpa, sp.placed_date
            FROM students s
            JOIN departments d ON s.dept_id = d.dept_id
            JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
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

$pageTitle = 'Placement Reports Center';
require_once(__DIR__ . '/../includes/header.php');

$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();

$selectedDept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$selectedBatch = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$selectedType = isset($_GET['report_type']) ? trim($_GET['report_type']) : 'students';
$selectedWilling = isset($_GET['willingness']) ? trim($_GET['willingness']) : '';
$selectedArrears = isset($_GET['arrears']) ? trim($_GET['arrears']) : '';

// -------------------------------------------------------------
// CATEGORY COUNTS FOR TABS
// -------------------------------------------------------------
$countStudents = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
$countResumes = (int)$db->query("SELECT COUNT(DISTINCT student_id) FROM resume_files WHERE is_current = 1")->fetchColumn();
$countPlaced = (int)$db->query("SELECT COUNT(DISTINCT student_id) FROM student_placements")->fetchColumn();

// -------------------------------------------------------------
// FETCH REPORT PREVIEW DATA ACCORDING TO SCOPE
// -------------------------------------------------------------
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
if (!empty($selectedWilling)) {
    $filterSql .= " AND s.placement_willingness = ? ";
    $params[] = $selectedWilling;
}
if ($selectedArrears === '0') {
    $filterSql .= " AND sa.standing_arrears = 0 ";
} elseif ($selectedArrears === 'gt0') {
    $filterSql .= " AND sa.standing_arrears > 0 ";
}

$reportRows = [];
$totalRows = 0;
$kpi1 = 0; $kpi2 = 0; $kpi3 = 0; $kpi4 = 0;

if ($selectedType === 'students') {
    $sql = "
        SELECT s.student_id, s.registration_number, s.student_name, s.email, s.mobile_number, 
               d.dept_code, d.dept_name, b.batch_name, s.placement_willingness,
               COALESCE(sa.current_cgpa, 0.00) AS current_cgpa, 
               COALESCE(sa.standing_arrears, 0) AS standing_arrears, 
               COALESCE(prs.readiness_score, 0) AS readiness_score
        FROM students s
        JOIN departments d ON s.dept_id = d.dept_id
        JOIN batches b ON s.batch_id = b.batch_id
        LEFT JOIN student_academics sa ON s.student_id = sa.student_id
        LEFT JOIN placement_readiness_scores prs ON s.student_id = prs.student_id
        " . $filterSql . "
        ORDER BY s.student_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll();
    $totalRows = count($reportRows);

    if ($totalRows > 0) {
        $willingCount = 0;
        $zeroArrearsCount = 0;
        $totalCgpa = 0;
        $highCgpaCount = 0;
        foreach ($reportRows as $r) {
            if ($r['placement_willingness'] === 'Yes') $willingCount++;
            if ((int)$r['standing_arrears'] === 0) $zeroArrearsCount++;
            $totalCgpa += (float)$r['current_cgpa'];
            if ((float)$r['current_cgpa'] >= 8.0) $highCgpaCount++;
        }
        $kpi1 = $totalRows;
        $kpi2 = round(($willingCount / $totalRows) * 100, 1) . '%';
        $kpi3 = round($totalCgpa / $totalRows, 2);
        $kpi4 = round(($zeroArrearsCount / $totalRows) * 100, 1) . '%';
    }
} elseif ($selectedType === 'resumes') {
    $sql = "
        SELECT s.student_id, s.registration_number, s.student_name, s.email,
               d.dept_code, b.batch_name,
               CASE WHEN rf.resume_id IS NULL THEN 0 ELSE 1 END AS has_resume,
               COALESCE(ra.overall_score, NULL) AS ats_score,
               COALESCE(ra.rule_score, NULL) AS rule_score,
               COALESCE(ra.ai_score, NULL) AS ai_score,
               rf.uploaded_at, rf.filename
        FROM students s
        JOIN departments d ON s.dept_id = d.dept_id
        JOIN batches b ON s.batch_id = b.batch_id
        LEFT JOIN student_academics sa ON s.student_id = sa.student_id
        LEFT JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
        LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
        " . $filterSql . "
        ORDER BY s.student_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll();
    $totalRows = count($reportRows);

    if ($totalRows > 0) {
        $uploadedCount = 0;
        $totalAts = 0;
        $analyzedCount = 0;
        $readyCount = 0;
        foreach ($reportRows as $r) {
            if ($r['has_resume'] == 1) {
                $uploadedCount++;
                if ($r['ats_score'] !== null) {
                    $totalAts += (int)$r['ats_score'];
                    $analyzedCount++;
                    if ((int)$r['ats_score'] >= 70) $readyCount++;
                }
            }
        }
        $kpi1 = $totalRows;
        $kpi2 = $uploadedCount . ' (' . round(($uploadedCount / $totalRows) * 100, 1) . '%)';
        $kpi3 = $analyzedCount > 0 ? round($totalAts / $analyzedCount, 1) : 'N/A';
        $kpi4 = $readyCount;
    }
} elseif ($selectedType === 'placed') {
    $sql = "
        SELECT s.student_id, s.registration_number, s.student_name,
               d.dept_code, b.batch_name,
               sp.placement_id, sp.company_name, COALESCE(NULLIF(sp.notes, ''), 'Placed') AS designation,
               sp.package_lpa, sp.placed_date
        FROM students s
        JOIN departments d ON s.dept_id = d.dept_id
        JOIN batches b ON s.batch_id = b.batch_id
        LEFT JOIN student_academics sa ON s.student_id = sa.student_id
        JOIN student_placements sp ON s.student_id = sp.student_id
        " . $filterSql . "
        ORDER BY sp.placed_date DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll();
    $totalRows = count($reportRows);

    if ($totalRows > 0) {
        $totalLpa = 0;
        $maxLpa = 0;
        foreach ($reportRows as $r) {
            $lpa = (float)$r['package_lpa'];
            $totalLpa += $lpa;
            if ($lpa > $maxLpa) $maxLpa = $lpa;
        }
        $kpi1 = $totalRows;
        $kpi2 = number_format($maxLpa, 2) . ' LPA';
        $kpi3 = number_format($totalLpa / $totalRows, 2) . ' LPA';
        $kpi4 = count(array_unique(array_column($reportRows, 'company_name')));
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <div class="container-fluid px-0">

            <!-- 1. BREADCRUMBS & EXECUTIVE HERO BANNER -->
            <div class="reports-hero-card">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="hero-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-file-invoice"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary-subtle text-primary rounded-pill px-2.5 py-1 small fw-semibold">
                                    <i class="fa-solid fa-layer-group me-1"></i> Stage 2 Intelligence
                                </span>
                                <span class="text-muted small">Academic Year <?php echo esc($activeSessionName); ?></span>
                            </div>
                            <h2 class="h4 mb-0 fw-bold text-dark font-heading">Placement Reports & Intelligence Hub</h2>
                            <p class="text-muted small mb-0 mt-0.5">Filter, audit, and extract candidate rosters, resume ATS telemetry, and placement records.</p>
                        </div>
                    </div>
                    
                    <!-- Quick Action Toolbars -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="?export=csv&report_type=<?php echo urlencode($selectedType); ?>&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>&willingness=<?php echo urlencode($selectedWilling); ?>&arrears=<?php echo urlencode($selectedArrears); ?>" 
                           class="btn btn-success btn-sm px-3 py-2 fw-semibold btn-hover-shine shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-file-csv"></i> Export Dataset (CSV)
                        </a>
                        <button onclick="window.print();" class="btn btn-outline-secondary btn-sm px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1.5 bg-white">
                            <i class="fa-solid fa-print"></i> Print
                        </button>
                    </div>
                </div>
            </div>

            <!-- 2. REPORT CATEGORY SELECTOR CARDS -->
            <div class="report-category-grid">
                <!-- Tab 1: Full Candidate Roster -->
                <a href="?report_type=students&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" 
                   class="report-category-card <?php echo $selectedType === 'students' ? 'active' : ''; ?>">
                    <div class="report-category-icon <?php echo $selectedType === 'students' ? 'bg-primary text-white shadow-sm' : 'bg-primary-subtle text-primary'; ?>">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-between">
                            <h6 class="report-category-title mb-0">Candidate Master Roster</h6>
                            <span class="badge <?php echo $selectedType === 'students' ? 'bg-primary text-white' : 'bg-light text-muted border'; ?> rounded-pill small">
                                <?php echo $countStudents; ?>
                            </span>
                        </div>
                        <p class="report-category-desc mb-0">Academics, CGPA, arrears & readiness index</p>
                    </div>
                </a>

                <!-- Tab 2: Resume Health Log -->
                <a href="?report_type=resumes&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" 
                   class="report-category-card <?php echo $selectedType === 'resumes' ? 'active' : ''; ?>">
                    <div class="report-category-icon <?php echo $selectedType === 'resumes' ? 'bg-info text-white shadow-sm' : 'bg-info-subtle text-info'; ?>">
                        <i class="fa-solid fa-file-shield"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-between">
                            <h6 class="report-category-title mb-0">Resume & ATS Health Log</h6>
                            <span class="badge <?php echo $selectedType === 'resumes' ? 'bg-info text-white' : 'bg-light text-muted border'; ?> rounded-pill small">
                                <?php echo $countResumes; ?> Uploaded
                            </span>
                        </div>
                        <p class="report-category-desc mb-0">ATS scores, rule metrics & upload audits</p>
                    </div>
                </a>

                <!-- Tab 3: Placed Students Ledger -->
                <a href="?report_type=placed&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" 
                   class="report-category-card <?php echo $selectedType === 'placed' ? 'active' : ''; ?>">
                    <div class="report-category-icon <?php echo $selectedType === 'placed' ? 'bg-success text-white shadow-sm' : 'bg-success-subtle text-success'; ?>">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-between">
                            <h6 class="report-category-title mb-0">Placed Students Ledger</h6>
                            <span class="badge <?php echo $selectedType === 'placed' ? 'bg-success text-white' : 'bg-light text-muted border'; ?> rounded-pill small">
                                <?php echo $countPlaced; ?> Placed
                            </span>
                        </div>
                        <p class="report-category-desc mb-0">Company offers, packages & drive dates</p>
                    </div>
                </a>
            </div>

            <!-- 3. SMART DYNAMIC FILTER PANEL -->
            <div class="pro-filter-panel">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="report_type" value="<?php echo esc($selectedType); ?>">

                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <label class="form-label"><i class="fa-solid fa-building-columns text-muted me-1"></i> Department</label>
                        <select class="form-select" name="dept_id">
                            <option value="0">All Departments (Global)</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $selectedDept === (int)$d['dept_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['dept_code']); ?> - <?php echo htmlspecialchars($d['dept_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <label class="form-label"><i class="fa-solid fa-graduation-cap text-muted me-1"></i> Programme Batch</label>
                        <select class="form-select" name="batch_id">
                            <option value="0">All Batches</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $selectedBatch === (int)$b['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['batch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($selectedType === 'students'): ?>
                        <div class="col-xl-2 col-md-4 col-6">
                            <label class="form-label"><i class="fa-solid fa-thumbs-up text-muted me-1"></i> Willingness</label>
                            <select class="form-select" name="willingness">
                                <option value="">All Candidates</option>
                                <option value="Yes" <?php echo $selectedWilling === 'Yes' ? 'selected' : ''; ?>>Willing (Opted-in)</option>
                                <option value="No" <?php echo $selectedWilling === 'No' ? 'selected' : ''; ?>>Not Willing</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <label class="form-label"><i class="fa-solid fa-circle-check text-muted me-1"></i> Arrears Status</label>
                            <select class="form-select" name="arrears">
                                <option value="">All Arrears</option>
                                <option value="0" <?php echo $selectedArrears === '0' ? 'selected' : ''; ?>>0 Standing (Clear)</option>
                                <option value="gt0" <?php echo $selectedArrears === 'gt0' ? 'selected' : ''; ?>>With Active Arrears</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-xl-auto col-md-12 ms-xl-auto d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-primary px-3.5 py-2 fw-semibold btn-hover-shine shadow-sm">
                            <i class="fa-solid fa-filter me-1"></i> Apply Scope
                        </button>
                        <?php if ($selectedDept > 0 || $selectedBatch > 0 || !empty($selectedWilling) || !empty($selectedArrears)): ?>
                            <a href="?report_type=<?php echo $selectedType; ?>" class="btn btn-outline-secondary px-3 py-2 fw-semibold" title="Reset Filters">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 4. DYNAMIC LIVE KPI SUMMARY RIBBON -->
            <?php if ($totalRows > 0): ?>
                <div class="kpi-summary-ribbon">
                    <!-- Stat 1: Total Records -->
                    <div class="kpi-mini-card">
                        <div>
                            <div class="kpi-lbl">Records in Scope</div>
                            <div class="kpi-val"><?php echo $kpi1; ?></div>
                        </div>
                        <div class="kpi-icon-pill bg-primary-subtle text-primary">
                            <i class="fa-solid fa-list-check"></i>
                        </div>
                    </div>

                    <?php if ($selectedType === 'students'): ?>
                        <!-- Stat 2: Willingness % -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Willingness Ratio</div>
                                <div class="kpi-val text-success"><?php echo $kpi2; ?></div>
                            </div>
                            <div class="kpi-icon-pill bg-success-subtle text-success">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                        </div>
                        <!-- Stat 3: Avg CGPA -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Average CGPA</div>
                                <div class="kpi-val text-info"><?php echo $kpi3; ?> <span class="small text-muted" style="font-size:0.8rem;">/ 10</span></div>
                            </div>
                            <div class="kpi-icon-pill bg-info-subtle text-info">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                        </div>
                        <!-- Stat 4: Zero Arrears -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Zero Arrears Clearance</div>
                                <div class="kpi-val text-primary"><?php echo $kpi4; ?></div>
                            </div>
                            <div class="kpi-icon-pill bg-primary-subtle text-primary">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                        </div>
                    <?php elseif ($selectedType === 'resumes'): ?>
                        <!-- Stat 2: Upload Ratio -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Resumes Uploaded</div>
                                <div class="kpi-val text-success"><?php echo $kpi2; ?></div>
                            </div>
                            <div class="kpi-icon-pill bg-success-subtle text-success">
                                <i class="fa-solid fa-file-circle-check"></i>
                            </div>
                        </div>
                        <!-- Stat 3: Cohort Avg ATS -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Cohort Avg ATS</div>
                                <div class="kpi-val text-primary"><?php echo $kpi3; ?> <span class="small text-muted" style="font-size:0.8rem;">/ 100</span></div>
                            </div>
                            <div class="kpi-icon-pill bg-primary-subtle text-primary">
                                <i class="fa-solid fa-gauge-high"></i>
                            </div>
                        </div>
                        <!-- Stat 4: Drive Ready (70+) -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">ATS Ready (≥ 70)</div>
                                <div class="kpi-val text-warning"><?php echo $kpi4; ?> <span class="small text-muted" style="font-size:0.8rem;">Students</span></div>
                            </div>
                            <div class="kpi-icon-pill bg-warning-subtle text-warning">
                                <i class="fa-solid fa-star"></i>
                            </div>
                        </div>
                    <?php elseif ($selectedType === 'placed'): ?>
                        <!-- Stat 2: Highest CTC -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Highest Package</div>
                                <div class="kpi-val text-success"><?php echo $kpi2; ?></div>
                            </div>
                            <div class="kpi-icon-pill bg-success-subtle text-success">
                                <i class="fa-solid fa-trophy"></i>
                            </div>
                        </div>
                        <!-- Stat 3: Avg CTC -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Average Package</div>
                                <div class="kpi-val text-primary"><?php echo $kpi3; ?></div>
                            </div>
                            <div class="kpi-icon-pill bg-primary-subtle text-primary">
                                <i class="fa-solid fa-coins"></i>
                            </div>
                        </div>
                        <!-- Stat 4: Distinct Companies -->
                        <div class="kpi-mini-card">
                            <div>
                                <div class="kpi-lbl">Hiring Companies</div>
                                <div class="kpi-val text-info"><?php echo $kpi4; ?> <span class="small text-muted" style="font-size:0.8rem;">Recruiters</span></div>
                            </div>
                            <div class="kpi-icon-pill bg-info-subtle text-info">
                                <i class="fa-solid fa-building"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- 5. PREVIEW DATA TABLE PANEL -->
            <div class="pro-table-card">
                <div class="pro-table-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-table-list text-primary"></i>
                        <h6 class="mb-0 fw-bold text-dark font-heading">
                            <?php 
                            if ($selectedType === 'students') echo 'Candidate Performance & Readiness Ledger';
                            elseif ($selectedType === 'resumes') echo 'Resume ATS Telemetry & Submission Matrix';
                            elseif ($selectedType === 'placed') echo 'Official Placement Offers & CTC Records';
                            ?>
                        </h6>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-muted border px-2.5 py-1.5 small fw-semibold">
                            <i class="fa-solid fa-database me-1"></i> Showing <?php echo $totalRows; ?> Records
                        </span>
                    </div>
                </div>

                <div class="p-3">
                    <?php if (empty($reportRows)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-muted" style="font-size: 3rem;">
                                <i class="fa-solid fa-folder-open text-slate-300"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">No Data Found for this Query</h6>
                            <p class="text-muted small mb-3">Try adjusting your department, batch, or filter selections.</p>
                            <a href="?report_type=<?php echo $selectedType; ?>" class="btn btn-sm btn-outline-primary px-3">
                                <i class="fa-solid fa-rotate-left me-1"></i> Reset Scope Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="reportsDataTable" class="table table-hover align-middle mb-0" style="width: 100%;">
                                <thead class="table-light">
                                    <?php if ($selectedType === 'students'): ?>
                                        <tr>
                                            <th>Reg. Number</th>
                                            <th>Student Details</th>
                                            <th>Department</th>
                                            <th>Batch</th>
                                            <th>Willingness</th>
                                            <th>Current CGPA</th>
                                            <th>Arrears</th>
                                            <th>Readiness Score</th>
                                            <th class="text-end no-sort">Action</th>
                                        </tr>
                                    <?php elseif ($selectedType === 'resumes'): ?>
                                        <tr>
                                            <th>Reg. Number</th>
                                            <th>Student Details</th>
                                            <th>Department</th>
                                            <th>Batch</th>
                                            <th>Resume Upload</th>
                                            <th>ATS Overall</th>
                                            <th>Rule / AI Split</th>
                                            <th>Submission Date</th>
                                            <th class="text-end no-sort">Action</th>
                                        </tr>
                                    <?php elseif ($selectedType === 'placed'): ?>
                                        <tr>
                                            <th>Reg. Number</th>
                                            <th>Student Details</th>
                                            <th>Department</th>
                                            <th>Batch</th>
                                            <th>Recruiter / Company</th>
                                            <th>Role</th>
                                            <th>Package (CTC)</th>
                                            <th>Placed Date</th>
                                            <th class="text-end no-sort">Action</th>
                                        </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportRows as $row): 
                                        $initials = strtoupper(substr($row['student_name'] ?? 'S', 0, 2));
                                    ?>
                                        <?php if ($selectedType === 'students'): ?>
                                            <tr>
                                                <td>
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="reg-no-code" title="View Student Profile">
                                                        <?php echo htmlspecialchars($row['registration_number']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="table-avatar-badge"><?php echo $initials; ?></div>
                                                        <div>
                                                            <div class="fw-bold text-dark font-heading"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                                            <div class="small text-muted"><?php echo htmlspecialchars($row['email'] ?: 'No email'); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border fw-semibold">
                                                        <?php echo htmlspecialchars($row['dept_code']); ?>
                                                    </span>
                                                </td>
                                                <td><span class="small text-muted"><?php echo htmlspecialchars($row['batch_name']); ?></span></td>
                                                <td>
                                                    <?php if ($row['placement_willingness'] === 'Yes'): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill">
                                                            <i class="fa-solid fa-circle-check me-1"></i> Willing
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1 rounded-pill">
                                                            <i class="fa-solid fa-ban me-1"></i> Not Willing
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $cgpa = (float)$row['current_cgpa'];
                                                    $cgpaBadge = $cgpa >= 8.0 ? 'bg-success-subtle text-success border-success-subtle' : ($cgpa >= 6.5 ? 'bg-primary-subtle text-primary border-primary-subtle' : 'bg-warning-subtle text-warning border-warning-subtle');
                                                    ?>
                                                    <span class="badge <?php echo $cgpaBadge; ?> border fw-bold px-2 py-1">
                                                        <?php echo number_format($cgpa, 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ((int)$row['standing_arrears'] === 0): ?>
                                                        <span class="badge bg-success-subtle text-success rounded-pill px-2.5 py-1">
                                                            <i class="fa-solid fa-check me-1"></i> 0 Clear
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2.5 py-1">
                                                            <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo $row['standing_arrears']; ?> Active
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2" style="min-width: 100px;">
                                                        <div class="progress flex-grow-1" style="height: 6px; border-radius: 999px;">
                                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, (int)$row['readiness_score']); ?>%;"></div>
                                                        </div>
                                                        <span class="small fw-bold text-dark"><?php echo (int)$row['readiness_score']; ?>%</span>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="btn btn-sm btn-light border px-2.5 py-1 text-primary" title="View Full File">
                                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                    </a>
                                                </td>
                                            </tr>

                                        <?php elseif ($selectedType === 'resumes'): ?>
                                            <tr>
                                                <td>
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="reg-no-code" title="View Student Profile">
                                                        <?php echo htmlspecialchars($row['registration_number']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="table-avatar-badge"><?php echo $initials; ?></div>
                                                        <div>
                                                            <div class="fw-bold text-dark font-heading"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                                            <div class="small text-muted"><?php echo htmlspecialchars($row['email'] ?: 'No email'); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border fw-semibold"><?php echo htmlspecialchars($row['dept_code']); ?></span>
                                                </td>
                                                <td><span class="small text-muted"><?php echo htmlspecialchars($row['batch_name']); ?></span></td>
                                                <td>
                                                    <?php if ($row['has_resume'] == 1): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill">
                                                            <i class="fa-solid fa-file-circle-check me-1"></i> Uploaded
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 rounded-pill">
                                                            <i class="fa-solid fa-circle-xmark me-1"></i> Missing
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $ats = $row['ats_score'];
                                                    if ($ats !== null) {
                                                        $atsTier = $ats >= 90 ? 'tier-excellent' : ($ats >= 75 ? 'tier-verygood' : ($ats >= 60 ? 'tier-good' : ($ats >= 40 ? 'tier-basic' : 'tier-needswork')));
                                                        echo '<span class="ats-score-pill ' . $atsTier . '"><i class="fa-solid fa-gauge-high me-1"></i> ' . $ats . '/100</span>';
                                                    } else {
                                                        echo '<span class="ats-score-pill tier-missing">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['rule_score'] !== null || $row['ai_score'] !== null): ?>
                                                        <span class="small text-muted">
                                                            Rule: <strong class="text-dark"><?php echo $row['rule_score'] ?? '0'; ?></strong> | AI: <strong class="text-dark"><?php echo $row['ai_score'] ?? '0'; ?></strong>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="small text-muted">
                                                        <?php echo $row['uploaded_at'] ? date('d M Y, h:i A', strtotime($row['uploaded_at'])) : '—'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>#resumes" class="btn btn-sm btn-light border px-2.5 py-1 text-primary" title="Inspect Resume">
                                                        <i class="fa-solid fa-file-pdf"></i>
                                                    </a>
                                                </td>
                                            </tr>

                                        <?php elseif ($selectedType === 'placed'): ?>
                                            <tr>
                                                <td>
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="reg-no-code" title="View Student Profile">
                                                        <?php echo htmlspecialchars($row['registration_number']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="table-avatar-badge"><?php echo $initials; ?></div>
                                                        <div class="fw-bold text-dark font-heading"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border fw-semibold"><?php echo htmlspecialchars($row['dept_code']); ?></span>
                                                </td>
                                                <td><span class="small text-muted"><?php echo htmlspecialchars($row['batch_name']); ?></span></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="p-1 rounded bg-primary-subtle text-primary">
                                                            <i class="fa-solid fa-building"></i>
                                                        </div>
                                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['company_name']); ?></span>
                                                    </div>
                                                </td>
                                                <td><span class="small text-muted"><?php echo htmlspecialchars($row['designation']); ?></span></td>
                                                <td>
                                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle fw-bold px-2.5 py-1">
                                                        <i class="fa-solid fa-coins text-warning me-1"></i> <?php echo number_format((float)$row['package_lpa'], 2); ?> LPA
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="small text-muted">
                                                        <?php echo $row['placed_date'] ? date('d M Y', strtotime($row['placed_date'])) : '—'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="btn btn-sm btn-light border px-2.5 py-1 text-primary" title="View Full Placement Record">
                                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    if ($('#reportsDataTable').length) {
        $('#reportsDataTable').DataTable({
            "pageLength": 25,
            "order": [[1, "asc"]],
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Live filter rows...",
                "lengthMenu": "Show _MENU_ records",
                "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                "paginate": {
                    "first": "<i class='fa-solid fa-angles-left'></i>",
                    "previous": "<i class='fa-solid fa-chevron-left'></i>",
                    "next": "<i class='fa-solid fa-chevron-right'></i>",
                    "last": "<i class='fa-solid fa-angles-right'></i>"
                }
            },
            "columnDefs": [
                { "orderable": false, "targets": "no-sort" }
            ]
        });
    }
});
</script>
