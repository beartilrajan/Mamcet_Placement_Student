<?php
// MAMCET Placement & Learning Portal - Officer Application Review & Stage Updater
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$appId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$appId) {
    header("Location: applications.php");
    exit;
}

// Fetch detailed application record
$stmtApp = $db->prepare("
    SELECT pa.*, po.job_id, po.opportunity_id,
           s.student_id, s.student_name, s.register_number, s.email AS student_email, s.mobile_number,
           sa.current_cgpa, sa.standing_arrears, sa.history_arrears,
           d.dept_name, d.dept_code, b.batch_name,
           jd.company_name, jd.job_title, jd.job_role, jd.package_lpa AS jd_package
    FROM placement_applications pa
    JOIN students s ON pa.student_id = s.student_id
    JOIN student_academics sa ON s.student_id = sa.student_id
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    JOIN placement_opportunities po ON pa.opportunity_id = po.opportunity_id
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    WHERE pa.application_id = ?
");
$stmtApp->execute([$appId]);
$app = $stmtApp->fetch();

if (!$app) {
    header("Location: applications.php");
    exit;
}

$studentId = (int)$app['student_id'];

// Load active resume & ATS Compatibility score
$stmtRes = $db->prepare("SELECT rf.resume_id, rf.resume_path, ra.overall_score FROM resume_files rf LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1");
$stmtRes->execute([$studentId]);
$resume = $stmtRes->fetch();

// Load placements record if selected
$stmtPlac = $db->prepare("SELECT * FROM student_placements WHERE student_id = ? AND company_name = ? LIMIT 1");
$stmtPlac->execute([$studentId, $app['company_name']]);
$placementInfo = $stmtPlac->fetch();

// Process Stage Update form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stage'])) {
    try {
        $db->beginTransaction();

        $newStage = trim($_POST['stage']);
        $notes = trim($_POST['officer_notes'] ?? '');

        // Update application stage & remarks
        $stmtUpd = $db->prepare("UPDATE placement_applications SET stage = ?, officer_notes = ?, updated_at = NOW() WHERE application_id = ?");
        $stmtUpd->execute([$newStage, $notes, $appId]);

        // Auto Sync with Stage 1 Placements table if selected
        if ($newStage === 'Selected' || $newStage === 'Offer Received') {
            $packageLpa = (float)($_POST['placed_package'] ?? $app['jd_package']);
            $placedDate = !empty($_POST['placed_date']) ? $_POST['placed_date'] : date('Y-m-d');
            
            // Insert or update placement record
            $stmtSync = $db->prepare("
                INSERT INTO student_placements (student_id, company_name, package_lpa, placed_date, notes) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE package_lpa = VALUES(package_lpa), placed_date = VALUES(placed_date), notes = VALUES(notes)
            ");
            $stmtSync->execute([$studentId, $app['company_name'], $packageLpa, $placedDate, $notes]);
        }

        $db->commit();
        $message = "Application stage updated successfully.";
        
        // Reload details
        $stmtApp->execute([$appId]);
        $app = $stmtApp->fetch();
        $stmtPlac->execute([$studentId, $app['company_name']]);
        $placementInfo = $stmtPlac->fetch();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-user-check text-primary"></i> Review Application</h1>
                <a href="applications.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-chevron-left"></i> Roster Log</a>
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

            <div class="row">
                <!-- Left: Candidate Profile summary & metrics -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-dark text-white py-3">
                            <h6 class="m-0 font-weight-bold">Candidate Registry & Academics</h6>
                        </div>
                        <div class="card-body" style="font-size:0.9rem; line-height: 1.6;">
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Student Name:</strong>
                                <span><?php echo htmlspecialchars($app['student_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Register Number:</strong>
                                <span><code><?php echo htmlspecialchars($app['register_number']); ?></code></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Branch / Department:</strong>
                                <span><?php echo htmlspecialchars($app['dept_code']); ?> - <?php echo htmlspecialchars($app['dept_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Graduation Batch:</strong>
                                <span>Batch <?php echo htmlspecialchars($app['batch_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Email / Phone:</strong>
                                <span><?php echo htmlspecialchars($app['student_email']); ?> / <?php echo htmlspecialchars($app['mobile_number']); ?></span>
                            </div>
                            
                            <hr>
                            
                            <h6 class="font-weight-bold text-primary mb-3">Academic Performance Metrics</h6>
                            <div class="row text-center mb-3">
                                <div class="col-4">
                                    <h5 class="font-weight-bold text-dark mb-0"><?php echo $app['current_cgpa']; ?></h5>
                                    <small class="text-muted">CGPA</small>
                                </div>
                                <div class="col-4">
                                    <h5 class="font-weight-bold text-danger mb-0"><?php echo $app['standing_arrears']; ?></h5>
                                    <small class="text-muted">Standing Arrears</small>
                                </div>
                                <div class="col-4">
                                    <h5 class="font-weight-bold text-secondary mb-0"><?php echo $app['history_arrears']; ?></h5>
                                    <small class="text-muted">History Arrears</small>
                                </div>
                            </div>
                            
                            <hr>

                            <h6 class="font-weight-bold text-primary mb-2">Resume & Compatibility</h6>
                            <?php if ($resume): ?>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded border">
                                    <div>
                                        <i class="fa-solid fa-file-pdf fa-2x text-danger me-2"></i>
                                        <strong>ATS Score:</strong> 
                                        <span class="badge bg-<?php echo $resume['overall_score'] >= 70 ? 'success' : 'warning'; ?> p-2">
                                            <?php echo $resume['overall_score']; ?> / 100
                                        </span>
                                    </div>
                                    <a href="<?php echo $baseDir . $resume['resume_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-download"></i> Download Resume
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning text-center py-2 mb-0">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Candidate hasn't uploaded a current resume.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Workflow tracker & updater forms -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="m-0 font-weight-bold">Modify Application Progress Stage</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label font-weight-bold text-muted">Recruitment Stage</label>
                                    <select class="form-select font-weight-bold" name="stage" id="stageSelector" required>
                                        <option value="Applied" <?php echo $app['stage'] === 'Applied' ? 'selected' : ''; ?>>Applied / Registered</option>
                                        <option value="Aptitude Test" <?php echo $app['stage'] === 'Aptitude Test' ? 'selected' : ''; ?>>Aptitude Test</option>
                                        <option value="Coding Test" <?php echo $app['stage'] === 'Coding Test' ? 'selected' : ''; ?>>Coding Test</option>
                                        <option value="Group Discussion" <?php echo $app['stage'] === 'Group Discussion' ? 'selected' : ''; ?>>Group Discussion</option>
                                        <option value="Technical Interview" <?php echo $app['stage'] === 'Technical Interview' ? 'selected' : ''; ?>>Technical Interview</option>
                                        <option value="HR Interview" <?php echo $app['stage'] === 'HR Interview' ? 'selected' : ''; ?>>HR Interview</option>
                                        <option value="Selected" <?php echo $app['stage'] === 'Selected' ? 'selected' : ''; ?>>Selected / Hired</option>
                                        <option value="Rejected" <?php echo $app['stage'] === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="Offer Received" <?php echo $app['stage'] === 'Offer Received' ? 'selected' : ''; ?>>Offer Letter Issued</option>
                                    </select>
                                </div>

                                <!-- Dynamic Hired Details section -->
                                <div class="p-3 bg-light rounded border mb-3 d-none" id="hiredSection">
                                    <h6 class="font-weight-bold text-success mb-3"><i class="fa-solid fa-trophy"></i> Placement Credentials (Stage 1 Sync)</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label font-weight-bold">Offered Package (LPA)</label>
                                            <input type="number" step="0.01" name="placed_package" class="form-control form-control-sm" value="<?php echo $placementInfo ? $placementInfo['package_lpa'] : $app['jd_package']; ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label font-weight-bold">Placement Date</label>
                                            <input type="date" name="placed_date" class="form-control form-control-sm" value="<?php echo $placementInfo ? $placementInfo['placed_date'] : date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label font-weight-bold text-muted">Officer Evaluation Notes</label>
                                    <textarea class="form-control" name="officer_notes" rows="4" placeholder="Input evaluation comments or drive progress notes..."><?php echo htmlspecialchars($app['officer_notes'] ?? ''); ?></textarea>
                                </div>

                                <button type="submit" name="update_stage" class="btn btn-primary w-100 font-weight-bold py-2">
                                    <i class="fa-solid fa-cloud-arrow-up"></i> Update Recruitment Stage
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function toggleHiredSection() {
        const val = $('#stageSelector').val();
        if (val === 'Selected' || val === 'Offer Received') {
            $('#hiredSection').removeClass('d-none');
        } else {
            $('#hiredSection').addClass('d-none');
        }
    }

    $('#stageSelector').on('change', toggleHiredSection);
    toggleHiredSection(); // Run on startup
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
