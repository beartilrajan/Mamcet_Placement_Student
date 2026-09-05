<?php
// MAMCET Placement & Learning Portal - Programme Batches Management (Senior UI/UX Redesign)

$pageTitle = 'Programme Batches';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$activeSessionId = getActiveAcademicSessionId();
$stmtSess = $db->prepare("SELECT session_id, session_name, start_year, end_year FROM academic_sessions WHERE session_id = ?");
$stmtSess->execute([$activeSessionId]);
$currentSession = $stmtSess->fetch();
if (!$currentSession || empty($currentSession['start_year'])) {
    $stmtActSess = $db->query("SELECT session_id, session_name, start_year, end_year FROM academic_sessions WHERE is_active = 1 LIMIT 1");
    $currentSession = $stmtActSess->fetch();
    if (!$currentSession) {
        $currentSession = $db->query("SELECT session_id, session_name, start_year, end_year FROM academic_sessions ORDER BY start_year DESC LIMIT 1")->fetch();
    }
}
$sessStartYear = (int)($currentSession['start_year'] ?? 2026);
$sessEndYear = (int)($currentSession['end_year'] ?? 2027);
$activeSessionName = $currentSession['session_name'] ?? '2026–2027';

// Handle Create / Edit Batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $batchName = trim($_POST['batch_name'] ?? '');
        $admissionYear = (int)($_POST['admission_year'] ?? 0);
        $graduationYear = (int)($_POST['graduation_year'] ?? 0);
        $programmeId = (int)($_POST['programme_id'] ?? 0);
        $programmeDuration = (int)($_POST['programme_duration'] ?? 4);
        $currentYearOfStudy = $_POST['current_year_of_study'] ?? 'Final Year';
        $status = $_POST['status'] ?? 'active';
        $description = trim($_POST['description'] ?? '');
        $batchId = (int)($_POST['batch_id'] ?? 0);
        
        if (empty($batchName) || $admissionYear <= 0 || $graduationYear <= 0 || $programmeId <= 0) {
            $error = 'Batch Name, Programme, Admission Year, and Graduation Year are required.';
        } elseif ($graduationYear <= $admissionYear) {
            $error = 'Graduation year must be greater than admission year.';
        } else {
            try {
                $db->beginTransaction();
                
                if ($action === 'create') {
                    // Check duplicate name
                    $chk = $db->prepare("SELECT batch_id FROM batches WHERE batch_name = ?");
                    $chk->execute([$batchName]);
                    if ($chk->fetch()) {
                        $error = "A cohort batch named '{$batchName}' already exists.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO batches (batch_name, admission_year, graduation_year, programme_id, programme_duration, current_year_of_study, status, description) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$batchName, $admissionYear, $graduationYear, $programmeId, $programmeDuration, $currentYearOfStudy, $status, $description]);
                        $newBatchId = $db->lastInsertId();
                        
                        // Map to active academic session
                        $stmtSession = $db->prepare("
                            INSERT INTO batch_academic_sessions (batch_id, session_id, year_of_study) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE year_of_study = VALUES(year_of_study)
                        ");
                        $stmtSession->execute([$newBatchId, $activeSessionId, $currentYearOfStudy]);
                        
                        $success = "Programme batch '{$batchName}' created successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Create Batch', "Created batch $batchName");
                    }
                } else { // edit
                    $chk = $db->prepare("SELECT batch_id FROM batches WHERE batch_name = ? AND batch_id != ?");
                    $chk->execute([$batchName, $batchId]);
                    if ($chk->fetch()) {
                        $error = "A cohort batch named '{$batchName}' already exists on another record.";
                    } else {
                        $stmt = $db->prepare("
                            UPDATE batches 
                            SET batch_name = ?, admission_year = ?, graduation_year = ?, programme_id = ?, programme_duration = ?, current_year_of_study = ?, status = ?, description = ? 
                            WHERE batch_id = ?
                        ");
                        $stmt->execute([$batchName, $admissionYear, $graduationYear, $programmeId, $programmeDuration, $currentYearOfStudy, $status, $description, $batchId]);
                        
                        $stmtSession = $db->prepare("
                            INSERT INTO batch_academic_sessions (batch_id, session_id, year_of_study) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE year_of_study = VALUES(year_of_study)
                        ");
                        $stmtSession->execute([$batchId, $activeSessionId, $currentYearOfStudy]);
                        
                        $success = "Programme batch '{$batchName}' updated successfully.";
                        logActivity($db, $_SESSION['user_id'], 'Update Batch', "Updated batch $batchName");
                    }
                }
                
                if (empty($error)) {
                    $db->commit();
                    syncBatchAcademicSessions($db);
                } else {
                    $db->rollBack();
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Sync batch academic session mappings
syncBatchAcademicSessions($db);

// Fetch all programmes for selector
$programmes = $db->query("SELECT * FROM programmes ORDER BY programme_name")->fetchAll();

// Fetch batches joined with programme name and active session mapping + student headcount
$stmt = $db->prepare("
    SELECT b.*, pr.programme_name, bas.year_of_study AS active_session_year_of_study,
           (SELECT COUNT(*) FROM students s WHERE s.batch_id = b.batch_id) AS student_count
    FROM batches b 
    JOIN programmes pr ON b.programme_id = pr.programme_id 
    LEFT JOIN batch_academic_sessions bas ON b.batch_id = bas.batch_id AND bas.session_id = ?
    ORDER BY b.graduation_year DESC
");
$stmt->execute([$activeSessionId]);
$batches = $stmt->fetchAll();

// KPI Stats
$totalBatches = count($batches);
$finalYearCount = 0;
$undergradCount = 0;
$graduatedCount = 0;
$totalStudentsInBatches = 0;

$processedBatches = [];
foreach ($batches as $b) {
    $adm = (int)$b['admission_year'];
    $grad = (int)$b['graduation_year'];
    $dur = (int)($b['programme_duration'] ?: 4);
    $totalStudentsInBatches += (int)$b['student_count'];

    $standingCategory = 'other';
    $yearBadge = '';

    if ($sessStartYear > 0 && $sessEndYear > 0) {
        if ($adm > $sessStartYear) {
            $standingCategory = 'upcoming';
            $yearBadge = '<span class="badge bg-light text-muted border"><i class="fa-regular fa-clock me-1"></i> Upcoming (Admitted ' . $adm . ')</span>';
        } elseif ($grad <= $sessStartYear) {
            $standingCategory = 'graduated';
            $graduatedCount++;
            $yearBadge = '<span class="badge bg-secondary-subtle text-secondary border"><i class="fa-solid fa-user-graduate me-1"></i> Graduated (' . $grad . ')</span>';
        } else {
            $yearNumber = ($sessStartYear - $adm) + (4 - $dur) + 1;
            if ($grad === $sessEndYear || $yearNumber >= 4) {
                $standingCategory = 'final_year';
                $finalYearCount++;
                $yearBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold"><i class="fa-solid fa-award me-1"></i> Final Year (Drive Eligible)</span>';
            } elseif ($yearNumber === 3) {
                $standingCategory = 'third_year';
                $undergradCount++;
                $yearBadge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-bold"><i class="fa-solid fa-book-open me-1"></i> Third Year</span>';
            } elseif ($yearNumber === 2) {
                $standingCategory = 'second_year';
                $undergradCount++;
                $yearBadge = '<span class="badge bg-info-subtle text-info border border-info-subtle fw-bold"><i class="fa-solid fa-graduation-cap me-1"></i> Second Year</span>';
            } else {
                $standingCategory = 'first_year';
                $undergradCount++;
                $yearBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold"><i class="fa-solid fa-user me-1"></i> First Year</span>';
            }
        }
    } else {
        $yearBadge = '<span class="text-muted">' . esc($b['active_session_year_of_study'] ?? $b['current_year_of_study'] ?? 'Not Configured') . '</span>';
    }

    $b['standing_category'] = $standingCategory;
    $b['year_badge_html'] = $yearBadge;
    $processedBatches[] = $b;
}

// Active Filter Tab
$activeTab = $_GET['tab'] ?? 'all';
$filteredBatches = [];
foreach ($processedBatches as $pb) {
    if ($activeTab === 'final' && $pb['standing_category'] !== 'final_year') continue;
    if ($activeTab === 'prefinal' && $pb['standing_category'] !== 'third_year') continue;
    if ($activeTab === 'junior' && !in_array($pb['standing_category'], ['first_year', 'second_year'])) continue;
    if ($activeTab === 'alumni' && $pb['standing_category'] !== 'graduated') continue;
    $filteredBatches[] = $pb;
}
?>

<style>
/* Segmented Tab Bar */
.segmented-tab-bar {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    gap: 3px;
    border: 1px solid #e2e8f0;
    max-width: 100%;
    overflow-x: auto;
}
.segmented-tab-item {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}
.segmented-tab-item:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.7);
    text-decoration: none !important;
}
.segmented-tab-item.active {
    background: #ffffff;
    color: #2563eb;
    font-weight: 700;
    border-color: #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    text-decoration: none !important;
}
.segmented-pill-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    background: #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1.2;
}
.segmented-tab-item.active .segmented-pill-badge {
    background: #eff6ff;
    color: #2563eb;
}

.timeline-pill {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px 6px;
    font-size: 0.74rem;
    font-family: monospace;
    color: #334155;
}
</style>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container py-3">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-circle-check me-1"></i> <?php echo esc($success); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h4 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-user-graduate text-primary me-2"></i> Programme Batches
                    </h4>
                    <span class="badge bg-light text-primary border px-2 py-1 small fw-semibold">
                        <i class="fa-solid fa-calendar-check me-1"></i> Mapped to Session: <?php echo esc($activeSessionName); ?>
                    </span>
                </div>
                <p class="text-muted small mb-0 mt-1">Manage academic cohort lifecycles, study year progression, and campus placement eligibility scopes.</p>
            </div>
            <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#batchModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> Create Batch
            </button>
        </div>

        <!-- Executive KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="batch-kpi-bar">
            <div class="batch-kpi-item">
                <div class="batch-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-users-viewfinder"></i>
                </div>
                <div>
                    <div class="batch-kpi-label">Total Cohorts</div>
                    <div class="batch-kpi-value text-primary"><?php echo $totalBatches; ?> Batches</div>
                </div>
            </div>

            <div class="batch-kpi-item">
                <div class="batch-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <div class="batch-kpi-label">Placement Final Year</div>
                    <div class="batch-kpi-value text-success"><?php echo $finalYearCount; ?> Active Batch</div>
                </div>
            </div>

            <div class="batch-kpi-item">
                <div class="batch-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-book-open-reader"></i>
                </div>
                <div>
                    <div class="batch-kpi-label">Undergraduate (1st-3rd)</div>
                    <div class="batch-kpi-value text-info"><?php echo $undergradCount; ?> Cohorts</div>
                </div>
            </div>

            <div class="batch-kpi-item">
                <div class="batch-kpi-icon bg-secondary-subtle text-secondary">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <div class="batch-kpi-label">Alumni / Graduated</div>
                    <div class="batch-kpi-value text-secondary"><?php echo $graduatedCount; ?> Batches</div>
                </div>
            </div>
        </div>

        <!-- Segmented Filter Tabs -->
        <div class="mb-3">
            <div class="segmented-tab-bar">
                <a href="batches.php?tab=all" class="segmented-tab-item <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-layer-group"></i> All Cohorts
                    <span class="segmented-pill-badge"><?php echo $totalBatches; ?></span>
                </a>
                <a href="batches.php?tab=final" class="segmented-tab-item <?php echo $activeTab === 'final' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-award text-success"></i> 🎯 Final Year Placement
                    <span class="segmented-pill-badge"><?php echo $finalYearCount; ?></span>
                </a>
                <a href="batches.php?tab=prefinal" class="segmented-tab-item <?php echo $activeTab === 'prefinal' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-open text-warning"></i> Pre-Final (3rd Year)
                    <span class="segmented-pill-badge"><?php echo count(array_filter($processedBatches, fn($b) => $b['standing_category'] === 'third_year')); ?></span>
                </a>
                <a href="batches.php?tab=junior" class="segmented-tab-item <?php echo $activeTab === 'junior' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-graduation-cap text-info"></i> Junior (1st & 2nd Year)
                    <span class="segmented-pill-badge"><?php echo count(array_filter($processedBatches, fn($b) => in_array($b['standing_category'], ['first_year', 'second_year']))); ?></span>
                </a>
                <a href="batches.php?tab=alumni" class="segmented-tab-item <?php echo $activeTab === 'alumni' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-graduate text-secondary"></i> Alumni / Graduated
                    <span class="segmented-pill-badge"><?php echo $graduatedCount; ?></span>
                </a>
            </div>
        </div>

        <!-- Batches Data Table Card -->
        <div class="mamcet-card border-0 shadow-sm" style="border-radius: 10px; overflow:hidden;">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-graduation-cap text-primary me-2"></i> Cohort Standing for Session: <?php echo esc($activeSessionName); ?>
                </h6>
                <span class="badge bg-light text-secondary border small"><?php echo count($filteredBatches); ?> Batches</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ann-table" id="batchesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Batch Name</th>
                                <th>Programme</th>
                                <th>Duration</th>
                                <th>Timeline (Adm → Grad)</th>
                                <th>Status in Session (<?php echo esc($activeSessionName); ?>)</th>
                                <th>Enrolled Students</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filteredBatches)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted small">No batches found matching selected filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filteredBatches as $batch): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="ann-icon-badge bg-primary-subtle text-primary">
                                                    <i class="fa-solid fa-graduation-cap"></i>
                                                </div>
                                                <div>
                                                    <strong class="text-dark d-block" style="font-size:0.86rem;"><?php echo esc($batch['batch_name']); ?></strong>
                                                    <small class="text-muted" style="font-size:0.72rem;">Batch ID: #<?php echo $batch['batch_id']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-primary border px-2 py-1 font-monospace" style="font-size: 0.76rem;">
                                                <?php echo esc($batch['programme_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.74rem;">
                                                <?php echo esc($batch['programme_duration']); ?> Years (Full-Time)
                                            </span>
                                        </td>
                                        <td>
                                            <span class="timeline-pill">
                                                <?php echo esc($batch['admission_year']); ?> <i class="fa-solid fa-arrow-right text-muted mx-1" style="font-size:0.65rem;"></i> <?php echo esc($batch['graduation_year']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $batch['year_badge_html']; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size:0.76rem;">
                                                <i class="fa-solid fa-users text-primary me-1"></i> <?php echo (int)$batch['student_count']; ?> Students
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($batch['status'] === 'active'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                    ● Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 0.72rem;">
                                                    ● Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button class="btn btn-sm btn-light border" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($batch)); ?>)" title="Edit Batch Details">
                                                    <i class="fa-solid fa-pencil text-secondary me-1"></i> Edit
                                                </button>
                                                <a href="students.php?batch=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-light border" title="View Students in Batch">
                                                    <i class="fa-solid fa-user-group text-primary"></i>
                                                </a>
                                            </div>
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
</div>

<!-- CREATE / EDIT BATCH MODAL -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-labelledby="batchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-dark border-0 shadow">
            <form action="batches.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="batch_id" id="form_batch_id" value="0">
                
                <div class="modal-header py-2 px-3 bg-light border-bottom">
                    <h6 class="modal-title fw-bold" id="batchModalLabel">
                        <i class="fa-solid fa-graduation-cap text-primary me-2"></i> Create Programme Batch
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Batch Label Name <span class="text-danger">*</span></label>
                        <input type="text" name="batch_name" id="modal_batch_name" class="form-control form-control-sm" placeholder="e.g. 2024–2028" required>
                        <div class="form-text small" style="font-size:0.72rem;">Academic cohort span (auto-generated from admission & graduation years).</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">Associated Programme Degree <span class="text-danger">*</span></label>
                        <select name="programme_id" id="modal_programme_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Programme --</option>
                            <?php foreach ($programmes as $pr): ?>
                                <option value="<?php echo $pr['programme_id']; ?>"><?php echo esc($pr['programme_name']); ?> (<?php echo esc($pr['programme_code']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Admission Year <span class="text-danger">*</span></label>
                            <input type="number" name="admission_year" id="modal_admission_year" class="form-control form-control-sm" placeholder="2024" required oninput="calcGraduationYear()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Duration (Years)</label>
                            <input type="number" name="programme_duration" id="modal_programme_duration" class="form-control form-control-sm" value="4" min="1" max="6" required oninput="calcGraduationYear()">
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Graduation Year <span class="text-danger">*</span></label>
                            <input type="number" name="graduation_year" id="modal_graduation_year" class="form-control form-control-sm" placeholder="2028" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Year of Study (Session)</label>
                            <select name="current_year_of_study" id="modal_current_year_of_study" class="form-select form-select-sm">
                                <option value="First Year">First Year</option>
                                <option value="Second Year">Second Year</option>
                                <option value="Third Year">Third Year</option>
                                <option value="Final Year" selected>Final Year</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Status</label>
                            <select name="status" id="modal_status" class="form-select form-select-sm">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Description / Notes</label>
                            <input type="text" name="description" id="modal_description" class="form-control form-control-sm" placeholder="Optional notes...">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer py-2 px-3 bg-light border-top">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitForm">
                        <i class="fa-solid fa-plus me-1"></i> Create Batch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function calcGraduationYear() {
    const adm = parseInt($('#modal_admission_year').val()) || 0;
    const dur = parseInt($('#modal_programme_duration').val()) || 4;
    if (adm > 0) {
        const grad = adm + dur;
        $('#modal_graduation_year').val(grad);
        if (!$('#modal_batch_name').val() || $('#form_action').val() === 'create') {
            $('#modal_batch_name').val(adm + '–' + grad);
        }
    }
}

function openCreateModal() {
    $('#batchModalLabel').html('<i class="fa-solid fa-graduation-cap text-primary me-2"></i> Create Programme Batch');
    $('#form_action').val('create');
    $('#form_batch_id').val('0');
    $('#modal_batch_name').val('');
    $('#modal_programme_id').val('');
    $('#modal_admission_year').val('<?php echo date('Y'); ?>');
    $('#modal_programme_duration').val('4');
    $('#modal_graduation_year').val('<?php echo date('Y') + 4; ?>');
    $('#modal_batch_name').val('<?php echo date('Y'); ?>–<?php echo date('Y') + 4; ?>');
    $('#modal_current_year_of_study').val('First Year');
    $('#modal_status').val('active');
    $('#modal_description').val('');
    $('#btnSubmitForm').html('<i class="fa-solid fa-plus me-1"></i> Create Batch');
}

function openEditModal(batch) {
    $('#batchModalLabel').html('<i class="fa-solid fa-pencil text-primary me-2"></i> Edit Programme Batch');
    $('#form_action').val('edit');
    $('#form_batch_id').val(batch.batch_id);
    $('#modal_batch_name').val(batch.batch_name);
    $('#modal_programme_id').val(batch.programme_id);
    $('#modal_admission_year').val(batch.admission_year);
    $('#modal_graduation_year').val(batch.graduation_year);
    $('#modal_programme_duration').val(batch.programme_duration);
    $('#modal_current_year_of_study').val(batch.active_session_year_of_study || batch.current_year_of_study);
    $('#modal_status').val(batch.status);
    $('#modal_description').val(batch.description || '');
    $('#btnSubmitForm').html('<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('batchModal'));
    modal.show();
}
</script>


