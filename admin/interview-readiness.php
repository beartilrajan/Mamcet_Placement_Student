<?php
// MAMCET Placement & Learning Portal - Officer Interview Readiness Scores View
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$selectedDept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$selectedBatch = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();

// Build filter SQL query
$query = "
    SELECT s.student_id, s.student_name, s.register_number, 
           d.dept_code, b.batch_name,
           COALESCE(prs.readiness_score, 0) AS readiness_score,
           sa.current_cgpa, sa.standing_arrears
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN placement_readiness_scores prs ON s.student_id = prs.student_id
    WHERE 1=1
";
$params = [];

if ($selectedDept > 0) {
    $query .= " AND s.dept_id = ? ";
    $params[] = $selectedDept;
}
if ($selectedBatch > 0) {
    $query .= " AND s.batch_id = ? ";
    $params[] = $selectedBatch;
}

$query .= " ORDER BY prs.readiness_score DESC, s.student_name ASC ";
$stmtSt = $db->prepare($query);
$stmtSt->execute($params);
$readinessList = $stmtSt->fetchAll();
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-star text-warning"></i> Candidates Readiness Scoring</h1>

            <!-- Filters card -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Branch / Department</label>
                            <select class="form-select" name="dept_id">
                                <option value="0">All Departments</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $selectedDept === (int)$d['dept_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_name']); ?> (<?php echo $d['dept_code']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Graduation Batch</label>
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

            <!-- READINESS LEADERBOARD -->
            <div class="card shadow mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="m-0 font-weight-bold">Talent Readiness Index Matrix</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="readinessLeaderboard" width="100%">
                            <thead>
                                <tr>
                                    <th>Register No.</th>
                                    <th>Student Name</th>
                                    <th>Branch</th>
                                    <th>CGPA</th>
                                    <th>Standing Arrears</th>
                                    <th>Readiness Score</th>
                                    <th>Profile</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($readinessList)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">No student records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($readinessList as $row): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($row['register_number']); ?></code></td>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['dept_code']); ?></td>
                                            <td><?php echo htmlspecialchars($row['current_cgpa']); ?></td>
                                            <td><?php echo htmlspecialchars($row['standing_arrears']); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="font-weight-bold text-primary me-2" style="font-size:0.95rem;"><?php echo $row['readiness_score']; ?>%</span>
                                                    <div class="progress w-100" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $row['readiness_score']; ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="student-view.php?student_id=<?php echo $row['student_id']; ?>" class="btn btn-sm btn-outline-info">
                                                    <i class="fa-solid fa-user"></i> View Profile
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    if ($('#readinessLeaderboard').find('tbody tr').length > 1) {
        $('#readinessLeaderboard').DataTable({
            order: [[5, 'desc']]
        });
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
