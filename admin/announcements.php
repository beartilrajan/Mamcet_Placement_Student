<?php
// MAMCET Placement & Learning Portal - Placement Announcements Management Page (Senior UI/UX Redesign)

$pageTitle = 'Placement Announcements';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$activeSessionId = getActiveAcademicSessionId();
$stmtSess = $db->prepare("SELECT session_id, session_name FROM academic_sessions WHERE session_id = ?");
$stmtSess->execute([$activeSessionId]);
$activeSession = $stmtSess->fetch();
$activeSessionName = $activeSession['session_name'] ?? '2024–2025';

// Handle Create / Edit / Delete Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    
    if ($action === 'create' || $action === 'edit') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['announcement_type'] ?? 'General';
        $companyName = trim($_POST['company_name'] ?? '');
        $jobRole = trim($_POST['job_role'] ?? '');
        $package = trim($_POST['package'] ?? '');
        $location = trim($_POST['job_location'] ?? '');
        $minCgpa = !empty($_POST['min_cgpa']) ? (float)$_POST['min_cgpa'] : 0.00;
        $maxArrears = !empty($_POST['max_standing_arrears']) ? (int)$_POST['max_standing_arrears'] : 99;
        $maxHistory = !empty($_POST['max_history_arrears']) ? (int)$_POST['max_history_arrears'] : 99;
        $willingnessReq = isset($_POST['placement_willingness_req']) ? 1 : 0;
        $googleFormUrl = trim($_POST['google_form_url'] ?? '');
        $googleSheetUrl = trim($_POST['google_sheet_url'] ?? '');
        $sheetVisible = isset($_POST['is_sheet_student_visible']) ? 1 : 0;
        $externalLink = trim($_POST['external_link'] ?? '');
        $expiryDate = $_POST['expiry_date'] ?? '';
        $priority = $_POST['priority'] ?? 'Medium';
        $status = $_POST['status'] ?? 'published';
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        
        $targetDepts = $_POST['departments'] ?? [];
        $targetBatches = $_POST['batches'] ?? [];
        
        if (empty($title) || empty($description) || empty($expiryDate) || empty($targetDepts) || empty($targetBatches)) {
            $error = 'Title, Description, Expiry Date, target Departments, and Batches are required.';
        } else {
            try {
                $db->beginTransaction();
                
                // Process File upload (Job description attachment) if exists
                $jdFilePath = '';
                if (isset($_FILES['jd_file']) && $_FILES['jd_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['jd_file'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/../assets/uploads/attachments/';
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        
                        $safeFilename = 'jd_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file['name']);
                        if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
                            $jdFilePath = 'assets/uploads/attachments/' . $safeFilename;
                        }
                    }
                }
                
                if ($action === 'create') {
                    $stmt = $db->prepare("
                        INSERT INTO announcements (
                            title, description, announcement_type, company_name, job_role, package, job_location, 
                            min_cgpa, max_standing_arrears, max_history_arrears, placement_willingness_req,
                            session_id, google_form_url, google_sheet_url, is_sheet_student_visible, jd_file_path, external_link, 
                            publish_date, expiry_date, priority, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title, $description, $type, $companyName, $jobRole, $package, $location,
                        $minCgpa, $maxArrears, $maxHistory, $willingnessReq,
                        $activeSessionId, $googleFormUrl, $googleSheetUrl, $sheetVisible, $jdFilePath, $externalLink,
                        $expiryDate, $priority, $status, $_SESSION['user_id']
                    ]);
                    $newAnnId = $db->lastInsertId();
                    $annIdForMapping = $newAnnId;
                } else {
                    $annIdForMapping = $announcementId;
                    
                    if (empty($jdFilePath)) {
                        $stmtJd = $db->prepare("SELECT jd_file_path FROM announcements WHERE announcement_id = ?");
                        $stmtJd->execute([$announcementId]);
                        $jdFilePath = $stmtJd->fetchColumn() ?: '';
                    }
                    
                    $stmt = $db->prepare("
                        UPDATE announcements 
                        SET title = ?, description = ?, announcement_type = ?, company_name = ?, job_role = ?, package = ?, job_location = ?, 
                            min_cgpa = ?, max_standing_arrears = ?, max_history_arrears = ?, placement_willingness_req = ?,
                            google_form_url = ?, google_sheet_url = ?, is_sheet_student_visible = ?, jd_file_path = ?, external_link = ?, 
                            expiry_date = ?, priority = ?, status = ?
                        WHERE announcement_id = ?
                    ");
                    $stmt->execute([
                        $title, $description, $type, $companyName, $jobRole, $package, $location,
                        $minCgpa, $maxArrears, $maxHistory, $willingnessReq,
                        $googleFormUrl, $googleSheetUrl, $sheetVisible, $jdFilePath, $externalLink,
                        $expiryDate, $priority, $status, $announcementId
                    ]);
                    
                    $db->prepare("DELETE FROM announcement_departments WHERE announcement_id = ?")->execute([$announcementId]);
                    $db->prepare("DELETE FROM announcement_batches WHERE announcement_id = ?")->execute([$announcementId]);
                }
                
                $stmtDept = $db->prepare("INSERT INTO announcement_departments (announcement_id, dept_id) VALUES (?, ?)");
                foreach ($targetDepts as $dId) {
                    $stmtDept->execute([$annIdForMapping, $dId]);
                }
                
                $stmtBatch = $db->prepare("INSERT INTO announcement_batches (announcement_id, batch_id) VALUES (?, ?)");
                foreach ($targetBatches as $bId) {
                    $stmtBatch->execute([$annIdForMapping, $bId]);
                }
                
                $db->commit();

                // Dispatch real-time portal notification to targeted students
                if ($status === 'published' && $annIdForMapping > 0) {
                    require_once(__DIR__ . '/../services/NotificationService.php');
                    NotificationService::notifyAnnouncementPublished($db, (int)$annIdForMapping, (int)$_SESSION['user_id']);
                }

                $success = 'Announcement ' . ($action === 'create' ? 'published' : 'updated') . ' successfully.';
                logActivity($db, $_SESSION['user_id'], 'Manage Announcement', "Published/Updated announcement $title");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        if ($announcementId > 0) {
            try {
                // Fetch announcement details for file cleanup and logging
                $stmtAnnInfo = $db->prepare("SELECT title, jd_file_path FROM announcements WHERE announcement_id = ?");
                $stmtAnnInfo->execute([$announcementId]);
                $annInfo = $stmtAnnInfo->fetch(PDO::FETCH_ASSOC);

                if (!$annInfo) {
                    $error = 'Announcement not found or already deleted.';
                } else {
                    $db->beginTransaction();

                    // 1. Delete associated mappings and reads
                    $db->prepare("DELETE FROM announcement_departments WHERE announcement_id = ?")->execute([$announcementId]);
                    $db->prepare("DELETE FROM announcement_batches WHERE announcement_id = ?")->execute([$announcementId]);
                    
                    try {
                        $db->prepare("DELETE FROM announcement_reads WHERE announcement_id = ?")->execute([$announcementId]);
                    } catch (\Throwable $t) {
                        // ignore if table does not exist
                    }

                    // 2. Delete the announcement record
                    $db->prepare("DELETE FROM announcements WHERE announcement_id = ?")->execute([$announcementId]);

                    $db->commit();

                    // 3. Remove attached JD file if exists on disk
                    if (!empty($annInfo['jd_file_path'])) {
                        $jdDiskFile = __DIR__ . '/../' . ltrim($annInfo['jd_file_path'], '/\\');
                        if (file_exists($jdDiskFile) && is_file($jdDiskFile)) {
                            @unlink($jdDiskFile);
                        }
                    }

                    $success = "Announcement '{$annInfo['title']}' deleted successfully.";
                    logActivity($db, $_SESSION['user_id'], 'Delete Announcement', "Deleted announcement ID $announcementId ({$annInfo['title']})");
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
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY graduation_year DESC")->fetchAll();

// Fetch announcements for active session
$stmtAnn = $db->prepare("
    SELECT a.*, 
           GROUP_CONCAT(DISTINCT d.dept_code SEPARATOR ', ') AS target_depts, 
           GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR ', ') AS target_batches
    FROM announcements a
    LEFT JOIN announcement_departments ad ON a.announcement_id = ad.announcement_id
    LEFT JOIN departments d ON ad.dept_id = d.dept_id
    LEFT JOIN announcement_batches ab ON a.announcement_id = ab.announcement_id
    LEFT JOIN batches b ON ab.batch_id = b.batch_id
    WHERE a.session_id = ?
    GROUP BY a.announcement_id
    ORDER BY a.publish_date DESC, a.announcement_id DESC
");
$stmtAnn->execute([$activeSessionId]);
$allAnnouncements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);

// Metrics computation
$today = strtotime('today');
$kpiTotal = count($allAnnouncements);
$kpiDrives = 0;
$kpiActive = 0;
$kpiExpiringSoon = 0;

foreach ($allAnnouncements as $ann) {
    if ($ann['announcement_type'] === 'Placement Drive') $kpiDrives++;
    $exp = !empty($ann['expiry_date']) ? strtotime($ann['expiry_date']) : 0;
    if ($exp >= $today) {
        $kpiActive++;
        if ($exp <= strtotime('+3 days', $today)) {
            $kpiExpiringSoon++;
        }
    }
}

// Active Tab Filter
$activeTab = $_GET['tab'] ?? 'all';
$displayedAnnouncements = [];

foreach ($allAnnouncements as $ann) {
    $exp = !empty($ann['expiry_date']) ? strtotime($ann['expiry_date']) : 0;
    $isExpired = $exp < $today;
    
    if ($activeTab === 'drives' && $ann['announcement_type'] !== 'Placement Drive') continue;
    if ($activeTab === 'notices' && !in_array($ann['announcement_type'], ['General', 'Circular', 'Deadline Reminder'])) continue;
    if ($activeTab === 'training' && !in_array($ann['announcement_type'], ['Training', 'Test Schedule', 'Interview Schedule'])) continue;
    if ($activeTab === 'active' && $isExpired) continue;
    if ($activeTab === 'expired' && !$isExpired) continue;
    
    $displayedAnnouncements[] = $ann;
}
?>

<style>
/* Segmented Tab Bar */
.segmented-tab-bar {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    gap: 2px;
    border: 1px solid #e2e8f0;
    max-width: 100%;
    overflow-x: auto;
}
.segmented-tab-item {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.segmented-tab-item:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.6);
}
.segmented-tab-item.active {
    background: #ffffff;
    color: #0b3d91;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.segmented-pill-badge {
    font-size: 0.7rem;
    padding: 1px 6px;
    border-radius: 10px;
    background: #e2e8f0;
    color: #475569;
}
.segmented-tab.active .segmented-pill-badge {
    background: #e0e7ff;
    color: #1e40af;
}

/* High-Density Card and Table */
.table-responsive {
    overflow-x: auto;
    overflow-y: visible !important;
}
.ann-table thead th {
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
}
.ann-table tbody td {
    padding: 10px 14px;
    font-size: 0.84rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}
.ann-table tbody tr:hover {
    background-color: #fbfcfe;
}
.ann-title-link {
    color: #1e293b;
    font-weight: 700;
    text-decoration: none;
    display: block;
}
.ann-title-link:hover {
    color: #0b3d91;
}

/* Priority Indicators */
.priority-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
}
.priority-dot-urgent { background-color: #ef4444; }
.priority-dot-high { background-color: #f59e0b; }
.priority-dot-medium { background-color: #3b82f6; }
.priority-dot-low { background-color: #94a3b8; }

/* DataTables Modern Styling */
.dataTables_wrapper {
    padding: 12px 16px;
}
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin-bottom: 12px;
    font-size: 0.82rem;
    color: #475569;
}
.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 0.82rem;
    outline: none;
    transition: all 0.2s ease;
}
.dataTables_wrapper .dataTables_filter input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}
.dataTables_wrapper .dataTables_length select {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 0.82rem;
    outline: none;
}
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    padding-top: 12px;
    font-size: 0.82rem;
    color: #64748b;
}
.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.8rem;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff !important;
    color: #334155 !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current,
.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #0b3d91 !important;
    color: #ffffff !important;
    border-color: #0b3d91 !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    border-color: #cbd5e1 !important;
}

/* KPI Bar */
.compact-kpi-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.compact-kpi-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.compact-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.compact-kpi-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
.compact-kpi-value { font-size: 0.95rem; font-weight: 800; color: #1e293b; }
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

        <!-- 1. Header Toolbar -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex align-items-center gap-2">
                <h4 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-bullhorn text-primary me-2"></i> Placement Bulletins & Notices
                </h4>
                <span class="badge bg-light text-primary border px-2 py-1 small fw-semibold">
                    <i class="fa-solid fa-calendar-check me-1"></i> Session: <?php echo esc($activeSessionName); ?>
                </span>
            </div>
            <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#announcementModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> Publish Drive / Notice
            </button>
        </div>

        <!-- 2. Compact KPI Metric Strip (Equal-Width 4-Card Grid) -->
        <div class="compact-kpi-bar">
            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Total Published</div>
                    <div class="compact-kpi-value text-primary"><?php echo $kpiTotal; ?> Alerts</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Placement Drives</div>
                    <div class="compact-kpi-value text-success"><?php echo $kpiDrives; ?> Drives</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Active / Open</div>
                    <div class="compact-kpi-value text-info"><?php echo $kpiActive; ?> Active</div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Expiring Soon (< 3d)</div>
                    <div class="compact-kpi-value <?php echo $kpiExpiringSoon > 0 ? 'text-warning' : 'text-dark'; ?>"><?php echo $kpiExpiringSoon; ?> Alerts</div>
                </div>
            </div>
        </div>

        <!-- 3. Segmented Quick Tabs -->
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div class="segmented-tab-bar">
                <a href="announcements.php?tab=all" class="segmented-tab-item <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-layer-group"></i> All Alerts <span class="segmented-pill-badge"><?php echo $kpiTotal; ?></span>
                </a>
                <a href="announcements.php?tab=drives" class="segmented-tab-item <?php echo $activeTab === 'drives' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-briefcase text-success"></i> Placement Drives <span class="segmented-pill-badge"><?php echo $kpiDrives; ?></span>
                </a>
                <a href="announcements.php?tab=notices" class="segmented-tab-item <?php echo $activeTab === 'notices' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-newspaper text-primary"></i> General Notices
                </a>
                <a href="announcements.php?tab=training" class="segmented-tab-item <?php echo $activeTab === 'training' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-award text-warning"></i> Training & Schedules
                </a>
                <a href="announcements.php?tab=active" class="segmented-tab-item <?php echo $activeTab === 'active' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-circle-dot text-success"></i> Active Only <span class="segmented-pill-badge"><?php echo $kpiActive; ?></span>
                </a>
                <a href="announcements.php?tab=expired" class="segmented-tab-item <?php echo $activeTab === 'expired' ? 'active' : ''; ?>">
                    <i class="fa-regular fa-clock text-secondary"></i> Expired / Closed
                </a>
            </div>
        </div>

        <!-- 4. High-Density Announcement Data Table or Empty State Card -->
        <div class="mamcet-card border-0 shadow-sm" style="overflow: hidden; border-radius: 10px;">
            <?php if (empty($displayedAnnouncements)): ?>
                <div class="p-5 text-center bg-white">
                    <div class="mb-3">
                        <span class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </span>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No Announcements in this Category</h6>
                    <p class="text-muted small mb-3">No campus recruitment drives or notices match the selected filter tab.</p>
                    <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#announcementModal" onclick="openCreateModal()">
                        <i class="fa-solid fa-plus me-1"></i> Publish First Announcement
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ann-table" id="annTable">
                        <thead>
                            <tr>
                                <th>Announcement & Role</th>
                                <th>Target Audience</th>
                                <th>Eligibility Rules</th>
                                <th>Registration & Links</th>
                                <th>Deadline</th>
                                <th>Priority</th>
                                <th class="text-end no-sort" width="80">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($displayedAnnouncements as $ann): 
                                $exp = !empty($ann['expiry_date']) ? strtotime($ann['expiry_date']) : 0;
                                $isExpired = $exp < $today;
                                $diffDays = floor(($exp - $today) / (60 * 60 * 24));
                                
                                $isDrive = $ann['announcement_type'] === 'Placement Drive';
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="ann-icon-badge <?php echo $isDrive ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'; ?>">
                                                <i class="fa-solid <?php echo $isDrive ? 'fa-briefcase' : ($ann['announcement_type'] === 'Training' ? 'fa-award' : 'fa-bullhorn'); ?>"></i>
                                            </div>
                                            <div>
                                                <a href="javascript:void(0)" class="text-dark fw-bold text-decoration-none" onclick="openPreviewModal(<?php echo htmlspecialchars(json_encode($ann)); ?>)">
                                                    <?php echo esc($ann['title']); ?>
                                                </a>
                                                <div class="d-flex align-items-center gap-2 mt-0">
                                                    <span class="badge bg-light text-primary border px-1" style="font-size: 0.7rem;">
                                                        <?php echo esc($ann['announcement_type']); ?>
                                                    </span>
                                                    <?php if (!empty($ann['company_name'])): ?>
                                                        <span class="fw-semibold text-secondary" style="font-size: 0.76rem;">
                                                            <?php echo esc($ann['company_name']); ?><?php echo !empty($ann['job_role']) ? ' • ' . esc($ann['job_role']) : ''; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($ann['package'])): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-1" style="font-size: 0.68rem;">
                                                            ₹ <?php echo esc($ann['package']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 180px;">
                                            <small class="text-muted d-block" style="font-size: 0.72rem;">Depts:</small>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.7rem;">
                                                <?php echo esc($ann['target_depts'] ?: 'All Depts'); ?>
                                            </span>
                                        </div>
                                        <div class="text-truncate mt-1" style="max-width: 180px;">
                                            <small class="text-muted d-block" style="font-size: 0.72rem;">Batches:</small>
                                            <span class="badge bg-light text-dark border" style="font-size: 0.7rem;">
                                                <?php echo esc($ann['target_batches'] ?: 'All Batches'); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                                            <span class="text-muted">CGPA:</span>
                                            <strong>≥ <?php echo number_format((float)$ann['min_cgpa'], 2); ?></strong>
                                        </div>
                                        <div class="d-flex align-items-center gap-1 mt-0" style="font-size: 0.78rem;">
                                            <span class="text-muted">Standing:</span>
                                            <span class="badge <?php echo (int)$ann['max_standing_arrears'] === 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning border border-warning-subtle'; ?>" style="font-size: 0.68rem;">
                                                ≤ <?php echo (int)$ann['max_standing_arrears']; ?> Arrears
                                            </span>
                                        </div>
                                        <?php if (!empty($ann['placement_willingness_req'])): ?>
                                            <small class="text-success d-block mt-0" style="font-size: 0.7rem;">
                                                <i class="fa-solid fa-circle-check me-1"></i> Willing Only
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <?php if (!empty($ann['google_form_url'])): ?>
                                                <a href="<?php echo esc($ann['google_form_url']); ?>" target="_blank" class="btn btn-sm btn-light border py-0 px-2 text-primary d-inline-flex align-items-center gap-1" style="font-size: 0.74rem; width: fit-content;">
                                                    <i class="fa-solid fa-square-poll-horizontal text-primary"></i> Apply Form
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($ann['google_sheet_url'])): ?>
                                                <a href="<?php echo esc($ann['google_sheet_url']); ?>" target="_blank" class="btn btn-sm btn-light border py-0 px-2 text-success d-inline-flex align-items-center gap-1" style="font-size: 0.74rem; width: fit-content;" title="Google Sheet Responses">
                                                    <i class="fa-solid fa-table text-success"></i> Responses <?php echo !empty($ann['is_sheet_student_visible']) ? '<span class="badge bg-info-subtle text-info p-0" style="font-size:0.6rem;">public</span>' : ''; ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($ann['jd_file_path'])): ?>
                                                <a href="<?php echo $baseDir . esc($ann['jd_file_path']); ?>" target="_blank" class="btn btn-sm btn-light border py-0 px-2 text-danger d-inline-flex align-items-center gap-1" style="font-size: 0.74rem; width: fit-content;">
                                                    <i class="fa-solid fa-file-pdf text-danger"></i> JD PDF
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 0.8rem;">
                                            <?php echo date('d M Y', strtotime($ann['expiry_date'])); ?>
                                        </div>
                                        <?php if ($isExpired): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border py-0" style="font-size: 0.68rem;">
                                                Closed / Expired
                                            </span>
                                        <?php elseif ($diffDays <= 3): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle py-0" style="font-size: 0.68rem;">
                                                <i class="fa-regular fa-clock me-1"></i> <?php echo $diffDays === 0 ? 'Today' : $diffDays . 'd left'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle py-0" style="font-size: 0.68rem;">
                                                <i class="fa-solid fa-circle-check me-1"></i> Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ann['priority'] === 'High'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-0" style="font-size: 0.74rem;">
                                                <span class="priority-dot priority-dot-high"></span> Urgent
                                            </span>
                                        <?php elseif ($ann['priority'] === 'Medium'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle py-0" style="font-size: 0.74rem;">
                                                <span class="priority-dot priority-dot-med"></span> Medium
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border py-0" style="font-size: 0.74rem;">
                                                <span class="priority-dot priority-dot-low"></span> Low
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <button class="btn btn-sm btn-light border py-0 px-2 text-danger" type="button" onclick="confirmDeleteAnnouncement(<?php echo (int)$ann['announcement_id']; ?>, '<?php echo esc(addslashes($ann['title'])); ?>')" title="Delete Announcement" style="font-size: 0.78rem;">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border py-0 px-2 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.78rem;">
                                                    Action
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.82rem;">
                                                    <li>
                                                        <button class="dropdown-item py-1" type="button" onclick="openPreviewModal(<?php echo htmlspecialchars(json_encode($ann)); ?>)">
                                                            <i class="fa-solid fa-eye text-primary me-2"></i> View Details
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item py-1" type="button" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ann)); ?>)">
                                                            <i class="fa-solid fa-pencil text-secondary me-2"></i> Edit Notice
                                                        </button>
                                                    </li>
                                                    <li><hr class="dropdown-divider my-1"></li>
                                                    <li>
                                                        <button type="button" class="dropdown-item py-1 text-danger" onclick="confirmDeleteAnnouncement(<?php echo (int)$ann['announcement_id']; ?>, '<?php echo esc(addslashes($ann['title'])); ?>')">
                                                            <i class="fa-solid fa-trash-can text-danger me-2"></i> Delete
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
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

<!-- 5. Redesigned Publish / Edit Modal -->
<div class="modal fade" id="announcementModal" tabindex="-1" aria-labelledby="annModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content text-dark border-0 shadow">
            <form action="announcements.php" method="POST" enctype="multipart/form-data">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="announcement_id" id="form_announcement_id" value="0">
                
                <div class="modal-header py-2 px-3 border-bottom bg-light">
                    <h6 class="modal-title fw-bold" id="annModalLabel">
                        <i class="fa-solid fa-bullhorn text-primary me-2"></i> Publish Placement Announcement
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-3" style="max-height: 540px; overflow-y: auto;">
                    <!-- Section 1: Basic Information -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">1. Announcement Title & Type</label>
                        <div class="row g-2">
                            <div class="col-md-8 col-12">
                                <input type="text" name="title" id="modal_title" class="form-control form-control-sm" placeholder="e.g. TCS Ninja Campus Recruitment Drive 2025" required>
                            </div>
                            <div class="col-md-4 col-12">
                                <select name="announcement_type" id="modal_type" class="form-select form-select-sm">
                                    <option value="Placement Drive" selected>Placement Drive</option>
                                    <option value="General">General Notice</option>
                                    <option value="Training">Training Circular</option>
                                    <option value="Test Schedule">Test Schedule</option>
                                    <option value="Interview Schedule">Interview Schedule</option>
                                    <option value="Deadline Reminder">Deadline Reminder</option>
                                    <option value="Circular">College Circular</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Company Details -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">2. Company & Job Details</label>
                        <div class="row g-2">
                            <div class="col-md-4 col-12">
                                <input type="text" name="company_name" id="modal_company" class="form-control form-control-sm" placeholder="Company Name (e.g. TCS)">
                            </div>
                            <div class="col-md-4 col-12">
                                <input type="text" name="job_role" id="modal_role" class="form-control form-control-sm" placeholder="Job Role (e.g. Software Engineer)">
                            </div>
                            <div class="col-md-2 col-6">
                                <input type="text" name="package" id="modal_package" class="form-control form-control-sm" placeholder="CTC (e.g. 7.0 LPA)">
                            </div>
                            <div class="col-md-2 col-6">
                                <input type="text" name="job_location" id="modal_location" class="form-control form-control-sm" placeholder="Location (e.g. Chennai)">
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Description -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">3. Announcement Body / Description</label>
                        <textarea name="description" id="modal_desc" class="form-control form-control-sm" rows="3" placeholder="Provide complete circular details, interview stages, syllabus, or instructions..." required></textarea>
                    </div>

                    <!-- Section 4: Eligibility Constraints -->
                    <div class="mb-3 p-2 bg-light rounded border">
                        <label class="form-label small fw-bold text-dark mb-1">4. Eligibility Filter Constraints</label>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-3 col-6">
                                <label class="text-muted small" style="font-size: 0.72rem;">Min CGPA</label>
                                <input type="number" step="0.01" name="min_cgpa" id="modal_cgpa" class="form-control form-control-sm" value="0.00">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="text-muted small" style="font-size: 0.72rem;">Max Standing Arrears</label>
                                <input type="number" name="max_standing_arrears" id="modal_max_arrears" class="form-control form-control-sm" value="99">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="text-muted small" style="font-size: 0.72rem;">Max History Arrears</label>
                                <input type="number" name="max_history_arrears" id="modal_max_history" class="form-control form-control-sm" value="99">
                            </div>
                            <div class="col-md-3 col-6 pt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="placement_willingness_req" id="modal_willing" value="1" checked>
                                    <label class="form-check-label small fw-semibold" for="modal_willing">Willing Only</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 5: Target Departments & Batches -->
                    <div class="mb-3">
                        <div class="row g-2">
                            <div class="col-md-6 col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-dark mb-0">Eligible Departments</label>
                                    <button type="button" class="btn btn-link p-0 text-primary small" style="font-size:0.72rem; text-decoration:none;" onclick="$('.dept-chk').prop('checked', true)">Select All</button>
                                </div>
                                <div class="p-2 border rounded bg-white" style="max-height: 100px; overflow-y: auto;">
                                    <?php foreach ($departments as $d): ?>
                                        <div class="form-check">
                                            <input class="form-check-input dept-chk" type="checkbox" name="departments[]" value="<?php echo $d['dept_id']; ?>" id="chk_d_<?php echo $d['dept_id']; ?>">
                                            <label class="form-check-label small" for="chk_d_<?php echo $d['dept_id']; ?>">
                                                <strong><?php echo esc($d['dept_code']); ?></strong> - <?php echo esc($d['dept_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-dark mb-0">Eligible Batches</label>
                                    <button type="button" class="btn btn-link p-0 text-primary small" style="font-size:0.72rem; text-decoration:none;" onclick="$('.batch-chk').prop('checked', true)">Select All</button>
                                </div>
                                <div class="p-2 border rounded bg-white" style="max-height: 100px; overflow-y: auto;">
                                    <?php foreach ($batches as $b): ?>
                                        <div class="form-check">
                                            <input class="form-check-input batch-chk" type="checkbox" name="batches[]" value="<?php echo $b['batch_id']; ?>" id="chk_b_<?php echo $b['batch_id']; ?>">
                                            <label class="form-check-label small" for="chk_b_<?php echo $b['batch_id']; ?>">
                                                <?php echo esc($b['batch_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 6: Links & Files -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">6. Links, Forms & Job Description Attachment</label>
                        <div class="row g-2">
                            <div class="col-md-6 col-12">
                                <input type="url" name="google_form_url" id="modal_form_url" class="form-control form-control-sm" placeholder="Google Form URL (https://forms.gle/...)">
                            </div>
                            <div class="col-md-6 col-12">
                                <input type="url" name="google_sheet_url" id="modal_sheet_url" class="form-control form-control-sm" placeholder="Google Sheet Responses URL (Internal)">
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="is_sheet_student_visible" id="modal_sheet_visible" value="1">
                                    <label class="form-check-label small" for="modal_sheet_visible" style="font-size:0.72rem;">Make responses sheet link visible to students</label>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="text-muted small" style="font-size: 0.72rem;">Upload JD PDF Attachment</label>
                                <input type="file" name="jd_file" class="form-control form-control-sm" accept="application/pdf">
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="text-muted small" style="font-size: 0.72rem;">External Careers Portal Link</label>
                                <input type="url" name="external_link" id="modal_external" class="form-control form-control-sm" placeholder="https://careers.company.com">
                            </div>
                        </div>
                    </div>

                    <!-- Section 7: Deadline, Priority & Status -->
                    <div class="row g-2">
                        <div class="col-md-4 col-12">
                            <label class="form-label small fw-bold text-dark mb-1">Expiry / Deadline</label>
                            <input type="date" name="expiry_date" id="modal_expiry" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Priority Level</label>
                            <select name="priority" id="modal_priority" class="form-select form-select-sm">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High (Urgent)</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label small fw-bold text-dark mb-1">Status</label>
                            <select name="status" id="modal_status" class="form-select form-select-sm">
                                <option value="published" selected>Published</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer py-2 px-3 bg-light border-top d-flex justify-content-between align-items-center">
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteAnnouncementInModal" style="display: none;" onclick="deleteAnnouncementFromEditModal()">
                            <i class="fa-solid fa-trash-can me-1"></i> Delete Announcement
                        </button>
                    </div>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitForm">Publish Announcement</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 6. Quick Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-dark border-0 shadow">
            <div class="modal-header py-2 px-3 bg-light border-bottom">
                <h6 class="modal-title fw-bold" id="previewModalLabel">
                    <i class="fa-solid fa-eye text-primary me-2"></i> Announcement Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" id="previewModalBody">
                <!-- Injected via JS -->
            </div>
            <div class="modal-footer py-2 px-3 bg-light border-top d-flex justify-content-between align-items-center" id="previewModalFooter">
                <div>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteFromPreview">
                        <i class="fa-solid fa-trash-can me-1"></i> Delete Announcement
                    </button>
                </div>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEditFromPreview">
                        <i class="fa-solid fa-pencil me-1"></i> Edit Notice
                    </button>
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Announcement Deletion Form -->
<form id="deleteAnnouncementForm" action="announcements.php<?php echo !empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''; ?>" method="POST" style="display: none;">
    <?php csrfInput(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="announcement_id" id="delete_announcement_id" value="0">
</form>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    if ($('#annTable').length) {
        $('#annTable').DataTable({
            "columnDefs": [
                { "orderable": false, "targets": 'no-sort' }
            ],
            "order": [[4, "asc"]],
            "pageLength": 15,
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search announcements, companies, roles...",
                "lengthMenu": "Show _MENU_ entries"
            }
        });
    }
});

let currentEditAnnouncement = null;
let currentPreviewAnnouncement = null;

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
    currentEditAnnouncement = null;
    $('#btnDeleteAnnouncementInModal').hide();
    $('#annModalLabel').html('<i class="fa-solid fa-bullhorn text-primary me-2"></i> Publish Placement Announcement');
    $('#form_action').val('create');
    $('#form_announcement_id').val('0');
    
    $('#modal_title').val('');
    $('#modal_type').val('Placement Drive');
    $('#modal_company').val('');
    $('#modal_role').val('');
    $('#modal_package').val('');
    $('#modal_location').val('');
    $('#modal_desc').val('');
    
    $('#modal_cgpa').val('0.00');
    $('#modal_max_arrears').val('99');
    $('#modal_max_history').val('99');
    $('#modal_willing').prop('checked', true);
    
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    $('#modal_form_url').val('');
    $('#modal_sheet_url').val('');
    $('#modal_sheet_visible').prop('checked', false);
    $('#modal_external').val('');
    $('#modal_expiry').val('');
    $('#modal_priority').val('Medium');
    $('#modal_status').val('published');
    
    $('#btnSubmitForm').text('Publish Announcement');
}

function openEditModal(ann) {
    currentEditAnnouncement = ann;
    $('#btnDeleteAnnouncementInModal').show();
    $('#annModalLabel').html('<i class="fa-solid fa-pencil text-primary me-2"></i> Edit Announcement');
    $('#form_action').val('edit');
    $('#form_announcement_id').val(ann.announcement_id);
    
    $('#modal_title').val(ann.title);
    $('#modal_type').val(ann.announcement_type);
    $('#modal_company').val(ann.company_name || '');
    $('#modal_role').val(ann.job_role || '');
    $('#modal_package').val(ann.package || '');
    $('#modal_location').val(ann.job_location || '');
    $('#modal_desc').val(ann.description);
    
    $('#modal_cgpa').val(ann.min_cgpa);
    $('#modal_max_arrears').val(ann.max_standing_arrears);
    $('#modal_max_history').val(ann.max_history_arrears);
    $('#modal_willing').prop('checked', ann.placement_willingness_req == 1);
    
    $('.dept-chk').prop('checked', false);
    $('.batch-chk').prop('checked', false);
    
    if (ann.target_depts) {
        ann.target_depts.split(', ').forEach(code => {
            $('.dept-chk').each(function() {
                if ($(this).next('label').text().indexOf(code) !== -1) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    if (ann.target_batches) {
        ann.target_batches.split(', ').forEach(name => {
            $('.batch-chk').each(function() {
                if ($(this).next('label').text().trim() === name) {
                    $(this).prop('checked', true);
                }
            });
        });
    }
    
    $('#modal_form_url').val(ann.google_form_url || '');
    $('#modal_sheet_url').val(ann.google_sheet_url || '');
    $('#modal_sheet_visible').prop('checked', ann.is_sheet_student_visible == 1);
    $('#modal_external').val(ann.external_link || '');
    $('#modal_expiry').val(ann.expiry_date);
    $('#modal_priority').val(ann.priority);
    $('#modal_status').val(ann.status);
    
    $('#btnSubmitForm').text('Save Changes');
    
    var modal = new bootstrap.Modal(document.getElementById('announcementModal'));
    modal.show();
}

function deleteAnnouncementFromEditModal() {
    if (!currentEditAnnouncement) return;
    const modalEl = document.getElementById('announcementModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) {
        modalInstance.hide();
    }
    confirmDeleteAnnouncement(currentEditAnnouncement.announcement_id, currentEditAnnouncement.title);
}

function openPreviewModal(ann) {
    currentPreviewAnnouncement = ann;
    let html = `
        <h5 class="fw-bold text-dark mb-1">${escapeHtml(ann.title)}</h5>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-primary-subtle text-primary border">${escapeHtml(ann.announcement_type)}</span>
            ${ann.company_name ? `<span class="badge bg-light text-dark border">🏢 ${escapeHtml(ann.company_name)}</span>` : ''}
            ${ann.package ? `<span class="badge bg-success-subtle text-success border">₹ ${escapeHtml(ann.package)}</span>` : ''}
            <span class="badge bg-warning-subtle text-warning border">⏳ Deadline: ${escapeHtml(ann.expiry_date)}</span>
        </div>
        <div class="p-3 bg-light rounded border mb-3">
            <h6 class="fw-bold small mb-2 text-secondary text-uppercase">Description</h6>
            <div style="font-size: 0.88rem; white-space: pre-line;">${escapeHtml(ann.description)}</div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="p-2 border rounded bg-white">
                    <small class="text-muted d-block">Target Departments</small>
                    <strong class="text-primary small">${escapeHtml(ann.target_depts || 'All')}</strong>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 border rounded bg-white">
                    <small class="text-muted d-block">Target Batches</small>
                    <strong class="text-dark small">${escapeHtml(ann.target_batches || 'All')}</strong>
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            ${ann.google_form_url ? `<a href="${escapeHtml(ann.google_form_url)}" target="_blank" class="btn btn-sm btn-primary"><i class="fa-solid fa-square-poll-horizontal me-1"></i> Apply / Register</a>` : ''}
            ${ann.google_sheet_url ? `<a href="${escapeHtml(ann.google_sheet_url)}" target="_blank" class="btn btn-sm btn-success"><i class="fa-solid fa-table me-1"></i> Response Sheet</a>` : ''}
            ${ann.external_link ? `<a href="${escapeHtml(ann.external_link)}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Careers Portal</a>` : ''}
        </div>
    `;
    
    $('#previewModalBody').html(html);

    // Bind preview action buttons
    $('#btnDeleteFromPreview').off('click').on('click', function() {
        const modalEl = document.getElementById('previewModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) {
            modalInstance.hide();
        }
        confirmDeleteAnnouncement(ann.announcement_id, ann.title);
    });

    $('#btnEditFromPreview').off('click').on('click', function() {
        const modalEl = document.getElementById('previewModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) {
            modalInstance.hide();
        }
        openEditModal(ann);
    });

    var modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
}

function confirmDeleteAnnouncement(announcementId, title) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Announcement?',
            html: `Are you sure you want to permanently delete <strong>${escapeHtml(title)}</strong>?<br><br><div class="alert alert-danger text-start p-2 mb-0 small"><i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Permanent Action:</strong> This announcement and any target batch/department distribution records will be removed from all student dashboards.</div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Delete Notice',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_announcement_id').val(announcementId);
                $('#deleteAnnouncementForm').submit();
            }
        });
    } else {
        if (confirm(`Permanently delete announcement "${title}"? This cannot be undone.`)) {
            $('#delete_announcement_id').val(announcementId);
            $('#deleteAnnouncementForm').submit();
        }
    }
}
</script>

