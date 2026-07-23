<?php
// MAMCET Placement & Learning Portal - Student Job Applications and Eligibility
require_once(__DIR__ . '/../includes/header.php');

// Restrict to student only
if ($roleId !== ROLE_STUDENT) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$message = '';
$error = '';

// 1. Process application registration POST
if (isset($_POST['apply_opportunity'])) {
    try {
        $oppId = (int)$_POST['opportunity_id'];
        $confirmCode = trim($_POST['registration_confirmation'] ?? '');

        // Fetch opportunity & job details
        $stmtOpp = $db->prepare("SELECT job_id, session_id FROM placement_opportunities WHERE opportunity_id = ? AND status = 'active'");
        $stmtOpp->execute([$oppId]);
        $opp = $stmtOpp->fetch();
        if (!$opp) {
            throw new Exception("Opportunity not found or inactive.");
        }

        // Run automated eligibility engine to block ineligible applications
        require_once(__DIR__ . '/../services/EligibilityService.php');
        $eligData = EligibilityService::calculateStudentEligibility($db, $studentId, (int)$opp['job_id']);
        
        if ($eligData['status'] === 'Not Eligible' || $eligData['status'] === 'Data Incomplete') {
            throw new Exception("Access Denied: You are not eligible for this placement drive.");
        }

        // Insert application record
        $stmtIns = $db->prepare("
            INSERT INTO placement_applications (student_id, opportunity_id, stage, is_eligible, registration_confirmation, application_date) 
            VALUES (?, ?, 'Applied', 1, ?, CURDATE())
            ON DUPLICATE KEY UPDATE registration_confirmation = VALUES(registration_confirmation), updated_at = NOW()
        ");
        $stmtIns->execute([$studentId, $oppId, $confirmCode]);

        $message = "Application submitted successfully! Tracking is now active.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 2. Fetch all student applications
$stmtApps = $db->prepare("
    SELECT pa.*, po.job_id, jd.company_name, jd.job_title, jd.job_role, jd.package_lpa, jd.google_form_url
    FROM placement_applications pa
    JOIN placement_opportunities po ON pa.opportunity_id = po.opportunity_id
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    WHERE pa.student_id = ?
    ORDER BY pa.application_id DESC
");
$stmtApps->execute([$studentId]);
$appliedJobs = $stmtApps->fetchAll();

// 3. Fetch all active published drives (opportunities)
$stmtOpps = $db->query("
    SELECT po.*, jd.company_name, jd.job_title, jd.job_role, jd.package_lpa, jd.application_deadline, jd.google_form_url
    FROM placement_opportunities po
    JOIN job_descriptions jd ON po.job_id = jd.job_id
    WHERE po.status = 'active' AND jd.application_deadline >= CURDATE()
    ORDER BY jd.application_deadline ASC
");
$opportunities = $stmtOpps->fetchAll();

// Query eligibility service helper
require_once(__DIR__ . '/../services/EligibilityService.php');
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-briefcase text-primary"></i> Job Openings & Application Center</h1>

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

            <ul class="nav nav-tabs mb-4" id="applicationsTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="open-tab" data-bs-toggle="tab" data-bs-target="#open-drives" type="button"><i class="fa-solid fa-folder-open"></i> Active Campus Drives</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="applied-tab" data-bs-toggle="tab" data-bs-target="#applied-drives" type="button"><i class="fa-solid fa-clock-rotate-left"></i> My Applications (<?php echo count($appliedJobs); ?>)</button>
                </li>
            </ul>

            <div class="tab-content" id="applicationsTabContent">
                <!-- ACTIVE CAMPUS DRIVES -->
                <div class="tab-pane fade show active" id="open-drives">
                    <div class="row">
                        <?php if (empty($opportunities)): ?>
                            <div class="col-12 py-5 text-center text-muted">
                                <i class="fa-solid fa-circle-nodes fa-3x mb-3"></i>
                                <h5>No active campus drives published at this moment.</h5>
                            </div>
                        <?php else: ?>
                            <?php 
                            foreach ($opportunities as $opp): 
                                // Check if already applied
                                $alreadyApplied = false;
                                foreach ($appliedJobs as $app) {
                                    if ($app['opportunity_id'] == $opp['opportunity_id']) {
                                        $alreadyApplied = true;
                                        break;
                                    }
                                }

                                // Run dynamic eligibility
                                $eligData = EligibilityService::calculateStudentEligibility($db, $studentId, (int)$opp['job_id']);
                            ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow h-100 border-left-<?php echo $eligData['is_eligible'] ? 'success' : 'danger'; ?>">
                                        <div class="card-body">
                                            <h5 class="font-weight-bold text-dark mb-1"><?php echo htmlspecialchars($opp['company_name']); ?></h5>
                                            <h6 class="text-primary"><?php echo htmlspecialchars($opp['job_title']); ?> <small class="text-muted">(<?php echo htmlspecialchars($opp['job_role']); ?>)</small></h6>
                                            
                                            <div class="row my-3" style="font-size:0.85rem;">
                                                <div class="col-6">
                                                    <strong>Package:</strong> <?php echo $opp['package_lpa']; ?> LPA
                                                </div>
                                                <div class="col-6 text-end">
                                                    <strong>Deadline:</strong> <?php echo date('d M Y', strtotime($opp['application_deadline'])); ?>
                                                </div>
                                            </div>

                                            <hr>

                                            <!-- Eligibility Info -->
                                            <div class="mb-3">
                                                <strong>Eligibility:</strong> 
                                                <?php if ($eligData['status'] === 'Eligible'): ?>
                                                    <span class="badge bg-success">Eligible</span>
                                                <?php elseif ($eligData['status'] === 'Officer Override'): ?>
                                                    <span class="badge bg-primary">Officer Override (Eligible)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Not Eligible</span>
                                                    <ul class="text-danger mt-2 pl-3" style="font-size:0.8rem; line-height: 1.4;">
                                                        <?php foreach ($eligData['reasons'] as $r): ?>
                                                            <li><?php echo htmlspecialchars($r); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Action controls -->
                                            <?php if ($alreadyApplied): ?>
                                                <div class="alert alert-secondary text-center mb-0 py-2">
                                                    <i class="fa-solid fa-circle-check text-success"></i> Registered & Applied
                                                </div>
                                            <?php elseif ($eligData['is_eligible'] == 1 || $eligData['status'] === 'Officer Override'): ?>
                                                <div class="bg-light p-3 rounded border">
                                                    <small class="text-muted d-block mb-2"><i class="fa-solid fa-arrow-up-right-from-square"></i> Step 1: Fill the company's Google Registration Form.</small>
                                                    <?php if (!empty($opp['google_form_url'])): ?>
                                                        <a href="<?php echo htmlspecialchars($opp['google_form_url']); ?>" target="_blank" class="btn btn-sm btn-outline-info btn-block mb-3 font-weight-bold">
                                                            Open Registration Form <i class="fa-solid fa-chevron-right"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                    <form method="POST">
                                                        <input type="hidden" name="opportunity_id" value="<?php echo $opp['opportunity_id']; ?>">
                                                        <div class="mb-2">
                                                            <input type="text" name="registration_confirmation" class="form-control form-control-sm" placeholder="Paste registration confirmation code/ID" required>
                                                        </div>
                                                        <button type="submit" name="apply_opportunity" class="btn btn-sm btn-success btn-block font-weight-bold">
                                                            Mark as Registered (Apply)
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <button class="btn btn-block btn-secondary btn-sm" disabled>Application Locked</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- MY APPLICATIONS TIMELINES -->
                <div class="tab-pane fade" id="applied-drives">
                    <div class="row">
                        <?php if (empty($appliedJobs)): ?>
                            <div class="col-12 py-5 text-center text-muted">
                                <i class="fa-solid fa-clock-rotate-left fa-3x mb-3"></i>
                                <h5>You haven't submitted any job registrations yet.</h5>
                            </div>
                        <?php else: ?>
                            <?php foreach ($appliedJobs as $app): ?>
                                <div class="col-12 mb-4">
                                    <div class="card shadow">
                                        <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                                            <h6 class="m-0 font-weight-bold text-dark">
                                                <?php echo htmlspecialchars($app['company_name']); ?> | 
                                                <small class="text-primary"><?php echo htmlspecialchars($app['job_title']); ?></small>
                                            </h6>
                                            <span class="badge bg-primary p-2">Stage: <?php echo htmlspecialchars($app['stage']); ?></span>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <p class="mb-1"><strong>Applied Date:</strong> <?php echo date('d M Y', strtotime($app['application_date'])); ?></p>
                                                    <p class="mb-1"><strong>Confirmation Code:</strong> <code><?php echo htmlspecialchars($app['registration_confirmation']); ?></code></p>
                                                    <?php if (!empty($app['officer_notes'])): ?>
                                                        <div class="p-3 bg-light rounded mt-3" style="font-size:0.85rem; border-left:4px solid #4e73df;">
                                                            <strong>Officer Remarks:</strong><br>
                                                            <?php echo htmlspecialchars($app['officer_notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="col-md-8">
                                                    <!-- Tracking Workflow timeline -->
                                                    <div class="d-flex justify-content-between mt-3 position-relative" style="font-size:0.75rem;">
                                                        <div style="position: absolute; top:12px; left:20px; right:20px; height:3px; background-color:#e3e6f0; z-index: 1;"></div>
                                                        
                                                        <div class="text-center z-index-2" style="z-index: 2; width:80px;">
                                                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto mb-2" style="width:28px; height:28px;">
                                                                <i class="fa-solid fa-check"></i>
                                                            </div>
                                                            Registered
                                                        </div>
                                                        
                                                        <div class="text-center" style="z-index: 2; width:80px;">
                                                            <div class="rounded-circle <?php echo in_array($app['stage'], ['Aptitude Test', 'Coding Test', 'Group Discussion', 'Technical Interview', 'HR Interview', 'Selected']) ? 'bg-success text-white' : 'bg-light text-muted'; ?> d-flex align-items-center justify-content-center mx-auto mb-2" style="width:28px; height:28px;">
                                                                <i class="fa-solid fa-spinner"></i>
                                                            </div>
                                                            Written Tests
                                                        </div>

                                                        <div class="text-center" style="z-index: 2; width:80px;">
                                                            <div class="rounded-circle <?php echo in_array($app['stage'], ['Technical Interview', 'HR Interview', 'Selected']) ? 'bg-success text-white' : 'bg-light text-muted'; ?> d-flex align-items-center justify-content-center mx-auto mb-2" style="width:28px; height:28px;">
                                                                <i class="fa-solid fa-people-arrows"></i>
                                                            </div>
                                                            Interviews
                                                        </div>

                                                        <div class="text-center" style="z-index: 2; width:80px;">
                                                            <div class="rounded-circle <?php echo $app['stage'] === 'Selected' ? 'bg-success text-white' : 'bg-light text-muted'; ?> d-flex align-items-center justify-content-center mx-auto mb-2" style="width:28px; height:28px;">
                                                                <i class="fa-solid fa-trophy"></i>
                                                            </div>
                                                            Selected
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
