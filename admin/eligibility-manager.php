<?php
// MAMCET Placement & Learning Portal - Placement Eligibility Manager
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$selectedJobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$jobs = $db->query("SELECT job_id, company_name, job_title FROM job_descriptions ORDER BY job_id DESC")->fetchAll();

// Handle Manual Override submit
if (isset($_POST['submit_override'])) {
    try {
        $studentId = (int)$_POST['student_id'];
        $jobId = (int)$_POST['job_id'];
        $action = $_POST['override_action']; // 'override' or 'block' or 'remove'
        $reason = trim($_POST['override_reason'] ?? '');

        if ($action === 'remove') {
            $stmtDel = $db->prepare("DELETE FROM eligibility_overrides WHERE student_id = ? AND job_id = ?");
            $stmtDel->execute([$studentId, $jobId]);
            $msgAction = "Override removed";
        } else {
            if (empty($reason)) {
                throw new Exception("Please specify an audit reason for the manual override.");
            }
            $isOverride = ($action === 'override') ? 1 : 0;
            $stmtIns = $db->prepare("
                INSERT INTO eligibility_overrides (student_id, job_id, is_override, reason, updated_by) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE is_override = VALUES(is_override), reason = VALUES(reason), updated_by = VALUES(updated_by), updated_at = NOW()
            ");
            $stmtIns->execute([$studentId, $jobId, $isOverride, $reason, $_SESSION['user_id']]);
            $msgAction = "Override saved (" . $action . ")";
        }

        // Clear cached eligibility status
        $db->prepare("DELETE FROM eligibility_cache WHERE student_id = ? AND job_id = ?")->execute([$studentId, $jobId]);

        // Insert audit log
        $stmtAudit = $db->prepare("INSERT INTO eligibility_audit_logs (student_id, job_id, action, reason, updated_by) VALUES (?, ?, ?, ?, ?)");
        $stmtAudit->execute([$studentId, $jobId, $action, $reason, $_SESSION['user_id']]);

        $message = "Manual eligibility adjustment successful: " . $msgAction;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 2. Fetch candidates roster if Job selected
$studentsList = [];
if ($selectedJobId > 0) {
    // Get all students
    $stmtSt = $db->query("
        SELECT s.student_id, s.student_name, s.register_number, s.placement_willingness,
               d.dept_code, b.batch_name
        FROM students s
        JOIN departments d ON s.dept_id = d.dept_id
        JOIN batches b ON s.batch_id = b.batch_id
        ORDER BY s.student_name ASC
    ");
    $studentsList = $stmtSt->fetchAll();
}

// Query eligibility service helper
require_once(__DIR__ . '/../services/EligibilityService.php');
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800"><i class="fa-solid fa-user-shield text-primary"></i> Automated Eligibility Manager</h1>

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

            <!-- Job selector drop menu -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-8">
                            <label class="form-label font-weight-bold text-muted">Select Campus Drive / Job Description</label>
                            <select class="form-select" name="job_id" required>
                                <option value="">-- Choose Corporate Opportunity --</option>
                                <?php foreach ($jobs as $j): ?>
                                    <option value="<?php echo $j['job_id']; ?>" <?php echo $selectedJobId === (int)$j['job_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($j['company_name']); ?> - <?php echo htmlspecialchars($j['job_title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold">
                                <i class="fa-solid fa-magnifying-glass"></i> Scan Candidates
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- CANDIDATES ROSTER TABLE -->
            <?php if ($selectedJobId > 0): ?>
                <div class="card shadow mb-4">
                    <div class="card-header bg-dark text-white py-3">
                        <h6 class="m-0 font-weight-bold">Scan Results for Selected Campus Drive</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="eligibilityTable" width="100%">
                                <thead>
                                    <tr>
                                        <th>Reg. Number</th>
                                        <th>Name</th>
                                        <th>Branch</th>
                                        <th>Batch</th>
                                        <th>Automated Status</th>
                                        <th>Override Adjustment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($studentsList as $st): 
                                        // Calculate dynamic eligibility
                                        $eligData = EligibilityService::calculateStudentEligibility($db, (int)$st['student_id'], $selectedJobId);
                                    ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($st['register_number']); ?></code></td>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($st['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($st['dept_code']); ?></td>
                                            <td><?php echo htmlspecialchars($st['batch_name']); ?></td>
                                            <td>
                                                <?php if ($eligData['status'] === 'Eligible'): ?>
                                                    <span class="badge bg-success">Eligible</span>
                                                <?php elseif ($eligData['status'] === 'Officer Override'): ?>
                                                    <span class="badge bg-primary">Officer Override (Eligible)</span>
                                                <?php elseif ($eligData['status'] === 'Officer Blocked'): ?>
                                                    <span class="badge bg-danger">Officer Blocked (Ineligible)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Ineligible</span>
                                                    <small class="d-block text-danger mt-1" style="font-size:0.75rem;">
                                                        <?php echo implode(', ', $eligData['reasons']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-secondary open-override-modal" 
                                                        data-student-id="<?php echo $st['student_id']; ?>"
                                                        data-student-name="<?php echo htmlspecialchars($st['student_name']); ?>"
                                                        data-status="<?php echo htmlspecialchars($eligData['status']); ?>">
                                                    <i class="fa-solid fa-gears"></i> Adjust
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

    </div>
</div>

<!-- OVERRIDE MODAL TEMPLATE -->
<div class="modal fade" id="overrideModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title font-weight-bold">Manual Eligibility Override</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="student_id" id="modalStudentId">
        <input type="hidden" name="job_id" value="<?php echo $selectedJobId; ?>">
        
        <p>Adjusting candidate: <strong id="modalStudentName" class="text-primary"></strong></p>
        <p>Current Status: <span id="modalCurrentStatus" class="badge"></span></p>
        
        <div class="mb-3">
            <label class="form-label font-weight-bold">Override Action</label>
            <select class="form-select" name="override_action" required>
                <option value="override">Allow Application (Manual Override)</option>
                <option value="block">Block Application (Lock Out)</option>
                <option value="remove">Remove Manual Override (Default Rules)</option>
            </select>
        </div>
        
        <div class="mb-3">
            <label class="form-label font-weight-bold">Audit Reason (Required)</label>
            <input type="text" name="override_reason" class="form-control" placeholder="Specify override credentials or logic" required>
            <small class="text-muted">This justification is stored in eligibility audit logs for compliance tracking.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="submit_override" class="btn btn-primary font-weight-bold">Save Override</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    if ($('#eligibilityTable').find('tbody tr').length > 0) {
        $('#eligibilityTable').DataTable({
            pageLength: 25
        });
    }

    $('.open-override-modal').on('click', function() {
        const btn = $(this);
        const sId = btn.data('student-id');
        const sName = btn.data('student-name');
        const status = btn.data('status');

        $('#modalStudentId').val(sId);
        $('#modalStudentName').text(sName);
        
        // Status styling
        const stBadge = $('#modalCurrentStatus');
        stBadge.text(status);
        stBadge.removeClass('bg-success bg-danger bg-warning bg-primary');
        if (status === 'Eligible') stBadge.addClass('bg-success');
        else if (status === 'Officer Override') stBadge.addClass('bg-primary');
        else if (status === 'Officer Blocked') stBadge.addClass('bg-danger');
        else stBadge.addClass('bg-warning text-dark');

        const overrideModal = new bootstrap.Modal(document.getElementById('overrideModal'));
        overrideModal.show();
    });
});
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
