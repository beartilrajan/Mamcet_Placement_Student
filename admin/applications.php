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
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="page-container">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fa-solid fa-list-check text-primary me-2"></i>Placement Application Tracking</h1>
                <p class="text-muted small mb-0 mt-1">Review student applications, interview rounds progression, and offer statuses.</p>
            </div>
            <a href="opportunities.php" class="btn btn-sm btn-light border shadow-sm">
                <i class="fa-solid fa-bullhorn me-1 text-primary"></i> View Active Drives
            </a>
        </div>

        <!-- Micro-KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="compact-kpi-bar">
            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Total Applications</div>
                    <div class="compact-kpi-value text-primary"><?php echo count($applications); ?> Records</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-trophy"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Offers Issued</div>
                    <div class="compact-kpi-value text-success"><?php echo count(array_filter($applications, fn($a) => in_array($a['stage'], ['Selected', 'Offer Received']))); ?> Placed</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">In Interview Pipeline</div>
                    <div class="compact-kpi-value text-warning"><?php echo count(array_filter($applications, fn($a) => in_array($a['stage'], ['Technical Interview', 'HR Interview', 'Coding Test', 'Aptitude Test', 'Group Discussion']))); ?> Active</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-building"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Active Drives</div>
                    <div class="compact-kpi-value text-info"><?php echo count($drives); ?> Companies</div>
                </div>
            </div>
        </div>

        <!-- Filters card -->
        <div class="mamcet-card mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-5 col-12">
                        <label class="form-label mb-1">Filter by Campus Drive</label>
                        <select class="form-select form-select-sm" name="opportunity_id" onchange="this.form.submit()">
                            <option value="0">All Active Campus Drives</option>
                            <?php foreach ($drives as $dr): ?>
                                <option value="<?php echo $dr['opportunity_id']; ?>" <?php echo $selectedOppId === (int)$dr['opportunity_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dr['company_name']); ?> — <?php echo htmlspecialchars($dr['job_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12">
                        <label class="form-label mb-1">Recruitment Stage</label>
                        <select class="form-select form-select-sm" name="stage" onchange="this.form.submit()">
                            <option value="">All Recruitment Stages</option>
                            <option value="Applied" <?php echo $selectedStage === 'Applied' ? 'selected' : ''; ?>>Applied / Registered</option>
                            <option value="Aptitude Test" <?php echo $selectedStage === 'Aptitude Test' ? 'selected' : ''; ?>>Aptitude Test</option>
                            <option value="Coding Test" <?php echo $selectedStage === 'Coding Test' ? 'selected' : ''; ?>>Coding Test</option>
                            <option value="Group Discussion" <?php echo $selectedStage === 'Group Discussion' ? 'selected' : ''; ?>>Group Discussion</option>
                            <option value="Technical Interview" <?php echo $selectedStage === 'Technical Interview' ? 'selected' : ''; ?>>Technical Interview</option>
                            <option value="HR Interview" <?php echo $selectedStage === 'HR Interview' ? 'selected' : ''; ?>>HR Interview</option>
                            <option value="Selected" <?php echo $selectedStage === 'Selected' ? 'selected' : ''; ?>>Selected / Hired</option>
                            <option value="Offer Received" <?php echo $selectedStage === 'Offer Received' ? 'selected' : ''; ?>>Offer Letter Issued</option>
                            <option value="Rejected" <?php echo $selectedStage === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="fa-solid fa-filter me-1"></i> Apply Filter
                        </button>
                        <?php if ($selectedOppId > 0 || !empty($selectedStage)): ?>
                            <a href="applications.php" class="btn btn-sm btn-light border" title="Reset Filters">
                                <i class="fa-solid fa-rotate-left text-danger"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- APPLICATIONS ROSTER -->
        <div class="mamcet-card">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="fa-solid fa-table-list text-primary me-2"></i>Candidate Applications Roster</h6>
                <span class="badge bg-light text-dark border"><?php echo count($applications); ?> Applicants</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="applicationsRoster" width="100%">
                        <thead>
                            <tr>
                                <th class="ps-3">Student Candidate</th>
                                <th>Branch / Cohort</th>
                                <th>Target Drive</th>
                                <th>Stage Progression</th>
                                <th>Applied Date</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-5">
                                        <i class="fa-solid fa-folder-open fa-2x mb-2 opacity-50"></i>
                                        <p class="small mb-0">No candidate applications found matching the selected filters.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $app): 
                                    $stageColor = 'primary';
                                    if (in_array($app['stage'], ['Selected', 'Offer Received'])) $stageColor = 'success';
                                    elseif ($app['stage'] === 'Rejected') $stageColor = 'danger';
                                    elseif (in_array($app['stage'], ['Technical Interview', 'HR Interview'])) $stageColor = 'warning';
                                    elseif (in_array($app['stage'], ['Coding Test', 'Aptitude Test'])) $stageColor = 'info';
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <strong class="text-dark d-block"><?php echo htmlspecialchars($app['student_name']); ?></strong>
                                            <span class="badge bg-light text-muted border" style="font-size:0.72rem; font-family: monospace;">
                                                <?php echo htmlspecialchars($app['register_number'] ?? $app['registration_number'] ?? '-'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">
                                                <?php echo htmlspecialchars($app['dept_code']); ?>
                                            </span>
                                            <small class="text-muted d-block mt-1">Cohort <?php echo htmlspecialchars($app['batch_name']); ?></small>
                                        </td>
                                        <td>
                                            <strong class="text-dark d-block"><?php echo htmlspecialchars($app['company_name']); ?></strong>
                                            <span class="text-muted small"><?php echo htmlspecialchars($app['job_title']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $stageColor; ?>-subtle text-<?php echo $stageColor; ?> border border-<?php echo $stageColor; ?>-subtle">
                                                <i class="fa-solid fa-circle me-1" style="font-size:0.45rem;"></i>
                                                <?php echo htmlspecialchars($app['stage']); ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?php echo date('d M Y', strtotime($app['application_date'])); ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="application-view.php?id=<?php echo $app['application_id']; ?>" class="btn btn-sm btn-light border" title="Review Application">
                                                <i class="fa-solid fa-arrow-up-right-from-square text-primary me-1"></i> Review
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
            order: [[4, 'desc']],
            language: {
                search: "",
                searchPlaceholder: "Search applicants..."
            }
        });
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
