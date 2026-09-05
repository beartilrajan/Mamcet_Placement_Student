<?php
// MAMCET Placement & Learning Portal - Student Training Catalog & Attendance Page

$pageTitle = 'My Training Programs';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
require_once(__DIR__ . '/../services/TrainingService.php');

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$trainingService = new TrainingService($db);
$statusFilter = trim($_GET['status'] ?? '');

$trainings = $trainingService->getStudentEligibleTrainings((int)$student['student_id'], (int)$student['dept_id'], ['status' => $statusFilter]);

// Counts for filter pills
$allCount = count($trainingService->getStudentEligibleTrainings((int)$student['student_id'], (int)$student['dept_id']));
$activeCount = count($trainingService->getStudentEligibleTrainings((int)$student['student_id'], (int)$student['dept_id'], ['status' => 'Active']));
$upcomingCount = count($trainingService->getStudentEligibleTrainings((int)$student['student_id'], (int)$student['dept_id'], ['status' => 'Upcoming']));
$completedCount = count($trainingService->getStudentEligibleTrainings((int)$student['student_id'], (int)$student['dept_id'], ['status' => 'Completed']));
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">My Training Programs</h1>
                <p class="text-muted mb-0">Join interactive workshops, webinars, and skill bootcamps assigned to your department (<strong><?php echo esc($student['dept_code']); ?></strong>).</p>
            </div>
            
            <!-- Filter Tabs -->
            <ul class="nav nav-pills bg-white p-1 rounded-3 border shadow-sm" id="trainingStatusTabs">
                <li class="nav-item">
                    <a class="nav-link fw-semibold px-3 py-1 text-nowrap <?php echo empty($statusFilter) ? 'active' : ''; ?>" href="trainings.php" style="font-size: 0.85rem;">
                        All (<?php echo $allCount; ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-semibold px-3 py-1 text-nowrap <?php echo $statusFilter === 'Active' ? 'active' : ''; ?>" href="trainings.php?status=Active" style="font-size: 0.85rem;">
                        <span class="spinner-grow spinner-grow-sm text-success me-1" role="status" style="width: 7px; height: 7px;"></span> Live (<?php echo $activeCount; ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-semibold px-3 py-1 text-nowrap <?php echo $statusFilter === 'Upcoming' ? 'active' : ''; ?>" href="trainings.php?status=Upcoming" style="font-size: 0.85rem;">
                        Upcoming (<?php echo $upcomingCount; ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-semibold px-3 py-1 text-nowrap <?php echo $statusFilter === 'Completed' ? 'active' : ''; ?>" href="trainings.php?status=Completed" style="font-size: 0.85rem;">
                        Completed (<?php echo $completedCount; ?>)
                    </a>
                </li>
            </ul>
        </div>

        <!-- Training Programs Grid -->
        <?php if (empty($trainings)): ?>
            <div class="card border-0 shadow-sm rounded-3 bg-white p-5 text-center">
                <div class="py-4">
                    <i class="fa-solid fa-chalkboard-user fa-3x text-secondary opacity-50 mb-3 d-block"></i>
                    <h5 class="fw-bold">No Training Programs Available</h5>
                    <p class="text-muted small mb-0">There are no active or scheduled trainings currently assigned to your department.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($trainings as $t): ?>
                    <?php
                        $computedStatus = $t['computed_status'] ?? 'Upcoming';
                        $statusBadgeClass = 'bg-secondary';
                        if ($computedStatus === 'Active') $statusBadgeClass = 'bg-success';
                        elseif ($computedStatus === 'Upcoming') $statusBadgeClass = 'bg-info text-dark';
                        elseif ($computedStatus === 'Completed') $statusBadgeClass = 'bg-primary';

                        $myAtt = $t['my_attendance_status'] ?? 'Pending';
                        $attBadge = 'bg-secondary-subtle text-secondary border';
                        if ($myAtt === 'Present') $attBadge = 'bg-success text-white';
                        elseif ($myAtt === 'Late') $attBadge = 'bg-warning text-dark';
                        elseif ($myAtt === 'Excused') $attBadge = 'bg-info text-dark';
                        elseif ($myAtt === 'Absent') $attBadge = 'bg-danger-subtle text-danger border border-danger-subtle';
                        $eventType = $t['event_type'] ?? 'Online';
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="card border-0 shadow-sm rounded-3 h-100 bg-white overflow-hidden d-flex flex-column justify-content-between">
                            <div>
                                <!-- Image or Banner -->
                                <div style="height: 140px; background: linear-gradient(135deg, #0B3D91, #1e40af); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                                    <?php if (!empty($t['thumbnail_url'])): ?>
                                        <img src="<?php echo esc($t['thumbnail_url']); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="text-white opacity-25 text-center">
                                            <i class="fa-solid fa-graduation-cap fa-4x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span class="badge <?php echo $statusBadgeClass; ?> position-absolute top-0 end-0 m-3 shadow-sm">
                                        <?php echo esc($computedStatus); ?>
                                    </span>
                                </div>

                                <div class="p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-light text-dark border small">
                                            <i class="fa-solid fa-building-columns me-1"></i><?php echo esc($t['eligible_departments']); ?>
                                        </span>
                                        <span class="badge <?php echo $attBadge; ?> small px-2 py-1">
                                            <i class="fa-solid fa-user-check me-1"></i><?php echo esc($myAtt); ?>
                                        </span>
                                    </div>

                                    <h5 class="fw-bold text-dark mb-1 text-truncate-2" style="font-size: 1.05rem; min-height: 2.6rem;">
                                        <?php echo esc($t['title']); ?>
                                    </h5>

                                    <?php if (!empty($t['trainer_name'])): ?>
                                        <div class="text-secondary small mb-2">
                                            <i class="fa-regular fa-user-circle me-1"></i><?php echo esc($t['trainer_name']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <p class="text-muted small mb-3 text-truncate-2" style="height: 2.4rem; overflow: hidden; font-size: 0.82rem;">
                                        <?php echo esc($t['description'] ?: 'External training module with verified attendance tracking.'); ?>
                                    </p>

                                    <!-- Event details -->
                                    <div class="bg-light rounded-3 p-2 small mb-3 border">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="text-muted"><i class="fa-regular fa-calendar me-1"></i>Starts:</span>
                                            <span class="fw-semibold text-dark"><?php echo date('M d, Y h:i A', strtotime($t['start_date_time'])); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="text-muted"><i class="fa-regular fa-clock me-1"></i>Ends:</span>
                                            <span class="fw-semibold text-dark"><?php echo date('M d, Y h:i A', strtotime($t['end_date_time'])); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted"><i class="fa-solid fa-<?php echo $eventType === 'Offline' ? 'location-dot' : 'wifi'; ?> me-1"></i>Event:</span>
                                            <span class="fw-semibold text-primary"><?php echo esc($eventType); ?></span>
                                        </div>
                                        <?php if ($eventType === 'Offline' && !empty($t['venue_location'])): ?>
                                            <div class="d-flex align-items-center justify-content-between mt-1">
                                                <span class="text-muted"><i class="fa-solid fa-location-dot me-1"></i>Venue:</span>
                                                <span class="fw-semibold text-dark text-end ms-2"><?php echo esc($t['venue_location']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3 pt-0">
                                <a href="training-view.php?id=<?php echo $t['training_id']; ?>" class="btn <?php echo $myAtt === 'Present' ? 'btn-outline-success' : 'btn-primary'; ?> w-100 shadow-sm">
                                    <?php if ($myAtt === 'Present'): ?>
                                        <i class="fa-solid fa-circle-check me-1"></i> View Training & Attendance
                                    <?php elseif ($computedStatus === 'Active' && $eventType === 'Online'): ?>
                                        <i class="fa-solid fa-play me-1"></i> Launch Training Now
                                    <?php else: ?>
                                        <i class="fa-solid fa-arrow-right me-1"></i> View Training Details
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
