<?php
// MAMCET Placement & Learning Portal - Notifications API Endpoint

header('Content-Type: application/json');
require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../services/NotificationService.php');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'read_all');
$db = Database::getInstance()->getConnection();
$userId = (int)$_SESSION['user_id'];
$roleId = (int)$_SESSION['role_id'];

if ($action === 'read_all') {
    $ok = NotificationService::markAsRead($db, $userId, null);
    echo json_encode(['success' => $ok, 'message' => 'All notifications marked as read']);
    exit;
}

if ($action === 'read_single') {
    $notifId = (int)($_POST['notification_id'] ?? ($_GET['notification_id'] ?? 0));
    $ok = NotificationService::markAsRead($db, $userId, $notifId);
    echo json_encode(['success' => $ok, 'message' => 'Notification marked as read']);
    exit;
}

if ($action === 'fetch_unread') {
    $data = NotificationService::getStudentNotifications($db, $userId, 8, true);
    echo json_encode([
        'success'       => true, 
        'unread_count'  => $data['unread_count'],
        'notifications' => $data['notifications']
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);


