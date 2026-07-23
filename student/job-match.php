<?php
// MAMCET Placement & Learning Portal - Student Job Matching & Suitability Reports
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
$stmtJobs = $db->query("SELECT * FROM job_descriptions WHERE status = 'published' ORDER BY created_at DESC");
$jobsList = $stmtJobs->fetchAll();

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

        $message = "Match report successfully generated!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-code-compare text-primary"></i> Job Description Matching</h1>

            <!-- CRITICAL WARNING BANNER (COMPULSORY CRITERIA) -->
            <div class="alert alert-warning border shadow-sm mb-4" role="alert" style="border-left: 5px solid #ffc107 !important;">
                <h6 class="font-weight-bold text-dark"><i class="fa-solid fa-triangle-exclamation"></i> Academic & Ethical Reminder</h6>
                <p class="mb-0" style="font-size:0.9rem;"><strong>“Only add skills, projects and experience that you genuinely possess.”</strong> Falsifying profile credentials violates placement cell policies and will result in disqualification from current and future drives.</p>
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
                        <form method="POST">
                            <div class="row align-items-end">
                                <div class="col-md-8 mb-3 mb-md-0">
                                    <label class="form-label font-weight-bold text-muted">Available Positions</label>
                                    <select class="form-select" name="job_id" required>
                                        <option value="" disabled selected>-- Select a Job Opportunity --</option>
                                        <?php foreach ($jobsList as $job): ?>
                                            <option value="<?php echo $job['job_id']; ?>" <?php echo $selectedJobId === (int)$job['job_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($job['company_name']); ?> - <?php echo htmlspecialchars($job['job_title']); ?> (Package: <?php echo $job['package_lpa']; ?> LPA)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" name="match_job" class="btn btn-primary btn-block py-2 font-weight-bold shadow-sm">
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
                                    <div class="d-inline-flex position-relative mb-4 align-items-center justify-content-center" style="width: 140px; height: 140px;">
                                        <div class="position-absolute" style="font-size: 2.2rem; font-weight: 800; color: #1cc88a;">
                                            <?php echo $matchReport['match_score']; ?><small style="font-size:0.9rem; font-weight:400; color:#aaa;">%</small>
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
                                                    <span class="badge bg-success m-1 p-2"><?php echo htmlspecialchars($sk); ?></span>
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
                                                    <span class="badge bg-danger m-1 p-2"><?php echo htmlspecialchars($sk); ?></span>
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
                                <div class="card-body">
                                    <ul class="list-group list-group-flush" style="font-size: 0.9rem;">
                                        <?php foreach (($matchReport['suggested_modifications'] ?? []) as $mod): ?>
                                            <li class="list-group-item d-flex align-items-center py-2">
                                                <i class="fa-solid fa-circle-chevron-right text-warning me-3"></i>
                                                <?php echo htmlspecialchars($mod); ?>
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
                                <div class="card-body">
                                    <ul class="list-group list-group-flush" style="font-size: 0.9rem;">
                                        <?php foreach (($matchReport['recommended_learning'] ?? []) as $learn): ?>
                                            <li class="list-group-item d-flex align-items-center py-2">
                                                <i class="fa-solid fa-book-open text-info me-3"></i>
                                                <?php echo htmlspecialchars($learn); ?>
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
