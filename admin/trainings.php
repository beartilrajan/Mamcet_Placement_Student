<?php
// MAMCET Placement & Learning Portal - Admin Training Management Page

$pageTitle = 'Training Programs & Attendance';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
require_once(__DIR__ . '/../services/TrainingService.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$trainingService = new TrainingService($db);

// Filter and Pagination params
$search = trim($_GET['search'] ?? '');
$deptId = (int)($_GET['dept_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sortBy = trim($_GET['sort_by'] ?? 'created_at');
$sortOrder = trim($_GET['sort_order'] ?? 'DESC');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$filters = [
    'search' => $search,
    'dept_id' => $deptId,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'sort_by' => $sortBy,
    'sort_order' => $sortOrder
];

$trainingsResult = $trainingService->listTrainings($filters, $page, $perPage);
$trainings = $trainingsResult['items'];
$totalItems = $trainingsResult['total'];
$totalPages = $trainingsResult['total_pages'];

// Helper data for forms and filters
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Overall metric counts
$totalTrainingsCount = (int)$db->query("SELECT COUNT(*) FROM trainings WHERE status != 'Archived'")->fetchColumn();
$activeTrainingsCount = (int)$db->query("SELECT COUNT(*) FROM trainings WHERE status != 'Archived' AND status != 'Draft' AND start_date_time <= NOW() AND end_date_time >= NOW()")->fetchColumn();
$upcomingTrainingsCount = (int)$db->query("SELECT COUNT(*) FROM trainings WHERE status != 'Archived' AND status != 'Draft' AND start_date_time > NOW()")->fetchColumn();
$totalAttendedCount = (int)$db->query("SELECT COUNT(DISTINCT CONCAT(training_id, ':', student_id)) FROM training_attendance WHERE status IN ('Present', 'Late', 'Excused')")->fetchColumn();

// Helper URLs for trainer attendance links
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">Training Programs & Attendance</h1>
        <p class="text-muted mb-0">Organize online and offline trainings, assign eligible departments, and share trainer attendance links.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary shadow-sm" onclick="openCreateTrainingModal()">
                    <i class="fa-solid fa-plus me-1"></i> Create Training
                </button>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Total Programs</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?php echo number_format($totalTrainingsCount); ?></div>
                        </div>
                        <div class="rounded-3 p-3 bg-primary-subtle text-primary">
                            <i class="fa-solid fa-chalkboard-user fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Live & Active</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?php echo number_format($activeTrainingsCount); ?></div>
                        </div>
                        <div class="rounded-3 p-3 bg-success-subtle text-success">
                            <i class="fa-solid fa-tower-broadcast fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Upcoming</div>
                            <div class="fs-4 fw-bold text-info mt-1"><?php echo number_format($upcomingTrainingsCount); ?></div>
                        </div>
                        <div class="rounded-3 p-3 bg-info-subtle text-info">
                            <i class="fa-solid fa-calendar-check fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-medium">Unique Attendees</div>
                            <div class="fs-4 fw-bold text-warning mt-1"><?php echo number_format($totalAttendedCount); ?></div>
                        </div>
                        <div class="rounded-3 p-3 bg-warning-subtle text-warning">
                            <i class="fa-solid fa-user-graduate fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
            <div class="card-body p-3">
                <form method="GET" action="trainings.php" id="filterForm" class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold text-muted mb-1">Search Keyword</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="Title, trainer, or topic..." value="<?php echo esc($search); ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                        <select name="dept_id" class="form-select form-select-sm">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $deptId === (int)$d['dept_id'] ? 'selected' : ''; ?>>
                                    <?php echo esc($d['dept_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            <option value="Upcoming" <?php echo $status === 'Upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="Active" <?php echo $status === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Completed" <?php echo $status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Draft" <?php echo $status === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="Archived" <?php echo $status === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold text-muted mb-1">Start Date Range</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo esc($dateFrom); ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold text-muted mb-1">End Date Range</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo esc($dateTo); ?>">
                    </div>
                    <div class="col-12 col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary w-100" title="Apply Filter">
                            <i class="fa-solid fa-filter"></i>
                        </button>
                        <a href="trainings.php" class="btn btn-sm btn-light border" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Trainings Listing Table -->
        <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="table-responsive table-responsive-dropdown" style="min-height: 280px;">
                <table class="table table-hover align-middle mb-0" id="trainingsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 250px;">Training Program</th>
                            <th>Assigned Depts</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Eligible / Attended</th>
                            <th>Attendance</th>
                            <th>Created By</th>
                            <th class="text-end" style="min-width: 170px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trainings)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fa-solid fa-chalkboard-user fa-3x text-secondary opacity-50 mb-3 d-block"></i>
                                        <h6 class="fw-bold">No Training Programs Found</h6>
                                        <p class="small text-muted mb-3">No trainings match your current query or no trainings have been created yet.</p>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openCreateTrainingModal()">
                                            <i class="fa-solid fa-plus me-1"></i> Create First Training
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($trainings as $t): ?>
                                <?php
                                    $computedStatus = $t['computed_status'] ?? $t['status'];
                                    $badgeClass = 'bg-secondary';
                                    if ($computedStatus === 'Active') $badgeClass = 'bg-success';
                                    elseif ($computedStatus === 'Upcoming') $badgeClass = 'bg-info text-dark';
                                    elseif ($computedStatus === 'Completed') $badgeClass = 'bg-primary';
                                    elseif ($computedStatus === 'Draft') $badgeClass = 'bg-warning text-dark';
                                    elseif ($computedStatus === 'Archived') $badgeClass = 'bg-dark';

                                    $eligible = (int)($t['eligible_students_count'] ?? 0);
                                    $attended = (int)($t['attended_students_count'] ?? 0);
                                    $pct = (float)($t['attendance_percentage'] ?? 0);
                                    $tLink = $protocol . '://' . $host . $basePath . '/trainer/attendance.php?token=' . urlencode($t['trainer_access_token'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($t['thumbnail_url'])): ?>
                                                <img src="<?php echo esc($t['thumbnail_url']); ?>" alt="" class="rounded border" style="width: 44px; height: 44px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 44px; height: 44px; font-size: 1rem;">
                                                    <i class="fa-solid fa-chalkboard"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <a href="training-view.php?id=<?php echo $t['training_id']; ?>" class="fw-bold text-dark text-decoration-none">
                                                    <?php echo esc($t['title']); ?>
                                                </a>
                                                <?php if (!empty($t['trainer_name'])): ?>
                                                    <div class="text-muted small" style="font-size: 0.75rem;">
                                                        <i class="fa-regular fa-user me-1"></i><?php echo esc($t['trainer_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="small mt-1" style="max-width: 280px;">
                                                    <span class="badge bg-light text-dark border" style="font-size: 0.68rem;"><i class="fa-solid fa-<?php echo (($t['event_type'] ?? 'Online') === 'Offline') ? 'location-dot' : 'wifi'; ?> me-1"></i><?php echo esc($t['event_type'] ?? 'Online'); ?></span>
                                                    <?php if (($t['event_type'] ?? 'Online') === 'Online' && !empty($t['training_url'])): ?>
                                                        <a href="<?php echo esc($t['training_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-primary text-decoration-none ms-1" style="font-size: 0.72rem;">Open link</a>
                                                    <?php elseif (!empty($t['venue_location'])): ?>
                                                        <span class="text-muted ms-1" style="font-size: 0.72rem;"><?php echo esc($t['venue_location']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($t['dept_codes'])): ?>
                                            <?php 
                                                $codes = explode(', ', $t['dept_codes']);
                                                foreach ($codes as $c): 
                                            ?>
                                                <span class="badge bg-light text-dark border me-1 mb-1" style="font-size: 0.7rem;"><?php echo esc($c); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark" style="font-size: 0.78rem;">
                                            <?php echo date('M d, Y h:i A', strtotime($t['start_date_time'])); ?>
                                        </div>
                                        <div class="small text-muted" style="font-size: 0.72rem;">
                                            to <?php echo date('M d, Y h:i A', strtotime($t['end_date_time'])); ?>
                                        </div>
                                        <span class="badge bg-light text-secondary border mt-1" style="font-size: 0.68rem;" title="Attendance is marked manually by the trainer">
                                            <i class="fa-solid fa-user-check me-1"></i>Trainer marked
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.72rem;">
                                            <?php echo esc($computedStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark"><?php echo $attended; ?></span>
                                        <span class="text-muted small">/ <?php echo $eligible; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2" style="min-width: 100px;">
                                            <div class="progress flex-grow-1" style="height: 6px;">
                                                <div class="progress-bar <?php echo $pct >= 75 ? 'bg-success' : ($pct >= 40 ? 'bg-primary' : 'bg-warning'); ?>" 
                                                     role="progressbar" style="width: <?php echo $pct; ?>%;" 
                                                     aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <span class="small fw-semibold text-muted" style="font-size: 0.75rem;"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small text-dark fw-medium" style="font-size: 0.78rem;">
                                            <?php echo esc($t['creator_username'] ?? 'Admin'); ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.7rem;">
                                            <?php echo date('M d, Y', strtotime($t['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end align-items-center gap-1">
                                            <a href="training-view.php?id=<?php echo $t['training_id']; ?>" class="btn btn-sm btn-outline-primary fw-medium px-2 shadow-sm" title="Mark Attendance & View Roster">
                                                <i class="fa-solid fa-clipboard-user me-1 text-primary"></i> Attendance
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit Training Details" onclick="openEditTrainingModal(<?php echo $t['training_id']; ?>)">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="viewport" data-bs-popper-config='{"strategy":"fixed"}' title="More Actions">
                                                    <span class="visually-hidden">Toggle Dropdown</span>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" style="z-index: 1060; min-width: 220px;">
                                                    <li>
                                                        <a class="dropdown-item fw-semibold py-2" href="training-view.php?id=<?php echo $t['training_id']; ?>">
                                                            <i class="fa-solid fa-clipboard-check me-2 text-primary"></i> Attendance Dashboard
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item py-2" type="button" onclick="copyDirectTrainerLink('<?php echo esc(addslashes($tLink)); ?>')">
                                                            <i class="fa-solid fa-link me-2 text-success"></i> Copy Trainer Link
                                                        </button>
                                                    </li>
                                                    <?php if (($t['event_type'] ?? 'Online') === 'Online' && !empty($t['training_url'])): ?>
                                                        <li>
                                                            <a class="dropdown-item py-2" href="<?php echo esc($t['training_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                                <i class="fa-solid fa-arrow-up-right-from-square me-2 text-info"></i> Open Training Link
                                                            </a>
                                                        </li>
                                                    <?php elseif (!empty($t['venue_location'])): ?>
                                                        <li><span class="dropdown-item-text text-muted py-2"><i class="fa-solid fa-location-dot me-2 text-warning"></i><?php echo esc($t['venue_location']); ?></span></li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider my-1"></li>
                                                    <li>
                                                        <button class="dropdown-item py-2" type="button" onclick="duplicateTraining(<?php echo $t['training_id']; ?>, '<?php echo esc(addslashes($t['title'])); ?>')">
                                                            <i class="fa-regular fa-copy me-2 text-secondary"></i> Duplicate Program
                                                        </button>
                                                    </li>
                                                    <?php if ($computedStatus !== 'Archived'): ?>
                                                        <li>
                                                            <button class="dropdown-item py-2" type="button" onclick="archiveTraining(<?php echo $t['training_id']; ?>, '<?php echo esc(addslashes($t['title'])); ?>')">
                                                                <i class="fa-solid fa-box-archive me-2 text-warning"></i> Archive Program
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <button class="dropdown-item py-2 text-danger" type="button" onclick="deleteTraining(<?php echo $t['training_id']; ?>, '<?php echo esc(addslashes($t['title'])); ?>')">
                                                            <i class="fa-solid fa-trash-can me-2"></i> Delete Program
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center p-3">
                    <div class="small text-muted">
                        Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong> (Total: <?php echo $totalItems; ?> trainings)
                    </div>
                    <nav aria-label="Trainings pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"><i class="fa-solid fa-chevron-left"></i></a>
                            </li>
                            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"><i class="fa-solid fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Create / Edit Training -->
<div class="modal fade" id="trainingModal" tabindex="-1" aria-labelledby="trainingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form id="trainingForm" onsubmit="handleTrainingSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?php echo esc(getCsrfToken()); ?>">
                <input type="hidden" name="training_id" id="modalTrainingId" value="0">
                <input type="hidden" name="action" id="modalAction" value="create_training">

                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold" id="trainingModalLabel">Create New Training Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div id="modalAlert" class="alert alert-danger d-none mb-3"></div>

                    <!-- Row 1: Title -->
                    <div class="mb-3">
                        <label for="inputTitle" class="form-label small fw-bold">Training Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="inputTitle" name="title" required placeholder="e.g. Full Stack Web Development Bootcamp or TCS NQT Mock Test Session">
                    </div>

                    <!-- Row 2: Trainer and Status -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label for="inputTrainer" class="form-label small fw-bold">Trainer / Resource Person / Organizer</label>
                            <input type="text" class="form-control" id="inputTrainer" name="trainer_name" placeholder="e.g. Dr. John Doe, Senior Architect at TCS">
                        </div>
                        <div class="col-md-5">
                            <label for="selectStatus" class="form-label small fw-bold">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="selectStatus" name="status">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Active">Active (Live Now)</option>
                                <option value="Draft">Draft (Hidden from students)</option>
                                <option value="Completed">Completed</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 3: Event Type -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label for="selectEventType" class="form-label small fw-bold">Event Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="selectEventType" name="event_type" onchange="updateEventTypeFields()">
                                <option value="Online">Online</option>
                                <option value="Offline">Offline</option>
                            </select>
                        </div>
                        <div class="col-md-7" id="venueFieldWrapper">
                            <label for="inputVenue" class="form-label small fw-bold">Venue / Location <span class="text-danger" id="venueRequiredMark">*</span></label>
                            <input type="text" class="form-control" id="inputVenue" name="venue_location" maxlength="500" placeholder="e.g. Seminar Hall 2, MAMCET Campus">
                        </div>
                    </div>

                    <!-- Row 4: External URL -->
                    <div class="mb-3">
                        <label for="inputUrl" class="form-label small fw-bold">Online Training URL <span class="text-danger" id="urlRequiredMark">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fa-solid fa-link"></i></span>
                            <input type="url" class="form-control" id="inputUrl" name="training_url" placeholder="https://meet.google.com/xyz or https://zoom.us/j/... or LMS URL">
                        </div>
                        <small class="text-muted" id="urlHelpText">Students can open this link. Required for online events.</small>
                    </div>

                    <!-- Row 5: Eligible Departments Multi-select -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold mb-0">Eligible Departments <span class="text-danger">*</span></label>
                            <div>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none me-2" onclick="toggleAllDepts(true)">Select All</button>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-muted" onclick="toggleAllDepts(false)">Deselect All</button>
                            </div>
                        </div>
                        <div class="border rounded-3 p-3 bg-light" style="max-height: 140px; overflow-y: auto;">
                            <div class="row g-2" id="deptCheckboxesContainer">
                                <?php foreach ($departments as $d): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input dept-checkbox" type="checkbox" name="departments[]" value="<?php echo $d['dept_id']; ?>" id="dept_<?php echo $d['dept_id']; ?>">
                                            <label class="form-check-label small" for="dept_<?php echo $d['dept_id']; ?>">
                                                <strong><?php echo esc($d['dept_code']); ?></strong> - <?php echo esc($d['dept_name']); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="text-muted">Only students belonging to selected departments will see and have access to this training.</small>
                    </div>

                    <!-- Row 6: Dates & Times -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="inputStartDateTime" class="form-label small fw-bold">Start Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="inputStartDateTime" name="start_date_time" required>
                        </div>
                        <div class="col-md-6">
                            <label for="inputEndDateTime" class="form-label small fw-bold">End Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="inputEndDateTime" name="end_date_time" required>
                        </div>
                    </div>

                    <!-- Row 7: Attendance rule and thumbnail -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="inputThumbnailUrl" class="form-label small fw-bold">Thumbnail / Banner Image URL (Optional)</label>
                            <input type="url" class="form-control" id="inputThumbnailUrl" name="thumbnail_url" placeholder="https://example.com/image.jpg">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="small text-muted border rounded-3 bg-light p-2 w-100">
                                <i class="fa-solid fa-circle-info text-primary me-1"></i>Attendance is marked manually by the assigned trainer. Opening the event link never marks a student present.
                            </div>
                        </div>
                    </div>

                    <!-- Row 7: Description -->
                    <div class="mb-2">
                        <label for="inputDescription" class="form-label small fw-bold">Description / Instructions</label>
                        <textarea class="form-control" id="inputDescription" name="description" rows="3" placeholder="Provide syllabus, prerequisites, meeting passcode, instructions, or agenda for students..."></textarea>
                    </div>
                </div>

                <div class="modal-footer border-top py-2 px-4">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveTrainingBtn">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Training
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?php echo esc(getCsrfToken()); ?>';

function openCreateTrainingModal() {
    document.getElementById('trainingForm').reset();
    document.getElementById('modalTrainingId').value = '0';
    document.getElementById('modalAction').value = 'create_training';
    document.getElementById('trainingModalLabel').innerText = 'Create New Training Program';
    document.getElementById('modalAlert').classList.add('d-none');
    document.getElementById('selectEventType').value = 'Online';
    document.getElementById('inputVenue').value = '';
    updateEventTypeFields();
    toggleAllDepts(false);

    // Default start time to current hour rounded up, end time +2 hours
    const now = new Date();
    now.setMinutes(0, 0, 0);
    now.setHours(now.getHours() + 1);
    const end = new Date(now.getTime() + 2 * 60 * 60 * 1000);

    document.getElementById('inputStartDateTime').value = formatDateTimeLocal(now);
    document.getElementById('inputEndDateTime').value = formatDateTimeLocal(end);

    new bootstrap.Modal(document.getElementById('trainingModal')).show();
}

function openEditTrainingModal(trainingId) {
    document.getElementById('trainingForm').reset();
    document.getElementById('modalAlert').classList.add('d-none');

    $.ajax({
        url: '../api/training-api.php',
        type: 'GET',
        data: { action: 'get_training', training_id: trainingId },
        dataType: 'json',
        success: function(res) {
            if (!res.success || !res.training) {
                Swal.fire('Error', res.message || 'Could not load training details.', 'error');
                return;
            }

            const t = res.training;
            document.getElementById('modalTrainingId').value = t.training_id;
            document.getElementById('modalAction').value = 'update_training';
            document.getElementById('trainingModalLabel').innerText = 'Edit Training: ' + t.title;

            document.getElementById('inputTitle').value = t.title || '';
            document.getElementById('inputTrainer').value = t.trainer_name || '';
            document.getElementById('selectStatus').value = t.status || 'Upcoming';
            document.getElementById('selectEventType').value = t.event_type || 'Online';
            document.getElementById('inputVenue').value = t.venue_location || '';
            document.getElementById('inputUrl').value = t.training_url || '';
            document.getElementById('inputThumbnailUrl').value = t.thumbnail_url || '';
            document.getElementById('inputDescription').value = t.description || '';

            updateEventTypeFields();

            if (t.start_date_time) {
                document.getElementById('inputStartDateTime').value = formatDateTimeLocal(new Date(t.start_date_time));
            }
            if (t.end_date_time) {
                document.getElementById('inputEndDateTime').value = formatDateTimeLocal(new Date(t.end_date_time));
            }

            // Set checkboxes
            toggleAllDepts(false);
            const deptIds = t.department_ids || [];
            deptIds.forEach(dId => {
                const cb = document.getElementById('dept_' + dId);
                if (cb) cb.checked = true;
            });

            new bootstrap.Modal(document.getElementById('trainingModal')).show();
        },
        error: function() {
            Swal.fire('Error', 'Server connection failure.', 'error');
        }
    });
}

function toggleAllDepts(checked) {
    document.querySelectorAll('.dept-checkbox').forEach(cb => cb.checked = checked);
}

function updateEventTypeFields() {
    const isOffline = document.getElementById('selectEventType').value === 'Offline';
    const urlInput = document.getElementById('inputUrl');
    const venueInput = document.getElementById('inputVenue');
    document.getElementById('urlRequiredMark').classList.toggle('d-none', isOffline);
    document.getElementById('venueRequiredMark').classList.toggle('d-none', !isOffline);
    urlInput.required = !isOffline;
    venueInput.required = isOffline;
    document.getElementById('urlHelpText').innerText = isOffline
        ? 'Optional for offline events. Leave blank when there is no online link.'
        : 'Students can open this link. Required for online events.';
}

function formatDateTimeLocal(d) {
    const pad = n => n < 10 ? '0' + n : n;
    return d.getFullYear() + '-' +
        pad(d.getMonth() + 1) + '-' +
        pad(d.getDate()) + 'T' +
        pad(d.getHours()) + ':' +
        pad(d.getMinutes());
}

function handleTrainingSubmit(e) {
    e.preventDefault();
    const alertBox = document.getElementById('modalAlert');
    alertBox.classList.add('d-none');
    alertBox.innerText = '';

    // Validation
    const title = document.getElementById('inputTitle').value.trim();
    const eventType = document.getElementById('selectEventType').value;
    const url = document.getElementById('inputUrl').value.trim();
    const venue = document.getElementById('inputVenue').value.trim();
    const startStr = document.getElementById('inputStartDateTime').value;
    const endStr = document.getElementById('inputEndDateTime').value;

    const checkedDepts = Array.from(document.querySelectorAll('.dept-checkbox:checked')).map(cb => cb.value);

    if (!title) {
        alertBox.innerText = 'Training title is required.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (eventType === 'Online' && !url) {
        alertBox.innerText = 'An online training URL is required for online events.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (eventType === 'Offline' && !venue) {
        alertBox.innerText = 'A venue or location is required for offline events.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (checkedDepts.length === 0) {
        alertBox.innerText = 'At least one eligible department must be selected.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (!startStr || !endStr) {
        alertBox.innerText = 'Start and End dates/times are required.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (new Date(endStr) <= new Date(startStr)) {
        alertBox.innerText = 'End date/time must be strictly later than Start date/time.';
        alertBox.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('saveTrainingBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    const formData = new FormData(document.getElementById('trainingForm'));

    $.ajax({
        url: '../api/training-api.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Training';

            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('trainingModal')).hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: res.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });
            } else {
                alertBox.innerText = res.message || 'Validation failed. Please check inputs.';
                alertBox.classList.remove('d-none');
            }
        },
        error: function(xhr) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Training';
            let msg = 'Server error occurred.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            alertBox.innerText = msg;
            alertBox.classList.remove('d-none');
        }
    });
}

function duplicateTraining(id, title) {
    Swal.fire({
        title: 'Duplicate Program?',
        text: `Create a copy of "${title}"? The new program will be created in Draft status.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Duplicate',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/training-api.php',
                type: 'POST',
                data: { action: 'duplicate_training', training_id: id, csrf_token: CSRF_TOKEN },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Duplicated', res.message, 'success').then(() => window.location.reload());
                    } else {
                        Swal.fire('Error', res.message || 'Failed to duplicate.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Server connection failure.', 'error');
                }
            });
        }
    });
}

function archiveTraining(id, title) {
    Swal.fire({
        title: 'Archive Program?',
        text: `Archive "${title}"? It will no longer be visible to students in their active catalog.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Archive',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/training-api.php',
                type: 'POST',
                data: { action: 'archive_training', training_id: id, csrf_token: CSRF_TOKEN },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Archived', res.message, 'success').then(() => window.location.reload());
                    } else {
                        Swal.fire('Error', res.message || 'Failed to archive.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Server connection failure.', 'error');
                }
            });
        }
    });
}

function deleteTraining(id, title) {
    Swal.fire({
        title: 'Delete Training Program?',
        text: `Are you sure you want to permanently delete "${title}"? All associated attendance logs will be removed. This action cannot be undone.`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/training-api.php',
                type: 'POST',
                data: { action: 'delete_training', training_id: id, csrf_token: CSRF_TOKEN },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Deleted', res.message, 'success').then(() => window.location.reload());
                    } else {
                        Swal.fire('Error', res.message || 'Failed to delete.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Server connection failure.', 'error');
                }
            });
        }
    });
}

function copyDirectTrainerLink(url) {
    if (!url) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            if (window.Swal) {
                Swal.fire({
                    icon: 'success',
                    title: 'Link Copied',
                    text: 'Trainer attendance link copied to clipboard.',
                    timer: 1600,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            } else {
                alert('Trainer attendance link copied to clipboard.');
            }
        });
    } else {
        prompt('Copy this trainer attendance link:', url);
    }
}
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
