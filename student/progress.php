<?php
// MAMCET Placement & Learning Portal - Student Learning Progress Scorecard

$pageTitle = 'Learning Progress';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$studentId = $student['student_id'];

// 1. Fetch count stats
$stmtAssigned = $db->prepare("
    SELECT COUNT(DISTINCT ca.course_id) 
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE c.status = 'active' AND ca.session_id = ? AND (
        c.course_id IN (SELECT course_id FROM course_departments WHERE dept_id = ?)
        OR c.course_id IN (SELECT course_id FROM course_batches WHERE batch_id = ?)
    )
");
$stmtAssigned->execute([$activeSessionId, $student['dept_id'], $student['batch_id']]);
$assignedCount = (int)$stmtAssigned->fetchColumn();

$stmtCompleted = $db->prepare("
    SELECT COUNT(*) FROM student_course_progress 
    WHERE student_id = ? AND status = 'Completed'
");
$stmtCompleted->execute([$studentId]);
$completedCount = (int)$stmtCompleted->fetchColumn();

$stmtInPrg = $db->prepare("
    SELECT COUNT(*) FROM student_course_progress 
    WHERE student_id = ? AND status = 'In Progress'
");
$stmtInPrg->execute([$studentId]);
$inPrgCount = (int)$stmtInPrg->fetchColumn();

// 2. Fetch all assigned courses with progress
$stmtCourses = $db->prepare("
    SELECT c.course_title, c.course_code, c.category,
           scp.completion_percentage, scp.status AS progress_status, scp.started_at, scp.completed_at
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN student_course_progress scp ON c.course_id = scp.course_id AND scp.student_id = ?
    WHERE c.status = 'active' AND ca.session_id = ? AND (
        c.course_id IN (SELECT course_id FROM course_departments WHERE dept_id = ?)
        OR c.course_id IN (SELECT course_id FROM course_batches WHERE batch_id = ?)
    )
    GROUP BY c.course_id
    ORDER BY scp.completion_percentage DESC
");
$stmtCourses->execute([$studentId, $activeSessionId, $student['dept_id'], $student['batch_id']]);
$coursesProgress = $stmtCourses->fetchAll();

// 3. Fetch list of completed lessons
$stmtLessons = $db->prepare("
    SELECT slp.completed_at, cl.lesson_title, cl.duration_minutes, cm.module_title, c.course_title 
    FROM student_lesson_progress slp
    JOIN course_lessons cl ON slp.lesson_id = cl.lesson_id
    JOIN course_modules cm ON cl.module_id = cm.module_id
    JOIN courses c ON cm.course_id = c.course_id
    WHERE slp.student_id = ? AND slp.status = 'Completed'
    ORDER BY slp.completed_at DESC
");
$stmtLessons->execute([$studentId]);
$completedLessons = $stmtLessons->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Learning Analytics</h4>
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
        
        <!-- Summary Stats grid -->
        <div class="row g-4 mb-4">
            <div class="col-6 col-md-3">
                <div class="p-3 border rounded text-center bg-white shadow-sm h-100">
                    <span class="text-muted small d-block mb-1">Assigned Courses</span>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo $assignedCount; ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 border rounded text-center bg-success-subtle text-success shadow-sm h-100">
                    <span class="small d-block mb-1">Courses Completed</span>
                    <h3 class="fw-bold mb-0"><?php echo $completedCount; ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 border rounded text-center bg-info-subtle text-info shadow-sm h-100">
                    <span class="small d-block mb-1">In Progress</span>
                    <h3 class="fw-bold mb-0"><?php echo $inPrgCount; ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 border rounded text-center bg-light text-dark shadow-sm h-100">
                    <span class="text-muted small d-block mb-1">Completed Topics</span>
                    <h3 class="fw-bold mb-0"><?php echo count($completedLessons); ?></h3>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Courses progression grid -->
            <div class="col-lg-6 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Course-wise Progress Summary</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($coursesProgress)): ?>
                            <p class="text-muted small text-center py-4">No progress logs recorded yet.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($coursesProgress as $c): 
                                    $pct = (int)($c['completion_percentage'] ?? 0);
                                ?>
                                    <div class="py-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <strong class="text-dark d-block"><?php echo esc($c['course_title']); ?></strong>
                                                <small class="text-muted"><?php echo esc($c['course_code']); ?></small>
                                            </div>
                                            <span class="badge <?php echo $c['progress_status'] === 'Completed' ? 'bg-success' : ($c['progress_status'] === 'In Progress' ? 'bg-info' : 'bg-secondary'); ?>">
                                                <?php echo $c['progress_status'] ?: 'Not Started'; ?>
                                            </span>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 3px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                            <span class="small fw-bold text-dark"><?php echo $pct; ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Completed topics list -->
            <div class="col-lg-6 mb-4">
                <div class="mamcet-card h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Completed Lessons Feed</h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($completedLessons)): ?>
                            <p class="text-muted small text-center py-5">No completed lesson updates found. Play lessons and click complete to see them here.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($completedLessons as $l): ?>
                                    <div class="list-group-item py-3 px-3">
                                        <div class="d-flex w-100 justify-content-between">
                                            <strong class="text-dark" style="font-size:0.9rem;"><?php echo esc($l['lesson_title']); ?></strong>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($l['completed_at'])); ?></small>
                                        </div>
                                        <span class="small text-muted d-block"><?php echo esc($l['course_title']); ?> &gt; <?php echo esc($l['module_title']); ?></span>
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

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

