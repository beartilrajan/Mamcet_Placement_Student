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
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-bullhorn text-primary"></i> Placement Drive Opportunities Publisher</h1>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left: Publish New Drive panel -->
                <div class="col-lg-4 mb-4">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="m-0 font-weight-bold">Publish Campus Drive</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($unmappedJobs)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-circle-check fa-2x mb-2 text-success"></i>
                                    <p class="mb-0" style="font-size:0.85rem;">All Job Descriptions are currently published.</p>
                                    <a href="job-description-form.php" class="btn btn-sm btn-link mt-2">Add New JD</a>
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label font-weight-bold">Select Job Description</label>
                                        <select class="form-select" name="job_id" required>
                                            <option value="">-- Choose Job Description --</option>
                                            <?php foreach ($unmappedJobs as $j): ?>
                                                <option value="<?php echo $j['job_id']; ?>"><?php echo htmlspecialchars($j['company_name']); ?> - <?php echo htmlspecialchars($j['job_title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="publish_opp" class="btn btn-success w-100 font-weight-bold">
                                        <i class="fa-solid fa-bullhorn"></i> Publish Announcement
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Active Opportunities list -->
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-dark text-white py-3">
                            <h6 class="m-0 font-weight-bold">Published Drive Registries</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="opportunitiesTable" width="100%">
                                    <thead>
                                        <tr>
                                            <th>Company</th>
                                            <th>Job Title</th>
                                            <th>Applicants</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($opportunities)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3">No campus drives published yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($opportunities as $opp): ?>
                                                <tr>
                                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($opp['company_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($opp['job_title']); ?> <small class="text-muted d-block"><?php echo $opp['package_lpa']; ?> LPA</small></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary p-2"><?php echo $opp['total_applicants']; ?> Students</span>
                                                    </td>
                                                    <td>
                                                        <?php if ($opp['status'] === 'active'): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="?action=toggle&id=<?php echo $opp['opportunity_id']; ?>&status=<?php echo $opp['status']; ?>" class="btn btn-sm btn-outline-<?php echo $opp['status'] === 'active' ? 'warning' : 'success'; ?>">
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
