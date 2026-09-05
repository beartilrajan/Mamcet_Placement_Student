<?php
// MAMCET Placement & Learning Portal - Written Interview Preparation
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

// 1. Fetch student info and branch context
$stmtStud = $db->prepare("SELECT s.student_name, d.dept_name, d.short_name FROM students s LEFT JOIN departments d ON s.dept_id = d.dept_id WHERE s.student_id = ?");
$stmtStud->execute([$studentId]);
$studentInfo = $stmtStud->fetch();

// 2. Fetch current active resume text (using LEFT JOIN so records without extracted text don't fail)
$stmtResume = $db->prepare("
    SELECT rf.resume_id, ret.extracted_text 
    FROM resume_files rf 
    LEFT JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1
");
$stmtResume->execute([$studentId]);
$currentResume = $stmtResume->fetch();

$resumeId = $currentResume ? (int)$currentResume['resume_id'] : 0;
$resumeText = $currentResume ? ($currentResume['extracted_text'] ?? '') : '';
$deptTitle = !empty($studentInfo['dept_name']) ? $studentInfo['dept_name'] : (!empty($studentInfo['short_name']) ? $studentInfo['short_name'] : '');
if (empty($resumeText) && !empty($deptTitle)) {
    $resumeText = "Student in " . $deptTitle . " Engineering.";
}

// 3. Handle Question Set Regeneration request
if (isset($_POST['regenerate_questions'])) {
    try {
        $db->prepare("DELETE FROM interview_question_sets WHERE student_id = ?")->execute([$studentId]);
        require_once(__DIR__ . '/../services/InterviewService.php');
        InterviewService::generateQuestionSet($db, $studentId, $resumeId, $resumeText);
        $db = Database::getInstance()->getConnection();
        $message = "A fresh set of tailored interview questions has been generated!";
    } catch (\Throwable $e) {
        $error = "Failed to regenerate questions: " . $e->getMessage();
    }
}

// 4. Fetch or generate Question Set
$questionSetId = 0;
$questions = [];

$stmtSet = $db->prepare("SELECT set_id FROM interview_question_sets WHERE student_id = ? ORDER BY set_id DESC LIMIT 1");
$stmtSet->execute([$studentId]);
$questionSetId = (int)$stmtSet->fetchColumn();

// Auto-generate if missing
if (!$questionSetId) {
    try {
        require_once(__DIR__ . '/../services/InterviewService.php');
        $questionSetId = InterviewService::generateQuestionSet($db, $studentId, $resumeId, $resumeText);
        $db = Database::getInstance()->getConnection();
    } catch (\Throwable $e) {
        $error = "Notice: " . $e->getMessage();
        $db = Database::getInstance()->getConnection();
    }
}

if ($questionSetId) {
    $stmtQ = $db->prepare("SELECT * FROM interview_questions WHERE set_id = ? ORDER BY question_id ASC");
    $stmtQ->execute([$questionSetId]);
    $questions = $stmtQ->fetchAll();
}

// Guarantee questions are available (fallback if set was somehow empty)
if (empty($questions)) {
    try {
        require_once(__DIR__ . '/../services/InterviewService.php');
        $questionSetId = InterviewService::generateQuestionSet($db, $studentId, $resumeId, $resumeText);
        $db = Database::getInstance()->getConnection();
        $stmtQ = $db->prepare("SELECT * FROM interview_questions WHERE set_id = ? ORDER BY question_id ASC");
        $stmtQ->execute([$questionSetId]);
        $questions = $stmtQ->fetchAll();
    } catch (\Throwable $e) {
        $error = "Could not initialize questions: " . $e->getMessage();
    }
}

// 5. Handle written practice answer submission
$evaluatedFeedback = null;
$selectedQuestionId = 0;
if (isset($_POST['submit_answer'])) {
    try {
        $selectedQuestionId = (int)$_POST['question_id'];
        $studentAnswer = trim($_POST['student_answer']);

        if (empty($studentAnswer)) {
            throw new Exception("Please type a valid answer to evaluate.");
        }

        // Fetch question text
        $stmtQText = $db->prepare("SELECT question FROM interview_questions WHERE question_id = ?");
        $stmtQText->execute([$selectedQuestionId]);
        $questionText = $stmtQText->fetchColumn();

        if (!$questionText) {
            throw new Exception("Question record not found.");
        }

        // Start attempt session mapping to written practice
        $db->beginTransaction();
        
        $stmtAttempt = $db->prepare("INSERT INTO interview_attempts (student_id, attempt_type, total_questions, is_completed) VALUES (?, 'written', 1, 1)");
        $stmtAttempt->execute([$studentId]);
        $attemptId = (int)$db->lastInsertId();

        $stmtAns = $db->prepare("INSERT INTO interview_answers (attempt_id, question_id, student_answer) VALUES (?, ?, ?)");
        $stmtAns->execute([$attemptId, $selectedQuestionId, $studentAnswer]);
        $answerId = (int)$db->lastInsertId();

        $db->commit();

        // Call coach evaluation
        require_once(__DIR__ . '/../services/InterviewService.php');
        $evaluatedFeedback = InterviewService::evaluateAnswer($db, $studentId, $answerId, $questionText, $studentAnswer);
        $db = Database::getInstance()->getConnection();

        $message = "Answer evaluated by Placement AI Coach!";
    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        $db = Database::getInstance()->getConnection();
    }
}

// Group questions by category
$categories = [];
foreach ($questions as $q) {
    $catName = !empty($q['category']) ? $q['category'] : 'General';
    $categories[$catName][] = $q;
}

// Student AI Quota status for Interview Preparation
require_once(__DIR__ . '/../services/AIService.php');
$quotaInfo = AIService::checkStudentLimits($db, $studentId, 'interview_prep');
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
            <div>
                <h1 class="h3 mb-1 text-gray-800"><i class="fa-solid fa-brain text-primary"></i> Written Interview Practice</h1>
                <p class="text-muted small mb-0">Master HR, technical, and situational interview questions with real-time AI feedback.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php echo AIService::renderQuotaBadge($quotaInfo); ?>
                <form method="POST" class="d-inline mb-0" onsubmit="return <?php echo $quotaInfo['monthly_remaining'] > 0 ? "confirm('Generate fresh placement interview questions? (Uses 1 of your " . $quotaInfo['monthly_limit'] . " monthly AI calls)')" : "confirm('Your AI limit is reached for this month. Questions will be generated using domain placement templates. Proceed?')"; ?>;">
                    <button type="submit" name="regenerate_questions" class="btn btn-outline-primary btn-sm text-nowrap">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh Questions
                    </button>
                </form>
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
            <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$currentResume): ?>
            <div class="alert alert-info border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                <div>
                    <i class="fa-solid fa-info-circle me-2 text-info"></i>
                    <strong>Standard Campus Placement Practice Mode:</strong> Practicing core engineering and placement syllabus questions.
                </div>
                <a href="resume.php" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-file-arrow-up me-1"></i> Upload Resume for Custom Questions
                </a>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left: Questions list -->
            <div class="col-lg-5 mb-4">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-list-check me-1"></i> Interview Questions (<?php echo count($questions); ?>)</h6>
                    </div>
                    <div class="card-body p-0" style="max-height: 480px; overflow-y: auto;">
                        <?php if (empty($questions)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fa-solid fa-clipboard-question fa-3x mb-3 text-secondary"></i>
                                <h5>No Questions Available</h5>
                                <form method="POST">
                                    <button type="submit" name="regenerate_questions" class="btn btn-primary btn-sm mt-2">
                                        <i class="fa-solid fa-plus-circle me-1"></i> Generate Practice Questions
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="accordionQuestions">
                                <?php $i = 0; foreach ($categories as $cat => $catQs): $i++; ?>
                                    <div class="accordion-item border-0 border-bottom">
                                        <h2 class="accordion-header" id="heading<?php echo $i; ?>">
                                            <button class="accordion-button <?php echo $i > 1 ? 'collapsed' : ''; ?> py-3 bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $i; ?>" aria-expanded="<?php echo $i === 1 ? 'true' : 'false'; ?>">
                                                <strong class="text-dark"><?php echo htmlspecialchars($cat); ?></strong>
                                                <span class="badge bg-secondary ms-2"><?php echo count($catQs); ?></span>
                                            </button>
                                        </h2>
                                        <div id="collapse<?php echo $i; ?>" class="accordion-collapse collapse <?php echo $i === 1 ? 'show' : ''; ?>" data-bs-parent="#accordionQuestions">
                                            <div class="accordion-body p-0">
                                                <div class="list-group list-group-flush">
                                                    <?php foreach ($catQs as $idx => $q): ?>
                                                        <button type="button" class="list-group-item list-group-item-action py-3 select-question-btn <?php echo ($i === 1 && $idx === 0) ? 'active bg-light' : ''; ?>" 
                                                                data-id="<?php echo $q['question_id']; ?>"
                                                                data-q="<?php echo htmlspecialchars($q['question']); ?>"
                                                                data-category="<?php echo htmlspecialchars($q['category']); ?>"
                                                                data-reason="<?php echo htmlspecialchars($q['reason'] ?? ''); ?>"
                                                                data-points="<?php echo htmlspecialchars($q['answer_points'] ?? '[]'); ?>"
                                                                data-struct="<?php echo htmlspecialchars($q['sample_structure'] ?? ''); ?>"
                                                                data-avoid="<?php echo htmlspecialchars($q['mistakes_to_avoid'] ?? ''); ?>">
                                                            <div class="d-flex align-items-start gap-2">
                                                                <i class="fa-solid fa-circle-question text-primary mt-1 flex-shrink-0"></i>
                                                                <div>
                                                                    <div class="fw-semibold text-dark mb-1" style="font-size: 0.95rem;"><?php echo htmlspecialchars($q['question']); ?></div>
                                                                    <small class="text-muted"><span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($q['category']); ?></span></small>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Editor box and feedback panel -->
            <div class="col-lg-7 mb-4">
                <!-- FEEDBACK SCORECARD (if evaluated) -->
                <?php if ($evaluatedFeedback): ?>
                    <div class="card shadow border-0 border-start border-4 border-success mb-4">
                        <div class="card-header bg-success text-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-award me-1"></i> AI Coach Performance Scorecard</h6>
                            <span class="badge bg-white text-success font-weight-bold">Evaluated</span>
                        </div>
                        <div class="card-body">
                            <div class="row text-center mb-4">
                                <div class="col-4">
                                    <div class="p-3 bg-light rounded shadow-sm">
                                        <h3 class="font-weight-bold text-primary mb-0"><?php echo (int)($evaluatedFeedback['relevance'] ?? 8); ?>/10</h3>
                                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem;">Relevance</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-3 bg-light rounded shadow-sm">
                                        <h3 class="font-weight-bold text-success mb-0"><?php echo (int)($evaluatedFeedback['clarity'] ?? 8); ?>/10</h3>
                                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem;">Clarity</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-3 bg-light rounded shadow-sm">
                                        <h3 class="font-weight-bold text-warning mb-0"><?php echo (int)($evaluatedFeedback['structure'] ?? 8); ?>/10</h3>
                                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem;">Structure</small>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <strong><i class="fa-solid fa-spell-check text-primary me-1"></i> Communication & Grammar:</strong>
                                <p class="text-muted mb-2 mt-1"><?php echo htmlspecialchars($evaluatedFeedback['grammar'] ?? 'Good sentence structure.'); ?></p>
                            </div>
                            <div class="mb-3">
                                <strong><i class="fa-solid fa-lightbulb text-warning me-1"></i> Suggested Improvements:</strong>
                                <p class="text-muted mb-2 mt-1"><?php echo htmlspecialchars($evaluatedFeedback['suggestions'] ?? 'Include more quantitative details.'); ?></p>
                            </div>
                            <div>
                                <strong><i class="fa-solid fa-comments text-success me-1"></i> Overall Coach Review:</strong>
                                <p class="text-muted mb-0 mt-1"><?php echo htmlspecialchars($evaluatedFeedback['overall_comments'] ?? 'Well structured response.'); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Practice Card Form -->
                <div class="card shadow mb-4" id="practiceFormCard">
                    <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-feather me-1"></i> Type Your Answer</h6>
                        <span class="badge bg-white text-primary" id="activeCategoryBadge">Introduction</span>
                    </div>
                    <div class="card-body">
                        <h5 class="text-dark font-weight-bold mb-3" id="activeQuestionText">Question Placeholder</h5>
                        
                        <ul class="nav nav-tabs mb-3" id="questionTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="write-tab" data-bs-toggle="tab" data-bs-target="#write-panel" type="button" role="tab"><i class="fa-solid fa-pen-to-square me-1"></i> Answer Editor</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="coaching-tab" data-bs-toggle="tab" data-bs-target="#coaching-panel" type="button" role="tab"><i class="fa-solid fa-chalkboard-user me-1"></i> Recruiter Tips & STAR Guidance</button>
                            </li>
                        </ul>

                        <div class="tab-content" id="questionTabsContent">
                            <!-- Written Text box -->
                            <div class="tab-pane fade show active" id="write-panel" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="question_id" id="formQuestionId" value="">
                                    <div class="mb-3">
                                        <textarea class="form-control" name="student_answer" id="studentAnswerBox" rows="6" placeholder="Type your detailed answer here... (Tip: Follow the STAR method: Situation, Task, Action, and Result for maximum recruiter impact)" required></textarea>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <small class="text-muted" id="wordCountLabel">0 words</small>
                                        <button type="submit" name="submit_answer" class="btn btn-primary font-weight-bold w-100 w-sm-auto">
                                            <i class="fa-solid fa-paper-plane me-1"></i> Submit Answer for AI Coach Review
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Recruiter Coach instructions -->
                            <div class="tab-pane fade" id="coaching-panel" role="tabpanel">
                                <div class="p-3 bg-light rounded" style="font-size:0.92rem; line-height: 1.6;">
                                    <p class="mb-2"><strong><i class="fa-solid fa-bullseye text-danger me-1"></i> Why Recruiters Ask This:</strong> <span id="coachReason" class="text-muted"></span></p>
                                    <p class="mb-2"><strong><i class="fa-solid fa-layer-group text-primary me-1"></i> Recommended Structure:</strong> <span id="coachStructure" class="text-muted"></span></p>
                                    <p class="mb-3"><strong><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Pitfalls to Avoid:</strong> <span id="coachAvoid" class="text-muted"></span></p>
                                    <strong><i class="fa-solid fa-list-check text-success me-1"></i> Key Points to Include:</strong>
                                    <ul id="coachPoints" class="mt-2 mb-0 text-muted"></ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State Placeholder (hidden when active) -->
                <div class="card shadow mb-4 d-none" id="emptyEditorState">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fa-solid fa-keyboard fa-3x mb-3 text-gray-300"></i>
                        <h5>No Question Selected</h5>
                        <p>Select any question from the left sidebar to begin typing your answer and receiving AI evaluations.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function loadQuestionData(btn) {
        if (!btn || !btn.length) return;

        const qId = btn.data('id');
        const qText = btn.data('q');
        const cat = btn.data('category') || 'General';
        const reason = btn.data('reason') || 'Evaluates candidate problem-solving and domain aptitude.';
        const structure = btn.data('struct') || 'Situation -> Task -> Action -> Result (STAR Method)';
        const avoid = btn.data('avoid') || 'Avoid giving vague or one-sentence responses.';
        const points = btn.data('points');

        // Highlight active question
        $('.select-question-btn').removeClass('active bg-light');
        btn.addClass('active bg-light');

        // Show practice form
        $('#emptyEditorState').addClass('d-none');
        $('#practiceFormCard').removeClass('d-none');

        // Populate fields
        $('#formQuestionId').val(qId);
        $('#activeQuestionText').text(qText);
        $('#activeCategoryBadge').text(cat);
        $('#studentAnswerBox').val('');
        $('#wordCountLabel').text('0 words');

        // Populate coaching tab
        $('#coachReason').text(reason);
        $('#coachStructure').text(structure);
        $('#coachAvoid').text(avoid);

        // Populate points bullet list
        const pointsList = $('#coachPoints');
        pointsList.empty();
        try {
            let items = points;
            if (typeof items === 'string') {
                try {
                    items = JSON.parse(items);
                } catch(e) {
                    items = [items];
                }
            }
            if (Array.isArray(items) && items.length > 0) {
                items.forEach(pt => {
                    pointsList.append(`<li>${escapeHtml(pt)}</li>`);
                });
            } else {
                pointsList.append(`<li>Explain concepts clearly with concrete technical examples.</li>`);
                pointsList.append(`<li>State your personal hands-on contribution and measurable outcomes.</li>`);
            }
        } catch(e) {
            pointsList.append(`<li>Highlight core strengths and structured approaches.</li>`);
        }
    }

    $('.select-question-btn').on('click', function() {
        loadQuestionData($(this));
        // Switch to write tab
        $('#write-tab').trigger('click');
        if (window.innerWidth < 992) {
            const formCard = $('#practiceFormCard');
            if (formCard.length) {
                $('html, body').animate({
                    scrollTop: formCard.offset().top - 75
                }, 350);
            }
        }
    });

    // Word count counter
    $('#studentAnswerBox').on('input', function() {
        const text = $(this).val().trim();
        const count = text ? text.split(/\s+/).length : 0;
        $('#wordCountLabel').text(count + (count === 1 ? ' word' : ' words'));
    });

    // Auto-select first question on page load
    const firstBtn = $('.select-question-btn.active').first();
    if (firstBtn.length) {
        loadQuestionData(firstBtn);
    } else {
        const anyFirst = $('.select-question-btn').first();
        if (anyFirst.length) {
            loadQuestionData(anyFirst);
        }
    }

    function escapeHtml(string) {
        return String(string).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
