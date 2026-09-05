<?php
// MAMCET Placement & Learning Portal - Student Dashboard

// Locate root directory dynamically across any server directory layout
function resolveAppPath($relativePath) {
    $relativePath = ltrim($relativePath, '/\\');
    $candidates = [
        dirname(__DIR__) . '/' . $relativePath,
        __DIR__ . '/../' . $relativePath,
        dirname(dirname(__DIR__)) . '/' . $relativePath,
        dirname(__DIR__) . '/Mamcet_Placement_Student/' . $relativePath,
        __DIR__ . '/' . $relativePath,
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/Beartil/Mamcet/Mamcet_Placement_Student/' . $relativePath,
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . $relativePath
    ];

    foreach ($candidates as $c) {
        if (!empty($c) && file_exists($c)) {
            return realpath($c) ?: $c;
        }
    }
    return null;
}

$headerPath = resolveAppPath('includes/header.php');
if (!$headerPath) {
    // Diagnostic screen if includes/ directory is missing or misplaced on the hosting server
    $parentDir = dirname(__DIR__);
    $dirContents = is_dir($parentDir) ? scandir($parentDir) : [];
    $filtered = array_diff($dirContents, ['.', '..']);
    
    echo "<!DOCTYPE html><html><head><title>Setup Notice - MAMCET Placement Portal</title>";
    echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'>";
    echo "</head><body class='bg-light py-5'>";
    echo "<div class='container' style='max-width:720px;'>";
    echo "<div class='card shadow-sm border-danger'>";
    echo "<div class='card-header bg-danger text-white fw-bold py-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>Missing 'includes' Directory on Server</div>";
    echo "<div class='card-body p-4'>";
    echo "<p class='lead mb-3'>The server cannot locate <code>includes/header.php</code>.</p>";
    echo "<p class='mb-1'><strong>Current Script Path:</strong> <code>" . htmlspecialchars(__DIR__) . "</code></p>";
    echo "<p class='mb-2'><strong>Looked inside Root Folder:</strong> <code>" . htmlspecialchars($parentDir) . "</code></p>";
    echo "<p class='fw-bold mb-1 mt-3'>Files & Folders currently found in this directory:</p>";
    echo "<pre class='bg-dark text-light p-3 rounded small mb-4'>" . htmlspecialchars(implode("\n", $filtered ?: ['(Directory is empty or inaccessible)'])) . "</pre>";
    echo "<div class='alert alert-warning py-2 mb-3'><strong>How to fix:</strong> In your cPanel / Hostinger File Manager, upload the <code>includes</code> folder to <code>" . htmlspecialchars($parentDir) . "</code> (ensure it is named lowercase <code>includes</code>).</div>";
    echo "</div></div></div></body></html>";
    exit;
}

require_once($headerPath);

$sidebarPath = resolveAppPath('includes/sidebar.php');
if ($sidebarPath) {
    require_once($sidebarPath);
}

$pageTitle = 'Student Dashboard';

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login. Contact Placement Officer.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$studentId = $student['student_id'];

// 1. Calculate Profile Completion
$profileCompletion = calculateProfileCompletion($db, $studentId);

// 2. Fetch Placement Announcements matching student dept and batch
$stmtAnn = $db->prepare("
    SELECT a.* 
    FROM announcements a
    JOIN announcement_departments ad ON a.announcement_id = ad.announcement_id
    JOIN announcement_batches ab ON a.announcement_id = ab.announcement_id
    WHERE a.status = 'published' 
      AND a.session_id = ? 
      AND ad.dept_id = ? 
      AND ab.batch_id = ?
      AND a.expiry_date >= CURDATE()
    ORDER BY a.priority DESC, a.publish_date DESC 
    LIMIT 4
");
$stmtAnn->execute([$activeSessionId, $student['dept_id'], $student['batch_id']]);
$announcements = $stmtAnn->fetchAll();

// 3. Fetch LMS Courses Progress
$stmtCourses = $db->prepare("
    SELECT c.course_id, c.course_title, c.course_code, scp.completion_percentage, scp.status 
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN student_course_progress scp ON c.course_id = scp.course_id AND scp.student_id = ?
    WHERE ca.session_id = ? AND (
        c.course_id IN (SELECT course_id FROM course_departments WHERE dept_id = ?)
        OR c.course_id IN (SELECT course_id FROM course_batches WHERE batch_id = ?)
    )
    GROUP BY c.course_id
    LIMIT 3
");
$stmtCourses->execute([$studentId, $activeSessionId, $student['dept_id'], $student['batch_id']]);
$courses = $stmtCourses->fetchAll();

// 4. Fetch overall progress metrics
$stmtProg = $db->prepare("
    SELECT COUNT(*) FROM student_lesson_progress WHERE student_id = ?
");
$stmtProg->execute([$studentId]);
$completedLessonsCount = (int)$stmtProg->fetchColumn();

// 5. Fetch ATS Resume Analysis Data
$stmtAts = $db->prepare("
    SELECT ra.*, rf.filename, rf.uploaded_at
    FROM resume_files rf
    LEFT JOIN resume_analysis ra ON rf.resume_id = ra.resume_id
    WHERE rf.student_id = ? AND rf.is_current = 1
    ORDER BY ra.analyzed_at DESC
    LIMIT 1
");
$stmtAts->execute([$studentId]);
$atsData = $stmtAts->fetch();

$atsScore = ($atsData && !is_null($atsData['overall_score'])) ? (int)$atsData['overall_score'] : -1;
$atsTag = 'Not Scanned';
$atsBadge = 'bg-secondary';
$atsBadgeClass = 'needs-action';
$atsColor = '#64748b';
if ($atsScore >= 90) {
    $atsTag = 'Excellent'; $atsBadge = 'bg-success'; $atsBadgeClass = 'excellent'; $atsColor = '#10b981';
} elseif ($atsScore >= 75) {
    $atsTag = 'Very Good'; $atsBadge = 'bg-info text-dark'; $atsBadgeClass = 'very-good'; $atsColor = '#06b6d4';
} elseif ($atsScore >= 60) {
    $atsTag = 'Good'; $atsBadge = 'bg-primary'; $atsBadgeClass = 'good'; $atsColor = '#2563eb';
} elseif ($atsScore >= 40) {
    $atsTag = 'Basic'; $atsBadge = 'bg-warning text-dark'; $atsBadgeClass = 'basic'; $atsColor = '#f59e0b';
} elseif ($atsScore >= 0) {
    $atsTag = 'Needs Action'; $atsBadge = 'bg-danger'; $atsBadgeClass = 'needs-action'; $atsColor = '#ef4444';
}

// 6. Fetch LeetCode Account Info
$lcUsername = '';
try {
    $stmtLC = $db->prepare("SELECT leetcode_username FROM leetcode_profiles WHERE student_id = ?");
    $stmtLC->execute([$studentId]);
    $lcProfile = $stmtLC->fetch();
    $lcUsername = $lcProfile ? $lcProfile['leetcode_username'] : '';
} catch (Exception $e) {
    $lcUsername = '';
}

// 8. Fetch Daily Aptitude & Streak Info
require_once(__DIR__ . '/../services/AptitudeService.php');
$aptitudeStreak = AptitudeService::getStudentStreakStats($db, $studentId);

// 9. Fetch Student Certifications
ensureCertificationsTable($db);
$stmtCertCount = $db->prepare("SELECT COUNT(*) FROM student_certifications WHERE student_id = ?");
$stmtCertCount->execute([$studentId]);
$totalCertificatesCount = (int)$stmtCertCount->fetchColumn();

$stmtRecentCerts = $db->prepare("
    SELECT cert_id, title, issuing_organization, file_name, file_path, file_size, status, created_at
    FROM student_certifications
    WHERE student_id = ?
    ORDER BY created_at DESC
    LIMIT 4
");
$stmtRecentCerts->execute([$studentId]);
$recentCertificates = $stmtRecentCerts->fetchAll(PDO::FETCH_ASSOC);

$standingArrears = (int)($student['standing_arrears'] ?? 0);
$hasArrears = $standingArrears > 0;
$currentCgpa = number_format((float)($student['current_cgpa'] ?? 0), 2);
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <div class="db-container">

            <!-- 1. WELCOME HERO BANNER -->
            <div class="card border-0 text-white shadow-sm overflow-hidden db-hero-card">
                <div class="db-hero-body">
                    <div class="d-flex flex-column flex-xl-row justify-content-between gap-3 gap-xl-4 align-items-xl-center">
                        <div class="flex-grow-1 min-w-0">
                            <!-- Compact Quick Chip for Mobile & Tablet Glance -->
                            <div class="db-hero-mobile-chip d-xl-none mb-2">
                                <span class="badge db-hero-academic-chip">
                                    <i class="fa-solid fa-award text-warning"></i>
                                    <span>CGPA <strong><?php echo esc($currentCgpa); ?></strong></span>
                                    <span class="chip-divider">·</span>
                                    <span class="<?php echo $hasArrears ? 'text-warning' : 'text-emerald'; ?>">
                                        <i class="fa-solid <?php echo $hasArrears ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> me-0.5"></i>
                                        <?php echo $hasArrears ? $standingArrears . ' Arrear' . ($standingArrears > 1 ? 's' : '') : '0 Arrears'; ?>
                                    </span>
                                </span>
                            </div>
                            
                            <h2 class="db-hero-title">Welcome back, <?php echo esc($student['student_name']); ?>! 👋</h2>
                            
                            <p class="db-hero-copy">
                                Track active campus recruitment drives, monitor your ATS resume readiness, and practice daily aptitude challenges.
                            </p>
                            
                            <div class="db-hero-actions">
                                <a href="placements.php" class="btn btn-light db-btn-primary-action">
                                    <i class="fa-solid fa-bullhorn text-primary"></i> <span>Browse Drives</span>
                                </a>
                                <div class="db-hero-secondary-actions">
                                    <a href="ats-analysis.php" class="btn btn-outline-light">
                                        <i class="fa-solid fa-file-waveform"></i> <span>ATS Resume</span>
                                    </a>
                                    <button type="button" class="btn btn-outline-light" onclick="openUploadCertModal()">
                                        <i class="fa-solid fa-certificate"></i> <span>Upload Cert</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Academic & Eligibility Badge (Full card for Desktop) -->
                        <div class="db-hero-metric-box flex-shrink-0 d-none d-xl-flex">
                            <div class="metric-icon-circle">
                                <i class="fa-solid fa-award"></i>
                            </div>
                            <div class="metric-info-wrap">
                                <span class="db-hero-metric-label">Current CGPA</span>
                                <span class="db-hero-metric-value"><?php echo esc($currentCgpa); ?></span>
                                <span class="db-hero-metric-meta">
                                    <i class="fa-solid <?php echo $hasArrears ? 'fa-triangle-exclamation text-warning' : 'fa-circle-check text-success'; ?> me-1"></i>
                                    <?php echo $hasArrears ? $standingArrears . ' Standing Arrear(s)' : '0 Standing Arrears'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. SPACIOUS AT-A-GLANCE METRIC TILES -->
            <div>
                <div class="db-section-header">
                    <div>
                        <h4 class="db-section-title">
                            <i class="fa-solid fa-chart-pie"></i> Placement Readiness Overview
                        </h4>
                    </div>
                    <span class="db-section-subtitle">Live profile snapshot</span>
                </div>

                <div class="db-metrics-grid">
                    <!-- Tile 1: Placement Status -->
                    <div class="db-stat-tile tile-blue">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Placement Status</span>
                            <div class="db-stat-icon primary">
                                <i class="fa-solid fa-briefcase"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo esc($student['placement_status'] === 'Placed' ? 'Placed' : 'Seeking Drive'); ?></div>
                        <div class="db-stat-footer">
                            <span>Willing: <strong class="text-dark"><?php echo esc($student['placement_willingness']); ?></strong></span>
                            <span class="badge <?php echo $student['placement_status'] === 'Placed' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'; ?> rounded-pill px-2.5 py-1">
                                <i class="fa-solid fa-circle-dot me-1" style="font-size: 0.55rem;"></i> <?php echo esc($student['placement_status']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Tile 2: Daily Aptitude Streak -->
                    <a href="aptitude.php" class="db-stat-tile tile-amber">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Aptitude Streak</span>
                            <div class="db-stat-icon warning">
                                <i class="fa-solid fa-fire"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $aptitudeStreak['current_streak']; ?> <span style="font-size:0.95rem; font-weight:600; color:var(--text-muted);">Days</span></div>
                        <div class="db-stat-footer">
                            <span><?php echo $aptitudeStreak['completed_today'] ? 'Score: <strong>' . $aptitudeStreak['today_score'] . '/5</strong>' : '5 questions ready'; ?></span>
                            <span class="badge <?php echo $aptitudeStreak['completed_today'] ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'; ?> rounded-pill px-2.5 py-1">
                                <?php echo $aptitudeStreak['completed_today'] ? '✓ Done Today' : '⚡ Pending'; ?>
                            </span>
                        </div>
                    </a>

                    <!-- Tile 3: ATS Resume Health -->
                    <a href="ats-analysis.php" class="db-stat-tile tile-emerald">
                        <div class="db-stat-header">
                            <span class="db-stat-label">ATS Resume Score</span>
                            <div class="db-stat-icon success">
                                <i class="fa-solid fa-file-waveform"></i>
                            </div>
                        </div>
                        <div class="db-stat-value"><?php echo $atsScore >= 0 ? $atsScore . '%' : 'Not Scanned'; ?></div>
                        <div class="db-stat-footer">
                            <span><?php echo $atsScore >= 0 ? 'Rule: ' . (int)($atsData['rule_score'] ?? 0) . '/60' : 'Run instant scan'; ?></span>
                            <span class="badge <?php echo $atsBadge; ?> rounded-pill px-2.5 py-1">
                                <?php echo $atsTag; ?>
                            </span>
                        </div>
                    </a>

                    <!-- Tile 4: LeetCode Profile -->
                    <a href="leetcode.php" class="db-stat-tile tile-purple">
                        <div class="db-stat-header">
                            <span class="db-stat-label">LeetCode Profile</span>
                            <div class="db-stat-icon purple">
                                <svg style="width:20px;height:20px;" viewBox="0 0 24 24" fill="#8b5cf6">
                                    <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1-.028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="db-stat-value" style="font-size: 1.25rem;">
                            <?php echo !empty($lcUsername) ? '@' . esc($lcUsername) : 'Not Linked'; ?>
                        </div>
                        <div class="db-stat-footer">
                            <span><?php echo !empty($lcUsername) ? 'Live Sync' : 'Connect account'; ?></span>
                            <span class="badge <?php echo !empty($lcUsername) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?> rounded-pill px-2.5 py-1">
                                <?php echo !empty($lcUsername) ? '✓ Synced' : 'Action'; ?>
                            </span>
                        </div>
                    </a>

                    <!-- Tile 5: Uploaded Certificates -->
                    <a href="certificates.php" class="db-stat-tile tile-teal" style="text-decoration: none;">
                        <div class="db-stat-header">
                            <span class="db-stat-label">Certificates</span>
                            <div class="db-stat-icon" style="background: #f0fdfa; color: #0d9488;">
                                <i class="fa-solid fa-certificate"></i>
                            </div>
                        </div>
                        <div class="db-stat-value" id="dbCertCountDisplay"><?php echo $totalCertificatesCount; ?></div>
                        <div class="db-stat-footer">
                            <span><?php echo $totalCertificatesCount > 0 ? 'Verified & In Review' : 'Upload credentials'; ?></span>
                            <span class="badge bg-success-subtle text-success rounded-pill px-2.5 py-1">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i> Manage
                            </span>
                        </div>
                    </a>
                </div>
            </div>

            <!-- 3. MAIN DASHBOARD CONTENT GRID (7:5 SPLIT) -->
            <div class="row g-4">

                <!-- LEFT COLUMN: Campus Recruitment & LMS Learning (Col-lg-7) -->
                <div class="col-lg-7 col-12 d-flex flex-column gap-4">

                    <!-- WIDGET 1: Campus Recruitment Drives -->
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-bullhorn text-primary"></i>
                                Campus Recruitment Drives
                            </h5>
                            <a href="placements.php" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.78rem;">
                                View All <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="db-card-body">
                            <?php if (empty($announcements)): ?>
                                <div class="db-empty-hub">
                                    <div class="db-empty-hub-icon">
                                        <i class="fa-solid fa-bullhorn"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1" style="font-size: 1.05rem;">No Active Drives Posted Right Now</h6>
                                    <p class="text-muted small mb-3" style="max-width: 440px; margin: 0 auto; line-height: 1.45;">
                                        Upcoming recruitment announcements for your batch (<strong><?php echo esc($student['batch_name'] ?? 'Active Batch'); ?></strong>) will appear here as soon as company drives open.
                                    </p>
                                    <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap">
                                        <a href="placements.php" class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fw-semibold" style="font-size: 0.8rem;">
                                            <i class="fa-solid fa-clipboard-check me-1"></i> Check Placement Rules
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column">
                                    <?php foreach ($announcements as $ann): ?>
                                        <a href="placements.php?announcement_id=<?php echo $ann['announcement_id']; ?>" class="db-drive-item">
                                            <div class="d-flex align-items-start gap-3">
                                                <div class="db-drive-avatar">
                                                    <?php echo strtoupper(substr($ann['company_name'] ?: ($ann['title'] ?? 'D'), 0, 2)); ?>
                                                </div>
                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
                                                        <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.95rem;">
                                                            <?php echo esc($ann['title']); ?>
                                                        </h6>
                                                        <span class="badge <?php echo $ann['priority'] === 'High' ? 'bg-danger-subtle text-danger' : ($ann['priority'] === 'Medium' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'); ?> rounded-pill px-2 py-1" style="font-size: 0.72rem; font-weight: 600;">
                                                            <?php echo $ann['priority']; ?> Priority
                                                        </span>
                                                    </div>
                                                    
                                                    <p class="text-muted small mb-2 text-truncate" style="max-width: 480px;">
                                                        <?php echo esc(strip_tags($ann['description'])); ?>
                                                    </p>

                                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-1 border-top border-light">
                                                        <span class="small fw-semibold text-primary">
                                                            <i class="fa-solid fa-building me-1"></i>
                                                            <?php echo esc($ann['company_name'] ?: 'Campus Drive'); ?>
                                                        </span>
                                                        <span class="db-drive-badge-deadline">
                                                            <i class="fa-regular fa-clock text-muted"></i>
                                                            Deadline: <?php echo date('d M Y', strtotime($ann['expiry_date'])); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- WIDGET 2: LMS Learning & Skill Tracks -->
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-book-open text-primary"></i>
                                Enrolled Learning Tracks
                            </h5>
                            <a href="learning.php" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.78rem;">
                                All Courses <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="db-card-body">
                            <?php if (empty($courses)): ?>
                                <div class="text-center py-4 text-muted">
                                    <div class="mb-2">
                                        <span class="d-inline-flex p-3 rounded-circle bg-light text-secondary">
                                            <i class="fa-solid fa-graduation-cap fa-2x opacity-50"></i>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">No Courses Uploaded Yet</h6>
                                    <p class="small text-muted mb-0" style="max-width: 380px; margin: 0 auto; line-height: 1.4;">
                                        Currently no LMS learning courses are assigned or uploaded for your batch.
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column">
                                    <?php foreach ($courses as $c): 
                                        $pct = (int)($c['completion_percentage'] ?? 0);
                                        $isComplete = $pct >= 100;
                                    ?>
                                        <div class="db-course-item">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <span class="badge bg-light text-secondary border me-1" style="font-size: 0.7rem;">
                                                        <?php echo esc($c['course_code']); ?>
                                                    </span>
                                                    <strong class="text-dark" style="font-size: 0.9rem;">
                                                        <?php echo esc($c['course_title']); ?>
                                                    </strong>
                                                </div>
                                                <span class="badge <?php echo $isComplete ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'; ?> rounded-pill px-2" style="font-size: 0.72rem;">
                                                    <?php echo $pct; ?>% Completed
                                                </span>
                                            </div>
                                            
                                            <div class="progress" style="height: 6px; border-radius: 999px; background-color: #f1f5f9;">
                                                <div class="progress-bar <?php echo $isComplete ? 'bg-success' : 'bg-primary'; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $pct; ?>%;" 
                                                     aria-valuenow="<?php echo $pct; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center mt-2 pt-1">
                                                <small class="text-muted" style="font-size: 0.75rem;">
                                                    Status: <span class="fw-semibold text-dark"><?php echo esc($c['status'] ?? 'In Progress'); ?></span>
                                                </small>
                                                <a href="course-view.php?course_id=<?php echo $c['course_id']; ?>" class="small text-primary fw-bold text-decoration-none">
                                                    Continue Lesson <i class="fa-solid fa-chevron-right ms-1" style="font-size: 0.7rem;"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex align-items-center justify-content-between pt-3 mt-1 border-top">
                                    <span class="small text-muted">
                                        <i class="fa-solid fa-check-circle text-success me-1"></i>
                                        <strong><?php echo $completedLessonsCount; ?></strong> Lessons finished so far
                                    </span>
                                    <a href="learning.php" class="small text-primary fw-bold text-decoration-none">View Progress</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- WIDGET: Student Certificates Portfolio -->
                    <div class="db-card-modern">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-certificate text-primary"></i>
                                Certifications & Credentials
                            </h5>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" style="font-size: 0.78rem;" onclick="openUploadCertModal()">
                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload
                                </button>
                                <a href="certificates.php" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.78rem;">
                                    View All (<?php echo $totalCertificatesCount; ?>) <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="db-card-body" id="dashboardCertListContainer">
                            <?php if (empty($recentCertificates)): ?>
                                <div class="text-center py-4 text-muted">
                                    <div class="mb-2">
                                        <span class="d-inline-flex p-3 rounded-circle" style="background: #f0fdfa; color: #0d9488;">
                                            <i class="fa-solid fa-award fa-2x"></i>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No Certificates Uploaded Yet</h6>
                                    <p class="small text-muted mb-3 mx-auto" style="max-width: 380px;">
                                        Add your certifications from Coursera, NPTEL, Udemy, or college technical events to strengthen your placement profile.
                                    </p>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-4" onclick="openUploadCertModal()">
                                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Certificate Now
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($recentCertificates as $rc): ?>
                                        <?php
                                        $status = $rc['status'];
                                        $badgeClass = 'bg-warning-subtle text-warning';
                                        if ($status === 'Verified') $badgeClass = 'bg-success-subtle text-success';
                                        elseif ($status === 'Rejected') $badgeClass = 'bg-danger-subtle text-danger';

                                        $rcFile = $rc['file_name'] ?: basename($rc['file_path'] ?? 'document');
                                        $rcExt = strtolower(pathinfo($rcFile, PATHINFO_EXTENSION));
                                        $rcIcon = $rcExt === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
                                        ?>
                                        <?php
                                        $rcIsPdf = $rcExt === 'pdf';
                                        $rcViewUrl = "../api/certificate-file.php?cert_id=" . $rc['cert_id'] . "&mode=view";
                                        $rcDownloadUrl = "../api/certificate-file.php?cert_id=" . $rc['cert_id'] . "&mode=download";
                                        ?>
                                        <div class="p-3 rounded-3 border bg-white shadow-xs d-flex justify-content-between align-items-center flex-wrap gap-2 transition-smooth">
                                            <div class="d-flex align-items-center gap-3 min-w-0">
                                                <?php if ($rcIsPdf): ?>
                                                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; background: #fee2e2; border: 1px solid #fecaca; color: #dc2626;">
                                                        <i class="fa-solid fa-file-pdf fa-lg"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <img src="<?php echo $rcViewUrl; ?>" 
                                                         alt="<?php echo esc($rc['title']); ?>" 
                                                         class="rounded-3 border flex-shrink-0" 
                                                         style="width: 42px; height: 42px; object-fit: cover;"
                                                         loading="lazy"
                                                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'rounded-3 bg-light p-2 border d-flex align-items-center justify-content-center flex-shrink-0\' style=\'width:42px; height:42px;\'><i class=\'fa-solid fa-award text-teal\'></i></div>';">
                                                <?php endif; ?>
                                                <div class="min-w-0">
                                                    <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.92rem;" title="<?php echo esc($rc['title']); ?>">
                                                        <?php echo esc($rc['title']); ?>
                                                    </h6>
                                                    <div class="text-muted small" style="font-size: 0.76rem;">
                                                        <span class="fw-semibold text-secondary"><i class="fa-solid fa-building-columns text-teal me-1"></i><?php echo esc($rc['issuing_organization'] ?: 'Credential'); ?></span>
                                                        • <span><i class="fa-regular fa-clock me-1"></i><?php echo date('d M Y', strtotime($rc['created_at'])); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                <span class="badge <?php echo $badgeClass; ?> rounded-pill px-2 py-1 small" style="font-size: 0.72rem;">
                                                    <?php echo esc($status); ?>
                                                </span>
                                                <a href="<?php echo $rcViewUrl; ?>" target="_blank" class="btn btn-xs btn-outline-primary rounded-pill px-2.5 py-1" title="View Certificate">
                                                    <i class="fa-solid fa-eye me-1"></i> View
                                                </a>
                                                <a href="<?php echo $rcDownloadUrl; ?>" class="btn btn-xs btn-outline-secondary rounded-pill px-2.5 py-1" title="Download">
                                                    <i class="fa-solid fa-download"></i>
                                                </a>
                                                <button type="button" class="btn btn-xs btn-outline-danger rounded-pill px-2 py-1" onclick="deleteDashboardCert(<?php echo $rc['cert_id']; ?>, '<?php echo addslashes($rc['title']); ?>')" title="Delete">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN: ATS & LeetCode Readiness (Col-lg-5) -->
                <div class="col-lg-5 col-12 d-flex flex-column gap-4">

                    <!-- WIDGET 3: ATS Resume Score & Insights -->
                    <div class="db-card-modern db-ats-widget-card">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <i class="fa-solid fa-file-waveform text-primary"></i>
                                ATS Resume Diagnostics
                            </h5>
                            <a href="ats-analysis.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 0.78rem;">
                                <span>Report</span>
                                <i class="fa-solid fa-arrow-right" style="font-size: 0.7rem;"></i>
                            </a>
                        </div>
                        <div class="db-card-body">
                            <?php if ($atsData && !is_null($atsData['overall_score'])): ?>
                                <?php 
                                $priorityActions = !empty($atsData['priority_actions']) ? json_decode($atsData['priority_actions'], true) : [];
                                $missingKeywords = !empty($atsData['missing_keywords']) ? json_decode($atsData['missing_keywords'], true) : [];
                                $overallScoreVal = (int)$atsData['overall_score'];
                                $ruleScoreVal = (int)($atsData['rule_score'] ?? 0);
                                $aiScoreVal = (int)($atsData['ai_score'] ?? 0);
                                $rulePct = min(100, max(0, round(($ruleScoreVal / 60) * 100)));
                                $aiPct = min(100, max(0, round(($aiScoreVal / 40) * 100)));
                                $dashoffset = round(238.76 - (238.76 * min(100, max(0, $overallScoreVal)) / 100), 2);
                                ?>
                                <!-- Top Hero: Radial Gauge + Mini Meters -->
                                <div class="db-ats-hero-box">
                                    <div class="db-ats-donut-rel">
                                        <svg class="db-ats-donut-svg" viewBox="0 0 100 100">
                                            <circle cx="50" cy="50" r="38" fill="none" stroke="#f1f5f9" stroke-width="8"></circle>
                                            <circle cx="50" cy="50" r="38" fill="none" stroke="<?php echo $atsColor; ?>" stroke-width="8" stroke-linecap="round" stroke-dasharray="238.76" stroke-dashoffset="<?php echo $dashoffset; ?>"></circle>
                                        </svg>
                                        <div class="db-ats-donut-center">
                                            <div class="db-ats-donut-score" style="color: <?php echo $atsColor; ?>;"><?php echo $overallScoreVal; ?>%</div>
                                            <div class="db-ats-donut-unit">Score</div>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div>
                                                <h6 class="fw-bold text-dark mb-0" style="font-size: 0.92rem; letter-spacing: -0.01em;">Resume Health</h6>
                                                <div class="text-secondary small d-flex align-items-center gap-1 mt-0.5" style="font-size: 0.74rem;">
                                                    <i class="fa-regular fa-calendar-check text-muted"></i>
                                                    <span>Scanned <?php echo date('d M Y', strtotime($atsData['analyzed_at'])); ?></span>
                                                </div>
                                            </div>
                                            <span class="db-ats-badge <?php echo $atsBadgeClass; ?>">
                                                <span class="db-ats-badge-dot"></span>
                                                <?php echo $atsTag; ?>
                                            </span>
                                        </div>

                                        <!-- Mini Score Breakdown Meters -->
                                        <div class="d-flex flex-column gap-2 mt-2.5">
                                            <div class="db-ats-meter-item">
                                                <div class="d-flex justify-content-between align-items-center mb-1.5" style="font-size: 0.72rem;">
                                                    <span class="text-secondary fw-semibold d-flex align-items-center gap-1">
                                                        <i class="fa-solid fa-shield-halved text-primary" style="font-size: 0.7rem;"></i> Rule Check
                                                    </span>
                                                    <span class="db-ats-meter-ratio">
                                                        <strong><?php echo $ruleScoreVal; ?></strong><span class="text-muted">/60</span>
                                                    </span>
                                                </div>
                                                <div class="db-ats-progress-track">
                                                    <div class="db-ats-progress-bar bg-primary" style="width: <?php echo $rulePct; ?>%;"></div>
                                                </div>
                                            </div>
                                            <div class="db-ats-meter-item">
                                                <div class="d-flex justify-content-between align-items-center mb-1.5" style="font-size: 0.72rem;">
                                                    <span class="text-secondary fw-semibold d-flex align-items-center gap-1">
                                                        <i class="fa-solid fa-brain text-success" style="font-size: 0.7rem;"></i> AI Role Match
                                                    </span>
                                                    <span class="db-ats-meter-ratio">
                                                        <strong><?php echo $aiScoreVal; ?></strong><span class="text-muted">/40</span>
                                                    </span>
                                                </div>
                                                <div class="db-ats-progress-track">
                                                    <div class="db-ats-progress-bar bg-success" style="width: <?php echo $aiPct; ?>%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Priority AI Suggestion -->
                                <?php if (!empty($priorityActions)): ?>
                                    <div class="db-ats-insight-card">
                                        <div class="db-ats-insight-header">
                                            <div class="db-ats-insight-title">
                                                <span class="db-ats-insight-icon-wrap">
                                                    <i class="fa-solid fa-lightbulb"></i>
                                                </span>
                                                <span>Priority Suggestion</span>
                                            </div>
                                            <span class="db-ats-impact-badge">High Impact</span>
                                        </div>
                                        <div class="db-ats-insight-body">
                                            <?php echo esc(is_array($priorityActions) ? ($priorityActions[0] ?? 'Review ATS recommendations') : $priorityActions); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Suggested Keywords -->
                                <?php if (!empty($missingKeywords) && is_array($missingKeywords)): ?>
                                    <div class="db-ats-keywords-section">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="db-ats-kw-heading">
                                                <i class="fa-solid fa-tags text-primary opacity-75"></i> Suggested Industry Keywords:
                                            </span>
                                            <span class="text-muted" style="font-size: 0.7rem; font-weight: 500;">
                                                +<?php echo count($missingKeywords); ?> keywords
                                            </span>
                                        </div>
                                        <div class="db-ats-kw-pills">
                                            <?php foreach (array_slice($missingKeywords, 0, 4) as $kw): ?>
                                                <span class="db-ats-kw-chip">
                                                    <i class="fa-solid fa-plus db-ats-chip-icon"></i>
                                                    <?php echo esc($kw); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- CTA Action Button -->
                                <a href="ats-analysis.php" class="db-ats-cta-btn">
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                                        <span>View Detailed ATS Report</span>
                                    </span>
                                    <i class="fa-solid fa-arrow-right db-ats-cta-arrow"></i>
                                </a>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <div class="mb-3">
                                        <span class="d-inline-flex p-3 rounded-circle" style="background: rgba(37, 99, 235, 0.08); color: #2563eb;">
                                            <i class="fa-solid fa-file-waveform fa-2x"></i>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Instant Resume Scoring</h6>
                                    <p class="small text-muted mb-3" style="max-width: 340px; margin: 0 auto; line-height: 1.45;">Upload your resume to check formatting issues, missing keywords, and recruiter search compatibility.</p>
                                    <a href="ats-analysis.php" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold shadow-sm">
                                        <i class="fa-solid fa-bolt me-1"></i> Run ATS Scan Now
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- WIDGET 4: LeetCode Statistics Card -->
                    <div class="db-card-modern db-lc-widget-card">
                        <div class="db-card-header">
                            <h5 class="db-card-title">
                                <svg class="db-lc-icon" viewBox="0 0 24 24" fill="#ffa116">
                                    <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1-.028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                                </svg>
                                LeetCode Problem Stats
                            </h5>
                            <a href="leetcode.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 0.78rem;">
                                <span>Profile</span>
                                <i class="fa-solid fa-arrow-right" style="font-size: 0.7rem;"></i>
                            </a>
                        </div>
                        <div class="db-card-body">
                            <?php if (!empty($lcUsername)): ?>
                                <div id="db-lc-skeleton" class="py-4 text-center">
                                    <div class="spinner-border text-warning mb-2" role="status" style="width: 1.85rem; height: 1.85rem;">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <div class="text-secondary small fw-medium">Synchronizing coding statistics...</div>
                                </div>

                                <div id="db-lc-content" style="display:none;">
                                    <!-- User Header -->
                                    <div class="db-lc-user-row mb-3 pb-2 border-bottom d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-3 min-w-0">
                                            <img id="db-lc-avatar" src="" alt="Avatar" class="db-lc-avatar">
                                            <div class="min-w-0">
                                                <h6 id="db-lc-name" class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.92rem; letter-spacing: -0.01em;"></h6>
                                                <div class="text-secondary small text-truncate" style="font-size: 0.76rem; font-weight: 500;">@<?php echo esc($lcUsername); ?></div>
                                            </div>
                                        </div>
                                        <span class="db-lc-live-badge">
                                            <span class="db-lc-live-dot"></span> Live
                                        </span>
                                    </div>

                                    <!-- Donut and Stat Rows -->
                                    <div class="db-lc-stats-grid">
                                        <!-- Donut Chart -->
                                        <div class="db-lc-donut-box">
                                            <div class="db-lc-donut-rel">
                                                <svg class="db-lc-donut-svg" viewBox="0 0 150 150">
                                                    <circle cx="75" cy="75" r="65" fill="none" stroke="#f1f5f9" stroke-width="11"></circle>
                                                    <circle id="db-donut-easy" cx="75" cy="75" r="65" fill="none" stroke="#00b8a3" stroke-width="11" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                                    <circle id="db-donut-medium" cx="75" cy="75" r="65" fill="none" stroke="#ffa116" stroke-width="11" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                                    <circle id="db-donut-hard" cx="75" cy="75" r="65" fill="none" stroke="#ef4743" stroke-width="11" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                                </svg>
                                                <div class="db-lc-donut-center">
                                                    <div id="db-lc-total" class="db-lc-donut-count">0</div>
                                                    <div class="db-lc-donut-label">Solved</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Difficulty Rows -->
                                        <div class="db-lc-rows-list">
                                            <!-- Easy Row -->
                                            <div class="db-lc-stat-row easy">
                                                <div class="db-lc-diff-badge easy">
                                                    <span class="db-lc-diff-dot"></span>
                                                    <span>Easy</span>
                                                </div>
                                                <div class="db-lc-count-box">
                                                    <span id="db-lc-easy-count" class="db-lc-val">0</span>
                                                    <span id="db-lc-easy-total" class="db-lc-total">/0</span>
                                                </div>
                                                <div class="db-lc-beats-box">
                                                    <span id="db-lc-easy-beats" class="db-lc-beats-pill">Beats 0.0%</span>
                                                </div>
                                            </div>

                                            <!-- Medium Row -->
                                            <div class="db-lc-stat-row medium">
                                                <div class="db-lc-diff-badge medium">
                                                    <span class="db-lc-diff-dot"></span>
                                                    <span>Medium</span>
                                                </div>
                                                <div class="db-lc-count-box">
                                                    <span id="db-lc-med-count" class="db-lc-val">0</span>
                                                    <span id="db-lc-med-total" class="db-lc-total">/0</span>
                                                </div>
                                                <div class="db-lc-beats-box">
                                                    <span id="db-lc-med-beats" class="db-lc-beats-pill">Beats 0.0%</span>
                                                </div>
                                            </div>

                                            <!-- Hard Row -->
                                            <div class="db-lc-stat-row hard">
                                                <div class="db-lc-diff-badge hard">
                                                    <span class="db-lc-diff-dot"></span>
                                                    <span>Hard</span>
                                                </div>
                                                <div class="db-lc-count-box">
                                                    <span id="db-lc-hard-count" class="db-lc-val">0</span>
                                                    <span id="db-lc-hard-total" class="db-lc-total">/0</span>
                                                </div>
                                                <div class="db-lc-beats-box">
                                                    <span id="db-lc-hard-beats" class="db-lc-beats-pill">Beats 0.0%</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <div class="mb-3">
                                        <span class="d-inline-flex p-3 rounded-circle" style="background: rgba(255, 161, 22, 0.1); color: #ffa116;">
                                            <svg style="width:28px;height:28px;" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1-.028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Connect Your LeetCode Account</h6>
                                    <p class="small text-muted mb-3 mx-auto" style="max-width: 320px;">Link your profile to display solved questions, difficulty breakdown, and ranking percentiles.</p>
                                    <a href="leetcode.php" class="btn btn-warning btn-sm px-4 rounded-pill fw-bold shadow-sm" style="background:#ffa116; color:#0f172a; border:none;">
                                        <i class="fa-solid fa-link me-1.5"></i> Connect LeetCode
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </div>
</div>

<!-- DASHBOARD UPLOAD CERTIFICATE MODAL -->
<div class="modal fade" id="uploadCertModal" tabindex="-1" aria-labelledby="uploadCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%);">
                <h5 class="modal-title fw-bold" id="uploadCertModalLabel">
                    <i class="fa-solid fa-certificate me-2"></i> Upload Certificate
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="dashboardCertUploadForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                <div class="modal-body p-4">
                    <div id="dbCertUploadAlert" class="alert d-none py-2 px-3 small rounded-3 mb-3"></div>

                    <!-- Certificate Title -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Certificate Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="dbCertTitle" class="form-control" placeholder="e.g. AWS Certified Solutions Architect, NPTEL Python" required>
                    </div>

                    <!-- Issuing Organization & Date -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-7">
                            <label class="form-label small fw-bold text-dark">Issuing Organization</label>
                            <input type="text" name="issuing_organization" id="dbCertOrg" class="form-control" placeholder="e.g. Coursera, Udemy, NPTEL, Oracle">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-bold text-dark">Issue Date</label>
                            <input type="date" name="issue_date" id="dbCertDate" class="form-control">
                        </div>
                    </div>

                    <!-- Credential ID & URL -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark">Credential ID (Optional)</label>
                            <input type="text" name="credential_id" id="dbCertCredId" class="form-control" placeholder="e.g. UC-83921">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark">Verification URL (Optional)</label>
                            <input type="url" name="credential_url" id="dbCertCredUrl" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <!-- Certificate Document File -->
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">Certificate Document <span class="text-danger">*</span></label>
                        <label for="dbCertFileInput" class="p-3 border rounded-3 bg-light text-center d-block w-100 mb-0" id="dbDropZone" style="border-style: dashed !important; border-width: 2px !important; border-color: #cbd5e1 !important; cursor: pointer; user-select: none;">
                            <input type="file" name="cert_file" id="dbCertFileInput" class="d-none" accept=".pdf,.png,.jpg,.jpeg">
                            <div id="dbDropPrompt">
                                <i class="fa-solid fa-cloud-arrow-up fa-2x text-muted mb-2"></i>
                                <div class="fw-semibold text-dark small">Click to browse or drag certificate file here</div>
                                <div class="text-muted small mt-1" style="font-size: 0.75rem;">Supported formats: PDF, PNG, JPG (Maximum size: 5MB)</div>
                            </div>
                            <div id="dbDropSelected" class="d-none text-start">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-file text-primary fa-lg"></i>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-bold text-dark text-truncate small" id="dbSelectedFileName"></div>
                                        <div class="text-muted small" id="dbSelectedFileSize" style="font-size:0.72rem;"></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" id="dbBtnRemoveFile" title="Remove file">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal text-white rounded-pill px-4 fw-semibold" id="dbBtnSubmitCert" style="background: #0d9488;">
                        <span id="dbBtnSubmitCertText"><i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Certificate</span>
                        <span id="dbBtnSubmitCertSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openUploadCertModal() {
    const modalEl = document.getElementById('uploadCertModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function deleteDashboardCert(certId, certTitle) {
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    const confirmMsg = `Are you sure you want to delete certificate "${certTitle}"?`;

    const doDelete = () => {
        $.post('../api/certificate-actions.php', {
            action: 'delete',
            cert_id: certId,
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Deleted!', res.message, 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    alert(res.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Error', res.message || 'Failed to delete certificate.', 'error');
                } else {
                    alert(res.message || 'Failed to delete certificate.');
                }
            }
        }, 'json').fail(function() {
            alert('Server error while deleting certificate.');
        });
    };

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Certificate?',
            text: `Are you sure you want to delete "${certTitle}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it'
        }).then((result) => {
            if (result.isConfirmed) doDelete();
        });
    } else {
        if (confirm(confirmMsg)) doDelete();
    }
}

$(document).ready(function() {
    // Certificate Upload Modal handling
    const $dropZone = $('#dbDropZone');
    const $fileInput = $('#dbCertFileInput');
    const $dropPrompt = $('#dbDropPrompt');
    const $dropSelected = $('#dbDropSelected');
    const $selectedName = $('#dbSelectedFileName');
    const $selectedSize = $('#dbSelectedFileSize');
    const $alert = $('#dbCertUploadAlert');

    $fileInput.on('change', function() {
        validateAndShowCertFile(this.files[0]);
    });

    $dropZone.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).css('border-color', '#0d9488').css('background', '#f0fdfa');
    });

    $dropZone.on('dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).css('border-color', '#cbd5e1').css('background', '#f8fafc');
    });

    $dropZone.on('drop', function(e) {
        const dt = e.originalEvent.dataTransfer;
        if (dt && dt.files && dt.files.length) {
            $fileInput[0].files = dt.files;
            validateAndShowCertFile(dt.files[0]);
        }
    });

    $('#dbBtnRemoveFile').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $fileInput.val('');
        $dropSelected.addClass('d-none');
        $dropPrompt.removeClass('d-none');
    });

    function validateAndShowCertFile(file) {
        $alert.addClass('d-none').removeClass('alert-danger alert-success');
        if (!file) return;

        const allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        const nameParts = file.name.split('.');
        const ext = nameParts.length > 1 ? nameParts.pop().toLowerCase() : '';

        if (!allowedExts.includes(ext)) {
            showCertAlert('Invalid file format. Only PDF, JPG, and PNG formats are allowed.', 'danger');
            $fileInput.val('');
            return;
        }

        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) {
            showCertAlert('The uploaded file exceeds the 5MB size limit (' + (file.size / (1024*1024)).toFixed(2) + 'MB).', 'danger');
            $fileInput.val('');
            return;
        }

        $selectedName.text(file.name);
        $selectedSize.text((file.size / 1024).toFixed(1) + ' KB');
        $dropPrompt.addClass('d-none');
        $dropSelected.removeClass('d-none');
    }

    function showCertAlert(msg, type) {
        $alert.removeClass('d-none alert-danger alert-success')
              .addClass('alert-' + type)
              .html(msg);
    }

    $('#dashboardCertUploadForm').on('submit', function(e) {
        e.preventDefault();

        const file = $fileInput[0].files[0];
        if (!file) {
            showCertAlert('Please select a certificate file (PDF, PNG, or JPG) to upload.', 'danger');
            return;
        }

        const formData = new FormData(this);
        const $btn = $('#dbBtnSubmitCert');
        const $text = $('#dbBtnSubmitCertText');
        const $spinner = $('#dbBtnSubmitCertSpinner');

        $btn.prop('disabled', true);
        $text.addClass('d-none');
        $spinner.removeClass('d-none');

        $.ajax({
            url: '../api/upload-certification.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false);
                $text.removeClass('d-none');
                $spinner.addClass('d-none');

                if (res.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Uploaded Successfully!',
                            text: res.message || 'Certificate uploaded successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        showCertAlert(res.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } else {
                    showCertAlert(res.message || 'Upload failed.', 'danger');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $text.removeClass('d-none');
                $spinner.addClass('d-none');

                let errMsg = 'Failed to upload certificate. Please try again.';
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) errMsg = parsed.message;
                } catch(e) {}
                showCertAlert(errMsg, 'danger');
            }
        });
    });

    // Fetch LeetCode Data via Proxy
    const lcUsername = '<?php echo esc($lcUsername ?? ""); ?>';
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    
    if (lcUsername) {
        $.post('../api/leetcode-proxy.php', {
            action: 'fetch_all',
            force_refresh: false,
            csrf_token: csrfToken
        }, function(response) {
            if (response.success && response.data) {
                const userProfile = response.data.user_profile || {};
                const profile = userProfile.profile || {};
                const stats = response.data.problem_stats || {};
                
                const fallbackAvatar = 'https://assets.leetcode.com/users/avatars/avatar_1701233055.png';

                if(profile.userAvatar) {
                    $('#db-lc-avatar').attr('src', profile.userAvatar);
                } else {
                    $('#db-lc-avatar').attr('src', fallbackAvatar);
                }
                
                $('#db-lc-avatar').on('error', function() {
                    $(this).attr('src', fallbackAvatar);
                });

                if(profile.realName) $('#db-lc-name').text(profile.realName);
                else $('#db-lc-name').text(lcUsername);
                
                let totalSolved = 0, easySolved = 0, medSolved = 0, hardSolved = 0;
                let easyTotal = 0, medTotal = 0, hardTotal = 0;
                let easyBeats = 0, medBeats = 0, hardBeats = 0;

                const solvedArr = stats.solved || [];
                const beatsArr = stats.beats || [];
                const allQs = stats.allQuestions || [];
                
                solvedArr.forEach(s => {
                    if (s.difficulty === 'All') totalSolved = s.count;
                    if (s.difficulty === 'Easy') easySolved = s.count;
                    if (s.difficulty === 'Medium') medSolved = s.count;
                    if (s.difficulty === 'Hard') hardSolved = s.count;
                });
                
                beatsArr.forEach(b => {
                    const val = (b && typeof b.percentage === 'number') ? b.percentage : 0;
                    if (b.difficulty === 'Easy') easyBeats = val;
                    if (b.difficulty === 'Medium') medBeats = val;
                    if (b.difficulty === 'Hard') hardBeats = val;
                });
                
                allQs.forEach(t => {
                    if (t.difficulty === 'Easy') easyTotal = t.count;
                    if (t.difficulty === 'Medium') medTotal = t.count;
                    if (t.difficulty === 'Hard') hardTotal = t.count;
                });

                $('#db-lc-total').text(totalSolved);
                $('#db-lc-easy-count').text(easySolved);
                $('#db-lc-med-count').text(medSolved);
                $('#db-lc-hard-count').text(hardSolved);
                
                if (easyTotal > 0) $('#db-lc-easy-total').text(' / ' + easyTotal);
                if (medTotal > 0) $('#db-lc-med-total').text(' / ' + medTotal);
                if (hardTotal > 0) $('#db-lc-hard-total').text(' / ' + hardTotal);

                $('#db-lc-easy-beats').text('Beats ' + (easyBeats ? easyBeats.toFixed(1) : '0.0') + '%');
                $('#db-lc-med-beats').text('Beats ' + (medBeats ? medBeats.toFixed(1) : '0.0') + '%');
                $('#db-lc-hard-beats').text('Beats ' + (hardBeats ? hardBeats.toFixed(1) : '0.0') + '%');

                const circ = 408;
                if(easyTotal > 0) $('#db-donut-easy').attr('stroke-dasharray', `${(easySolved/easyTotal)*circ} ${circ}`);
                if(medTotal > 0) $('#db-donut-medium').attr('stroke-dasharray', `${(medSolved/medTotal)*circ} ${circ}`);
                if(hardTotal > 0) $('#db-donut-hard').attr('stroke-dasharray', `${(hardSolved/hardTotal)*circ} ${circ}`);
                
                $('#db-lc-skeleton').hide();
                $('#db-lc-content').fadeIn();
            } else {
                $('#db-lc-skeleton').html('<div class="text-muted py-3 small">Failed to load LeetCode data.</div>');
            }
        }, 'json').fail(function() {
            $('#db-lc-skeleton').html('<div class="text-muted py-3 small">Error connecting to LeetCode API.</div>');
        });
    }
});
</script>
