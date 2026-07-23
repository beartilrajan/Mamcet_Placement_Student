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
$willingness = $_POST['placement_willingness'] === 'No' ? 'No' : 'Yes';

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
    
    // Process Resume PDF Upload if provided
    $resumePath = $orig['resume_path'] ?? '';
    
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
        
        $newFilename = 'resume_' . $student['registration_number'] . '_' . time() . '.pdf';
        $destPath = $uploadDir . $newFilename;
        
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            // Delete old file if exists
            if (!empty($resumePath) && file_exists($resumePath)) {
                unlink($resumePath);
            }
            $newResumePath = $destPath;
            $logChange('resume_path', $resumePath, $newResumePath);
            $resumePath = $newResumePath;
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
    
    $db->commit();
    
    // Log activity
    logActivity($db, $userId, 'Update Profile', 'Updated personal portfolio profile details.');
    
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
