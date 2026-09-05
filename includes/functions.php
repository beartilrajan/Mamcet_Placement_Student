<?php
// MAMCET Placement & Learning Portal - Global Helper Functions

require_once(__DIR__ . '/../config/constants.php');

/**
 * Escapes output for XSS protection
 */
function esc($string) {
    if (is_array($string)) {
        return array_map('esc', $string);
    }
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return trim($data ?? '');
}

/**
 * Flash messages manager
 */
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

function getFlash($type = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        if ($type !== null) {
            if (is_array($flash) && isset($flash['type']) && $flash['type'] === $type) {
                unset($_SESSION['flash']);
                return $flash['message'] ?? '';
            }
            return null;
        }
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Log System Activity
 */
function logActivity($db, $userId, $actionType, $description) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $actionType, $description, $ip]);
    } catch (Exception $e) {
        // Silently fail logging in case of database glitches to prevent site crash
    }
}

/**
 * Get human-readable Role Name by Role ID
 */
function getRoleName($roleId) {
    switch ((int)$roleId) {
        case ROLE_SUPER_ADMIN:
            return 'Super Admin';
        case ROLE_PLACEMENT_OFFICER:
            return 'Placement Officer';
        case ROLE_STUDENT:
            return 'Student';
        default:
            return 'User';
    }
}

/**
 * Format currency or packages (e.g. 4.5 LPA)
 */
function formatPackage($package) {
    if (empty($package)) return 'N/A';
    if (is_numeric($package)) {
        return $package . ' LPA';
    }
    return $package;
}

/**
 * Format bytes into human-readable file size (e.g. 512 B, 1.2 KB, 3.5 MB, 1.2 GB)
 *
 * @param int|float $bytes
 * @param int $precision
 * @return string
 */
function formatBytes($bytes, $precision = 1) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    $pow = max(0, min((int)$pow, count($units) - 1));
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Calculate dynamic Profile Completion Percentage
 * Personal Details: 20%
 * Academic Details: 20%
 * Skills: 15%
 * Projects: 15%
 * Internships: 10%
 * Certifications: 10%
 * Resume: 10%
 */
function calculateProfileCompletion($db, $studentId) {
    // Define all required fields and their sources.
    // Each field contributes equally to the total percentage.
    // The percentage is ALWAYS recalculated from current data,
    // so it naturally decreases when fields are cleared.

    $filledCount = 0;
    $totalFields = 12; // Total number of required fields

    // --- Section 1: Contact & Location (from students + student_profiles) ---
    $stmt = $db->prepare("
        SELECT s.email, s.alt_mobile_number, sp.address, sp.city, sp.district 
        FROM students s 
        LEFT JOIN student_profiles sp ON s.student_id = sp.student_id 
        WHERE s.student_id = ?
    ");
    $stmt->execute([$studentId]);
    $personal = $stmt->fetch();
    if ($personal) {
        // Required: email, address, city
        if (!empty(trim($personal['email'] ?? ''))) $filledCount++;
        if (!empty(trim($personal['address'] ?? ''))) $filledCount++;
        if (!empty(trim($personal['city'] ?? ''))) $filledCount++;
    }

    // --- Section 2: Academic Performance (from student_academics) ---
    $stmt = $db->prepare("SELECT tenth_percentage, twelfth_percentage, current_cgpa FROM student_academics WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $academics = $stmt->fetch();
    if ($academics) {
        // Required: 10th %, 12th %, CGPA
        if (!empty($academics['tenth_percentage']) && (float)$academics['tenth_percentage'] > 0) $filledCount++;
        if (!empty($academics['twelfth_percentage']) && (float)$academics['twelfth_percentage'] > 0) $filledCount++;
        if (!empty($academics['current_cgpa']) && (float)$academics['current_cgpa'] > 0) $filledCount++;
    }

    // --- Section 3 & 4: Profile details (from student_profiles) ---
    $stmt = $db->prepare("
        SELECT skills, projects, internships, certifications, resume_path, 
               career_objective, preferred_job_role
        FROM student_profiles WHERE student_id = ?
    ");
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();

    if ($profile) {
        // Required: skills, projects, internships, certifications, career_objective, preferred_job_role
        if (!empty(trim($profile['skills'] ?? ''))) $filledCount++;
        if (!empty(trim($profile['projects'] ?? ''))) $filledCount++;
        if (!empty(trim($profile['internships'] ?? ''))) $filledCount++;
        if (!empty(trim($profile['certifications'] ?? ''))) $filledCount++;
        if (!empty(trim($profile['career_objective'] ?? ''))) $filledCount++;
        if (!empty(trim($profile['preferred_job_role'] ?? ''))) $filledCount++;
    }

    // Calculate percentage: pure ratio of filled fields to total required
    $completion = (int)round(($filledCount / $totalFields) * 100);
    return min($completion, 100);
}

/**
 * Clean YouTube URLs and return video ID
 */
function getYouTubeVideoId($url) {
    if (empty($url)) return '';
    
    // Matches patterns:
    // - youtube.com/watch?v=VIDEO_ID
    // - youtu.be/VIDEO_ID
    // - youtube.com/embed/VIDEO_ID
    $videoId = '';
    $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i';
    
    if (preg_match($pattern, $url, $matches)) {
        $videoId = $matches[1];
    }
    return $videoId;
}

/**
 * Ensures announcement_reads table exists for tracking read notifications per user
 */
function ensureAnnouncementReadsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `announcement_reads` (
              `announcement_id` INT NOT NULL,
              `user_id` INT NOT NULL,
              `is_read` TINYINT(1) DEFAULT 1,
              `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`announcement_id`, `user_id`),
              FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
              FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Table already exists or creation failed silently
    }
}

/**
 * Ensures student_certifications table, columns, and upload directories exist
 */
function ensureCertificationsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `student_certifications` (
              `cert_id` INT AUTO_INCREMENT PRIMARY KEY,
              `student_id` INT NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `issuing_organization` VARCHAR(255) NOT NULL,
              `issue_date` DATE DEFAULT NULL,
              `credential_id` VARCHAR(100) DEFAULT NULL,
              `credential_url` VARCHAR(255) DEFAULT NULL,
              `skills` VARCHAR(255) DEFAULT NULL,
              `file_path` VARCHAR(255) DEFAULT NULL,
              `file_name` VARCHAR(255) DEFAULT NULL,
              `file_size` INT DEFAULT NULL,
              `status` ENUM('Pending Verification', 'Verified', 'Rejected') DEFAULT 'Pending Verification',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_student_cert_student_id` (`student_id`),
              FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $cols = $db->query("SHOW COLUMNS FROM `student_certifications` LIKE 'file_name'")->fetch();
        if (!$cols) {
            $db->exec("ALTER TABLE `student_certifications` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL AFTER `file_path`");
        }
        $colsSize = $db->query("SHOW COLUMNS FROM `student_certifications` LIKE 'file_size'")->fetch();
        if (!$colsSize) {
            $db->exec("ALTER TABLE `student_certifications` ADD COLUMN `file_size` INT DEFAULT NULL AFTER `file_name`");
        }
    } catch (Exception $e) {
        // Table or columns already configured
    }

    $uploadDir = __DIR__ . '/../assets/uploads/certifications/';
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
}

/**
 * Automatically synchronize batch-to-academic-session mappings.
 * Ensures active batches are mapped to their respective academic sessions with the correct year of study and semester.
 */
function syncBatchAcademicSessions($db) {
    try {
        // Fetch all academic sessions
        $sessions = $db->query("SELECT session_id, start_year, end_year, is_active FROM academic_sessions")->fetchAll(PDO::FETCH_ASSOC);
        // Fetch all batches
        $batches = $db->query("SELECT batch_id, admission_year, graduation_year, programme_duration, current_year_of_study FROM batches")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sessions) || empty($batches)) {
            return;
        }

        $upsertStmt = $db->prepare("
            INSERT INTO batch_academic_sessions (batch_id, session_id, year_of_study, semester)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE year_of_study = VALUES(year_of_study), semester = VALUES(semester)
        ");

        $updBatchStmt = $db->prepare("
            UPDATE batches 
            SET current_year_of_study = ? 
            WHERE batch_id = ?
        ");

        $updStudentStmt = $db->prepare("
            UPDATE students 
            SET year_of_study = ?, current_semester = ? 
            WHERE batch_id = ?
        ");

        // Identify currently active session
        $activeSessionId = null;
        if (isset($_SESSION['active_session_id']) && (int)$_SESSION['active_session_id'] > 0) {
            $activeSessionId = (int)$_SESSION['active_session_id'];
        } else {
            foreach ($sessions as $s) {
                if (!empty($s['is_active'])) {
                    $activeSessionId = (int)$s['session_id'];
                    break;
                }
            }
            if (!$activeSessionId && !empty($sessions)) {
                $activeSessionId = (int)$sessions[0]['session_id'];
            }
        }

        foreach ($sessions as $session) {
            $sessId = (int)$session['session_id'];
            $sessStart = (int)$session['start_year'];
            $sessEnd = (int)$session['end_year'];

            foreach ($batches as $batch) {
                $batchId = (int)$batch['batch_id'];
                $adm = (int)$batch['admission_year'];
                $grad = (int)$batch['graduation_year'];
                $duration = (int)($batch['programme_duration'] ?: 4);

                // Check if the batch was active during this academic session
                if ($adm <= $sessStart && $grad >= $sessEnd) {
                    $yearNumber = ($sessStart - $adm) + (4 - $duration) + 1;

                    if ($grad === $sessEnd || $yearNumber >= 4) {
                        $yearOfStudy = 'Final Year';
                        $semester = 7;
                    } elseif ($yearNumber === 3) {
                        $yearOfStudy = 'Third Year';
                        $semester = 5;
                    } elseif ($yearNumber === 2) {
                        $yearOfStudy = 'Second Year';
                        $semester = 3;
                    } else {
                        $yearOfStudy = 'First Year';
                        $semester = 1;
                    }

                    $upsertStmt->execute([$batchId, $sessId, $yearOfStudy, $semester]);

                    // Sync active session year & semester to batches and students
                    if ($activeSessionId !== null && $sessId === $activeSessionId) {
                        $updBatchStmt->execute([$yearOfStudy, $batchId]);
                        $updStudentStmt->execute([$yearOfStudy, $semester, $batchId]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Failed to sync batch academic sessions: " . $e->getMessage());
    }
}

/**
 * Automatically find or create an academic session from a raw string, batch, or year.
 * Examples of accepted inputs:
 *  - "2027-2028", "2027–2028", "2027/2028", "2027 - 2028"
 *  - "2027-28", "2027/28"
 *  - "2028" (graduation year) -> creates "2027–2028"
 *  - "2024-2028" (batch string) -> graduation year 2028 -> creates "2027–2028"
 *
 * @param PDO $db
 * @param string|int $rawSessionStr
 * @param string $description
 * @return array ['session_id' => int, 'session_name' => string, 'is_new' => bool]
 */
function ensureAcademicSessionExists($db, $rawSessionStr, $description = '') {
    $raw = trim((string)$rawSessionStr);
    if (empty($raw)) {
        return ['session_id' => 0, 'session_name' => '', 'is_new' => false];
    }

    $startYear = 0;
    $endYear = 0;

    // 1. Two 4-digit years (e.g. 2027-2028, 2027–2028, 2027/2028, 2027 - 2028)
    if (preg_match('/(\d{4})\s*[-–—\/]\s*(\d{4})/u', $raw, $m)) {
        $y1 = (int)$m[1];
        $y2 = (int)$m[2];
        if ($y2 === $y1 + 1) {
            $startYear = $y1;
            $endYear = $y2;
        } elseif ($y2 > $y1) {
            // It's a batch duration (e.g. 2024 - 2028). The final placement session is (end-1)–end
            $startYear = $y2 - 1;
            $endYear = $y2;
        }
    }
    // 2. 4-digit year followed by 2-digit year (e.g. 2027-28, 2027/28)
    elseif (preg_match('/(\d{4})\s*[-–—\/]\s*(\d{2})/u', $raw, $m)) {
        $y1 = (int)$m[1];
        $century = (int)substr((string)$y1, 0, 2);
        $y2 = ($century * 100) + (int)$m[2];
        if ($y2 === $y1 + 1) {
            $startYear = $y1;
            $endYear = $y2;
        }
    }
    // 3. Single 4-digit year (e.g. 2028 as graduation year or session year)
    elseif (preg_match('/^\d{4}$/', $raw)) {
        $yr = (int)$raw;
        if ($yr >= 2000 && $yr <= 2100) {
            $startYear = $yr - 1;
            $endYear = $yr;
        }
    }

    if ($startYear <= 0 || $endYear <= 0) {
        return ['session_id' => 0, 'session_name' => '', 'is_new' => false];
    }

    $canonicalName = "{$startYear}–{$endYear}";
    $altHyphenName = "{$startYear}-{$endYear}";

    // Check if session exists in database
    $stmt = $db->prepare("
        SELECT session_id, session_name 
        FROM academic_sessions 
        WHERE (start_year = ? AND end_year = ?) 
           OR session_name = ? 
           OR session_name = ?
        LIMIT 1
    ");
    $stmt->execute([$startYear, $endYear, $canonicalName, $altHyphenName]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return [
            'session_id' => (int)$existing['session_id'],
            'session_name' => $existing['session_name'],
            'is_new' => false
        ];
    }

    // Auto-create new academic session
    try {
        $startDate = "{$startYear}-06-01";
        $endDate = "{$endYear}-05-31";
        $desc = !empty($description) ? $description : "Academic Session for year {$startYear}-{$endYear} (Auto-created from student dataset)";

        $stmtIns = $db->prepare("
            INSERT INTO academic_sessions (session_name, start_year, end_year, start_date, end_date, is_active, status, description)
            VALUES (?, ?, ?, ?, ?, 0, 'active', ?)
        ");
        $stmtIns->execute([$canonicalName, $startYear, $endYear, $startDate, $endDate, $desc]);
        $newSessionId = (int)$db->lastInsertId();

        // Sync batch mappings for this newly created session
        syncBatchAcademicSessions($db);

        return [
            'session_id' => $newSessionId,
            'session_name' => $canonicalName,
            'is_new' => true
        ];
    } catch (Exception $e) {
        error_log("Failed to auto-create academic session: " . $e->getMessage());
        return ['session_id' => 0, 'session_name' => '', 'is_new' => false];
    }
}



