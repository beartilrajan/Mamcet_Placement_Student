<?php
// MAMCET Placement & Learning Portal - LMS Course Builder & Curriculum Page

$pageTitle = 'Curriculum Builder';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Course ID is required.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Fetch Course Details
$stmtC = $db->prepare("SELECT * FROM courses WHERE course_id = ?");
$stmtC->execute([$courseId]);
$course = $stmtC->fetch();

if (!$course) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Course record not found.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Handle Forms (Modules / Lessons CRUD)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create_module' || $action === 'edit_module') {
        $moduleTitle = trim($_POST['module_title'] ?? '');
        $description = trim($_POST['module_description'] ?? '');
        $moduleOrder = (int)($_POST['module_order'] ?? 1);
        $moduleId = (int)($_POST['module_id'] ?? 0);
        
        if (empty($moduleTitle)) {
            $error = 'Module title is required.';
        } else {
            try {
                if ($action === 'create_module') {
                    $stmt = $db->prepare("INSERT INTO course_modules (course_id, module_title, description, module_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$courseId, $moduleTitle, $description, $moduleOrder]);
                    $success = 'Module added successfully.';
                } else {
                    $stmt = $db->prepare("UPDATE course_modules SET module_title = ?, description = ?, module_order = ? WHERE module_id = ? AND course_id = ?");
                    $stmt->execute([$moduleTitle, $description, $moduleOrder, $moduleId, $courseId]);
                    $success = 'Module details updated successfully.';
                }
                logActivity($db, $_SESSION['user_id'], 'Manage Module', "Modified modules on course $courseId ($moduleTitle)");
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_module') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        if ($moduleId > 0) {
            try {
                $db->prepare("DELETE FROM course_modules WHERE module_id = ? AND course_id = ?")->execute([$moduleId, $courseId]);
                $success = 'Module and all its lessons deleted successfully.';
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create_lesson' || $action === 'edit_lesson') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $lessonTitle = trim($_POST['lesson_title'] ?? '');
        $description = trim($_POST['lesson_description'] ?? '');
        $youtubeUrl = trim($_POST['youtube_url'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0);
        $notesText = trim($_POST['notes_text'] ?? '');
        $externalUrl = trim($_POST['external_resource_url'] ?? '');
        $lessonOrder = (int)($_POST['lesson_order'] ?? 1);
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        
        $videoId = getYouTubeVideoId($youtubeUrl);
        
        if (empty($lessonTitle) || $moduleId <= 0) {
            $error = 'Lesson title and target module are required.';
        } elseif (!empty($youtubeUrl) && empty($videoId)) {
            $error = 'Invalid YouTube URL provided.';
        } else {
            try {
                // Process PDF resource upload
                $pdfPath = '';
                if (isset($_FILES['pdf_resource']) && $_FILES['pdf_resource']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['pdf_resource'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($file['tmp_name']);
                        
                        if ($mime === 'application/pdf') {
                            $uploadDir = __DIR__ . '/../assets/uploads/courses/';
                            if (!file_exists($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            
                            $safeName = 'notes_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file['name']);
                            if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                                $pdfPath = 'assets/uploads/courses/' . $safeName;
                            }
                        }
                    }
                }
                
                if ($action === 'create_lesson') {
                    $stmt = $db->prepare("
                        INSERT INTO course_lessons (module_id, lesson_title, description, youtube_url, youtube_video_id, duration_minutes, notes_text, pdf_resource_path, external_resource_url, lesson_order, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$moduleId, $lessonTitle, $description, $youtubeUrl, $videoId, $duration, $notesText, $pdfPath, $externalUrl, $lessonOrder]);
                    $success = 'Lesson created successfully.';
                } else {
                    // Retain PDF resource path if not uploading new
                    if (empty($pdfPath)) {
                        $stmtPdf = $db->prepare("SELECT pdf_resource_path FROM course_lessons WHERE lesson_id = ?");
                        $stmtPdf->execute([$lessonId]);
                        $pdfPath = $stmtPdf->fetchColumn() ?: '';
                    }
                    
                    $stmt = $db->prepare("
                        UPDATE course_lessons 
                        SET module_id = ?, lesson_title = ?, description = ?, youtube_url = ?, youtube_video_id = ?, duration_minutes = ?, notes_text = ?, pdf_resource_path = ?, external_resource_url = ?, lesson_order = ? 
                        WHERE lesson_id = ?
                    ");
                    $stmt->execute([$moduleId, $lessonTitle, $description, $youtubeUrl, $videoId, $duration, $notesText, $pdfPath, $externalUrl, $lessonOrder, $lessonId]);
                    $success = 'Lesson updated successfully.';
                }
                logActivity($db, $_SESSION['user_id'], 'Manage Lesson', "Modified lessons on course $courseId ($lessonTitle)");
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_lesson') {
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            try {
                $db->prepare("DELETE FROM course_lessons WHERE lesson_id = ?")->execute([$lessonId]);
                $success = 'Lesson deleted successfully.';
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Syllabus hierarchy (Modules -> Lessons)
$stmtMod = $db->prepare("SELECT * FROM course_modules WHERE course_id = ? ORDER BY module_order ASC, module_id ASC");
$stmtMod->execute([$courseId]);
$modules = $stmtMod->fetchAll();

foreach ($modules as &$m) {
    $stmtLes = $db->prepare("SELECT * FROM course_lessons WHERE module_id = ? ORDER BY lesson_order ASC, lesson_id ASC");
    $stmtLes->execute([$m['module_id']]);
    $m['lessons'] = $stmtLes->fetchAll();
}
unset($m);

// Fetch enrolled student completion stats
$stmtStats = $db->prepare("
    SELECT s.registration_number, s.student_name, d.dept_code, scp.completion_percentage, scp.status, scp.last_accessed_at
    FROM student_course_progress scp
    JOIN students s ON scp.student_id = s.student_id
    JOIN departments d ON s.dept_id = d.dept_id
    WHERE scp.course_id = ?
    ORDER BY scp.completion_percentage DESC
");
$stmtStats->execute([$courseId]);
$studentProgressStats = $stmtStats->fetchAll();
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Curriculum Builder</h4>
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

        <div class="mb-3">
            <a href="courses.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Courses</a>
        </div>

        <!-- Course Header Summary -->
        <div class="mamcet-card mb-4 bg-light">
            <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <span class="badge bg-primary mb-2"><?php echo esc($course['category']); ?></span>
                    <h2 class="fw-bold mb-1 text-dark"><?php echo esc($course['course_title']); ?></h2>
                    <p class="mb-0 text-muted">Syllabus configuration dashboard for course code: <strong><?php echo esc($course['course_code']); ?></strong></p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#moduleModal" onclick="openCreateModuleModal()">
                        <i class="fa-solid fa-plus me-1"></i> Add Module
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lessonModal" onclick="openCreateLessonModal()">
                        <i class="fa-solid fa-circle-plus me-1"></i> Add Lesson
                    </button>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- LEFT CURRICULUM SYLLABUS -->
            <div class="col-lg-7 mb-4">
                <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-bars-staggered me-2 text-primary"></i> Course Outline</h5>
                
                <?php if (empty($modules)): ?>
                    <div class="mamcet-card py-5 text-center text-muted">
                        <i class="fa-solid fa-cubes fa-3x mb-3 text-secondary" style="opacity: 0.3;"></i>
                        <p class="mb-0">No syllabus modules defined yet.</p>
                        <small>Create modules to hold your lessons.</small>
                    </div>
                <?php else: ?>
                    <div class="accordion" id="curriculumAccordion">
                        <?php foreach ($modules as $idx => $m): ?>
                            <div class="accordion-item mamcet-card mb-3 border">
                                <h2 class="accordion-header" id="heading_<?php echo $m['module_id']; ?>">
                                    <button class="accordion-button <?php echo $idx === 0 ? '' : 'collapsed'; ?> fw-bold text-dark py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $m['module_id']; ?>">
                                        <div class="d-flex justify-content-between align-items-center w-100 pe-3">
                                            <span>
                                                <span class="badge bg-dark me-2">Mod <?php echo esc($m['module_order']); ?></span>
                                                <?php echo esc($m['module_title']); ?>
                                            </span>
                                            <span class="badge bg-secondary font-monospace" style="font-size:0.75rem;"><?php echo count($m['lessons']); ?> Lessons</span>
                                        </div>
                                    </button>
                                </h2>
                                
                                <div id="collapse_<?php echo $m['module_id']; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#curriculumAccordion">
                                    <div class="accordion-body bg-white border-top">
                                        <p class="text-muted small border-bottom pb-2 mb-3"><?php echo esc($m['description'] ?: 'No module description provided.'); ?></p>
                                        
                                        <div class="text-end mb-3">
                                            <button class="btn btn-sm btn-outline-secondary me-1" onclick='openEditModuleModal(<?php echo json_encode($m); ?>)'>
                                                <i class="fa-solid fa-pencil"></i> Edit Module
                                            </button>
                                            <form action="course-builder.php?course_id=<?php echo $courseId; ?>" method="POST" class="d-inline" onsubmit="return confirm('Deleting this module will delete all its lessons permanently. Continue?')">
                                                <?php csrfInput(); ?>
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="module_id" value="<?php echo $m['module_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                            </form>
                                        </div>

                                        <!-- Lessons List -->
                                        <?php if (empty($m['lessons'])): ?>
                                            <p class="small text-muted text-center py-2">No lessons added to this module yet.</p>
                                        <?php else: ?>
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($m['lessons'] as $les): ?>
                                                    <div class="list-group-item py-3 px-0 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
                                                        <div>
                                                            <span class="fw-bold d-block text-dark">
                                                                <span class="badge bg-light text-dark border me-1"><?php echo esc($les['lesson_order']); ?></span>
                                                                <?php echo esc($les['lesson_title']); ?>
                                                            </span>
                                                            <small class="text-muted">
                                                                <?php if (!empty($les['youtube_video_id'])): ?>
                                                                    <i class="fa-brands fa-youtube text-danger me-1"></i> Video (<?php echo (int)$les['duration_minutes']; ?> mins)
                                                                <?php endif; ?>
                                                                <?php if (!empty($les['pdf_resource_path'])): ?>
                                                                    | <i class="fa-solid fa-file-pdf text-danger me-1"></i> Attachment Notes
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                        <div>
                                                            <button class="btn btn-sm btn-light border" onclick='openEditLessonModal(<?php echo json_encode($les); ?>)'>
                                                                <i class="fa-solid fa-pencil"></i> Edit
                                                            </button>
                                                            <form action="course-builder.php?course_id=<?php echo $courseId; ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this lesson permanently?')">
                                                                <?php csrfInput(); ?>
                                                                <input type="hidden" name="action" value="delete_lesson">
                                                                <input type="hidden" name="lesson_id" value="<?php echo $les['lesson_id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-light border"><i class="fa-solid fa-trash text-danger"></i></button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT ANALYTICS BOARD -->
            <div class="col-lg-5 mb-4">
                <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-chart-line me-2 text-primary"></i> Learning Roster</h5>
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">Enrolled Students Progress</h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($studentProgressStats)): ?>
                            <p class="text-muted small text-center py-5">No progress logs recorded. Students assigned have not started this course yet.</p>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem; border: none;">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Student</th>
                                            <th>Progress</th>
                                            <th>Last Active</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($studentProgressStats as $s): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-dark d-block"><?php echo esc($s['student_name']); ?></span>
                                                    <small class="text-muted"><?php echo esc($s['registration_number']); ?> (<?php echo esc($s['dept_code']); ?>)</small>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2" style="min-width: 100px;">
                                                        <div class="progress flex-grow-1" style="height: 4px;">
                                                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (float)$s['completion_percentage']; ?>%"></div>
                                                        </div>
                                                        <span class="fw-bold text-dark" style="font-size:0.75rem;"><?php echo (int)$s['completion_percentage']; ?>%</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo $s['last_accessed_at'] ? date('d M H:i', strtotime($s['last_accessed_at'])) : 'N/A'; ?></small>
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
        </div>

    </div>
</div>

<!-- MODULE MODAL -->
<div class="modal fade" id="moduleModal" tabindex="-1" aria-labelledby="moduleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content text-dark">
            <form action="course-builder.php?course_id=<?php echo $courseId; ?>" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="module_action" value="create_module">
                <input type="hidden" name="module_id" id="module_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="moduleModalLabel">Add Course Module</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Module Title</label>
                        <input type="text" name="module_title" id="m_title" class="form-control" placeholder="e.g. Chapter 1: Introduction to OOPs" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description / Summary</label>
                        <textarea name="module_description" id="m_desc" class="form-control" rows="3" placeholder="Brief outline of concepts covered in this module..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Module Order</label>
                        <input type="number" name="module_order" id="m_order" class="form-control" value="1" required>
                        <div class="form-text">Used for sorting modules sequentially in students view.</div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitModule">Add Module</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- LESSON MODAL -->
<div class="modal fade" id="lessonModal" tabindex="-1" aria-labelledby="lessonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content text-dark">
            <form action="course-builder.php?course_id=<?php echo $courseId; ?>" method="POST" enctype="multipart/form-data">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="lesson_action" value="create_lesson">
                <input type="hidden" name="lesson_id" id="lesson_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="lessonModalLabel">Add Lesson</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Target Syllabus Module</label>
                            <select name="module_id" id="l_module_id" class="form-select" required>
                                <option value="">-- Choose Module --</option>
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?php echo $m['module_id']; ?>">Mod <?php echo $m['module_order']; ?>: <?php echo esc($m['module_title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Lesson Title</label>
                            <input type="text" name="lesson_title" id="l_title" class="form-control" placeholder="e.g. Encapsulation & Polymorphism" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Lesson Description / Details</label>
                        <textarea name="lesson_description" id="l_desc" class="form-control" rows="2" placeholder="Brief outline of lesson video contents..."></textarea>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">YouTube Video Integration</h6>
                    <div class="row">
                        <div class="col-md-9 mb-3">
                            <label class="form-label">YouTube URL</label>
                            <input type="url" name="youtube_url" id="l_url" class="form-control" placeholder="https://www.youtube.com/watch?v=VIDEO_ID">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Duration (Minutes)</label>
                            <input type="number" name="duration_minutes" id="l_duration" class="form-control" value="0">
                        </div>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">Lesson Resources & Attachments</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Upload Study Notes / PDF Resource</label>
                            <input type="file" name="pdf_resource" class="form-control" accept="application/pdf">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">External resource link</label>
                            <input type="url" name="external_resource_url" id="l_external" class="form-control" placeholder="https://docs.oracle.com/...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Written Lesson Notes / Markdown Details</label>
                        <textarea name="notes_text" id="l_notes" class="form-control" rows="3" placeholder="Enter written lessons contents..."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Lesson Order (Sorting)</label>
                            <input type="number" name="lesson_order" id="l_order" class="form-control" value="1" required>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitLesson">Save Lesson</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCreateModuleModal() {
    $('#moduleModalLabel').text('Add Course Module');
    $('#module_action').val('create_module');
    $('#module_id').val('0');
    $('#m_title').val('');
    $('#m_desc').val('');
    $('#m_order').val('1');
    $('#btnSubmitModule').text('Add Module');
}

function openEditModuleModal(m) {
    $('#moduleModalLabel').text('Edit Course Module');
    $('#module_action').val('edit_module');
    $('#module_id').val(m.module_id);
    $('#m_title').val(m.module_title);
    $('#m_desc').val(m.description || '');
    $('#m_order').val(m.module_order);
    $('#btnSubmitModule').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('moduleModal'));
    modal.show();
}

function openCreateLessonModal() {
    $('#lessonModalLabel').text('Add Lesson');
    $('#lesson_action').val('create_lesson');
    $('#lesson_id').val('0');
    
    $('#l_module_id').val('');
    $('#l_title').val('');
    $('#l_desc').val('');
    $('#l_url').val('');
    $('#l_duration').val('0');
    $('#l_notes').val('');
    $('#l_external').val('');
    $('#l_order').val('1');
    
    $('#btnSubmitLesson').text('Add Lesson');
}

function openEditLessonModal(les) {
    $('#lessonModalLabel').text('Edit Lesson Details');
    $('#lesson_action').val('edit_lesson');
    $('#lesson_id').val(les.lesson_id);
    
    $('#l_module_id').val(les.module_id);
    $('#l_title').val(les.lesson_title);
    $('#l_desc').val(les.description || '');
    $('#l_url').val(les.youtube_url || '');
    $('#l_duration').val(les.duration_minutes);
    $('#l_notes').val(les.notes_text || '');
    $('#l_external').val(les.external_resource_url || '');
    $('#l_order').val(les.lesson_order);
    
    $('#btnSubmitLesson').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('lessonModal'));
    modal.show();
}
</script>

