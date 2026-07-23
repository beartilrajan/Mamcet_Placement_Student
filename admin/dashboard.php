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

// Build basic query strings for filters
$whereParams = [$activeSessionId];
$whereSql = "s.batch_id IN (SELECT batch_id FROM batch_academic_sessions WHERE session_id = ?)";

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
$placementWhereSql = str_replace('s.', 'st.', $whereSql);
$placementParams = $whereParams;

$stmtPkg = $db->prepare("
    SELECT MAX(sp.package_lpa) AS max_lpa, AVG(sp.package_lpa) AS avg_lpa, COUNT(sp.placement_id) AS total_offers
    FROM student_placements sp
    JOIN students st ON sp.student_id = st.student_id
    WHERE $placementWhereSql
");
$stmtPkg->execute($placementParams);
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

// Profile completed count (let's assume > 80% is completed)
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
    ORDER BY l.updated_at DESC LIMIT 5
");
$stmtRecentUpdates->execute();
$recentUpdates = $stmtRecentUpdates->fetchAll();

// Scoped dropdown resources
$allBatches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$allDepts = $db->query("SELECT * FROM departments ORDER BY dept_code")->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Placement Dashboard</h4>
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
        
        <!-- DASHBOARD DRILL-DOWN FILTERS -->
        <div class="mamcet-card mb-4 bg-light">
            <div class="card-body py-3">
                <form action="dashboard.php" method="GET" class="row align-items-center g-3">
                    <div class="col-md-auto col-12">
                        <span class="small fw-bold text-dark"><i class="fa-solid fa-filter me-1 text-primary"></i> Dashboard Filters:</span>
                    </div>
                    <div class="col-md-2 col-6">
                        <select name="batch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Batches</option>
                            <?php foreach ($allBatches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $filterBatchId == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $filterDeptId == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <select name="placement_willingness" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Willingness</option>
                            <option value="Yes" <?php echo $filterWillingness === 'Yes' ? 'selected' : ''; ?>>Willing</option>
                            <option value="No" <?php echo $filterWillingness === 'No' ? 'selected' : ''; ?>>Not Willing</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <select name="arrears_status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Arrears</option>
                            <option value="no_arrears" <?php echo $filterArrears === 'no_arrears' ? 'selected' : ''; ?>>No Standing Arrears</option>
                            <option value="with_arrears" <?php echo $filterArrears === 'with_arrears' ? 'selected' : ''; ?>>With Arrears</option>
                        </select>
                    </div>
                    <div class="col-md-auto col-12 text-end ms-auto">
                        <span class="badge bg-primary text-uppercase px-3 py-2 small">Session: <?php echo esc($activeSessionName); ?></span>
                    </div>
                </form>
            </div>
        </div>

        <!-- STAT CARDS ROW 1 -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $totalStudents; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon success"><i class="fa-solid fa-briefcase"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $placedStudents; ?></div>
                        <div class="stat-label">Placed Students</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="fa-solid fa-user-check"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $willingStudents; ?></div>
                        <div class="stat-label">Placement Willing</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon info"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $eligibleStudents; ?></div>
                        <div class="stat-label">Placement Eligible</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STAT CARDS ROW 2 -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon success"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $completedProfiles; ?></div>
                        <div class="stat-label">Completed Profiles</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="fa-solid fa-circle-exclamation"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $incompleteProfiles; ?></div>
                        <div class="stat-label">Incomplete Profiles</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon info"><i class="fa-solid fa-book-open"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $activeCourses; ?></div>
                        <div class="stat-label">LMS Active Courses</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="fa-solid fa-bullhorn"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $activeAnnouncements; ?></div>
                        <div class="stat-label">Published Drives</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STAT CARDS ROW 3: PLACEMENT PACKAGES (LPA) -->
        <div class="row g-4 mb-4">
            <div class="col-md-4 col-12">
                <div class="stat-card">
                    <div class="stat-icon success"><i class="fa-solid fa-money-bill-trend-up text-success"></i></div>
                    <div class="stat-info">
                        <div class="stat-value text-success"><?php echo number_format($highestLpa, 2); ?> LPA</div>
                        <div class="stat-label">Highest Package Offered</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="stat-card">
                    <div class="stat-icon info"><i class="fa-solid fa-calculator text-info"></i></div>
                    <div class="stat-info">
                        <div class="stat-value text-info"><?php echo number_format($averageLpa, 2); ?> LPA</div>
                        <div class="stat-label">Average Package (LPA)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="fa-solid fa-award text-primary"></i></div>
                    <div class="stat-info">
                        <div class="stat-value text-primary"><?php echo $totalOffers; ?></div>
                        <div class="stat-label">Total Job Offers Received</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CHARTS SECTION -->
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Placement Distribution</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width: 100%; max-height: 250px;">
                            <canvas id="placementStatusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Department Roster Distribution</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width: 100%; max-height: 250px;">
                            <canvas id="deptChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-12 col-lg-4 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">CGPA Classification</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width: 100%; max-height: 250px;">
                            <canvas id="cgpaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECENT UPDATES TIMELINE -->
        <div class="mamcet-card">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Recent Student Profile Updates</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentUpdates)): ?>
                    <p class="text-muted small text-center py-4">No student updates logged in the active session.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem; border: none;">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Field Modified</th>
                                    <th>Old Value</th>
                                    <th>New Value</th>
                                    <th>Update Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentUpdates as $l): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-dark d-block"><?php echo esc($l['student_name']); ?></strong>
                                            <small class="text-muted"><?php echo esc($l['registration_number']); ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo esc($l['field_name']); ?></span></td>
                                        <td><span class="text-danger small" style="text-decoration:line-through;"><?php echo esc(substr($l['old_value'] ?? '', 0, 50)) ?: '(empty)'; ?></span></td>
                                        <td><span class="text-success small fw-bold"><?php echo esc(substr($l['new_value'] ?? '', 0, 50)) ?: '(empty)'; ?></span></td>
                                        <td><small class="text-muted"><?php echo date('d M Y h:i A', strtotime($l['updated_at'])); ?></small></td>
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
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 10 } } }
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
                backgroundColor: '#2563eb',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { display: false } },
                x: { grid: { display: false } }
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
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { display: false } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

