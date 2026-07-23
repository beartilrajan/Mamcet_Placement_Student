<?php
// MAMCET Placement & Learning Portal - Student Resume Upload & Version Manager
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

// Load stage2 configs
$stage2Config = require(__DIR__ . '/../config/stage2.php');
$maxMb = $stage2Config['resume']['max_file_size_mb'] ?? 5;
$maxBytes = $maxMb * 1024 * 1024;

// 1. Fetch current student consent info
$stmtStud = $db->prepare("SELECT ai_consent, officer_visibility FROM students WHERE student_id = ?");
$stmtStud->execute([$studentId]);
$studentInfo = $stmtStud->fetch();

$hasConsented = (bool)($studentInfo['ai_consent'] ?? false);
$officerVisibility = (bool)($studentInfo['officer_visibility'] ?? true);

// 2. Handle Consent Submission
if (isset($_POST['submit_consent'])) {
    $visibility = isset($_POST['officer_visibility']) ? 1 : 0;
    
    $stmtUpd = $db->prepare("UPDATE students SET ai_consent = 1, consent_timestamp = NOW(), officer_visibility = ? WHERE student_id = ?");
    $stmtUpd->execute([$visibility, $studentId]);
    
    $hasConsented = true;
    $officerVisibility = (bool)$visibility;
    $message = "Thank you! Consent preferences successfully saved.";
}

// 3. Handle Visibility Toggle Updates
if (isset($_POST['update_visibility']) && $hasConsented) {
    $visibility = isset($_POST['officer_visibility']) ? 1 : 0;
    $stmtUpd = $db->prepare("UPDATE students SET officer_visibility = ? WHERE student_id = ?");
    $stmtUpd->execute([$visibility, $studentId]);
    $officerVisibility = (bool)$visibility;
    $message = "Officer visibility settings updated.";
}

// 4. Handle File Upload Request
if (isset($_POST['upload_resume']) && $hasConsented) {
    try {
        if (!isset($_FILES['resume_file']) || $_FILES['resume_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid resume document to upload.");
        }

        $file = $_FILES['resume_file'];
        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['pdf', 'docx'])) {
            throw new Exception("Only PDF and DOCX files are allowed.");
        }

        if ($file['size'] > $maxBytes) {
            throw new Exception("File exceeds maximum allowed size of $maxMb MB.");
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword'
        ];

        if (!in_array($mime, $allowedMimes)) {
            throw new Exception("Invalid file content type. Please upload a genuine PDF or Word document.");
        }

        // Setup secure upload path
        $uploadDir = __DIR__ . '/../assets/uploads/resumes/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Generate safe randomized filename
        $storedName = bin2hex(random_bytes(10)) . '.' . $ext;
        $destPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception("Failed to save file on the server. Please check folder permissions.");
        }

        // Begin DB Transaction
        $db->beginTransaction();
        
        // Reset previous resumes from being current
        $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);

        // Insert new resume record
        $stmtIns = $db->prepare("
            INSERT INTO resume_files (student_id, original_filename, stored_filename, file_path, file_type, file_size, is_current, status) 
            VALUES (?, ?, ?, ?, ?, ?, 1, 'uploaded')
        ");
        $stmtIns->execute([$studentId, $origName, $storedName, 'assets/uploads/resumes/' . $storedName, $ext, $file['size']]);
        $resumeId = (int)$db->lastInsertId();

        // Save initial version
        $db->prepare("INSERT INTO resume_versions (resume_id, version_number, file_path, file_size) VALUES (?, 1, ?, ?)")
           ->execute([$resumeId, 'assets/uploads/resumes/' . $storedName, $file['size']]);

        // Extract Text
        require_once(__DIR__ . '/../services/ResumeExtractionService.php');
        $extractedText = '';
        try {
            $extractedText = ResumeExtractionService::extractText($destPath, $ext);
        } catch (Exception $ex) {
            $extractedText = ''; // Allow blank to let student paste manually
        }

        $textHash = hash('sha256', $extractedText);
        $db->prepare("INSERT INTO resume_extracted_text (resume_id, extracted_text, text_hash) VALUES (?, ?, ?)")
           ->execute([$resumeId, $extractedText, $textHash]);

        $db->commit();
        $message = "Resume uploaded successfully! Please review the extracted text preview below.";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// 5. Handle Text Verification & Update
if (isset($_POST['save_extracted_text']) && $hasConsented) {
    try {
        $resumeId = (int)$_POST['resume_id'];
        $correctedText = trim($_POST['extracted_text_content']);

        if (empty($correctedText)) {
            throw new Exception("Resume text content cannot be blank.");
        }

        // Verify ownership
        $stmtVer = $db->prepare("SELECT student_id FROM resume_files WHERE resume_id = ?");
        $stmtVer->execute([$resumeId]);
        if ($stmtVer->fetchColumn() != $studentId) {
            throw new Exception("Access Denied: Owner mismatch.");
        }

        $textHash = hash('sha256', $correctedText);

        // Update extracted text
        $stmtUpdText = $db->prepare("UPDATE resume_extracted_text SET extracted_text = ?, text_hash = ? WHERE resume_id = ?");
        $stmtUpdText->execute([$correctedText, $textHash, $resumeId]);

        // Set status as validated/ready for analysis
        $db->prepare("UPDATE resume_files SET status = 'ready' WHERE resume_id = ?")->execute([$resumeId]);

        $message = "Resume text verified and updated. ATS analysis is now enabled!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 6. Handle Version Operations (Set Current / Delete)
if (isset($_GET['action']) && $hasConsented) {
    $action = $_GET['action'];
    $rId = (int)($_GET['resume_id'] ?? 0);

    try {
        // Verify ownership
        $stmtVer = $db->prepare("SELECT student_id, stored_filename FROM resume_files WHERE resume_id = ?");
        $stmtVer->execute([$rId]);
        $owner = $stmtVer->fetch();
        
        if (!$owner || $owner['student_id'] != $studentId) {
            throw new Exception("Resume record not found or access denied.");
        }

        if ($action === 'set_current') {
            $db->beginTransaction();
            $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);
            $db->prepare("UPDATE resume_files SET is_current = 1 WHERE resume_id = ?")->execute([$rId]);
            $db->commit();
            $message = "Current resume updated.";
        } elseif ($action === 'delete') {
            $filePath = __DIR__ . '/../assets/uploads/resumes/' . $owner['stored_filename'];
            
            $db->beginTransaction();
            $db->prepare("DELETE FROM resume_files WHERE resume_id = ?")->execute([$rId]);
            $db->commit();

            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            $message = "Resume version deleted.";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// 7. Query active resume details
$stmtCur = $db->prepare("
    SELECT rf.*, ret.extracted_text 
    FROM resume_files rf 
    LEFT JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1
");
$stmtCur->execute([$studentId]);
$currentResume = $stmtCur->fetch();

// 8. Query historical uploads list
$stmtHist = $db->prepare("SELECT * FROM resume_files WHERE student_id = ? ORDER BY uploaded_at DESC");
$stmtHist->execute([$studentId]);
$historyList = $stmtHist->fetchAll();
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-file-pdf text-danger"></i> My Resumes & Documents</h1>

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

            <?php if (!$hasConsented): ?>
                <!-- AI PROCESSING PRIVACY NOTICE -->
                <div class="card shadow border-left-danger">
                    <div class="card-header bg-danger text-white py-3">
                        <h5 class="m-0 font-weight-bold"><i class="fa-solid fa-shield-halved"></i> AI Processing Privacy & Consent Notice</h5>
                    </div>
                    <div class="card-body">
                        <p class="lead">Before uploading your resume and invoking automated ATS scoring or interview prep modules, please review our privacy parameters:</p>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="p-3 bg-light border-left-primary rounded mb-3">
                                    <h6><i class="fa-solid fa-user-shield text-primary"></i> Data Redaction Policy</h6>
                                    <small class="text-muted">For your privacy, your phone numbers, personal email addresses, and home contact details are automatically redacted before sending queries to Gemini or OpenAI services.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-light border-left-info rounded mb-3">
                                    <h6><i class="fa-solid fa-circle-info text-info"></i> Non-Guarantee of Results</h6>
                                    <small class="text-muted">ATS scoring, keyword recommendations, and feedback reports are simulated career preparation guidelines. They do not constitute official scoring or guarantee hiring decisions.</small>
                                </div>
                            </div>
                        </div>

                        <form method="POST">
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="officer_visibility" id="officerVisibilityCheck" checked>
                                <label class="form-check-label font-weight-bold" for="officerVisibilityCheck">
                                    Allow Placement Officers to view my detailed ATS feedback reports.
                                </label>
                                <small class="text-muted d-block">If unchecked, Placement Officers will only see that you have a resume uploaded, but cannot access qualitative scoring feedback.</small>
                            </div>

                            <button type="submit" name="submit_consent" class="btn btn-danger btn-icon-split px-3 py-2 font-weight-bold">
                                <span class="text">I Consent and Agree to Terms</span>
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- CONSENTED WORKFLOW -->
                <div class="row">
                    <!-- Left: Upload Form & Text Corrector -->
                    <div class="col-lg-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-primary text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-file-arrow-up"></i> Upload New Resume Document</h6>
                                <small>Max File Size: <?php echo $maxMb; ?>MB (PDF, DOCX)</small>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center">
                                    <div class="flex-grow-1 me-3">
                                        <input class="form-control" type="file" name="resume_file" accept=".pdf,.docx" required>
                                    </div>
                                    <button type="submit" name="upload_resume" class="btn btn-primary px-4">
                                        <i class="fa-solid fa-upload"></i> Upload
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Current Resume extracted text corrector -->
                        <?php if ($currentResume): ?>
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-dark text-white">
                                    <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-align-left"></i> Extracted Resume Text (Editable Corrector)</h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted"><i class="fa-solid fa-circle-question"></i> Verify and correct the text below if any formatting or characters were parsed incorrectly. This text is used for ATS analysis.</p>
                                    <form method="POST">
                                        <input type="hidden" name="resume_id" value="<?php echo $currentResume['resume_id']; ?>">
                                        <div class="mb-3">
                                            <textarea class="form-control font-monospace" name="extracted_text_content" rows="12" style="font-size:0.85rem;" required><?php echo htmlspecialchars($currentResume['extracted_text'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <button type="submit" name="save_extracted_text" class="btn btn-success font-weight-bold">
                                                <i class="fa-solid fa-circle-check"></i> Verify & Enable ATS Analysis
                                            </button>
                                            <?php if ($currentResume['status'] === 'ready'): ?>
                                                <a href="ats-analysis.php" class="btn btn-info font-weight-bold text-white">
                                                    <i class="fa-solid fa-chart-line"></i> Run ATS Analysis <i class="fa-solid fa-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card shadow mb-4">
                                <div class="card-body text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fa-3x mb-3 text-gray-300"></i>
                                    <h5>No Active Resume Found</h5>
                                    <p>Please upload a text-based PDF or DOCX file to configure your placement profile.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: History List & Preferences -->
                    <div class="col-lg-4">
                        <!-- Preferences Card -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-secondary text-white">
                                <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-user-lock"></i> Visibility Preferences</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="officer_visibility" id="officerVisibilityToggle" <?php echo $officerVisibility ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="officerVisibilityToggle">Officers can view ATS reports</label>
                                    </div>
                                    <button type="submit" name="update_visibility" class="btn btn-sm btn-secondary font-weight-bold">
                                        Save Options
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Historical versions list -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-light">
                                <h6 class="m-0 font-weight-bold text-dark"><i class="fa-solid fa-clock-rotate-left"></i> Resume Upload History</h6>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php if (empty($historyList)): ?>
                                        <li class="list-group-item text-center text-muted py-3">No history found.</li>
                                    <?php else: ?>
                                        <?php foreach ($historyList as $hist): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                                <div style="line-height:1.2; max-width: 70%;">
                                                    <span class="d-block text-truncate font-weight-bold text-dark" style="font-size:0.85rem;" title="<?php echo htmlspecialchars($hist['original_filename']); ?>">
                                                        <?php echo htmlspecialchars($hist['original_filename']); ?>
                                                    </span>
                                                    <small class="text-muted" style="font-size:0.75rem;">
                                                        Uploaded: <?php echo date('d M, h:i A', strtotime($hist['uploaded_at'])); ?>
                                                    </small>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($hist['is_current'] == 1): ?>
                                                        <span class="badge bg-success me-2">Current</span>
                                                    <?php else: ?>
                                                        <a href="?action=set_current&resume_id=<?php echo $hist['resume_id']; ?>" class="btn btn-sm btn-outline-primary me-2" title="Set as Current">
                                                            <i class="fa-solid fa-check"></i>
                                                        </a>
                                                        <a href="?action=delete&resume_id=<?php echo $hist['resume_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this version?')" title="Delete Version">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
