<?php
// MAMCET Placement & Learning Portal - Notification Center
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();
$drives = $db->query("
    SELECT po.opportunity_id, po.job_id, jd.company_name, jd.job_title 
    FROM placement_opportunities po 
    JOIN job_descriptions jd ON po.job_id = jd.job_id 
    WHERE po.status = 'active'
")->fetchAll();

// Handle Submit
if (isset($_POST['send_notification'])) {
    try {
        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        $target = $_POST['target_group']; // 'all', 'dept_batch', 'eligible_only'
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;

        if (empty($title) || empty($body)) {
            throw new Exception("Title and message body are required.");
        }

        $recipientUserIds = [];

        if ($target === 'all') {
            // Get all student user ids
            $stmt = $db->query("SELECT user_id FROM students");
            $recipientUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target === 'dept_batch') {
            $deptId = (int)$_POST['dept_id'];
            $batchId = (int)$_POST['batch_id'];
            
            $sql = "SELECT user_id FROM students WHERE 1=1";
            $params = [];
            if ($deptId > 0) {
                $sql .= " AND dept_id = ? ";
                $params[] = $deptId;
            }
            if ($batchId > 0) {
                $sql .= " AND batch_id = ? ";
                $params[] = $batchId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $recipientUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target === 'eligible_only') {
            $oppId = (int)$_POST['opportunity_id'];
            
            // Get Job ID
            $stmtJob = $db->prepare("SELECT job_id FROM placement_opportunities WHERE opportunity_id = ?");
            $stmtJob->execute([$oppId]);
            $jobId = (int)$stmtJob->fetchColumn();

            if (!$jobId) {
                throw new Exception("Selected campus drive opportunity not found.");
            }

            // Loop and scan eligibility for all students
            require_once(__DIR__ . '/../services/EligibilityService.php');
            $stmtSt = $db->query("SELECT student_id, user_id FROM students");
            $allSt = $stmtSt->fetchAll();

            foreach ($allSt as $st) {
                $eligData = EligibilityService::calculateStudentEligibility($db, (int)$st['student_id'], $jobId);
                if ($eligData['is_eligible'] == 1) {
                    $recipientUserIds[] = (int)$st['user_id'];
                }
            }
        }

        if (empty($recipientUserIds)) {
            throw new Exception("No recipient students found matching filters.");
        }

        // Send in portal & queue email
        require_once(__DIR__ . '/../services/NotificationService.php');
        
        $db->beginTransaction();
        NotificationService::dispatchNotification($db, $recipientUserIds, $title, $body);

        if ($sendEmail) {
            NotificationService::dispatchEmailGroup($db, $recipientUserIds, $title, $body);
        }
        $db->commit();

        $message = "Notification dispatched to " . count($recipientUserIds) . " students successfully! " . ($sendEmail ? "Emails have been queued." : "");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-paper-plane text-primary"></i> Multi-Channel Notification Center</h1>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left: Send Notification Form -->
                <div class="col-lg-7 mb-4">
                    <form method="POST" class="card shadow">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="m-0 font-weight-bold">Compose Alert Message</h6>
                        </div>
                        <div class="card-body">
                            <!-- Template dropdown selector -->
                            <div class="mb-3">
                                <label class="form-label font-weight-bold text-muted">Use Template</label>
                                <select class="form-select" id="templateSelector">
                                    <option value="">-- Choose Template (Optional) --</option>
                                    <option value="drive">Campus Recruitment Drive invitation</option>
                                    <option value="profile">Profile Details verification warning</option>
                                    <option value="course">Learning LMS deadline reminder</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Notification Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="notifTitle" class="form-control" placeholder="e.g. Drive Alert: Zoho Recruitment Drive" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Message Details <span class="text-danger">*</span></label>
                                <textarea name="body" id="notifBody" class="form-control" rows="5" placeholder="Type message context here..." required></textarea>
                            </div>

                            <!-- Target selector -->
                            <div class="mb-3">
                                <label class="form-label font-weight-bold text-muted d-block">Recipient Students Group</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input target-radio" type="radio" name="target_group" id="targetAll" value="all" checked>
                                    <label class="form-check-label" for="targetAll">All Student Registry</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input target-radio" type="radio" name="target_group" id="targetFilter" value="dept_batch">
                                    <label class="form-check-label" for="targetFilter">Select Dept / Batch</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input target-radio" type="radio" name="target_group" id="targetElig" value="eligible_only">
                                    <label class="form-check-label" for="targetElig">Only Eligible for Drive</label>
                                </div>
                            </div>

                            <!-- Filter parameters Section (Conditional) -->
                            <div class="p-3 bg-light rounded border mb-3 d-none" id="filterSection">
                                <div class="row">
                                    <div class="col-6">
                                        <label class="form-label font-weight-bold">Department</label>
                                        <select class="form-select form-select-sm" name="dept_id">
                                            <option value="0">All Departments</option>
                                            <?php foreach ($departments as $d): ?>
                                                <option value="<?php echo $d['dept_id']; ?>"><?php echo htmlspecialchars($d['dept_code']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label font-weight-bold">Batch</label>
                                        <select class="form-select form-select-sm" name="batch_id">
                                            <option value="0">All Batches</option>
                                            <?php foreach ($batches as $b): ?>
                                                <option value="<?php echo $b['batch_id']; ?>"><?php echo htmlspecialchars($b['batch_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Job Eligibility Section (Conditional) -->
                            <div class="p-3 bg-light rounded border mb-3 d-none" id="eligibilitySection">
                                <label class="form-label font-weight-bold">Filter by Drive Eligibility</label>
                                <select class="form-select form-select-sm" name="opportunity_id">
                                    <option value="">-- Select Active Drive --</option>
                                    <?php foreach ($drives as $dr): ?>
                                        <option value="<?php echo $dr['opportunity_id']; ?>"><?php echo htmlspecialchars($dr['company_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Email toggle checkbox -->
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmailCheckbox">
                                    <label class="form-check-label font-weight-bold text-muted" for="sendEmailCheckbox">
                                        Also dispatch SMTP E-mail alert queue.
                                    </label>
                                </div>
                            </div>

                            <button type="submit" name="send_notification" class="btn btn-primary w-100 font-weight-bold">
                                <i class="fa-solid fa-paper-plane"></i> Send Notification
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right: WhatsApp Reminders generator panel -->
                <div class="col-lg-5">
                    <div class="card shadow">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="m-0 font-weight-bold"><i class="fa-brands fa-whatsapp"></i> Quick WhatsApp Reminder Builder</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted" style="font-size:0.85rem;">Draft reminders to generate pre-filled Click-to-Chat links. Easily share drive details with class coordinators or students.</p>
                            
                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Recipient Mobile (91+)</label>
                                <input type="text" id="waMobile" class="form-control" placeholder="919876543210" value="91">
                            </div>

                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Message Context</label>
                                <textarea id="waText" class="form-control" rows="4" placeholder="Dear Students, Zoho Corporation drive registration details..."></textarea>
                            </div>

                            <button type="button" id="btnGenWa" class="btn btn-success w-100 font-weight-bold">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Generate and Open link
                            </button>
                        </div>
                    </div>
                </div>
            </div>

    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // 1. Target groups toggler
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

    // 2. Templates populator
    $('#templateSelector').on('change', function() {
        const val = $(this).val();
        const titleInput = $('#notifTitle');
        const bodyInput = $('#notifBody');

        if (val === 'drive') {
            titleInput.val('Placement Alert: Upcoming Campus Recruitment Drive');
            bodyInput.val('We are pleased to inform you that the registration for upcoming corporate recruitment drive is open. Eligible candidates are advised to verify details and register prior to application deadlines.');
        } else if (val === 'profile') {
            titleInput.val('Warning: Review and Verify Profile Academics');
            bodyInput.val('Placement records check indicates incomplete details or mismatch in CGPA backlogs parameters. Please log into the portal and update profile fields immediately to avoid automated drive lockouts.');
        } else if (val === 'course') {
            titleInput.val('Academic Portal: LMS Learning Module Deadline');
            bodyInput.val('Please complete pending assignments and course chapters inside LMS dashboard prior to scheduled placement training sessions. Progress rates are mapped to candidate evaluation scores.');
        } else {
            titleInput.val('');
            bodyInput.val('');
        }
    });

    // 3. WhatsApp click-to-chat generator
    $('#btnGenWa').on('click', function() {
        const mobile = $('#waMobile').val().trim();
        const text = $('#waText').val().trim();

        if (mobile === '' || text === '') {
            alert('Please input both recipient phone and text details.');
            return;
        }

        const encodedText = encodeURIComponent(text);
        const url = `https://api.whatsapp.com/send?phone=${mobile}&text=${encodedText}`;
        window.open(url, '_blank');
    });
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
