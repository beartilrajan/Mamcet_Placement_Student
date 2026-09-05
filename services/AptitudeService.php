<?php
// MAMCET Placement & Learning Portal - Aptitude & Streak Service

class AptitudeService {
    
    /**
     * Ensure database tables and basic category/question seeds exist
     */
    public static function ensureTables($db) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `aptitude_categories` (
                    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `category_name` VARCHAR(100) NOT NULL UNIQUE,
                    `slug` VARCHAR(100) NOT NULL UNIQUE,
                    `icon` VARCHAR(50) DEFAULT 'fa-brain',
                    `description` TEXT,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `aptitude_questions` (
                    `question_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `category_id` INT NOT NULL,
                    `question_text` TEXT NOT NULL,
                    `option_a` TEXT NOT NULL,
                    `option_b` TEXT NOT NULL,
                    `option_c` TEXT NOT NULL,
                    `option_d` TEXT NOT NULL,
                    `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
                    `explanation` TEXT NOT NULL,
                    `difficulty` ENUM('Easy', 'Medium', 'Hard') DEFAULT 'Medium',
                    `topic` VARCHAR(100) DEFAULT 'General Aptitude',
                    `company_tag` VARCHAR(255) DEFAULT 'TCS, Infosys, Wipro',
                    `is_active` TINYINT(1) DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_category` (`category_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `aptitude_daily_sets` (
                    `set_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `challenge_date` DATE NOT NULL UNIQUE,
                    `question_ids` JSON NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `aptitude_daily_progress` (
                    `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_id` INT NOT NULL,
                    `challenge_date` DATE NOT NULL,
                    `score` INT NOT NULL DEFAULT 0,
                    `total_questions` INT NOT NULL DEFAULT 5,
                    `time_taken_seconds` INT NOT NULL DEFAULT 0,
                    `is_completed` TINYINT(1) DEFAULT 1,
                    `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_student_date` (`student_id`, `challenge_date`),
                    INDEX `idx_date` (`challenge_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `aptitude_submissions` (
                    `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_id` INT NOT NULL,
                    `challenge_date` DATE NOT NULL,
                    `question_id` INT NOT NULL,
                    `selected_option` ENUM('A', 'B', 'C', 'D', 'SKIP') DEFAULT 'SKIP',
                    `is_correct` TINYINT(1) DEFAULT 0,
                    `time_spent_seconds` INT DEFAULT 0,
                    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_student_date_question` (`student_id`, `challenge_date`, `question_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `aptitude_streaks` (
                    `streak_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_id` INT NOT NULL UNIQUE,
                    `current_streak` INT NOT NULL DEFAULT 0,
                    `longest_streak` INT NOT NULL DEFAULT 0,
                    `last_submission_date` DATE DEFAULT NULL,
                    `total_days_completed` INT NOT NULL DEFAULT 0,
                    `total_questions_attempted` INT NOT NULL DEFAULT 0,
                    `total_correct` INT NOT NULL DEFAULT 0,
                    `accuracy_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            // Check if questions are populated, if not load migration script
            $count = (int)$db->query("SELECT COUNT(*) FROM aptitude_questions")->fetchColumn();
            if ($count === 0 && file_exists(__DIR__ . '/../database/migrations/aptitude.sql')) {
                $sql = file_get_contents(__DIR__ . '/../database/migrations/aptitude.sql');
                $db->exec($sql);
            }
        } catch (Exception $e) {
            error_log("AptitudeService::ensureTables error: " . $e->getMessage());
        }
    }

    /**
     * Get or create daily 5 questions for a specified date
     */
    public static function getDailyChallenge($db, $date = null, $includeAnswers = false) {
        self::ensureTables($db);
        if (!$date) {
            $date = date('Y-m-d');
        }

        // Check if daily set already exists
        $stmt = $db->prepare("SELECT * FROM aptitude_daily_sets WHERE challenge_date = ?");
        $stmt->execute([$date]);
        $dailySet = $stmt->fetch();

        $questionIds = [];
        if ($dailySet && !empty($dailySet['question_ids'])) {
            $questionIds = json_decode($dailySet['question_ids'], true);
        }

        // If no daily set generated for this date yet, select 5 balanced questions
        if (empty($questionIds) || !is_array($questionIds)) {
            $questionIds = self::generateDailyQuestionIds($db, $date);
            if (!empty($questionIds)) {
                $stmtInsert = $db->prepare("
                    INSERT INTO aptitude_daily_sets (challenge_date, question_ids)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE question_ids = VALUES(question_ids)
                ");
                $stmtInsert->execute([$date, json_encode($questionIds)]);
            }
        }

        if (empty($questionIds)) {
            return [];
        }

        // Fetch questions preserving order
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $fields = "q.question_id, q.category_id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.difficulty, q.topic, q.company_tag, c.category_name, c.icon as category_icon";
        if ($includeAnswers) {
            $fields .= ", q.correct_option, q.explanation";
        }

        $stmtQ = $db->prepare("
            SELECT $fields 
            FROM aptitude_questions q
            JOIN aptitude_categories c ON q.category_id = c.category_id
            WHERE q.question_id IN ($placeholders)
            ORDER BY FIELD(q.question_id, $placeholders)
        ");
        
        $params = array_merge($questionIds, $questionIds);
        $stmtQ->execute($params);
        return $stmtQ->fetchAll();
    }

    /**
     * Pick 5 diverse questions based on daily date seed (2 Quant, 2 Logical, 1 Verbal/DI)
     */
    private static function generateDailyQuestionIds($db, $date) {
        $seed = abs(crc32($date));
        mt_srand($seed);

        $selectedIds = [];

        // 1. Fetch 2 Quantitative questions (category 1)
        $stmtQuant = $db->query("SELECT question_id FROM aptitude_questions WHERE category_id = 1 AND is_active = 1");
        $quantPool = $stmtQuant->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($quantPool)) {
            shuffle($quantPool);
            $selectedIds = array_merge($selectedIds, array_slice($quantPool, 0, 2));
        }

        // 2. Fetch 2 Logical Reasoning questions (category 2)
        $stmtLogic = $db->query("SELECT question_id FROM aptitude_questions WHERE category_id = 2 AND is_active = 1");
        $logicPool = $stmtLogic->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($logicPool)) {
            shuffle($logicPool);
            $selectedIds = array_merge($selectedIds, array_slice($logicPool, 0, 2));
        }

        // 3. Fetch 1 Verbal or Data Interpretation question (category 3 or 4)
        $stmtOther = $db->query("SELECT question_id FROM aptitude_questions WHERE category_id IN (3, 4) AND is_active = 1");
        $otherPool = $stmtOther->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($otherPool)) {
            shuffle($otherPool);
            $selectedIds = array_merge($selectedIds, array_slice($otherPool, 0, 1));
        }

        // Fallback: If less than 5, pick remaining randomly from all questions
        if (count($selectedIds) < 5) {
            $stmtAll = $db->query("SELECT question_id FROM aptitude_questions WHERE is_active = 1");
            $allPool = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
            $remaining = array_diff($allPool, $selectedIds);
            shuffle($remaining);
            $needed = 5 - count($selectedIds);
            $selectedIds = array_merge($selectedIds, array_slice($remaining, 0, $needed));
        }

        return array_slice($selectedIds, 0, 5);
    }

    /**
     * Get student's progress and submissions for a given date
     */
    public static function getStudentDailyProgress($db, $studentId, $date = null) {
        self::ensureTables($db);
        if (!$date) {
            $date = date('Y-m-d');
        }

        $stmt = $db->prepare("SELECT * FROM aptitude_daily_progress WHERE student_id = ? AND challenge_date = ?");
        $stmt->execute([$studentId, $date]);
        $progress = $stmt->fetch();

        if (!$progress) {
            return [
                'is_completed' => false,
                'score' => 0,
                'total_questions' => 5,
                'time_taken_seconds' => 0,
                'completed_at' => null,
                'submissions' => []
            ];
        }

        // Fetch submissions with question details
        $stmtSub = $db->prepare("
            SELECT s.*, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option, q.explanation, q.difficulty, q.topic, q.company_tag, c.category_name, c.icon as category_icon
            FROM aptitude_submissions s
            JOIN aptitude_questions q ON s.question_id = q.question_id
            JOIN aptitude_categories c ON q.category_id = c.category_id
            WHERE s.student_id = ? AND s.challenge_date = ?
            ORDER BY s.submission_id ASC
        ");
        $stmtSub->execute([$studentId, $date]);
        $submissions = $stmtSub->fetchAll();

        return [
            'is_completed' => (bool)$progress['is_completed'],
            'score' => (int)$progress['score'],
            'total_questions' => (int)$progress['total_questions'],
            'time_taken_seconds' => (int)$progress['time_taken_seconds'],
            'completed_at' => $progress['completed_at'],
            'submissions' => $submissions
        ];
    }

    /**
     * Submit answers for today's daily 5 questions challenge
     */
    public static function submitDailyChallenge($db, $studentId, $date, $answers, $timeTakenSeconds = 0) {
        self::ensureTables($db);
        if (!$date) {
            $date = date('Y-m-d');
        }

        // Fetch actual questions for today with answers
        $dailyQuestions = self::getDailyChallenge($db, $date, true);
        if (empty($dailyQuestions)) {
            throw new Exception("Today's challenge questions are not available.");
        }

        $totalQuestions = count($dailyQuestions);
        $score = 0;
        $processedSubmissions = [];

        $db->beginTransaction();
        try {
            foreach ($dailyQuestions as $q) {
                $qId = (int)$q['question_id'];
                $correctOpt = strtoupper(trim($q['correct_option']));
                $userSelected = isset($answers[$qId]) ? strtoupper(trim($answers[$qId])) : 'SKIP';
                if (!in_array($userSelected, ['A', 'B', 'C', 'D', 'SKIP'])) {
                    $userSelected = 'SKIP';
                }

                $isCorrect = ($userSelected !== 'SKIP' && $userSelected === $correctOpt) ? 1 : 0;
                if ($isCorrect) {
                    $score++;
                }

                // Insert/update submission record
                $stmtSub = $db->prepare("
                    INSERT INTO aptitude_submissions 
                    (student_id, challenge_date, question_id, selected_option, is_correct, time_spent_seconds)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        selected_option = VALUES(selected_option),
                        is_correct = VALUES(is_correct),
                        time_spent_seconds = VALUES(time_spent_seconds),
                        submitted_at = CURRENT_TIMESTAMP
                ");
                $stmtSub->execute([$studentId, $date, $qId, $userSelected, $isCorrect, (int)($timeTakenSeconds / max(1, $totalQuestions))]);

                $processedSubmissions[] = [
                    'question_id' => $qId,
                    'question_text' => $q['question_text'],
                    'option_a' => $q['option_a'],
                    'option_b' => $q['option_b'],
                    'option_c' => $q['option_c'],
                    'option_d' => $q['option_d'],
                    'selected_option' => $userSelected,
                    'correct_option' => $correctOpt,
                    'is_correct' => (bool)$isCorrect,
                    'explanation' => $q['explanation'],
                    'category_name' => $q['category_name'],
                    'difficulty' => $q['difficulty'],
                    'topic' => $q['topic']
                ];
            }

            // Record daily progress
            $stmtProg = $db->prepare("
                INSERT INTO aptitude_daily_progress 
                (student_id, challenge_date, score, total_questions, time_taken_seconds, is_completed, completed_at)
                VALUES (?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                    score = VALUES(score),
                    total_questions = VALUES(total_questions),
                    time_taken_seconds = VALUES(time_taken_seconds),
                    is_completed = 1,
                    completed_at = CURRENT_TIMESTAMP
            ");
            $stmtProg->execute([$studentId, $date, $score, $totalQuestions, (int)$timeTakenSeconds]);

            // Update Streaks
            $streakResult = self::updateStreakOnSubmission($db, $studentId, $date, $score, $totalQuestions);

            $db->commit();

            return [
                'success' => true,
                'score' => $score,
                'total_questions' => $totalQuestions,
                'accuracy' => round(($score / $totalQuestions) * 100, 1),
                'time_taken_seconds' => $timeTakenSeconds,
                'current_streak' => $streakResult['current_streak'],
                'longest_streak' => $streakResult['longest_streak'],
                'is_new_streak_record' => $streakResult['is_new_record'],
                'submissions' => $processedSubmissions
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Calculate and update streak table
     */
    private static function updateStreakOnSubmission($db, $studentId, $todayDate, $todayScore, $todayTotal) {
        $stmt = $db->prepare("SELECT * FROM aptitude_streaks WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $streak = $stmt->fetch();

        $yesterday = date('Y-m-d', strtotime($todayDate . ' -1 day'));

        $currentStreak = 1;
        $longestStreak = 1;
        $totalDays = 1;
        $totalAttempted = $todayTotal;
        $totalCorrect = $todayScore;
        $isNewRecord = false;

        if ($streak) {
            $lastDate = $streak['last_submission_date'];
            $existingCurrent = (int)$streak['current_streak'];
            $existingLongest = (int)$streak['longest_streak'];
            $totalDays = (int)$streak['total_days_completed'];
            $totalAttempted = (int)$streak['total_questions_attempted'] + $todayTotal;
            $totalCorrect = (int)$streak['total_correct'] + $todayScore;

            if ($lastDate === $todayDate) {
                // Already submitted today; keep current streak
                $currentStreak = max(1, $existingCurrent);
            } elseif ($lastDate === $yesterday) {
                // Consecutive day! Increment streak
                $currentStreak = $existingCurrent + 1;
                $totalDays++;
            } else {
                // Streak was broken; reset to 1
                $currentStreak = 1;
                $totalDays++;
            }

            $longestStreak = max($existingLongest, $currentStreak);
            if ($currentStreak > $existingLongest) {
                $isNewRecord = true;
            }

            $accuracy = $totalAttempted > 0 ? round(($totalCorrect / $totalAttempted) * 100, 2) : 0.00;

            $stmtUpdate = $db->prepare("
                UPDATE aptitude_streaks 
                SET current_streak = ?,
                    longest_streak = ?,
                    last_submission_date = ?,
                    total_days_completed = ?,
                    total_questions_attempted = ?,
                    total_correct = ?,
                    accuracy_percentage = ?
                WHERE student_id = ?
            ");
            $stmtUpdate->execute([
                $currentStreak,
                $longestStreak,
                $todayDate,
                $totalDays,
                $totalAttempted,
                $totalCorrect,
                $accuracy,
                $studentId
            ]);
        } else {
            $accuracy = $totalAttempted > 0 ? round(($totalCorrect / $totalAttempted) * 100, 2) : 0.00;
            $stmtInsert = $db->prepare("
                INSERT INTO aptitude_streaks 
                (student_id, current_streak, longest_streak, last_submission_date, total_days_completed, total_questions_attempted, total_correct, accuracy_percentage)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $studentId,
                $currentStreak,
                $longestStreak,
                $todayDate,
                $totalDays,
                $totalAttempted,
                $totalCorrect,
                $accuracy
            ]);
            $isNewRecord = true;
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'is_new_record' => $isNewRecord
        ];
    }

    /**
     * Get live streak stats for a student
     */
    public static function getStudentStreakStats($db, $studentId) {
        self::ensureTables($db);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $stmt = $db->prepare("SELECT * FROM aptitude_streaks WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $streak = $stmt->fetch();

        // Check if student completed today's challenge
        $stmtToday = $db->prepare("SELECT score, time_taken_seconds, completed_at FROM aptitude_daily_progress WHERE student_id = ? AND challenge_date = ? AND is_completed = 1");
        $stmtToday->execute([$studentId, $today]);
        $todayProg = $stmtToday->fetch();
        $completedToday = (bool)$todayProg;

        if (!$streak) {
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'total_days_completed' => 0,
                'total_questions_attempted' => 0,
                'total_correct' => 0,
                'accuracy_percentage' => 0.00,
                'last_submission_date' => null,
                'completed_today' => $completedToday,
                'today_score' => $todayProg ? (int)$todayProg['score'] : null,
                'today_time' => $todayProg ? (int)$todayProg['time_taken_seconds'] : null,
                'streak_status' => $completedToday ? 'active' : 'pending'
            ];
        }

        $lastDate = $streak['last_submission_date'];
        $rawCurrent = (int)$streak['current_streak'];

        // Determine if streak is alive:
        // If submitted today: active
        // If submitted yesterday: active (waiting for today's submission to continue)
        // If submitted before yesterday and not today: streak broken (0 until today is done)
        $effectiveCurrentStreak = $rawCurrent;
        $streakStatus = 'pending';

        if ($completedToday) {
            $effectiveCurrentStreak = $rawCurrent;
            $streakStatus = 'active';
        } elseif ($lastDate === $yesterday) {
            $effectiveCurrentStreak = $rawCurrent; // Still on streak if completed today
            $streakStatus = 'at_risk';
        } else {
            $effectiveCurrentStreak = 0; // Streak broken
            $streakStatus = 'broken';
        }

        return [
            'current_streak' => $effectiveCurrentStreak,
            'longest_streak' => (int)$streak['longest_streak'],
            'total_days_completed' => (int)$streak['total_days_completed'],
            'total_questions_attempted' => (int)$streak['total_questions_attempted'],
            'total_correct' => (int)$streak['total_correct'],
            'accuracy_percentage' => (float)$streak['accuracy_percentage'],
            'last_submission_date' => $lastDate,
            'completed_today' => $completedToday,
            'today_score' => $todayProg ? (int)$todayProg['score'] : null,
            'today_time' => $todayProg ? (int)$todayProg['time_taken_seconds'] : null,
            'streak_status' => $streakStatus
        ];
    }

    /**
     * Get 7-day activity array for weekly tracker bar
     */
    public static function getWeeklyTracker($db, $studentId) {
        self::ensureTables($db);
        $weekly = [];
        // Past 7 days including today
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime($d));
            $isToday = ($d === date('Y-m-d'));

            $stmt = $db->prepare("SELECT score, is_completed FROM aptitude_daily_progress WHERE student_id = ? AND challenge_date = ?");
            $stmt->execute([$studentId, $d]);
            $row = $stmt->fetch();

            $weekly[] = [
                'date' => $d,
                'day_name' => $dayName,
                'day_number' => date('j', strtotime($d)),
                'is_today' => $isToday,
                'is_completed' => $row ? (bool)$row['is_completed'] : false,
                'score' => $row ? (int)$row['score'] : 0
            ];
        }
        return $weekly;
    }

    /**
     * Get 30-day activity map for calendar grid
     */
    public static function getMonthlyActivityMap($db, $studentId) {
        self::ensureTables($db);
        $stmt = $db->prepare("
            SELECT challenge_date, score, is_completed, time_taken_seconds
            FROM aptitude_daily_progress 
            WHERE student_id = ? AND challenge_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY challenge_date ASC
        ");
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll();

        $activity = [];
        foreach ($rows as $r) {
            $activity[$r['challenge_date']] = [
                'score' => (int)$r['score'],
                'completed' => (bool)$r['is_completed'],
                'time_taken' => (int)$r['time_taken_seconds']
            ];
        }
        return $activity;
    }

    /**
     * Get performance breakdown by category for student
     */
    public static function getCategoryBreakdown($db, $studentId) {
        self::ensureTables($db);
        $stmt = $db->prepare("
            SELECT 
                c.category_id,
                c.category_name,
                c.slug,
                c.icon,
                COUNT(s.submission_id) as total_attempted,
                SUM(CASE WHEN s.is_correct = 1 THEN 1 ELSE 0 END) as total_correct
            FROM aptitude_categories c
            LEFT JOIN aptitude_questions q ON c.category_id = q.category_id
            LEFT JOIN aptitude_submissions s ON q.question_id = s.question_id AND s.student_id = ?
            GROUP BY c.category_id, c.category_name, c.slug, c.icon
            ORDER BY c.category_id ASC
        ");
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll();

        $breakdown = [];
        foreach ($rows as $row) {
            $attempted = (int)$row['total_attempted'];
            $correct = (int)$row['total_correct'];
            $accuracy = $attempted > 0 ? round(($correct / $attempted) * 100, 1) : 0;

            $breakdown[] = [
                'category_id' => $row['category_id'],
                'category_name' => $row['category_name'],
                'icon' => $row['icon'],
                'attempted' => $attempted,
                'correct' => $correct,
                'accuracy' => $accuracy
            ];
        }
        return $breakdown;
    }

    /**
     * Get Leaderboard (Top Streaks & Top Solvers)
     */
    public static function getLeaderboard($db, $limit = 10) {
        self::ensureTables($db);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $stmt = $db->prepare("
            SELECT 
                s.student_id,
                s.student_name,
                s.registration_number,
                d.dept_code,
                ast.current_streak,
                ast.longest_streak,
                ast.total_days_completed,
                ast.total_correct,
                ast.accuracy_percentage,
                ast.last_submission_date,
                (CASE 
                    WHEN ast.last_submission_date = ? OR ast.last_submission_date = ? THEN ast.current_streak 
                    ELSE 0 
                END) as active_streak
            FROM aptitude_streaks ast
            JOIN students s ON ast.student_id = s.student_id
            JOIN departments d ON s.dept_id = d.dept_id
            WHERE ast.total_days_completed > 0
            ORDER BY active_streak DESC, ast.total_correct DESC, ast.accuracy_percentage DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $today, PDO::PARAM_STR);
        $stmt->bindValue(2, $yesterday, PDO::PARAM_STR);
        $stmt->bindValue(3, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
