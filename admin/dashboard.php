<?php
// MAMCET Placement & Learning Portal - Officer & Admin Analytics Dashboard

$pageTitle = 'Officer Dashboard';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();

$activeSessionId = getActiveAcademicSessionId();

// Filter values
$filterBatchId = $_GET['batch_id'] ?? '';
$filterDeptId = $_GET['dept_id'] ?? '';
$filterWillingness = $_GET['placement_willingness'] ?? '';
$filterArrears = $_GET['arrears_status'] ?? '';
$hasActiveFilters = !empty($filterBatchId) || !empty($filterDeptId) || $filterWillingness !== '' || !empty($filterArrears);

// Build basic query strings for filters
$whereParams = [$activeSessionId, $activeSessionId, $activeSessionId];
$whereSql = "(
    s.batch_id IN (SELECT batch_id FROM batch_academic_sessions WHERE session_id = ?)
    OR s.batch_id IN (
        SELECT b.batch_id FROM batches b 
        JOIN academic_sessions sess ON sess.session_id = ? 
        WHERE b.admission_year <= sess.start_year AND b.graduation_year >= sess.end_year
    )
    OR NOT EXISTS (SELECT 1 FROM batch_academic_sessions WHERE session_id = ?)
)";

if (!empty($filterBatchId)) {
    $whereSql .= " AND s.batch_id = ?";
    $whereParams[] = $filterBatchId;
}
if (!empty($filterDeptId)) {
    $whereSql .= " AND s.dept_id = ?";
    $whereParams[] = $filterDeptId;
}
if ($filterWillingness !== '') {
    $whereSql .= " AND s.placement_willingness = ?";
    $whereParams[] = $filterWillingness;
}
if ($filterArrears === 'no_arrears') {
    $whereSql .= " AND s.student_id IN (SELECT student_id FROM student_academics WHERE standing_arrears = 0)";
} elseif ($filterArrears === 'with_arrears') {
    $whereSql .= " AND s.student_id IN (SELECT student_id FROM student_academics WHERE standing_arrears > 0)";
}

// Compute Placement Package LPA Stats (respects student filter configurations)
$stmtPkg = $db->prepare("
    SELECT MAX(sp.package_lpa) AS max_lpa, AVG(sp.package_lpa) AS avg_lpa, COUNT(sp.placement_id) AS total_offers
    FROM student_placements sp
    JOIN students s ON sp.student_id = s.student_id
    WHERE $whereSql
");
$stmtPkg->execute($whereParams);
$pkgStats = $stmtPkg->fetch();

$highestLpa = (float)($pkgStats['max_lpa'] ?? 0.00);
$averageLpa = (float)($pkgStats['avg_lpa'] ?? 0.00);
$totalOffers = (int)($pkgStats['total_offers'] ?? 0);

// -------------------------------------------------------------
// METRIC CALCULATIONS
// -------------------------------------------------------------

// Total Students
$stmt = $db->prepare("SELECT COUNT(*) FROM students s WHERE $whereSql");
$stmt->execute($whereParams);
$totalStudents = (int)$stmt->fetchColumn();

// Total Departments
$totalDepts = (int)$db->query("SELECT COUNT(*) FROM departments WHERE status = 'active'")->fetchColumn();

// Placed Students
$placedParams = array_merge($whereParams);
$stmt = $db->prepare("SELECT COUNT(*) FROM students s WHERE $whereSql AND s.placement_status = 'Placed'");
$stmt->execute($placedParams);
$placedStudents = (int)$stmt->fetchColumn();

// Willing Students
$stmt = $db->prepare("SELECT COUNT(*) FROM students s WHERE $whereSql AND s.placement_willingness = 'Yes'");
$stmt->execute($whereParams);
$willingStudents = (int)$stmt->fetchColumn();

// Eligible Students (CGPA >= 6.0 and standing arrears = 0)
$stmt = $db->prepare("
    SELECT COUNT(s.student_id) FROM students s 
    JOIN student_academics sa ON s.student_id = sa.student_id
    WHERE $whereSql AND sa.current_cgpa >= 6.0 AND sa.standing_arrears = 0
");
$stmt->execute($whereParams);
$eligibleStudents = (int)$stmt->fetchColumn();

// Active LMS Courses
$stmt = $db->prepare("SELECT COUNT(*) FROM courses WHERE session_id = ? AND status = 'active'");
$stmt->execute([$activeSessionId]);
$activeCourses = (int)$stmt->fetchColumn();

// Published Announcements
$stmt = $db->prepare("SELECT COUNT(*) FROM announcements WHERE session_id = ? AND status = 'published'");
$stmt->execute([$activeSessionId]);
$activeAnnouncements = (int)$stmt->fetchColumn();

// Profile completed count (> 80% is completed)
$completedProfiles = 0;
$incompleteProfiles = 0;

$stmtS = $db->prepare("SELECT student_id FROM students s WHERE $whereSql");
$stmtS->execute($whereParams);
$studIds = $stmtS->fetchAll(PDO::FETCH_COLUMN);

foreach ($studIds as $sId) {
    $pct = calculateProfileCompletion($db, $sId);
    if ($pct >= 80) {
        $completedProfiles++;
    } else {
        $incompleteProfiles++;
    }
}

$placementRate = $totalStudents > 0 ? round(($placedStudents / $totalStudents) * 100, 1) : 0;

// Total Certificates Uploaded & Student Certificate Counts
ensureCertificationsTable($db);
$stmtTotalCerts = $db->prepare("
    SELECT COUNT(sc.cert_id) 
    FROM student_certifications sc
    JOIN students s ON sc.student_id = s.student_id
    WHERE $whereSql
");
$stmtTotalCerts->execute($whereParams);
$totalCertificatesCount = (int)$stmtTotalCerts->fetchColumn();

$stmtStudentCerts = $db->prepare("
    SELECT s.student_id, s.registration_number, s.student_name, d.dept_code, b.batch_name,
           COUNT(sc.cert_id) AS cert_count,
           SUM(CASE WHEN sc.status = 'Verified' THEN 1 ELSE 0 END) AS verified_count,
           SUM(CASE WHEN sc.status = 'Pending Verification' THEN 1 ELSE 0 END) AS pending_count
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN student_certifications sc ON s.student_id = sc.student_id
    WHERE $whereSql
    GROUP BY s.student_id, s.registration_number, s.student_name, d.dept_code, b.batch_name
    ORDER BY cert_count DESC, s.student_name ASC
    LIMIT 10
");
$stmtStudentCerts->execute($whereParams);
$studentCertSummaries = $stmtStudentCerts->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// CHARTS DATA PREPARATION
// -------------------------------------------------------------

// 1. Department-wise Student Count
$stmtChartDepts = $db->prepare("
    SELECT d.dept_code, COUNT(s.student_id) AS student_count 
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    WHERE $whereSql
    GROUP BY d.dept_id
");
$stmtChartDepts->execute($whereParams);
$chartDepts = $stmtChartDepts->fetchAll();
$deptCodes = array_column($chartDepts, 'dept_code');
$deptStudentCounts = array_column($chartDepts, 'student_count');

// 2. Placement Status Distribution
$stmtChartStatus = $db->prepare("
    SELECT s.placement_status, COUNT(s.student_id) AS student_count 
    FROM students s
    WHERE $whereSql
    GROUP BY s.placement_status
");
$stmtChartStatus->execute($whereParams);
$chartStatus = $stmtChartStatus->fetchAll();
$statusLabels = array_column($chartStatus, 'placement_status');
$statusCounts = array_column($chartStatus, 'student_count');

// 3. CGPA Classifications
$cgpaRanges = ['< 6.0' => 0, '6.0 - 7.0' => 0, '7.0 - 8.0' => 0, '8.0 - 9.0' => 0, '> 9.0' => 0];
$stmtChartCgpa = $db->prepare("
    SELECT sa.current_cgpa 
    FROM student_academics sa
    JOIN students s ON sa.student_id = s.student_id
    WHERE $whereSql
");
$stmtChartCgpa->execute($whereParams);
$cgpas = $stmtChartCgpa->fetchAll(PDO::FETCH_COLUMN);

foreach ($cgpas as $c) {
    $val = (float)$c;
    if ($val < 6.0) $cgpaRanges['< 6.0']++;
    elseif ($val >= 6.0 && $val < 7.0) $cgpaRanges['6.0 - 7.0']++;
    elseif ($val >= 7.0 && $val < 8.0) $cgpaRanges['7.0 - 8.0']++;
    elseif ($val >= 8.0 && $val < 9.0) $cgpaRanges['8.0 - 9.0']++;
    else $cgpaRanges['> 9.0']++;
}

// -------------------------------------------------------------
// RECENT ACTIVITY LOGS
// -------------------------------------------------------------

// Recent student profile updates
$stmtRecentUpdates = $db->prepare("
    SELECT l.*, s.student_name, s.registration_number 
    FROM student_update_logs l
    JOIN students s ON l.student_id = s.student_id
    ORDER BY l.updated_at DESC LIMIT 6
");
$stmtRecentUpdates->execute();
$recentUpdates = $stmtRecentUpdates->fetchAll();

// Scoped dropdown resources
$allBatches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$allDepts = $db->query("SELECT * FROM departments ORDER BY dept_code")->fetchAll();
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <div class="db-container">
            
            <!-- 1. DASHBOARD DRILL-DOWN FILTERS TOOLBAR -->
            <div class="db-filter-card">
                <form action="dashboard.php" method="GET" class="row align-items-center g-3">
                    <div class="col-xl-auto col-12 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-filter text-primary"></i>
                        <span class="small fw-bold text-dark">Roster Filters:</span>
                    </div>
                    
                    <div class="col-xl-2 col-md-3 col-6">
                        <select name="batch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Batches</option>
                            <?php foreach ($allBatches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $filterBatchId == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-3 col-6">
                        <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $filterDeptId == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-3 col-6">
                        <select name="placement_willingness" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Willingness</option>
                            <option value="Yes" <?php echo $filterWillingness === 'Yes' ? 'selected' : ''; ?>>Willing</option>
                            <option value="No" <?php echo $filterWillingness === 'No' ? 'selected' : ''; ?>>Not Willing</option>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-3 col-6">
                        <select name="arrears_status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Arrears</option>
                            <option value="no_arrears" <?php echo $filterArrears === 'no_arrears' ? 'selected' : ''; ?>>0 Arrears (Clear)</option>
                            <option value="with_arrears" <?php echo $filterArrears === 'with_arrears' ? 'selected' : ''; ?>>With Arrears</option>
                        </select>
                    </div>

                    <div class="col-xl-auto col-12 ms-xl-auto d-flex align-items-center justify-content-between gap-3">
                        <?php if ($hasActiveFilters): ?>
                            <a href="dashboard.php" class="btn btn-sm btn-link text-danger text-decoration-none small p-0">
                                <i class="fa-solid fa-xmark me-1"></i> Reset Filters
                            </a>
                        <?php endif; ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill small fw-semibold">
                            <i class="fa-solid fa-calendar-check me-1"></i> <?php echo esc($activeSessionName); ?>
                        </span>
                    </div>
                </form>
            </div>

            <!-- 2. TIER 1: KEY PLACEMENT HIGHLIGHTS (HERO ROW) -->
            <div>
                <div class="db-section-header">
                    <div>
                        <h4 class="db-section-title">
                            <i class="fa-solid fa-award"></i> Placement & Package Highlights
                        </h4>
                    </div>
                    <span class="db-section-subtitle">Real-time drive performance</span>
                </div>

                <div class="row g-3 g-xl-4">
                    <div class="col-lg-3 col-sm-6 col-12">
                        <div class="db-hero-kpi highlight-emerald">
                            <div class="db-hero-kpi-icon bg-success-subtle text-success">
                                <i class="fa-solid fa-money-bill-trend-up"></i>
                            </div>
                            <div>
                                <div class="db-hero-kpi-val text-success"><?php echo number_format($highestLpa, 2); ?> <span style="font-size:1rem; font-weight:700;">LPA</span></div>
                                <div class="db-hero-kpi-label">Highest Package Offered</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-sm-6 col-12">
                        <div class="db-hero-kpi highlight-blue">
                            <div class="db-hero-kpi-icon bg-primary-subtle text-primary">
                                <i class="fa-solid fa-calculator"></i>
                            </div>
                            <div>
                                <div class="db-hero-kpi-val text-primary"><?php echo number_format($averageLpa, 2); ?> <span style="font-size:1rem; font-weight:700;">LPA</span></div>
                                <div class="db-hero-kpi-label">Average Package (LPA)</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-sm-6 col-12">
                        <div class="db-hero-kpi highlight-amber">
                            <div class="db-hero-kpi-icon bg-warning-subtle text-warning">
                                <i class="fa-solid fa-award"></i>
                            </div>
                            <div>
                                <div class="db-hero-kpi-val text-dark"><?php echo $totalOffers; ?> <span style="font-size:1rem; font-weight:700; color:var(--text-muted);">Offers</span></div>
                                <div class="db-hero-kpi-label">Total Offers Received</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-sm-6 col-12">
                        <div class="db-hero-kpi highlight-purple">
                            <div class="db-hero-kpi-icon" style="background: #f5f3ff; color: #8b5cf6;">
                                <i class="fa-solid fa-briefcase"></i>
                            </div>
                            <div>
                                <div class="db-hero-kpi-val text-dark"><?php echo $placedStudents; ?> <span style="font-size:0.95rem; font-weight:600; color:var(--text-muted);">(<?php echo $placementRate; ?>%)</span></div>
                                <div class="db-hero-kpi-label">Placed Students</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. TIER 2: STUDENT COHORT & READINESS METRICS -->
            <div>
                <div class="db-section-header">
                    <div>
                        <h4 class="db-section-title">
                            <i class="fa-solid fa-users-gear"></i> Cohort & Academic Standing
                        </h4>
                    </div>
                    <span class="db-section-subtitle">Student pool diagnostics</span>
                </div>

                <div class="db-metrics-grid">
                    <!-- Stat 1: Total Students -->
                    <div class="db-stat-tile">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Enrolled Students</span>
                            <div class="db-stat-icon primary">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $totalStudents; ?></div>
                        <div class="db-stat-footer">
                            <span>Active Departments: <strong><?php echo $totalDepts; ?></strong></span>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-2">Cohort</span>
                        </div>
                    </div>

                    <!-- Stat 2: Placement Willing -->
                    <div class="db-stat-tile">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Placement Willing</span>
                            <div class="db-stat-icon warning">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $willingStudents; ?></div>
                        <div class="db-stat-footer">
                            <span>Opt-in Rate: <strong><?php echo $totalStudents > 0 ? round(($willingStudents / $totalStudents) * 100, 1) : 0; ?>%</strong></span>
                            <span class="badge bg-warning-subtle text-warning rounded-pill px-2">Willing</span>
                        </div>
                    </div>

                    <!-- Stat 3: Placement Eligible -->
                    <div class="db-stat-tile">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Drive Eligible</span>
                            <div class="db-stat-icon info">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $eligibleStudents; ?></div>
                        <div class="db-stat-footer">
                            <span>Criteria: CGPA ≥ 6.0 & 0 Arrears</span>
                            <span class="badge bg-info-subtle text-info rounded-pill px-2">Ready</span>
                        </div>
                    </div>

                    <!-- Stat 4: Profile Readiness -->
                    <div class="db-stat-tile">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Profile Completed</span>
                            <div class="db-stat-icon success">
                                <i class="fa-solid fa-id-card"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $completedProfiles; ?></div>
                        <div class="db-stat-footer">
                            <span>Pending (&lt;80%): <strong><?php echo $incompleteProfiles; ?></strong></span>
                            <span class="badge bg-success-subtle text-success rounded-pill px-2">Complete</span>
                        </div>
                    </div>

                    <!-- Stat 5: Certificates Uploaded -->
                    <div class="db-stat-tile tile-teal">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Student Certificates</span>
                            <div class="db-stat-icon" style="background: #f0fdfa; color: #0d9488;">
                                <i class="fa-solid fa-certificate"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $totalCertificatesCount; ?></div>
                        <div class="db-stat-footer">
                            <span>Verified & In Review</span>
                            <a href="students.php" class="badge bg-success-subtle text-success rounded-pill px-2 text-decoration-none">
                                Roster <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. ANALYTICS CHARTS ROW (SPACIOUS 2-COLUMN + SPECTRUM) -->
            <div class="row g-4">
                <!-- Placement Distribution Chart -->
                <div class="col-lg-6 col-12">
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-chart-pie text-primary"></i>
                                Placement Status Distribution
                            </h5>
                            <span class="text-muted small">Status breakup</span>
                        </div>
                        <div class="db-card-body d-flex align-items-center justify-content-center" style="min-height: 280px;">
                            <div style="width: 100%; max-height: 260px; position: relative;">
                                <canvas id="placementStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Department Roster Distribution Chart -->
                <div class="col-lg-6 col-12">
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-chart-column text-primary"></i>
                                Department Roster Count
                            </h5>
                            <span class="text-muted small">Branch breakdown</span>
                        </div>
                        <div class="db-card-body d-flex align-items-center justify-content-center" style="min-height: 280px;">
                            <div style="width: 100%; max-height: 260px; position: relative;">
                                <canvas id="deptChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CGPA Classification Chart -->
                <div class="col-12">
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-graduation-cap text-primary"></i>
                                Academic CGPA Spectrum
                            </h5>
                            <span class="text-muted small">Cohort grade distribution</span>
                        </div>
                        <div class="db-card-body d-flex align-items-center justify-content-center">
                            <div style="width: 100%; max-height: 240px; position: relative;">
                                <canvas id="cgpaChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. RECENT PROFILE UPDATES AUDIT LEDGER -->
            <div class="db-card-modern">
                <div class="db-card-header">
                    <h5 class="db-card-title">
                        <i class="fa-solid fa-clock-rotate-left text-primary"></i>
                        Recent Student Profile Updates
                    </h5>
                    <span class="text-muted small">Live activity log</span>
                </div>
                <div class="db-card-body p-0">
                    <?php if (empty($recentUpdates)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-regular fa-clock fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No student profile modifications recorded in the active session.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4 py-3">Student</th>
                                        <th class="py-3">Field Modified</th>
                                        <th class="py-3">Old Value</th>
                                        <th class="py-3">New Value</th>
                                        <th class="pe-4 py-3 text-end">Update Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUpdates as $l): ?>
                                        <tr>
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="db-feed-avatar">
                                                        <?php echo strtoupper(substr($l['student_name'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <strong class="text-dark d-block"><?php echo esc($l['student_name']); ?></strong>
                                                        <small class="text-muted"><?php echo esc($l['registration_number']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3">
                                                <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.78rem;">
                                                    <?php echo esc($l['field_name']); ?>
                                                </span>
                                            </td>
                                            <td class="py-3">
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle text-decoration-line-through" style="font-size: 0.78rem;">
                                                    <?php echo esc(substr($l['old_value'] ?? '', 0, 45)) ?: '(empty)'; ?>
                                                </span>
                                            </td>
                                            <td class="py-3">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold" style="font-size: 0.78rem;">
                                                    <?php echo esc(substr($l['new_value'] ?? '', 0, 45)) ?: '(empty)'; ?>
                                                </span>
                                            </td>
                                            <td class="pe-4 py-3 text-end text-muted small">
                                                <i class="fa-regular fa-clock me-1"></i>
                                                <?php echo date('d M Y, h:i A', strtotime($l['updated_at'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 6. STUDENT CERTIFICATE REPOSITORY & COUNTS -->
            <div class="db-card-modern">
                <div class="db-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="db-card-title">
                            <i class="fa-solid fa-certificate text-primary"></i>
                            Student Certificates & Credentials Summary
                        </h5>
                        <span class="text-muted small">Certificate counts by student with direct verification access</span>
                    </div>
                    <a href="students.php" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.78rem;">
                        View All Students in Roster <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="db-card-body p-0">
                    <?php if (empty($studentCertSummaries)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-certificate fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No student records found in active session.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4 py-3">Student</th>
                                        <th class="py-3">Department & Batch</th>
                                        <th class="py-3 text-center">Total Certificates</th>
                                        <th class="py-3 text-center">Verification Breakdown</th>
                                        <th class="pe-4 py-3 text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentCertSummaries as $sc): ?>
                                        <?php $cCount = (int)$sc['cert_count']; ?>
                                        <tr>
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="db-feed-avatar" style="background:#f0fdfa; color:#0d9488; font-weight:700;">
                                                        <?php echo strtoupper(substr($sc['student_name'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <a href="student-view.php?student_id=<?php echo $sc['student_id']; ?>#certificates" class="text-dark fw-bold d-block text-decoration-none">
                                                            <?php echo esc($sc['student_name']); ?>
                                                        </a>
                                                        <small class="text-muted"><?php echo esc($sc['registration_number']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3">
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold" style="font-size: 0.76rem;">
                                                    <?php echo esc($sc['dept_code']); ?>
                                                </span>
                                                <small class="text-muted ms-1"><?php echo esc($sc['batch_name']); ?></small>
                                            </td>
                                            <td class="py-3 text-center">
                                                <span class="badge rounded-pill px-3 py-1.5 fw-bold" style="<?php echo $cCount > 0 ? 'background:#f0fdfa; color:#0d9488; border:1px solid #ccfbf1; font-size:0.85rem;' : 'background:#f8fafc; color:#94a3b8; border:1px solid #e2e8f0; font-size:0.82rem;'; ?>">
                                                    <i class="fa-solid fa-certificate me-1"></i> <?php echo $cCount; ?>
                                                </span>
                                            </td>
                                            <td class="py-3 text-center">
                                                <?php if ($cCount > 0): ?>
                                                    <div class="d-inline-flex gap-1.5 flex-wrap justify-content-center">
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.72rem;" title="Verified">
                                                            ✓ <?php echo (int)$sc['verified_count']; ?> Verified
                                                        </span>
                                                        <?php if ((int)$sc['pending_count'] > 0): ?>
                                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size:0.72rem;" title="Pending Verification">
                                                                ⏳ <?php echo (int)$sc['pending_count']; ?> Pending
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">None uploaded</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="pe-4 py-3 text-end">
                                                <a href="student-view.php?student_id=<?php echo $sc['student_id']; ?>#certificates" class="btn btn-xs btn-outline-primary rounded-pill px-3 py-1 fw-semibold" style="font-size: 0.78rem;">
                                                    <i class="fa-solid fa-eye me-1"></i> View Certificates
                                                </a>
                                            </td>
                                        </tr>
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

<!-- Setup Charts JavaScript -->
<script>
$(document).ready(function() {
    // 1. Placement Status Chart
    const statusCtx = document.getElementById('placementStatusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map('ucfirst', $statusLabels)); ?>,
            datasets: [{
                data: <?php echo json_encode($statusCounts); ?>,
                backgroundColor: ['#10b981', '#f59e0b', '#06b6d4', '#8b5cf6', '#64748b'],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { 
                    position: 'bottom', 
                    labels: { 
                        boxWidth: 12, 
                        padding: 16,
                        font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' } 
                    } 
                }
            }
        }
    });

    // 2. Department-wise Student Count Chart
    const deptCtx = document.getElementById('deptChart').getContext('2d');
    new Chart(deptCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($deptCodes); ?>,
            datasets: [{
                label: 'Students Count',
                data: <?php echo json_encode($deptStudentCounts); ?>,
                backgroundColor: '#3b82f6',
                hoverBackgroundColor: '#2563eb',
                borderRadius: 8,
                maxBarThickness: 38
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12 },
                    bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                    padding: 10,
                    cornerRadius: 8
                }
            },
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { color: '#f1f5f9' },
                    ticks: { font: { family: 'Plus Jakarta Sans', size: 11 } }
                },
                x: { 
                    grid: { display: false },
                    ticks: { font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' } }
                }
            }
        }
    });

    // 3. CGPA Chart
    const cgpaCtx = document.getElementById('cgpaChart').getContext('2d');
    new Chart(cgpaCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($cgpaRanges)); ?>,
            datasets: [{
                label: 'No. of Students',
                data: <?php echo json_encode(array_values($cgpaRanges)); ?>,
                backgroundColor: '#0d9488',
                hoverBackgroundColor: '#0f766e',
                borderRadius: 8,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12 },
                    bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                    padding: 10,
                    cornerRadius: 8
                }
            },
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { color: '#f1f5f9' },
                    ticks: { font: { family: 'Plus Jakarta Sans', size: 11 } }
                },
                x: { 
                    grid: { display: false },
                    ticks: { font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' } }
                }
            }
        }
    });
});
</script>


