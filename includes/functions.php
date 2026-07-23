<?php
// MAMCET Placement & Learning Portal - Global Helper Functions

require_once(__DIR__ . '/../config/constants.php');

/**
 * Escapes output for XSS protection
 */
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
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

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
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
    $completion = 0;
    
    // 1. Fetch personal details
    $stmt = $db->prepare("SELECT email, dob, alt_mobile_number, aadhar_number, parent_mobile, gender FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $personal = $stmt->fetch();
    if ($personal) {
        // Check if at least 4 of these personal fields are filled
        $filledCount = 0;
        foreach (['email', 'dob', 'alt_mobile_number', 'aadhar_number', 'parent_mobile', 'gender'] as $f) {
            if (!empty($personal[$f])) $filledCount++;
        }
        if ($filledCount >= 4) {
            $completion += 20;
        } else {
            $completion += (int)(20 * ($filledCount / 4));
        }
    }
    
    // 2. Fetch academic details
    $stmt = $db->prepare("SELECT tenth_percentage, twelfth_percentage, current_cgpa FROM student_academics WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $academics = $stmt->fetch();
    if ($academics) {
        $filledCount = 0;
        foreach (['tenth_percentage', 'twelfth_percentage', 'current_cgpa'] as $f) {
            if (!empty($academics[$f])) $filledCount++;
        }
        if ($filledCount === 3) {
            $completion += 20;
        } else {
            $completion += (int)(20 * ($filledCount / 3));
        }
    }
    
    // 3. Fetch profile fields (linkedin, skills, projects, internships, certifications, resume)
    $stmt = $db->prepare("SELECT skills, projects, internships, certifications, resume_path FROM student_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();
    
    if ($profile) {
        // Skills: 15%
        if (!empty($profile['skills']) && strlen(trim($profile['skills'])) > 5) {
            $completion += 15;
        }
        // Projects: 15%
        if (!empty($profile['projects']) && strlen(trim($profile['projects'])) > 10) {
            $completion += 15;
        }
        // Internships: 10%
        if (!empty($profile['internships']) && strlen(trim($profile['internships'])) > 10) {
            $completion += 10;
        }
        // Certifications: 10%
        if (!empty($profile['certifications']) && strlen(trim($profile['certifications'])) > 10) {
            $completion += 10;
        }
        // Resume: 10%
        if (!empty($profile['resume_path'])) {
            $completion += 10;
        }
    }
    
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
