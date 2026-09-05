<?php
// MAMCET Placement & Learning Portal - Update Student Profile API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn() || (int)$_SESSION['role_id'] !== ROLE_STUDENT) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

$studentId = $student['student_id'];
$userId = $_SESSION['user_id'];

// Sanitize inputs
$email = trim($_POST['email'] ?? '');
$altMobile = trim($_POST['alt_mobile_number'] ?? '');
$willingness = ($_POST['placement_willingness'] ?? 'Yes') === 'No' ? 'No' : 'Yes';

$linkedin = trim($_POST['linkedin_url'] ?? '');
$github = trim($_POST['github_url'] ?? '');
$portfolio = trim($_POST['portfolio_url'] ?? '');
$preferredRole = trim($_POST['preferred_job_role'] ?? '');
$preferredLocation = trim($_POST['preferred_location'] ?? '');
$address = trim($_POST['address'] ?? '');
$city = trim($_POST['city'] ?? '');
$district = trim($_POST['district'] ?? '');
$objective = trim($_POST['career_objective'] ?? '');
$skills = trim($_POST['skills'] ?? '');
$projects = trim($_POST['projects'] ?? '');
$internships = trim($_POST['internships'] ?? '');
$certifications = trim($_POST['certifications'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Personal email is required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
    exit;
}

try {
    $db->beginTransaction();
    
    // Fetch original details for update logging
    $stmtOrig = $db->prepare("
        SELECT s.email, s.alt_mobile_number, s.placement_willingness,
               sp.linkedin_url, sp.github_url, sp.portfolio_url, sp.address, sp.city, sp.district, sp.career_objective, sp.skills, sp.projects, sp.internships, sp.certifications, sp.resume_path
        FROM students s
        LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
        WHERE s.student_id = ?
    ");
    $stmtOrig->execute([$studentId]);
    $orig = $stmtOrig->fetch();
    
    // Helper function to log changes
    $logChange = function($field, $old, $new) use ($db, $studentId, $userId) {
        if ($old !== $new) {
            $stmt = $db->prepare("INSERT INTO student_update_logs (student_id, field_name, old_value, new_value, updated_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$studentId, $field, $old ?: '', $new ?: '', $userId]);
        }
    };
    
    // Process Resume PDF Upload or Removal if requested
    $resumePath = $orig['resume_path'] ?? '';
    
    if (isset($_POST['remove_resume']) && $_POST['remove_resume'] === '1') {
        if (!empty($resumePath)) {
            $fileName = basename($resumePath);
            $filePath1 = __DIR__ . '/../' . $resumePath;
            $filePath2 = __DIR__ . '/../assets/uploads/resumes/' . $fileName;
            if (file_exists($filePath1) && is_file($filePath1)) @unlink($filePath1);
            if (file_exists($filePath2) && is_file($filePath2)) @unlink($filePath2);
        }
        $logChange('resume_path', $resumePath, null);
        $resumePath = null;
        $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);
    }
    
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['resume'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $file['error']);
        }
        
        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        if ($mime !== 'application/pdf') {
            throw new Exception("Invalid file type. Only PDF resumes are accepted.");
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception("File size exceeds 5MB limit.");
        }
        
        // Save file
        $uploadDir = __DIR__ . '/../assets/uploads/resumes/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $newFilename = 'resume_' . preg_replace('/[^a-zA-Z0-9]/', '', $student['registration_number']) . '_' . time() . '.pdf';
        $destPath = $uploadDir . $newFilename;
        $relativePath = 'assets/uploads/resumes/' . $newFilename;
        
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            // Delete old file if exists
            if (!empty($resumePath)) {
                $oldFileName = basename($resumePath);
                $oldPath = $uploadDir . $oldFileName;
                if (file_exists($oldPath) && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $newResumePath = $relativePath;
            $logChange('resume_path', $resumePath, $newResumePath);
            $resumePath = $newResumePath;

            // Sync with resume_files
            $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);
            $stmtInsFile = $db->prepare("
                INSERT INTO resume_files (student_id, filename, file_type, file_size, resume_path, is_current, status, uploaded_at)
                VALUES (?, ?, ?, ?, ?, 1, 'ready', NOW())
            ");
            $stmtInsFile->execute([
                $studentId,
                $file['name'],
                'application/pdf',
                $file['size'],
                $relativePath
            ]);
        } else {
            throw new Exception("Unable to save resume upload file to disk.");
        }
    }
    
    // Log other changes
    $logChange('email', $orig['email'], $email);
    $logChange('alt_mobile_number', $orig['alt_mobile_number'], $altMobile);
    $logChange('placement_willingness', $orig['placement_willingness'], $willingness);
    
    $logChange('linkedin_url', $orig['linkedin_url'], $linkedin);
    $logChange('github_url', $orig['github_url'], $github);
    $logChange('portfolio_url', $orig['portfolio_url'], $portfolio);
    $logChange('address', $orig['address'], $address);
    $logChange('city', $orig['city'], $city);
    $logChange('district', $orig['district'], $district);
    $logChange('career_objective', $orig['career_objective'], $objective);
    $logChange('skills', $orig['skills'], $skills);
    $logChange('projects', $orig['projects'], $projects);
    $logChange('internships', $orig['internships'], $internships);
    $logChange('certifications', $orig['certifications'], $certifications);
    
    // Execute updates
    $stmtUpdStudent = $db->prepare("UPDATE students SET email = ?, alt_mobile_number = ?, placement_willingness = ? WHERE student_id = ?");
    $stmtUpdStudent->execute([$email, $altMobile, $willingness, $studentId]);
    
    $stmtUpdProfile = $db->prepare("
        UPDATE student_profiles 
        SET linkedin_url = ?, github_url = ?, portfolio_url = ?, preferred_job_role = ?, preferred_location = ?,
            address = ?, city = ?, district = ?, career_objective = ?, skills = ?, projects = ?, internships = ?, certifications = ?, resume_path = ?
        WHERE student_id = ?
    ");
    $stmtUpdProfile->execute([
        $linkedin, $github, $portfolio, $preferredRole, $preferredLocation,
        $address, $city, $district, $objective, $skills, $projects, $internships, $certifications, $resumePath,
        $studentId
    ]);

    // Handle GPA, CGPA and Academic Scores update
    if (isset($_POST['update_academics'])) {
        $tenthPct = isset($_POST['tenth_percentage']) && $_POST['tenth_percentage'] !== '' ? (float)$_POST['tenth_percentage'] : null;
        $twelfthPct = isset($_POST['twelfth_percentage']) && $_POST['twelfth_percentage'] !== '' ? (float)$_POST['twelfth_percentage'] : null;
        $diplomaPct = isset($_POST['diploma_percentage']) && $_POST['diploma_percentage'] !== '' ? (float)$_POST['diploma_percentage'] : null;

        $sem1 = isset($_POST['sem1_gpa']) && $_POST['sem1_gpa'] !== '' ? (float)$_POST['sem1_gpa'] : null;
        $sem2 = isset($_POST['sem2_gpa']) && $_POST['sem2_gpa'] !== '' ? (float)$_POST['sem2_gpa'] : null;
        $sem3 = isset($_POST['sem3_gpa']) && $_POST['sem3_gpa'] !== '' ? (float)$_POST['sem3_gpa'] : null;
        $sem4 = isset($_POST['sem4_gpa']) && $_POST['sem4_gpa'] !== '' ? (float)$_POST['sem4_gpa'] : null;
        $sem5 = isset($_POST['sem5_gpa']) && $_POST['sem5_gpa'] !== '' ? (float)$_POST['sem5_gpa'] : null;
        $sem6 = isset($_POST['sem6_gpa']) && $_POST['sem6_gpa'] !== '' ? (float)$_POST['sem6_gpa'] : null;
        $sem7 = isset($_POST['sem7_gpa']) && $_POST['sem7_gpa'] !== '' ? (float)$_POST['sem7_gpa'] : null;
        $sem8 = isset($_POST['sem8_gpa']) && $_POST['sem8_gpa'] !== '' ? (float)$_POST['sem8_gpa'] : null;

        $semGpas = array_filter([$sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8], function($val) {
            return $val !== null && $val > 0;
        });

        if (!empty($semGpas)) {
            $cgpa = round(array_sum($semGpas) / count($semGpas), 2);
        } else {
            $cgpa = isset($_POST['current_cgpa']) && $_POST['current_cgpa'] !== '' ? (float)$_POST['current_cgpa'] : null;
        }

        if ($cgpa !== null && ($cgpa < 0.0 || $cgpa > 10.0)) {
            throw new Exception("Overall CGPA must be between 0.00 and 10.00.");
        }

        $standingArrears = isset($_POST['standing_arrears']) ? max(0, (int)$_POST['standing_arrears']) : 0;
        $historyArrears = isset($_POST['history_of_arrears']) ? max(0, (int)$_POST['history_of_arrears']) : 0;

        $stmtAcad = $db->prepare("
            INSERT INTO student_academics 
            (student_id, current_cgpa, tenth_percentage, twelfth_percentage, diploma_percentage, sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa, sem7_gpa, sem8_gpa, standing_arrears, history_of_arrears)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            current_cgpa = VALUES(current_cgpa),
            tenth_percentage = VALUES(tenth_percentage),
            twelfth_percentage = VALUES(twelfth_percentage),
            diploma_percentage = VALUES(diploma_percentage),
            sem1_gpa = VALUES(sem1_gpa),
            sem2_gpa = VALUES(sem2_gpa),
            sem3_gpa = VALUES(sem3_gpa),
            sem4_gpa = VALUES(sem4_gpa),
            sem5_gpa = VALUES(sem5_gpa),
            sem6_gpa = VALUES(sem6_gpa),
            sem7_gpa = VALUES(sem7_gpa),
            sem8_gpa = VALUES(sem8_gpa),
            standing_arrears = VALUES(standing_arrears),
            history_of_arrears = VALUES(history_of_arrears)
        ");
        $stmtAcad->execute([
            $studentId, $cgpa, $tenthPct, $twelfthPct, $diplomaPct,
            $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8,
            $standingArrears, $historyArrears
        ]);
        
        $logChange('current_cgpa', '', (string)$cgpa);
    }
    
    $db->commit();
    
    // Log activity
    logActivity($db, $userId, 'Update Profile', 'Updated personal profile and academic GPA details.');

    
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
