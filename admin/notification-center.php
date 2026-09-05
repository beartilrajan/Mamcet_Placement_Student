<?php
// MAMCET Placement & Learning Portal - Multi-Channel Notification Center (Senior UI/UX Redesign)

$pageTitle = 'Notification Center';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Officer / Super Admin
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY graduation_year DESC")->fetchAll();
$drives = $db->query("
    SELECT po.opportunity_id, po.job_id, jd.company_name, jd.job_title 
    FROM placement_opportunities po 
    JOIN job_descriptions jd ON po.job_id = jd.job_id 
    WHERE po.status = 'active'
")->fetchAll();

// Handle Dispatch Notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    verifyCsrfRequest();
    try {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $target = $_POST['target_group'] ?? 'all';
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;

        if (empty($title) || empty($body)) {
            throw new Exception("Notification Title and Message Details are required.");
        }

        $recipientUserIds = [];

        if ($target === 'all') {
            $stmt = $db->query("SELECT user_id FROM students WHERE user_id IS NOT NULL");
            $recipientUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target === 'dept_batch') {
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $batchId = (int)($_POST['batch_id'] ?? 0);
            
            $sql = "SELECT user_id FROM students WHERE user_id IS NOT NULL";
            $params = [];
            if ($deptId > 0) {
                $sql .= " AND dept_id = ?";
                $params[] = $deptId;
            }
            if ($batchId > 0) {
                $sql .= " AND batch_id = ?";
                $params[] = $batchId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $recipientUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target === 'eligible_only') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            
            $stmtJob = $db->prepare("SELECT job_id FROM placement_opportunities WHERE opportunity_id = ?");
            $stmtJob->execute([$oppId]);
            $jobId = (int)$stmtJob->fetchColumn();

            if (!$jobId) {
                throw new Exception("Selected campus drive opportunity was not found.");
            }

            require_once(__DIR__ . '/../services/EligibilityService.php');
            $stmtSt = $db->query("SELECT student_id, user_id FROM students WHERE user_id IS NOT NULL");
            $allSt = $stmtSt->fetchAll();

            foreach ($allSt as $st) {
                $eligData = EligibilityService::calculateStudentEligibility($db, (int)$st['student_id'], $jobId);
                if (!empty($eligData['is_eligible'])) {
                    $recipientUserIds[] = (int)$st['user_id'];
                }
            }
        }

        if (empty($recipientUserIds)) {
            throw new Exception("No recipient students match the selected target criteria.");
        }

        // Deduplicate
        $recipientUserIds = array_unique(array_filter($recipientUserIds));

        require_once(__DIR__ . '/../services/NotificationService.php');
        require_once(__DIR__ . '/../services/EmailService.php');
        
        // Dispatch In-Portal
        NotificationService::sendPortalNotification($db, (int)$_SESSION['user_id'], $title, $body, $recipientUserIds);

        // Queue Email if selected
        if ($sendEmail) {
            foreach ($recipientUserIds as $uId) {
                $st = $db->prepare("SELECT student_name, email FROM students WHERE user_id = ?");
                $st->execute([$uId]);
                $stu = $st->fetch();
                if ($stu && !empty($stu['email'])) {
                    EmailService::queueEmail($db, $stu['email'], $stu['student_name'], $title, nl2br(htmlspecialchars($body)));
                }
            }
        }

        $reachCount = count($recipientUserIds);
        $message = "Broadcast successfully dispatched to {$reachCount} students!" . ($sendEmail ? " Email alerts have also been queued." : "");
        logActivity($db, $_SESSION['user_id'], 'Broadcast Notification', "Sent notification '{$title}' to {$reachCount} students");
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch Broadcast Statistics
$totalBroadcasts = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$totalReach = (int)$db->query("SELECT COUNT(*) FROM notification_recipients")->fetchColumn();
$totalDrivesCount = count($drives);

// Fetch Recent Dispatches (Correct SQL Joins for users, placement_officers, and students)
$recentNotifs = $db->query("
    SELECT n.*, 
           u.username,
           COALESCE(po.name, st.student_name, u.username, 'System') AS sender_name,
           COUNT(nr.user_id) AS total_recipients,
           SUM(CASE WHEN nr.is_read = 1 THEN 1 ELSE 0 END) AS read_count
    FROM notifications n
    LEFT JOIN users u ON n.sender_id = u.user_id
    LEFT JOIN placement_officers po ON u.user_id = po.user_id
    LEFT JOIN students st ON u.user_id = st.user_id
    LEFT JOIN notification_recipients nr ON n.notification_id = nr.notification_id
    GROUP BY n.notification_id
    ORDER BY n.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Notification Hub Custom Styles */
.notif-kpi-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.notif-kpi-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.notif-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.notif-kpi-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    line-height: 1;
    margin-bottom: 2px;
}
.notif-kpi-value {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}

/* Segmented Tab Switcher */
.settings-nav-tabs {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 10px;
    gap: 4px;
    border: 1px solid #e2e8f0;
}
.settings-nav-link,
button.settings-nav-link {
    appearance: none !important;
    -webkit-appearance: none !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    padding: 7px 14px !important;
    border-radius: 8px !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    color: #475569 !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    white-space: nowrap !important;
    transition: all 0.15s ease !important;
    cursor: pointer !important;
    outline: none !important;
    box-shadow: none !important;
    font-family: inherit !important;
}
.settings-nav-link:hover,
button.settings-nav-link:hover {
    color: #0f172a !important;
    background: rgba(255, 255, 255, 0.7) !important;
}
.settings-nav-link.active,
button.settings-nav-link.active {
    background: #ffffff !important;
    color: #2563eb !important;
    font-weight: 700 !important;
    border-color: #e2e8f0 !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08) !important;
}

/* Template Chips */
.template-chip {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 4px 11px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    user-select: none;
}
.template-chip:hover {
    background: #e0e7ff;
    border-color: #c7d2fe;
    color: #3730a3;
}

/* Device Mockup Card */
.device-mockup-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    overflow: hidden;
}
.mockup-header-bar {
    background: #0b1329;
    color: #ffffff;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.76rem;
    font-weight: 600;
}
.mockup-body {
    padding: 14px;
    background: #f8fafc;
    min-height: 180px;
}
.mockup-alert-bubble {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #2563eb;
    border-radius: 8px;
    padding: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}

/* WhatsApp Bubble Preview */
.whatsapp-chat-bubble {
    background: #dcf8c6;
    border-radius: 8px;
    padding: 12px 14px;
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    font-size: 0.85rem;
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
        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-circle-check me-1"></i> <?php echo esc($message); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h4 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-paper-plane text-primary me-2"></i> Multi-Channel Notification Hub
                </h4>
                <p class="text-muted small mb-0 mt-1">Broadcast high-priority placement alerts, target specific student cohorts, and generate instant WhatsApp reminders.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-primary border px-2 py-1 small fw-semibold">
                    <i class="fa-solid fa-signal me-1"></i> Gateway Online
                </span>
            </div>
        </div>

        <!-- Executive KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="notif-kpi-bar">
            <div class="notif-kpi-item">
                <div class="notif-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <div class="notif-kpi-label">Total Broadcasts</div>
                    <div class="notif-kpi-value text-primary"><?php echo $totalBroadcasts; ?></div>
                </div>
            </div>

            <div class="notif-kpi-item">
                <div class="notif-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-users-viewfinder"></i>
                </div>
                <div>
                    <div class="notif-kpi-label">Student Outreach</div>
                    <div class="notif-kpi-value text-success"><?php echo number_format($totalReach); ?> Reached</div>
                </div>
            </div>

            <div class="notif-kpi-item">
                <div class="notif-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="notif-kpi-label">Active Campus Drives</div>
                    <div class="notif-kpi-value text-dark"><?php echo $totalDrivesCount; ?> Live</div>
                </div>
            </div>

            <div class="notif-kpi-item">
                <div class="notif-kpi-icon bg-info-subtle text-info">
                    <i class="fa-brands fa-whatsapp"></i>
                </div>
                <div>
                    <div class="notif-kpi-label">WhatsApp Quick Hub</div>
                    <div class="notif-kpi-value text-info">Instant Click-to-Chat</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- Left Column: Compose & Dispatch Broadcast -->
            <div class="col-lg-7 col-12">
                <div class="mamcet-card border-0 shadow-sm" style="border-radius: 10px;">
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-pen-to-square text-primary me-2"></i> Compose Notification Broadcast
                        </h6>
                        <span class="badge bg-light text-secondary border small">Channels: In-App & Email</span>
                    </div>
                    <div class="card-body p-3">
                        <!-- Quick Template Selector Chips -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark mb-1">Quick Presets & Templates</label>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="template-chip" onclick="applyTemplate('drive')">
                                    <i class="fa-solid fa-briefcase text-success"></i> Campus Drive Alert
                                </span>
                                <span class="template-chip" onclick="applyTemplate('profile')">
                                    <i class="fa-solid fa-triangle-exclamation text-warning"></i> Profile Review Warning
                                </span>
                                <span class="template-chip" onclick="applyTemplate('course')">
                                    <i class="fa-solid fa-book-bookmark text-primary"></i> LMS Deadline
                                </span>
                                <span class="template-chip" onclick="applyTemplate('interview')">
                                    <i class="fa-solid fa-user-tie text-info"></i> Interview Round
                                </span>
                            </div>
                        </div>

                        <form method="POST" action="notification-center.php" id="broadcastForm">
                            <?php csrfInput(); ?>

                            <!-- Notification Title -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-dark mb-0">Notification Title <span class="text-danger">*</span></label>
                                    <span class="text-muted small" id="titleCharCount" style="font-size:0.72rem;">0 / 120</span>
                                </div>
                                <input type="text" name="title" id="notifTitle" class="form-control form-control-sm" placeholder="e.g. Drive Alert: TCS Ninja Recruitment 2025" maxlength="120" required oninput="updateLivePreview()">
                            </div>

                            <!-- Notification Body -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark mb-1">Message Context & Instructions <span class="text-danger">*</span></label>
                                <textarea name="body" id="notifBody" class="form-control form-control-sm" rows="4" placeholder="Enter full notification details, instructions, or links..." required oninput="updateLivePreview()"></textarea>
                            </div>

                            <!-- Target Cohort Audience Selection -->
                            <div class="mb-3 p-2 bg-light rounded border">
                                <label class="form-label small fw-bold text-dark mb-1">Target Recipient Audience</label>
                                <div class="d-flex flex-wrap gap-3 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input target-radio" type="radio" name="target_group" id="targetAll" value="all" checked>
                                        <label class="form-check-label small fw-semibold" for="targetAll">All Registered Students</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input target-radio" type="radio" name="target_group" id="targetFilter" value="dept_batch">
                                        <label class="form-check-label small fw-semibold" for="targetFilter">By Department & Batch</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input target-radio" type="radio" name="target_group" id="targetElig" value="eligible_only">
                                        <label class="form-check-label small fw-semibold" for="targetElig">Only Eligible for Drive</label>
                                    </div>
                                </div>

                                <!-- Filter parameters Section (Conditional) -->
                                <div class="row g-2 pt-2 border-top d-none" id="filterSection">
                                    <div class="col-6">
                                        <label class="form-label small text-muted mb-0" style="font-size:0.72rem;">Department</label>
                                        <select class="form-select form-select-sm" name="dept_id">
                                            <option value="0">All Departments</option>
                                            <?php foreach ($departments as $d): ?>
                                                <option value="<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_code']); ?> - <?php echo esc($d['dept_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small text-muted mb-0" style="font-size:0.72rem;">Batch</label>
                                        <select class="form-select form-select-sm" name="batch_id">
                                            <option value="0">All Batches</option>
                                            <?php foreach ($batches as $b): ?>
                                                <option value="<?php echo $b['batch_id']; ?>"><?php echo esc($b['batch_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Job Eligibility Section (Conditional) -->
                                <div class="pt-2 border-top d-none" id="eligibilitySection">
                                    <label class="form-label small text-muted mb-1" style="font-size:0.72rem;">Select Live Campus Drive</label>
                                    <select class="form-select form-select-sm" name="opportunity_id">
                                        <option value="">-- Choose Active Placement Opportunity --</option>
                                        <?php foreach ($drives as $dr): ?>
                                            <option value="<?php echo $dr['opportunity_id']; ?>"><?php echo esc($dr['company_name']); ?> (<?php echo esc($dr['job_title']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Channel Multi-Select Toggle -->
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmailCheckbox">
                                    <label class="form-check-label small text-secondary fw-semibold" for="sendEmailCheckbox">
                                        <i class="fa-regular fa-envelope text-primary me-1"></i> Also dispatch SMTP E-mail alert queue to student email addresses
                                    </label>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" name="send_notification" class="btn btn-sm btn-primary w-100 py-2 shadow-sm fw-bold">
                                <i class="fa-solid fa-paper-plane me-1"></i> Dispatch Multi-Channel Broadcast
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Live Mockup & Smart WhatsApp Builder -->
            <div class="col-lg-5 col-12">
                <!-- Nav Tabs between Live Preview & WhatsApp Hub -->
                <div class="settings-nav-tabs w-100 mb-2">
                    <button type="button" class="settings-nav-link active flex-fill justify-content-center" id="tabBtnPreview" onclick="switchRightTab('preview')">
                        <i class="fa-solid fa-mobile-screen text-primary"></i> Live Student Preview
                    </button>
                    <button type="button" class="settings-nav-link flex-fill justify-content-center" id="tabBtnWa" onclick="switchRightTab('whatsapp')">
                        <i class="fa-brands fa-whatsapp text-success"></i> WhatsApp Builder
                    </button>
                </div>

                <!-- Pane 1: Live Student Device Preview -->
                <div id="panePreview">
                    <div class="device-mockup-card">
                        <div class="mockup-header-bar">
                            <span><i class="fa-solid fa-graduation-cap me-1"></i> MAMCET Student Portal</span>
                            <span class="badge bg-primary text-white" style="font-size:0.68rem;">Live Preview</span>
                        </div>
                        <div class="mockup-body">
                            <small class="text-muted text-uppercase fw-bold mb-2 d-block" style="font-size:0.7rem;">In-App Drawer Notification</small>
                            <div class="mockup-alert-bubble">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <strong class="text-dark" id="previewTitle" style="font-size: 0.88rem;">Drive Alert: Sample Recruitment Drive</strong>
                                    <span class="badge bg-light text-muted border py-0" style="font-size: 0.65rem;">Just now</span>
                                </div>
                                <p class="text-secondary mb-2" id="previewBody" style="font-size: 0.8rem; line-height: 1.4; white-space: pre-wrap;">Message details typed on the left will render here live for testing student readability.</p>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top" style="font-size:0.72rem;">
                                    <span class="text-primary fw-semibold"><i class="fa-solid fa-circle-check me-1"></i> Verified Placement Notice</span>
                                    <span class="text-muted">Placement Cell</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pane 2: WhatsApp Reminder Builder -->
                <div id="paneWhatsApp" class="d-none">
                    <div class="mamcet-card border-0 shadow-sm" style="border-radius: 10px;">
                        <div class="card-header bg-success text-white py-2 px-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0">
                                <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp Reminder Builder
                            </h6>
                            <span class="badge bg-white text-success small">Click-to-Chat</span>
                        </div>
                        <div class="card-body p-3">
                            <p class="text-muted small mb-2" style="font-size: 0.78rem;">Generate formatted WhatsApp Click-to-Chat direct links for Class Coordinators, Student Reps, or Department groups.</p>
                            
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-dark mb-1">Recipient Mobile Number (91+)</label>
                                <input type="text" id="waMobile" class="form-control form-control-sm" placeholder="919876543210" value="91">
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold text-dark mb-1">WhatsApp Formatted Message</label>
                                <textarea id="waText" class="form-control form-control-sm" rows="4" placeholder="Dear Students, Zoho Corporation drive registration details..." oninput="updateWaBubble()"></textarea>
                            </div>

                            <!-- Live WhatsApp Bubble -->
                            <div class="p-2 rounded bg-light border mb-3" style="background: #e5ddd5 !important;">
                                <div class="whatsapp-chat-bubble" id="waBubble">
                                    Dear Students, Zoho Corporation drive registration details...
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" id="btnGenWa" class="btn btn-sm btn-success flex-grow-1 shadow-sm font-weight-bold" onclick="launchWhatsApp()">
                                    <i class="fa-brands fa-whatsapp me-1"></i> Open in WhatsApp
                                </button>
                                <button type="button" class="btn btn-sm btn-light border" onclick="copyWaText()" title="Copy to Clipboard">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Full-Width Card: Recent Broadcast History Audit Log -->
        <div class="mamcet-card border-0 shadow-sm mt-3" style="border-radius: 10px; overflow:hidden;">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Recent Broadcast Audit Log
                </h6>
                <span class="badge bg-light text-secondary border small">Last 15 Dispatches</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ann-table" id="auditTable">
                        <thead class="table-light">
                            <tr>
                                <th>Timestamp</th>
                                <th>Notification Title & Snippet</th>
                                <th>Channel</th>
                                <th>Recipients Reached</th>
                                <th>Dispatched By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentNotifs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted small">No notifications broadcasted yet in this academic session.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentNotifs as $rn): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark" style="font-size:0.8rem;"><?php echo date('d M Y', strtotime($rn['created_at'])); ?></div>
                                            <small class="text-muted" style="font-size:0.72rem;"><?php echo date('h:i A', strtotime($rn['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <strong class="text-dark d-block" style="font-size:0.84rem;"><?php echo esc($rn['title']); ?></strong>
                                            <span class="text-muted text-truncate d-block" style="font-size:0.76rem; max-width: 320px;">
                                                <?php echo esc($rn['message']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.7rem;">
                                                <i class="fa-solid fa-mobile-screen me-1"></i> In-Portal
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 font-monospace" style="font-size:0.76rem;">
                                                <i class="fa-solid fa-users me-1"></i> <?php echo (int)$rn['total_recipients']; ?> Students
                                            </span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?php echo esc($rn['sender_name']); ?></div>
                                            <small class="text-muted" style="font-size:0.7rem;">Officer ID: #<?php echo $rn['sender_id']; ?></small>
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

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    // Audience radio toggles
    $('.target-radio').on('change', function() {
        const val = $('input[name="target_group"]:checked').val();
        if (val === 'dept_batch') {
            $('#filterSection').removeClass('d-none');
            $('#eligibilitySection').addClass('d-none');
        } else if (val === 'eligible_only') {
            $('#eligibilitySection').removeClass('d-none');
            $('#filterSection').addClass('d-none');
        } else {
            $('#filterSection').addClass('d-none');
            $('#eligibilitySection').addClass('d-none');
        }
    });

    updateLivePreview();
    updateWaBubble();
});

function applyTemplate(type) {
    const titleInput = $('#notifTitle');
    const bodyInput = $('#notifBody');
    const waInput = $('#waText');

    if (type === 'drive') {
        titleInput.val('Placement Alert: Upcoming Campus Recruitment Drive');
        bodyInput.val('We are pleased to inform you that the registration for upcoming corporate recruitment drive is open. Eligible candidates are advised to verify details and register prior to application deadlines.');
        waInput.val('*MAMCET Placement Alert*\n\nUpcoming campus recruitment drive registration is now OPEN. Eligible candidates are requested to verify their criteria and apply before deadline.\n\nLogin: https://portal.mamcet.org');
    } else if (type === 'profile') {
        titleInput.val('Warning: Review and Verify Profile Academics');
        bodyInput.val('Placement records check indicates incomplete details or mismatch in CGPA backlogs parameters. Please log into the portal and update profile fields immediately to avoid automated drive lockouts.');
        waInput.val('*MAMCET Placement Cell: Profile Action Required*\n\nYour profile has missing academic details or unverified CGPA. Please log into the portal and update fields immediately.');
    } else if (type === 'course') {
        titleInput.val('Academic Portal: LMS Learning Module Deadline');
        bodyInput.val('Please complete pending assignments and course chapters inside LMS dashboard prior to scheduled placement training sessions. Progress rates are mapped to candidate evaluation scores.');
        waInput.val('*MAMCET LMS Reminder*\n\nPlease complete your pending LMS learning modules and practice quizzes before the upcoming corporate test evaluation.');
    } else if (type === 'interview') {
        titleInput.val('Interview Schedule: Technical & HR Round');
        bodyInput.val('Shortlisted candidates for the next round of interview are requested to be present in formal attire with 2 copies of updated resumes at Placement Seminar Hall by 09:00 AM.');
        waInput.val('*MAMCET Interview Alert*\n\nShortlisted candidates for Technical/HR interviews: Please report at Seminar Hall by 09:00 AM with updated resumes.');
    }

    updateLivePreview();
    updateWaBubble();
}

function updateLivePreview() {
    const title = $('#notifTitle').val().trim() || 'Drive Alert: Sample Recruitment Drive';
    const body = $('#notifBody').val().trim() || 'Message details typed on the left will render here live for testing student readability.';
    
    $('#previewTitle').text(title);
    $('#previewBody').text(body);
    $('#titleCharCount').text($('#notifTitle').val().length + ' / 120');
}

function updateWaBubble() {
    const text = $('#waText').val().trim() || 'Dear Students, Zoho Corporation drive registration details...';
    $('#waBubble').text(text);
}

function switchRightTab(tab) {
    if (tab === 'preview') {
        $('#panePreview').removeClass('d-none');
        $('#paneWhatsApp').addClass('d-none');
        $('#tabBtnPreview').addClass('active');
        $('#tabBtnWa').removeClass('active');
    } else {
        $('#panePreview').addClass('d-none');
        $('#paneWhatsApp').removeClass('d-none');
        $('#tabBtnPreview').removeClass('active');
        $('#tabBtnWa').addClass('active');
    }
}

function launchWhatsApp() {
    const mobile = $('#waMobile').val().trim();
    const text = $('#waText').val().trim();

    if (!mobile || !text) {
        alert('Please provide recipient mobile and message context.');
        return;
    }

    const encodedText = encodeURIComponent(text);
    const url = `https://api.whatsapp.com/send?phone=${mobile}&text=${encodedText}`;
    window.open(url, '_blank');
}

function copyWaText() {
    const text = $('#waText').val().trim();
    if (!text) return;
    navigator.clipboard.writeText(text).then(function() {
        alert('WhatsApp formatted message copied to clipboard!');
    });
}
</script>
