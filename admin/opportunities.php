<?php
// MAMCET Placement & Learning Portal - Placement Opportunities Publishing
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Handle Toggle status action
if (isset($_GET['action']) && $_GET['action'] === 'toggle') {
    try {
        $oppId = (int)$_GET['id'];
        $status = $_GET['status'] === 'active' ? 'inactive' : 'active';
        
        $stmt = $db->prepare("UPDATE placement_opportunities SET status = ? WHERE opportunity_id = ?");
        $stmt->execute([$status, $oppId]);
        
        $message = "Opportunity status updated successfully.";
    } catch (Exception $e) {
        $error = "Failed to update status: " . $e->getMessage();
    }
}

// Handle Publish Opportunity Action
if (isset($_POST['publish_opp'])) {
    try {
        $jobId = (int)$_POST['job_id'];
        
        // Fetch Job Details
        $stmtJob = $db->prepare("SELECT session_id FROM job_descriptions WHERE job_id = ?");
        $stmtJob->execute([$jobId]);
        $session_id = $stmtJob->fetchColumn();

        if (!$session_id) {
            throw new Exception("Selected Job Description invalid.");
        }

        // Insert into placement_opportunities
        $stmtIns = $db->prepare("
            INSERT INTO placement_opportunities (job_id, session_id, status) 
            VALUES (?, ?, 'active')
            ON DUPLICATE KEY UPDATE status = 'active'
        ");
        $stmtIns->execute([$jobId, $session_id]);

        // Resolve opportunity_id for notification broadcast dispatcher
        $stmtFind = $db->prepare("SELECT opportunity_id FROM placement_opportunities WHERE job_id = ?");
        $stmtFind->execute([$jobId]);
        $oppRow = $stmtFind->fetch();
        $opportunityId = (int)($oppRow['opportunity_id'] ?? 0);

        if ($opportunityId > 0) {
            require_once(__DIR__ . '/../services/NotificationService.php');
            $senderId = (int)($_SESSION['user_id'] ?? 0);
            NotificationService::notifyOpportunityPublished($db, $opportunityId, $senderId);
        }

        $message = "Opportunity drive published successfully and email alerts broadcasted!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch published opportunities
$stmtOpps = $db->query("
    SELECT po.*, jd.company_name, jd.job_title, jd.job_role, jd.package_lpa, jd.application_deadline,
           (SELECT COUNT(*) FROM placement_applications pa WHERE pa.opportunity_id = po.opportunity_id) AS total_applicants
    FROM placement_opportunities po
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    ORDER BY po.opportunity_id DESC
");
$opportunities = $stmtOpps->fetchAll();

// Fetch unmapped Job Descriptions
$stmtJobsList = $db->query("
    SELECT job_id, company_name, job_title 
    FROM job_descriptions 
    WHERE job_id NOT IN (SELECT job_id FROM placement_opportunities)
");
$unmappedJobs = $stmtJobsList->fetchAll();
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="page-container">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fa-solid fa-bullhorn text-primary me-2"></i>Campus Placement Drives</h1>
                <p class="text-muted small mb-0 mt-1">Publish and manage campus recruitment opportunities for eligible students.</p>
            </div>
            <a href="job-description-form.php" class="btn btn-sm btn-primary shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> Add New Job Description
            </a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Micro-KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="compact-kpi-bar">
            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Total Drives</div>
                    <div class="compact-kpi-value text-primary"><?php echo count($opportunities); ?> Active</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Active Drives</div>
                    <div class="compact-kpi-value text-success"><?php echo count(array_filter($opportunities, fn($o) => $o['status'] === 'active')); ?> Published</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Unpublished JDs</div>
                    <div class="compact-kpi-value text-warning"><?php echo count($unmappedJobs); ?> Pending</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Total Applicants</div>
                    <div class="compact-kpi-value text-info"><?php echo array_sum(array_column($opportunities, 'total_applicants')); ?> Applied</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Publish New Drive panel -->
            <div class="col-lg-4 col-12">
                <div class="mamcet-card">
                    <div class="card-header">
                        <h6 class="card-title mb-0"><i class="fa-solid fa-paper-plane text-primary me-2"></i>Publish Campus Drive</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($unmappedJobs)): ?>
                            <div class="text-center py-4 text-muted">
                                <div class="mb-3">
                                    <i class="fa-solid fa-circle-check fa-2x text-success opacity-75"></i>
                                </div>
                                <p class="small text-dark fw-semibold mb-1">All Job Descriptions Published</p>
                                <p class="small text-muted mb-3">Create a new JD to launch additional campus recruitment drives.</p>
                                <a href="job-description-form.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fa-solid fa-plus me-1"></i> Create Job Description
                                </a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Select Job Description</label>
                                    <select class="form-select" name="job_id" required>
                                        <option value="">-- Choose Job Description --</option>
                                        <?php foreach ($unmappedJobs as $j): ?>
                                            <option value="<?php echo $j['job_id']; ?>"><?php echo htmlspecialchars($j['company_name']); ?> - <?php echo htmlspecialchars($j['job_title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted d-block mt-1">Publishing broadcasts instant email & in-app alerts to eligible students.</small>
                                </div>
                                <button type="submit" name="publish_opp" class="btn btn-primary w-100">
                                    <i class="fa-solid fa-bullhorn me-1"></i> Publish Announcement
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Active Opportunities list -->
            <div class="col-lg-8 col-12">
                <div class="mamcet-card">
                    <div class="card-header">
                        <h6 class="card-title mb-0"><i class="fa-solid fa-list-ul text-primary me-2"></i>Published Drive Registries</h6>
                        <span class="badge bg-light text-dark border"><?php echo count($opportunities); ?> Drives</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="opportunitiesTable" width="100%">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Company & Role</th>
                                        <th>Package (LPA)</th>
                                        <th>Applicants</th>
                                        <th>Status</th>
                                        <th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($opportunities)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-5">
                                                <i class="fa-solid fa-briefcase fa-2x mb-2 opacity-50"></i>
                                                <p class="small mb-0">No campus drives published yet.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($opportunities as $opp): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <strong class="text-dark d-block"><?php echo htmlspecialchars($opp['company_name']); ?></strong>
                                                    <span class="text-muted small"><?php echo htmlspecialchars($opp['job_title']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">
                                                        <?php echo $opp['package_lpa']; ?> LPA
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="applications.php?opportunity_id=<?php echo $opp['opportunity_id']; ?>" class="text-decoration-none">
                                                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                            <i class="fa-solid fa-users me-1"></i> <?php echo $opp['total_applicants']; ?> Applicants
                                                        </span>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php if ($opp['status'] === 'active'): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                            <i class="fa-solid fa-circle me-1" style="font-size:0.5rem;"></i> Active
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                            <i class="fa-solid fa-circle me-1" style="font-size:0.5rem;"></i> Inactive
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <a href="?action=toggle&id=<?php echo $opp['opportunity_id']; ?>&status=<?php echo $opp['status']; ?>" class="btn btn-sm btn-light border" title="Toggle Status">
                                                        <i class="fa-solid <?php echo $opp['status'] === 'active' ? 'fa-pause text-warning' : 'fa-play text-success'; ?> me-1"></i>
                                                        <?php echo $opp['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
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

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
