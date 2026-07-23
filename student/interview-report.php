<?php
// MAMCET Placement & Learning Portal - Mock Interview Report Card
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

$attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if (!$attemptId) {
    header("Location: mock-interview.php");
    exit;
}

// 1. Fetch attempt info
$stmtAtt = $db->prepare("SELECT * FROM interview_attempts WHERE attempt_id = ? AND student_id = ? AND is_completed = 1");
$stmtAtt->execute([$attemptId, $studentId]);
$attempt = $stmtAtt->fetch();

if (!$attempt) {
    header("Location: mock-interview.php");
    exit;
}

// 2. Fetch or trigger final report compilation
// We save final summary feedback under dummy answer ID = -($attemptId) inside interview_feedback table
$dummyAnswerId = -($attemptId);
$stmtFeed = $db->prepare("SELECT * FROM interview_feedback WHERE answer_id = ?");
$stmtFeed->execute([$dummyAnswerId]);
$reportRecord = $stmtFeed->fetch();

$reportData = [];

if ($reportRecord) {
    $extraDetails = json_decode($reportRecord['overall_comments'], true) ?: [];
    $reportData = [
        'relevance_score' => $reportRecord['relevance'],
        'clarity_score' => $reportRecord['clarity'],
        'structure_score' => $reportRecord['structure'],
        'confidence_indication' => $reportRecord['grammar'],
        'areas_to_improve' => json_decode($reportRecord['suggestions'], true) ?: [],
        'resume_understanding' => $extraDetails['resume_understanding'] ?? 'N/A',
        'project_explanation_rating' => $extraDetails['project_explanation_rating'] ?? 'N/A',
        'skill_explanation_rating' => $extraDetails['skill_explanation_rating'] ?? 'N/A',
        'hr_readiness' => $extraDetails['hr_readiness'] ?? 'N/A',
        'strengths' => $extraDetails['strengths'] ?? [],
        'recommended_practice' => $extraDetails['recommended_practice'] ?? []
    ];
} else {
    // Generate new final report
    try {
        require_once(__DIR__ . '/../services/InterviewService.php');
        $feedback = InterviewService::generateMockFinalReport($db, $studentId, $attemptId);
        
        $reportData = $feedback;
    } catch (Exception $e) {
        $error = "Report generation failed: " . $e->getMessage();
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-chart-line text-success"></i> Interview Performance Scorecard</h1>
                <a href="mock-interview.php" class="btn btn-primary d-print-none"><i class="fa-solid fa-arrow-rotate-left"></i> Start New Practice</a>
            </div>

            <!-- Warning notice on text emotion detection (Privacy Compliance) -->
            <div class="alert alert-light border shadow-sm mb-4" role="alert" style="border-left: 5px solid #6c757d !important;">
                <small class="text-muted"><i class="fa-solid fa-triangle-exclamation"></i> Mock analytics and confidence levels represent estimates formulated entirely from written text structures. We do not claim to accurately detect emotion or physical confidence parameters from text alone.</small>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($reportData)): ?>
                <!-- RENDERING REPORT CARD -->
                <div class="row">
                    <!-- Score meters -->
                    <div class="col-lg-4">
                        <div class="card shadow mb-4">
                            <div class="card-header bg-dark text-white text-center py-3">
                                <h6 class="m-0 font-weight-bold">Performance Ratings</h6>
                            </div>
                            <div class="card-body">
                                <!-- Relevance -->
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.85rem;">
                                        <span class="text-dark font-weight-bold">Answer Relevance</span>
                                        <span class="text-muted font-weight-bold"><?php echo $reportData['relevance_score']; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $reportData['relevance_score']; ?>%"></div>
                                    </div>
                                </div>
                                <!-- Clarity -->
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.85rem;">
                                        <span class="text-dark font-weight-bold">Communication Clarity</span>
                                        <span class="text-muted font-weight-bold"><?php echo $reportData['clarity_score']; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $reportData['clarity_score']; ?>%"></div>
                                    </div>
                                </div>
                                <!-- Structure -->
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.85rem;">
                                        <span class="text-dark font-weight-bold">Structured Answers</span>
                                        <span class="text-muted font-weight-bold"><?php echo $reportData['structure_score']; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $reportData['structure_score']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Qualitative evaluations card -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-light py-3">
                                <h6 class="m-0 font-weight-bold text-dark">Subject Matter Ratings</h6>
                            </div>
                            <div class="card-body" style="font-size:0.85rem; line-height: 1.5;">
                                <p><strong>HR Readiness:</strong> <span class="badge bg-primary p-2"><?php echo htmlspecialchars($reportData['hr_readiness']); ?></span></p>
                                <hr>
                                <p><strong>Resume Understanding:</strong> <?php echo htmlspecialchars($reportData['resume_understanding']); ?></p>
                                <p><strong>Project Explanations:</strong> <?php echo htmlspecialchars($reportData['project_explanation_rating']); ?></p>
                                <p><strong>Skill Explanations:</strong> <?php echo htmlspecialchars($reportData['skill_explanation_rating']); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Right columns: feedback summaries -->
                    <div class="col-lg-8">
                        <!-- Confidence tag -->
                        <div class="card shadow border-left-info mb-4">
                            <div class="card-body">
                                <h6 class="font-weight-bold text-info"><i class="fa-solid fa-microphone-lines"></i> Communication Confidence Indication</h6>
                                <p class="lead mb-0 mt-2" style="font-size:1.05rem; font-style:italic;"><?php echo htmlspecialchars($reportData['confidence_indication']); ?></p>
                            </div>
                        </div>

                        <!-- Strengths & Weaknesses -->
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card shadow border-left-success h-100">
                                    <div class="card-body">
                                        <h6 class="font-weight-bold text-success"><i class="fa-solid fa-circle-check"></i> Key Strengths</h6>
                                        <ul class="pl-3 mt-2" style="font-size: 0.85rem; line-height: 1.5;">
                                            <?php foreach ($reportData['strengths'] as $str): ?>
                                                <li><?php echo htmlspecialchars($str); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <div class="card shadow border-left-danger h-100">
                                    <div class="card-body">
                                        <h6 class="font-weight-bold text-danger"><i class="fa-solid fa-circle-xmark"></i> Areas to Improve</h6>
                                        <ul class="pl-3 mt-2" style="font-size: 0.85rem; line-height: 1.5;">
                                            <?php foreach ($reportData['areas_to_improve'] as $arr): ?>
                                                <li><?php echo htmlspecialchars($arr); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Practice recommendations checklist -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-warning text-dark py-3">
                                <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-lightbulb"></i> Recommended Topics to Study</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush" style="font-size: 0.9rem;">
                                    <?php foreach ($reportData['recommended_practice'] as $rec): ?>
                                        <li class="list-group-item d-flex align-items-center py-2">
                                            <i class="fa-solid fa-square-check text-warning me-3"></i>
                                            <?php echo htmlspecialchars($rec); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endif; ?>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
