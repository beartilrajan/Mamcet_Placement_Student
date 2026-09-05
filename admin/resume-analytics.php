<?php
// MAMCET Placement & Learning Portal - Executive Resume Analytics & ATS Intelligence Dashboard
$pageTitle = 'Resume Intelligence & ATS Analytics';
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();

// Load filter values
$selectedDept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$selectedBatch = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

// Query filters lists
$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();

// Build SQL filter clause
$filterClause = " WHERE 1=1 ";
$params = [];
if ($selectedDept > 0) {
    $filterClause .= " AND s.dept_id = ? ";
    $params[] = $selectedDept;
}
if ($selectedBatch > 0) {
    $filterClause .= " AND s.batch_id = ? ";
    $params[] = $selectedBatch;
}

// -------------------------------------------------------------
// 1. CALCULATE EXECUTIVE AGGREGATED METRICS (TIER 1)
// -------------------------------------------------------------

// Total Students in current scope
$stmtTot = $db->prepare("SELECT COUNT(*) FROM students s " . $filterClause);
$stmtTot->execute($params);
$totalStudents = (int)$stmtTot->fetchColumn();

// Students with active uploaded resumes
$stmtWithRes = $db->prepare("
    SELECT COUNT(DISTINCT s.student_id) 
    FROM students s 
    JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
    " . $filterClause
);
$stmtWithRes->execute($params);
$studentsWithResume = (int)$stmtWithRes->fetchColumn();
$studentsWithoutResume = max(0, $totalStudents - $studentsWithResume);
$completionRate = $totalStudents > 0 ? round(($studentsWithResume / $totalStudents) * 100, 1) : 0;

// Average ATS score
$stmtAvgAts = $db->prepare("
    SELECT AVG(ra.overall_score) 
    FROM resume_files rf 
    JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
    JOIN students s ON rf.student_id = s.student_id
    " . $filterClause . " AND rf.is_current = 1
");
$stmtAvgAts->execute($params);
$averageAtsScore = round((float)$stmtAvgAts->fetchColumn(), 1);

// Candidates above threshold (70+)
$stmtAboveThresh = $db->prepare("
    SELECT COUNT(DISTINCT s.student_id) 
    FROM students s 
    JOIN resume_files rf ON s.student_id = rf.student_id 
    JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
    " . $filterClause . " AND rf.is_current = 1 AND ra.overall_score >= 70
");
$stmtAboveThresh->execute($params);
$studentsAboveThreshold = (int)$stmtAboveThresh->fetchColumn();
$readyRate = $studentsWithResume > 0 ? round(($studentsAboveThreshold / $studentsWithResume) * 100, 1) : 0;

// -------------------------------------------------------------
// 2. CHART TELEMETRY DATA: ATS SCORE DISTRIBUTION
// -------------------------------------------------------------
$scoreIntervals = ['0-39' => 0, '40-59' => 0, '60-74' => 0, '75-89' => 0, '90-100' => 0];
$stmtDist = $db->prepare("
    SELECT ra.overall_score 
    FROM resume_analysis ra
    JOIN resume_files rf ON ra.resume_id = rf.resume_id
    JOIN students s ON rf.student_id = s.student_id
    " . $filterClause . " AND rf.is_current = 1
");
$stmtDist->execute($params);
$scores = $stmtDist->fetchAll(PDO::FETCH_COLUMN);

foreach ($scores as $s) {
    if ($s >= 90) $scoreIntervals['90-100']++;
    elseif ($s >= 75) $scoreIntervals['75-89']++;
    elseif ($s >= 60) $scoreIntervals['60-74']++;
    elseif ($s >= 40) $scoreIntervals['40-59']++;
    else $scoreIntervals['0-39']++;
}

// -------------------------------------------------------------
// 3. CHART TELEMETRY DATA: DEPARTMENT-WISE BENCHMARKING
// -------------------------------------------------------------
$deptNames = [];
$deptUploads = [];
$deptTotals = [];
$deptHighScorers = [];

foreach ($departments as $d) {
    $stmtDeptTot = $db->prepare("SELECT COUNT(*) FROM students s WHERE s.dept_id = ? " . ($selectedBatch > 0 ? " AND s.batch_id = ? " : ""));
    $dParams = [$d['dept_id']];
    if ($selectedBatch > 0) $dParams[] = $selectedBatch;
    $stmtDeptTot->execute($dParams);
    $dTot = (int)$stmtDeptTot->fetchColumn();

    $stmtDeptRes = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) 
        FROM students s 
        JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
        WHERE s.dept_id = ? " . ($selectedBatch > 0 ? " AND s.batch_id = ? " : "")
    );
    $stmtDeptRes->execute($dParams);
    $dRes = (int)$stmtDeptRes->fetchColumn();

    $stmtDeptHigh = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) 
        FROM students s 
        JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
        JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
        WHERE s.dept_id = ? AND ra.overall_score >= 70 " . ($selectedBatch > 0 ? " AND s.batch_id = ? " : "")
    );
    $stmtDeptHigh->execute($dParams);
    $dHigh = (int)$stmtDeptHigh->fetchColumn();

    if ($dTot > 0) {
        $deptNames[] = $d['dept_code'];
        $deptUploads[] = $dRes;
        $deptTotals[] = $dTot;
        $deptHighScorers[] = $dHigh;
    }
}

// -------------------------------------------------------------
// 4. ACTIONABLE CANDIDATES ROSTER (TIER 3 DRILLDOWN)
// -------------------------------------------------------------
$stmtRoster = $db->prepare("
    SELECT s.student_id, s.registration_number, s.student_name, s.email, s.mobile_number,
           d.dept_code, b.batch_name,
           rf.resume_id, rf.filename, rf.uploaded_at,
           ra.analysis_id, ra.overall_score, ra.rule_score, ra.ai_score,
           CASE 
               WHEN rf.resume_id IS NULL THEN 'missing'
               WHEN ra.overall_score >= 70 THEN 'ready'
               WHEN ra.overall_score < 60 THEN 'low'
               ELSE 'medium'
           END AS ats_status
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN resume_files rf ON s.student_id = rf.student_id AND rf.is_current = 1
    LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
    " . $filterClause . "
    ORDER BY s.student_name ASC
");
$stmtRoster->execute($params);
$candidateRoster = $stmtRoster->fetchAll();

$countMissingList = 0;
$countLowList = 0;
$countReadyList = 0;

foreach ($candidateRoster as $c) {
    if ($c['ats_status'] === 'missing') $countMissingList++;
    elseif ($c['ats_status'] === 'low') $countLowList++;
    elseif ($c['ats_status'] === 'ready') $countReadyList++;
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <div class="container-fluid px-0">

            <!-- 1. BREADCRUMBS & EXECUTIVE HERO HEADER -->
            <div class="reports-hero-card">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="hero-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary-subtle text-primary rounded-pill px-2.5 py-1 small fw-semibold">
                                    <i class="fa-solid fa-brain me-1"></i> AI & Rule-Based ATS Engine
                                </span>
                                <span class="text-muted small">Academic Year <?php echo esc($activeSessionName); ?></span>
                            </div>
                            <h2 class="h4 mb-0 fw-bold text-dark font-heading">Resume Intelligence & ATS Analytics</h2>
                            <p class="text-muted small mb-0 mt-0.5">Cohort resume submission telemetry, ATS score distribution curves, and actionable drive readiness.</p>
                        </div>
                    </div>

                    <!-- Header Quick Actions -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="advanced-reports.php?report_type=resumes&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" 
                           class="btn btn-primary btn-sm px-3 py-2 fw-semibold btn-hover-shine shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-table-list"></i> Full ATS Audit Log
                        </a>
                        <a href="advanced-reports.php?export=csv&report_type=resumes&dept_id=<?php echo $selectedDept; ?>&batch_id=<?php echo $selectedBatch; ?>" 
                           class="btn btn-success btn-sm px-3 py-2 fw-semibold btn-hover-shine shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-file-csv"></i> Export CSV
                        </a>
                    </div>
                </div>
            </div>

            <!-- 2. SMART FILTER TOOLBAR -->
            <div class="pro-filter-panel">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-xl-4 col-md-5 col-sm-6">
                        <label class="form-label"><i class="fa-solid fa-building-columns text-muted me-1"></i> Department / Branch</label>
                        <select class="form-select" name="dept_id">
                            <option value="0">All Departments (Global Campus)</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $selectedDept === (int)$d['dept_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['dept_name']); ?> (<?php echo htmlspecialchars($d['dept_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-4 col-md-4 col-sm-6">
                        <label class="form-label"><i class="fa-solid fa-graduation-cap text-muted me-1"></i> Programme Batch</label>
                        <select class="form-select" name="batch_id">
                            <option value="0">All Batches (Global)</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $selectedBatch === (int)$b['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['batch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-4 col-md-3 d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold btn-hover-shine shadow-sm flex-grow-1">
                            <i class="fa-solid fa-filter me-1"></i> Filter Analytics
                        </button>
                        <?php if ($selectedDept > 0 || $selectedBatch > 0): ?>
                            <a href="resume-analytics.php" class="btn btn-outline-secondary px-3 py-2 fw-semibold" title="Reset Filters">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 3. TIER 1: EXECUTIVE KPI METRIC CARDS -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Card 1: Resumes Uploaded -->
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="analytics-kpi-card" style="border-left: 3px solid #10b981;">
                        <div class="kpi-top">
                            <div class="kpi-title">Resumes Uploaded</div>
                            <div class="kpi-icon-wrap bg-success-subtle text-success">
                                <i class="fa-solid fa-file-circle-check"></i>
                            </div>
                        </div>
                        <div class="kpi-value-lg text-dark">
                            <?php echo $studentsWithResume; ?> <span style="font-size:1.05rem; font-weight:600; color:var(--text-muted);">/ <?php echo $totalStudents; ?></span>
                        </div>
                        <div class="kpi-progress">
                            <div class="progress-bar bg-success" style="width: <?php echo $completionRate; ?>%;"></div>
                        </div>
                        <div class="kpi-subtext">
                            <span>Cohort Completion</span>
                            <strong class="text-success fw-bold"><?php echo $completionRate; ?>%</strong>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Missing Resumes -->
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="analytics-kpi-card" style="border-left: 3px solid #ef4444;">
                        <div class="kpi-top">
                            <div class="kpi-title">Missing Resumes</div>
                            <div class="kpi-icon-wrap bg-danger-subtle text-danger">
                                <i class="fa-solid fa-file-circle-exclamation"></i>
                            </div>
                        </div>
                        <div class="kpi-value-lg text-danger">
                            <?php echo $studentsWithoutResume; ?> <span style="font-size:0.95rem; font-weight:600; color:var(--text-muted);">Students</span>
                        </div>
                        <div class="kpi-progress">
                            <div class="progress-bar bg-danger" style="width: <?php echo 100 - $completionRate; ?>%;"></div>
                        </div>
                        <div class="kpi-subtext">
                            <span>Pending Submissions</span>
                            <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Urgent Action</span>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Average ATS Score -->
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="analytics-kpi-card" style="border-left: 3px solid #06b6d4;">
                        <div class="kpi-top">
                            <div class="kpi-title">Cohort Avg ATS Score</div>
                            <div class="kpi-icon-wrap bg-info-subtle text-info">
                                <i class="fa-solid fa-gauge-high"></i>
                            </div>
                        </div>
                        <div class="kpi-value-lg text-dark">
                            <?php echo $averageAtsScore ?: '0.0'; ?> <span style="font-size:1.05rem; font-weight:600; color:var(--text-muted);">/ 100</span>
                        </div>
                        <div class="kpi-progress">
                            <div class="progress-bar bg-info" style="width: <?php echo min(100, $averageAtsScore); ?>%;"></div>
                        </div>
                        <div class="kpi-subtext">
                            <span>Target Benchmark: 70+</span>
                            <?php if ($averageAtsScore >= 70): ?>
                                <span class="badge bg-success-subtle text-success rounded-pill px-2">Optimal</span>
                            <?php elseif ($averageAtsScore >= 50): ?>
                                <span class="badge bg-warning-subtle text-warning rounded-pill px-2">Developing</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Needs Push</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 4: High ATS Candidates (70+) -->
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="analytics-kpi-card" style="border-left: 3px solid #f59e0b;">
                        <div class="kpi-top">
                            <div class="kpi-title">Drive Ready (ATS ≥ 70)</div>
                            <div class="kpi-icon-wrap bg-warning-subtle text-warning">
                                <i class="fa-solid fa-star"></i>
                            </div>
                        </div>
                        <div class="kpi-value-lg text-dark">
                            <?php echo $studentsAboveThreshold; ?> <span style="font-size:0.95rem; font-weight:600; color:var(--text-muted);">Candidates</span>
                        </div>
                        <div class="kpi-progress">
                            <div class="progress-bar bg-warning" style="width: <?php echo $readyRate; ?>%;"></div>
                        </div>
                        <div class="kpi-subtext">
                            <span>Of Uploaded Resumes</span>
                            <strong class="text-warning fw-bold"><?php echo $readyRate; ?>%</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. TIER 2: INTERACTIVE VISUAL ANALYTICS GRID (CHARTS) -->
            <div class="row g-3 g-xl-4 mb-4">
                <!-- Chart 1: ATS Score Distribution Breakdown -->
                <div class="col-lg-7 col-12">
                    <div class="pro-chart-card">
                        <div class="pro-chart-header">
                            <h6 class="pro-chart-title">
                                <i class="fa-solid fa-chart-simple text-primary"></i> ATS Score Distribution Curve
                            </h6>
                            <span class="badge bg-light text-muted border px-2 py-1 small">
                                <?php echo count($scores); ?> Resumes Analyzed
                            </span>
                        </div>
                        <div class="pro-chart-body">
                            <canvas id="scoreDistChart" style="max-height: 260px;"></canvas>

                            <!-- Metric Legend Grid -->
                            <div class="chart-legend-grid">
                                <div class="chart-legend-item">
                                    <div class="count text-danger"><?php echo $scoreIntervals['0-39']; ?></div>
                                    <div class="label">0–39 Work</div>
                                </div>
                                <div class="chart-legend-item">
                                    <div class="count text-warning"><?php echo $scoreIntervals['40-59']; ?></div>
                                    <div class="label">40–59 Basic</div>
                                </div>
                                <div class="chart-legend-item">
                                    <div class="count text-primary"><?php echo $scoreIntervals['60-74']; ?></div>
                                    <div class="label">60–74 Good</div>
                                </div>
                                <div class="chart-legend-item">
                                    <div class="count text-info"><?php echo $scoreIntervals['75-89']; ?></div>
                                    <div class="label">75–89 V.Good</div>
                                </div>
                                <div class="chart-legend-item">
                                    <div class="count text-success"><?php echo $scoreIntervals['90-100']; ?></div>
                                    <div class="label">90–100 Top</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart 2: Cohort Health Status Radial / Donut -->
                <div class="col-lg-5 col-12">
                    <div class="pro-chart-card">
                        <div class="pro-chart-header">
                            <h6 class="pro-chart-title">
                                <i class="fa-solid fa-chart-pie text-info"></i> Cohort Readiness Breakdown
                            </h6>
                            <span class="badge bg-light text-muted border px-2 py-1 small">Overall Status</span>
                        </div>
                        <div class="pro-chart-body d-flex flex-column align-items-center justify-content-center">
                            <div style="width: 100%; max-width: 260px; height: 220px; position: relative;">
                                <canvas id="cohortHealthChart"></canvas>
                            </div>
                            <div class="d-flex justify-content-around w-100 mt-3 pt-2 border-top text-center">
                                <div>
                                    <div class="small fw-bold text-success font-heading"><?php echo $studentsAboveThreshold; ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;">Drive Ready</div>
                                </div>
                                <div>
                                    <div class="small fw-bold text-warning font-heading"><?php echo max(0, $studentsWithResume - $studentsAboveThreshold); ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;">Developing</div>
                                </div>
                                <div>
                                    <div class="small fw-bold text-danger font-heading"><?php echo $studentsWithoutResume; ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;">Missing</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart 3: Department-wise Completion Comparison -->
                <div class="col-12">
                    <div class="pro-chart-card">
                        <div class="pro-chart-header">
                            <h6 class="pro-chart-title">
                                <i class="fa-solid fa-building-columns text-success"></i> Department-wise Resume Completion & Quality Benchmark
                            </h6>
                            <div class="small text-muted">
                                <span class="badge bg-success-subtle text-success me-2">■ Resumes Uploaded</span>
                                <span class="badge bg-warning-subtle text-warning me-2">■ ATS ≥ 70</span>
                                <span class="badge bg-light text-muted border">■ Total Students</span>
                            </div>
                        </div>
                        <div class="pro-chart-body">
                            <canvas id="deptCompletionChart" style="max-height: 280px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. TIER 3: ACTIONABLE CANDIDATE ATTENTION TABLE -->
            <div class="pro-table-card">
                <div class="pro-table-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-user-shield text-primary"></i>
                        <h6 class="mb-0 fw-bold text-dark font-heading">Actionable Candidate Telemetry Center</h6>
                    </div>

                    <!-- Segmented Table Filter Tabs -->
                    <div class="attention-nav" id="rosterFilterNav">
                        <button type="button" class="attention-nav-btn active" data-filter="all">
                            All Students <span class="badge bg-light text-dark ms-1"><?php echo count($candidateRoster); ?></span>
                        </button>
                        <button type="button" class="attention-nav-btn" data-filter="missing">
                            <i class="fa-solid fa-triangle-exclamation text-danger"></i> Missing Resumes 
                            <span class="badge bg-danger text-white ms-1"><?php echo $countMissingList; ?></span>
                        </button>
                        <button type="button" class="attention-nav-btn" data-filter="low">
                            <i class="fa-solid fa-arrow-trend-down text-warning"></i> Needs Work (&lt;60) 
                            <span class="badge bg-warning text-dark ms-1"><?php echo $countLowList; ?></span>
                        </button>
                        <button type="button" class="attention-nav-btn" data-filter="ready">
                            <i class="fa-solid fa-star text-success"></i> Top Scorers (≥70) 
                            <span class="badge bg-success text-white ms-1"><?php echo $countReadyList; ?></span>
                        </button>
                    </div>
                </div>

                <div class="p-3">
                    <div class="table-responsive">
                        <table id="candidateTelemetryTable" class="table table-hover align-middle mb-0" style="width:100%;">
                            <thead class="table-light">
                                <tr>
                                    <th>Register Number</th>
                                    <th>Student Details</th>
                                    <th>Department</th>
                                    <th>Batch</th>
                                    <th>Resume Status</th>
                                    <th>ATS Overall</th>
                                    <th>Rule / AI Split</th>
                                    <th>Submission Timestamp</th>
                                    <th class="text-end no-sort">Quick Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($candidateRoster as $c): 
                                    $initials = strtoupper(substr($c['student_name'] ?? 'S', 0, 2));
                                ?>
                                    <tr data-status="<?php echo $c['ats_status']; ?>">
                                        <td>
                                            <a href="student-view.php?student_id=<?php echo $c['student_id']; ?>" class="reg-no-code" title="Open Full Profile">
                                                <?php echo htmlspecialchars($c['registration_number']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="table-avatar-badge"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="fw-bold text-dark font-heading"><?php echo htmlspecialchars($c['student_name']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($c['email'] ?: 'No email configured'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border fw-semibold"><?php echo htmlspecialchars($c['dept_code']); ?></span>
                                        </td>
                                        <td><span class="small text-muted"><?php echo htmlspecialchars($c['batch_name']); ?></span></td>
                                        <td>
                                            <?php if ($c['resume_id']): ?>
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
                                            $ats = $c['overall_score'];
                                            if ($ats !== null) {
                                                $atsTier = $ats >= 90 ? 'tier-excellent' : ($ats >= 75 ? 'tier-verygood' : ($ats >= 60 ? 'tier-good' : ($ats >= 40 ? 'tier-basic' : 'tier-needswork')));
                                                echo '<span class="ats-score-pill ' . $atsTier . '"><i class="fa-solid fa-gauge-high me-1"></i> ' . $ats . '/100</span>';
                                            } else {
                                                echo '<span class="ats-score-pill tier-missing">Not Scored</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($c['rule_score'] !== null || $c['ai_score'] !== null): ?>
                                                <span class="small text-muted">
                                                    Rule: <strong class="text-dark"><?php echo $c['rule_score'] ?? '0'; ?></strong> | AI: <strong class="text-dark"><?php echo $c['ai_score'] ?? '0'; ?></strong>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="small text-muted">
                                                <?php echo $c['uploaded_at'] ? date('d M Y, h:i A', strtotime($c['uploaded_at'])) : '—'; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex align-items-center justify-content-end gap-1.5">
                                                <?php if ($c['resume_id']): ?>
                                                    <a href="student-view.php?student_id=<?php echo $c['student_id']; ?>#resumes" class="btn btn-sm btn-light border px-2.5 py-1 text-primary" title="View Resume & Analysis">
                                                        <i class="fa-solid fa-file-lines"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="notification-center.php?send_to=<?php echo $c['student_id']; ?>" class="btn btn-sm btn-outline-danger px-2 py-1" title="Send Upload Reminder">
                                                        <i class="fa-solid fa-bell"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="student-view.php?student_id=<?php echo $c['student_id']; ?>" class="btn btn-sm btn-light border px-2 py-1 text-dark" title="View Full Profile">
                                                    <i class="fa-solid fa-user"></i>
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
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // -------------------------------------------------------------
    // 1. HIGH-RESOLUTION ATS SCORE DISTRIBUTION BAR CHART
    // -------------------------------------------------------------
    const distCanvas = document.getElementById('scoreDistChart');
    if (distCanvas) {
        const distCtx = distCanvas.getContext('2d');
        
        // Create custom vibrant gradients
        const gradRed = distCtx.createLinearGradient(0, 0, 0, 260);
        gradRed.addColorStop(0, '#f87171');
        gradRed.addColorStop(1, '#ef4444');

        const gradAmber = distCtx.createLinearGradient(0, 0, 0, 260);
        gradAmber.addColorStop(0, '#fbbf24');
        gradAmber.addColorStop(1, '#f59e0b');

        const gradBlue = distCtx.createLinearGradient(0, 0, 0, 260);
        gradBlue.addColorStop(0, '#60a5fa');
        gradBlue.addColorStop(1, '#2563eb');

        const gradTeal = distCtx.createLinearGradient(0, 0, 0, 260);
        gradTeal.addColorStop(0, '#2dd4bf');
        gradTeal.addColorStop(1, '#0d9488');

        const gradEmerald = distCtx.createLinearGradient(0, 0, 0, 260);
        gradEmerald.addColorStop(0, '#34d399');
        gradEmerald.addColorStop(1, '#10b981');

        new Chart(distCtx, {
            type: 'bar',
            data: {
                labels: ['Needs Work (0-39)', 'Basic (40-59)', 'Good (60-74)', 'Very Good (75-89)', 'Excellent (90-100)'],
                datasets: [{
                    label: 'Students Count',
                    data: [
                        <?php echo $scoreIntervals['0-39']; ?>,
                        <?php echo $scoreIntervals['40-59']; ?>,
                        <?php echo $scoreIntervals['60-74']; ?>,
                        <?php echo $scoreIntervals['75-89']; ?>,
                        <?php echo $scoreIntervals['90-100']; ?>
                    ],
                    backgroundColor: [gradRed, gradAmber, gradBlue, gradTeal, gradEmerald],
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: 'Outfit', weight: 'bold' },
                        bodyFont: { family: 'Plus Jakarta Sans' },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = <?php echo max(1, count($scores)); ?>;
                                const val = context.raw || 0;
                                const pct = ((val / total) * 100).toFixed(1);
                                return ` ${val} Students (${pct}% of cohort)`;
                            }
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: { stepSize: 1, font: { family: 'Plus Jakarta Sans' } } 
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Plus Jakarta Sans', weight: '600' } }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 2. COHORT READINESS DONUT CHART
    // -------------------------------------------------------------
    const healthCanvas = document.getElementById('cohortHealthChart');
    if (healthCanvas) {
        const healthCtx = healthCanvas.getContext('2d');
        new Chart(healthCtx, {
            type: 'doughnut',
            data: {
                labels: ['Drive Ready (≥70)', 'Developing (40-69)', 'Missing Resume'],
                datasets: [{
                    data: [
                        <?php echo $studentsAboveThreshold; ?>,
                        <?php echo max(0, $studentsWithResume - $studentsAboveThreshold); ?>,
                        <?php echo $studentsWithoutResume; ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 3,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        cornerRadius: 8,
                        padding: 10
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 3. DEPARTMENT BENCHMARK COMPARISON CHART
    // -------------------------------------------------------------
    const deptCanvas = document.getElementById('deptCompletionChart');
    if (deptCanvas) {
        const deptCtx = deptCanvas.getContext('2d');
        new Chart(deptCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($deptNames); ?>,
                datasets: [
                    {
                        label: 'Uploaded Resumes',
                        data: <?php echo json_encode($deptUploads); ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        barPercentage: 0.7
                    },
                    {
                        label: 'Drive Ready (≥70)',
                        data: <?php echo json_encode($deptHighScorers); ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6,
                        barPercentage: 0.7
                    },
                    {
                        label: 'Total Students',
                        data: <?php echo json_encode($deptTotals); ?>,
                        backgroundColor: '#e2e8f0',
                        borderRadius: 6,
                        barPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        cornerRadius: 8,
                        padding: 12
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: { stepSize: 1 } 
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 4. ACTIONABLE CANDIDATE DATATABLE & SEGMENTED FILTER TABS
    // -------------------------------------------------------------
    if ($('#candidateTelemetryTable').length) {
        let currentRosterFilter = 'all';

        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'candidateTelemetryTable') return true;
            if (currentRosterFilter === 'all') return true;
            
            const rowNode = settings.aoData[dataIndex].nTr;
            const rowStatus = $(rowNode).attr('data-status');
            return rowStatus === currentRosterFilter;
        });

        const dTable = $('#candidateTelemetryTable').DataTable({
            "pageLength": 25,
            "order": [[1, "asc"]],
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search candidates...",
                "lengthMenu": "Show _MENU_ entries",
                "info": "Showing _START_ to _END_ of _TOTAL_ candidates",
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

        // Tab click filter dispatch
        $('.attention-nav-btn').on('click', function() {
            $('.attention-nav-btn').removeClass('active');
            $(this).addClass('active');

            currentRosterFilter = $(this).data('filter');
            dTable.draw();
        });
    }
});
</script>
