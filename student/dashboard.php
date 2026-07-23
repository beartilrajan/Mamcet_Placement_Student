<?php
// MAMCET Placement & Learning Portal - Student Dashboard

$pageTitle = 'Student Dashboard';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

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
    LIMIT 5
");
$stmtAnn->execute([$activeSessionId, $student['dept_id'], $student['batch_id']]);
$announcements = $stmtAnn->fetchAll();

// 3. Fetch LMS Courses Progress
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
    LIMIT 3
");
$stmtCourses->execute([$studentId, $activeSessionId, $student['dept_id'], $student['batch_id']]);
$courses = $stmtCourses->fetchAll();

// 4. Fetch overall progress metrics (completed lessons vs total lessons)
$stmtProg = $db->prepare("
    SELECT COUNT(*) FROM student_lesson_progress WHERE student_id = ?
");
$stmtProg->execute([$studentId]);
$completedLessonsCount = (int)$stmtProg->fetchColumn();

// Fetch officer notes that might have been posted
$stmtNotes = $db->prepare("
    SELECT COUNT(*) FROM officer_notes WHERE student_id = ?
");
$stmtNotes->execute([$studentId]);
$totalNotesCount = (int)$stmtNotes->fetchColumn();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Student Dashboard</h4>
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
                    <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex">
                    <span class="user-name"><?php echo esc($student['student_name']); ?></span>
                    <span class="user-role">Student</span>
                </div>
            </div>
        </div>
    </header>

    <div class="page-container">
        <!-- Dashboard Welcome Card -->
        <div class="card border-0 text-white mb-4 shadow-sm" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); border-radius:16px;">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8 mb-3 mb-md-0">
                        <span class="badge bg-white-50 text-white mb-2" style="background: rgba(255, 255, 255, 0.15);">MAMCET Portal</span>
                        <h2 class="fw-bold mb-1">Welcome back, <?php echo esc($student['student_name']); ?>!</h2>
                        <p class="mb-0 text-white-50" style="font-size: 0.95rem;">Track your placement drives, verify parameters, and complete training courses.</p>
                    </div>
                    <div class="col-md-4 text-md-end text-start">
                        <div class="d-inline-block p-3 border rounded text-center" style="background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.15) !important;">
                            <span class="d-block small text-white-50">CGPA</span>
                            <h3 class="fw-bold mb-0 text-white"><?php echo esc($student['current_cgpa'] ?? '0.00'); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- 1. Profile Completion Card -->
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="stat-info w-70">
                        <div class="stat-value"><?php echo $profileCompletion; ?>%</div>
                        <div class="stat-label">Profile Completed</div>
                        <div class="progress mt-2" style="height: 5px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $profileCompletion; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 2. Arrears Counter -->
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon <?php echo ($student['standing_arrears'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo (int)($student['standing_arrears'] ?? 0); ?></div>
                        <div class="stat-label">Standing Arrears</div>
                    </div>
                </div>
            </div>
            <!-- 3. Placement Status -->
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="font-size:1.45rem; font-weight:800;"><?php echo esc($student['placement_status']); ?></div>
                        <div class="stat-label">Placement Willing: <?php echo esc($student['placement_willingness']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Announcements -->
            <div class="col-lg-7 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white">
                        <h5 class="card-title fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-bullhorn text-primary me-2"></i> Placement Drives & Notices
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($announcements)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fa-solid fa-bullhorn fa-3x mb-3 text-secondary" style="opacity:0.4;"></i>
                                <p class="mb-0">No active placement updates found for your department.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($announcements as $ann): ?>
                                    <a href="placements.php?announcement_id=<?php echo $ann['announcement_id']; ?>" class="list-group-item list-group-item-action py-3">
                                        <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                            <h6 class="fw-bold mb-0 text-dark"><?php echo esc($ann['title']); ?></h6>
                                            <span class="badge <?php echo $ann['priority'] === 'High' ? 'bg-danger' : ($ann['priority'] === 'Medium' ? 'bg-primary' : 'bg-secondary'); ?>">
                                                <?php echo $ann['priority']; ?>
                                            </span>
                                        </div>
                                        <p class="mb-1 text-muted small text-truncate"><?php echo esc(strip_tags($ann['description'])); ?></p>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <span class="small text-primary fw-bold">
                                                <i class="fa-solid fa-building me-1"></i> <?php echo esc($ann['company_name'] ?: 'General Notice'); ?>
                                            </span>
                                            <small class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Deadline: <?php echo date('d M Y', strtotime($ann['expiry_date'])); ?></small>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- LMS Summary -->
            <div class="col-lg-5 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white">
                        <h5 class="card-title fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-graduation-cap text-primary me-2"></i> Active Training Courses
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($courses)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fa-solid fa-book-open fa-3x mb-3 text-secondary" style="opacity:0.4;"></i>
                                <p class="mb-0">No training programs assigned to your department currently.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush px-3">
                                <?php foreach ($courses as $c): ?>
                                    <div class="py-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <span class="fw-bold text-dark d-block"><?php echo esc($c['course_title']); ?></span>
                                                <small class="text-muted"><?php echo esc($c['course_code']); ?></small>
                                            </div>
                                            <span class="badge <?php echo $c['status'] === 'Completed' ? 'bg-success' : ($c['status'] === 'In Progress' ? 'bg-info' : 'bg-secondary'); ?>">
                                                <?php echo $c['status'] ?: 'Not Started'; ?>
                                            </span>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="progress flex-grow-1" style="height: 5px;">
                                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (float)($c['completion_percentage'] ?? 0); ?>%"></div>
                                            </div>
                                            <span class="small fw-bold text-dark"><?php echo (int)($c['completion_percentage'] ?? 0); ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="p-3 text-center border-top">
                                <a href="courses.php" class="btn btn-sm btn-outline-primary w-100">View Learning Courses</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

