<?php
// MAMCET Placement & Learning Portal - Student Training Details & Attendance Status

$trainingId = (int)($_GET['id'] ?? 0);
if ($trainingId <= 0) {
    header('Location: trainings.php');
    exit;
}

$pageTitle = 'Training Details';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
require_once(__DIR__ . '/../services/TrainingService.php');

requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);
if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$trainingService = new TrainingService($db);
$access = $trainingService->verifyStudentAccess($trainingId, (int)$student['student_id']);
if (!$access['allowed']) {
    http_response_code(403);
    echo "<div class='main-content'><div class='page-container'><div class='card border-0 shadow-sm p-5 text-center bg-white'><div class='text-danger mb-3'><i class='fa-solid fa-ban fa-3x'></i></div><h4 class='fw-bold'>Access Restricted</h4><p class='text-muted'>" . esc($access['message']) . "</p><a href='trainings.php' class='btn btn-primary btn-sm'>Return to Training Catalog</a></div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$training = $access['training'];
$stmtAtt = $db->prepare('SELECT * FROM training_attendance WHERE training_id = ? AND student_id = ?');
$stmtAtt->execute([$trainingId, (int)$student['student_id']]);
$attendance = $stmtAtt->fetch(PDO::FETCH_ASSOC) ?: [];

$currentStatus = in_array(($attendance['status'] ?? ''), ['Partial', 'In Progress'], true)
    ? 'Pending'
    : ($attendance['status'] ?? 'Pending');
$statusClasses = [
    'Present' => 'bg-success',
    'Late' => 'bg-warning text-dark',
    'Excused' => 'bg-info text-dark',
    'Absent' => 'bg-danger-subtle text-danger border border-danger-subtle',
    'Pending' => 'bg-secondary-subtle text-secondary border'
];
$statusIcons = [
    'Present' => 'fa-circle-check',
    'Late' => 'fa-clock',
    'Excused' => 'fa-circle-info',
    'Absent' => 'fa-circle-xmark',
    'Pending' => 'fa-hourglass-half'
];
$statusClass = $statusClasses[$currentStatus] ?? $statusClasses['Pending'];
$statusIcon = $statusIcons[$currentStatus] ?? $statusIcons['Pending'];
$computedStatus = $training['computed_status'] ?? $training['status'];
$eventType = $training['event_type'] ?? 'Online';
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="page-container">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="trainings.php" class="text-decoration-none">My Trainings</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo esc($training['title']); ?></li>
                </ol>
            </nav>
            <a href="trainings.php" class="btn btn-light btn-sm border"><i class="fa-solid fa-arrow-left me-1"></i> Back to Catalog</a>
        </div>

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm rounded-3 bg-white overflow-hidden">
                    <div class="card-header bg-primary text-white p-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <span class="badge bg-white text-primary small mb-2 fw-bold"><?php echo esc($eventType); ?> Event</span>
                            <h4 class="fw-bold mb-1 text-white"><?php echo esc($training['title']); ?></h4>
                            <?php if (!empty($training['trainer_name'])): ?>
                                <small class="text-white-50"><i class="fa-regular fa-user-circle me-1"></i>Trainer: <?php echo esc($training['trainer_name']); ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="badge <?php echo $statusClass; ?> fs-6 px-3 py-2 shadow-sm">
                            <i class="fa-solid <?php echo $statusIcon; ?> me-1"></i><?php echo esc($currentStatus); ?>
                        </span>
                    </div>

                    <div class="card-body p-4">
                        <?php if ($eventType === 'Online' && !empty($training['training_url'])): ?>
                            <div class="p-4 rounded-3 border bg-light text-center mb-4">
                                <span class="badge bg-primary-subtle text-primary border px-3 py-1 rounded-pill small mb-3"><i class="fa-solid fa-wifi me-1"></i> Online Training</span>
                                <h5 class="fw-bold mb-2">Join the online training</h5>
                                <p class="text-muted small mb-4">Open the trainer’s online session using the link below. Opening the link does not mark attendance automatically.</p>
                                <a href="<?php echo esc($training['training_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-lg shadow px-4">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-2"></i> Open Online Training
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="p-4 rounded-3 border bg-light text-center mb-4">
                                <span class="badge bg-warning-subtle text-dark border px-3 py-1 rounded-pill small mb-3"><i class="fa-solid fa-location-dot me-1"></i> Offline Training</span>
                                <h5 class="fw-bold mb-2">Attend at the scheduled venue</h5>
                                <p class="text-muted small mb-2">Venue / Location</p>
                                <div class="fs-5 fw-semibold text-dark"><?php echo esc($training['venue_location'] ?: 'Venue will be announced by the organizer.'); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($training['description'])): ?>
                            <div class="mb-3">
                                <h6 class="fw-bold mb-2">Instructions & Agenda</h6>
                                <div class="p-3 bg-light rounded-3 small text-secondary"><?php echo nl2br(esc($training['description'])); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-info border-0 mb-0 small">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Your attendance is marked manually by the trainer. This page will show the status recorded by the trainer.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm rounded-3 bg-white p-3 mb-4">
                    <h6 class="fw-bold mb-3 border-bottom pb-2">Training Overview</h6>
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Event Type</span><strong><?php echo esc($eventType); ?></strong></li>
                        <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Status</span><span class="badge <?php echo $computedStatus === 'Active' ? 'bg-success' : 'bg-primary'; ?>"><?php echo esc($computedStatus); ?></span></li>
                        <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Starts</span><strong><?php echo date('M d, Y h:i A', strtotime($training['start_date_time'])); ?></strong></li>
                        <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Ends</span><strong><?php echo date('M d, Y h:i A', strtotime($training['end_date_time'])); ?></strong></li>
                        <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Attendance</span><span class="badge <?php echo $statusClass; ?>"><i class="fa-solid <?php echo $statusIcon; ?> me-1"></i><?php echo esc($currentStatus); ?></span></li>
                        <li class="d-flex justify-content-between py-2"><span class="text-muted">Marked At</span><strong><?php echo !empty($attendance['marked_at']) ? date('M d, Y h:i A', strtotime($attendance['marked_at'])) : 'Pending trainer update'; ?></strong></li>
                    </ul>
                </div>

                <?php if (!empty($attendance['marked_by']) || !empty($attendance['attendance_note'])): ?>
                    <div class="card border-0 shadow-sm rounded-3 bg-white p-3">
                        <h6 class="fw-bold mb-2 text-primary"><i class="fa-solid fa-clipboard-check me-1"></i> Trainer Update</h6>
                        <?php if (!empty($attendance['marked_by'])): ?><div class="small text-muted mb-2">Marked by <strong class="text-dark"><?php echo esc($attendance['marked_by']); ?></strong></div><?php endif; ?>
                        <?php if (!empty($attendance['attendance_note'])): ?><div class="small text-secondary bg-light rounded-3 p-2"><?php echo nl2br(esc($attendance['attendance_note'])); ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
