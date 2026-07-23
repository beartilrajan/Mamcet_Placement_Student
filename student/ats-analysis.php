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

// Handle trigger analysis POST
if (isset($_POST['trigger_analysis']) && $currentResume) {
    try {
        require_once(__DIR__ . '/../services/AIService.php');
        require_once(__DIR__ . '/../services/ATSScoringService.php');

        $resumeId = (int)$currentResume['resume_id'];
        $text = $currentResume['extracted_text'];

        if (empty($text)) {
            throw new Exception("Your resume does not contain any extractable text yet. Please go to 'My Resume' and verify text first.");
        }

        // 1. Verify daily and monthly API quotas
        $limitCheck = AIService::checkStudentLimits($db, $studentId);
        if (!$limitCheck['allowed']) {
            throw new Exception($limitCheck['reason']);
        }

        // 2. Perform Hybrid ATS Scoring
        $analysisResults = ATSScoringService::analyze($text, $studentId, $db);

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

        $message = "ATS Compatibility Analysis completed successfully!";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
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
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-gauge-high text-info"></i> ATS Resume Score Estimator</h1>
                <?php if ($latestAnalysis): ?>
                    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm d-print-none">
                        <i class="fa-solid fa-print"></i> Download PDF / Print Report
                    </button>
                <?php endif; ?>
            </div>

            <!-- DISCLAIMER NOTICE (COMPULSORY CRITERIA) -->
            <div class="alert alert-light border shadow-sm mb-4" role="alert" style="border-left: 5px solid #17a2b8 !important;">
                <h6 class="font-weight-bold text-info"><i class="fa-solid fa-triangle-exclamation"></i> Important Notice</h6>
                <small class="text-muted">This score is an estimated placement-readiness and ATS compatibility score. It is not an official score from any employer or recruitment platform. Different ATS search configurations prioritize keywords according to company-specific templates.</small>
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
                    <div class="card shadow py-5 text-center text-muted mb-4">
                        <div class="card-body">
                            <i class="fa-solid fa-laptop-code fa-3x mb-3 text-info"></i>
                            <h5>Resume Scan Ready</h5>
                            <p>Verify your details and run the ATS parser scan. This checks formatting, section tags, skills mapping, and evaluates writing scores.</p>
                            <form method="POST">
                                <button type="submit" name="trigger_analysis" class="btn btn-primary btn-lg mt-3">
                                    <i class="fa-solid fa-compass-drafting"></i> Analyze Resume
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
                                <div class="card-body py-5">
                                    <!-- Circular Progress Gauge -->
                                    <div class="d-inline-flex position-relative mb-4 align-items-center justify-content-center" style="width: 140px; height: 140px;">
                                        <div class="position-absolute" style="font-size: 2.2rem; font-weight: 800; color: #36b9cc;">
                                            <?php echo $latestAnalysis['overall_score']; ?><small style="font-size:0.9rem; font-weight:400; color:#aaa;">/100</small>
                                        </div>
                                        <svg class="w-100 h-100" style="transform: rotate(-90deg);">
                                            <circle cx="70" cy="70" r="60" stroke="#f3f3f3" stroke-width="12" fill="transparent"/>
                                            <circle cx="70" cy="70" r="60" stroke="#36b9cc" stroke-width="12" fill="transparent"
                                                    stroke-dasharray="377" stroke-dashoffset="<?php echo 377 - (377 * $latestAnalysis['overall_score'] / 100); ?>"/>
                                        </svg>
                                    </div>
                                    <h4 class="font-weight-bold text-<?php echo $scoreColor; ?>"><?php echo $scoreTag; ?></h4>
                                    <span class="text-muted d-block mt-2" style="font-size:0.8rem;">
                                        Last Analyzed: <?php echo date('d M Y, h:i A', strtotime($latestAnalysis['analyzed_at'])); ?>
                                    </span>
                                    
                                    <form method="POST" class="mt-4">
                                        <button type="submit" name="trigger_analysis" class="btn btn-outline-primary btn-block btn-sm d-print-none">
                                            <i class="fa-solid fa-arrows-spin"></i> Re-analyze Resume
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
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php 
                                        $actions = json_decode($latestAnalysis['priority_actions'], true) ?: [];
                                        foreach ($actions as $act): 
                                        ?>
                                            <li class="list-group-item d-flex align-items-center py-2" style="font-size:0.9rem;">
                                                <i class="fa-solid fa-square-check text-warning me-3"></i>
                                                <?php echo htmlspecialchars($act); ?>
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
                                                    <span class="text-muted">Keywords are optimized.</span>
                                                <?php else: ?>
                                                    <?php foreach ($kw as $k): ?>
                                                        <span class="badge bg-light text-dark border p-2 m-1" style="font-size:0.8rem;"><i class="fa-solid fa-plus text-success"></i> <?php echo htmlspecialchars($k); ?></span>
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
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php 
                                                $verbs = json_decode($latestAnalysis['action_verb_suggestions'], true) ?: [];
                                                if (empty($verbs)): 
                                                ?>
                                                    <span class="text-muted">Good usage of active sentences detected.</span>
                                                <?php else: ?>
                                                    <?php foreach ($verbs as $v): ?>
                                                        <span class="badge bg-light text-dark border p-2 m-1" style="font-size:0.8rem;"><i class="fa-solid fa-bolt text-warning"></i> <?php echo htmlspecialchars($v); ?></span>
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
