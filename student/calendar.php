<?php
// MAMCET Placement & Learning Portal - Student Real-time College Calendar Hub

$pageTitle = 'College Calendar';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">Real-time College Calendar</h1>
                <p class="text-muted mb-0">Track official campus events, placement drive schedules, exams, and academic deadlines.</p>
            </div>
            
            <!-- Category Legend -->
            <div class="d-flex gap-1 gap-md-2 flex-wrap">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size:0.72rem;">🟢 Placement Drive</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size:0.72rem;">🔵 Campus Event</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size:0.72rem;">🔴 Deadline / Exam</span>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1" style="font-size:0.72rem;">🟡 Workshop / Holiday</span>
            </div>
        </div>

        <div class="row g-3 g-md-4">
            <!-- Calendar Main Grid Card -->
            <div class="col-lg-8">
                <div class="mamcet-card p-3 p-md-4 shadow-sm" style="background:#ffffff; border-radius:16px;">
                    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-calendar-days text-primary me-2"></i> Monthly Campus Calendar
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="prevMonthBtn"><i class="fa-solid fa-chevron-left"></i></button>
                            <button type="button" class="btn btn-outline-secondary fw-bold disabled" id="currentMonthLabel">Month Year</button>
                            <button type="button" class="btn btn-outline-secondary" id="nextMonthBtn"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>

                    <!-- Interactive Monthly Grid -->
                    <div class="table-responsive">
                        <table class="table table-bordered text-center align-middle mb-0" id="calendarGridTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 14.28%;">Sun</th>
                                    <th style="width: 14.28%;">Mon</th>
                                    <th style="width: 14.28%;">Tue</th>
                                    <th style="width: 14.28%;">Wed</th>
                                    <th style="width: 14.28%;">Thu</th>
                                    <th style="width: 14.28%;">Fri</th>
                                    <th style="width: 14.28%;">Sat</th>
                                </tr>
                            </thead>
                            <tbody id="calendarGridBody">
                                <!-- Dynamically populated by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Upcoming Events Sidebar Feed -->
            <div class="col-lg-4">
                <div class="mamcet-card h-100 p-4 shadow-sm" style="background:#ffffff; border-radius:16px;">
                    <h5 class="fw-bold text-dark mb-3 border-bottom pb-3">
                        <i class="fa-solid fa-bullhorn text-warning me-2"></i> Upcoming Events & Deadlines
                    </h5>
                    
                    <div id="upcomingEventsFeed" class="d-flex flex-column gap-3">
                        <div class="text-center py-4 text-muted">
                            <i class="fa-solid fa-spinner fa-spin fa-2x mb-2 text-primary"></i>
                            <p class="mb-0">Loading synchronized calendar events...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- EVENT DETAILS MODAL -->
<div class="modal fade" id="eventDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark" id="modalEventTitle">Event Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <span class="badge mb-2" id="modalEventBadge">Category</span>
                <p class="text-muted small mb-3" id="modalEventTime"><i class="fa-regular fa-clock me-1"></i> Time</p>
                <p class="text-muted small mb-3" id="modalEventLocation"><i class="fa-solid fa-location-dot me-1"></i> Venue</p>
                <hr>
                <p class="text-dark mb-0" id="modalEventDesc">Description text...</p>
            </div>
            <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-secondary fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentDate = new Date();
    let eventsData = [];

    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

    const fetchEvents = () => {
        $.ajax({
            url: '../api/events.php',
            type: 'GET',
            data: { action: 'fetch' },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    eventsData = res.events;
                    renderCalendar();
                    renderUpcomingFeed();
                }
            }
        });
    };

    const renderCalendar = () => {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        document.getElementById('currentMonthLabel').textContent = `${monthNames[month]} ${year}`;

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        const gridBody = document.getElementById('calendarGridBody');
        gridBody.innerHTML = '';

        let dateCounter = 1;
        let html = '';

        for (let i = 0; i < 6; i++) {
            let row = '<tr>';
            for (let j = 0; j < 7; j++) {
                if (i === 0 && j < firstDay) {
                    row += '<td class="bg-light text-muted opacity-50 cal-cell"></td>';
                } else if (dateCounter > daysInMonth) {
                    row += '<td class="bg-light text-muted opacity-50 cal-cell"></td>';
                } else {
                    const fullDateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dateCounter).padStart(2, '0')}`;
                    
                    // Match events on this date
                    const dayEvents = eventsData.filter(ev => ev.start.startsWith(fullDateStr));
                    
                    let eventPills = '';
                    dayEvents.forEach(ev => {
                        eventPills += `<div class="badge ${ev.badgeClass} d-block text-truncate mb-1 p-1" style="font-size:0.65rem; cursor:pointer;" onclick="openEventModal('${ev.id}')">${ev.title}</div>`;
                    });

                    const isToday = new Date().toDateString() === new Date(year, month, dateCounter).toDateString();
                    const todayClass = isToday ? 'cal-cell-today' : '';

                    row += `<td class="p-2 cal-cell ${todayClass}" align="left" valign="top">
                        <span class="fw-bold ${isToday ? 'text-primary' : 'text-dark'}" style="font-size:0.85rem;">${dateCounter}</span>
                        <div class="mt-1">${eventPills}</div>
                    </td>`;
                    
                    dateCounter++;
                }
            }
            row += '</tr>';
            html += row;
            if (dateCounter > daysInMonth) break;
        }

        gridBody.innerHTML = html;
    };

    const renderUpcomingFeed = () => {
        const feedContainer = document.getElementById('upcomingEventsFeed');
        if (eventsData.length === 0) {
            feedContainer.innerHTML = '<div class="text-center py-4 text-muted"><p>No upcoming events posted.</p></div>';
            return;
        }

        let html = '';
        eventsData.slice(0, 5).forEach(ev => {
            html += `
                <div class="border rounded p-3 bg-light hover-lift" style="cursor:pointer;" onclick="openEventModal('${ev.id}')">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="fw-bold text-dark mb-0">${ev.title}</h6>
                        <span class="badge ${ev.badgeClass}">${ev.event_type}</span>
                    </div>
                    <small class="text-muted d-block"><i class="fa-regular fa-clock me-1"></i> ${ev.start}</small>
                    <small class="text-muted d-block"><i class="fa-solid fa-location-dot me-1"></i> ${ev.location}</small>
                </div>
            `;
        });
        feedContainer.innerHTML = html;
    };

    window.openEventModal = (id) => {
        const ev = eventsData.find(e => e.id === id);
        if (ev) {
            document.getElementById('modalEventTitle').textContent = ev.title;
            document.getElementById('modalEventBadge').className = `badge ${ev.badgeClass} mb-2`;
            document.getElementById('modalEventBadge').textContent = ev.event_type;
            document.getElementById('modalEventTime').innerHTML = `<i class="fa-regular fa-clock me-1"></i> ${ev.start} - ${ev.end}`;
            document.getElementById('modalEventLocation').innerHTML = `<i class="fa-solid fa-location-dot me-1"></i> ${ev.location}`;
            document.getElementById('modalEventDesc').textContent = ev.description || 'No additional details provided.';
            
            new bootstrap.Modal(document.getElementById('eventDetailModal')).show();
        }
    };

    document.getElementById('prevMonthBtn').addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    document.getElementById('nextMonthBtn').addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

    fetchEvents();
});
</script>
