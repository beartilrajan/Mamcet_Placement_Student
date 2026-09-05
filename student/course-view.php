<?php
// MAMCET Placement & Learning Portal - Student Course Curriculum Details View

$pageTitle = 'Course Syllabus';
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
$courseId = (int)($_GET['course_id'] ?? 0);

if ($courseId <= 0) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Course ID is required.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Fetch Course details
$stmt = $db->prepare("
    SELECT c.*, scp.completion_percentage, scp.status AS progress_status 
    FROM courses c
    LEFT JOIN student_course_progress scp ON c.course_id = scp.course_id AND scp.student_id = ?
    WHERE c.course_id = ? AND c.status = 'active'
");
$stmt->execute([$studentId, $courseId]);
$course = $stmt->fetch();

if (!$course) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Course not found or not assigned to your profile.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Initialize student course progress record if not started
if ($course['progress_status'] === null) {
    try {
        $stmtIns = $db->prepare("INSERT INTO student_course_progress (student_id, course_id, status, started_at) VALUES (?, ?, 'In Progress', NOW())");
        $stmtIns->execute([$studentId, $courseId]);
    } catch (Exception $e) {
        // Silently skip if insert conflict
    }
} else {
    // Update last accessed time
    $db->prepare("UPDATE student_course_progress SET last_accessed_at = NOW() WHERE student_id = ? AND course_id = ?")->execute([$studentId, $courseId]);
}

// Fetch modules
$stmtMod = $db->prepare("SELECT * FROM course_modules WHERE course_id = ? ORDER BY module_order ASC, module_id ASC");
$stmtMod->execute([$courseId]);
$modules = $stmtMod->fetchAll();

foreach ($modules as &$m) {
    // Fetch lessons joined with student progress to see if they marked it completed
    $stmtLes = $db->prepare("
        SELECT cl.*, slp.status AS completed_status 
        FROM course_lessons cl 
        LEFT JOIN student_lesson_progress slp ON cl.lesson_id = slp.lesson_id AND slp.student_id = ?
        WHERE cl.module_id = ? AND cl.status = 'active'
        ORDER BY cl.lesson_order ASC, cl.lesson_id ASC
    ");
    $stmtLes->execute([$studentId, $m['module_id']]);
    $m['lessons'] = $stmtLes->fetchAll();
}
unset($m);
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <div class="mb-4">
            <a href="courses.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Courses</a>
        </div>

        <div class="row">
            <!-- Left syllabus modules -->
            <div class="col-lg-8 mb-4">
                <div class="mamcet-card mb-4 bg-light">
                    <div class="card-body p-4">
                        <span class="badge bg-primary mb-2"><?php echo esc($course['category']); ?></span>
                        <h2 class="fw-bold mb-1 text-dark"><?php echo esc($course['course_title']); ?></h2>
                        <small class="text-muted d-block mb-3">Course Code: <strong><?php echo esc($course['course_code']); ?></strong> | Instructor: <strong><?php echo esc($course['instructor_name'] ?: 'Department Coordinator'); ?></strong></small>
                        <p class="mb-0 text-secondary-emphasis" style="font-size:0.9rem;"><?php echo esc($course['description'] ?: 'No course description available.'); ?></p>
                    </div>
                </div>

                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bars-staggered me-2 text-primary"></i> Curriculum Chapters</h5>
                
                <?php if (empty($modules)): ?>
                    <div class="mamcet-card p-5 text-center text-muted">
                        <i class="fa-solid fa-graduation-cap fa-3x mb-3 text-secondary" style="opacity: 0.3;"></i>
                        <p class="mb-0">This course does not contain any curriculum topics yet.</p>
                    </div>
                <?php else: ?>
                    <div class="accordion" id="studentSyllabusAccordion">
                        <?php foreach ($modules as $idx => $m): ?>
                            <div class="accordion-item mamcet-card border mb-3">
                                <h2 class="accordion-header" id="heading_<?php echo $m['module_id']; ?>">
                                    <button class="accordion-button <?php echo $idx === 0 ? '' : 'collapsed'; ?> fw-bold py-3 text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $m['module_id']; ?>">
                                        <div class="d-flex justify-content-between align-items-center w-100 pe-3">
                                            <span>
                                                <span class="badge bg-dark me-2">Mod <?php echo esc($m['module_order']); ?></span>
                                                <?php echo esc($m['module_title']); ?>
                                            </span>
                                            <span class="small text-muted font-monospace"><?php echo count($m['lessons']); ?> Topics</span>
                                        </div>
                                    </button>
                                </h2>
                                <div id="collapse_<?php echo $m['module_id']; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#studentSyllabusAccordion">
                                    <div class="accordion-body bg-white border-top">
                                        <p class="text-muted small border-bottom pb-2 mb-3"><?php echo esc($m['description']); ?></p>
                                        
                                        <div class="list-group list-group-flush">
                                            <?php if (empty($m['lessons'])): ?>
                                                <p class="small text-muted text-center py-2">No topics found inside this chapter.</p>
                                            <?php else: ?>
                                                <?php foreach ($m['lessons'] as $les): 
                                                    $completed = $les['completed_status'] === 'Completed';
                                                ?>
                                                    <a href="lesson.php?course_id=<?php echo $courseId; ?>&lesson_id=<?php echo $les['lesson_id']; ?>" class="list-group-item list-group-item-action py-3 px-0 border-bottom d-flex justify-content-between align-items-center">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <div class="text-center bg-light border rounded-circle d-flex align-items-center justify-content-center" style="width:34px;height:34px;">
                                                                <span class="small fw-bold text-dark"><?php echo esc($les['lesson_order']); ?></span>
                                                            </div>
                                                            <div>
                                                                <strong class="text-dark d-block" style="font-size:0.95rem;"><?php echo esc($les['lesson_title']); ?></strong>
                                                                <small class="text-muted">
                                                                    <?php if (!empty($les['youtube_video_id'])): ?>
                                                                        <i class="fa-brands fa-youtube text-danger me-1"></i> Video (<?php echo (int)$les['duration_minutes']; ?> mins)
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($les['pdf_resource_path'])): ?>
                                                                        | <i class="fa-solid fa-file-pdf text-danger me-1"></i> PDF Notes
                                                                    <?php endif; ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <?php if ($completed): ?>
                                                                <span class="badge bg-success-subtle text-success border-success border"><i class="fa-solid fa-circle-check"></i> Completed</span>
                                                            <?php else: ?>
                                                                <span class="btn btn-sm btn-outline-primary px-3 py-1" style="font-size:0.75rem;"><i class="fa-solid fa-circle-play"></i> Watch</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right side progress stats -->
            <div class="col-lg-4 mb-4">
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Course Status</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center py-3 mb-4">
                            <h2 class="fw-bold text-primary mb-1"><?php echo (int)($course['completion_percentage'] ?? 0); ?>%</h2>
                            <span class="small text-muted fw-bold">Overall Completion</span>
                        </div>
                        
                        <div class="progress mb-4" style="height: 6px; border-radius: 3px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)($course['completion_percentage'] ?? 0); ?>%"></div>
                        </div>
                        
                        <div class="list-group list-group-flush border-top">
                            <div class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted small">Course Category:</span>
                                <span class="small fw-bold text-dark"><?php echo esc($course['category']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted small">Difficulty Level:</span>
                                <span class="small fw-bold text-dark"><?php echo esc($course['difficulty']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted small">Start Date:</span>
                                <span class="small fw-bold text-dark"><?php echo $course['start_date'] ? date('d M Y', strtotime($course['start_date'])) : 'Open Learning'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

