<?php
// MAMCET Placement & Learning Portal - Centralized Resume Upload & Background ATS Endpoint

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/ResumeExtractionService.php');
require_once(__DIR__ . '/../services/AIService.php');
require_once(__DIR__ . '/../services/ATSScoringService.php');

verifyCsrfRequest();

if (!isLoggedIn() || (int)$_SESSION['role_id'] !== ROLE_STUDENT) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

$studentId = (int)$student['student_id'];
$regNo = $student['registration_number'];

try {
    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Please select a PDF or DOCX resume file to upload.");
    }

    $file = $_FILES['resume'];
    $errCode = $file['error'];

    if ($errCode !== UPLOAD_ERR_OK) {
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("The uploaded file exceeds maximum allowed server size limit (5MB).");
        } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
            throw new Exception("File was only partially uploaded. Please try again.");
        } else {
            throw new Exception("File upload failed with error code: " . $errCode);
        }
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['pdf', 'docx'])) {
        throw new Exception("Invalid file format. Only PDF (.pdf) and DOCX (.docx) resume documents are allowed.");
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception("File size exceeds 5MB limit.");
    }

    $uploadDir = __DIR__ . '/../assets/uploads/resumes/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $storedName = 'resume_' . preg_replace('/[^a-zA-Z0-9]/', '', $regNo) . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception("Failed to save uploaded resume file to server disk.");
    }

    $relativePath = 'assets/uploads/resumes/' . $storedName;

    // 1. Extract Text from Document
    $extractedText = ResumeExtractionService::extractText($destPath, $ext);
    if (empty(trim($extractedText))) {
        // Provide placeholder text if PDF contains pure images so parser can proceed
        $extractedText = "Resume Document Uploaded (" . strtoupper($ext) . ")\nRegistration Number: " . $regNo;
    }

    // 2. Run Hybrid ATS Scoring (Rule-based + AI with fallback) BEFORE opening database transaction
    $analysisResults = ATSScoringService::analyze($extractedText, null, $studentId, $db);

    // Refresh DB connection after external AI request
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
    }

    // 3. Perform Fast Database Persistence inside atomic transaction
    $db->beginTransaction();

    // Mark previous current resumes as non-current
    $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);

    // Insert into resume_files
    $stmtInsFile = $db->prepare("
        INSERT INTO resume_files (student_id, filename, file_type, file_size, resume_path, is_current, status, uploaded_at)
        VALUES (?, ?, ?, ?, ?, 1, 'pending', NOW())
    ");
    $stmtInsFile->execute([
        $studentId,
        $origName,
        $ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        $file['size'],
        $relativePath
    ]);
    $resumeId = (int)$db->lastInsertId();

    // Insert into resume_extracted_text
    $stmtInsText = $db->prepare("
        INSERT INTO resume_extracted_text (resume_id, extracted_text, text_hash)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE extracted_text = VALUES(extracted_text), text_hash = VALUES(text_hash)
    ");
    $stmtInsText->execute([
        $resumeId,
        $extractedText,
        md5($extractedText)
    ]);

    // Save ATS Analysis
    $db->prepare("DELETE FROM resume_analysis WHERE resume_id = ?")->execute([$resumeId]);

    $stmtInsAn = $db->prepare("
        INSERT INTO resume_analysis (
            resume_id, overall_score, rule_score, ai_score, assessment_text, 
            strengths, critical_issues, missing_sections, missing_keywords, 
            grammar_suggestions, action_verb_suggestions, priority_actions, analyzed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsAn->execute([
        $resumeId,
        (int)$analysisResults['overall_score'],
        (int)$analysisResults['rule_score'],
        (int)$analysisResults['ai_score'],
        $analysisResults['assessment_text'],
        $analysisResults['strengths'],
        $analysisResults['critical_issues'],
        $analysisResults['missing_sections'],
        $analysisResults['missing_keywords'],
        $analysisResults['grammar_suggestions'],
        $analysisResults['action_verb_suggestions'],
        $analysisResults['priority_actions']
    ]);
    $analysisId = (int)$db->lastInsertId();

    // Insert section scores
    $stmtSecIns = $db->prepare("INSERT INTO resume_analysis_sections (analysis_id, section_name, score, max_score, feedback) VALUES (?, ?, ?, ?, ?)");
    foreach ($analysisResults['section_scores'] as $secName => $scoreDetails) {
        $stmtSecIns->execute([
            $analysisId,
            $secName,
            (int)$scoreDetails['score'],
            (int)$scoreDetails['max'],
            'Score estimated via automated section criteria'
        ]);
    }

    // Update status in resume_files & student_profiles
    $db->prepare("UPDATE resume_files SET last_analyzed_at = NOW(), status = 'analyzed' WHERE resume_id = ?")->execute([$resumeId]);
    $db->prepare("UPDATE student_profiles SET resume_path = ? WHERE student_id = ?")->execute([$relativePath, $studentId]);

    $db->commit();

    logActivity($db, $_SESSION['user_id'] ?? $student['user_id'], 'Resume Upload & ATS Scan', "Uploaded new resume '{$origName}' with ATS score {$analysisResults['overall_score']}/100.");

    echo json_encode([
        'success' => true,
        'message' => "Resume uploaded and scanned successfully! ATS Score: {$analysisResults['overall_score']}/100.",
        'overall_score' => $analysisResults['overall_score'],
        'resume_path' => $relativePath,
        'filename' => $origName
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
