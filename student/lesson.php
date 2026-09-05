<?php
// MAMCET Placement & Learning Portal - Student Lesson Player Screen

$pageTitle = 'Lesson Viewer';
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
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if ($courseId <= 0 || $lessonId <= 0) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Course ID and Lesson ID are required parameters.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Fetch Lesson details
$stmtLes = $db->prepare("
    SELECT cl.*, cm.module_title, cm.course_id, c.course_title, c.course_code,
           (SELECT status FROM student_lesson_progress WHERE lesson_id = cl.lesson_id AND student_id = ?) AS completed_status,
           (SELECT completed_at FROM student_lesson_progress WHERE lesson_id = cl.lesson_id AND student_id = ?) AS completed_date
    FROM course_lessons cl
    JOIN course_modules cm ON cl.module_id = cm.module_id
    JOIN courses c ON cm.course_id = c.course_id
    WHERE cl.lesson_id = ? AND c.course_id = ? AND cl.status = 'active'
");
$stmtLes->execute([$studentId, $studentId, $lessonId, $courseId]);
$lesson = $stmtLes->fetch();

if (!$lesson) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Lesson not found or not assigned to your profile.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Fetch adjacent lessons for next topic navigation
$stmtNext = $db->prepare("
    SELECT lesson_id FROM course_lessons 
    WHERE module_id = ? AND status = 'active' AND lesson_order > ? 
    ORDER BY lesson_order ASC, lesson_id ASC LIMIT 1
");
$stmtNext->execute([$lesson['module_id'], $lesson['lesson_order']]);
$nextLessonId = $stmtNext->fetchColumn();

if (!$nextLessonId) {
    // Check if next module has a lesson
    $stmtNextMod = $db->prepare("
        SELECT cl.lesson_id FROM course_lessons cl
        JOIN course_modules cm ON cl.module_id = cm.module_id
        WHERE cm.course_id = ? AND cm.module_order > (SELECT module_order FROM course_modules WHERE module_id = ?)
          AND cl.status = 'active'
        ORDER BY cm.module_order ASC, cl.lesson_order ASC, cl.lesson_id ASC LIMIT 1
    ");
    $stmtNextMod->execute([$courseId, $lesson['module_id']]);
    $nextLessonId = $stmtNextMod->fetchColumn();
}

// Fetch all modules/lessons of this course for navigation sidebar list
$stmtMod = $db->prepare("SELECT * FROM course_modules WHERE course_id = ? ORDER BY module_order ASC, module_id ASC");
$stmtMod->execute([$courseId]);
$modules = $stmtMod->fetchAll();

foreach ($modules as &$m) {
    $stmtLesNav = $db->prepare("
        SELECT cl.lesson_id, cl.lesson_title, cl.lesson_order, slp.status AS completed_status 
        FROM course_lessons cl 
        LEFT JOIN student_lesson_progress slp ON cl.lesson_id = slp.lesson_id AND slp.student_id = ?
        WHERE cl.module_id = ? AND cl.status = 'active'
        ORDER BY cl.lesson_order ASC, cl.lesson_id ASC
    ");
    $stmtLesNav->execute([$studentId, $m['module_id']]);
    $m['lessons'] = $stmtLesNav->fetchAll();
}
unset($m);
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <!-- Back to Syllabus button -->
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a href="course-view.php?course_id=<?php echo $courseId; ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Return to Course Syllabus</a>
            <span class="badge bg-light text-dark border fw-bold"><?php echo esc($lesson['course_title']); ?> (<?php echo esc($lesson['course_code']); ?>)</span>
        </div>

        <div class="row">
            <!-- LEFT VIDEO AND NOTES -->
            <div class="col-lg-8 mb-4">
                <!-- Video Container -->
                <?php if (!empty($lesson['youtube_video_id'])): ?>
                    <div class="video-container mb-4">
                        <iframe
                            src="https://www.youtube.com/embed/<?php echo esc($lesson['youtube_video_id']); ?>?rel=0&modestbranding=1"
                            title="<?php echo esc($lesson['lesson_title']); ?>"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen>
                        </iframe>
                    </div>
                <?php else: ?>
                    <div class="mamcet-card p-5 mb-4 text-center bg-light">
                        <i class="fa-solid fa-file-invoice fa-4x text-secondary mb-3" style="opacity: 0.3;"></i>
                        <h5 class="fw-bold">Reading Assignment</h5>
                        <p class="text-muted">No lecture video associated. Please review the notes and resource downloads below.</p>
                    </div>
                <?php endif; ?>

                <div class="mamcet-card mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3 border-bottom pb-3">
                            <div>
                                <span class="badge bg-dark mb-2"><?php echo esc($lesson['module_title']); ?></span>
                                <h3 class="fw-bold text-dark mb-0"><?php echo esc($lesson['lesson_title']); ?></h3>
                                <small class="text-muted"><i class="fa-solid fa-clock me-1"></i> Duration: <?php echo (int)$lesson['duration_minutes']; ?> minutes</small>
                            </div>
                            
                            <div id="completionBtnBox">
                                <?php if ($lesson['completed_status'] === 'Completed'): ?>
                                    <button class="btn btn-success" disabled>
                                        <i class="fa-solid fa-circle-check me-1"></i> Completed on <?php echo date('d M', strtotime($lesson['completed_date'])); ?>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline-success px-4" id="btnMarkComplete" onclick="markAsComplete()">
                                        <i class="fa-solid fa-circle-check me-1"></i> Mark as Completed
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <p class="text-secondary-emphasis mb-4" style="font-size:0.95rem; line-height: 1.6;"><?php echo esc($lesson['description'] ?: 'No topic summary provided.'); ?></p>

                        <!-- Notes text contents -->
                        <?php if (!empty($lesson['notes_text'])): ?>
                            <h6 class="fw-bold text-dark mb-2">Written Notes Summary</h6>
                            <div class="p-3 border rounded bg-light mb-4" style="font-size:0.9rem; white-space: pre-line;"><?php echo esc($lesson['notes_text']); ?></div>
                        <?php endif; ?>

                        <!-- Resources files / links -->
                        <?php if (!empty($lesson['pdf_resource_path']) || !empty($lesson['external_resource_url'])): ?>
                            <h6 class="fw-bold text-dark mb-3">Topic Resources & Downloads</h6>
                            <div class="row g-3">
                                <?php if (!empty($lesson['pdf_resource_path'])): ?>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded bg-light d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-file-pdf text-danger fa-xl"></i>
                                                <span class="small fw-bold text-dark">Syllabus PDF Notes</span>
                                            </div>
                                            <a href="../<?php echo esc($lesson['pdf_resource_path']); ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-arrow-down-to-bracket"></i></a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($lesson['external_resource_url'])): ?>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded bg-light d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-up-right-from-square text-primary fa-xl"></i>
                                                <span class="small fw-bold text-dark">External documentation</span>
                                            </div>
                                            <a href="<?php echo esc($lesson['external_resource_url']); ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-link"></i></a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Next topic buttons -->
                        <?php if ($nextLessonId): ?>
                            <div class="mt-5 text-end border-top pt-4">
                                <a href="lesson.php?course_id=<?php echo $courseId; ?>&lesson_id=<?php echo $nextLessonId; ?>" class="btn btn-primary">
                                    Next Topic <i class="fa-solid fa-chevron-right ms-2"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT COURSE NAVIGATION OUTLINE -->
            <div class="col-lg-4 mb-4">
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Course Chapters</h6>
                    </div>
                    <div class="card-body p-2" style="max-height: 480px; overflow-y: auto;">
                        <div class="list-group list-group-flush">
                            <?php foreach ($modules as $m): ?>
                                <div class="mb-3">
                                    <span class="small fw-bold text-muted d-block px-2 mb-2">Mod <?php echo $m['module_order']; ?>: <?php echo esc($m['module_title']); ?></span>
                                    
                                    <?php foreach ($m['lessons'] as $navL): 
                                        $isActive = $navL['lesson_id'] == $lessonId;
                                        $navLCompleted = $navL['completed_status'] === 'Completed';
                                    ?>
                                        <a href="lesson.php?course_id=<?php echo $courseId; ?>&lesson_id=<?php echo $navL['lesson_id']; ?>" 
                                           class="list-group-item list-group-item-action py-2 px-3 border-0 rounded d-flex justify-content-between align-items-center mb-1 <?php echo $isActive ? 'bg-primary text-white active' : ''; ?>">
                                            <span class="small text-truncate" style="max-width: 80%;">
                                                <span class="font-monospace me-1"><?php echo $navL['lesson_order']; ?>.</span>
                                                <?php echo esc($navL['lesson_title']); ?>
                                            </span>
                                            <?php if ($navLCompleted): ?>
                                                <i class="fa-solid fa-circle-check text-<?php echo $isActive ? 'white' : 'success'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fa-solid fa-circle-play text-muted" style="opacity: 0.5;"></i>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function markAsComplete() {
    const btn = $('#btnMarkComplete');
    const origText = btn.html();
    
    btn.html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving...').prop('disabled', true);
    
    $.ajax({
        url: '../api/mark-lesson-complete.php',
        type: 'POST',
        data: {
            course_id: '<?php echo $courseId; ?>',
            lesson_id: '<?php echo $lessonId; ?>',
            csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Lesson Completed',
                    text: 'Your learning progress has been recorded!',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });
            } else {
                btn.html(origText).prop('disabled', false);
                Swal.fire('Error', response.message || 'Failed to update progress.', 'error');
            }
        },
        error: function() {
            btn.html(origText).prop('disabled', false);
            Swal.fire('Error', 'Communication failure with the server.', 'error');
        }
    });
}
</script>

