<?php
// MAMCET Placement & Learning Portal - Admin College Event Manager

$pageTitle = 'College Event Manager';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Admin or Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$sessionId = getActiveAcademicSessionId();

// Fetch events for active session
$stmtEv = $db->prepare("
    SELECT e.*, u.username 
    FROM college_events e
    LEFT JOIN users u ON e.created_by = u.user_id
    WHERE e.session_id = ?
    ORDER BY e.start_date DESC
");
$stmtEv->execute([$sessionId]);
$eventsList = $stmtEv->fetchAll();
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">College Event & Calendar Manager</h1>
                <p class="text-muted mb-0">Publish campus events, exam schedules, academic deadlines, and workshops synced to student portals.</p>
            </div>
            
            <button type="button" class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#createEventModal">
                <i class="fa-solid fa-plus me-1"></i> Add New Event
            </button>
        </div>

        <div class="mamcet-card mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-calendar-days text-primary me-2"></i> Published Events List</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 datatable">
                        <thead class="table-light">
                            <tr>
                                <th>Event Title</th>
                                <th>Type</th>
                                <th>Start Date & Time</th>
                                <th>Location</th>
                                <th>Posted By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventsList as $ev): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark d-block"><?php echo esc($ev['title']); ?></span>
                                        <small class="text-muted"><?php echo esc(substr($ev['description'] ?? '', 0, 60)); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $t = $ev['event_type'];
                                        $badge = $t === 'Placement Drive' ? 'bg-success' : ($t === 'Academic Deadline' || $t === 'Exam Schedule' ? 'bg-danger' : 'bg-primary');
                                        ?>
                                        <span class="badge <?php echo $badge; ?>"><?php echo esc($t); ?></span>
                                    </td>
                                    <td class="small fw-semibold"><?php echo date('M d, Y h:i A', strtotime($ev['start_date'])); ?></td>
                                    <td class="small"><?php echo esc($ev['location'] ?: 'Campus'); ?></td>
                                    <td class="small text-muted"><?php echo esc($ev['username'] ?: 'Admin'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger delete-event-btn" data-id="<?php echo $ev['event_id']; ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- CREATE EVENT MODAL -->
<div class="modal fade" id="createEventModal" tabindex="-1" aria-labelledby="createEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark" id="createEventModalLabel">
                    <i class="fa-solid fa-calendar-plus text-primary me-2"></i> Add College Event
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createEventForm" action="../api/events.php" method="POST">
                <input type="hidden" name="action" value="create">
                <?php csrfInput(); ?>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark">Event Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Annual Tech Symposium 2026" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark">Event Category *</label>
                        <select name="event_type" class="form-select" required>
                            <option value="Campus Event">Campus Event</option>
                            <option value="Placement Drive">Placement Drive</option>
                            <option value="Academic Deadline">Academic Deadline</option>
                            <option value="Exam Schedule">Exam Schedule</option>
                            <option value="Holiday">Holiday</option>
                            <option value="Workshop">Workshop</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">Start Date & Time *</label>
                            <input type="datetime-local" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">End Date & Time</label>
                            <input type="datetime-local" name="end_date" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark">Location / Venue</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Main Auditorium / Lab 3">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark">Description Details</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Provide event instructions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top p-3">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4">Publish Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    $('.datatable').DataTable();

    // Create Event AJAX
    $('#createEventForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Event Created',
                        text: res.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => window.location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }
        });
    });

    // Delete Event AJAX
    $('.delete-event-btn').on('click', function() {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete Event?',
            text: 'This event will be permanently removed from all student calendars.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '../api/events.php',
                    type: 'POST',
                    data: { action: 'delete', event_id: id },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire('Deleted!', res.message, 'success').then(() => window.location.reload());
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    }
                });
            }
        });
    });
});
</script>
