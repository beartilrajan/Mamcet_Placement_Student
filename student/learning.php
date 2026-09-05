<?php
// MAMCET Placement & Learning Portal - Unified Learning Hub (Consolidated Courses & Progress)

$pageTitle = 'Unified Learning Hub';
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
$activeTab = $_GET['tab'] ?? 'courses';

// 1. Fetch assigned courses with progress
$stmtCourses = $db->prepare("
    SELECT c.*, 
           scp.completion_percentage, 
           scp.status AS progress_status,
           (SELECT COUNT(*) FROM course_modules WHERE course_id = c.course_id) AS total_modules,
           (SELECT COUNT(*) FROM course_lessons cl JOIN course_modules cm ON cl.module_id = cm.module_id WHERE cm.course_id = c.course_id) AS total_lessons,
           (SELECT COUNT(*) FROM student_lesson_progress slp 
            JOIN course_lessons cl ON slp.lesson_id = cl.lesson_id 
            JOIN course_modules cm ON cl.module_id = cm.module_id 
            WHERE cm.course_id = c.course_id AND slp.student_id = ?) AS completed_lessons
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN student_course_progress scp ON c.course_id = scp.course_id AND scp.student_id = ?
    WHERE c.status = 'active' AND ca.session_id = ? AND (
        c.course_id IN (SELECT course_id FROM course_departments WHERE dept_id = ?)
        OR c.course_id IN (SELECT course_id FROM course_batches WHERE batch_id = ?)
    )
    GROUP BY c.course_id
    ORDER BY c.created_at DESC
");
$stmtCourses->execute([$studentId, $studentId, $activeSessionId, $student['dept_id'], $student['batch_id']]);
$assignedCourses = $stmtCourses->fetchAll();

// 2. Analytics calculations
$assignedCount = count($assignedCourses);
$completedCount = 0;
$inPrgCount = 0;

foreach ($assignedCourses as $c) {
    if (($c['progress_status'] ?? '') === 'Completed' || (int)($c['completion_percentage'] ?? 0) === 100) {
        $completedCount++;
    } elseif (($c['progress_status'] ?? '') === 'In Progress' || (int)($c['completion_percentage'] ?? 0) > 0) {
        $inPrgCount++;
    }
}

// 3. Fetch list of completed lessons for timeline
$stmtLessons = $db->prepare("
    SELECT slp.completed_at, cl.lesson_title, cl.duration_minutes, cm.module_title, c.course_title 
    FROM student_lesson_progress slp
    JOIN course_lessons cl ON slp.lesson_id = cl.lesson_id
    JOIN course_modules cm ON cl.module_id = cm.module_id
    JOIN courses c ON cm.course_id = c.course_id
    WHERE slp.student_id = ? AND slp.status = 'Completed'
    ORDER BY slp.completed_at DESC
    LIMIT 10
");
$stmtLessons->execute([$studentId]);
$completedLessons = $stmtLessons->fetchAll();
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <!-- Header Banner -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">Unified Learning Hub</h1>
                <p class="text-muted mb-0">Master training modules, complete lesson assignments, and track your progress metrics.</p>
            </div>
            
            <!-- Navigation Tabs Toggle -->
            <ul class="nav nav-pills bg-white p-1 rounded-3 border shadow-sm w-100 w-sm-auto flex-nowrap" id="learningTab" role="tablist">
                <li class="nav-item flex-fill text-center" role="presentation">
                    <a class="nav-link fw-semibold px-2 px-md-4 py-2 <?php echo $activeTab !== 'progress' ? 'active' : ''; ?> text-nowrap" href="learning.php?tab=courses" style="border-radius:6px; font-size: 0.88rem;">
                        <i class="fa-solid fa-book-open me-1 me-md-2"></i> Courses (<?php echo $assignedCount; ?>)
                    </a>
                </li>
                <li class="nav-item flex-fill text-center" role="presentation">
                    <a class="nav-link fw-semibold px-2 px-md-4 py-2 <?php echo $activeTab === 'progress' ? 'active' : ''; ?> text-nowrap" href="learning.php?tab=progress" style="border-radius:6px; font-size: 0.88rem;">
                        <i class="fa-solid fa-chart-column me-1 me-md-2"></i> Analytics
                    </a>
                </li>
            </ul>
        </div>

        <?php if ($activeTab !== 'progress'): ?>
            <!-- TAB 1: ASSIGNED COURSES GRID -->
            <div class="row g-4">
                <?php if (empty($assignedCourses)): ?>
                    <div class="col-12 text-center py-2 py-md-4">
                        <div class="mamcet-card p-4 p-md-5">
                            <i class="fa-solid fa-graduation-cap fa-3x fa-md-4x text-secondary mb-3" style="opacity:0.4;"></i>
                            <h4 class="fw-bold mb-2">No Courses Assigned Yet</h4>
                            <p class="text-muted small mb-0">Courses assigned to your department and batch will appear here.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($assignedCourses as $c): 
                        $pct = (int)($c['completion_percentage'] ?? 0);
                        $started = $c['progress_status'] !== null;
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="mamcet-card h-100 d-flex flex-column hover-lift shadow-sm border">
                                <div class="position-relative">
                                    <?php if (!empty($c['thumbnail_path'])): ?>
                                        <img src="<?php echo esc($baseDir . $c['thumbnail_path']); ?>" class="card-img-top" alt="Thumbnail" style="height: 170px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-primary text-white d-flex align-items-center justify-content-center" style="height: 170px;">
                                            <i class="fa-solid fa-laptop-code fa-4x" style="opacity:0.6;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span class="badge bg-dark position-absolute top-0 end-0 m-3" style="font-size: 0.72rem; opacity:0.9;">
                                        <?php echo esc($c['category']); ?>
                                    </span>
                                </div>

                                <div class="card-body p-4 flex-grow-1 d-flex flex-column">
                                    <h5 class="fw-bold text-dark mb-2"><?php echo esc($c['course_title']); ?></h5>
                                    <p class="text-muted small mb-3 text-truncate" style="max-height: 40px; white-space: normal;">
                                        <?php echo esc($c['description']); ?>
                                    </p>

                                    <div class="mt-auto">
                                        <div class="d-flex justify-content-between align-items-center mb-1 small text-muted font-monospace">
                                            <span>Progress</span>
                                            <span class="fw-bold text-dark"><?php echo $pct; ?>%</span>
                                        </div>
                                        <div class="progress mb-3" style="height: 8px; border-radius: 4px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>

                                        <div class="d-flex justify-content-between text-muted small border-top pt-3 mb-3">
                                            <span><i class="fa-solid fa-list-check me-1"></i> <?php echo $c['total_modules']; ?> Modules</span>
                                            <span><i class="fa-solid fa-circle-play me-1"></i> <?php echo $c['completed_lessons']; ?>/<?php echo $c['total_lessons']; ?> Lessons</span>
                                        </div>

                                        <a href="course-view.php?id=<?php echo $c['course_id']; ?>" class="btn btn-primary w-100 fw-semibold" style="border-radius: 8px;">
                                            <?php echo $pct > 0 ? ($pct == 100 ? 'Review Course' : 'Continue Learning') : 'Start Course'; ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- TAB 2: PROGRESS ANALYTICS & SCORECARD -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fa-solid fa-book-open"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $assignedCount; ?></div>
                            <div class="stat-label">Assigned Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fa-solid fa-spinner"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $inPrgCount; ?></div>
                            <div class="stat-label">Courses In Progress</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $completedCount; ?></div>
                            <div class="stat-label">Courses Completed</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Courses Progress Breakdown Table -->
                <div class="col-lg-7">
                    <div class="mamcet-card h-100">
                        <div class="card-header bg-white">
                            <h5 class="fw-bold mb-0 text-dark">Course Completion Status</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Course Title</th>
                                            <th>Category</th>
                                            <th>Progress</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignedCourses as $c): 
                                            $pct = (int)($c['completion_percentage'] ?? 0);
                                        ?>
                                            <tr>
                                                <td class="fw-semibold text-dark"><?php echo esc($c['course_title']); ?></td>
                                                <td><span class="badge bg-light text-dark border"><?php echo esc($c['category']); ?></span></td>
                                                <td style="width: 140px;">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="progress flex-grow-1" style="height:6px;">
                                                            <div class="progress-bar bg-primary" style="width: <?php echo $pct; ?>%;"></div>
                                                        </div>
                                                        <span class="small fw-bold"><?php echo $pct; ?>%</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($pct === 100): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Completed</span>
                                                    <?php elseif ($pct > 0): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">In Progress</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border">Not Started</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Completed Lessons Timeline -->
                <div class="col-lg-5">
                    <div class="mamcet-card h-100">
                        <div class="card-header bg-white">
                            <h5 class="fw-bold mb-0 text-dark">Recent Completed Lessons</h5>
                        </div>
                        <div class="card-body p-4">
                            <?php if (empty($completedLessons)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fa-regular fa-clock fa-2x mb-2 text-secondary opacity-50"></i>
                                    <p class="mb-0">No lesson completion logs yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($completedLessons as $les): ?>
                                        <div class="d-flex gap-3 mb-3 border-bottom pb-2">
                                            <div class="text-success"><i class="fa-solid fa-circle-check"></i></div>
                                            <div>
                                                <h6 class="mb-0 fw-bold text-dark" style="font-size:0.9rem;"><?php echo esc($les['lesson_title']); ?></h6>
                                                <small class="text-muted d-block"><?php echo esc($les['course_title']); ?> &bull; <?php echo esc($les['module_title']); ?></small>
                                                <small class="text-muted" style="font-size:0.75rem;"><?php echo date('M d, Y h:i A', strtotime($les['completed_at'])); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
