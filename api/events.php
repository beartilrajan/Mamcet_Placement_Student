<?php
// MAMCET Placement & Learning Portal - College Calendar Events API

header('Content-Type: application/json');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../config/database.php');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$db = Database::getInstance()->getConnection();
$roleId = (int)$_SESSION['role_id'];
$userId = $_SESSION['user_id'];
$sessionId = getActiveAcademicSessionId();

$action = $_POST['action'] ?? ($_GET['action'] ?? 'fetch');

if ($action === 'fetch') {
    // Fetch all events for the current active session + placement drives from announcements
    $stmtEv = $db->prepare("
        SELECT event_id AS id, title, description, event_type, start_date AS start, end_date AS end, location 
        FROM college_events 
        WHERE session_id = ?
        ORDER BY start_date ASC
    ");
    $stmtEv->execute([$sessionId]);
    $events = $stmtEv->fetchAll();

    // Also map placement announcements into the calendar feed
    $stmtAnn = $db->prepare("
        SELECT announcement_id AS id, title, description, 'Placement Drive' AS event_type, 
               publish_date AS start, expiry_date AS end, job_location AS location
        FROM announcements 
        WHERE status = 'published' AND session_id = ?
    ");
    $stmtAnn->execute([$sessionId]);
    $drives = $stmtAnn->fetchAll();

    $calendarFeed = [];

    foreach ($events as $ev) {
        $type = $ev['event_type'];
        $badgeClass = 'bg-primary';
        if ($type === 'Placement Drive') $badgeClass = 'bg-success';
        elseif ($type === 'Academic Deadline' || $type === 'Exam Schedule') $badgeClass = 'bg-danger';
        elseif ($type === 'Holiday' || $type === 'Workshop') $badgeClass = 'bg-warning text-dark';

        $calendarFeed[] = [
            'id' => 'ev_' . $ev['id'],
            'title' => $ev['title'],
            'description' => $ev['description'],
            'event_type' => $type,
            'start' => $ev['start'],
            'end' => $ev['end'] ?: $ev['start'],
            'location' => $ev['location'] ?: 'Campus',
            'badgeClass' => $badgeClass
        ];
    }

    foreach ($drives as $drv) {
        $calendarFeed[] = [
            'id' => 'drv_' . $drv['id'],
            'title' => '🏢 Drive: ' . $drv['title'],
            'description' => $drv['description'],
            'event_type' => 'Placement Drive',
            'start' => $drv['start'],
            'end' => $drv['end'],
            'location' => $drv['location'] ?: 'T&P Cell',
            'badgeClass' => 'bg-success'
        ];
    }

    echo json_encode(['success' => true, 'events' => $calendarFeed]);
    exit;
}

if ($action === 'create') {
    if ($roleId !== ROLE_SUPER_ADMIN && $roleId !== ROLE_PLACEMENT_OFFICER) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $type = $_POST['event_type'] ?? 'Campus Event';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($title) || empty($startDate)) {
        echo json_encode(['success' => false, 'message' => 'Title and Start Date are required']);
        exit;
    }

    $stmtIns = $db->prepare("
        INSERT INTO college_events (title, description, event_type, start_date, end_date, location, session_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtIns->execute([$title, $description, $type, $startDate, $endDate, $location, $sessionId, $userId]);
    $newEventId = (int)$db->lastInsertId();

    if ($newEventId > 0) {
        require_once(__DIR__ . '/../services/NotificationService.php');
        NotificationService::notifyCalendarEvent($db, $newEventId, (int)$userId);
    }

    echo json_encode(['success' => true, 'message' => 'College Event successfully created!']);
    exit;
}

if ($action === 'delete') {
    if ($roleId !== ROLE_SUPER_ADMIN && $roleId !== ROLE_PLACEMENT_OFFICER) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $stmtDel = $db->prepare("DELETE FROM college_events WHERE event_id = ?");
    $stmtDel->execute([$eventId]);

    echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
