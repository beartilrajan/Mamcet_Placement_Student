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

// 1. Fetch current active resume text
$stmtResume = $db->prepare("
    SELECT rf.resume_id, ret.extracted_text 
    FROM resume_files rf 
    JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1
");
$stmtResume->execute([$studentId]);
$currentResume = $stmtResume->fetch();

$questionSetId = 0;
$questions = [];

if ($currentResume) {
    $resumeId = (int)$currentResume['resume_id'];
    
    // Check if question set already exists
    $stmtSet = $db->prepare("SELECT set_id FROM interview_question_sets WHERE student_id = ? AND resume_id = ? LIMIT 1");
    $stmtSet->execute([$studentId, $resumeId]);
    $questionSetId = (int)$stmtSet->fetchColumn();

    // Auto-generate if missing
    if (!$questionSetId && !empty($currentResume['extracted_text'])) {
        try {
            require_once(__DIR__ . '/../services/InterviewService.php');
            $questionSetId = InterviewService::generateQuestionSet($db, $studentId, $resumeId, $currentResume['extracted_text']);
        } catch (Exception $e) {
            $error = "Auto Question Generation failed: " . $e->getMessage();
        }
    }

    if ($questionSetId) {
        $stmtQ = $db->prepare("SELECT * FROM interview_questions WHERE set_id = ?");
        $stmtQ->execute([$questionSetId]);
        $questions = $stmtQ->fetchAll();
    }
}

// 2. Handle written practice answer submission
$evaluatedFeedback = null;
$selectedQuestionId = 0;
if (isset($_POST['submit_answer']) && $currentResume) {
    try {
        $selectedQuestionId = (int)$_POST['question_id'];
        $studentAnswer = trim($_POST['student_answer']);

        if (empty($studentAnswer)) {
            throw new Exception("Please type a valid answer to evaluate.");
        }

        // Fetch question text
        $stmtQText = $db->prepare("SELECT question FROM interview_questions WHERE question_id = ? AND set_id = ?");
        $stmtQText->execute([$selectedQuestionId, $questionSetId]);
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

        $message = "Answer evaluated by Placement AI Coach!";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// Group questions by category
$categories = [];
foreach ($questions as $q) {
    $categories[$q['category']][] = $q;
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-brain text-primary"></i> Written Interview Practice</h1>

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
                        <h5>No Resume Found</h5>
                        <p>Upload a current resume to automatically generate interview questions tailored to your skills.</p>
                        <a href="resume.php" class="btn btn-primary mt-2">Go to My Resume</a>
                    </div>
                </div>
            <?php else: ?>
                
                <div class="row">
                    <!-- Left: Questions list -->
                    <div class="col-lg-5">
                        <div class="card shadow mb-4">
                            <div class="card-header bg-dark text-white py-3">
                                <h6 class="m-0 font-weight-bold">Resume Tailored Questions</h6>
                            </div>
                            <div class="card-body p-0" style="max-height:600px; overflow-y:scroll;">
                                <?php if (empty($questions)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-arrows-spin fa-spin fa-2x mb-3 text-info"></i>
                                        <p>Generating resume-specific questions...</p>
                                        <a href="" class="btn btn-sm btn-secondary">Reload Page</a>
                                    </div>
                                <?php else: ?>
                                    <div class="accordion" id="accordionQuestions">
                                        <?php $i = 0; foreach ($categories as $cat => $catQs): $i++; ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="heading<?php echo $i; ?>">
                                                    <button class="accordion-button <?php echo $i > 1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $i; ?>">
                                                        <strong><?php echo htmlspecialchars($cat); ?></strong>
                                                        <span class="badge bg-secondary ms-2"><?php echo count($catQs); ?></span>
                                                    </button>
                                                </h2>
                                                <div id="collapse<?php echo $i; ?>" class="accordion-collapse collapse <?php echo $i === 1 ? 'show' : ''; ?>" data-bs-parent="#accordionQuestions">
                                                    <div class="accordion-body p-0">
                                                        <div class="list-group list-group-flush">
                                                            <?php foreach ($catQs as $q): ?>
                                                                <button type="button" class="list-group-item list-group-item-action py-3 select-question-btn" 
                                                                        data-id="<?php echo $q['question_id']; ?>"
                                                                        data-q="<?php echo htmlspecialchars($q['question']); ?>"
                                                                        data-reason="<?php echo htmlspecialchars($q['reason']); ?>"
                                                                        data-points="<?php echo htmlspecialchars($q['answer_points']); ?>"
                                                                        data-struct="<?php echo htmlspecialchars($q['sample_structure']); ?>"
                                                                        data-avoid="<?php echo htmlspecialchars($q['mistakes_to_avoid']); ?>">
                                                                    <i class="fa-solid fa-circle-question text-info me-2"></i>
                                                                    <?php echo htmlspecialchars($q['question']); ?>
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
                    <div class="col-lg-7">
                        <div class="card shadow mb-4 d-none" id="practiceFormCard">
                            <div class="card-header bg-primary text-white py-3">
                                <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-feather"></i> Type Your Answer</h6>
                            </div>
                            <div class="card-body">
                                <h5 class="text-dark font-weight-bold mb-3" id="activeQuestionText">Question Placeholder</h5>
                                
                                <ul class="nav nav-tabs mb-3" id="questionTabs" role="tablist">
                                    <li class="nav-item">
                                        <button class="nav-link active" id="write-tab" data-bs-toggle="tab" data-bs-target="#write-panel" type="button">Answer Editor</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" id="coaching-tab" data-bs-toggle="tab" data-bs-target="#coaching-panel" type="button">Recruiter Tips</button>
                                    </li>
                                </ul>

                                <div class="tab-content" id="questionTabsContent">
                                    <!-- Written Text box -->
                                    <div class="tab-pane fade show active" id="write-panel">
                                        <form method="POST">
                                            <input type="hidden" name="question_id" id="formQuestionId" value="">
                                            <div class="mb-3">
                                                <textarea class="form-control" name="student_answer" id="studentAnswerBox" rows="6" placeholder="Type your detailed answer here... (minimum 3 sentences advised for accurate structural reviews)"></textarea>
                                            </div>
                                            <button type="submit" name="submit_answer" class="btn btn-primary font-weight-bold">
                                                <i class="fa-solid fa-paper-plane"></i> Submit Answer for AI Coach Review
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Recruiter Coach instructions -->
                                    <div class="tab-pane fade" id="coaching-panel">
                                        <div class="p-3 bg-light rounded" style="font-size:0.9rem;">
                                            <p><strong>Why HR asks this:</strong> <span id="coachReason"></span></p>
                                            <p><strong>Structure:</strong> <span id="coachStructure"></span></p>
                                            <p><strong>Pitfalls to avoid:</strong> <span id="coachAvoid"></span></p>
                                            <strong>Points to include:</strong>
                                            <ul id="coachPoints" class="mt-2"></ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- FEEDBACK SCORECARD -->
                        <?php if ($evaluatedFeedback): ?>
                            <div class="card shadow border-left-success mb-4">
                                <div class="card-header bg-success text-white py-3">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-comment-dots"></i> AI Coach Performance Scorecard</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center mb-4">
                                        <div class="col-4">
                                            <h4 class="font-weight-bold text-primary mb-0"><?php echo $evaluatedFeedback['relevance']; ?>/10</h4>
                                            <small class="text-muted">Relevance</small>
                                        </div>
                                        <div class="col-4">
                                            <h4 class="font-weight-bold text-success mb-0"><?php echo $evaluatedFeedback['clarity']; ?>/10</h4>
                                            <small class="text-muted">Clarity</small>
                                        </div>
                                        <div class="col-4">
                                            <h4 class="font-weight-bold text-warning mb-0"><?php echo $evaluatedFeedback['structure']; ?>/10</h4>
                                            <small class="text-muted">Structure</small>
                                        </div>
                                    </div>
                                    <hr>
                                    <p><strong>Grammar Review:</strong> <?php echo htmlspecialchars($evaluatedFeedback['grammar']); ?></p>
                                    <p><strong>Suggested Phrasing:</strong> <?php echo htmlspecialchars($evaluatedFeedback['suggestions']); ?></p>
                                    <p><strong>Overall Coach Evaluation:</strong> <?php echo htmlspecialchars($evaluatedFeedback['overall_comments']); ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card shadow mb-4" id="emptyEditorState">
                                <div class="card-body text-center py-5 text-muted">
                                    <i class="fa-solid fa-keyboard fa-3x mb-3 text-gray-300"></i>
                                    <h5>No Question Selected</h5>
                                    <p>Select a question from the list on the left to start typing your answer and get feedback.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.select-question-btn').on('click', function() {
        const btn = $(this);
        const qId = btn.data('id');
        const qText = btn.data('q');
        const reason = btn.data('reason');
        const structure = btn.data('struct');
        const avoid = btn.data('avoid');
        const points = btn.data('points'); // json array string

        // Highlight active question
        $('.select-question-btn').removeClass('active bg-light');
        btn.addClass('active bg-light');

        // Toggle Cards visibility
        $('#emptyEditorState').addClass('d-none');
        $('#practiceFormCard').removeClass('d-none');

        // Populate fields
        $('#formQuestionId').val(qId);
        $('#activeQuestionText').text(qText);
        $('#studentAnswerBox').val('');

        // Populate coaching tab
        $('#coachReason').text(reason);
        $('#coachStructure').text(structure);
        $('#coachAvoid').text(avoid);

        // Populate points bullet list
        const pointsList = $('#coachPoints');
        pointsList.empty();
        try {
            const arr = points; // points data is parsed automatically by jQuery if JSON
            const items = typeof arr === 'string' ? JSON.parse(arr) : arr;
            items.forEach(pt => {
                pointsList.append(`<li>${pt}</li>`);
            });
        } catch(e) {
            // Silently fallback
        }
        
        // Focus tab
        $('#write-tab').trigger('click');
    });
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
