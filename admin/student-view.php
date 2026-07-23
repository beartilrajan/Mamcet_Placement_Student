<?php
// MAMCET Placement & Learning Portal - Student Detail Profile View Page

$pageTitle = 'Student Detail Profile';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$studentId = (int)($_GET['student_id'] ?? 0);
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
        
        try {
            $stmtChk = $db->prepare("SELECT academic_id FROM student_academics WHERE student_id = ?");
            $stmtChk->execute([$studentId]);
            $exists = $stmtChk->fetch();
            
            if ($exists) {
                $stmtSa = $db->prepare("
                    UPDATE student_academics 
                    SET current_cgpa = ?, standing_arrears = ?, history_of_arrears = ?, 
                        tenth_percentage = ?, twelfth_percentage = ?, diploma_percentage = ?, 
                        sem1_gpa = ?, sem2_gpa = ?, sem3_gpa = ?, sem4_gpa = ?, sem5_gpa = ?, sem6_gpa = ? 
                    WHERE student_id = ?
                ");
                $stmtSa->execute([
                    $cgpa, $standingArrears, $historyArrears, 
                    $tenth, $twelfth, $diploma, 
                    $sem1, $sem2, $sem3, $sem4, $sem5, $sem6, 
                    $studentId
                ]);
            } else {
                $stmtSa = $db->prepare("
                    INSERT INTO student_academics 
                    (student_id, current_cgpa, standing_arrears, history_of_arrears, 
                     tenth_percentage, twelfth_percentage, diploma_percentage, 
                     sem1_gpa, sem2_gpa, sem3_gpa, sem4_gpa, sem5_gpa, sem6_gpa) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtSa->execute([
                    $studentId, $cgpa, $standingArrears, $historyArrears, 
                    $tenth, $twelfth, $diploma, 
                    $sem1, $sem2, $sem3, $sem4, $sem5, $sem6
                ]);
            }
            
            $success = 'Academic record updated successfully.';
            logActivity($db, $_SESSION['user_id'], 'Update Student Academics', "Updated academic records (CGPA: $cgpa, Arrears: $standingArrears) for student ID $studentId");
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch student data with all related departments, batches, sections, academics, and profiles
$stmt = $db->prepare("
    SELECT s.*, d.dept_name, d.dept_code, b.batch_name, pr.programme_name, sec.section_name,
           sa.tenth_percentage, sa.twelfth_percentage, sa.diploma_percentage, sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears,
           sa.sem1_gpa, sa.sem2_gpa, sa.sem3_gpa, sa.sem4_gpa, sa.sem5_gpa, sa.sem6_gpa,
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

// Compute Profile completion
$profileCompletion = calculateProfileCompletion($db, $studentId);

// Fetch officer notes
$stmtNotes = $db->prepare("
    SELECT n.*, o.name AS officer_name 
    FROM officer_notes n 
    JOIN placement_officers o ON n.officer_id = o.officer_id 
    WHERE n.student_id = ? 
    ORDER BY n.created_at DESC
");
$stmtNotes->execute([$studentId]);
$notes = $stmtNotes->fetchAll();

// Fetch assigned courses & progress
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

// Fetch student placement offers
$stmtPl = $db->prepare("SELECT * FROM student_placements WHERE student_id = ? ORDER BY placed_date DESC");
$stmtPl->execute([$studentId]);
$placements = $stmtPl->fetchAll();

// Fetch update history logs
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
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Student Profile Details</h4>
        </div>
        
        <div class="navbar-right">
            <div class="session-selector-container">
                <span class="small text-muted fw-bold d-none d-md-inline">Active Session:</span>
                <select class="session-selector-select" id="globalSessionSelector">
                    <?php foreach ($allSessions as $s): ?>
                        <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                            <?php echo esc($s['session_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-profile-dropdown">
                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:36px;height:36px;border-radius:50%;font-weight:bold;">
                    <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex">
                    <span class="user-name"><?php echo esc($loggedInUser['display_name'] ?? 'User'); ?></span>
                    <span class="user-role"><?php echo $_SESSION['role_id'] === 1 ? 'Super Admin' : 'Placement Officer'; ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="page-container">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger mb-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?></div>
        <?php endif; ?>

        <!-- BACK TO LIST -->
        <div class="mb-4">
            <a href="students.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Student Roster</a>
        </div>

        <div class="row">
            <!-- LEFT PROFILE SYNOPSIS -->
            <div class="col-lg-4 mb-4">
                <div class="mamcet-card">
                    <div class="card-body text-center pt-5">
                        <div class="user-profile-img mx-auto text-center d-flex align-items-center justify-content-center bg-primary text-white mb-3" style="width:90px;height:90px;border-radius:50%;font-size:3rem;font-weight:bold;">
                            <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                        </div>
                        <h4 class="fw-bold mb-1 text-dark"><?php echo esc($student['student_name']); ?></h4>
                        <span class="badge bg-secondary mb-3"><?php echo esc($student['registration_number']); ?></span>
                        
                        <div class="mb-4 text-start">
                            <label class="small text-muted fw-bold d-block mb-1">Profile Completion: <?php echo $profileCompletion; ?>%</label>
                            <div class="progress" style="height: 10px; border-radius: 5px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $profileCompletion; ?>%" aria-valuenow="<?php echo $profileCompletion; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>

                        <hr>
                        
                        <div class="text-start mt-3">
                            <span class="d-block small text-muted">Programme / Dept</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['programme_name'] ?? 'B.Tech') . ' - ' . esc($student['dept_code']); ?></span>
                            
                            <span class="d-block small text-muted">Batch / Section</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['batch_name']) . ' (Section ' . esc($student['section_name'] ?? 'N/A') . ')'; ?></span>
                            
                            <span class="d-block small text-muted">Official Mobile</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['mobile_number']); ?></span>

                            <span class="d-block small text-muted">College Email</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['college_email'] ?? 'Not Set'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- OVERRIDE STATUS -->
                <div class="mamcet-card">
                    <div class="card-header">
                        <h6 class="card-title fw-bold mb-0 text-dark">Placement Overrides</h6>
                    </div>
                    <div class="card-body">
                        <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="placement_override">
                            
                            <div class="mb-3">
                                <label class="form-label small">Override Placement Status</label>
                                <select name="placement_status" class="form-select">
                                    <option value="Placed" <?php echo $student['placement_status'] === 'Placed' ? 'selected' : ''; ?>>Placed</option>
                                    <option value="Unplaced" <?php echo $student['placement_status'] === 'Unplaced' ? 'selected' : ''; ?>>Unplaced</option>
                                    <option value="Higher Studies" <?php echo $student['placement_status'] === 'Higher Studies' ? 'selected' : ''; ?>>Higher Studies</option>
                                    <option value="Entrepreneurship" <?php echo $student['placement_status'] === 'Entrepreneurship' ? 'selected' : ''; ?>>Entrepreneurship</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label small">Placement Willingness</label>
                                <select name="placement_willingness" class="form-select">
                                    <option value="Yes" <?php echo $student['placement_willingness'] === 'Yes' ? 'selected' : ''; ?>>Willing</option>
                                    <option value="No" <?php echo $student['placement_willingness'] === 'No' ? 'selected' : ''; ?>>Not Willing</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-sm w-100">Update Status</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- RIGHT DETAILS TABS -->
            <div class="col-lg-8">
                <div class="mamcet-card">
                    <div class="card-header bg-white">
                        <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active fw-bold" id="academics-tab" data-bs-toggle="tab" data-bs-target="#academics" type="button" role="tab">Academics</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="portfolio-tab" data-bs-toggle="tab" data-bs-target="#portfolio" type="button" role="tab">Portfolio & CV</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="courses-tab" data-bs-toggle="tab" data-bs-target="#courses" type="button" role="tab">LMS Progress</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="placements-tab" data-bs-toggle="tab" data-bs-target="#placements" type="button" role="tab">Placement Offers</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button" role="tab">Officer Notes</button>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="card-body">
                        <div class="tab-content" id="profileTabsContent">
                            <!-- TAB 1: ACADEMICS -->
                            <div class="tab-pane fade show active" id="academics" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                                    <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-graduation-cap text-primary me-2"></i> Academic Summary</h6>
                                    <button class="btn btn-xs btn-outline-primary py-1 px-2" style="font-size: 0.8rem;" data-bs-toggle="modal" data-bs-target="#editAcademicsModal">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit Academics
                                    </button>
                                </div>
                                <div class="row mb-4">
                                    <div class="col-md-3 col-6 text-center border-end mb-2">
                                        <span class="text-muted small d-block">Current CGPA</span>
                                        <h3 class="fw-bold text-primary mb-0"><?php echo esc($student['current_cgpa'] ?? 'N/A'); ?></h3>
                                    </div>
                                    <div class="col-md-3 col-6 text-center border-end mb-2">
                                        <span class="text-muted small d-block">Standing Arrears</span>
                                        <h3 class="fw-bold <?php echo $student['standing_arrears'] > 0 ? 'text-danger' : 'text-success'; ?> mb-0"><?php echo (int)($student['standing_arrears'] ?? 0); ?></h3>
                                    </div>
                                    <div class="col-md-3 col-6 text-center border-end mb-2">
                                        <span class="text-muted small d-block">History of Arrears</span>
                                        <h3 class="fw-bold text-dark mb-0"><?php echo (int)($student['history_of_arrears'] ?? 0); ?></h3>
                                    </div>
                                    <div class="col-md-3 col-6 text-center mb-2">
                                        <span class="text-muted small d-block">Passing Year</span>
                                        <h3 class="fw-bold text-dark mb-0"><?php echo esc($student['graduation_year']); ?></h3>
                                    </div>
                                </div>

                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">School & Diploma Records</h6>
                                <div class="row mb-4 g-3">
                                    <div class="col-4">
                                        <div class="p-3 bg-light rounded text-center">
                                            <span class="text-muted small d-block">10th %</span>
                                            <span class="fw-bold text-dark"><?php echo esc($student['tenth_percentage'] ?? 'N/A'); ?>%</span>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 bg-light rounded text-center">
                                            <span class="text-muted small d-block">12th %</span>
                                            <span class="fw-bold text-dark"><?php echo esc($student['twelfth_percentage'] ?? 'N/A'); ?>%</span>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 bg-light rounded text-center">
                                            <span class="text-muted small d-block">Diploma %</span>
                                            <span class="fw-bold text-dark"><?php echo esc($student['diploma_percentage'] ?? 'N/A'); ?>%</span>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">Semester-wise GPA Analysis</h6>
                                <div class="row g-2">
                                    <?php for ($i = 1; $i <= 6; $i++): $g = $student["sem{$i}_gpa"]; ?>
                                        <div class="col-4 col-md-2">
                                            <div class="p-2 border rounded text-center">
                                                <span class="text-muted small d-block" style="font-size:0.75rem;">Sem <?php echo $i; ?></span>
                                                <span class="fw-bold text-dark"><?php echo esc($g ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <!-- TAB 2: PORTFOLIO & CV -->
                            <div class="tab-pane fade" id="portfolio" role="tabpanel">
                                <div class="row mb-4 g-3">
                                    <div class="col-md-4">
                                        <?php if (!empty($student['linkedin_url'])): ?>
                                            <a href="<?php echo esc($student['linkedin_url']); ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100"><i class="fa-brands fa-linkedin me-2"></i> LinkedIn URL</a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100" disabled><i class="fa-brands fa-linkedin me-2"></i> LinkedIn (Not Set)</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?php if (!empty($student['github_url'])): ?>
                                            <a href="<?php echo esc($student['github_url']); ?>" target="_blank" class="btn btn-outline-dark btn-sm w-100"><i class="fa-brands fa-github me-2"></i> GitHub Portfolio</a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100" disabled><i class="fa-brands fa-github me-2"></i> GitHub (Not Set)</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?php if (!empty($student['resume_path'])): ?>
                                            <a href="../assets/uploads/resumes/<?php echo basename($student['resume_path']); ?>" target="_blank" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-file-pdf me-2"></i> View Resume / CV</a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100" disabled><i class="fa-solid fa-file-pdf me-2"></i> Resume (Not Uploaded)</button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <span class="text-muted small fw-bold d-block">Career Objective</span>
                                    <p class="p-3 border rounded text-dark bg-light" style="font-size:0.9rem;"><?php echo esc($student['career_objective'] ?: 'Not defined yet.'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <span class="text-muted small fw-bold d-block">Technical Skills</span>
                                    <p class="p-3 border rounded text-dark bg-light" style="font-size:0.9rem;"><?php echo esc($student['skills'] ?: 'Not added.'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <span class="text-muted small fw-bold d-block">Academic Projects</span>
                                    <p class="p-3 border rounded text-dark bg-light" style="font-size:0.9rem; white-space: pre-line;"><?php echo esc($student['projects'] ?: 'No projects added.'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <span class="text-muted small fw-bold d-block">Internships & Work Experiences</span>
                                    <p class="p-3 border rounded text-dark bg-light" style="font-size:0.9rem; white-space: pre-line;"><?php echo esc($student['internships'] ?: 'No internships added.'); ?></p>
                                </div>
                                <div class="mb-0">
                                    <span class="text-muted small fw-bold d-block">Certifications</span>
                                    <p class="p-3 border rounded text-dark bg-light" style="font-size:0.9rem; white-space: pre-line;"><?php echo esc($student['certifications'] ?: 'No certifications added.'); ?></p>
                                </div>
                            </div>

                            <!-- TAB 3: LMS PROGRESS -->
                            <div class="tab-pane fade" id="courses" role="tabpanel">
                                <?php if (empty($coursesAssigned)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fa-solid fa-graduation-cap fa-2x mb-2 text-secondary"></i>
                                        <p class="mb-0">No active LMS learning programs assigned to this department and batch.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($coursesAssigned as $c): ?>
                                            <div class="list-group-item py-3 px-0 border-bottom">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <span class="fw-bold text-dark d-block"><?php echo esc($c['course_title']); ?></span>
                                                        <small class="text-muted">Code: <?php echo esc($c['course_code']); ?></small>
                                                    </div>
                                                    <span class="badge <?php echo $c['status'] === 'Completed' ? 'bg-success' : ($c['status'] === 'In Progress' ? 'bg-info' : 'bg-secondary'); ?>">
                                                        <?php echo $c['status'] ?: 'Not Started'; ?>
                                                    </span>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (float)($c['completion_percentage'] ?? 0); ?>%" aria-valuenow="<?php echo (float)($c['completion_percentage'] ?? 0); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <span class="small fw-bold text-dark" style="min-width: 34px;"><?php echo (int)($c['completion_percentage'] ?? 0); ?>%</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- TAB 4: PLACEMENT OFFERS -->
                            <div class="tab-pane fade" id="placements" role="tabpanel">
                                <!-- Add Placement Form -->
                                <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" enctype="multipart/form-data" class="mb-4 p-3 border rounded bg-light">
                                    <?php csrfInput(); ?>
                                    <input type="hidden" name="action" value="add_placement">
                                    
                                    <h6 class="fw-bold mb-3 text-dark text-primary"><i class="fa-solid fa-plus-circle me-1"></i> Record New Job Offer</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Company Name</label>
                                            <input type="text" name="company_name" class="form-control form-control-sm" placeholder="e.g. Zoho, TCS" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold">Salary Package (LPA)</label>
                                            <input type="number" step="0.01" name="package_lpa" class="form-control form-control-sm" placeholder="e.g. 4.50" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold">Placed Date</label>
                                            <input type="date" name="placed_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Offer Letter Document (PDF / Image)</label>
                                            <input type="file" name="offer_letter" class="form-control form-control-sm" accept="application/pdf,image/png,image/jpeg,image/jpg" required>
                                            <div class="form-text small" style="font-size:0.75rem;">Max 5MB. PDF, PNG, JPG, JPEG accepted.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Placement Notes / Remarks</label>
                                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Selected via on-campus drive">
                                        </div>
                                    </div>
                                    <div class="text-end mt-3">
                                        <button type="submit" class="btn btn-sm btn-primary px-3">Add Placement Offer</button>
                                    </div>
                                </form>

                                <!-- Active Offers Timeline -->
                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">Current Job Offers</h6>
                                <?php if (empty($placements)): ?>
                                    <p class="text-muted small text-center py-3">No placement records found for this student.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem; border: none;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Company</th>
                                                    <th>Package (LPA)</th>
                                                    <th>Placed Date</th>
                                                    <th>Offer Letter</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($placements as $p): ?>
                                                    <tr>
                                                        <td>
                                                            <strong class="text-dark"><?php echo esc($p['company_name']); ?></strong>
                                                            <?php if (!empty($p['notes'])): ?>
                                                                <small class="text-muted d-block"><?php echo esc($p['notes']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="fw-bold text-success"><?php echo number_format($p['package_lpa'], 2); ?> LPA</span></td>
                                                        <td><?php echo $p['placed_date'] ? date('d M Y', strtotime($p['placed_date'])) : 'N/A'; ?></td>
                                                        <td>
                                                            <?php if (!empty($p['offer_letter_path'])): ?>
                                                                <a href="../<?php echo esc($p['offer_letter_path']); ?>" target="_blank" class="btn btn-xs btn-outline-danger py-1" style="font-size:0.75rem;">
                                                                    <i class="fa-solid fa-file-pdf me-1"></i> View Offer
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted small">No File</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this placement offer permanently?')">
                                                                <?php csrfInput(); ?>
                                                                <input type="hidden" name="action" value="delete_placement">
                                                                <input type="hidden" name="placement_id" value="<?php echo $p['placement_id']; ?>">
                                                                <button type="submit" class="btn btn-xs btn-light border"><i class="fa-solid fa-trash text-danger"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- TAB 5: OFFICER NOTES -->
                            <div class="tab-pane fade" id="notes" role="tabpanel">
                                <!-- Add Note Form -->
                                <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST" class="mb-4">
                                    <?php csrfInput(); ?>
                                    <input type="hidden" name="action" value="add_note">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Post New Comment</label>
                                        <textarea name="note_content" class="form-control" rows="3" placeholder="Enter placement feedback, technical remarks or details on student willingness..." required></textarea>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-sm btn-primary px-3">Post Note</button>
                                    </div>
                                </form>

                                <!-- Notes Timeline -->
                                <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">Activity Remarks history</h6>
                                <?php if (empty($notes)): ?>
                                    <p class="text-muted small text-center py-3">No officer remarks recorded on this profile yet.</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($notes as $n): ?>
                                            <div class="list-group-item py-3 px-0">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <strong class="text-dark" style="font-size:0.9rem;"><?php echo esc($n['officer_name']); ?></strong>
                                                    <small class="text-muted"><?php echo date('d M Y h:i A', strtotime($n['created_at'])); ?></small>
                                                </div>
                                                <p class="mb-0 text-secondary-emphasis" style="font-size:0.9rem;"><?php echo esc($n['note_content']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- UPDATE LOGS -->
                <?php if (!empty($updateLogs)): ?>
                    <div class="mamcet-card">
                        <div class="card-header bg-light">
                            <h6 class="card-title fw-bold mb-0 text-dark">Profile Change History</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Field</th>
                                            <th>Previous Value</th>
                                            <th>Updated Value</th>
                                            <th>Modified By</th>
                                            <th>Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($updateLogs as $log): ?>
                                            <tr>
                                                <td><span class="fw-bold"><?php echo esc($log['field_name']); ?></span></td>
                                                <td><span class="text-danger" style="text-decoration: line-through;"><?php echo esc($log['old_value'] ?: '(empty)'); ?></span></td>
                                                <td><span class="text-success"><?php echo esc($log['new_value'] ?: '(empty)'); ?></span></td>
                                                <td><?php echo esc($log['updated_by_user']); ?></td>
                                                <td><?php echo date('d M Y H:i', strtotime($log['updated_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<!-- EDIT ACADEMICS MODAL -->
<div class="modal fade" id="editAcademicsModal" tabindex="-1" aria-labelledby="editAcademicsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="student-view.php?student_id=<?php echo $studentId; ?>" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="update_academics">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark" id="editAcademicsModalLabel"><i class="fa-solid fa-graduation-cap text-primary me-2"></i> Edit Academic Records</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-4 text-dark">
                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">University Cumulative Metrics</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">Current CGPA (0.00 - 10.00)</label>
                            <input type="number" step="0.01" min="0.00" max="10.00" name="current_cgpa" class="form-control" value="<?php echo esc($student['current_cgpa'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">Standing Arrears</label>
                            <input type="number" min="0" name="standing_arrears" class="form-control" value="<?php echo (int)($student['standing_arrears'] ?? 0); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">History of Arrears</label>
                            <input type="number" min="0" name="history_of_arrears" class="form-control" value="<?php echo (int)($student['history_of_arrears'] ?? 0); ?>" required>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">School & Diploma Records (%)</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">10th Percentage</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="tenth_percentage" class="form-control" value="<?php echo esc($student['tenth_percentage'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">12th Percentage</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="twelfth_percentage" class="form-control" value="<?php echo esc($student['twelfth_percentage'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold">Diploma Percentage</label>
                            <input type="number" step="0.01" min="0.00" max="100.00" name="diploma_percentage" class="form-control" value="<?php echo esc($student['diploma_percentage'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">Semester-wise GPA (0.00 - 10.00)</h6>
                    <div class="row">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <div class="col-6 col-md-2 mb-3">
                                <label class="form-label small fw-bold">Sem <?php echo $i; ?></label>
                                <input type="number" step="0.01" min="0.00" max="10.00" name="sem<?php echo $i; ?>_gpa" class="form-control" value="<?php echo esc($student["sem{$i}_gpa"] ?? ''); ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

