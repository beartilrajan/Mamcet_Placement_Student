<?php
// MAMCET Placement & Learning Portal - Student Courses Roster

$pageTitle = 'Learning Courses';
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

// Query assigned courses and join student progress details
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
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Learning Hub</h4>
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
        
        <div class="page-header">
            <h1 class="page-title fw-bold">Assigned Courses</h1>
        </div>

        <div class="alert alert-info py-3 mb-4">
            <i class="fa-solid fa-graduation-cap me-2"></i> Access lessons assigned by the placement unit. Mark lessons as completed to track syllabus updates dynamically.
        </div>

        <div class="row">
            <?php if (empty($assignedCourses)): ?>
                <div class="col-12 text-center py-5">
                    <div class="mamcet-card p-5">
                        <i class="fa-solid fa-book-open fa-4x text-secondary mb-3" style="opacity:0.4;"></i>
                        <h4 class="fw-bold">No Courses Assigned Yet</h4>
                        <p class="text-muted">Courses assigned to your department and batch will be listed here.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($assignedCourses as $c): 
                    $pct = (int)($c['completion_percentage'] ?? 0);
                    $started = $c['progress_status'] !== null;
                    $completed = $c['progress_status'] === 'Completed';
                ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="mamcet-card h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div style="height: 150px; background-color: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative;">
                                    <?php if (!empty($c['thumbnail_path'])): ?>
                                        <img src="../<?php echo esc($c['thumbnail_path']); ?>" alt="Course cover" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-graduation-cap fa-4x text-secondary" style="opacity: 0.3;"></i>
                                    <?php endif; ?>
                                    <span class="badge bg-dark position-absolute top-0 end-0 m-3"><?php echo esc($c['difficulty']); ?></span>
                                </div>
                                <div class="p-4">
                                    <span class="text-primary small fw-bold mb-1 d-block"><?php echo esc($c['category']); ?></span>
                                    <h5 class="fw-bold mb-1 text-dark"><?php echo esc($c['course_title']); ?></h5>
                                    <small class="text-muted d-block mb-3">Instructor: <?php echo esc($c['instructor_name'] ?: 'External Faculty'); ?></small>
                                    
                                    <p class="small text-secondary-emphasis mb-3" style="height: 38px; overflow: hidden; font-size:0.85rem;"><?php echo esc($c['description']); ?></p>
                                    
                                    <div class="d-flex align-items-center justify-content-between small mb-2">
                                        <span class="text-muted"><i class="fa-solid fa-circle-check text-success"></i> Lessons: <strong><?php echo $c['completed_lessons']; ?> / <?php echo $c['total_lessons']; ?></strong></span>
                                        <span class="fw-bold text-dark"><?php echo $pct; ?>% Done</span>
                                    </div>
                                    <div class="progress" style="height: 5px; border-radius: 5px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="px-4 pb-4 pt-0 border-top bg-light">
                                <a href="course-view.php?course_id=<?php echo $c['course_id']; ?>" class="btn btn-primary w-100 mt-3 btn-sm">
                                    <?php if ($completed): ?>
                                        <i class="fa-solid fa-check-double me-1"></i> Review Syllabus
                                    <?php elseif ($started): ?>
                                        <i class="fa-solid fa-play me-1"></i> Resume Learning
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-play me-1"></i> Start Learning
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

