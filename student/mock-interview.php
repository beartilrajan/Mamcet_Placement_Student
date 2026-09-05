<?php
// MAMCET Placement & Learning Portal - Interactive Mock Interview Chat
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../config/database.php');

$baseDir = '../';
$roleId = (int)($_SESSION['role_id'] ?? 0);

// Restrict to student only
if (!isLoggedIn() || $roleId !== ROLE_STUDENT) {
    if (isset($_POST['send_answer'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$message = '';
$error = '';

// Load active resume details
$stmtResume = $db->prepare("SELECT resume_id FROM resume_files WHERE student_id = ? AND is_current = 1 LIMIT 1");
$stmtResume->execute([$studentId]);
$resumeId = (int)$stmtResume->fetchColumn();
// 1. Process Start Request
if (isset($_POST['start_mock'])) {
    try {
        require_once(__DIR__ . '/../services/AIService.php');
        $limitCheck = AIService::checkStudentLimits($db, $studentId, 'mock_interview');
        if (!$limitCheck['allowed']) {
            throw new Exception($limitCheck['reason']);
        }

        $totalQ = (int)$_POST['total_questions'];
        if (!in_array($totalQ, [5, 10, 15])) {
            $totalQ = 5;
        }

        // Insert attempt record
        $stmtIns = $db->prepare("INSERT INTO interview_attempts (student_id, attempt_type, total_questions, is_completed) VALUES (?, 'mock_chat', ?, 0)");
        $stmtIns->execute([$studentId, $totalQ]);
        $attemptId = (int)$db->lastInsertId();

        // Redirect to active interview session
        header("Location: mock-interview.php?attempt_id=" . $attemptId);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// 2. Load active attempt context if session parameters are passed
$attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$attempt = null;
$qaHistory = [];
$currentQIndex = 1;

if ($attemptId > 0) {
    $stmtAtt = $db->prepare("SELECT * FROM interview_attempts WHERE attempt_id = ? AND student_id = ? AND is_completed = 0");
    $stmtAtt->execute([$attemptId, $studentId]);
    $attempt = $stmtAtt->fetch();
    
    if (!$attempt) {
        if (isset($_POST['send_answer'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Active session not found or already completed.']);
            exit;
        }
        header("Location: mock-interview.php");
        exit;
    }

    // Load conversation history
    $stmtHist = $db->prepare("
        SELECT a.answer_id, q.question, a.student_answer 
        FROM interview_answers a 
        JOIN interview_questions q ON a.question_id = q.question_id 
        WHERE a.attempt_id = ? 
        ORDER BY a.answer_id ASC
    ");
    $stmtHist->execute([$attemptId]);
    $qaHistory = $stmtHist->fetchAll();
    $currentQIndex = count($qaHistory) + 1;

    // Check if we need to load/trigger the first question
    if (empty($qaHistory)) {
        try {
            require_once(__DIR__ . '/../services/InterviewService.php');
            
            // Get introductory question text
            $firstQuestionText = InterviewService::getMockChatNextQuestion($db, $studentId, $attemptId, 1, $attempt['total_questions'], '', '');

            if (class_exists('Database')) {
                $db = Database::getInstance()->getConnection();
            }

            $db->beginTransaction();
            // Get or create question set
            $stmtSet = $db->prepare("SELECT set_id FROM interview_question_sets WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmtSet->execute([$studentId]);
            $setId = (int)$stmtSet->fetchColumn();

            if (!$setId) {
                $stmtSetIns = $db->prepare("INSERT INTO interview_question_sets (student_id, resume_id) VALUES (?, ?)");
                $stmtSetIns->execute([$studentId, $resumeId > 0 ? $resumeId : null]);
                $setId = (int)$db->lastInsertId();
            }

            // Save question in database mapping to set
            $stmtQ = $db->prepare("INSERT INTO interview_questions (set_id, category, question) VALUES (?, 'Introduction', ?)");
            $stmtQ->execute([$setId, $firstQuestionText]);
            $questionId = (int)$db->lastInsertId();

            // Insert blank placeholder into answers
            $stmtAns = $db->prepare("INSERT INTO interview_answers (attempt_id, question_id, student_answer) VALUES (?, ?, '')");
            $stmtAns->execute([$attemptId, $questionId]);
            
            $db->commit();

            // Reload history
            $stmtHist->execute([$attemptId]);
            $qaHistory = $stmtHist->fetchAll();
            $currentQIndex = 2;
        } catch (Exception $ex) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Failed to start conversation: " . $ex->getMessage();
            $db = Database::getInstance()->getConnection();
        }
    }
}

// 3. Handle ajax POST answer submission
if (isset($_POST['send_answer']) && $attempt) {
    header('Content-Type: application/json');
    try {
        $studentAnswer = trim($_POST['answer'] ?? '');
        if (empty($studentAnswer)) {
            throw new Exception("Please type a valid response.");
        }

        // Find active placeholder question (the last row with blank answer)
        $stmtAct = $db->prepare("SELECT answer_id, question_id FROM interview_answers WHERE attempt_id = ? ORDER BY answer_id DESC LIMIT 1");
        $stmtAct->execute([$attemptId]);
        $activeRow = $stmtAct->fetch();
        
        if (!$activeRow) {
            throw new Exception("Active question placeholder not found.");
        }

        // Update candidate response text
        $stmtUpd = $db->prepare("UPDATE interview_answers SET student_answer = ? WHERE answer_id = ?");
        $stmtUpd->execute([$studentAnswer, $activeRow['answer_id']]);

        // Get question text
        $stmtQText = $db->prepare("SELECT question FROM interview_questions WHERE question_id = ?");
        $stmtQText->execute([$activeRow['question_id']]);
        $lastQuestionText = $stmtQText->fetchColumn();

        // Increment index
        $stmtHist->execute([$attemptId]);
        $allAnswers = $stmtHist->fetchAll();
        $nextIndex = count($allAnswers) + 1;

        if ($nextIndex > (int)$attempt['total_questions']) {
            // End interview session
            $db->prepare("UPDATE interview_attempts SET is_completed = 1 WHERE attempt_id = ?")->execute([$attemptId]);
            echo json_encode(['success' => true, 'redirect' => 'interview-report.php?attempt_id=' . $attemptId]);
            exit;
        }

        // Request next question from InterviewService
        require_once(__DIR__ . '/../services/InterviewService.php');
        $nextQText = InterviewService::getMockChatNextQuestion(
            $db, 
            $studentId, 
            $attemptId, 
            $nextIndex, 
            (int)$attempt['total_questions'], 
            $lastQuestionText, 
            $studentAnswer
        );

        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
        }

        $db->beginTransaction();

        // Get setId
        $stmtSet = $db->prepare("SELECT set_id FROM interview_question_sets WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtSet->execute([$studentId]);
        $setId = (int)$stmtSet->fetchColumn();

        // Insert next question
        $stmtQ = $db->prepare("INSERT INTO interview_questions (set_id, category, question) VALUES (?, 'Mock Chat', ?)");
        $stmtQ->execute([$setId, $nextQText]);
        $nextQId = (int)$db->lastInsertId();

        // Insert next answer placeholder
        $db->prepare("INSERT INTO interview_answers (attempt_id, question_id, student_answer) VALUES (?, ?, '')")->execute([$attemptId, $nextQId]);

        $db->commit();

        echo json_encode([
            'success' => true, 
            'next_question' => $nextQText, 
            'progress' => $nextIndex . ' / ' . $attempt['total_questions']
        ]);
        exit;
    } catch (Exception $ex) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        exit;
    }
}

// Include HTML header for page rendering
$pageTitle = "Interactive AI Mock Interview";
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Student AI Quota status for Mock Interview
require_once(__DIR__ . '/../services/AIService.php');
$quotaInfo = AIService::checkStudentLimits($db, $studentId, 'mock_interview');
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="container-fluid py-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-comments text-primary"></i> Interactive AI Mock Interview</h1>
                <?php echo AIService::renderQuotaBadge($quotaInfo); ?>
            </div>

            <!-- Quota Status Banner / Live Countdown Timer -->
            <?php echo AIService::renderQuotaBanner($quotaInfo); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!$attempt): ?>
                <?php if (!$resumeId): ?>
                    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                        <div>
                            <i class="fa-solid fa-info-circle me-2 text-info"></i>
                            <strong>General Placement Mock Mode:</strong> Practicing standard campus placement rounds.
                        </div>
                        <a href="resume.php" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-file-arrow-up me-1"></i> Upload Resume for Custom Mock
                        </a>
                    </div>
                <?php endif; ?>

                <!-- INITIAL CONFIG SCREEN -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-user-tie me-1"></i> Start AI Recruiter Chat Session</h6>
                    </div>
                    <div class="card-body text-center py-4 py-md-5 px-3">
                        <i class="fa-solid fa-user-tie fa-3x text-primary mb-3"></i>
                        <h4 class="fw-bold mb-2">Welcome to Placement Mock Center</h4>
                        <p class="text-muted mb-4 small" style="max-width: 460px; margin-left: auto; margin-right: auto;">Our Placement AI simulates live corporate recruiter rounds. Choose your practice length to begin.</p>
                        
                        <form method="POST" class="d-inline-block text-start" style="max-width: 400px; width: 100%;" onsubmit="return <?php echo $quotaInfo['monthly_remaining'] > 0 ? "confirm('Start mock interview session? (Uses 1 of your " . $quotaInfo['monthly_limit'] . " monthly AI API calls)')" : "confirm('Your monthly AI limit is reached. The session will proceed with structured placement question tracks. Continue?')"; ?>;">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">Interview Session Length</label>
                                <select class="form-select" name="total_questions">
                                    <option value="5" selected>Quick Practice (5 questions)</option>
                                    <option value="10">Standard Interview (10 questions)</option>
                                    <option value="15">Full Length Assessment (15 questions)</option>
                                </select>
                            </div>
                            <button type="submit" name="start_mock" class="btn btn-primary btn-lg w-100 py-2 font-weight-bold shadow-sm">
                                <i class="fa-solid fa-circle-play me-1"></i> Start Session
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- ACTIVE CHAT LAYOUT -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-comments text-success"></i> Corporate Recruiter Chat</h6>
                        <span class="badge bg-secondary p-2" id="interviewProgressText">
                            <?php echo count($qaHistory); ?> / <?php echo $attempt['total_questions']; ?> Questions
                        </span>
                    </div>
                    <div class="card-body bg-light p-3 p-md-4" style="min-height: 280px; max-height: 520px; height: 50vh; overflow-y: auto;" id="chatBody">
                        
                        <!-- AI Introduction bubble -->
                        <div class="d-flex mb-3">
                            <div class="bg-primary text-white p-3 rounded shadow-sm" style="max-width:88%; border-top-left-radius:0px;">
                                <small class="d-block text-white-50 font-weight-bold mb-1">MAMCET AI Recruiter</small>
                                <span>Hello! I am your AI placement recruiter today. I will ask you a series of questions. Let's start.</span>
                            </div>
                        </div>

                        <!-- Render previous chat nodes -->
                        <?php foreach ($qaHistory as $idx => $qa): ?>
                            <!-- Question -->
                            <div class="d-flex mb-3">
                                <div class="bg-primary text-white p-3 rounded shadow-sm" style="max-width:88%; border-top-left-radius:0px;">
                                    <small class="d-block text-white-50 font-weight-bold mb-1">MAMCET AI Recruiter</small>
                                    <span><?php echo htmlspecialchars($qa['question']); ?></span>
                                </div>
                            </div>
                            <!-- Answer -->
                            <?php if (!empty($qa['student_answer'])): ?>
                                <div class="d-flex mb-3 justify-content-end">
                                    <div class="bg-white text-dark p-3 border rounded shadow-sm" style="max-width:88%; border-top-right-radius:0px;">
                                        <small class="d-block text-muted font-weight-bold mb-1">You</small>
                                        <span><?php echo htmlspecialchars($qa['student_answer']); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>

                    </div>
                    
                    <!-- Text box panel -->
                    <div class="card-footer bg-white p-2 p-md-3">
                        <div class="input-group">
                            <input type="text" class="form-control" id="studentResponseBox" placeholder="Type your response here..." aria-label="Candidate response">
                            <button class="btn btn-primary px-3 px-md-4 font-weight-bold" type="button" id="btnSendAnswer">
                                <i class="fa-solid fa-paper-plane me-1"></i> Send
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    const chatBody = $('#chatBody');
    if (chatBody.length) {
        chatBody.scrollTop(chatBody[0].scrollHeight);
    }

    $('#btnSendAnswer').on('click', function() {
        sendAnswerText();
    });

    $('#studentResponseBox').on('keypress', function(e) {
        if(e.which === 13) {
            sendAnswerText();
        }
    });

    function sendAnswerText() {
        const input = $('#studentResponseBox');
        const text = input.val().trim();
        const btn = $('#btnSendAnswer');
        if (text === '') return;

        // Disable input
        input.val('').prop('disabled', true);
        btn.prop('disabled', true);

        // Append student message bubble immediately
        chatBody.append(`
            <div class="d-flex mb-4 justify-content-end">
                <div class="bg-white text-dark p-3 border rounded shadow-sm" style="max-width:75%; border-top-right-radius:0px;">
                    <small class="d-block text-muted font-weight-bold mb-1">You</small>
                    <span>${escapeHtml(text)}</span>
                </div>
            </div>
        `);
        chatBody.scrollTop(chatBody[0].scrollHeight);

        // Append loader bubble for AI reply
        chatBody.append(`
            <div class="d-flex mb-4" id="aiChatLoaderBubble">
                <div class="bg-primary text-white p-3 rounded shadow-sm" style="max-width:75%; border-top-left-radius:0px;">
                    <small class="d-block text-white-50 font-weight-bold mb-1">MAMCET AI Recruiter</small>
                    <span><i class="fa-solid fa-spinner fa-spin me-1"></i> Typing...</span>
                </div>
            </div>
        `);
        chatBody.scrollTop(chatBody[0].scrollHeight);

        const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

        // Submit via AJAX
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                send_answer: 1,
                answer: text,
                csrf_token: csrfToken
            },
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            dataType: 'json',
            success: function(response) {
                $('#aiChatLoaderBubble').remove();
                input.prop('disabled', false).focus();
                btn.prop('disabled', false);

                if (response.success) {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        // Append next question
                        chatBody.append(`
                            <div class="d-flex mb-4">
                                <div class="bg-primary text-white p-3 rounded shadow-sm" style="max-width:75%; border-top-left-radius:0px;">
                                    <small class="d-block text-white-50 font-weight-bold mb-1">MAMCET AI Recruiter</small>
                                    <span>${escapeHtml(response.next_question)}</span>
                                </div>
                            </div>
                        `);
                        chatBody.scrollTop(chatBody[0].scrollHeight);
                        $('#interviewProgressText').text(response.progress + ' Questions');
                    }
                } else {
                    alert(response.message || "An error occurred while generating next question.");
                }
            },
            error: function(xhr, status, error) {
                $('#aiChatLoaderBubble').remove();
                input.prop('disabled', false).focus();
                btn.prop('disabled', false);
                
                let errorMsg = "Failed to connect to AI Recruiter. Please try again.";
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res && res.message) {
                        errorMsg = res.message;
                    }
                } catch(e) {}
                
                alert(errorMsg);
            }
        });
    }

    function escapeHtml(string) {
        return String(string).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
