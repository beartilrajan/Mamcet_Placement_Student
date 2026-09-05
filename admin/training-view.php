<?php
// MAMCET Placement & Learning Portal - Training Details & Attendance Dashboard Page

$trainingId = (int)($_GET['id'] ?? 0);
if ($trainingId <= 0) {
    header("Location: trainings.php");
    exit;
}

$pageTitle = 'Training Attendance Dashboard';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
require_once(__DIR__ . '/../services/TrainingService.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$trainingService = new TrainingService($db);

// Filter params
$search = trim($_GET['search'] ?? '');
$deptId = (int)($_GET['dept_id'] ?? 0);
$status = trim($_GET['status'] ?? '');

$filters = [
    'search' => $search,
    'dept_id' => $deptId,
    'status' => $status
];

$dashboardData = $trainingService->getTrainingAttendanceDashboard($trainingId, $filters);
if (!$dashboardData) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Training program not found or has been deleted.</div><a href='trainings.php' class='btn btn-secondary'>Back to Trainings</a></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$training = $dashboardData['training'];
$summary = $dashboardData['summary'];
$records = $dashboardData['records'];

// Fetch departments for filter dropdown
$departments = $db->query("SELECT dept_id, dept_code FROM departments ORDER BY dept_code ASC")->fetchAll(PDO::FETCH_ASSOC);

$computedStatus = $training['computed_status'] ?? $training['status'];
$badgeClass = 'bg-secondary';
if ($computedStatus === 'Active') $badgeClass = 'bg-success';
elseif ($computedStatus === 'Upcoming') $badgeClass = 'bg-info text-dark';
elseif ($computedStatus === 'Completed') $badgeClass = 'bg-primary';
elseif ($computedStatus === 'Draft') $badgeClass = 'bg-warning text-dark';
elseif ($computedStatus === 'Archived') $badgeClass = 'bg-dark';

$excelExportUrl = "../api/training-api.php?" . http_build_query([
    'action' => 'export_excel',
    'training_id' => $trainingId,
    'status' => $status,
    'dept_id' => $deptId,
    'search' => $search
]);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$trainerAttendanceUrl = $protocol . '://' . $host . $basePath . '/trainer/attendance.php?token=' . urlencode($training['trainer_access_token'] ?? '');
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">

        <!-- Top Breadcrumb Navigation -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="trainings.php" class="text-decoration-none">Trainings</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo esc($training['title']); ?></li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="<?php echo esc($excelExportUrl); ?>" class="btn btn-outline-success btn-sm shadow-sm" id="exportExcelBtn">
                    <i class="fa-solid fa-file-excel me-1"></i> Export Attendance Excel
                </a>
                <?php if (($training['event_type'] ?? 'Online') === 'Online' && !empty($training['training_url'])): ?>
                    <a href="<?php echo esc($training['training_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm shadow-sm">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Training Link
                    </a>
                <?php endif; ?>
                <a href="trainings.php" class="btn btn-light btn-sm border">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Roster
                </a>
            </div>
        </div>

        <!-- Training Hero Card -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white overflow-hidden">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
                    <div class="d-flex gap-3 align-items-start flex-grow-1">
                        <?php if (!empty($training['thumbnail_url'])): ?>
                            <img src="<?php echo esc($training['thumbnail_url']); ?>" alt="" class="rounded border d-none d-sm-block" style="width: 80px; height: 80px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded bg-primary-subtle text-primary d-none d-sm-flex align-items-center justify-content-center fw-bold" style="width: 80px; height: 80px; font-size: 1.75rem;">
                                <i class="fa-solid fa-chalkboard-user"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h3 class="fw-bold mb-0 text-dark"><?php echo esc($training['title']); ?></h3>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo esc($computedStatus); ?></span>
                            </div>
                            <?php if (!empty($training['trainer_name'])): ?>
                                <div class="text-secondary small fw-medium mb-2">
                                    <i class="fa-regular fa-user-circle me-1"></i>Trainer: <strong><?php echo esc($training['trainer_name']); ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($training['description'])): ?>
                                <p class="text-muted small mb-2" style="max-width: 750px;"><?php echo nl2br(esc($training['description'])); ?></p>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-3 small text-muted align-items-center pt-1 border-top">
                                <div>
                                    <i class="fa-regular fa-calendar me-1 text-primary"></i>
                                    <strong>Schedule:</strong> <?php echo date('M d, Y h:i A', strtotime($training['start_date_time'])); ?> - <?php echo date('M d, Y h:i A', strtotime($training['end_date_time'])); ?>
                                </div>
                                <div>
                                    <i class="fa-solid fa-<?php echo (($training['event_type'] ?? 'Online') === 'Offline') ? 'location-dot' : 'wifi'; ?> me-1 text-info"></i>
                                    <strong><?php echo esc($training['event_type'] ?? 'Online'); ?>:</strong> <?php echo esc(($training['event_type'] ?? 'Online') === 'Offline' ? ($training['venue_location'] ?? 'Venue not set') : 'External link'); ?>
                                </div>
                                <div>
                                    <i class="fa-solid fa-building-columns me-1 text-warning"></i>
                                    <strong>Assigned Departments:</strong> <?php echo esc($training['departments_display']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trainer Attendance Link -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-link text-primary me-1"></i> Trainer Attendance Link</h6>
                        <p class="text-muted small mb-0">Share this secure link with the assigned trainer. The trainer uses it to mark attendance for eligible students.</p>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyTrainerLink()"><i class="fa-regular fa-copy me-1"></i> Copy Link</button>
                </div>
                <div class="input-group input-group-sm mt-3">
                    <input type="text" class="form-control bg-light" id="trainerAttendanceLink" value="<?php echo esc($trainerAttendanceUrl); ?>" readonly aria-label="Trainer attendance link">
                </div>
                <?php if (!empty($training['attendance_submitted_at'])): ?>
                    <div class="alert alert-success py-2 px-3 mt-3 mb-0 small"><i class="fa-solid fa-lock me-1"></i><strong>Trainer submission locked:</strong> <?php echo date('M d, Y h:i A', strtotime($training['attendance_submitted_at'])); ?><?php if (!empty($training['attendance_submitted_by'])): ?> by <?php echo esc($training['attendance_submitted_by']); ?><?php endif; ?>. Admin corrections remain available below.</div>
                <?php else: ?>
                    <div class="alert alert-info py-2 px-3 mt-3 mb-0 small"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Trainer edits remain drafts until the trainer submits the roster. The student view and Excel export update after submission.</div>
                <?php endif; ?>
                <small class="text-muted d-block mt-2"><i class="fa-solid fa-shield-halved me-1"></i>Treat this link as private. Anyone with the link can mark attendance for this training.</small>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center">
                    <div class="text-muted small fw-medium">Eligible Students</div>
                    <div class="fs-4 fw-bold text-dark mt-1" id="kpiEligibleCount"><?php echo number_format($summary['eligible_count']); ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Assigned departments</small>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center">
                    <div class="text-muted small fw-medium">Present (Attended)</div>
                    <div class="fs-4 fw-bold text-success mt-1" id="kpiPresentCount"><?php echo number_format($summary['present_count']); ?></div>
                    <small class="text-success" style="font-size: 0.7rem;">Met criteria</small>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center">
                    <div class="text-muted small fw-medium">Late / Excused</div>
                    <div class="fs-4 fw-bold text-warning mt-1" id="kpiLateExcusedCount"><?php echo number_format($summary['late_count'] + $summary['excused_count']); ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Trainer-marked statuses</small>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center">
                    <div class="text-muted small fw-medium">Absent (Not Joined)</div>
                    <div class="fs-4 fw-bold text-danger mt-1" id="kpiAbsentCount"><?php echo number_format($summary['absent_count']); ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Marked by trainer</small>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center">
                    <div class="text-muted small fw-medium">Attendance Rate</div>
                    <div class="fs-4 fw-bold text-primary mt-1" id="kpiAttendanceRate"><?php echo $summary['attendance_percentage']; ?>%</div>
                    <div class="progress mt-1" style="height: 4px;">
                        <div class="progress-bar bg-primary" id="kpiProgressBar" role="progressbar" style="width: <?php echo $summary['attendance_percentage']; ?>%;"></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white h-100 text-center border-start border-success border-3">
                    <div class="text-muted small fw-medium">Pending Marking</div>
                    <div class="fs-4 fw-bold text-secondary mt-1" id="kpiPendingCount"><?php echo number_format($summary['pending_count']); ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Awaiting mark</small>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
            <div class="card-body p-3">
                <form method="GET" action="training-view.php" class="row g-2 align-items-end">
                    <input type="hidden" name="id" value="<?php echo $trainingId; ?>">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-muted mb-1">Search Student</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, roll no, email..." value="<?php echo esc($search); ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-muted mb-1">Filter by Department</label>
                        <select name="dept_id" class="form-select form-select-sm">
                            <option value="">All Assigned Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <?php if (in_array((int)$d['dept_id'], $training['department_ids'], true)): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo $deptId === (int)$d['dept_id'] ? 'selected' : ''; ?>>
                                        <?php echo esc($d['dept_code']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-muted mb-1">Filter Attendance Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Attendance Statuses</option>
                            <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Present" <?php echo $status === 'Present' ? 'selected' : ''; ?>>Present</option>
                            <option value="Late" <?php echo $status === 'Late' ? 'selected' : ''; ?>>Late</option>
                            <option value="Excused" <?php echo $status === 'Excused' ? 'selected' : ''; ?>>Excused</option>
                            <option value="Absent" <?php echo $status === 'Absent' ? 'selected' : ''; ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="fa-solid fa-filter me-1"></i> Filter
                        </button>
                        <a href="training-view.php?id=<?php echo $trainingId; ?>" class="btn btn-sm btn-light border" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Attendance Floating / Top Action Bar -->
        <div id="bulkActionBar" class="attendance-bulk-bar mb-3 d-none">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-check-circle text-success fs-5"></i>
                <span><strong id="selectedStudentsCount">0</strong> students selected</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="small text-white-50">Bulk Mark As:</span>
                <button type="button" class="btn btn-sm btn-success fw-semibold" onclick="applyBulkStatus('Present')"><i class="fa-solid fa-check me-1"></i> Present</button>
                <button type="button" class="btn btn-sm btn-warning text-dark fw-semibold" onclick="applyBulkStatus('Late')"><i class="fa-regular fa-clock me-1"></i> Late</button>
                <button type="button" class="btn btn-sm btn-info text-dark fw-semibold" onclick="applyBulkStatus('Excused')"><i class="fa-solid fa-shield-halved me-1"></i> Excused</button>
                <button type="button" class="btn btn-sm btn-danger fw-semibold" onclick="applyBulkStatus('Absent')"><i class="fa-solid fa-xmark me-1"></i> Absent</button>
                <button type="button" class="btn btn-sm btn-secondary fw-semibold" onclick="applyBulkStatus('Pending')"><i class="fa-solid fa-rotate-left me-1"></i> Pending</button>
                <button type="button" class="btn btn-sm btn-outline-light ms-2" onclick="clearAllSelections()"><i class="fa-solid fa-times me-1"></i> Cancel</button>
            </div>
        </div>

        <!-- Attendance Records Table -->
        <div class="card border-0 shadow-sm rounded-3 bg-white overflow-hidden">
            <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-clipboard-user text-primary me-1"></i> Student Attendance Roster</h6>
                    <small class="text-muted">Displaying <span id="displayedStudentsCount"><?php echo count($records); ?></span> eligible students</small>
                </div>
                <!-- Quick Batch Toolbar -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="input-group input-group-sm" style="max-width: 220px;">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" id="liveRosterSearch" class="form-control border-start-0" placeholder="Quick find on page..." oninput="filterRosterLive(this.value)">
                    </div>
                    <button type="button" class="btn btn-sm btn-success" onclick="confirmMarkAllRoster('Present')">
                        <i class="fa-solid fa-check-double me-1"></i> Mark All Present
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="confirmMarkAllRoster('Present', true)">
                        <i class="fa-solid fa-user-check me-1"></i> Mark Pending Present
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmMarkAllRoster('Absent', true)">
                        <i class="fa-solid fa-user-xmark me-1"></i> Mark Pending Absent
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="attendanceTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;" class="text-center">
                                <input type="checkbox" class="form-check-input" id="selectAllStudentsCb" onchange="toggleSelectAll(this)" title="Select All Students">
                            </th>
                            <th style="min-width: 200px;">Student / Roll No</th>
                            <th>Email & Dept</th>
                            <th style="min-width: 280px;">1-Click Attendance Marking</th>
                            <th>Status</th>
                            <th>Marked Info</th>
                            <th>Audit Note</th>
                            <th class="text-end" style="min-width: 80px;">Edit</th>
                        </tr>
                    </thead>
                    <tbody id="rosterTableBody">
                        <?php if (empty($records)): ?>
                            <tr id="emptyRosterRow">
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-user-slash fa-3x opacity-50 mb-3 d-block"></i>
                                    <h6>No student records found</h6>
                                    <p class="small text-muted mb-0">Try clearing your search query or department filter.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $r): ?>
                                <?php
                                    $st = $r['attendance_status'];
                                    $stBadge = 'bg-secondary-subtle text-secondary border';
                                    if ($st === 'Present') $stBadge = 'bg-success text-white';
                                    elseif ($st === 'Late') $stBadge = 'bg-warning text-dark';
                                    elseif ($st === 'Excused') $stBadge = 'bg-info text-dark';
                                    elseif ($st === 'Absent') $stBadge = 'bg-danger text-white';
                                    elseif ($st === 'Pending') $stBadge = 'bg-secondary-subtle text-secondary border';
                                ?>
                                <tr class="student-row" 
                                    data-student-id="<?php echo (int)$r['student_id']; ?>"
                                    data-attendance-id="<?php echo (int)$r['attendance_id']; ?>"
                                    data-student-name="<?php echo esc($r['student_name']); ?>"
                                    data-reg-no="<?php echo esc($r['registration_number']); ?>"
                                    data-email="<?php echo esc($r['email']); ?>"
                                    data-status="<?php echo esc($st); ?>">
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input student-select-cb" value="<?php echo (int)$r['student_id']; ?>" onchange="updateBulkBarState()">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div>
                                                <div class="fw-bold text-dark student-name-text"><?php echo esc($r['student_name']); ?></div>
                                                <div class="small text-muted font-monospace" style="font-size: 0.75rem;">
                                                    <?php echo esc($r['registration_number']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small text-muted mb-1"><?php echo esc($r['email'] ?: '—'); ?></div>
                                        <span class="badge bg-light text-dark border"><?php echo esc($r['dept_code']); ?></span>
                                    </td>
                                    <td>
                                        <!-- 1-Click Status Pill Group -->
                                        <div class="btn-attendance-group" role="group" aria-label="Attendance status buttons">
                                            <button type="button" 
                                                    class="btn-att-pill pill-present <?php echo $st === 'Present' ? 'active-present' : ''; ?>" 
                                                    onclick="quickMarkStudent(<?php echo (int)$r['student_id']; ?>, 'Present', this)" 
                                                    title="Mark Present">
                                                <i class="fa-solid fa-check"></i> Present
                                            </button>
                                            <button type="button" 
                                                    class="btn-att-pill pill-late <?php echo $st === 'Late' ? 'active-late' : ''; ?>" 
                                                    onclick="quickMarkStudent(<?php echo (int)$r['student_id']; ?>, 'Late', this)" 
                                                    title="Mark Late">
                                                <i class="fa-regular fa-clock"></i> Late
                                            </button>
                                            <button type="button" 
                                                    class="btn-att-pill pill-excused <?php echo $st === 'Excused' ? 'active-excused' : ''; ?>" 
                                                    onclick="quickMarkStudent(<?php echo (int)$r['student_id']; ?>, 'Excused', this)" 
                                                    title="Mark Excused">
                                                <i class="fa-solid fa-shield-halved"></i> Excused
                                            </button>
                                            <button type="button" 
                                                    class="btn-att-pill pill-absent <?php echo $st === 'Absent' ? 'active-absent' : ''; ?>" 
                                                    onclick="quickMarkStudent(<?php echo (int)$r['student_id']; ?>, 'Absent', this)" 
                                                    title="Mark Absent">
                                                <i class="fa-solid fa-xmark"></i> Absent
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $stBadge; ?> status-badge-pill" style="font-size: 0.75rem;">
                                            <?php echo esc($st); ?>
                                        </span>
                                        <?php if (!empty($r['manually_adjusted'])): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-light text-secondary border adjusted-badge" title="Edited by <?php echo esc($r['adjusted_by_name'] ?? 'Admin'); ?>">
                                                    <i class="fa-solid fa-user-pen"></i> Edited
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark marked-at-text" style="font-size: 0.75rem;">
                                            <?php echo !empty($r['marked_at']) ? date('M d, h:i A', strtotime($r['marked_at'])) : 'Pending'; ?>
                                        </div>
                                        <div class="small text-muted marked-by-text" style="font-size: 0.7rem;">
                                            <?php echo esc($r['marked_by'] ?: '—'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="text-muted audit-note-text" title="<?php echo esc($r['attendance_note'] ?? ''); ?>" style="font-size: 0.72rem;">
                                            <?php echo esc($r['attendance_note'] ?: '—'); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Edit Note / Custom Correction" onclick="openManualAdjustModal(<?php echo (int)$r['attendance_id']; ?>, <?php echo (int)$trainingId; ?>, <?php echo (int)$r['student_id']; ?>, '<?php echo esc(addslashes($r['student_name'])); ?>', '<?php echo esc($st); ?>', '<?php echo esc(addslashes($r['adjustment_reason'] ?? '')); ?>')">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Legacy session modal retained for historical records -->
<div class="modal fade" id="sessionsModal" tabindex="-1" aria-labelledby="sessionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold" id="sessionsModalLabel">Historical Session Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="sessionsLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div id="sessionsContent" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light small">
                                <tr>
                                    <th>Session ID</th>
                                    <th>Status</th>
                                    <th>Started At</th>
                                    <th>Last Heartbeat</th>
                                    <th>Ended At</th>
                                    <th>Duration</th>
                                    <th>IP / Agent</th>
                                </tr>
                            </thead>
                            <tbody id="sessionsTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-4">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Manual Attendance Adjustment -->
<div class="modal fade" id="adjustModal" tabindex="-1" aria-labelledby="adjustModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form id="adjustForm" onsubmit="handleManualAdjustSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?php echo esc(getCsrfToken()); ?>">
                <input type="hidden" name="action" value="adjust_attendance">
                <input type="hidden" name="attendance_id" id="adjustAttendanceId" value="0">
                <input type="hidden" name="training_id" id="adjustTrainingId" value="0">
                <input type="hidden" name="student_id" id="adjustStudentId" value="0">

                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold" id="adjustModalLabel">Manual Attendance Correction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="adjustAlert" class="alert alert-danger d-none mb-3"></div>

                    <div class="mb-3">
                        <label class="form-label small text-muted mb-0">Student</label>
                        <div class="fw-bold fs-6 text-dark" id="adjustStudentName">—</div>
                    </div>

                    <div class="mb-3">
                        <label for="adjustStatus" class="form-label small fw-bold">Attendance Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="adjustStatus" name="status" required>
                            <option value="Pending">Pending</option>
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Excused">Excused</option>
                            <option value="Absent">Absent</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="adjustReason" class="form-label small fw-bold">Correction Reason / Audit Note <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="adjustReason" name="reason" rows="3" required placeholder="Explain why the attendance status is being corrected."></textarea>
                        <small class="text-muted">This correction note will be logged in the permanent attendance record and export files.</small>
                    </div>
                </div>
                <div class="modal-footer border-top py-2 px-4">
                    <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="saveAdjustBtn">
                        <i class="fa-solid fa-check me-1"></i> Save Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const TRAINING_ID = <?php echo (int)$trainingId; ?>;
const CSRF_TOKEN = '<?php echo esc(getCsrfToken()); ?>';

// Status badge styling helper
const STATUS_BADGE_CLASSES = {
    'Present': 'bg-success text-white',
    'Late': 'bg-warning text-dark',
    'Excused': 'bg-info text-dark',
    'Absent': 'bg-danger text-white',
    'Pending': 'bg-secondary-subtle text-secondary border'
};

function copyTrainerLink() {
    const input = document.getElementById('trainerAttendanceLink');
    if (!input) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(() => {
            if (window.Swal) Swal.fire({ icon: 'success', title: 'Copied', text: 'Trainer attendance link copied.', timer: 1400, showConfirmButton: false, toast: true, position: 'top-end' });
        }).catch(() => {
            input.select();
            document.execCommand('copy');
        });
    } else {
        input.select();
        document.execCommand('copy');
    }
}

function updateKpiDashboard(summary) {
    if (!summary) return;
    const elCount = document.getElementById('kpiEligibleCount');
    const elPres = document.getElementById('kpiPresentCount');
    const elLate = document.getElementById('kpiLateExcusedCount');
    const elAbs = document.getElementById('kpiAbsentCount');
    const elRate = document.getElementById('kpiAttendanceRate');
    const elBar = document.getElementById('kpiProgressBar');
    const elPend = document.getElementById('kpiPendingCount');

    if (elCount) elCount.innerText = Number(summary.eligible_count || 0).toLocaleString();
    if (elPres) elPres.innerText = Number(summary.present_count || 0).toLocaleString();
    if (elLate) elLate.innerText = Number((summary.late_count || 0) + (summary.excused_count || 0)).toLocaleString();
    if (elAbs) elAbs.innerText = Number(summary.absent_count || 0).toLocaleString();
    if (elPend) elPend.innerText = Number(summary.pending_count || 0).toLocaleString();
    if (elRate) elRate.innerText = (summary.attendance_percentage || 0) + '%';
    if (elBar) {
        elBar.style.width = (summary.attendance_percentage || 0) + '%';
        elBar.setAttribute('aria-valuenow', summary.attendance_percentage || 0);
    }
}

function updateRowVisuals(row, status, res) {
    if (!row) return;
    row.setAttribute('data-status', status);

    // Update pill active classes
    const pills = row.querySelectorAll('.btn-att-pill');
    pills.forEach(p => {
        p.classList.remove('active-present', 'active-late', 'active-excused', 'active-absent', 'active-pending');
        const pStatus = p.getAttribute('data-status');
        if (pStatus === status) {
            p.classList.add('active-' + status.toLowerCase());
        }
    });

    // Update status badge
    const badge = row.querySelector('.status-badge-pill');
    if (badge) {
        badge.className = 'badge ' + (STATUS_BADGE_CLASSES[status] || STATUS_BADGE_CLASSES.Pending) + ' status-badge-pill';
        badge.innerText = status;
    }

    // Update marked timestamp and user
    if (res && res.marked_at) {
        const markedAtEl = row.querySelector('.marked-at-text');
        if (markedAtEl) markedAtEl.innerText = res.marked_at;
    }
    if (res && res.marked_by) {
        const markedByEl = row.querySelector('.marked-by-text');
        if (markedByEl) markedByEl.innerText = res.marked_by;
    }
    if (res && res.attendance_id) {
        row.setAttribute('data-attendance-id', res.attendance_id);
    }
}

function quickMarkStudent(studentId, status, clickedBtn) {
    const row = document.querySelector(`.student-row[data-student-id="${studentId}"]`);
    const studentName = row ? (row.getAttribute('data-student-name') || 'Student') : 'Student';
    const originalContent = clickedBtn.innerHTML;

    // Show loading spinner on clicked pill
    clickedBtn.disabled = true;
    clickedBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    $.ajax({
        url: '../api/training-api.php',
        type: 'POST',
        data: {
            action: 'quick_mark_attendance',
            training_id: TRAINING_ID,
            student_id: studentId,
            status: status,
            csrf_token: CSRF_TOKEN
        },
        dataType: 'json',
        success: function(res) {
            clickedBtn.disabled = false;
            clickedBtn.innerHTML = originalContent;

            if (res.success) {
                updateRowVisuals(row, status, res);
                updateKpiDashboard(res.summary);
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: status,
                        text: `${studentName} marked as ${status}`,
                        timer: 1200,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                }
            } else {
                if (window.Swal) Swal.fire('Error', res.message || 'Failed to update attendance', 'error');
            }
        },
        error: function(xhr) {
            clickedBtn.disabled = false;
            clickedBtn.innerHTML = originalContent;
            let msg = 'Failed to connect to server.';
            if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            if (window.Swal) Swal.fire('Error', msg, 'error');
        }
    });
}

function updateBulkBarState() {
    const checked = document.querySelectorAll('.student-select-cb:checked');
    const totalVisible = document.querySelectorAll('.student-row:not(.d-none) .student-select-cb');
    const bulkBar = document.getElementById('bulkActionBar');
    const countEl = document.getElementById('selectedStudentsCount');
    const masterCb = document.getElementById('selectAllStudentsCb');

    if (countEl) countEl.innerText = checked.length;
    if (bulkBar) {
        if (checked.length > 0) {
            bulkBar.classList.remove('d-none');
        } else {
            bulkBar.classList.add('d-none');
        }
    }

    if (masterCb) {
        masterCb.checked = (totalVisible.length > 0 && checked.length === totalVisible.length);
        masterCb.indeterminate = (checked.length > 0 && checked.length < totalVisible.length);
    }
}

function toggleSelectAll(masterCb) {
    const visibleCheckboxes = document.querySelectorAll('.student-row:not(.d-none) .student-select-cb');
    visibleCheckboxes.forEach(cb => {
        cb.checked = masterCb.checked;
    });
    updateBulkBarState();
}

function clearAllSelections() {
    document.querySelectorAll('.student-select-cb').forEach(cb => cb.checked = false);
    const masterCb = document.getElementById('selectAllStudentsCb');
    if (masterCb) {
        masterCb.checked = false;
        masterCb.indeterminate = false;
    }
    updateBulkBarState();
}

function applyBulkStatus(status) {
    const checkedBoxes = Array.from(document.querySelectorAll('.student-select-cb:checked'));
    if (!checkedBoxes.length) {
        if (window.Swal) Swal.fire('No Students Selected', 'Please check at least one student to update.', 'info');
        return;
    }

    const studentIds = checkedBoxes.map(cb => cb.value);

    Swal.fire({
        title: `Mark ${studentIds.length} Student(s) as ${status}?`,
        text: `This will immediately update the attendance status for all selected students.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, Mark as ${status}`,
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Updating Attendance...',
                text: 'Please wait while records are saved.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            $.ajax({
                url: '../api/training-api.php',
                type: 'POST',
                data: {
                    action: 'bulk_mark_attendance',
                    training_id: TRAINING_ID,
                    student_ids: studentIds,
                    status: status,
                    csrf_token: CSRF_TOKEN
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        checkedBoxes.forEach(cb => {
                            const row = cb.closest('.student-row');
                            updateRowVisuals(row, status, res);
                        });
                        updateKpiDashboard(res.summary);
                        clearAllSelections();
                        Swal.fire('Updated', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message || 'Failed to update selected students.', 'error');
                    }
                },
                error: function(xhr) {
                    let msg = 'Failed to connect to server.';
                    if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                    Swal.fire('Error', msg, 'error');
                }
            });
        }
    });
}

function confirmMarkAllRoster(status, onlyPending = false) {
    const rows = Array.from(document.querySelectorAll('.student-row'));
    if (!rows.length) {
        Swal.fire('No Students', 'No student records are currently available on this page.', 'info');
        return;
    }

    let targetRows = rows;
    if (onlyPending) {
        targetRows = rows.filter(r => r.getAttribute('data-status') === 'Pending');
    }

    if (!targetRows.length) {
        Swal.fire('None Found', onlyPending ? 'There are no pending students in this roster.' : 'No students to update.', 'info');
        return;
    }

    const studentIds = targetRows.map(r => r.getAttribute('data-student-id'));
    const titleText = onlyPending ? `Mark all ${studentIds.length} pending student(s) as ${status}?` : `Mark all ${studentIds.length} student(s) as ${status}?`;

    Swal.fire({
        title: titleText,
        text: `All ${targetRows.length} matching students in this training will be marked as ${status}.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: `Yes, Mark ${status}`,
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Saving Records...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            $.ajax({
                url: '../api/training-api.php',
                type: 'POST',
                data: {
                    action: 'bulk_mark_attendance',
                    training_id: TRAINING_ID,
                    student_ids: studentIds,
                    status: status,
                    csrf_token: CSRF_TOKEN
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        targetRows.forEach(r => {
                            updateRowVisuals(r, status, res);
                        });
                        updateKpiDashboard(res.summary);
                        clearAllSelections();
                        Swal.fire('Completed', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message || 'Failed to update attendance.', 'error');
                    }
                },
                error: function(xhr) {
                    let msg = 'Failed to connect to server.';
                    if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                    Swal.fire('Error', msg, 'error');
                }
            });
        }
    });
}

function filterRosterLive(query) {
    query = (query || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.student-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const name = (r.getAttribute('data-student-name') || '').toLowerCase();
        const reg = (r.getAttribute('data-reg-no') || '').toLowerCase();
        const email = (r.getAttribute('data-email') || '').toLowerCase();

        if (!query || name.includes(query) || reg.includes(query) || email.includes(query)) {
            r.classList.remove('d-none');
            visibleCount++;
        } else {
            r.classList.add('d-none');
        }
    });

    const displayedCountEl = document.getElementById('displayedStudentsCount');
    if (displayedCountEl) displayedCountEl.innerText = visibleCount;
    updateBulkBarState();
}

function viewStudentSessions(attendanceId, studentName) {
    document.getElementById('sessionsModalLabel').innerText = 'Engagement Sessions: ' + studentName;
    document.getElementById('sessionsLoading').classList.remove('d-none');
    document.getElementById('sessionsContent').classList.add('d-none');
    document.getElementById('sessionsTableBody').innerHTML = '';

    new bootstrap.Modal(document.getElementById('sessionsModal')).show();

    $.ajax({
        url: '../api/training-api.php',
        type: 'GET',
        data: { action: 'get_sessions', attendance_id: attendanceId },
        dataType: 'json',
        success: function(res) {
            document.getElementById('sessionsLoading').classList.add('d-none');
            document.getElementById('sessionsContent').classList.remove('d-none');

            if (!res.success || !res.sessions || res.sessions.length === 0) {
                document.getElementById('sessionsTableBody').innerHTML = '<tr><td colspan="7" class="text-center py-3 text-muted">No session heartbeats recorded.</td></tr>';
                return;
            }

            let html = '';
            res.sessions.forEach(s => {
                const statusBadge = s.session_status === 'completed' ? 'bg-success' : (s.session_status === 'active' ? 'bg-primary' : 'bg-secondary');
                const durMin = Math.floor(s.engagement_seconds / 60);
                const durSec = s.engagement_seconds % 60;
                const durStr = (durMin > 0 ? durMin + 'm ' : '') + durSec + 's';

                html += `<tr>
                    <td class="font-monospace small">#${s.session_id}</td>
                    <td><span class="badge ${statusBadge}">${s.session_status}</span></td>
                    <td class="small">${s.started_at}</td>
                    <td class="small">${s.last_heartbeat_at}</td>
                    <td class="small">${s.ended_at || '—'}</td>
                    <td class="fw-bold small">${durStr}</td>
                    <td class="small text-muted text-truncate" style="max-width: 140px;" title="${s.user_agent || ''}">${s.ip_address || '—'}</td>
                </tr>`;
            });
            document.getElementById('sessionsTableBody').innerHTML = html;
        },
        error: function() {
            document.getElementById('sessionsLoading').classList.add('d-none');
            document.getElementById('sessionsContent').classList.remove('d-none');
            document.getElementById('sessionsTableBody').innerHTML = '<tr><td colspan="7" class="text-center py-3 text-danger">Failed to load session details.</td></tr>';
        }
    });
}

function openManualAdjustModal(attendanceId, trainingId, studentId, studentName, currentStatus, currentReason) {
    document.getElementById('adjustAttendanceId').value = attendanceId;
    document.getElementById('adjustTrainingId').value = trainingId;
    document.getElementById('adjustStudentId').value = studentId;
    document.getElementById('adjustStudentName').innerText = studentName;
    document.getElementById('adjustStatus').value = currentStatus || 'Present';
    document.getElementById('adjustReason').value = currentReason || '';
    document.getElementById('adjustAlert').classList.add('d-none');

    new bootstrap.Modal(document.getElementById('adjustModal')).show();
}

function handleManualAdjustSubmit(e) {
    e.preventDefault();
    const alertBox = document.getElementById('adjustAlert');
    alertBox.classList.add('d-none');
    alertBox.innerText = '';

    const reason = document.getElementById('adjustReason').value.trim();
    if (!reason) {
        alertBox.innerText = 'An audit reason for manual correction is required.';
        alertBox.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('saveAdjustBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    const formData = new FormData(document.getElementById('adjustForm'));

    $.ajax({
        url: '../api/training-api.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Save Adjustment';

            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('adjustModal')).hide();
                const studentId = document.getElementById('adjustStudentId').value;
                const newStatus = document.getElementById('adjustStatus').value;
                const row = document.querySelector(`.student-row[data-student-id="${studentId}"]`);
                if (row) {
                    updateRowVisuals(row, newStatus, res);
                    const noteEl = row.querySelector('.audit-note-text');
                    if (noteEl) noteEl.innerText = reason;
                }
                updateKpiDashboard(res.summary);
                Swal.fire({
                    icon: 'success',
                    title: 'Attendance Corrected',
                    text: res.message,
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            } else {
                alertBox.innerText = res.message || 'Failed to update attendance.';
                alertBox.classList.remove('d-none');
            }
        },
        error: function(xhr) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Save Adjustment';
            let msg = 'Server error encountered.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            alertBox.innerText = msg;
            alertBox.classList.remove('d-none');
        }
    });
}
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
