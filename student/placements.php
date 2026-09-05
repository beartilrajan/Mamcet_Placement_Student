<?php
// MAMCET Placement & Learning Portal - Student Placement Announcements View

$pageTitle = 'Placement Updates';
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
$annId = (int)($_GET['announcement_id'] ?? 0);

// Fetch academic details to check eligibility constraints
$stmtAc = $db->prepare("SELECT * FROM student_academics WHERE student_id = ?");
$stmtAc->execute([$studentId]);
$academics = $stmtAc->fetch();

$cgpa = (float)($academics['current_cgpa'] ?? 0.00);
$standingArrears = (int)($academics['standing_arrears'] ?? 0);
$historyArrears = (int)($academics['history_of_arrears'] ?? 0);
$willing = $student['placement_willingness'] ?? 'Yes';

if ($annId > 0) {
    // -------------------------------------------------------------
    // DETAIL VIEW
    // -------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT a.*, o.name AS officer_name, o.email AS officer_email
        FROM announcements a
        JOIN users u ON a.created_by = u.user_id
        LEFT JOIN placement_officers o ON u.user_id = o.user_id
        WHERE a.announcement_id = ? AND a.status = 'published'
          AND a.announcement_id IN (SELECT announcement_id FROM announcement_departments WHERE dept_id = ?)
          AND a.announcement_id IN (SELECT announcement_id FROM announcement_batches WHERE batch_id = ?)
    ");
    $stmt->execute([$annId, $student['dept_id'], $student['batch_id']]);
    $ann = $stmt->fetch();
    
    if (!$ann) {
        echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Announcement not found or not applicable to your batch/department.</div></div></div>";
        require_once(__DIR__ . '/../includes/footer.php');
        exit;
    }
    
    // Evaluate Eligibility
    $isEligible = true;
    $ineligibleReasons = [];
    
    if ($cgpa < (float)$ann['min_cgpa']) {
        $isEligible = false;
        $ineligibleReasons[] = "Your CGPA is " . number_format($cgpa, 2) . ", which is below the minimum required " . number_format($ann['min_cgpa'], 2) . ".";
    }
    if ($standingArrears > (int)$ann['max_standing_arrears']) {
        $isEligible = false;
        $ineligibleReasons[] = "You have " . $standingArrears . " standing arrears, which exceeds the limit of " . $ann['max_standing_arrears'] . ".";
    }
    if ($historyArrears > (int)$ann['max_history_arrears']) {
        $isEligible = false;
        $ineligibleReasons[] = "You have a history of " . $historyArrears . " arrears, which exceeds the limit of " . $ann['max_history_arrears'] . ".";
    }
    if ($ann['placement_willingness_req'] == 1 && $willing !== 'Yes') {
        $isEligible = false;
        $ineligibleReasons[] = "This drive requires placement willingness to be set to 'Yes' (your current status is 'No'). You can change this on your profile page.";
    }
    
} else {
    // -------------------------------------------------------------
    // LIST VIEW
    // -------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT a.* 
        FROM announcements a
        JOIN announcement_departments ad ON a.announcement_id = ad.announcement_id
        JOIN announcement_batches ab ON a.announcement_id = ab.announcement_id
        WHERE a.status = 'published' 
          AND a.session_id = ? 
          AND ad.dept_id = ? 
          AND ab.batch_id = ?
        ORDER BY a.expiry_date >= CURDATE() DESC, a.priority DESC, a.publish_date DESC
    ");
    $stmt->execute([$activeSessionId, $student['dept_id'], $student['batch_id']]);
    $announcements = $stmt->fetchAll();
}
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <?php if ($annId > 0): ?>
            <!-- -------------------------------------------------------------
                 DETAIL VIEW RENDER
                 ------------------------------------------------------------- -->
            <div class="mb-4">
                <a href="placements.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Notices</a>
            </div>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="mamcet-card h-100">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold mb-0 text-dark"><?php echo esc($ann['title']); ?></h5>
                            <span class="badge <?php echo $ann['priority'] === 'High' ? 'bg-danger' : 'bg-primary'; ?>"><?php echo $ann['priority']; ?> Priority</span>
                        </div>
                        
                        <div class="card-body">
                            <span class="badge bg-light text-primary border mb-3"><?php echo esc($ann['announcement_type']); ?></span>
                            
                            <div class="row mb-4 g-3">
                                <?php if (!empty($ann['company_name'])): ?>
                                    <div class="col-6 col-md-3">
                                        <span class="d-block small text-muted">Company</span>
                                        <strong class="text-dark"><?php echo esc($ann['company_name']); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <span class="d-block small text-muted">Job Role</span>
                                        <strong class="text-dark"><?php echo esc($ann['job_role']); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <span class="d-block small text-muted">Package</span>
                                        <strong class="text-success"><?php echo formatPackage($ann['package']); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <span class="d-block small text-muted">Job Location</span>
                                        <strong class="text-dark"><?php echo esc($ann['job_location'] ?: 'Not Specified'); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Job Description & Details</h6>
                            <p class="text-secondary-emphasis" style="white-space: pre-line; line-height: 1.6;"><?php echo esc($ann['description']); ?></p>

                            <!-- Attachments -->
                            <?php if (!empty($ann['jd_file_path'])): ?>
                                <div class="mt-4 p-3 border rounded bg-light d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="fa-solid fa-file-pdf fa-2x text-danger"></i>
                                        <div>
                                            <strong class="text-dark d-block">Job Details Document</strong>
                                            <small class="text-muted">PDF Attachment</small>
                                        </div>
                                    </div>
                                    <a href="../<?php echo esc($ann['jd_file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-arrow-down-to-bracket me-1"></i> Download PDF</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mb-4">
                    <!-- ELIGIBILITY BOX -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title fw-bold mb-0 text-dark">Your Eligibility Status</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($isEligible): ?>
                                <div class="alert alert-success py-3 text-center mb-4">
                                    <i class="fa-solid fa-circle-check fa-3x mb-2"></i>
                                    <h5 class="fw-bold mb-1">You are Eligible!</h5>
                                    <span class="small">You satisfy all academic parameters.</span>
                                </div>
                                
                                <?php if (!empty($ann['google_form_url'])): ?>
                                    <a href="<?php echo esc($ann['google_form_url']); ?>" target="_blank" class="btn btn-primary btn-lg w-100 mb-3"><i class="fa-solid fa-square-poll-horizontal me-2"></i> Apply on Google Form</a>
                                <?php endif; ?>
                                
                                <?php if (!empty($ann['external_link'])): ?>
                                    <a href="<?php echo esc($ann['external_link']); ?>" target="_blank" class="btn btn-outline-dark btn-sm w-100"><i class="fa-solid fa-up-right-from-square me-2"></i> Company Portal Link</a>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="alert alert-danger py-3 text-center mb-4">
                                    <i class="fa-solid fa-circle-xmark fa-3x mb-2"></i>
                                    <h5 class="fw-bold mb-1">Not Eligible</h5>
                                    <span class="small">Does not satisfy target constraints.</span>
                                </div>
                                
                                <h6 class="fw-bold small text-dark mb-2">Reasons for Ineligibility:</h6>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($ineligibleReasons as $reason): ?>
                                        <li class="small text-danger mb-2"><i class="fa-solid fa-xmark me-2"></i> <?php echo esc($reason); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                
                                <div class="text-center mt-3 text-muted small">
                                    <em>If you believe this is an error, contact the placement officer to override eligibility.</em>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- OFFICER CONTACT -->
                    <div class="mamcet-card">
                        <div class="card-header bg-light">
                            <h6 class="card-title fw-bold mb-0 text-dark">Notice Coordinator</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-secondary text-white" style="width:40px;height:40px;border-radius:50%;font-weight:bold;">
                                    <?php echo strtoupper(substr($ann['officer_name'] ?: 'O', 0, 1)); ?>
                                </div>
                                <div>
                                    <strong class="text-dark d-block"><?php echo esc($ann['officer_name'] ?: 'Placement Officer'); ?></strong>
                                    <span class="small text-muted d-block"><?php echo esc($ann['officer_email']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RESPONSES SHEET (IF VISIBLE TO STUDENTS) -->
            <?php if (!empty($ann['google_sheet_url']) && $ann['is_sheet_student_visible'] == 1): ?>
                <div class="mamcet-card mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-table text-success me-2"></i> Applied Students Registry (Google Sheet)</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="ratio ratio-21x9" style="border: none;">
                            <iframe src="<?php echo esc($ann['google_sheet_url']); ?>?widget=true&headers=false"></iframe>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- -------------------------------------------------------------
                 LIST VIEW RENDER
                 ------------------------------------------------------------- -->
            <div class="mamcet-card">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title fw-bold mb-0"><i class="fa-solid fa-bullhorn text-primary me-2"></i> Current Placements, Drives and Schedules</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($announcements)): ?>
                        <div class="text-center py-4 py-md-5 px-3 text-muted">
                            <i class="fa-solid fa-bullhorn fa-3x mb-3 text-secondary" style="opacity:0.4;"></i>
                            <p class="mb-0 small">No active placement updates posted for your batch/department.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($announcements as $a):
                                $expired = strtotime($a['expiry_date']) < time();
                            ?>
                                <div class="list-group-item list-group-item-action py-4 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                                    <div style="flex-grow:1; max-width: 75%;">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <h5 class="fw-bold mb-0 text-dark"><?php echo esc($a['title']); ?></h5>
                                            <span class="badge <?php echo $a['priority'] === 'High' ? 'bg-danger' : 'bg-primary'; ?>"><?php echo $a['priority']; ?></span>
                                            <?php if ($expired): ?>
                                                <span class="badge bg-secondary">Closed</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <span class="badge bg-light text-primary border"><?php echo esc($a['announcement_type']); ?></span>
                                            <?php if (!empty($a['company_name'])): ?>
                                                <span class="small text-muted ms-2">Company: <strong><?php echo esc($a['company_name']); ?></strong> (<?php echo formatPackage($a['package']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <p class="mb-0 text-muted small text-truncate" style="max-width: 600px;"><?php echo esc(strip_tags($a['description'])); ?></p>
                                    </div>
                                    
                                    <div class="text-md-end text-start" style="min-width: 160px;">
                                        <span class="small text-muted d-block mb-2"><i class="fa-solid fa-calendar me-1"></i> Deadline: <strong><?php echo date('d M Y', strtotime($a['expiry_date'])); ?></strong></span>
                                        <a href="placements.php?announcement_id=<?php echo $a['announcement_id']; ?>" class="btn btn-sm btn-primary px-3">
                                            View Details <i class="fa-solid fa-chevron-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

