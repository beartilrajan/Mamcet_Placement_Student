<?php
// MAMCET Placement & Learning Portal - Officer Resume Analytics & Charts
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

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

// 1. Calculate General Aggregated Stats
// Total Students
$stmtTot = $db->prepare("SELECT COUNT(*) FROM students s " . $filterClause);
$stmtTot->execute($params);
$totalStudents = (int)$stmtTot->fetchColumn();

// Students with resumes
$stmtWithRes = $db->prepare("
    SELECT COUNT(DISTINCT s.student_id) 
    FROM students s 
    JOIN resume_files rf ON s.student_id = rf.student_id 
    " . $filterClause
);
$stmtWithRes->execute($params);
$studentsWithResume = (int)$stmtWithRes->fetchColumn();
$studentsWithoutResume = max(0, $totalStudents - $studentsWithResume);

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

// 2. Build Chart JSON Data: ATS score distribution
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

// 3. Build Department-wise uploads data
$deptNames = [];
$deptUploads = [];
$deptTotals = [];

foreach ($departments as $d) {
    $stmtDeptTot = $db->prepare("SELECT COUNT(*) FROM students s WHERE s.dept_id = ? " . ($selectedBatch > 0 ? " AND s.batch_id = ? " : ""));
    $dParams = [$d['dept_id']];
    if ($selectedBatch > 0) $dParams[] = $selectedBatch;
    $stmtDeptTot->execute($dParams);
    $dTot = (int)$stmtDeptTot->fetchColumn();

    $stmtDeptRes = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) 
        FROM students s 
        JOIN resume_files rf ON s.student_id = rf.student_id 
        WHERE s.dept_id = ? " . ($selectedBatch > 0 ? " AND s.batch_id = ? " : "")
    );
    $stmtDeptRes->execute($dParams);
    $dRes = (int)$stmtDeptRes->fetchColumn();

    if ($dTot > 0) {
        $deptNames[] = $d['dept_code'];
        $deptUploads[] = $dRes;
        $deptTotals[] = $dTot;
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-magnifying-glass-chart text-primary"></i> Resume Analytics Dashboard</h1>

            <!-- Filter header -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Filter by Department</label>
                            <select class="form-select" name="dept_id">
                                <option value="0">All Departments</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $selectedDept === (int)$d['dept_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_name']); ?> (<?php echo $d['dept_code']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Filter by Batch</label>
                            <select class="form-select" name="batch_id">
                                <option value="0">All Batches</option>
                                <?php foreach ($batches as $b): ?>
                                    <option value="<?php echo $b['batch_id']; ?>" <?php echo $selectedBatch === (int)$b['batch_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['batch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats metrics Cards -->
            <div class="row">
                <!-- Students with Resumes -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Resumes Uploaded</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $studentsWithResume; ?> / <?php echo $totalStudents; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fa-solid fa-file-circle-check fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Missing Resumes -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-danger shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Missing Resumes</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $studentsWithoutResume; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fa-solid fa-file-circle-exclamation fa-2x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Average ATS Score -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Average ATS Score</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $averageAtsScore ?: '0.0'; ?>/100</div>
                                </div>
                                <div class="col-auto">
                                    <i class="fa-solid fa-gauge-high fa-2x text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Above Threshold -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">ATS Scores >= 70</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $studentsAboveThreshold; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fa-solid fa-circle-arrow-up fa-2x text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS LAYOUT -->
            <div class="row">
                <!-- Score Distribution Bar Chart -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow h-100">
                        <div class="card-header py-3 bg-light">
                            <h6 class="m-0 font-weight-bold text-dark">ATS Score Range Distribution</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="scoreDistChart" style="height: 300px;"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Department wise uploads Comparison Chart -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow h-100">
                        <div class="card-header py-3 bg-light">
                            <h6 class="m-0 font-weight-bold text-dark">Department-wise Resume Completion Ratio</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="deptCompletionChart" style="height: 300px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Score Distribution Chart
    const distCtx = document.getElementById('scoreDistChart').getContext('2d');
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
                backgroundColor: ['#e74a3b', '#f6c23e', '#4e73df', '#36b9cc', '#1cc88a'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });

    // 2. Department-wise Completion Chart
    const deptCtx = document.getElementById('deptCompletionChart').getContext('2d');
    new Chart(deptCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($deptNames); ?>,
            datasets: [
                {
                    label: 'Resumes Uploaded',
                    data: <?php echo json_encode($deptUploads); ?>,
                    backgroundColor: '#1cc88a'
                },
                {
                    label: 'Total Students',
                    data: <?php echo json_encode($deptTotals); ?>,
                    backgroundColor: '#eaecf4'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
