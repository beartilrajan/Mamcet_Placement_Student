<?php
// MAMCET Placement & Learning Portal - ATS Resume Analysis Dashboard
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

// Query active resume details
$stmtCur = $db->prepare("
    SELECT rf.*, ret.extracted_text, ret.text_hash 
    FROM resume_files rf 
    JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1
");
$stmtCur->execute([$studentId]);
$currentResume = $stmtCur->fetch();

$latestAnalysis = null;
$sectionScores = [];

if ($currentResume) {
    $resumeId = (int)$currentResume['resume_id'];
    
    // Check if we have an existing analysis for this resume version
    $stmtAn = $db->prepare("SELECT * FROM resume_analysis WHERE resume_id = ? ORDER BY analyzed_at DESC LIMIT 1");
    $stmtAn->execute([$resumeId]);
    $latestAnalysis = $stmtAn->fetch();

    if ($latestAnalysis) {
        // Fetch section scores
        $stmtSec = $db->prepare("SELECT * FROM resume_analysis_sections WHERE analysis_id = ?");
        $stmtSec->execute([$latestAnalysis['analysis_id']]);
        $sectionScores = $stmtSec->fetchAll();
    }
}

require_once(__DIR__ . '/../services/JobSeederService.php');
JobSeederService::seedIfEmpty($db);
$stmtJobs = $db->query("SELECT job_id, company_name, job_title FROM job_descriptions WHERE status = 'published' OR status IS NULL OR status = '' OR status = 'draft' ORDER BY job_id DESC");
$availableJobs = $stmtJobs ? $stmtJobs->fetchAll() : [];

// Handle trigger analysis POST
if (isset($_POST['trigger_analysis']) && $currentResume) {
    try {
        if (!class_exists('AIService')) {
            $aiServicePaths = [
                __DIR__ . '/../services/AIService.php',
                dirname(__DIR__) . '/services/AIService.php',
                realpath(__DIR__ . '/../services/AIService.php')
            ];
            foreach ($aiServicePaths as $path) {
                if ($path && file_exists($path)) {
                    require_once($path);
                    break;
                }
            }
            if (!class_exists('AIService')) {
                @include_once(__DIR__ . '/../services/AIService.php');
            }
            if (!class_exists('AIService')) {
                throw new Exception("Service file 'AIService.php' is missing from the services/ directory on your server. Please upload the 'services/' folder to your server.");
            }
        }

        if (!class_exists('ATSScoringService')) {
            $atsServicePaths = [
                __DIR__ . '/../services/ATSScoringService.php',
                dirname(__DIR__) . '/services/ATSScoringService.php',
                realpath(__DIR__ . '/../services/ATSScoringService.php')
            ];
            foreach ($atsServicePaths as $path) {
                if ($path && file_exists($path)) {
                    require_once($path);
                    break;
                }
            }
            if (!class_exists('ATSScoringService')) {
                @include_once(__DIR__ . '/../services/ATSScoringService.php');
            }
            if (!class_exists('ATSScoringService')) {
                throw new Exception("Service file 'ATSScoringService.php' is missing from the services/ directory on your server. Please upload the 'services/' folder to your server.");
            }
        }

        $resumeId = (int)$currentResume['resume_id'];
        $text = $currentResume['extracted_text'];

        if (empty($text)) {
            throw new Exception("Your resume does not contain any extractable text yet. Please go to 'My Resume' and verify text first.");
        }

        // 1. Verify daily and monthly API quotas for ATS Resume Scan
        $limitCheck = AIService::checkStudentLimits($db, $studentId, 'ats');
        if (!$limitCheck['allowed']) {
            throw new Exception($limitCheck['reason']);
        }

        // 2. Determine target evaluation mode (General by default)
        $targetJobId = isset($_POST['target_job_id']) ? (int)$_POST['target_job_id'] : 0;
        $jdText = null;
        if ($targetJobId > 0) {
            $stmtJd = $db->prepare("SELECT * FROM job_descriptions WHERE job_id = ?");
            $stmtJd->execute([$targetJobId]);
            $jd = $stmtJd->fetch();
            if ($jd) {
                $jdText = "Company: " . ($jd['company_name'] ?? '') . "\nRole: " . ($jd['job_title'] ?? '') . "\nRequired Skills: " . ($jd['required_skills'] ?? '') . "\nSummary: " . ($jd['job_summary'] ?? '') . "\nResponsibilities: " . ($jd['responsibilities'] ?? '');
            }
        }

        // 3. Perform Hybrid ATS Compatibility Scoring
        $analysisResults = ATSScoringService::analyze($text, $jdText, $studentId, $db);

        // Refresh DB connection after external AI request
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
        }

        // 3. Save to database
        $db->beginTransaction();

        // Remove old analysis if any to save storage space
        $db->prepare("DELETE FROM resume_analysis WHERE resume_id = ?")->execute([$resumeId]);

        $stmtIns = $db->prepare("
            INSERT INTO resume_analysis (
                resume_id, overall_score, rule_score, ai_score, assessment_text, 
                strengths, critical_issues, missing_sections, missing_keywords, 
                grammar_suggestions, action_verb_suggestions, priority_actions, analyzed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtIns->execute([
            $resumeId,
            (int)$analysisResults['overall_score'],
            (int)$analysisResults['rule_score'],
            (int)$analysisResults['ai_score'],
            $analysisResults['assessment_text'],
            $analysisResults['strengths'],
            $analysisResults['critical_issues'],
            $analysisResults['missing_sections'],
            $analysisResults['missing_keywords'],
            $analysisResults['grammar_suggestions'],
            $analysisResults['action_verb_suggestions'],
            $analysisResults['priority_actions']
        ]);
        $analysisId = (int)$db->lastInsertId();

        // Insert section-wise scores
        $stmtSecIns = $db->prepare("INSERT INTO resume_analysis_sections (analysis_id, section_name, score, max_score, feedback) VALUES (?, ?, ?, ?, ?)");
        foreach ($analysisResults['section_scores'] as $secName => $scoreDetails) {
            $stmtSecIns->execute([
                $analysisId,
                $secName,
                (int)$scoreDetails['score'],
                (int)$scoreDetails['max'],
                ''
            ]);
        }

        // Update resume file status
        $db->prepare("UPDATE resume_files SET last_analyzed_at = NOW(), status = 'analyzed' WHERE resume_id = ?")->execute([$resumeId]);

        $db->commit();
        
        // Refresh variables
        $stmtAn = $db->prepare("SELECT * FROM resume_analysis WHERE analysis_id = ?");
        $stmtAn->execute([$analysisId]);
        $latestAnalysis = $stmtAn->fetch();

        $stmtSec = $db->prepare("SELECT * FROM resume_analysis_sections WHERE analysis_id = ?");
        $stmtSec->execute([$analysisId]);
        $sectionScores = $stmtSec->fetchAll();

        $message = !empty($jdText) ? "Contextual Job Match & ATS Analysis completed successfully!" : "ATS Compatibility Analysis completed successfully!";
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        $db = Database::getInstance()->getConnection();
    }
}

// Determine rating tags based on scores
$scoreTag = 'Needs Significant Improvement';
$scoreColor = 'danger';
if ($latestAnalysis) {
    $score = $latestAnalysis['overall_score'];
    if ($score >= 90) {
        $scoreTag = 'Excellent';
        $scoreColor = 'success';
    } elseif ($score >= 75) {
        $scoreTag = 'Very Good';
        $scoreColor = 'info';
    } elseif ($score >= 60) {
        $scoreTag = 'Good';
        $scoreColor = 'primary';
    } elseif ($score >= 40) {
        $scoreTag = 'Basic';
        $scoreColor = 'warning';
    }
}

// Student AI Quota status for ATS Resume Analysis
require_once(__DIR__ . '/../services/AIService.php');
$quotaInfo = AIService::checkStudentLimits($db, $studentId, 'ats');
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                <div>
                    <h1 class="h3 mb-1 text-gray-800"><i class="fa-solid fa-gauge-high text-info"></i> ATS Resume Score Estimator</h1>
                    <div class="mt-1"><?php echo AIService::renderQuotaBadge($quotaInfo); ?></div>
                </div>
                <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-md-auto">
                    <?php if ($currentResume): ?>
                        <form method="POST" class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-sm-auto mb-0" onsubmit="return <?php echo $quotaInfo['monthly_remaining'] > 0 ? "confirm('Notice: Running an ATS scan uses 1 of your " . $quotaInfo['monthly_limit'] . " monthly AI API calls (Remaining: " . $quotaInfo['monthly_remaining'] . "). Continue?')" : "false"; ?>;">
                            <select class="form-select form-select-sm flex-grow-1" name="target_job_id" style="min-width: 0; font-size: 0.85rem;" title="Select ATS Scoring Standard" <?php echo $quotaInfo['monthly_remaining'] <= 0 ? 'disabled' : ''; ?>>
                                <option value="0" selected>⚡ General ATS Standard (Default)</option>
                                <?php if (!empty($availableJobs)): ?>
                                    <optgroup label="Or Target Specific Job Drive">
                                        <?php foreach ($availableJobs as $job): ?>
                                            <option value="<?php echo $job['job_id']; ?>">
                                                <?php echo htmlspecialchars($job['company_name'] . ' - ' . $job['job_title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                            <button type="submit" name="trigger_analysis" class="btn btn-primary btn-sm font-weight-bold shadow-sm text-nowrap" <?php echo $quotaInfo['monthly_remaining'] <= 0 ? 'disabled title="Monthly AI request limit reached (' . $quotaInfo['monthly_limit'] . '/' . $quotaInfo['monthly_limit'] . ' used)."' : ''; ?>>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Run ATS Scan
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($latestAnalysis): ?>
                        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm d-print-none text-nowrap">
                            <i class="fa-solid fa-print me-1"></i> Print Report
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quota Status Banner / Live Countdown Timer -->
            <?php echo AIService::renderQuotaBanner($quotaInfo); ?>

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

            <!-- Disclaimer Notice -->
            <div class="alert alert-info bg-light-subtle py-2 px-3 mb-3 d-flex align-items-center gap-2 small shadow-sm border-0 border-start border-4 border-info" role="alert">
                <i class="fa-solid fa-circle-info text-info flex-shrink-0"></i>
                <div class="text-secondary">
                    <strong class="text-dark">Disclaimer:</strong> Estimated score for preparation only — not an official employer or ATS rating.
                </div>
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
                        <h5>No Resume Registered</h5>
                        <p>You must upload an active resume document first to enable ATS scores.</p>
                        <a href="resume.php" class="btn btn-primary mt-2">Go to My Resume</a>
                    </div>
                </div>
            <?php else: ?>
                
                <?php if (!$latestAnalysis): ?>
                    <div class="card shadow py-5 text-center text-muted mb-4 border-0">
                        <div class="card-body">
                            <i class="fa-solid fa-laptop-code fa-3x mb-3 text-info"></i>
                            <h5 class="text-dark font-weight-bold">Resume Scan Ready</h5>
                            <p class="mb-3">Your extracted resume text is ready for full AI ATS evaluation and qualitative scoring.</p>
                            <form method="POST" class="d-inline-block text-start" style="max-width: 420px; width: 100%;">
                                <div class="mb-3">
                                    <label class="form-label small font-weight-bold text-muted">Target Evaluation:</label>
                                    <select class="form-select" name="target_job_id">
                                        <option value="0" selected>⚡ General ATS Standard (Default)</option>
                                        <?php if (!empty($availableJobs)): ?>
                                            <optgroup label="Target Specific Job Role">
                                                <?php foreach ($availableJobs as $job): ?>
                                                    <option value="<?php echo $job['job_id']; ?>">
                                                        <?php echo htmlspecialchars($job['company_name'] . ' - ' . $job['job_title']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <button type="submit" name="trigger_analysis" class="btn btn-primary btn-lg font-weight-bold shadow-sm w-100">
                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Run Full ATS Scan Now
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    
                    <!-- DISPLAY ATS REPORT -->
                    <div class="row">
                        <!-- Left: Circular gauge score & section scores -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4 text-center">
                                <div class="card-header bg-dark text-white py-3">
                                    <h6 class="m-0 font-weight-bold">ATS Scorecard Summary</h6>
                                </div>
                                <div class="card-body py-3 py-md-4">
                                    <!-- Circular Progress Gauge -->
                                    <div class="gauge-score-wrap mb-3" style="width: 140px; height: 140px;">
                                        <div class="gauge-score-text" style="color: #06b6d4;">
                                            <span class="gauge-score-val"><?php echo $latestAnalysis['overall_score']; ?></span><span class="gauge-score-max">/100</span>
                                        </div>
                                        <svg class="w-100 h-100" viewBox="0 0 150 150" style="transform: rotate(-90deg);">
                                            <circle cx="75" cy="75" r="62" stroke="#f1f5f9" stroke-width="12" fill="transparent"/>
                                            <circle cx="75" cy="75" r="62" stroke="#06b6d4" stroke-width="12" stroke-linecap="round" fill="transparent"
                                                    stroke-dasharray="389.55" stroke-dashoffset="<?php echo 389.55 - (389.55 * (int)$latestAnalysis['overall_score'] / 100); ?>"/>
                                        </svg>
                                    </div>
                                    <h4 class="font-weight-bold text-<?php echo $scoreColor; ?> mb-1"><?php echo $scoreTag; ?></h4>
                                    <span class="text-muted d-block" style="font-size:0.78rem;">
                                        Last Analyzed: <?php echo date('d M Y, h:i A', strtotime($latestAnalysis['analyzed_at'])); ?>
                                    </span>
                                    
                                    <form method="POST" class="mt-4 text-start">
                                        <label class="form-label small font-weight-bold text-muted mb-1 d-print-none">Select Standard:</label>
                                        <select class="form-select form-select-sm mb-2 d-print-none" name="target_job_id" style="font-size: 0.82rem;">
                                            <option value="0" selected>⚡ General ATS Standard (Default)</option>
                                            <?php if (!empty($availableJobs)): ?>
                                                <optgroup label="Or Specific Job Opening">
                                                    <?php foreach ($availableJobs as $job): ?>
                                                        <option value="<?php echo $job['job_id']; ?>">
                                                            <?php echo htmlspecialchars($job['company_name'] . ' - ' . $job['job_title']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                        </select>
                                        <button type="submit" name="trigger_analysis" class="btn btn-outline-primary btn-block btn-sm d-print-none w-100 font-weight-bold">
                                            <i class="fa-solid fa-arrows-spin me-1"></i> Re-analyze Resume
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Section scores list -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-light py-3">
                                    <h6 class="m-0 font-weight-bold text-dark">Section Scores Breakdown</h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($sectionScores as $sec): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1" style="font-size: 0.85rem;">
                                                <span class="text-dark font-weight-bold"><?php echo htmlspecialchars($sec['section_name']); ?></span>
                                                <span class="text-muted"><?php echo $sec['score']; ?> / <?php echo $sec['max_score']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-info" role="progressbar" 
                                                     style="width: <?php echo ($sec['score'] / $sec['max_score']) * 100; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Qualitative evaluation feedback and suggestions -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-primary text-white py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-comment-nodes"></i> Coach Qualitative Review</h6>
                                </div>
                                <div class="card-body">
                                    <p class="lead" style="font-size: 1.1rem; line-height: 1.6;"><?php echo htmlspecialchars($latestAnalysis['assessment_text']); ?></p>
                                </div>
                            </div>

                            <!-- Strengths & Critical issues -->
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow border-left-success h-100">
                                        <div class="card-body">
                                            <h6 class="font-weight-bold text-success"><i class="fa-solid fa-circle-check"></i> Resume Strengths</h6>
                                            <ul class="pl-3 mt-2" style="font-size: 0.85rem; line-height: 1.6;">
                                                <?php 
                                                $strengths = json_decode($latestAnalysis['strengths'], true) ?: [];
                                                foreach ($strengths as $str): 
                                                ?>
                                                    <li><?php echo htmlspecialchars($str); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow border-left-danger h-100">
                                        <div class="card-body">
                                            <h6 class="font-weight-bold text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Critical Issues & Warnings</h6>
                                            <ul class="pl-3 mt-2" style="font-size: 0.85rem; line-height: 1.6;">
                                                <?php 
                                                $issues = json_decode($latestAnalysis['critical_issues'], true) ?: [];
                                                foreach ($issues as $iss): 
                                                ?>
                                                    <li><?php echo htmlspecialchars($iss); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Priority actions actions checklist -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-warning text-dark py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-list-check"></i> Suggested Priority Actions</h6>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush">
                                        <?php 
                                        $actions = json_decode($latestAnalysis['priority_actions'], true) ?: [];
                                        foreach ($actions as $act): 
                                        ?>
                                            <li class="list-group-item d-flex align-items-start py-3 px-4" style="font-size:0.9rem; line-height: 1.5; word-break: break-word; overflow-wrap: anywhere;">
                                                <i class="fa-solid fa-square-check text-warning me-3 mt-1 flex-shrink-0"></i>
                                                <div class="text-dark"><?php echo htmlspecialchars($act); ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>

                            <!-- Keywords & Action verbs -->
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header bg-light py-3">
                                            <h6 class="m-0 font-weight-bold text-dark"><i class="fa-solid fa-tags"></i> Missing Keywords to Add</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php 
                                                $kw = json_decode($latestAnalysis['missing_keywords'], true) ?: [];
                                                if (empty($kw)): 
                                                ?>
                                                    <span class="text-muted small">Keywords are optimized.</span>
                                                <?php else: ?>
                                                    <?php foreach ($kw as $k): ?>
                                                        <span class="badge bg-light text-dark border p-2 text-wrap text-start fw-normal" style="font-size:0.82rem; line-height:1.4; word-break: break-word; overflow-wrap: anywhere; max-width: 100%;"><i class="fa-solid fa-plus text-success me-1"></i><?php echo htmlspecialchars($k); ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header bg-light py-3">
                                            <h6 class="m-0 font-weight-bold text-dark"><i class="fa-solid fa-pen-nib"></i> Suggested Action Verbs</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex flex-column gap-2">
                                                <?php 
                                                $verbs = json_decode($latestAnalysis['action_verb_suggestions'], true) ?: [];
                                                if (empty($verbs)): 
                                                ?>
                                                    <span class="text-muted small">Good usage of active sentences detected.</span>
                                                <?php else: ?>
                                                    <?php foreach ($verbs as $v): ?>
                                                        <div class="p-2 px-3 rounded bg-light border d-flex align-items-start gap-2" style="font-size:0.83rem; line-height: 1.45; word-break: break-word; overflow-wrap: anywhere;">
                                                            <i class="fa-solid fa-bolt text-warning mt-1 flex-shrink-0"></i>
                                                            <div class="text-dark"><?php echo htmlspecialchars($v); ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

            <?php endif; ?>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
