<?php
// MAMCET Placement & Learning Portal - Student Detail Profile View Page

$pageTitle = 'Student Detail Profile';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);
require_once(__DIR__ . '/../services/StudentService.php');
require_once(__DIR__ . '/../services/AptitudeService.php');
require_once(__DIR__ . '/../services/ResumeExtractionService.php');
require_once(__DIR__ . '/../services/ATSScoringService.php');
require_once(__DIR__ . '/../services/AIService.php');

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$studentId = (int)($_GET['student_id'] ?? $_GET['id'] ?? 0);
if ($studentId <= 0) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student ID is required.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Handle Note Insertion / Placement Overrides
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'add_note') {
        $noteText = trim($_POST['note_content'] ?? '');
        if (empty($noteText)) {
            $error = 'Note content cannot be empty.';
        } else {
            try {
                // Fetch officer_id
                $stmt = $db->prepare("SELECT officer_id FROM placement_officers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $officerId = $stmt->fetchColumn();
                
                if ($officerId) {
                    $stmtIns = $db->prepare("INSERT INTO officer_notes (student_id, officer_id, note_content) VALUES (?, ?, ?)");
                    $stmtIns->execute([$studentId, $officerId, $noteText]);
                    $success = 'Note added successfully.';
                    logActivity($db, $_SESSION['user_id'], 'Add Student Note', "Added officer note for student ID $studentId");
                } else {
                    $error = 'Only designated placement officers can add notes.';
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'placement_override') {
        $placementStatus = $_POST['placement_status'] ?? 'Unplaced';
        $placementWillingness = $_POST['placement_willingness'] ?? 'Yes';
        
        try {
            $stmtUpd = $db->prepare("UPDATE students SET placement_status = ?, placement_willingness = ? WHERE student_id = ?");
            $stmtUpd->execute([$placementStatus, $placementWillingness, $studentId]);
            
            $success = 'Placement status overridden successfully.';
            logActivity($db, $_SESSION['user_id'], 'Override Placement', "Updated status of student ID $studentId to $placementStatus ($placementWillingness)");
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } elseif ($action === 'add_placement') {
        $companyName = trim($_POST['company_name'] ?? '');
        $packageLpa = (float)($_POST['package_lpa'] ?? 0);
        $placedDate = $_POST['placed_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($companyName) || $packageLpa <= 0) {
            $error = 'Company name and package (LPA) are required.';
        } else {
            try {
                $offerLetterPath = null;
                if (isset($_FILES['offer_letter']) && $_FILES['offer_letter']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['offer_letter'];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    
                    $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
                    if (!in_array($mime, $allowedMimes)) {
                        throw new Exception("Invalid file type. Only PDF and Images (PNG/JPG) are accepted.");
                    }
                    
                    if ($file['size'] > 5 * 1024 * 1024) {
                        throw new Exception("Offer letter file size exceeds 5MB limit.");
                    }
                    
                    $uploadDir = __DIR__ . '/../assets/uploads/offers/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $safeName = 'offer_' . $studentId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file['name']);
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                        $offerLetterPath = 'assets/uploads/offers/' . $safeName;
                    } else {
                        throw new Exception("Failed to upload the file to disk.");
                    }
                } else {
                    throw new Exception("Offer letter file upload is required.");
                }
                
                $db->beginTransaction();
                
                // 1. Insert into student_placements
                $stmtIns = $db->prepare("INSERT INTO student_placements (student_id, company_name, package_lpa, offer_letter_path, placed_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$studentId, $companyName, $packageLpa, $offerLetterPath, $placedDate ?: null, $notes]);
                
                // 2. Automatically override main student status to Placed
                $stmtUpd = $db->prepare("UPDATE students SET placement_status = 'Placed' WHERE student_id = ?");
                $stmtUpd->execute([$studentId]);
                
                $db->commit();
                $success = 'Placement offer recorded successfully. Student status synchronized to Placed.';
                logActivity($db, $_SESSION['user_id'], 'Record Student Placement', "Recorded job offer from $companyName ($packageLpa LPA) for student ID $studentId");
                
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'delete_placement') {
        $placementId = (int)($_POST['placement_id'] ?? 0);
        if ($placementId > 0) {
            try {
                $db->beginTransaction();
                
                // Get offer letter path
                $stmtPath = $db->prepare("SELECT offer_letter_path FROM student_placements WHERE placement_id = ? AND student_id = ?");
                $stmtPath->execute([$placementId, $studentId]);
                $filePath = $stmtPath->fetchColumn();
                
                // Delete from DB
                $stmtDel = $db->prepare("DELETE FROM student_placements WHERE placement_id = ? AND student_id = ?");
                $stmtDel->execute([$placementId, $studentId]);
                
                // Delete file from disk
                if ($filePath && file_exists(__DIR__ . '/../' . $filePath)) {
                    unlink(__DIR__ . '/../' . $filePath);
                }
                
                // Check if any placement offers remaining
                $stmtRem = $db->prepare("SELECT COUNT(*) FROM student_placements WHERE student_id = ?");
                $stmtRem->execute([$studentId]);
                $remainingCount = (int)$stmtRem->fetchColumn();
                
                if ($remainingCount === 0) {
                    // Update status back to Unplaced
                    $stmtUpd = $db->prepare("UPDATE students SET placement_status = 'Unplaced' WHERE student_id = ?");
                    $stmtUpd->execute([$studentId]);
                    $success = 'Placement record deleted. Student status synchronized back to Unplaced.';
                } else {
                    $success = 'Placement record deleted successfully.';
                }
                
                $db->commit();
                logActivity($db, $_SESSION['user_id'], 'Delete Student Placement', "Deleted placement offer ID $placementId for student ID $studentId");
                
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_academics') {
        $cgpa = !empty($_POST['current_cgpa']) ? (float)$_POST['current_cgpa'] : null;
        $standingArrears = isset($_POST['standing_arrears']) ? (int)$_POST['standing_arrears'] : 0;
        $historyArrears = isset($_POST['history_of_arrears']) ? (int)$_POST['history_of_arrears'] : 0;
        
        $tenth = !empty($_POST['tenth_percentage']) ? (float)$_POST['tenth_percentage'] : null;
        $twelfth = !empty($_POST['twelfth_percentage']) ? (float)$_POST['twelfth_percentage'] : null;
        $diploma = !empty($_POST['diploma_percentage']) ? (float)$_POST['diploma_percentage'] : null;
        
        $sem1 = !empty($_POST['sem1_gpa']) ? (float)$_POST['sem1_gpa'] : null;
        $sem2 = !empty($_POST['sem2_gpa']) ? (float)$_POST['sem2_gpa'] : null;
        $sem3 = !empty($_POST['sem3_gpa']) ? (float)$_POST['sem3_gpa'] : null;
        $sem4 = !empty($_POST['sem4_gpa']) ? (float)$_POST['sem4_gpa'] : null;
        $sem5 = !empty($_POST['sem5_gpa']) ? (float)$_POST['sem5_gpa'] : null;
        $sem6 = !empty($_POST['sem6_gpa']) ? (float)$_POST['sem6_gpa'] : null;
        $sem7 = !empty($_POST['sem7_gpa']) ? (float)$_POST['sem7_gpa'] : null;
        $sem8 = !empty($_POST['sem8_gpa']) ? (float)$_POST['sem8_gpa'] : null;
        
        try {
            $stmtChk = $db->prepare("SELECT academic_id FROM student_academics WHERE student_id = ?");
            $stmtChk->execute([$studentId]);
            $exists = $stmtChk->fetch();
            
            if ($exists) {
                $stmtSa = $db->prepare("
                    UPDATE student_academics 
                    SET current_cgpa = ?, standing_arrears = ?, history_of_arrears = ?, 
                        tenth_percentage = ?, twelfth_percentage = ?, diploma_percentage = ?, 
                        sem1_gpa = ?, sem2_gpa = ?, sem3_gpa = ?, sem4_gpa = ?, sem5_gpa = ?, sem6_gpa = ?, sem7_gpa = ?, sem8_gpa = ? 
                    WHERE student_id = ?
                ");
                $stmtSa->execute([
                    $cgpa, $standingArrears, $historyArrears, 
                    $tenth, $twelfth, $diploma, 
                    $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8, 
                    $studentId
                ]);
            } else {
                $stmtSa = $db->prepare("
                    INSERT INTO student_academics 
                    (student_id, current_cgpa, standing_arrears, history_of_arrears, 
                     tenth_percentage, twelfth_percentage, diploma_percentage, 
                     sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa, sem7_gpa, sem8_gpa) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtSa->execute([
                    $studentId, $cgpa, $standingArrears, $historyArrears, 
                    $tenth, $twelfth, $diploma, 
                    $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, $sem7, $sem8
                ]);
            }
            
            $success = 'Academic record updated successfully.';
            logActivity($db, $_SESSION['user_id'], 'Update Student Academics', "Updated academic records (CGPA: $cgpa, Arrears: $standingArrears) for student ID $studentId");
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } elseif ($action === 'delete_student') {
        try {
            $studentService = new StudentService($db);
            $res = $studentService->deleteStudent($studentId, (int)$_SESSION['user_id']);
            if ($res['success']) {
                setFlash('success', $res['message']);
                header('Location: students.php');
                exit;
            } else {
                $error = $res['message'];
            }
        } catch (Exception $e) {
            $error = 'Failed to delete student: ' . $e->getMessage();
        }
    } elseif ($action === 'run_ats_scan') {
        try {
            // Check active resume from resume_files or student_profiles
            $stmtResQ = $db->prepare("
                SELECT rf.*, ret.extracted_text 
                FROM resume_files rf 
                LEFT JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
                WHERE rf.student_id = ? AND rf.is_current = 1 
                LIMIT 1
            ");
            $stmtResQ->execute([$studentId]);
            $targetResume = $stmtResQ->fetch();

            // If not found in resume_files but found in student_profiles, register it
            if (!$targetResume) {
                $stmtSpRes = $db->prepare("SELECT resume_path, skills, projects, certifications FROM student_profiles WHERE student_id = ?");
                $stmtSpRes->execute([$studentId]);
                $spData = $stmtSpRes->fetch();

                if (!empty($spData['resume_path']) && file_exists(__DIR__ . '/../' . $spData['resume_path'])) {
                    $origFilename = basename($spData['resume_path']);
                    $ext = strtolower(pathinfo($origFilename, PATHINFO_EXTENSION));
                    $fSize = filesize(__DIR__ . '/../' . $spData['resume_path']);

                    $db->beginTransaction();
                    $stmtInsRf = $db->prepare("
                        INSERT INTO resume_files (student_id, filename, file_type, file_size, resume_path, is_current, status, uploaded_at)
                        VALUES (?, ?, ?, ?, ?, 1, 'uploaded', NOW())
                    ");
                    $stmtInsRf->execute([$studentId, $origFilename, $ext === 'pdf' ? 'application/pdf' : 'application/docx', $fSize, $spData['resume_path']]);
                    $targetResumeId = (int)$db->lastInsertId();

                    $extractedText = '';
                    try {
                        $extractedText = ResumeExtractionService::extractText(__DIR__ . '/../' . $spData['resume_path'], $ext);
                    } catch (Exception $ex) {
                        $extractedText = "Resume Document: " . $origFilename . "\nSkills: " . ($spData['skills'] ?? '') . "\nProjects: " . ($spData['projects'] ?? '');
                    }

                    $db->prepare("INSERT INTO resume_extracted_text (resume_id, extracted_text, text_hash) VALUES (?, ?, ?)")
                       ->execute([$targetResumeId, $extractedText, md5($extractedText)]);
                    $db->commit();

                    $targetResume = [
                        'resume_id' => $targetResumeId,
                        'resume_path' => $spData['resume_path'],
                        'filename' => $origFilename,
                        'extracted_text' => $extractedText
                    ];
                }
            }

            if (!$targetResume) {
                throw new Exception("No active resume document found on this student's profile to scan.");
            }

            $resumeId = (int)$targetResume['resume_id'];
            $text = trim($targetResume['extracted_text'] ?? '');

            if (empty($text) && !empty($targetResume['resume_path'])) {
                $fPath = __DIR__ . '/../' . $targetResume['resume_path'];
                if (file_exists($fPath)) {
                    $ext = strtolower(pathinfo($fPath, PATHINFO_EXTENSION));
                    $text = ResumeExtractionService::extractText($fPath, $ext);
                }
            }

            if (empty($text)) {
                $text = "Resume Document for Candidate ID " . $studentId;
            }

            // Run ATS scoring analysis
            $analysisResults = ATSScoringService::analyze($text, null, $studentId, $db);

            $db->beginTransaction();
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

            // Insert section-wise scores
            $stmtSecIns = $db->prepare("INSERT INTO resume_analysis_sections (analysis_id, section_name, score, max_score, feedback) VALUES (?, ?, ?, ?, ?)");
            foreach ($analysisResults['section_scores'] as $secName => $scoreDetails) {
                $stmtSecIns->execute([
                    $analysisId,
                    $secName,
                    (int)$scoreDetails['score'],
                    (int)$scoreDetails['max'],
                    'Assessed via automated criteria'
                ]);
            }

            $db->prepare("UPDATE resume_files SET last_analyzed_at = NOW(), status = 'analyzed' WHERE resume_id = ?")->execute([$resumeId]);
            $db->commit();

            $success = "ATS resume analysis completed successfully! Overall Score: " . $analysisResults['overall_score'] . "/100.";
            logActivity($db, $_SESSION['user_id'], 'Officer ATS Scan', "Executed ATS scan for student ID $studentId (Score: {$analysisResults['overall_score']}/100)");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'ATS scan failed: ' . $e->getMessage();
        }
    }
}

// Fetch student data with all related departments, batches, sections, academics, and profiles
$stmt = $db->prepare("
    SELECT s.*, d.dept_name, d.dept_code, b.batch_name, b.admission_year AS batch_admission_year, b.graduation_year AS batch_graduation_year, b.programme_duration, pr.programme_name, pr.duration_years, sec.section_name,
           sa.tenth_percentage, sa.twelfth_percentage, sa.diploma_percentage, sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears,
           sa.sem1_gpa, sa.sem2_gpa, sa.sem3_gpa, sa.sem4_gpa, sa.sem5_gpa, sa.sem6_gpa, sa.sem7_gpa, sa.sem8_gpa,
           sp.linkedin_url, sp.github_url, sp.portfolio_url, sp.skills, sp.projects, sp.internships, sp.certifications, sp.address, sp.city, sp.district, sp.resume_path, sp.career_objective, sp.preferred_job_role, sp.preferred_location
    FROM students s
    JOIN departments d ON s.dept_id = d.dept_id
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN programmes pr ON b.programme_id = pr.programme_id
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
    WHERE s.student_id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student not found.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Determine total semesters based on programme/batch duration or batch years
$durationYears = 4;
if (!empty($student['programme_duration']) && (int)$student['programme_duration'] > 0) {
    $durationYears = (int)$student['programme_duration'];
} elseif (!empty($student['duration_years']) && (int)$student['duration_years'] > 0) {
    $durationYears = (int)$student['duration_years'];
} elseif (!empty($student['admission_year']) && !empty($student['graduation_year']) && ((int)$student['graduation_year'] > (int)$student['admission_year'])) {
    $durationYears = (int)$student['graduation_year'] - (int)$student['admission_year'];
} elseif (!empty($student['batch_admission_year']) && !empty($student['batch_graduation_year']) && ((int)$student['batch_graduation_year'] > (int)$student['batch_admission_year'])) {
    $durationYears = (int)$student['batch_graduation_year'] - (int)$student['batch_admission_year'];
}
$totalSemesters = max(2, min(8, $durationYears * 2));

// If student has non-null marks recorded in higher semesters, ensure they are shown
for ($s = 8; $s > $totalSemesters; $s--) {
    if (isset($student["sem{$s}_gpa"]) && $student["sem{$s}_gpa"] !== null && $student["sem{$s}_gpa"] !== '') {
        $totalSemesters = $s;
        break;
    }
}

// Compute Profile completion
$profileCompletion = calculateProfileCompletion($db, $studentId);

// -------------------------------------------------------------
// 1. APTITUDE STREAK & PERFORMANCE METRICS
// -------------------------------------------------------------
$aptitudeStreak = AptitudeService::getStudentStreakStats($db, $studentId);
$categoryBreakdown = AptitudeService::getCategoryBreakdown($db, $studentId);
$weeklyTracker = AptitudeService::getWeeklyTracker($db, $studentId);

// -------------------------------------------------------------
// 2. RESUME FILE & ATS RESUME SCORE ANALYSIS
// -------------------------------------------------------------
$stmtRes = $db->prepare("
    SELECT rf.*, ret.extracted_text, ret.text_hash 
    FROM resume_files rf 
    LEFT JOIN resume_extracted_text ret ON rf.resume_id = ret.resume_id 
    WHERE rf.student_id = ? AND rf.is_current = 1 
    LIMIT 1
");
$stmtRes->execute([$studentId]);
$activeResume = $stmtRes->fetch();

// Fallback to student_profiles.resume_path if no record in resume_files
$hasResume = (bool)($activeResume || !empty($student['resume_path']));
$activeResumePath = $activeResume['resume_path'] ?? $student['resume_path'] ?? null;
$activeResumeFilename = $activeResume['filename'] ?? ($activeResumePath ? basename($activeResumePath) : 'Resume Document');

$latestAnalysis = null;
$sectionScores = [];

if ($activeResume) {
    $resumeId = (int)$activeResume['resume_id'];
    $stmtAn = $db->prepare("SELECT * FROM resume_analysis WHERE resume_id = ? ORDER BY analyzed_at DESC LIMIT 1");
    $stmtAn->execute([$resumeId]);
    $latestAnalysis = $stmtAn->fetch();

    if ($latestAnalysis) {
        $stmtSec = $db->prepare("SELECT * FROM resume_analysis_sections WHERE analysis_id = ?");
        $stmtSec->execute([$latestAnalysis['analysis_id']]);
        $sectionScores = $stmtSec->fetchAll();
    }
}

// ATS Score tiers and styling
$atsScore = ($latestAnalysis && !is_null($latestAnalysis['overall_score'])) ? (int)$latestAnalysis['overall_score'] : -1;
$atsTierClass = 'tier-missing';
$atsTierLabel = 'Not Scanned';
$atsBadgeBg = 'bg-secondary';
$atsColor = '#64748b';

if ($atsScore >= 90) {
    $atsTierClass = 'tier-excellent';
    $atsTierLabel = 'Excellent';
    $atsBadgeBg = 'bg-success';
    $atsColor = '#10b981';
} elseif ($atsScore >= 75) {
    $atsTierClass = 'tier-verygood';
    $atsTierLabel = 'Very Good';
    $atsBadgeBg = 'bg-info text-dark';
    $atsColor = '#06b6d4';
} elseif ($atsScore >= 60) {
    $atsTierClass = 'tier-good';
    $atsTierLabel = 'Good';
    $atsBadgeBg = 'bg-primary';
    $atsColor = '#2563eb';
} elseif ($atsScore >= 40) {
    $atsTierClass = 'tier-basic';
    $atsTierLabel = 'Basic';
    $atsBadgeBg = 'bg-warning text-dark';
    $atsColor = '#f59e0b';
} elseif ($atsScore >= 0) {
    $atsTierClass = 'tier-needswork';
    $atsTierLabel = 'Needs Work';
    $atsBadgeBg = 'bg-danger';
    $atsColor = '#ef4444';
}

// Query resume version history
$stmtHist = $db->prepare("SELECT * FROM resume_files WHERE student_id = ? ORDER BY uploaded_at DESC");
$stmtHist->execute([$studentId]);
$resumeHistory = $stmtHist->fetchAll();

// -------------------------------------------------------------
// 3. LEETCODE PROFILE DATA
// -------------------------------------------------------------
$lcUsername = '';
try {
    $stmtLC = $db->prepare("SELECT leetcode_username FROM leetcode_profiles WHERE student_id = ?");
    $stmtLC->execute([$studentId]);
    $lcProfile = $stmtLC->fetch();
    $lcUsername = $lcProfile ? $lcProfile['leetcode_username'] : '';
} catch (Exception $e) {
    $lcUsername = '';
}

// -------------------------------------------------------------
// 4. OFFICER NOTES, COURSES, PLACEMENTS & LOGS
// -------------------------------------------------------------
$stmtNotes = $db->prepare("
    SELECT n.*, o.name AS officer_name 
    FROM officer_notes n 
    JOIN placement_officers o ON n.officer_id = o.officer_id 
    WHERE n.student_id = ? 
    ORDER BY n.created_at DESC
");
$stmtNotes->execute([$studentId]);
$notes = $stmtNotes->fetchAll();

$stmtCourses = $db->prepare("
    SELECT c.course_title, c.course_code, scp.completion_percentage, scp.status 
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN student_course_progress scp ON c.course_id = scp.course_id AND scp.student_id = ?
    WHERE ca.session_id = ? AND (
        c.course_id IN (SELECT course_id FROM course_departments WHERE dept_id = ?)
        OR c.course_id IN (SELECT course_id FROM course_batches WHERE batch_id = ?)
    )
    GROUP BY c.course_id
");
$stmtCourses->execute([$studentId, $activeSessionId, $student['dept_id'], $student['batch_id']]);
$coursesAssigned = $stmtCourses->fetchAll();

$stmtPl = $db->prepare("SELECT * FROM student_placements WHERE student_id = ? ORDER BY placed_date DESC");
$stmtPl->execute([$studentId]);
$placements = $stmtPl->fetchAll();

$stmtLogs = $db->prepare("
    SELECT l.*, u.username AS updated_by_user 
    FROM student_update_logs l 
    JOIN users u ON l.updated_by = u.user_id 
    WHERE l.student_id = ? 
    ORDER BY l.updated_at DESC 
    LIMIT 10
");
$stmtLogs->execute([$studentId]);
$updateLogs = $stmtLogs->fetchAll();

// Certifications & Credentials
ensureCertificationsTable($db);
$stmtCerts = $db->prepare("
    SELECT * 
    FROM student_certifications 
    WHERE student_id = ? 
    ORDER BY created_at DESC, cert_id DESC
");
$stmtCerts->execute([$studentId]);
$studentCertificates = $stmtCerts->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalCertificatesCount = count($studentCertificates);

// Placement Drive Eligibility calculation
$cgpaVal = $student['current_cgpa'] !== null ? (float)$student['current_cgpa'] : null;
$standingArr = (int)($student['standing_arrears'] ?? 0);
$isEligibleForDrives = ($cgpaVal !== null && $cgpaVal >= 6.00 && $standingArr === 0 && $student['placement_willingness'] === 'Yes');
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container py-3">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- TOP BREADCRUMB & ACTION BAR -->
        <div class="student-profile-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="students.php" class="btn btn-sm btn-light border px-3 fw-semibold text-secondary shadow-sm">
                    <i class="fa-solid fa-arrow-left me-1.5 text-primary"></i> Back to Roster
                </a>
                <span class="text-muted d-none d-sm-inline">•</span>
                <nav aria-label="breadcrumb" class="d-none d-md-block">
                    <ol class="breadcrumb mb-0 py-0" style="font-size: 0.85rem;">
                        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php" class="text-decoration-none text-muted">Students</a></li>
                        <li class="breadcrumb-item active text-dark fw-bold" aria-current="page"><?php echo esc($student['student_name']); ?></li>
                    </ol>
                </nav>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#editAcademicsModal">
                    <i class="fa-solid fa-graduation-cap me-1.5"></i> Edit Academics
                </button>
                <button type="button" class="btn btn-sm btn-light border fw-semibold text-secondary shadow-sm" onclick="window.print();">
                    <i class="fa-solid fa-print me-1.5"></i> Print Summary
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger fw-semibold shadow-sm" onclick="confirmDeleteStudent(<?php echo $studentId; ?>, '<?php echo esc(addslashes($student['student_name'])); ?>', '<?php echo esc(addslashes($student['registration_number'])); ?>')">
                    <i class="fa-solid fa-trash-can me-1.5"></i> Delete Record
                </button>
            </div>
        </div>

        <!-- Hidden Delete Form -->
        <form id="deleteStudentForm" action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" style="display:none;">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="delete_student">
        </form>

        <div class="row g-4">
            <!-- LEFT COLUMN: STUDENT IDENTITY & CONTROL CENTER -->
            <div class="col-xl-4 col-lg-5">
                
                <!-- Main Identity Hero Card -->
                <div class="profile-hero-card mb-4">
                    <div class="profile-hero-banner"></div>
                    
                    <div class="card-body text-center pt-0 px-4 pb-4">
                        <div class="profile-hero-avatar-wrapper">
                            <div class="profile-hero-avatar">
                                <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                            </div>
                            <span class="profile-status-indicator online" title="Active Student"></span>
                        </div>

                        <h4 class="fw-bold mb-1 text-dark mt-2" style="font-family: var(--font-heading); letter-spacing: -0.02em;">
                            <?php echo esc($student['student_name']); ?>
                        </h4>

                        <!-- Copyable Registration Number -->
                        <div class="mb-3">
                            <span class="profile-copy-badge" onclick="copyToClipboard('<?php echo esc($student['registration_number']); ?>', 'Registration Number');" title="Click to copy Reg. No">
                                <span><?php echo esc($student['registration_number']); ?></span>
                                <i class="fa-regular fa-copy text-muted small"></i>
                            </span>
                        </div>

                        <!-- Dynamic Status Badges Strip -->
                        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                            <!-- Placement Status Badge -->
                            <?php
                            $stClass = 'status-unplaced';
                            $stIcon = 'fa-hourglass-half';
                            if ($student['placement_status'] === 'Placed') {
                                $stClass = 'status-placed';
                                $stIcon = 'fa-circle-check';
                            } elseif ($student['placement_status'] === 'Higher Studies') {
                                $stClass = 'status-higher-studies';
                                $stIcon = 'fa-user-graduate';
                            } elseif ($student['placement_status'] === 'Entrepreneurship') {
                                $stClass = 'status-entrepreneur';
                                $stIcon = 'fa-lightbulb';
                            }
                            ?>
                            <span class="profile-status-pill <?php echo $stClass; ?>" title="Placement Status">
                                <i class="fa-solid <?php echo $stIcon; ?>"></i> <?php echo esc($student['placement_status'] ?: 'Unplaced'); ?>
                            </span>

                            <!-- Willingness Badge -->
                            <span class="badge <?php echo $student['placement_willingness'] === 'Yes' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle'; ?> px-2.5 py-1.5 rounded-pill" style="font-size:0.75rem;">
                                <i class="fa-solid fa-handshake-simple me-1"></i> <?php echo $student['placement_willingness'] === 'Yes' ? 'Placement Willing' : 'Not Willing'; ?>
                            </span>

                            <!-- Aptitude Streak Badge -->
                            <?php if ($aptitudeStreak['current_streak'] > 0): ?>
                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2.5 py-1.5 rounded-pill fw-bold" title="Daily Aptitude Streak">
                                    <i class="fa-solid fa-fire text-warning me-1"></i> <?php echo $aptitudeStreak['current_streak']; ?>d Streak
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border px-2.5 py-1.5 rounded-pill" title="Daily Aptitude Streak">
                                    <i class="fa-solid fa-fire text-secondary me-1"></i> 0d Streak
                                </span>
                            <?php endif; ?>

                            <!-- ATS Score Pill -->
                            <?php if ($atsScore >= 0): ?>
                                <span class="ats-score-pill <?php echo $atsTierClass; ?>" style="font-size: 0.75rem;" title="ATS Score">
                                    <i class="fa-solid fa-gauge-high me-1"></i> ATS <?php echo $atsScore; ?>/100
                                </span>
                            <?php elseif ($hasResume): ?>
                                <span class="ats-score-pill tier-missing" style="font-size: 0.75rem;" title="ATS Score Pending Scan">
                                    <i class="fa-solid fa-file-waveform me-1"></i> ATS Pending
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 rounded-pill" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-file-circle-xmark me-1"></i> No Resume
                                </span>
                            <?php endif; ?>

                            <!-- Certificates Badge -->
                            <a href="#certificates" class="badge px-2.5 py-1.5 rounded-pill fw-bold text-decoration-none" style="background:#f0fdfa; color:#0d9488; border:1px solid #ccfbf1; font-size: 0.75rem;" title="View Uploaded Certificates">
                                <i class="fa-solid fa-certificate me-1"></i> <?php echo $totalCertificatesCount; ?> Cert<?php echo $totalCertificatesCount === 1 ? '' : 's'; ?>
                            </a>
                        </div>

                        <!-- Profile Completeness Bar Box -->
                        <div class="profile-meter-box text-start mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1.5">
                                <span class="small fw-bold text-dark">Profile Completion</span>
                                <span class="badge <?php echo $profileCompletion >= 80 ? 'bg-success' : ($profileCompletion >= 50 ? 'bg-primary' : 'bg-warning text-dark'); ?> rounded-pill">
                                    <?php echo $profileCompletion; ?>%
                                </span>
                            </div>
                            <div class="profile-meter-progress">
                                <div class="progress-bar <?php echo $profileCompletion >= 80 ? 'bg-success' : ($profileCompletion >= 50 ? 'bg-primary' : 'bg-warning'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $profileCompletion; ?>%" 
                                     aria-valuenow="<?php echo $profileCompletion; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                        </div>

                        <hr class="my-3 text-muted opacity-25">

                        <!-- Academic & Contact Meta Grid -->
                        <div class="profile-meta-group text-start">
                            <!-- Department & Programme -->
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Programme & Dept</span>
                                    <span class="profile-meta-val"><?php echo esc($student['programme_name'] ?? 'B.Tech') . ' - ' . esc($student['dept_name'] ?? $student['dept_code']); ?></span>
                                </div>
                            </div>

                            <!-- Batch & Section -->
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon">
                                    <i class="fa-solid fa-users-rectangle"></i>
                                </div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Batch & Section</span>
                                    <span class="profile-meta-val"><?php echo esc($student['batch_name']) . ' (Section ' . esc($student['section_name'] ?? 'A') . ')'; ?></span>
                                </div>
                            </div>

                            <!-- Official Mobile -->
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon">
                                    <i class="fa-solid fa-phone"></i>
                                </div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Official Mobile</span>
                                    <a href="tel:<?php echo esc($student['mobile_number']); ?>" class="profile-meta-val text-decoration-none text-dark hover-primary">
                                        <?php echo esc($student['mobile_number']); ?>
                                    </a>
                                </div>
                                <span class="profile-meta-action" onclick="copyToClipboard('<?php echo esc($student['mobile_number']); ?>', 'Mobile Number');" title="Copy Number">
                                    <i class="fa-regular fa-copy"></i>
                                </span>
                            </div>

                            <!-- College Email -->
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">College Email</span>
                                    <a href="mailto:<?php echo esc($student['college_email']); ?>" class="profile-meta-val text-decoration-none text-dark hover-primary" style="font-size:0.82rem;">
                                        <?php echo esc($student['college_email'] ?? 'Not Set'); ?>
                                    </a>
                                </div>
                                <?php if (!empty($student['college_email'])): ?>
                                    <span class="profile-meta-action" onclick="copyToClipboard('<?php echo esc($student['college_email']); ?>', 'College Email');" title="Copy Email">
                                        <i class="fa-regular fa-copy"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Professional & Social Links Strip -->
                        <div class="mt-4 pt-2 text-start">
                            <span class="profile-meta-label mb-2">Professional Profiles</span>
                            <div class="profile-social-strip">
                                <!-- LinkedIn -->
                                <?php if (!empty($student['linkedin_url'])): ?>
                                    <a href="<?php echo esc($student['linkedin_url']); ?>" target="_blank" class="profile-social-btn linkedin" title="LinkedIn Profile">
                                        <i class="fa-brands fa-linkedin text-primary"></i>
                                        <span>LinkedIn</span>
                                    </a>
                                <?php else: ?>
                                    <div class="profile-social-btn disabled" title="LinkedIn not linked">
                                        <i class="fa-brands fa-linkedin text-muted"></i>
                                        <span>LinkedIn</span>
                                    </div>
                                <?php endif; ?>

                                <!-- GitHub -->
                                <?php if (!empty($student['github_url'])): ?>
                                    <a href="<?php echo esc($student['github_url']); ?>" target="_blank" class="profile-social-btn github" title="GitHub Profile">
                                        <i class="fa-brands fa-github text-dark"></i>
                                        <span>GitHub</span>
                                    </a>
                                <?php else: ?>
                                    <div class="profile-social-btn disabled" title="GitHub not linked">
                                        <i class="fa-brands fa-github text-muted"></i>
                                        <span>GitHub</span>
                                    </div>
                                <?php endif; ?>

                                <!-- LeetCode -->
                                <?php if (!empty($lcUsername)): ?>
                                    <a href="https://leetcode.com/u/<?php echo urlencode($lcUsername); ?>" target="_blank" class="profile-social-btn leetcode" title="LeetCode @<?php echo esc($lcUsername); ?>">
                                        <svg style="width:16px;height:16px;margin-bottom:4px;" viewBox="0 0 24 24" fill="#ffa116">
                                            <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1 .028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                                        </svg>
                                        <span>LeetCode</span>
                                    </a>
                                <?php else: ?>
                                    <div class="profile-social-btn disabled" title="LeetCode not connected">
                                        <i class="fa-solid fa-code text-muted"></i>
                                        <span>LeetCode</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Portfolio -->
                                <?php if (!empty($student['portfolio_url'])): ?>
                                    <a href="<?php echo esc($student['portfolio_url']); ?>" target="_blank" class="profile-social-btn portfolio" title="Portfolio / Website">
                                        <i class="fa-solid fa-globe text-info"></i>
                                        <span>Website</span>
                                    </a>
                                <?php else: ?>
                                    <div class="profile-social-btn disabled" title="Website not linked">
                                        <i class="fa-solid fa-globe text-muted"></i>
                                        <span>Website</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PLACEMENT OVERRIDE CONTROL CARD -->
                <div class="mamcet-card mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                        <h6 class="card-title fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-sliders text-primary me-2"></i> Placement Status Controls
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="placement_override">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Override Placement Status</label>
                                <select name="placement_status" class="form-select form-select-sm">
                                    <option value="Placed" <?php echo $student['placement_status'] === 'Placed' ? 'selected' : ''; ?>>🟢 Placed</option>
                                    <option value="Unplaced" <?php echo $student['placement_status'] === 'Unplaced' ? 'selected' : ''; ?>>🟡 Unplaced</option>
                                    <option value="Higher Studies" <?php echo $student['placement_status'] === 'Higher Studies' ? 'selected' : ''; ?>>🟣 Higher Studies</option>
                                    <option value="Entrepreneurship" <?php echo $student['placement_status'] === 'Entrepreneurship' ? 'selected' : ''; ?>>🔵 Entrepreneurship</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted">Placement Willingness</label>
                                <select name="placement_willingness" class="form-select form-select-sm">
                                    <option value="Yes" <?php echo $student['placement_willingness'] === 'Yes' ? 'selected' : ''; ?>>✅ Willing for Campus Placement</option>
                                    <option value="No" <?php echo $student['placement_willingness'] === 'No' ? 'selected' : ''; ?>>❌ Not Willing</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-sm w-100 py-2 fw-semibold shadow-sm">
                                <i class="fa-solid fa-arrows-rotate me-1.5"></i> Update Placement Status
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: TABBED INTELLIGENCE & ACADEMIC DATA -->
            <div class="col-xl-8 col-lg-7">
                
                <!-- Modern Segmented Tabs Bar -->
                <div class="nav nav-pills profile-tabs-wrapper mb-4" id="profileTabsNav" role="tablist">
                    <button class="nav-link profile-nav-btn active" id="academics-tab" data-bs-toggle="pill" data-bs-target="#academics" type="button" role="tab" aria-controls="academics" aria-selected="true">
                        <i class="fa-solid fa-graduation-cap"></i> Academics
                    </button>

                    <button class="nav-link profile-nav-btn" id="resume-tab" data-bs-toggle="pill" data-bs-target="#resume" type="button" role="tab" aria-controls="resume" aria-selected="false">
                        <i class="fa-solid fa-file-waveform"></i> Resume & ATS
                        <?php if ($atsScore >= 0): ?>
                            <span class="tab-badge <?php echo $atsBadgeBg; ?>"><?php echo $atsScore; ?>%</span>
                        <?php endif; ?>
                    </button>

                    <button class="nav-link profile-nav-btn" id="aptitude-tab" data-bs-toggle="pill" data-bs-target="#aptitude" type="button" role="tab" aria-controls="aptitude" aria-selected="false">
                        <i class="fa-solid fa-fire text-warning"></i> Aptitude Streak
                        <?php if ($aptitudeStreak['current_streak'] > 0): ?>
                            <span class="tab-badge bg-warning text-dark"><?php echo $aptitudeStreak['current_streak']; ?>d</span>
                        <?php endif; ?>
                    </button>

                    <button class="nav-link profile-nav-btn" id="portfolio-tab" data-bs-toggle="pill" data-bs-target="#portfolio" type="button" role="tab" aria-controls="portfolio" aria-selected="false">
                        <i class="fa-solid fa-briefcase"></i> Portfolio & Profile
                    </button>

                    <button class="nav-link profile-nav-btn" id="certificates-tab" data-bs-toggle="pill" data-bs-target="#certificates" type="button" role="tab" aria-controls="certificates" aria-selected="false">
                        <i class="fa-solid fa-certificate"></i> Certificates
                        <span class="tab-badge <?php echo $totalCertificatesCount > 0 ? 'text-white' : 'bg-light text-muted border'; ?>" style="<?php echo $totalCertificatesCount > 0 ? 'background:#0d9488 !important;' : ''; ?>"><?php echo $totalCertificatesCount; ?></span>
                    </button>

                    <button class="nav-link profile-nav-btn" id="courses-tab" data-bs-toggle="pill" data-bs-target="#courses" type="button" role="tab" aria-controls="courses" aria-selected="false">
                        <i class="fa-solid fa-book-open"></i> LMS Progress
                        <?php if (!empty($coursesAssigned)): ?>
                            <span class="tab-badge bg-secondary text-white"><?php echo count($coursesAssigned); ?></span>
                        <?php endif; ?>
                    </button>

                    <button class="nav-link profile-nav-btn" id="placements-tab" data-bs-toggle="pill" data-bs-target="#placements" type="button" role="tab" aria-controls="placements" aria-selected="false">
                        <i class="fa-solid fa-trophy"></i> Offers
                        <?php if (!empty($placements)): ?>
                            <span class="tab-badge bg-success text-white"><?php echo count($placements); ?></span>
                        <?php endif; ?>
                    </button>

                    <button class="nav-link profile-nav-btn" id="notes-tab" data-bs-toggle="pill" data-bs-target="#notes" type="button" role="tab" aria-controls="notes" aria-selected="false">
                        <i class="fa-solid fa-comment-dots"></i> Officer Notes
                        <?php if (!empty($notes)): ?>
                            <span class="tab-badge bg-info text-dark"><?php echo count($notes); ?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Tab Content Panels -->
                <div class="tab-content" id="profileTabsContent">
                    
                    <!-- TAB 1: ACADEMICS -->
                    <div class="tab-pane fade show active" id="academics" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-graduation-cap text-primary"></i> Academic Performance Summary
                                    </h5>
                                    <button class="btn btn-sm btn-outline-primary fw-semibold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#editAcademicsModal">
                                        <i class="fa-solid fa-pen-to-square me-1.5"></i> Edit Academics
                                    </button>
                                </div>

                                <!-- 4 Hero Academic KPI Cards -->
                                <div class="academic-kpi-grid mb-4">
                                    <!-- CGPA Card -->
                                    <div class="academic-kpi-card">
                                        <div class="kpi-icon bg-primary-subtle text-primary">
                                            <i class="fa-solid fa-chart-line"></i>
                                        </div>
                                        <div class="kpi-label">Cumulative CGPA</div>
                                        <div class="kpi-value text-primary">
                                            <?php echo $student['current_cgpa'] !== null ? number_format((float)$student['current_cgpa'], 2) : 'N/A'; ?>
                                        </div>
                                        <div class="kpi-subtitle">
                                            <?php if ($student['current_cgpa'] !== null): ?>
                                                <?php if ((float)$student['current_cgpa'] >= 7.5): ?>
                                                    <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> First Class Distinction</span>
                                                <?php elseif ((float)$student['current_cgpa'] >= 6.0): ?>
                                                    <span class="text-primary"><i class="fa-solid fa-check me-1"></i> First Class</span>
                                                <?php else: ?>
                                                    <span class="text-warning"><i class="fa-solid fa-circle-exclamation me-1"></i> Below 6.0 Target</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not Recorded</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Standing Arrears -->
                                    <div class="academic-kpi-card">
                                        <div class="kpi-icon <?php echo (int)($student['standing_arrears'] ?? 0) > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?>">
                                            <i class="fa-solid <?php echo (int)($student['standing_arrears'] ?? 0) > 0 ? 'fa-triangle-exclamation' : 'fa-shield-check'; ?>"></i>
                                        </div>
                                        <div class="kpi-label">Standing Arrears</div>
                                        <div class="kpi-value <?php echo (int)($student['standing_arrears'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo (int)($student['standing_arrears'] ?? 0); ?>
                                        </div>
                                        <div class="kpi-subtitle">
                                            <?php if ((int)($student['standing_arrears'] ?? 0) === 0): ?>
                                                <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Clean Record</span>
                                            <?php else: ?>
                                                <span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Active Backlogs</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- History of Arrears -->
                                    <div class="academic-kpi-card">
                                        <div class="kpi-icon bg-secondary-subtle text-secondary">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </div>
                                        <div class="kpi-label">History of Arrears</div>
                                        <div class="kpi-value text-dark">
                                            <?php echo (int)($student['history_of_arrears'] ?? 0); ?>
                                        </div>
                                        <div class="kpi-subtitle text-muted">
                                            Total cleared arrears
                                        </div>
                                    </div>

                                    <!-- Passing Year -->
                                    <div class="academic-kpi-card">
                                        <div class="kpi-icon bg-info-subtle text-info">
                                            <i class="fa-solid fa-calendar-check"></i>
                                        </div>
                                        <div class="kpi-label">Graduation Year</div>
                                        <div class="kpi-value text-dark">
                                            <?php echo esc($student['graduation_year']); ?>
                                        </div>
                                        <div class="kpi-subtitle text-muted">
                                            Passing out batch
                                        </div>
                                    </div>
                                </div>

                                <!-- Placement Drive Eligibility Banner -->
                                <div class="drive-eligibility-card <?php echo $isEligibleForDrives ? 'eligible' : 'not-eligible'; ?> mb-4 shadow-sm">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="p-3 rounded-circle <?php echo $isEligibleForDrives ? 'bg-success text-white' : 'bg-danger text-white'; ?> d-flex align-items-center justify-content-center" style="width:48px;height:48px;font-size:1.3rem;">
                                            <i class="fa-solid <?php echo $isEligibleForDrives ? 'fa-user-check' : 'fa-user-xmark'; ?>"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1 <?php echo $isEligibleForDrives ? 'text-success-emphasis' : 'text-danger-emphasis'; ?>">
                                                <?php echo $isEligibleForDrives ? 'Placement Drive Eligible' : 'Placement Criteria Pending'; ?>
                                            </h6>
                                            <p class="mb-0 text-muted small">
                                                <?php if ($isEligibleForDrives): ?>
                                                    Candidate satisfies general campus drive criteria (CGPA &ge; 6.00, Zero Standing Arrears, and Willing for Placements).
                                                <?php else: ?>
                                                    <?php 
                                                    $reasons = [];
                                                    if ($cgpaVal === null || $cgpaVal < 6.0) $reasons[] = 'CGPA below 6.00';
                                                    if ($standingArr > 0) $reasons[] = "$standingArr active backlogs";
                                                    if ($student['placement_willingness'] !== 'Yes') $reasons[] = 'Not marked willing';
                                                    echo 'Flagged criteria: ' . implode(', ', $reasons) . '.';
                                                    ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge <?php echo $isEligibleForDrives ? 'bg-success' : 'bg-danger'; ?> px-3 py-2 rounded-pill fw-bold" style="font-size:0.8rem;">
                                            <?php echo $isEligibleForDrives ? 'Eligible' : 'Requires Clearance'; ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- School & Diploma Records -->
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                    <i class="fa-solid fa-school text-secondary me-2"></i> Pre-University & Diploma Credentials
                                </h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <div class="profile-stat-tile">
                                            <div class="stat-title">10th Standard %</div>
                                            <div class="stat-val"><?php echo $student['tenth_percentage'] !== null ? esc($student['tenth_percentage']) . '%' : 'N/A'; ?></div>
                                            <small class="text-muted">Secondary School</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="profile-stat-tile">
                                            <div class="stat-title">12th Standard (HSC) %</div>
                                            <div class="stat-val"><?php echo $student['twelfth_percentage'] !== null ? esc($student['twelfth_percentage']) . '%' : 'N/A'; ?></div>
                                            <small class="text-muted">Higher Secondary</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="profile-stat-tile">
                                            <div class="stat-title">Diploma Percentage %</div>
                                            <div class="stat-val"><?php echo $student['diploma_percentage'] !== null ? esc($student['diploma_percentage']) . '%' : 'N/A'; ?></div>
                                            <small class="text-muted">Lateral Entry / Polytech</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Semester-wise GPA Progression -->
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                    <i class="fa-solid fa-chart-simple text-primary me-2"></i> Semester-by-Semester GPA Progression
                                </h6>
                                <div class="sem-gpa-grid">
                                    <?php for ($i = 1; $i <= $totalSemesters; $i++): 
                                        $g = $student["sem{$i}_gpa"] ?? null;
                                    ?>
                                        <div class="sem-gpa-tile">
                                            <div class="sem-name">Sem <?php echo $i; ?></div>
                                            <div class="sem-val <?php echo $g !== null ? 'text-primary' : 'text-muted'; ?>">
                                                <?php echo $g !== null ? number_format((float)$g, 2) : '—'; ?>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: RESUME & ATS INTELLIGENCE -->
                    <div class="tab-pane fade" id="resume" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-file-waveform text-primary"></i> Resume & AI ATS Score Intelligence
                                    </h5>
                                    <?php if ($hasResume): ?>
                                        <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="d-inline">
                                            <?php csrfInput(); ?>
                                            <input type="hidden" name="action" value="run_ats_scan">
                                            <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3 shadow-sm">
                                                <i class="fa-solid fa-wand-magic-sparkles me-1.5"></i> <?php echo $latestAnalysis ? 'Re-scan ATS' : 'Run Full ATS Scan'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- Active Resume Banner -->
                                <div class="p-3 mb-4 rounded-3 border bg-light d-flex align-items-center justify-content-between flex-wrap gap-3" style="border-left: 4px solid var(--primary-color, #2563eb) !important;">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-danger-subtle text-danger rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; font-size: 1.8rem;">
                                            <i class="fa-solid fa-file-pdf"></i>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <h6 class="fw-bold text-dark mb-0"><?php echo esc($activeResumeFilename); ?></h6>
                                                <?php if ($hasResume): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                                        <i class="fa-solid fa-circle-check me-1"></i> Active Resume
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                                        <i class="fa-solid fa-circle-xmark me-1"></i> Missing
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small mt-1">
                                                <?php if ($activeResume && !empty($activeResume['uploaded_at'])): ?>
                                                    Uploaded: <?php echo date('d M Y, h:i A', strtotime($activeResume['uploaded_at'])); ?> • Size: <?php echo round(($activeResume['file_size'] ?? 0) / 1024, 1); ?> KB
                                                <?php elseif ($hasResume): ?>
                                                    Document registered on student profile record.
                                                <?php else: ?>
                                                    Student has not uploaded any resume file yet.
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                                        <?php if ($hasResume && $activeResumePath): ?>
                                            <a href="../<?php echo esc($activeResumePath); ?>" target="_blank" class="btn btn-primary btn-sm px-3 shadow-sm fw-semibold">
                                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View / Download
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($activeResume && !empty($activeResume['extracted_text'])): ?>
                                            <button class="btn btn-light border btn-sm px-3 text-secondary fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#extractedTextCollapse" aria-expanded="false">
                                                <i class="fa-solid fa-align-left me-1"></i> Extracted Text
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Collapsible Extracted Resume Text -->
                                <?php if ($activeResume && !empty($activeResume['extracted_text'])): ?>
                                    <div class="collapse mb-4" id="extractedTextCollapse">
                                        <div class="card card-body bg-light border">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-file-lines text-primary me-2"></i> Extracted Resume Text Content</h6>
                                                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="copyToClipboard(document.getElementById('rawExtractedResume').innerText, 'Extracted Resume Text');">
                                                    <i class="fa-solid fa-copy me-1"></i> Copy Text
                                                </button>
                                            </div>
                                            <pre id="rawExtractedResume" class="p-3 bg-white border rounded font-monospace small mb-0" style="max-height: 250px; overflow-y: auto; white-space: pre-wrap; font-size: 0.8rem;"><?php echo esc($activeResume['extracted_text']); ?></pre>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- ATS Scorecard & Diagnostics -->
                                <?php if ($latestAnalysis): ?>
                                    <div class="row g-3 mb-4">
                                        <!-- Scorecard Gauge Box -->
                                        <div class="col-md-5">
                                            <div class="p-4 bg-white border rounded-3 text-center h-100 shadow-sm">
                                                <span class="text-muted small fw-bold d-block text-uppercase mb-2">ATS Scorecard Summary</span>
                                                <div class="gauge-score-wrap my-2" style="width: 130px; height: 130px;">
                                                    <div class="gauge-score-text" style="color: <?php echo $atsColor; ?>;">
                                                        <span class="gauge-score-val"><?php echo $atsScore; ?></span><span class="gauge-score-max">/100</span>
                                                    </div>
                                                    <svg class="w-100 h-100" style="transform: rotate(-90deg);">
                                                        <circle cx="65" cy="65" r="55" stroke="#f1f5f9" stroke-width="10" fill="transparent"/>
                                                        <circle cx="65" cy="65" r="55" stroke="<?php echo $atsColor; ?>" stroke-width="10" fill="transparent"
                                                                stroke-dasharray="345" stroke-dashoffset="<?php echo 345 - (345 * max(0, min(100, $atsScore)) / 100); ?>"/>
                                                    </svg>
                                                </div>
                                                <h5 class="fw-bold mt-2 mb-1" style="color: <?php echo $atsColor; ?>;"><?php echo $atsTierLabel; ?></h5>
                                                <small class="text-muted d-block">Analyzed: <?php echo date('d M Y, h:i A', strtotime($latestAnalysis['analyzed_at'])); ?></small>
                                                
                                                <hr class="my-3">
                                                <div class="row text-center g-2">
                                                    <div class="col-6">
                                                        <span class="text-muted small d-block" style="font-size:0.75rem;">Rule Check</span>
                                                        <strong class="text-dark"><?php echo (int)$latestAnalysis['rule_score']; ?> / 60</strong>
                                                    </div>
                                                    <div class="col-6 border-start">
                                                        <span class="text-muted small d-block" style="font-size:0.75rem;">AI Rating</span>
                                                        <strong class="text-dark"><?php echo (int)$latestAnalysis['ai_score']; ?> / 40</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Section Score Breakdown Meters -->
                                        <div class="col-md-7">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i> Section-wise ATS Evaluation</h6>
                                                <?php if (!empty($sectionScores)): ?>
                                                    <?php foreach ($sectionScores as $sec): 
                                                        $pct = $sec['max_score'] > 0 ? round(($sec['score'] / $sec['max_score']) * 100) : 0;
                                                        $barColor = $pct >= 80 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
                                                    ?>
                                                        <div class="mb-2.5">
                                                            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.82rem;">
                                                                <span class="fw-semibold text-dark"><?php echo esc($sec['section_name']); ?></span>
                                                                <span class="text-muted"><?php echo $sec['score']; ?> / <?php echo $sec['max_score']; ?></span>
                                                            </div>
                                                            <div class="progress" style="height: 6px; border-radius: 999px; background-color: #f1f5f9;">
                                                                <div class="progress-bar <?php echo $barColor; ?>" role="progressbar" style="width: <?php echo $pct; ?>%;"></div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-muted small mb-0">No section breakdown available.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- AI Qualitative Summary Review -->
                                    <div class="card mb-4 border-0 shadow-sm" style="background: #f8fafc; border-left: 4px solid var(--primary-color, #2563eb) !important;">
                                        <div class="card-body p-3">
                                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-comment-dots text-primary me-2"></i> Automated Coach Qualitative Review</h6>
                                            <p class="mb-0 text-secondary-emphasis" style="font-size: 0.92rem; line-height: 1.55;">
                                                <?php echo nl2br(esc($latestAnalysis['assessment_text'] ?? 'Automated evaluation completed.')); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Strengths & Critical Issues -->
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm" style="border-left: 3px solid #10b981 !important;">
                                                <h6 class="fw-bold text-success mb-2"><i class="fa-solid fa-circle-check me-2"></i> Resume Strengths</h6>
                                                <?php 
                                                $strengths = json_decode($latestAnalysis['strengths'], true) ?: [];
                                                if (empty($strengths)): 
                                                ?>
                                                    <p class="text-muted small mb-0">No specific strengths recorded.</p>
                                                <?php else: ?>
                                                    <ul class="mb-0 ps-3 text-secondary" style="font-size: 0.85rem; line-height: 1.5;">
                                                        <?php foreach ($strengths as $str): ?>
                                                            <li class="mb-1"><?php echo esc($str); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm" style="border-left: 3px solid #ef4444 !important;">
                                                <h6 class="fw-bold text-danger mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Critical Issues & Warnings</h6>
                                                <?php 
                                                $issues = json_decode($latestAnalysis['critical_issues'], true) ?: [];
                                                if (empty($issues)): 
                                                ?>
                                                    <p class="text-success small mb-0"><i class="fa-solid fa-check me-1"></i> No critical formatting or content warnings detected.</p>
                                                <?php else: ?>
                                                    <ul class="mb-0 ps-3 text-danger-emphasis" style="font-size: 0.85rem; line-height: 1.5;">
                                                        <?php foreach ($issues as $iss): ?>
                                                            <li class="mb-1"><?php echo esc($iss); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Priority Actions & Keywords -->
                                    <?php 
                                    $priorityActions = json_decode($latestAnalysis['priority_actions'], true) ?: [];
                                    $missingKw = json_decode($latestAnalysis['missing_keywords'], true) ?: [];
                                    $actionVerbs = json_decode($latestAnalysis['action_verb_suggestions'], true) ?: [];
                                    ?>

                                    <?php if (!empty($priorityActions)): ?>
                                        <div class="card mb-4 border shadow-sm">
                                            <div class="card-header bg-light py-2 px-3">
                                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-circle-exclamation text-warning me-2"></i> Suggested Priority Improvements</h6>
                                            </div>
                                            <div class="card-body p-0">
                                                <ul class="list-group list-group-flush">
                                                    <?php foreach ($priorityActions as $act): ?>
                                                        <li class="list-group-item d-flex align-items-start py-2.5 px-3" style="font-size: 0.85rem;">
                                                            <i class="fa-solid fa-square-check text-warning me-2.5 mt-1 flex-shrink-0"></i>
                                                            <span class="text-dark"><?php echo esc($act); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm">
                                                <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-tags text-primary me-2"></i> Missing Recommended Keywords</h6>
                                                <?php if (empty($missingKw)): ?>
                                                    <span class="text-success small"><i class="fa-solid fa-check me-1"></i> Core industry keywords are present.</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-wrap gap-1.5">
                                                        <?php foreach ($missingKw as $kw): ?>
                                                            <span class="badge bg-light text-dark border px-2 py-1.5 fw-normal" style="font-size: 0.78rem;">
                                                                <i class="fa-solid fa-plus text-success me-1"></i><?php echo esc($kw); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm">
                                                <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-pen-nib text-info me-2"></i> High-Impact Action Verbs</h6>
                                                <?php if (empty($actionVerbs)): ?>
                                                    <span class="text-muted small">Action verbs formatting verified.</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column gap-1.5">
                                                        <?php foreach (array_slice($actionVerbs, 0, 4) as $vb): ?>
                                                            <div class="p-1.5 px-2.5 rounded bg-light border small text-dark d-flex align-items-start gap-2" style="font-size: 0.8rem;">
                                                                <i class="fa-solid fa-bolt text-warning mt-0.5 flex-shrink-0"></i>
                                                                <span><?php echo esc($vb); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                <?php elseif ($hasResume): ?>
                                    <div class="text-center py-5 border rounded-3 bg-white shadow-sm mb-4">
                                        <div class="mb-3">
                                            <span class="d-inline-flex p-3 rounded-circle bg-primary-subtle text-primary">
                                                <i class="fa-solid fa-wand-magic-sparkles fa-2x"></i>
                                            </span>
                                        </div>
                                        <h5 class="fw-bold text-dark">Resume Document Ready for ATS Scan</h5>
                                        <p class="text-muted small mb-3" style="max-width: 420px; margin: 0 auto;">
                                            This candidate has uploaded a resume, but it has not been scanned for ATS compatibility yet. Run a scan to generate instant qualitative scores and insights.
                                        </p>
                                        <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="d-inline">
                                            <?php csrfInput(); ?>
                                            <input type="hidden" name="action" value="run_ats_scan">
                                            <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold">
                                                <i class="fa-solid fa-play me-1"></i> Run ATS Scan Now
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5 border rounded-3 bg-white shadow-sm mb-4 text-muted">
                                        <i class="fa-solid fa-file-circle-xmark fa-3x mb-3 text-secondary opacity-50"></i>
                                        <h5 class="fw-bold text-dark">No Resume Registered</h5>
                                        <p class="small text-muted mb-0">Candidate has not uploaded any CV or resume document yet.</p>
                                    </div>
                                <?php endif; ?>

                                <!-- Resume Version History -->
                                <?php if (!empty($resumeHistory)): ?>
                                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark mt-4"><i class="fa-solid fa-clock-rotate-left text-secondary me-2"></i> Resume Version History</h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Document Name</th>
                                                    <th>Uploaded Date</th>
                                                    <th>Size</th>
                                                    <th>Status</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($resumeHistory as $hist): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <i class="fa-solid fa-file-pdf text-danger"></i>
                                                                <span class="fw-bold text-dark"><?php echo esc($hist['filename']); ?></span>
                                                                <?php if ($hist['is_current'] == 1): ?>
                                                                    <span class="badge bg-success-subtle text-success ms-1">Current</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo date('d M Y, h:i A', strtotime($hist['uploaded_at'])); ?></td>
                                                        <td><?php echo round($hist['file_size'] / 1024, 1); ?> KB</td>
                                                        <td>
                                                            <span class="badge bg-light text-dark border"><?php echo ucfirst($hist['status'] ?: 'uploaded'); ?></span>
                                                        </td>
                                                        <td class="text-end">
                                                            <a href="../<?php echo esc($hist['resume_path']); ?>" target="_blank" class="btn btn-xs btn-light border py-1" style="font-size: 0.75rem;">
                                                                <i class="fa-solid fa-download me-1"></i> View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: APTITUDE STREAK & CODING -->
                    <div class="tab-pane fade" id="aptitude" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-brain text-primary"></i> Daily Aptitude & Problem Solving Mastery
                                    </h5>
                                    <span class="badge bg-warning-subtle text-dark border border-warning px-3 py-1.5 rounded-pill fw-semibold">
                                        <i class="fa-solid fa-fire text-warning me-1"></i> <?php echo $aptitudeStreak['current_streak']; ?> Days Active Streak
                                    </span>
                                </div>

                                <!-- 1. Aptitude Key Metrics Grid -->
                                <div class="row g-3 mb-4">
                                    <!-- Current Streak -->
                                    <div class="col-md-3 col-6">
                                        <div class="p-3 bg-light rounded-3 text-center border">
                                            <div class="text-warning mb-1"><i class="fa-solid fa-fire fa-2x"></i></div>
                                            <h3 class="fw-bold text-dark mb-0"><?php echo $aptitudeStreak['current_streak']; ?> <small style="font-size:0.8rem; font-weight:normal;" class="text-muted">Days</small></h3>
                                            <span class="text-muted small" style="font-size:0.75rem;">Current Streak</span>
                                        </div>
                                    </div>

                                    <!-- Longest Streak -->
                                    <div class="col-md-3 col-6">
                                        <div class="p-3 bg-light rounded-3 text-center border">
                                            <div class="text-primary mb-1"><i class="fa-solid fa-trophy fa-2x"></i></div>
                                            <h3 class="fw-bold text-dark mb-0"><?php echo $aptitudeStreak['longest_streak']; ?> <small style="font-size:0.8rem; font-weight:normal;" class="text-muted">Days</small></h3>
                                            <span class="text-muted small" style="font-size:0.75rem;">Longest Record</span>
                                        </div>
                                    </div>

                                    <!-- Total Days Solved -->
                                    <div class="col-md-3 col-6">
                                        <div class="p-3 bg-light rounded-3 text-center border">
                                            <div class="text-info mb-1"><i class="fa-solid fa-calendar-check fa-2x"></i></div>
                                            <h3 class="fw-bold text-dark mb-0"><?php echo $aptitudeStreak['total_days_completed']; ?> <small style="font-size:0.8rem; font-weight:normal;" class="text-muted">Days</small></h3>
                                            <span class="text-muted small" style="font-size:0.75rem;">Completed Challenges</span>
                                        </div>
                                    </div>

                                    <!-- Accuracy Rate -->
                                    <div class="col-md-3 col-6">
                                        <div class="p-3 bg-light rounded-3 text-center border">
                                            <div class="text-success mb-1"><i class="fa-solid fa-bullseye fa-2x"></i></div>
                                            <h3 class="fw-bold text-dark mb-0"><?php echo $aptitudeStreak['accuracy_percentage']; ?>%</h3>
                                            <span class="text-muted small" style="font-size:0.75rem;"><?php echo $aptitudeStreak['total_correct']; ?> / <?php echo $aptitudeStreak['total_questions_attempted']; ?> Solved</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- 2. 7-Day Activity Tracker Strip -->
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-clock-rotate-left text-secondary me-2"></i> Recent 7-Day Challenge Track</h6>
                                <div class="d-flex justify-content-between align-items-center p-3 bg-white border rounded-3 mb-4 flex-wrap gap-2">
                                    <?php foreach ($weeklyTracker as $day): ?>
                                        <div class="text-center px-2 py-1 flex-grow-1" style="min-width: 60px;">
                                            <span class="text-muted small d-block" style="font-size:0.75rem;"><?php echo $day['day_name']; ?></span>
                                            <span class="fw-bold text-dark d-block mb-1" style="font-size:0.85rem;"><?php echo $day['day_number']; ?></span>
                                            <?php if ($day['is_completed']): ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success text-white" style="width: 32px; height: 32px; font-size: 0.85rem;" title="Score: <?php echo $day['score']; ?>/5">
                                                    <i class="fa-solid fa-check"></i>
                                                </div>
                                                <span class="d-block text-success fw-bold mt-1" style="font-size:0.7rem;"><?php echo $day['score']; ?>/5</span>
                                            <?php elseif ($day['is_today']): ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning border border-warning" style="width: 32px; height: 32px; font-size: 0.85rem;" title="Today Pending">
                                                    <i class="fa-solid fa-bolt"></i>
                                                </div>
                                                <span class="d-block text-warning fw-bold mt-1" style="font-size:0.7rem;">Today</span>
                                            <?php else: ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-muted border" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                                    <i class="fa-solid fa-minus"></i>
                                                </div>
                                                <span class="d-block text-muted mt-1" style="font-size:0.7rem;">Missed</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- 3. Category Breakdown Mastery -->
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-chart-column text-primary me-2"></i> Category Performance Breakdown</h6>
                                <div class="row g-3 mb-4">
                                    <?php foreach ($categoryBreakdown as $cat): ?>
                                        <div class="col-md-6">
                                            <div class="p-3 bg-white border rounded-3 h-100 shadow-sm">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <i class="fa-solid <?php echo esc($cat['icon']); ?> text-primary"></i>
                                                        <strong class="text-dark" style="font-size:0.9rem;"><?php echo esc($cat['category_name']); ?></strong>
                                                    </div>
                                                    <span class="badge bg-light text-dark border fw-bold"><?php echo $cat['accuracy']; ?>%</span>
                                                </div>
                                                <div class="progress mb-2" style="height: 6px; border-radius: 999px; background-color: #f1f5f9;">
                                                    <div class="progress-bar bg-primary" style="width: <?php echo $cat['accuracy']; ?>%;"></div>
                                                </div>
                                                <div class="d-flex justify-content-between text-muted small" style="font-size: 0.75rem;">
                                                    <span>Attempted: <strong><?php echo $cat['attempted']; ?></strong></span>
                                                    <span>Correct: <strong class="text-success"><?php echo $cat['correct']; ?></strong></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- 4. LeetCode Profile Telemetry -->
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                    <svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;" viewBox="0 0 24 24" fill="#ffa116">
                                        <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1 .028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                                    </svg>
                                    LeetCode Coding Telemetry
                                </h6>
                                <?php if (!empty($lcUsername)): ?>
                                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 bg-warning-subtle text-dark d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; font-size: 1.5rem; background: #fff3cd;">
                                                <i class="fa-solid fa-code text-warning"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <h6 class="fw-bold text-dark mb-0">@<?php echo esc($lcUsername); ?></h6>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                                        <i class="fa-solid fa-circle-check me-1"></i> Linked
                                                    </span>
                                                </div>
                                                <small class="text-muted">Live sync enabled on student dashboard</small>
                                            </div>
                                        </div>
                                        <a href="https://leetcode.com/u/<?php echo urlencode($lcUsername); ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark px-3 fw-semibold">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open LeetCode Profile
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="p-3 bg-light border rounded-3 text-center text-muted small">
                                        <i class="fa-solid fa-link-slash me-1"></i> Candidate has not connected their LeetCode profile handle yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: PORTFOLIO & PROFILE -->
                    <div class="tab-pane fade" id="portfolio" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-briefcase text-primary"></i> Professional Portfolio & Candidate Profile
                                    </h5>
                                </div>

                                <div class="row mb-4 g-3">
                                    <div class="col-md-4">
                                        <?php if (!empty($student['linkedin_url'])): ?>
                                            <a href="<?php echo esc($student['linkedin_url']); ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100 py-2 fw-semibold">
                                                <i class="fa-brands fa-linkedin me-2"></i> LinkedIn Profile
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100 py-2" disabled>
                                                <i class="fa-brands fa-linkedin me-2"></i> LinkedIn (Not Set)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?php if (!empty($student['github_url'])): ?>
                                            <a href="<?php echo esc($student['github_url']); ?>" target="_blank" class="btn btn-outline-dark btn-sm w-100 py-2 fw-semibold">
                                                <i class="fa-brands fa-github me-2"></i> GitHub Repository
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100 py-2" disabled>
                                                <i class="fa-brands fa-github me-2"></i> GitHub (Not Set)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?php if (!empty($student['portfolio_url'])): ?>
                                            <a href="<?php echo esc($student['portfolio_url']); ?>" target="_blank" class="btn btn-outline-info btn-sm w-100 py-2 text-dark fw-semibold">
                                                <i class="fa-solid fa-globe me-2"></i> Personal Website
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100 py-2" disabled>
                                                <i class="fa-solid fa-globe me-2"></i> Website (Not Set)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Career Objective -->
                                <div class="mb-4">
                                    <span class="text-muted small fw-bold text-uppercase d-block mb-1">Career Objective</span>
                                    <div class="p-3 border rounded-3 bg-light text-dark" style="font-size:0.9rem; line-height: 1.6;">
                                        <?php echo esc($student['career_objective'] ?: 'Not defined yet.'); ?>
                                    </div>
                                </div>

                                <!-- Preferred Roles -->
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded-3 bg-white shadow-sm">
                                            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Preferred Job Role</span>
                                            <span class="fw-bold text-dark"><?php echo esc($student['preferred_job_role'] ?: 'Not specified'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded-3 bg-white shadow-sm">
                                            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Preferred Location</span>
                                            <span class="fw-bold text-dark"><?php echo esc($student['preferred_location'] ?: 'Open to relocate'); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Technical Skills -->
                                <div class="mb-4">
                                    <span class="text-muted small fw-bold text-uppercase d-block mb-2">Technical Skills</span>
                                    <?php if (!empty($student['skills'])): 
                                        $skillsList = array_map('trim', explode(',', $student['skills']));
                                    ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($skillsList as $sk): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 rounded-pill fw-semibold" style="font-size:0.82rem;">
                                                    <?php echo esc($sk); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="p-3 border rounded-3 bg-light text-muted small mb-0">No technical skills added yet.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Projects -->
                                <div class="mb-4">
                                    <span class="text-muted small fw-bold text-uppercase d-block mb-1">Academic & Personal Projects</span>
                                    <div class="p-3 border rounded-3 bg-light text-dark" style="font-size:0.9rem; white-space: pre-line; line-height: 1.6;">
                                        <?php echo esc($student['projects'] ?: 'No projects added.'); ?>
                                    </div>
                                </div>

                                <!-- Internships -->
                                <div class="mb-4">
                                    <span class="text-muted small fw-bold text-uppercase d-block mb-1">Internships & Work Experiences</span>
                                    <div class="p-3 border rounded-3 bg-light text-dark" style="font-size:0.9rem; white-space: pre-line; line-height: 1.6;">
                                        <?php echo esc($student['internships'] ?: 'No internships added.'); ?>
                                    </div>
                                </div>

                                <!-- Certifications & Uploaded Documents -->
                                <div class="mb-0">
                                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                        <span class="text-muted small fw-bold text-uppercase">Certifications & Accreditations</span>
                                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 py-1 fw-semibold" onclick="switchProfileTab('#certificates')">
                                            <i class="fa-solid fa-certificate me-1"></i> Open Certificates Hub (<?php echo $totalCertificatesCount; ?>)
                                        </button>
                                    </div>

                                    <!-- Uploaded Certificate Files Preview -->
                                    <?php if (!empty($studentCertificates)): ?>
                                        <div class="border rounded-3 p-3 bg-white shadow-sm mb-3">
                                            <div class="small fw-bold text-muted mb-2.5 d-flex align-items-center justify-content-between">
                                                <span><i class="fa-solid fa-award text-teal me-1"></i> Verified & Uploaded Credentials (<?php echo count($studentCertificates); ?>)</span>
                                                <span class="badge rounded-pill px-2.5 py-1" style="background:#f0fdfa; color:#0d9488; border:1px solid #ccfbf1; font-size:0.72rem;">Student Portfolio</span>
                                            </div>
                                            <div class="d-flex flex-column gap-2">
                                                <?php foreach (array_slice($studentCertificates, 0, 4) as $cPreview): 
                                                    $cpFile = $cPreview['file_name'] ?: basename($cPreview['file_path'] ?? 'certificate');
                                                    $cpExt = strtolower(pathinfo($cpFile, PATHINFO_EXTENSION));
                                                    $cpIcon = $cpExt === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
                                                    $cpStatus = $cPreview['status'];
                                                    $cpBadge = 'bg-warning-subtle text-warning';
                                                    if ($cpStatus === 'Verified') $cpBadge = 'bg-success-subtle text-success';
                                                    elseif ($cpStatus === 'Rejected') $cpBadge = 'bg-danger-subtle text-danger';
                                                ?>
                                                    <div class="p-2.5 px-3 rounded-2 bg-light border d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                        <div class="d-flex align-items-center gap-2.5 min-w-0 flex-grow-1">
                                                            <div class="rounded-2 d-flex align-items-center justify-content-center bg-white border flex-shrink-0" style="width: 38px; height: 38px;">
                                                                <i class="fa-solid <?php echo $cpIcon; ?> fa-lg"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="fw-bold text-dark text-truncate" style="font-size:0.88rem; max-width: 360px;">
                                                                    <?php echo esc($cPreview['title']); ?>
                                                                </div>
                                                                <div class="text-muted" style="font-size:0.75rem;">
                                                                    <span class="fw-semibold text-dark"><i class="fa-solid fa-building me-1"></i><?php echo esc($cPreview['issuing_organization']); ?></span>
                                                                    <?php if (!empty($cPreview['issue_date'])): ?>
                                                                        • Issued: <?php echo date('d M Y', strtotime($cPreview['issue_date'])); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                            <span class="badge <?php echo $cpBadge; ?> rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                                                <?php echo esc($cpStatus); ?>
                                                            </span>
                                                            <a href="../api/certificate-file.php?cert_id=<?php echo $cPreview['cert_id']; ?>&mode=view" target="_blank" class="btn btn-xs btn-outline-primary px-2.5 py-1 fw-semibold" style="font-size:0.75rem;" title="View Certificate Document">
                                                                <i class="fa-solid fa-eye me-1"></i> View
                                                            </a>
                                                            <a href="../api/certificate-file.php?cert_id=<?php echo $cPreview['cert_id']; ?>&mode=download" class="btn btn-xs btn-outline-secondary px-2.5 py-1 fw-semibold" style="font-size:0.75rem;" title="Download Certificate">
                                                                <i class="fa-solid fa-download"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($studentCertificates) > 4): ?>
                                                    <div class="text-center pt-1">
                                                        <button type="button" class="btn btn-link btn-sm text-decoration-none fw-semibold p-0" onclick="switchProfileTab('#certificates')" style="font-size: 0.8rem;">
                                                            + View all <?php echo count($studentCertificates); ?> certificates in Certificates tab &rarr;
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Student Self-Attested Text Summary -->
                                    <?php if (!empty($student['certifications'])): ?>
                                        <div class="p-3 border rounded-3 bg-light text-dark" style="font-size:0.9rem; white-space: pre-line; line-height: 1.6;">
                                            <span class="text-muted small fw-bold d-block mb-1 text-uppercase" style="font-size:0.75rem;">Self-Attested Accreditations:</span>
                                            <?php echo esc($student['certifications']); ?>
                                        </div>
                                    <?php elseif (empty($studentCertificates)): ?>
                                        <div class="p-3 border rounded-3 bg-light text-muted small">
                                            <i class="fa-solid fa-circle-info me-1"></i> No certificates or credentials recorded yet.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 5: LMS PROGRESS -->
                    <div class="tab-pane fade" id="courses" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-book-open text-primary"></i> LMS Learning & Curriculum Progress
                                    </h5>
                                </div>

                                <?php if (empty($coursesAssigned)): ?>
                                    <div class="text-center py-5 text-muted bg-light rounded-3 border">
                                        <i class="fa-solid fa-graduation-cap fa-3x mb-2 text-secondary opacity-50"></i>
                                        <h6 class="fw-bold text-dark mt-2">No Courses Assigned</h6>
                                        <p class="small text-muted mb-0">No active LMS learning programs assigned to this department and batch for the active session.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($coursesAssigned as $c): ?>
                                            <div class="profile-course-item">
                                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                                    <div>
                                                        <h6 class="fw-bold text-dark mb-0"><?php echo esc($c['course_title']); ?></h6>
                                                        <small class="text-muted font-monospace">Course Code: <?php echo esc($c['course_code']); ?></small>
                                                    </div>
                                                    <span class="badge <?php echo $c['status'] === 'Completed' ? 'bg-success' : ($c['status'] === 'In Progress' ? 'bg-primary' : 'bg-secondary'); ?> rounded-pill px-3 py-1.5">
                                                        <?php echo $c['status'] ?: 'Not Started'; ?>
                                                    </span>
                                                </div>
                                                <div class="d-flex align-items-center gap-3 mt-2">
                                                    <div class="progress flex-grow-1" style="height: 8px; border-radius:999px;">
                                                        <div class="progress-bar <?php echo (float)($c['completion_percentage'] ?? 0) >= 100 ? 'bg-success' : 'bg-primary'; ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo (float)($c['completion_percentage'] ?? 0); ?>%" 
                                                             aria-valuenow="<?php echo (float)($c['completion_percentage'] ?? 0); ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <span class="small fw-bold text-dark" style="min-width: 40px;"><?php echo (int)($c['completion_percentage'] ?? 0); ?>%</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 6: PLACEMENT OFFERS -->
                    <div class="tab-pane fade" id="placements" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-trophy text-primary"></i> Placement Job Offers & Letter Documents
                                    </h5>
                                </div>

                                <!-- Add Placement Form Box -->
                                <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" enctype="multipart/form-data" class="mb-4 p-4 border rounded-3 bg-light shadow-sm">
                                    <?php csrfInput(); ?>
                                    <input type="hidden" name="action" value="add_placement">
                                    
                                    <h6 class="fw-bold mb-3 text-dark text-primary"><i class="fa-solid fa-plus-circle me-1"></i> Record New Job Offer</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Company Name</label>
                                            <input type="text" name="company_name" class="form-control form-control-sm" placeholder="e.g. Zoho, TCS, Infosys" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold">Package (LPA)</label>
                                            <input type="number" step="0.01" name="package_lpa" class="form-control form-control-sm" placeholder="e.g. 6.50" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold">Placed Date</label>
                                            <input type="date" name="placed_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Offer Letter File (PDF / Image)</label>
                                            <input type="file" name="offer_letter" class="form-control form-control-sm" accept="application/pdf,image/png,image/jpeg,image/jpg" required>
                                            <div class="form-text small" style="font-size:0.75rem;">Max 5MB. PDF, PNG, JPG accepted.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Placement Notes / Drive Remarks</label>
                                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Selected via On-Campus Placement Drive">
                                        </div>
                                    </div>
                                    <div class="text-end mt-3">
                                        <button type="submit" class="btn btn-sm btn-primary px-4 fw-semibold shadow-sm">
                                            <i class="fa-solid fa-circle-check me-1"></i> Save Job Offer
                                        </button>
                                    </div>
                                </form>

                                <!-- Active Offers Cards -->
                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">Verified Job Offers Recorded</h6>
                                <?php if (empty($placements)): ?>
                                    <div class="text-center py-4 text-muted bg-light rounded-3 border">
                                        <i class="fa-solid fa-briefcase fa-2x mb-2 text-secondary opacity-50"></i>
                                        <p class="small text-muted mb-0">No job offer records recorded for this student yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($placements as $p): ?>
                                            <div class="profile-offer-card d-flex justify-content-between align-items-center flex-wrap gap-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="bg-primary-subtle text-primary rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;font-size:1.4rem;">
                                                        <i class="fa-solid fa-building"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold text-dark mb-0"><?php echo esc($p['company_name']); ?></h6>
                                                        <div class="text-muted small mt-1">
                                                            <i class="fa-regular fa-calendar me-1"></i> Placed: <?php echo $p['placed_date'] ? date('d M Y', strtotime($p['placed_date'])) : 'N/A'; ?>
                                                            <?php if (!empty($p['notes'])): ?>
                                                                • <span class="text-secondary"><?php echo esc($p['notes']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                                    <span class="profile-offer-pkg">
                                                        ₹<?php echo number_format($p['package_lpa'], 2); ?> LPA
                                                    </span>

                                                    <?php if (!empty($p['offer_letter_path'])): ?>
                                                        <a href="../<?php echo esc($p['offer_letter_path']); ?>" target="_blank" class="btn btn-sm btn-outline-danger fw-semibold px-3 shadow-sm">
                                                            <i class="fa-solid fa-file-pdf me-1"></i> View Letter
                                                        </a>
                                                    <?php endif; ?>

                                                    <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this placement offer permanently?')">
                                                        <?php csrfInput(); ?>
                                                        <input type="hidden" name="action" value="delete_placement">
                                                        <input type="hidden" name="placement_id" value="<?php echo $p['placement_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete Offer">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 7: OFFICER NOTES & AUDIT HISTORY -->
                    <div class="tab-pane fade" id="notes" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header">
                                    <h5 class="profile-section-title">
                                        <i class="fa-solid fa-comment-dots text-primary"></i> Placement Officer Feedback & Internal Notes
                                    </h5>
                                </div>

                                <!-- Add Note Form Box -->
                                <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="mb-4 p-3 border rounded-3 bg-light shadow-sm">
                                    <?php csrfInput(); ?>
                                    <input type="hidden" name="action" value="add_note">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-dark">Post New Officer Comment / Activity Note</label>
                                        <textarea name="note_content" class="form-control" rows="3" placeholder="Enter placement counseling feedback, technical interview remarks, or notes regarding candidate readiness..." required></textarea>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-sm btn-primary px-4 fw-semibold shadow-sm">
                                            <i class="fa-solid fa-paper-plane me-1"></i> Post Note
                                        </button>
                                    </div>
                                </form>

                                <!-- Notes Timeline -->
                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">Activity Remarks History</h6>
                                <?php if (empty($notes)): ?>
                                    <div class="text-center py-4 text-muted bg-light rounded-3 border mb-4">
                                        <i class="fa-regular fa-comment-dots fa-2x mb-2 text-secondary opacity-50"></i>
                                        <p class="small text-muted mb-0">No officer remarks recorded on this profile yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="profile-timeline-list mb-4">
                                        <?php foreach ($notes as $n): ?>
                                            <div class="profile-timeline-item">
                                                <div class="profile-timeline-dot"></div>
                                                <div class="profile-timeline-bubble">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <strong class="text-dark" style="font-size:0.9rem;"><?php echo esc($n['officer_name']); ?></strong>
                                                        <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?></small>
                                                    </div>
                                                    <p class="mb-0 text-secondary-emphasis" style="font-size:0.9rem; line-height: 1.5;"><?php echo nl2br(esc($n['note_content'])); ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Profile Change History Log -->
                                <?php if (!empty($updateLogs)): ?>
                                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark mt-4">
                                        <i class="fa-solid fa-clock-rotate-left text-secondary me-2"></i> Profile Modification Audit Log
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Field Modified</th>
                                                    <th>Previous Value</th>
                                                    <th>Updated Value</th>
                                                    <th>Modified By</th>
                                                    <th>Timestamp</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($updateLogs as $log): ?>
                                                    <tr>
                                                        <td><span class="fw-bold text-dark"><?php echo esc($log['field_name']); ?></span></td>
                                                        <td><span class="text-danger" style="text-decoration: line-through;"><?php echo esc($log['old_value'] ?: '(empty)'); ?></span></td>
                                                        <td><span class="text-success fw-bold"><?php echo esc($log['new_value'] ?: '(empty)'); ?></span></td>
                                                        <td><?php echo esc($log['updated_by_user']); ?></td>
                                                        <td><?php echo date('d M Y H:i', strtotime($log['updated_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 8: CERTIFICATES & CREDENTIALS -->
                    <div class="tab-pane fade" id="certificates" role="tabpanel">
                        <div class="mamcet-card mb-4">
                            <div class="card-body p-4">
                                <div class="profile-section-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <h5 class="profile-section-title mb-0">
                                        <i class="fa-solid fa-certificate text-primary"></i> Uploaded Certificates & Credentials
                                    </h5>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-dark border px-3 py-1.5 rounded-pill">
                                            Total: <strong><?php echo $totalCertificatesCount; ?></strong>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#uploadCertModal">
                                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Certificate
                                        </button>
                                    </div>
                                </div>

                                <?php if (empty($studentCertificates)): ?>
                                    <div class="text-center py-5 text-muted bg-light rounded-3 border">
                                        <i class="fa-solid fa-award fa-3x mb-2 text-secondary opacity-50"></i>
                                        <h6 class="fw-bold text-dark mt-2">No Certificates Uploaded</h6>
                                        <p class="small text-muted mb-3">This student has not uploaded any certification documents yet.</p>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#uploadCertModal">
                                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Certificate for Student
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($studentCertificates as $cert): ?>
                                            <?php
                                            $cStatus = $cert['status'];
                                            $badgeClass = 'bg-warning-subtle text-warning';
                                            if ($cStatus === 'Verified') $badgeClass = 'bg-success-subtle text-success';
                                            elseif ($cStatus === 'Rejected') $badgeClass = 'bg-danger-subtle text-danger';

                                            $certFile = $cert['file_name'] ?: basename($cert['file_path'] ?? 'certificate');
                                            $certExt = strtolower(pathinfo($certFile, PATHINFO_EXTENSION));
                                            $certIcon = $certExt === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
                                            $certSize = !empty($cert['file_size']) ? (function_exists('formatBytes') ? formatBytes($cert['file_size']) : round($cert['file_size'] / 1024, 1) . ' KB') : '';
                                            $uploadDate = !empty($cert['created_at']) ? date('d M Y, h:i A', strtotime($cert['created_at'])) : 'N/A';
                                            ?>
                                            <?php
                                            $viewUrl = "../api/certificate-file.php?cert_id=" . $cert['cert_id'] . "&mode=view";
                                            $downloadUrl = "../api/certificate-file.php?cert_id=" . $cert['cert_id'] . "&mode=download";
                                            $isPdf = $certExt === 'pdf';
                                            ?>
                                            <div class="p-3.5 p-md-4 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-3">
                                                <div class="d-flex align-items-start gap-3 min-w-0 flex-grow-1">
                                                    <!-- Thumbnail / Document Preview -->
                                                    <?php if ($isPdf): ?>
                                                        <div class="rounded-3 d-flex flex-column align-items-center justify-content-center flex-shrink-0" 
                                                             style="width: 56px; height: 56px; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626; cursor: pointer;"
                                                             onclick="openCertQuickView(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>', 'pdf', '<?php echo $viewUrl; ?>', '<?php echo $downloadUrl; ?>')"
                                                             title="Click to preview PDF">
                                                            <i class="fa-solid fa-file-pdf fa-xl"></i>
                                                            <span style="font-size: 0.6rem; font-weight: 700; margin-top: 2px;">PDF</span>
                                                        </div>
                                                    <?php else: ?>
                                                        <img src="<?php echo $viewUrl; ?>" 
                                                             alt="<?php echo esc($cert['title']); ?>" 
                                                             class="rounded-3 border flex-shrink-0" 
                                                             style="width: 56px; height: 56px; object-fit: cover; cursor: pointer;"
                                                             onclick="openCertQuickView(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>', 'image', '<?php echo $viewUrl; ?>', '<?php echo $downloadUrl; ?>')"
                                                             loading="lazy"
                                                             title="Click to preview image"
                                                             onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'rounded-3 d-flex align-items-center justify-content-center flex-shrink-0\' style=\'width:56px; height:56px; background:#f8fafc; border:1px solid #e2e8f0;\'><i class=\'fa-solid fa-file-image fa-xl text-primary\'></i></div>';">
                                                    <?php endif; ?>

                                                    <div class="min-w-0 flex-grow-1">
                                                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                                            <h6 class="fw-bold text-dark mb-0" style="font-size: 1.02rem;">
                                                                <?php echo esc($cert['title']); ?>
                                                            </h6>
                                                            <span class="badge <?php echo $badgeClass; ?> rounded-pill px-2.5 py-1" style="font-size: 0.75rem;">
                                                                <?php echo esc($cStatus); ?>
                                                            </span>
                                                        </div>

                                                        <div class="text-muted small mb-2" style="font-size: 0.82rem;">
                                                            <span class="fw-semibold text-dark"><i class="fa-solid fa-building-columns text-teal me-1"></i><?php echo esc($cert['issuing_organization'] ?: 'Institution / Academy'); ?></span>
                                                            <?php if (!empty($cert['issue_date'])): ?>
                                                                • <span><i class="fa-regular fa-calendar-check me-1"></i>Issued: <?php echo date('d M Y', strtotime($cert['issue_date'])); ?></span>
                                                            <?php endif; ?>
                                                            • <span><i class="fa-regular fa-clock me-1"></i>Uploaded: <?php echo esc($uploadDate); ?></span>
                                                        </div>

                                                        <div class="d-flex align-items-center gap-3 text-muted small flex-wrap" style="font-size: 0.78rem;">
                                                            <span><i class="fa-regular fa-file me-1"></i> <strong class="text-dark"><?php echo esc($certFile); ?></strong><?php echo !empty($certSize) ? " ({$certSize})" : ''; ?></span>
                                                            <?php if (!empty($cert['credential_id'])): ?>
                                                                <span class="d-inline-flex align-items-center">
                                                                    <i class="fa-solid fa-fingerprint me-1"></i> ID: 
                                                                    <code class="text-dark bg-light border px-1.5 py-0.5 rounded ms-1 user-select-all" style="font-size:0.75rem;"><?php echo esc($cert['credential_id']); ?></code>
                                                                    <button type="button" class="cert-copy-btn" onclick="copyToClipboard('<?php echo esc(addslashes($cert['credential_id'])); ?>', this)" title="Copy Credential ID">
                                                                        <i class="fa-regular fa-copy"></i>
                                                                    </button>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($cert['credential_url'])): ?>
                                                                <span><a href="<?php echo esc($cert['credential_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-teal text-decoration-none fw-semibold"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Verify Online</a></span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php if (!empty($cert['skills'])): ?>
                                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                                <?php foreach (array_filter(array_map('trim', explode(',', $cert['skills']))) as $sk): ?>
                                                                    <span class="cert-skill-chip" style="font-size: 0.72rem;">
                                                                        <i class="fa-solid fa-tag opacity-50" style="font-size:0.62rem;"></i> <?php echo esc($sk); ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                    <button type="button" class="btn btn-sm btn-outline-primary px-3 fw-semibold shadow-sm" onclick="openCertQuickView(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>', '<?php echo $isPdf ? 'pdf' : 'image'; ?>', '<?php echo $viewUrl; ?>', '<?php echo $downloadUrl; ?>')" title="Quick View Document">
                                                        <i class="fa-solid fa-eye me-1"></i> View
                                                    </button>
                                                    <a href="<?php echo $downloadUrl; ?>" class="btn btn-sm btn-outline-secondary px-3 fw-semibold shadow-sm" title="Download File">
                                                        <i class="fa-solid fa-download me-1"></i> Download
                                                    </a>

                                                    <!-- Verification Status Dropdown -->
                                                    <div class="dropdown d-inline">
                                                        <button class="btn btn-sm btn-light border dropdown-toggle fw-semibold" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.8rem;">
                                                            Status
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.82rem;">
                                                            <li><a class="dropdown-item text-success" href="#" onclick="updateCertStatus(<?php echo $cert['cert_id']; ?>, 'Verified'); return false;"><i class="fa-solid fa-circle-check me-2"></i>Mark as Verified</a></li>
                                                            <li><a class="dropdown-item text-warning" href="#" onclick="updateCertStatus(<?php echo $cert['cert_id']; ?>, 'Pending Verification'); return false;"><i class="fa-solid fa-hourglass-half me-2"></i>Mark as Pending</a></li>
                                                            <li><a class="dropdown-item text-danger" href="#" onclick="updateCertStatus(<?php echo $cert['cert_id']; ?>, 'Rejected'); return false;"><i class="fa-solid fa-circle-xmark me-2"></i>Mark as Rejected</a></li>
                                                        </ul>
                                                    </div>

                                                    <button type="button" class="btn btn-sm btn-light border text-danger" onclick="deleteCertAdmin(<?php echo $cert['cert_id']; ?>, '<?php echo addslashes($cert['title']); ?>')" title="Delete Certificate">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- EDIT ACADEMICS MODAL PRO -->
<div class="modal fade" id="editAcademicsModal" tabindex="-1" aria-labelledby="editAcademicsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="update_academics">
                
                <div class="modal-header border-bottom py-3 px-4">
                    <h5 class="modal-title fw-bold text-dark" id="editAcademicsModalLabel">
                        <i class="fa-solid fa-graduation-cap text-primary me-2"></i> Update Academic Records & GPAs
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-4 text-dark">
                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">University Cumulative Metrics</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Current CGPA (0.00 - 10.00)</label>
                            <input type="number" step="0.01" min="0.00" max="10.00" name="current_cgpa" class="form-control" value="<?php echo esc($student['current_cgpa'] ?? ''); ?>" placeholder="e.g. 8.45" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Standing Arrears (Backlogs)</label>
                            <input type="number" min="0" name="standing_arrears" class="form-control" value="<?php echo (int)($student['standing_arrears'] ?? 0); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">History of Cleared Arrears</label>
                            <input type="number" min="0" name="history_of_arrears" class="form-control" value="<?php echo (int)($student['history_of_arrears'] ?? 0); ?>" required>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">School & Pre-University Records (%)</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">10th Percentage</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="tenth_percentage" class="form-control" placeholder="e.g. 85.50" value="<?php echo esc($student['tenth_percentage'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">12th Percentage (HSC)</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="twelfth_percentage" class="form-control" placeholder="e.g. 88.00" value="<?php echo esc($student['twelfth_percentage'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Diploma Percentage (Optional)</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="diploma_percentage" class="form-control" placeholder="e.g. 90.00" value="<?php echo esc($student['diploma_percentage'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">Semester-wise GPA (Sem 1 to Sem <?php echo $totalSemesters; ?>)</h6>
                    <div class="row g-2">
                        <?php for ($i = 1; $i <= $totalSemesters; $i++): ?>
                            <div class="col-4 col-md-3 col-lg-2 mb-2">
                                <label class="form-label small fw-bold text-muted">Sem <?php echo $i; ?></label>
                                <input type="number" step="0.01" min="0.00" max="10.00" name="sem<?php echo $i; ?>_gpa" class="form-control text-center font-monospace" placeholder="—" value="<?php echo esc($student["sem{$i}_gpa"] ?? ''); ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CERTIFICATE QUICK VIEW LIGHTBOX MODAL -->
<div class="modal fade" id="certQuickViewModal" tabindex="-1" aria-labelledby="certQuickViewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <i class="fa-solid fa-award text-warning"></i>
                    <h6 class="modal-title fw-bold text-white text-truncate mb-0" id="certQuickViewModalTitle">Certificate Preview</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="certQuickViewOpenTab" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-light rounded-pill px-3 py-1" style="font-size:0.78rem;" title="Open in New Tab">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Full Window
                    </a>
                    <a href="#" id="certQuickViewDownload" class="btn btn-sm btn-teal text-white rounded-pill px-3 py-1" style="background:#0d9488; font-size:0.78rem;" title="Download Certificate">
                        <i class="fa-solid fa-download me-1"></i> Download
                    </a>
                    <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0 bg-light position-relative" style="min-height: 440px; max-height: 80vh; display: flex; align-items: center; justify-content: center; overflow: auto;">
                <div id="certQuickViewLoading" class="position-absolute top-50 start-50 translate-middle text-center py-4">
                    <div class="spinner-border" role="status" style="width: 2.5rem; height: 2.5rem; color:#0d9488;">
                        <span class="visually-hidden">Loading document...</span>
                    </div>
                    <div class="text-muted small mt-2 fw-medium">Rendering document preview...</div>
                </div>
                <div id="certQuickViewContainer" class="w-100 h-100 d-flex justify-content-center align-items-center p-3">
                    <!-- Dynamic iframe or img loaded via JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- UPLOAD CERTIFICATE MODAL FOR ADMIN / OFFICER -->
<div class="modal fade" id="uploadCertModal" tabindex="-1" aria-labelledby="uploadCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: #ffffff;">
                <h5 class="modal-title fw-bold text-white mb-0" id="uploadCertModalLabel">
                    <i class="fa-solid fa-cloud-arrow-up me-2"></i> Upload Student Certificate
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="uploadCertAdminForm" enctype="multipart/form-data">
                <?php csrfInput(); ?>
                <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">

                <div class="modal-body p-4 text-dark">
                    <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size: 0.82rem;">
                        <i class="fa-solid fa-circle-info fa-lg flex-shrink-0"></i>
                        <span>Uploading certificate directly for <strong><?php echo esc($student['student_name']); ?></strong> (<?php echo esc($student['registration_number']); ?>). Document will be marked as <strong>Verified</strong>.</span>
                    </div>

                    <div id="uploadCertAlertBox" class="d-none alert alert-danger py-2 px-3 mb-3" style="font-size: 0.82rem;"></div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-dark">Certificate Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="adminCertTitle" class="form-control" placeholder="e.g. AWS Certified Cloud Practitioner, Python Bootcamp" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-dark">Issuing Organization</label>
                            <input type="text" name="issuing_organization" id="adminCertOrg" class="form-control" placeholder="e.g. Coursera, NPTEL, Udemy, AWS">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">Issue Date</label>
                            <input type="date" name="issue_date" id="adminCertDate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">Credential ID</label>
                            <input type="text" name="credential_id" id="adminCertCredId" class="form-control" placeholder="e.g. UC-889271">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">Verification URL</label>
                            <input type="url" name="credential_url" id="adminCertCredUrl" class="form-control" placeholder="https://coursera.org/verify/...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Associated Skills <span class="text-muted fw-normal">(Comma-separated)</span></label>
                        <input type="text" name="skills" id="adminCertSkills" class="form-control" placeholder="e.g. Python, SQL, Machine Learning, Problem Solving">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">Certificate Document File (PDF, JPG, PNG) <span class="text-danger">*</span></label>
                        <input type="file" name="cert_file" id="adminCertFileInput" class="form-control" accept=".pdf,.png,.jpg,.jpeg" required>
                        <div class="form-text small text-muted">Maximum file size: 5MB. Accepted formats: PDF, PNG, JPG.</div>
                    </div>
                </div>

                <div class="modal-footer border-top py-3 px-4 bg-light">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitAdminCert" class="btn btn-primary px-4 fw-semibold shadow-sm" style="background:#0d9488; border-color:#0d9488;">
                        <span id="btnSubmitAdminCertSpinner" class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>
                        <span id="btnSubmitAdminCertText"><i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload & Verify Certificate</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function copyToClipboard(text, label) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `${label} copied!`,
                showConfirmButton: false,
                timer: 1500
            });
        }
    }).catch(err => {
        console.error('Failed to copy text: ', err);
    });
}

function confirmDeleteStudent(studentId, studentName, regNo) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Student Record?',
            html: `Are you sure you want to permanently delete <strong>${studentName}</strong> (${regNo})?<br><br><div class="alert alert-danger text-start p-2 mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Warning:</strong> This will permanently delete their academic marks, profile, uploaded files, and login user account.</div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Permanently Delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#deleteStudentForm').submit();
            }
        });
    } else {
        if (confirm(`Are you sure you want to permanently delete student "${studentName}" (${regNo})? This action cannot be undone.`)) {
            $('#deleteStudentForm').submit();
        }
    }
}

function updateCertStatus(certId, newStatus) {
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    $.post('../api/certificate-actions.php', {
        action: 'update_status',
        cert_id: certId,
        status: newStatus,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Status Updated',
                    text: res.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.hash = '#certificates';
                    window.location.reload();
                });
            } else {
                alert(res.message);
                window.location.hash = '#certificates';
                window.location.reload();
            }
        } else {
            alert(res.message || 'Failed to update status.');
        }
    }, 'json').fail(function() {
        alert('Server error while updating certificate status.');
    });
}

function deleteCertAdmin(certId, certTitle) {
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    const doDelete = () => {
        $.post('../api/certificate-actions.php', {
            action: 'delete',
            cert_id: certId,
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Deleted!', res.message, 'success').then(() => {
                        window.location.hash = '#certificates';
                        window.location.reload();
                    });
                } else {
                    alert(res.message);
                    window.location.hash = '#certificates';
                    window.location.reload();
                }
            } else {
                alert(res.message || 'Failed to delete certificate.');
            }
        }, 'json').fail(function() {
            alert('Server error while deleting certificate.');
        });
    };

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Certificate?',
            text: `Permanently delete certificate "${certTitle}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete'
        }).then((result) => {
            if (result.isConfirmed) doDelete();
        });
    } else {
        if (confirm(`Permanently delete certificate "${certTitle}"?`)) doDelete();
    }
}

function switchProfileTab(targetHash) {
    if (!targetHash) return;
    if (targetHash.indexOf('#') !== 0) targetHash = '#' + targetHash;
    if (targetHash === '#resumes') targetHash = '#resume';
    var $btn = $('.profile-tabs-wrapper button[data-bs-target="' + targetHash + '"]');
    if ($btn.length) {
        $btn.trigger('click');
        var navElem = document.getElementById('profileTabsNav');
        if (navElem) {
            navElem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

// In-Page Quick-View Lightbox Preview for Admin
function openCertQuickView(certId, certTitle, fileType, viewUrl, downloadUrl) {
    $('#certQuickViewModalTitle').text(certTitle || 'Certificate Preview');
    $('#certQuickViewOpenTab').attr('href', viewUrl);
    $('#certQuickViewDownload').attr('href', downloadUrl);

    const $container = $('#certQuickViewContainer');
    const $loading = $('#certQuickViewLoading');
    $container.empty();
    $loading.removeClass('d-none');

    if (fileType === 'pdf') {
        const $iframe = $('<iframe>', {
            src: viewUrl,
            class: 'w-100 border-0 rounded-3',
            style: 'height: 70vh; min-height: 480px;'
        });
        $iframe.on('load', function() {
            $loading.addClass('d-none');
        });
        $container.append($iframe);
    } else {
        const $img = $('<img>', {
            src: viewUrl,
            alt: certTitle,
            class: 'img-fluid rounded-3 shadow-sm',
            style: 'max-height: 75vh; max-width: 100%; object-fit: contain;'
        });
        $img.on('load', function() {
            $loading.addClass('d-none');
        });
        $img.on('error', function() {
            $loading.addClass('d-none');
            $container.html('<div class="text-center p-5 text-muted"><i class="fa-regular fa-image fa-3x mb-2 opacity-50"></i><p class="small">Unable to render inline preview. Please use "Full Window" or "Download" buttons above.</p></div>');
        });
        $container.append($img);
    }

    const modal = new bootstrap.Modal(document.getElementById('certQuickViewModal'));
    modal.show();
}

// Support Tab Navigation & Deep-Linking from URL Hash (e.g. #resume, #aptitude, #portfolio, #courses, #placements, #notes, #certificates)
$(document).ready(function() {
    // Explicit tab click handling to guarantee tab switching
    $(document).on('click', '.profile-tabs-wrapper .profile-nav-btn', function(e) {
        e.preventDefault();
        var target = $(this).attr('data-bs-target');
        if (!target) return;
        
        // Update active class on tab buttons
        $('.profile-tabs-wrapper .profile-nav-btn').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        
        // Switch tab panes
        $('#profileTabsContent > .tab-pane').removeClass('show active');
        $(target).addClass('show active');
        
        // Use Bootstrap Tab API if available
        if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            var tabInstance = bootstrap.Tab.getOrCreateInstance(this);
            if (tabInstance) tabInstance.show();
        }
        
        // Update URL hash without jumping
        if (history.pushState) {
            history.pushState(null, null, target);
        } else {
            window.location.hash = target;
        }
    });

    // Handle clicks on any link or button targeting profile tabs (e.g. #certificates badge in sidebar, or in-card buttons)
    $(document).on('click', 'a[href^="#"], button[data-target-tab]', function(e) {
        var href = $(this).attr('href') || $(this).data('target-tab');
        if (href && href.startsWith('#') && href.length > 1) {
            var $targetBtn = $('.profile-tabs-wrapper button[data-bs-target="' + href + '"]');
            if ($targetBtn.length) {
                e.preventDefault();
                $targetBtn.trigger('click');
                var tabNav = document.getElementById('profileTabsNav');
                if (tabNav) {
                    tabNav.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }
    });

    // Deep linking helper
    function handleHashDeepLink() {
        var hash = window.location.hash;
        if (hash) {
            if (hash === '#resumes') hash = '#resume';
            var $targetBtn = $('.profile-tabs-wrapper button[data-bs-target="' + hash + '"]');
            if ($targetBtn.length && !$targetBtn.hasClass('active')) {
                $targetBtn.trigger('click');
            }
        }
    }

    // Deep linking on load and on hash change
    handleHashDeepLink();
    $(window).on('hashchange', handleHashDeepLink);

    // Admin Upload Certificate Form Submission via AJAX
    $('#uploadCertAdminForm').on('submit', function(e) {
        e.preventDefault();
        var form = this;
        var formData = new FormData(form);
        var $alert = $('#uploadCertAlertBox');
        var $btn = $('#btnSubmitAdminCert');
        var $spinner = $('#btnSubmitAdminCertSpinner');
        var $btnText = $('#btnSubmitAdminCertText');

        $alert.addClass('d-none').text('');

        var fileInput = document.getElementById('adminCertFileInput');
        if (!fileInput.files || !fileInput.files[0]) {
            $alert.removeClass('d-none').text('Please select a certificate file to upload.');
            return;
        }

        var file = fileInput.files[0];
        var allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        var ext = file.name.split('.').pop().toLowerCase();
        if (!allowedExts.includes(ext)) {
            $alert.removeClass('d-none').text('Invalid file format. Please upload a PDF, PNG, or JPG certificate.');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            $alert.removeClass('d-none').text('File size exceeds the 5MB limit. Please choose a smaller file.');
            return;
        }

        // Add csrf token if not present in form
        var csrfToken = $('meta[name="csrf-token"]').attr('content') || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
        if (!formData.has('csrf_token')) {
            formData.append('csrf_token', csrfToken);
        }

        $btn.prop('disabled', true);
        $spinner.removeClass('d-none');

        $.ajax({
            url: '../api/upload-certification.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    var modalElem = document.getElementById('uploadCertModal');
                    if (modalElem && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var modalInstance = bootstrap.Modal.getInstance(modalElem);
                        if (modalInstance) modalInstance.hide();
                    }
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Certificate Uploaded!',
                            text: res.message || 'Certificate uploaded and verified successfully.',
                            timer: 1800,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.hash = '#certificates';
                            window.location.reload();
                        });
                    } else {
                        alert(res.message || 'Certificate uploaded successfully.');
                        window.location.hash = '#certificates';
                        window.location.reload();
                    }
                } else {
                    $alert.removeClass('d-none').text(res.message || 'Failed to upload certificate.');
                    $btn.prop('disabled', false);
                    $spinner.addClass('d-none');
                }
            },
            error: function(xhr) {
                var errMsg = 'Failed to upload certificate. Please try again.';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res && res.message) errMsg = res.message;
                } catch(e) {}
                $alert.removeClass('d-none').text(errMsg);
                $btn.prop('disabled', false);
                $spinner.addClass('d-none');
            }
        });
    });
});
</script>

