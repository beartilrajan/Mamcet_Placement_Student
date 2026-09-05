<?php
// MAMCET Placement & Learning Portal - LMS Courses Management Page

$pageTitle = 'LMS Course Manager';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$activeSessionId = getActiveAcademicSessionId();

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $title = trim($_POST['course_title'] ?? '');
        $code = strtoupper(trim($_POST['course_code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? '';
        $instructor = trim($_POST['instructor_name'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'Beginner';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $courseId = (int)($_POST['course_id'] ?? 0);
        
        $targetDepts = $_POST['departments'] ?? [];
        $targetBatches = $_POST['batches'] ?? [];
        
        if (empty($title) || empty($code) || empty($category)) {
            $error = 'Course Title, Code, and Category are required.';
        } else {
            try {
                $db->beginTransaction();
                
                // Process Thumbnail Upload
                $thumbPath = '';
                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['thumbnail'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($file['tmp_name']);
                        
                        if (strpos($mime, 'image/') !== false) {
                            $uploadDir = __DIR__ . '/../assets/uploads/thumbnails/';
                            if (!file_exists($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            
                            $safeName = 'thumb_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file['name']);
                            if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                                $thumbPath = 'assets/uploads/thumbnails/' . $safeName;
                            }
                        }
                    }
                }
                
                if ($action === 'create') {
                    // Check duplicate code
                    $chk = $db->prepare("SELECT course_id FROM courses WHERE course_code = ?");
                    $chk->execute([$code]);
                    if ($chk->fetch()) {
                        $error = 'A course with this code already exists.';
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO courses (course_title, course_code, description, category, thumbnail_path, instructor_name, difficulty, session_id, start_date, end_date, status, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $title, $code, $description, $category, $thumbPath, $instructor, $difficulty, 
                            $activeSessionId, $startDate ?: null, $endDate ?: null, $status, $_SESSION['user_id']
                        ]);
                        $newCourseId = $db->lastInsertId();
                        $courseIdForMapping = $newCourseId;
                        
                        // Default assignment to this academic session
                        $stmtAssign = $db->prepare("INSERT INTO course_assignments (course_id, session_id) VALUES (?, ?)");
                        $stmtAssign->execute([$newCourseId, $activeSessionId]);
                    }
                } else { // edit
                    $courseIdForMapping = $courseId;
                    
                    // Retain old thumbnail if no new file is uploaded
                    if (empty($thumbPath)) {
                        $stmtThumb = $db->prepare("SELECT thumbnail_path FROM courses WHERE course_id = ?");
                        $stmtThumb->execute([$courseId]);
                        $thumbPath = $stmtThumb->fetchColumn() ?: '';
                    }
                    
                    $stmt = $db->prepare("
                        UPDATE courses 
                        SET course_title = ?, course_code = ?, description = ?, category = ?, thumbnail_path = ?, instructor_name = ?, difficulty = ?, start_date = ?, end_date = ?, status = ? 
                        WHERE course_id = ?
                    ");
                    $stmt->execute([
                        $title, $code, $description, $category, $thumbPath, $instructor, $difficulty, 
                        $startDate ?: null, $endDate ?: null, $status, $courseId
                    ]);
                    
                    // Clear old mappings
                    $db->prepare("DELETE FROM course_departments WHERE course_id = ?")->execute([$courseId]);
                    $db->prepare("DELETE FROM course_batches WHERE course_id = ?")->execute([$courseId]);
                }
                
                if (empty($error)) {
                    // Insert Department & Batch mappings
                    $stmtDept = $db->prepare("INSERT INTO course_departments (course_id, dept_id) VALUES (?, ?)");
                    foreach ($targetDepts as $dId) {
                        $stmtDept->execute([$courseIdForMapping, $dId]);
                    }
                    
                    $stmtBatch = $db->prepare("INSERT INTO course_batches (course_id, batch_id) VALUES (?, ?)");
                    foreach ($targetBatches as $bId) {
                        $stmtBatch->execute([$courseIdForMapping, $bId]);
                    }
                    
                    $db->commit();

                    // Dispatch portal notification to targeted students
                    if ($status === 'active' && $courseIdForMapping > 0) {
                        require_once(__DIR__ . '/../services/NotificationService.php');
                        NotificationService::notifyCoursePublished($db, (int)$courseIdForMapping, (int)$_SESSION['user_id'], $action);
                    }

                    $success = 'Course ' . ($action === 'create' ? 'created' : 'updated') . ' successfully.';
                    logActivity($db, $_SESSION['user_id'], 'Manage Course', "Saved course $code ($title)");
                } else {
                    $db->rollBack();
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            try {
                // Fetch course info for logging and file cleanup
                $stmtC = $db->prepare("SELECT course_title, course_code, thumbnail_path FROM courses WHERE course_id = ?");
                $stmtC->execute([$courseId]);
                $courseInfo = $stmtC->fetch(PDO::FETCH_ASSOC);

                if (!$courseInfo) {
                    $error = 'Course not found or already deleted.';
                } else {
                    $db->beginTransaction();

                    // 1. Gather all lesson PDF resources for cleanup
                    $stmtPdfs = $db->prepare("
                        SELECT cl.pdf_resource_path 
                        FROM course_lessons cl 
                        JOIN course_modules cm ON cl.module_id = cm.module_id 
                        WHERE cm.course_id = ? AND cl.pdf_resource_path IS NOT NULL AND cl.pdf_resource_path != ''
                    ");
                    $stmtPdfs->execute([$courseId]);
                    $pdfPaths = $stmtPdfs->fetchAll(PDO::FETCH_COLUMN);

                    // 2. Cascade delete records in proper child-to-parent order
                    $db->prepare("
                        DELETE slp FROM student_lesson_progress slp
                        JOIN course_lessons cl ON slp.lesson_id = cl.lesson_id
                        JOIN course_modules cm ON cl.module_id = cm.module_id
                        WHERE cm.course_id = ?
                    ")->execute([$courseId]);

                    $db->prepare("DELETE FROM student_course_progress WHERE course_id = ?")->execute([$courseId]);

                    $db->prepare("
                        DELETE cl FROM course_lessons cl
                        JOIN course_modules cm ON cl.module_id = cm.module_id
                        WHERE cm.course_id = ?
                    ")->execute([$courseId]);

                    $db->prepare("DELETE FROM course_modules WHERE course_id = ?")->execute([$courseId]);
                    $db->prepare("DELETE FROM course_assignments WHERE course_id = ?")->execute([$courseId]);
                    $db->prepare("DELETE FROM course_departments WHERE course_id = ?")->execute([$courseId]);
                    $db->prepare("DELETE FROM course_batches WHERE course_id = ?")->execute([$courseId]);
                    $db->prepare("DELETE FROM courses WHERE course_id = ?")->execute([$courseId]);

                    $db->commit();

                    // 3. Delete physical files from disk
                    if (!empty($courseInfo['thumbnail_path'])) {
                        $thumbFile = __DIR__ . '/../' . ltrim($courseInfo['thumbnail_path'], '/\\');
                        if (file_exists($thumbFile) && is_file($thumbFile)) {
                            @unlink($thumbFile);
                        }
                    }
                    foreach ($pdfPaths as $pdfPath) {
                        if (!empty($pdfPath)) {
                            $resFile = __DIR__ . '/../' . ltrim($pdfPath, '/\\');
                            if (file_exists($resFile) && is_file($resFile)) {
                                @unlink($resFile);
                            }
                        }
                    }

                    $success = "Course '{$courseInfo['course_code']} - {$courseInfo['course_title']}' and all associated curriculum lessons were deleted successfully.";
                    logActivity($db, $_SESSION['user_id'], 'Delete Course', "Deleted course ID $courseId ({$courseInfo['course_code']})");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch helper resources
$departments = $db->query("SELECT dept_id, dept_code FROM departments ORDER BY dept_code")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY graduation_year DESC")->fetchAll();

// Fetch courses for active session
$stmtC = $db->prepare("
    SELECT c.*, 
           GROUP_CONCAT(DISTINCT d.dept_code SEPARATOR ', ') AS target_depts, 
           GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR ', ') AS target_batches,
           (SELECT COUNT(*) FROM course_modules WHERE course_id = c.course_id) AS total_modules,
           (SELECT COUNT(*) FROM course_lessons cl JOIN course_modules cm ON cl.module_id = cm.module_id WHERE cm.course_id = c.course_id) AS total_lessons
    FROM courses c
    LEFT JOIN course_departments cd ON c.course_id = cd.course_id
    LEFT JOIN departments d ON cd.dept_id = d.dept_id
    LEFT JOIN course_batches cb ON c.course_id = cb.course_id
    LEFT JOIN batches b ON cb.batch_id = b.batch_id
    WHERE c.session_id = ?
    GROUP BY c.course_id
    ORDER BY c.created_at DESC
");
$stmtC->execute([$activeSessionId]);
$courses = $stmtC->fetchAll();
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title fw-bold">Training Courses</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-2"></i> Create Course
            </button>
        </div>

        <div class="row">
            <?php if (empty($courses)): ?>
                <div class="col-12 text-center py-5">
                    <div class="mamcet-card p-5">
                        <i class="fa-solid fa-book-open fa-4x text-secondary mb-3" style="opacity: 0.4;"></i>
                        <h4 class="fw-bold">No Courses Published Yet</h4>
                        <p class="text-muted">Create syllabus paths and upload YouTube lessons to start learning programs.</p>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="openCreateModal()">Create Course Now</button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $c): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="mamcet-card h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div style="height: 160px; background-color: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative;">
                                    <?php if (!empty($c['thumbnail_path'])): ?>
                                        <img src="../<?php echo esc($c['thumbnail_path']); ?>" alt="Course cover" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-book-open fa-4x text-secondary" style="opacity: 0.3;"></i>
                                    <?php endif; ?>
                                    <span class="badge bg-dark position-absolute top-0 end-0 m-3"><?php echo esc($c['difficulty']); ?></span>
                                </div>
                                <div class="p-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-primary small fw-bold"><?php echo esc($c['category']); ?></span>
                                        <span class="badge <?php echo $c['status'] === 'active' ? 'bg-success-subtle text-success border-success' : 'bg-secondary-subtle text-secondary'; ?> border px-2 py-1" style="font-size: 0.7rem;"><?php echo strtoupper($c['status']); ?></span>
                                    </div>
                                    <h5 class="fw-bold mb-1 text-dark"><?php echo esc($c['course_title']); ?></h5>
                                    <small class="text-muted d-block mb-3">Code: <strong><?php echo esc($c['course_code']); ?></strong> | Instructor: <?php echo esc($c['instructor_name'] ?: 'Not specified'); ?></small>
                                    
                                    <p class="small text-secondary-emphasis mb-3 text-truncate-2" style="font-size:0.85rem; height: 38px; overflow: hidden;"><?php echo esc($c['description']); ?></p>
                                    
                                    <div class="pt-2 border-top">
                                        <span class="small d-block text-muted">Targets: <strong class="text-dark"><?php echo esc($c['target_depts'] ?: 'All Depts'); ?></strong></span>
                                        <span class="small d-block text-muted">Batches: <strong class="text-dark"><?php echo esc($c['target_batches'] ?: 'All Batches'); ?></strong></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="px-4 pb-4 pt-0 border-top bg-light d-flex justify-content-between align-items-center">
                                <span class="small text-muted"><i class="fa-solid fa-layer-group me-1"></i> Modules: <strong><?php echo $c['total_modules']; ?></strong> (<?php echo $c['total_lessons']; ?> lessons)</span>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="course-builder.php?course_id=<?php echo $c['course_id']; ?>" class="btn btn-sm btn-primary" title="Build Curriculum">
                                        <i class="fa-solid fa-cubes me-1"></i> Build
                                    </a>
                                    <button type="button" class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($c)); ?>)" title="Edit Course Details">
                                        <i class="fa-solid fa-pencil text-secondary"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border text-danger" onclick="confirmDeleteCourse(<?php echo (int)$c['course_id']; ?>, '<?php echo esc(addslashes($c['course_title'])); ?>', '<?php echo esc(addslashes($c['course_code'])); ?>')" title="Delete Course">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Form -->
<div class="modal fade" id="courseModal" tabindex="-1" aria-labelledby="courseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content text-dark">
            <form action="courses.php" method="POST" enctype="multipart/form-data">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="course_id" id="form_course_id" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="courseModalLabel">Create Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body" style="max-height: 520px; overflow-y: auto;">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Course Title</label>
                            <input type="text" name="course_title" id="modal_title" class="form-control" placeholder="e.g. Java Placement Preparation" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" id="modal_code" class="form-control" placeholder="e.g. CSE-JV01" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Course Category</label>
                            <select name="category" id="modal_category" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach (COURSE_CATEGORIES as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo esc($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Instructor Name</label>
                            <input type="text" name="instructor_name" id="modal_instructor" class="form-control" placeholder="e.g. Prof. R. Raman">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Difficulty Level</label>
                            <select name="difficulty" id="modal_difficulty" class="form-select">
                                <option value="Beginner">Beginner</option>
                                <option value="Intermediate">Intermediate</option>
                                <option value="Advanced">Advanced</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="modal_desc" class="form-control" rows="3" placeholder="Enter course summaries, outcomes, and prereqs..."></textarea>
                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Target Audience Assignment</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Target Departments</label>
                            <div class="p-2 border rounded" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($departments as $d): ?>
                                    <div class="form-check">
                                        <input class="form-check-input dept-chk" type="checkbox" name="departments[]" value="<?php echo $d['dept_id']; ?>" id="chk_d_<?php echo $d['dept_id']; ?>">
                                        <label class="form-check-label" for="chk_d_<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_code']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Target Batches</label>
                            <div class="p-2 border rounded" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($batches as $b): ?>
                                    <div class="form-check">
                                        <input class="form-check-input batch-chk" type="checkbox" name="batches[]" value="<?php echo $b['batch_id']; ?>" id="chk_b_<?php echo $b['batch_id']; ?>">
                                        <label class="form-check-label" for="chk_b_<?php echo $b['batch_id']; ?>"><?php echo esc($b['batch_name']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Thumbnail Cover</label>
                            <input type="file" name="thumbnail" class="form-control" accept="image/*">
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="modal_start" class="form-control">
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" id="modal_end" class="form-control">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="modal_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer d-flex justify-content-between align-items-center">
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteCourseInModal" style="display: none;" onclick="deleteCourseFromModal()">
                            <i class="fa-solid fa-trash-can me-1"></i> Delete Course
                        </button>
                    </div>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="btnSubmitForm">Create Course</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Deletion Form -->
<form id="deleteCourseForm" action="courses.php" method="POST" style="display: none;">
    <?php csrfInput(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="course_id" id="delete_course_id" value="0">
</form>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
let currentEditCourse = null;

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function openCreateModal() {
    currentEditCourse = null;
    $('#btnDeleteCourseInModal').hide();
    $('#courseModalLabel').text('Create Course');
    $('#form_action').val('create');
    $('#form_course_id').val('0');
    
    $('#modal_title').val('');
    $('#modal_code').val('');
    $('#modal_category').val('');
    $('#modal_instructor').val('');
    $('#modal_difficulty').val('Beginner');
    $('#modal_desc').val('');
    
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    $('#modal_start').val('');
    $('#modal_end').val('');
    $('#modal_status').val('active');
    
    $('#btnSubmitForm').text('Create Course');
}

function openEditModal(c) {
    currentEditCourse = c;
    $('#btnDeleteCourseInModal').show();
    $('#courseModalLabel').text('Edit Course Details');
    $('#form_action').val('edit');
    $('#form_course_id').val(c.course_id);
    
    $('#modal_title').val(c.course_title);
    $('#modal_code').val(c.course_code);
    $('#modal_category').val(c.category);
    $('#modal_instructor').val(c.instructor_name || '');
    $('#modal_difficulty').val(c.difficulty);
    $('#modal_desc').val(c.description || '');
    
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    // Parse target codes
    if (c.target_depts) {
        c.target_depts.split(', ').forEach(code => {
            $('.dept-chk').each(function() {
                if ($(this).next('label').text().trim() === code) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    if (c.target_batches) {
        c.target_batches.split(', ').forEach(name => {
            $('.batch-chk').each(function() {
                if ($(this).next('label').text().trim() === name) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    
    $('#modal_start').val(c.start_date || '');
    $('#modal_end').val(c.end_date || '');
    $('#modal_status').val(c.status);
    
    $('#btnSubmitForm').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('courseModal'));
    modal.show();
}

function deleteCourseFromModal() {
    if (!currentEditCourse) return;
    const modalEl = document.getElementById('courseModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) {
        modalInstance.hide();
    }
    confirmDeleteCourse(currentEditCourse.course_id, currentEditCourse.course_title, currentEditCourse.course_code);
}

function confirmDeleteCourse(courseId, title, code) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete LMS Course?',
            html: `Are you sure you want to permanently delete <strong>${escapeHtml(title)} (${escapeHtml(code)})</strong>?<br><br><div class="alert alert-danger text-start p-2 mb-0 small"><i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Warning:</strong> All syllabus modules, lesson videos, notes/PDF resources, and enrolled student progress records will be permanently deleted. This cannot be undone.</div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Delete Course',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_course_id').val(courseId);
                $('#deleteCourseForm').submit();
            }
        });
    } else {
        if (confirm(`Permanently delete course "${title} (${code})"? All curriculum modules, lessons, and progress will be deleted.`)) {
            $('#delete_course_id').val(courseId);
            $('#deleteCourseForm').submit();
        }
    }
}
</script>

