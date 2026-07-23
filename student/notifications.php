<?php
// MAMCET Placement & Learning Portal - Student Notification logs
require_once(__DIR__ . '/../includes/header.php');

// Restrict to student only
if ($roleId !== ROLE_STUDENT) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$userId = (int)$_SESSION['user_id'];
$message = '';
$error = '';

// 1. Process mark all as read
if (isset($_POST['mark_all_read'])) {
    $db->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE user_id = ?")->execute([$userId]);
    $message = "All notifications marked as read.";
}

// 2. Process mark single notification as read
if (isset($_GET['read_id'])) {
    $notifId = (int)$_GET['read_id'];
    $stmtUpd = $db->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmtUpd->execute([$notifId, $userId]);
    header("Location: notifications.php");
    exit;
}

// 3. Fetch all notifications mapping to this user
$stmtNotif = $db->prepare("
    SELECT n.*, nr.is_read, nr.read_at 
    FROM notifications n
    JOIN notification_recipients nr ON n.notification_id = nr.notification_id
    WHERE nr.user_id = ?
    ORDER BY n.created_at DESC
");
$stmtNotif->execute([$userId]);
$notificationsList = $stmtNotif->fetchAll();
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-bell text-warning"></i> Notifications Center</h1>
                <?php if (!empty($notificationsList)): ?>
                    <form method="POST">
                        <button type="submit" name="mark_all_read" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-check-double"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow mb-4">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (empty($notificationsList)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fa-solid fa-envelope-open fa-3x mb-3 text-gray-300"></i>
                                <h5>All Caught Up!</h5>
                                <p>You have no active alerts or notifications at this moment.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notificationsList as $notif): ?>
                                <div class="list-group-item list-group-item-action py-3 d-flex justify-content-between align-items-start <?php echo $notif['is_read'] ? '' : 'bg-light font-weight-bold'; ?>">
                                    <div class="me-auto" style="max-width: 85%;">
                                        <div class="d-flex align-items-center mb-1">
                                            <h6 class="text-dark font-weight-bold mb-0 me-2" style="font-size:0.95rem;"><?php echo htmlspecialchars($notif['title']); ?></h6>
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="badge bg-danger" style="font-size:0.65rem;">New</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-muted mb-0" style="font-size:0.85rem; line-height: 1.4;"><?php echo htmlspecialchars($notif['message']); ?></p>
                                        <small class="text-muted mt-2 d-block" style="font-size:0.75rem;">
                                            <i class="fa-solid fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($notif['created_at'])); ?>
                                        </small>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                        <a href="?read_id=<?php echo $notif['notification_id']; ?>" class="btn btn-sm btn-outline-success" title="Mark as Read">
                                            <i class="fa-solid fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
