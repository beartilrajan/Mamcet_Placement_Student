<?php
// MAMCET Placement & Learning Portal - Job Descriptions Management
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Handle Delete request
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    try {
        $jobId = (int)$_GET['id'];
        
        $db->beginTransaction();
        $db->prepare("DELETE FROM job_description_departments WHERE job_id = ?")->execute([$jobId]);
        $db->prepare("DELETE FROM job_description_batches WHERE job_id = ?")->execute([$jobId]);
        $db->prepare("DELETE FROM job_description_skills WHERE job_id = ?")->execute([$jobId]);
        $db->prepare("DELETE FROM job_descriptions WHERE job_id = ?")->execute([$jobId]);
        $db->commit();
        
        $message = "Job Description deleted successfully.";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = "Failed to delete: " . $e->getMessage();
    }
}

// Fetch all Job Descriptions
require_once(__DIR__ . '/../services/JobSeederService.php');
JobSeederService::seedIfEmpty($db);
$stmt = $db->query("
    SELECT jd.*, s.session_name 
    FROM job_descriptions jd
    LEFT JOIN academic_sessions s ON jd.session_id = s.session_id
    ORDER BY jd.job_id DESC
");
$jobs = $stmt ? $stmt->fetchAll() : [];
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-file-contract text-primary"></i> Job Descriptions Database</h1>
                <a href="job-description-form.php" class="btn btn-primary font-weight-bold">
                    <i class="fa-solid fa-plus"></i> Create Job Description
                </a>
            </div>

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

            <div class="card shadow mb-4">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="jobDescTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Job Title</th>
                                    <th>Package</th>
                                    <th>Deadline</th>
                                    <th>CGPA Req.</th>
                                    <th>Max Arrears</th>
                                    <th>Session</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No job descriptions added yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $job): ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($job['company_name']); ?></td>
                                            <td><?php echo htmlspecialchars($job['job_title']); ?> <small class="text-muted d-block"><?php echo htmlspecialchars($job['job_role']); ?></small></td>
                                            <td><?php echo $job['package_lpa']; ?> LPA</td>
                                            <td>
                                                <?php 
                                                    $deadline = strtotime($job['application_deadline']);
                                                    $isExpired = $deadline < time();
                                                    echo '<span class="' . ($isExpired ? 'text-danger font-weight-bold' : '') . '">' . date('d M Y', $deadline) . '</span>';
                                                ?>
                                            </td>
                                            <td><?php echo $job['min_cgpa']; ?></td>
                                            <td><?php echo $job['max_standing_arrears']; ?></td>
                                            <td><?php echo htmlspecialchars($job['session_name']); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="job-description-form.php?id=<?php echo $job['job_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                    <a href="?action=delete&id=<?php echo $job['job_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this Job Description? This removes all mappings.')" title="Delete">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
                                                </div>
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
    if ($('#jobDescTable').find('tbody tr').length > 1) {
        $('#jobDescTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 10
        });
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
