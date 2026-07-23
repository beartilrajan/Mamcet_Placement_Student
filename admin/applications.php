<?php
// MAMCET Placement & Learning Portal - Officer Placement Applications Manager
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$selectedOppId = isset($_GET['opportunity_id']) ? (int)$_GET['opportunity_id'] : 0;
$selectedStage = isset($_GET['stage']) ? trim($_GET['stage']) : '';

// Fetch active campus drives
$drives = $db->query("
    SELECT po.opportunity_id, jd.company_name, jd.job_title 
    FROM placement_opportunities po 
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    ORDER BY po.opportunity_id DESC
")->fetchAll();

// Build filter SQL query
$query = "
    SELECT pa.application_id, pa.stage, pa.application_date,
           s.student_id, s.student_name, s.register_number, 
           d.dept_code, b.batch_name,
           jd.company_name, jd.job_title
    FROM placement_applications pa
    JOIN students s ON pa.student_id = s.student_id
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    JOIN placement_opportunities po ON pa.opportunity_id = po.opportunity_id
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    WHERE 1=1
";
$params = [];

if ($selectedOppId > 0) {
    $query .= " AND pa.opportunity_id = ? ";
    $params[] = $selectedOppId;
}
if (!empty($selectedStage)) {
    $query .= " AND pa.stage = ? ";
    $params[] = $selectedStage;
}

$query .= " ORDER BY pa.application_id DESC ";
$stmtApps = $db->prepare($query);
$stmtApps->execute($params);
$applications = $stmtApps->fetchAll();
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-list-check text-primary"></i> Drive Applications Tracking</h1>

            <!-- Filters card -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-5 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Filter by Campus Drive</label>
                            <select class="form-select" name="opportunity_id">
                                <option value="0">All Active Drives</option>
                                <?php foreach ($drives as $dr): ?>
                                    <option value="<?php echo $dr['opportunity_id']; ?>" <?php echo $selectedOppId === (int)$dr['opportunity_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dr['company_name']); ?> - <?php echo htmlspecialchars($dr['job_title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label class="form-label font-weight-bold text-muted">Recruitment Stage</label>
                            <select class="form-select" name="stage">
                                <option value="">All Stages</option>
                                <option value="Applied" <?php echo $selectedStage === 'Applied' ? 'selected' : ''; ?>>Applied / Registered</option>
                                <option value="Aptitude Test" <?php echo $selectedStage === 'Aptitude Test' ? 'selected' : ''; ?>>Aptitude Test</option>
                                <option value="Coding Test" <?php echo $selectedStage === 'Coding Test' ? 'selected' : ''; ?>>Coding Test</option>
                                <option value="Group Discussion" <?php echo $selectedStage === 'Group Discussion' ? 'selected' : ''; ?>>Group Discussion</option>
                                <option value="Technical Interview" <?php echo $selectedStage === 'Technical Interview' ? 'selected' : ''; ?>>Technical Interview</option>
                                <option value="HR Interview" <?php echo $selectedStage === 'HR Interview' ? 'selected' : ''; ?>>HR Interview</option>
                                <option value="Selected" <?php echo $selectedStage === 'Selected' ? 'selected' : ''; ?>>Selected / Hired</option>
                                <option value="Rejected" <?php echo $selectedStage === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="Offer Received" <?php echo $selectedStage === 'Offer Received' ? 'selected' : ''; ?>>Offer Letter Issued</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- APPLICATIONS ROSTER -->
            <div class="card shadow mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="m-0 font-weight-bold">Candidate Sign-ups Log</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="applicationsRoster" width="100%">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Register No.</th>
                                    <th>Company</th>
                                    <th>Branch</th>
                                    <th>Stage</th>
                                    <th>Applied Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($applications)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">No applications found matching the search filters.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($applications as $app): ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($app['student_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($app['register_number']); ?></code></td>
                                            <td><?php echo htmlspecialchars($app['company_name']); ?> <small class="text-muted d-block"><?php echo htmlspecialchars($app['job_title']); ?></small></td>
                                            <td><?php echo htmlspecialchars($app['dept_code']); ?> <small class="text-muted d-block">Batch <?php echo htmlspecialchars($app['batch_name']); ?></small></td>
                                            <td>
                                                <span class="badge bg-primary p-2"><?php echo htmlspecialchars($app['stage']); ?></span>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($app['application_date'])); ?></td>
                                            <td>
                                                <a href="application-view.php?id=<?php echo $app['application_id']; ?>" class="btn btn-sm btn-outline-info">
                                                    <i class="fa-solid fa-eye"></i> View Profile
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
    if ($('#applicationsRoster').find('tbody tr').length > 1) {
        $('#applicationsRoster').DataTable({
            order: [[5, 'desc']]
        });
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
