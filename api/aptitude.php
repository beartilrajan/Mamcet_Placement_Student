<?php
// MAMCET Placement & Learning Portal - Aptitude & Streak AJAX API Endpoint

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../services/AptitudeService.php');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

$studentId = (int)$student['student_id'];
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'submit':
            // Verify request is POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method.');
            }

            // Verify CSRF token
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
                throw new Exception('Security validation failed (Invalid CSRF Token). Please reload and try again.');
            }

            $date = trim($_POST['date'] ?? date('Y-m-d'));
            if ($date !== date('Y-m-d')) {
                // Allow submissions for current date
                $date = date('Y-m-d');
            }

            $answers = $_POST['answers'] ?? [];
            if (!is_array($answers)) {
                $answers = json_decode($answers, true) ?: [];
            }

            $timeTaken = max(0, (int)($_POST['time_taken'] ?? 0));

            $result = AptitudeService::submitDailyChallenge($db, $studentId, $date, $answers, $timeTaken);

            // Log activity
            logActivity($db, $_SESSION['user_id'], 'APTITUDE_SUBMISSION', "Completed Daily Aptitude Challenge (Score: {$result['score']}/{$result['total_questions']}, Streak: {$result['current_streak']})");

            echo json_encode($result);
            break;

        case 'get_stats':
            $stats = AptitudeService::getStudentStreakStats($db, $studentId);
            $weekly = AptitudeService::getWeeklyTracker($db, $studentId);
            $categories = AptitudeService::getCategoryBreakdown($db, $studentId);

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'weekly' => $weekly,
                'categories' => $categories
            ]);
            break;

        case 'get_leaderboard':
            $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
            $leaderboard = AptitudeService::getLeaderboard($db, $limit);

            echo json_encode([
                'success' => true,
                'leaderboard' => $leaderboard
            ]);
            break;

        case 'get_past_challenge':
            $date = trim($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('Invalid date format.');
            }

            $progress = AptitudeService::getStudentDailyProgress($db, $studentId, $date);
            echo json_encode([
                'success' => true,
                'date' => $date,
                'progress' => $progress
            ]);
            break;

        default:
            throw new Exception('Unknown or missing action parameter.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
