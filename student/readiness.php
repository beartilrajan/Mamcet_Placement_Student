<?php
// MAMCET Placement & Learning Portal - Placement Readiness Score Card
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

// 1. Fetch Student profile & academics
$stmtStud = $db->prepare("
    SELECT s.*, sa.current_cgpa, sa.standing_arrears
    FROM students s
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    WHERE s.student_id = ?
");
$stmtStud->execute([$studentId]);
$student = $stmtStud->fetch();

// 2. Fetch Resume upload info
$stmtResume = $db->prepare("SELECT rf.resume_id, rf.status, ra.overall_score FROM resume_files rf LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id WHERE rf.student_id = ? AND rf.is_current = 1 LIMIT 1");
$stmtResume->execute([$studentId]);
$resume = $stmtResume->fetch();

// 3. Fetch Resume Builder details
$stmtBuilder = $db->prepare("SELECT * FROM resume_builder_profiles WHERE student_id = ?");
$stmtBuilder->execute([$studentId]);
$builder = $stmtBuilder->fetch();

// 4. Fetch Course Progress
$stmtCourse = $db->prepare("SELECT COUNT(*) FROM student_course_progress WHERE student_id = ? AND status = 'Completed'");
$stmtCourse->execute([$studentId]);
$completedCourses = (int)$stmtCourse->fetchColumn();

// 5. Fetch Mock practice count
$stmtMock = $db->prepare("SELECT COUNT(*) FROM interview_attempts WHERE student_id = ? AND is_completed = 1");
$stmtMock->execute([$studentId]);
$mockAttempts = (int)$stmtMock->fetchColumn();

// -----------------------------------------------------------------------------
// DYNAMIC SCORE CALCULATIONS
// -----------------------------------------------------------------------------
$scores = [
    'profile' => 0,
    'resume_avail' => 0,
    'resume_ats' => 0,
    'academic' => 0,
    'skills' => 0,
    'projects' => 0,
    'internship' => 0,
    'certs' => 0,
    'course' => 0,
    'interview' => 0
];

$actions = [];

// 1. Profile Completion (Weight: 10)
// Check basic attributes (Aadhar, blood, DOB, address, Aadhar, parents contact)
$profilePoints = 0;
if (!empty($student['mobile_number'])) $profilePoints += 2;
if (!empty($student['dob'])) $profilePoints += 2;
if (!empty($student['aadhar_number'])) $profilePoints += 2;
if (!empty($student['willingness'])) $profilePoints += 2;
if (!empty($student['skills'])) $profilePoints += 2;
$scores['profile'] = $profilePoints;
if ($profilePoints < 10) {
    $actions[] = "Complete your basic profile contact and registry info (e.g. Aadhar, Willingness status).";
}

// 2. Resume Availability (Weight: 10)
if ($resume) {
    $scores['resume_avail'] = 10;
} else {
    $actions[] = "Upload a text-based PDF/DOCX resume file to enable recruiter search index.";
}

// 3. Resume ATS Score (Weight: 20)
if ($resume && !empty($resume['overall_score'])) {
    $scores['resume_ats'] = round((int)$resume['overall_score'] * 20 / 100);
    if ((int)$resume['overall_score'] < 75) {
        $actions[] = "Improve your ATS compatibility score (aim for 75+ by adding missing keywords).";
    }
} else {
    $actions[] = "Analyze your active resume to obtain qualitative ATS feedback.";
}

// 4. Academic Eligibility (Weight: 15)
$academicPoints = 0;
if ($student && $student['current_cgpa'] >= 6.0) $academicPoints += 5;
if ($student && $student['standing_arrears'] == 0) $academicPoints += 5;
if ($student && strtolower($student['placement_willingness']) === 'yes') $academicPoints += 5;
$scores['academic'] = $academicPoints;
if ($academicPoints < 15) {
    $actions[] = "Address backlog papers or verify your academic CGPA scores match registrar records.";
}

// 5. Technical Skills (Weight: 15)
$skillsCount = 0;
if (!empty($student['skills'])) {
    $sk = json_decode($student['skills'], true) ?: explode(',', $student['skills']);
    $skillsCount = count(array_filter($sk));
}
if ($skillsCount >= 5) {
    $scores['skills'] = 15;
} elseif ($skillsCount >= 3) {
    $scores['skills'] = 10;
    $actions[] = "Add at least 5 core technical skill tags to your profile.";
} elseif ($skillsCount > 0) {
    $scores['skills'] = 5;
    $actions[] = "Add at least 3 core technical skill tags to your profile.";
} else {
    $actions[] = "List technical skill expertise categories on your portal profile.";
}

// 6. Projects (Weight: 10)
$projCount = 0;
if ($builder && !empty($builder['projects_json'])) {
    $projCount = count(json_decode($builder['projects_json'], true) ?: []);
}
if ($projCount >= 2) {
    $scores['projects'] = 10;
} elseif ($projCount == 1) {
    $scores['projects'] = 5;
    $actions[] = "Add a second academic/personal project in your resume builder profile.";
} else {
    $actions[] = "Add academic or mini project descriptions inside your resume profile builder.";
}

// 7. Internships (Weight: 5)
$internCount = 0;
if ($builder && !empty($builder['internships_json'])) {
    $internCount = count(json_decode($builder['internships_json'], true) ?: []);
}
if ($internCount >= 1) {
    $scores['internship'] = 5;
} else {
    $actions[] = "Complete at least one corporate internship or practical training workshop.";
}

// 8. Certifications (Weight: 5)
$certsCount = 0;
if ($builder && !empty($builder['certifications_json'])) {
    $certsCount = count(json_decode($builder['certifications_json'], true) ?: []);
}
if ($certsCount >= 1) {
    $scores['certs'] = 5;
} else {
    $actions[] = "Add verified certifications in your resume profile (e.g. AWS, Oracle, Google).";
}

// 9. Course Completion (Weight: 5)
if ($completedCourses >= 1) {
    $scores['course'] = 5;
} else {
    $actions[] = "Complete at least one active LMS learning course (e.g. Aptitude, Soft Skills).";
}

// 10. Interview Practice (Weight: 5)
if ($mockAttempts >= 2) {
    $scores['interview'] = 5;
} elseif ($mockAttempts == 1) {
    $scores['interview'] = 3;
    $actions[] = "Run a second AI mock interview chat practice session.";
} else {
    $actions[] = "Complete at least one interactive AI recruiter mock interview session.";
}

// Final combined index
$totalReadiness = array_sum($scores);

// Save to DB
try {
    $stmtUpd = $db->prepare("
        INSERT INTO placement_readiness_scores (student_id, readiness_score) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE readiness_score = VALUES(readiness_score)
    ");
    $stmtUpd->execute([$studentId, $totalReadiness]);
} catch (Exception $ex) {
    // Silently continue
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-award text-primary"></i> Campus Placement Readiness Index</h1>

            <!-- COMPULSORY DISCLAIMER BANNER -->
            <div class="alert alert-light border shadow-sm mb-4" role="alert" style="border-left: 5px solid #6c757d !important;">
                <h6 class="font-weight-bold text-muted"><i class="fa-solid fa-circle-info"></i> Portal Guidance</h6>
                <small class="text-muted">This scorecard represents an internal preparation indicator. It does not constitute a guaranteed placement probability or direct recruitment endorsement. Score assessments help students check and refine skills prior to corporate drives.</small>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left: Circular Gauge meter and category progress bars -->
                <div class="col-lg-5">
                    <div class="card shadow mb-4 text-center">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="m-0 font-weight-bold">Readiness Index Score</h6>
                        </div>
                        <div class="card-body py-5">
                            <div class="d-inline-flex position-relative mb-4 align-items-center justify-content-center" style="width: 150px; height: 150px;">
                                <div class="position-absolute" style="font-size: 2.5rem; font-weight: 800; color: #4e73df;">
                                    <?php echo $totalReadiness; ?><small style="font-size:0.9rem; font-weight:400; color:#aaa;">/100</small>
                                </div>
                                <svg class="w-100 h-100" style="transform: rotate(-90deg);">
                                    <circle cx="75" cy="75" r="65" stroke="#f3f3f3" stroke-width="12" fill="transparent"/>
                                    <circle cx="75" cy="75" r="65" stroke="#4e73df" stroke-width="12" fill="transparent"
                                            stroke-dasharray="408" stroke-dashoffset="<?php echo 408 - (408 * $totalReadiness / 100); ?>"/>
                                </svg>
                            </div>
                            <h5 class="font-weight-bold mt-2 text-dark">Portal Evaluation Rating</h5>
                        </div>
                    </div>

                    <!-- Category scores list -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light">
                            <h6 class="m-0 font-weight-bold text-dark">Weighted Category Scorecards</h6>
                        </div>
                        <div class="card-body" style="font-size:0.85rem;">
                            <!-- Profile Completion -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Profile Registry Fields</span>
                                    <span><?php echo $scores['profile']; ?> / 10</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['profile']/10)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Resume uploaded -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Resume Uploaded</span>
                                    <span><?php echo $scores['resume_avail']; ?> / 10</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['resume_avail']/10)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- ATS compatibility -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>ATS Score Rating</span>
                                    <span><?php echo $scores['resume_ats']; ?> / 20</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['resume_ats']/20)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Academic eligibility -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Academic Eligibility</span>
                                    <span><?php echo $scores['academic']; ?> / 15</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['academic']/15)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Skills -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Core Skills Listed</span>
                                    <span><?php echo $scores['skills']; ?> / 15</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['skills']/15)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Projects -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Academic Projects</span>
                                    <span><?php echo $scores['projects']; ?> / 10</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['projects']/10)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Internships -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Corporate Internships</span>
                                    <span><?php echo $scores['internship']; ?> / 5</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['internship']/5)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Certifications -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Professional Certifications</span>
                                    <span><?php echo $scores['certs']; ?> / 5</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['certs']/5)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Course Progress -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>LMS Course Completion</span>
                                    <span><?php echo $scores['course']; ?> / 5</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['course']/5)*100; ?>%"></div>
                                </div>
                            </div>
                            <!-- Mock attempts -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Mock Recruiters Practice</span>
                                    <span><?php echo $scores['interview']; ?> / 5</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo ($scores['interview']/5)*100; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Checklist of prioritize actions -->
                <div class="col-lg-7">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-dark text-white py-3">
                            <h6 class="m-0 font-weight-bold"><i class="fa-solid fa-list-check"></i> Recommended Next Actions (Prioritized Checklist)</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($actions)): ?>
                                <div class="text-center py-5 text-success">
                                    <i class="fa-solid fa-trophy fa-3x mb-3"></i>
                                    <h5>Excellent! You have completed all checklist items!</h5>
                                    <p class="text-muted">Keep checking for new drive notifications.</p>
                                </div>
                            <?php else: ?>
                                <p class="text-muted"><i class="fa-solid fa-circle-info"></i> Improve your score by checking off the missing parameters listed below:</p>
                                <ul class="list-group list-group-flush mt-3" style="font-size: 0.95rem;">
                                    <?php foreach ($actions as $idx => $act): ?>
                                        <li class="list-group-item d-flex align-items-start py-3">
                                            <i class="fa-solid fa-circle-chevron-right text-primary mt-1 me-3"></i>
                                            <span><?php echo htmlspecialchars($act); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
