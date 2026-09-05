<?php
// MAMCET Placement & Learning Portal - Student Job Matching & Suitability Reports
require_once(__DIR__ . '/../includes/header.php');

// Restrict to student only
if ($roleId !== ROLE_STUDENT) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

// Redirect - Job Match section removed for students
header("Location: " . $baseDir . "student/dashboard.php");
exit;

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$message = '';
$error = '';

// 1. Fetch current active resume text
$stmtResume = $db->prepare("
    SELECT rf.*, ret.extracted_text 
    FROM resume_files rf 
    JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1
");
$stmtResume->execute([$studentId]);
$currentResume = $stmtResume->fetch();

// 2. Fetch all published job opportunities
require_once(__DIR__ . '/../services/JobSeederService.php');
JobSeederService::seedIfEmpty($db);
$stmtJobs = $db->query("SELECT * FROM job_descriptions WHERE status = 'published' OR status IS NULL OR status = '' OR status = 'draft' ORDER BY job_id DESC");
$jobsList = $stmtJobs ? $stmtJobs->fetchAll() : [];

$matchReport = null;
$selectedJobId = 0;

// 3. Process Match Request
if (isset($_POST['match_job']) && $currentResume) {
    try {
        $selectedJobId = (int)$_POST['job_id'];
        
        // Fetch selected job
        $stmtSelectedJob = $db->prepare("SELECT * FROM job_descriptions WHERE job_id = ?");
        $stmtSelectedJob->execute([$selectedJobId]);
        $jobData = $stmtSelectedJob->fetch();
        
        if (!$jobData) {
            throw new Exception("Selected job description not found.");
        }

        // Verify student daily AI limits
        require_once(__DIR__ . '/../services/AIService.php');
        $limitCheck = AIService::checkStudentLimits($db, $studentId, 'job_matching');
        if (!$limitCheck['allowed']) {
            throw new Exception($limitCheck['reason']);
        }

        require_once(__DIR__ . '/../services/JobMatchService.php');
        
        // Execute Match comparison
        $matchReport = JobMatchService::match(
            $studentId,
            (int)$currentResume['resume_id'],
            $selectedJobId,
            $currentResume['extracted_text'],
            $jobData,
            $db
        );
        $db = Database::getInstance()->getConnection();

        $message = "Match report successfully generated!";
    } catch (Exception $e) {
        $error = $e->getMessage();
        $db = Database::getInstance()->getConnection();
    }
}

// Check current quota status for display
require_once(__DIR__ . '/../services/AIService.php');
$quotaInfo = AIService::checkStudentLimits($db, $studentId, 'job_matching');
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-code-compare text-primary"></i> Job Description Matching</h1>
                <?php echo AIService::renderQuotaBadge($quotaInfo); ?>
            </div>

            <!-- Quota Status Banner / Live Countdown Timer -->
            <?php echo AIService::renderQuotaBanner($quotaInfo); ?>

            <!-- Academic & Ethical Reminder Banner -->
            <div class="alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2 small shadow-sm border-0 border-start border-4 border-warning" role="alert">
                <i class="fa-solid fa-triangle-exclamation text-warning flex-shrink-0"></i>
                <div class="text-dark">
                    <strong>Ethical Reminder:</strong> Only list skills & experience you genuinely possess. Falsifying credentials violates placement policy and results in disqualification.
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($message); ?>
                    <div class="small mt-1 text-muted">
                        <i class="fa-solid fa-bolt text-warning me-1"></i> AI Quota Status: <strong><?php echo $quotaInfo['monthly_used']; ?> of <?php echo $quotaInfo['monthly_limit']; ?> calls used</strong> this month (<?php echo $quotaInfo['monthly_remaining']; ?> remaining). Next reset: in <?php echo $quotaInfo['days_until_reset']; ?> days (<?php echo $quotaInfo['reset_date_formatted']; ?>).
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$currentResume): ?>
                <div class="card shadow py-5 text-center text-muted">
                    <div class="card-body">
                        <i class="fa-solid fa-file-excel fa-3x mb-3"></i>
                        <h5>No Resume Configured</h5>
                        <p>Upload a current resume document first to enable job matching reports.</p>
                        <a href="resume.php" class="btn btn-primary mt-2">Go to My Resume</a>
                    </div>
                </div>
            <?php else: ?>
                
                <div class="card shadow mb-4">
                    <div class="card-header bg-dark text-white py-3">
                        <h6 class="m-0 font-weight-bold">Select Published Job Description</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" onsubmit="return <?php echo $quotaInfo['monthly_remaining'] > 0 ? "confirm('Notice: Comparing against this job uses 1 of your " . $quotaInfo['monthly_limit'] . " monthly AI API calls (Remaining: " . $quotaInfo['monthly_remaining'] . "). Proceed?')" : "false"; ?>;">
                            <div class="row align-items-end">
                                <div class="col-md-8 mb-3 mb-md-0">
                                    <label class="form-label font-weight-bold text-muted">Available Positions</label>
                                    <select class="form-select" name="job_id" required <?php echo $quotaInfo['monthly_remaining'] <= 0 ? 'disabled' : ''; ?>>
                                        <option value="" disabled selected>-- Select a Job Opportunity --</option>
                                        <?php foreach ($jobsList as $job): ?>
                                            <option value="<?php echo $job['job_id']; ?>" <?php echo $selectedJobId === (int)$job['job_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($job['company_name']); ?> - <?php echo htmlspecialchars($job['job_title']); ?> (Package: <?php echo $job['package_lpa']; ?> LPA)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" name="match_job" class="btn btn-primary btn-block py-2 font-weight-bold shadow-sm" <?php echo $quotaInfo['monthly_remaining'] <= 0 ? 'disabled title="Monthly AI request limit reached (3/3 used)."' : ''; ?>>
                                        <i class="fa-solid fa-arrows-spin"></i> Compare & Generate Score
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($matchReport): ?>
                    <!-- DISPLAY MATCH REPORT -->
                    <div class="row">
                        <!-- Score meter & basic suitability -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4 text-center">
                                <div class="card-header bg-primary text-white py-3">
                                    <h6 class="m-0 font-weight-bold">Match Scorecard</h6>
                                </div>
                                <div class="card-body py-5">
                                    <div class="gauge-score-wrap mb-4" style="width: 140px; height: 140px;">
                                        <div class="gauge-score-text" style="color: #1cc88a;">
                                            <span class="gauge-score-val"><?php echo $matchReport['match_score']; ?></span><span class="gauge-score-max" style="color:#aaa;">%</span>
                                        </div>
                                        <svg class="w-100 h-100" style="transform: rotate(-90deg);">
                                            <circle cx="70" cy="70" r="60" stroke="#f3f3f3" stroke-width="12" fill="transparent"/>
                                            <circle cx="70" cy="70" r="60" stroke="#1cc88a" stroke-width="12" fill="transparent"
                                                    stroke-dasharray="377" stroke-dashoffset="<?php echo 377 - (377 * $matchReport['match_score'] / 100); ?>"/>
                                        </svg>
                                    </div>
                                    <h5 class="font-weight-bold mt-2">Resume Fit Indicator</h5>
                                </div>
                            </div>

                            <!-- Structural relevance summary -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-light py-3">
                                    <h6 class="m-0 font-weight-bold text-dark">Suitability breakdown</h6>
                                </div>
                                <div class="card-body" style="font-size:0.85rem; line-height: 1.5;">
                                    <p><strong>Education:</strong> <?php echo htmlspecialchars($matchReport['education_match'] ?? 'Partial Match'); ?></p>
                                    <p><strong>Projects Relevance:</strong> <?php echo htmlspecialchars($matchReport['project_relevance'] ?? 'N/A'); ?></p>
                                    <p><strong>Internships:</strong> <?php echo htmlspecialchars($matchReport['internship_relevance'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Right details panel -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-dark text-white py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-ranking-star"></i> AI Fitment Comments</h6>
                                </div>
                                <div class="card-body">
                                    <p class="lead" style="font-size: 1.05rem;"><?php echo htmlspecialchars($matchReport['role_suitability'] ?? ''); ?></p>
                                </div>
                            </div>

                            <!-- Skills matching & missing lists -->
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow border-left-success h-100">
                                        <div class="card-body">
                                            <h6 class="font-weight-bold text-success"><i class="fa-solid fa-circle-check"></i> Matching Core Skills</h6>
                                            <div class="d-flex flex-wrap gap-1 mt-2">
                                                <?php foreach (($matchReport['matching_skills'] ?? []) as $sk): ?>
                                                    <span class="badge bg-success m-1 p-2 text-wrap text-start text-break fw-normal" style="font-size: 0.82rem; line-height: 1.4; max-width: 100%;"><?php echo htmlspecialchars($sk); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow border-left-danger h-100">
                                        <div class="card-body">
                                            <h6 class="font-weight-bold text-danger"><i class="fa-solid fa-circle-minus"></i> Missing Required Skills</h6>
                                            <div class="d-flex flex-wrap gap-1 mt-2">
                                                <?php foreach (($matchReport['missing_skills'] ?? []) as $sk): ?>
                                                    <span class="badge bg-danger m-1 p-2 text-wrap text-start text-break fw-normal" style="font-size: 0.82rem; line-height: 1.4; max-width: 100%;"><?php echo htmlspecialchars($sk); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modification actions -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-warning text-dark py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-lightbulb"></i> Recommended Resume Modifications</h6>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush" style="font-size: 0.9rem;">
                                        <?php foreach (($matchReport['suggested_modifications'] ?? []) as $mod): ?>
                                            <li class="list-group-item d-flex align-items-start py-3 px-4" style="line-height: 1.5; word-break: break-word; overflow-wrap: anywhere;">
                                                <i class="fa-solid fa-circle-chevron-right text-warning me-3 mt-1 flex-shrink-0"></i>
                                                <div class="text-dark"><?php echo htmlspecialchars($mod); ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>

                            <!-- Learning modules recommendations -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-info text-white py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-graduation-cap"></i> Recommended Study & Course Topics</h6>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush" style="font-size: 0.9rem;">
                                        <?php foreach (($matchReport['recommended_learning'] ?? []) as $learn): ?>
                                            <li class="list-group-item d-flex align-items-start py-3 px-4" style="line-height: 1.5; word-break: break-word; overflow-wrap: anywhere;">
                                                <i class="fa-solid fa-book-open text-info me-3 mt-1 flex-shrink-0"></i>
                                                <div class="text-dark"><?php echo htmlspecialchars($learn); ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>

                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
