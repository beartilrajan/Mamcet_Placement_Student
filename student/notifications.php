<?php
// MAMCET Placement & Learning Portal - Student Notification Center
require_once(__DIR__ . '/../includes/header.php');

// Restrict to student only
if ($roleId !== ROLE_STUDENT) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

require_once(__DIR__ . '/../services/NotificationService.php');

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$userId = (int)$_SESSION['user_id'];
$message = '';
$error = '';

$selectedCategory = trim($_GET['cat'] ?? 'all');
$filterUnread = isset($_GET['unread']) && $_GET['unread'] === '1';

// 1. Process mark all as read
if (isset($_POST['mark_all_read'])) {
    NotificationService::markAsRead($db, $userId, null);
    $message = "All notifications marked as read.";
}

// 2. Process mark single notification as read
if (isset($_GET['read_id'])) {
    $notifId = (int)$_GET['read_id'];
    if ($notifId > 0) {
        NotificationService::markAsRead($db, $userId, $notifId);
    }
    
    if (!empty($_GET['redirect'])) {
        header("Location: " . $_GET['redirect']);
        exit;
    }
    header("Location: notifications.php" . (!empty($selectedCategory) && $selectedCategory !== 'all' ? "?cat=" . urlencode($selectedCategory) : ""));
    exit;
}

// 3. Fetch notifications data
$notifData = NotificationService::getStudentNotifications($db, $userId, 50, $filterUnread, $selectedCategory);
$notificationsList = $notifData['notifications'];
$unreadCount = $notifData['unread_count'];

// Calculate category counts for filter tabs
$allData = NotificationService::getStudentNotifications($db, $userId, 100, false, null);
$catCounts = [
    'all'       => count($allData['notifications']),
    'placement' => 0,
    'calendar'  => 0,
    'learning'  => 0,
    'training'  => 0,
    'broadcast' => 0
];
foreach ($allData['notifications'] as $item) {
    $cat = $item['category'] ?? 'general';
    if (isset($catCounts[$cat])) {
        $catCounts[$cat]++;
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    <div class="page-container py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="page-title fw-bold mb-1">
                    <i class="fa-solid fa-bell text-warning me-2"></i> Notifications Center
                </h1>
                <p class="text-muted mb-0">Stay updated on all college events, placement drives, learning courses, and trainings.</p>
            </div>
            
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($unreadCount > 0): ?>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-primary fw-semibold">
                            <i class="fa-solid fa-check-double me-1"></i> Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Category Filter Tabs -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
            <div class="card-body p-2 p-md-3">
                <div class="d-flex align-items-center gap-2 overflow-x-auto pb-1" style="scrollbar-width: thin;">
                    <a href="notifications.php" class="btn btn-sm <?php echo ($selectedCategory === 'all' && !$filterUnread) ? 'btn-primary' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-layer-group me-1"></i> All (<?php echo $catCounts['all']; ?>)
                    </a>
                    <a href="notifications.php?unread=1" class="btn btn-sm <?php echo $filterUnread ? 'btn-danger' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-circle-dot me-1"></i> Unread (<?php echo $unreadCount; ?>)
                    </a>
                    <a href="notifications.php?cat=placement" class="btn btn-sm <?php echo $selectedCategory === 'placement' ? 'btn-success' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-briefcase me-1"></i> Placements (<?php echo $catCounts['placement']; ?>)
                    </a>
                    <a href="notifications.php?cat=calendar" class="btn btn-sm <?php echo $selectedCategory === 'calendar' ? 'btn-info text-white' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-calendar-days me-1"></i> Calendar (<?php echo $catCounts['calendar']; ?>)
                    </a>
                    <a href="notifications.php?cat=learning" class="btn btn-sm <?php echo $selectedCategory === 'learning' ? 'btn-primary' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-graduation-cap me-1"></i> Learning Hub (<?php echo $catCounts['learning']; ?>)
                    </a>
                    <a href="notifications.php?cat=training" class="btn btn-sm <?php echo $selectedCategory === 'training' ? 'btn-warning text-dark' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-chalkboard-user me-1"></i> Trainings (<?php echo $catCounts['training']; ?>)
                    </a>
                    <a href="notifications.php?cat=broadcast" class="btn btn-sm <?php echo $selectedCategory === 'broadcast' ? 'btn-dark' : 'btn-light border'; ?> rounded-pill px-3 text-nowrap fw-semibold">
                        <i class="fa-solid fa-bullhorn me-1"></i> Broadcasts (<?php echo $catCounts['broadcast']; ?>)
                    </a>
                </div>
            </div>
        </div>

        <!-- Notification List -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white">
            <div class="card-body p-0">
                <?php if (empty($notificationsList)): ?>
                    <div class="text-center py-5 text-muted">
                        <div class="mb-3">
                            <i class="fa-regular fa-bell-slash fa-3x text-secondary opacity-50"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-1">No notifications found</h5>
                        <p class="small text-muted mb-0">You're completely caught up! New updates will show up here as soon as they are posted.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notificationsList as $notif): 
                            $rawTarget = !empty($notif['target_url']) ? $notif['target_url'] : 'student/notifications.php';
                            $targetLink = 'notifications.php?read_id=' . (int)$notif['notification_id'] . '&redirect=' . urlencode($baseDir . $rawTarget);
                        ?>
                            <div class="list-group-item p-3 p-md-4 d-flex justify-content-between align-items-start gap-3 transition-all <?php echo !$notif['is_read'] ? 'bg-primary-subtle bg-opacity-25 border-start border-4 border-primary' : ''; ?>" style="border-bottom: 1px solid #f1f5f9;">
                                <div class="d-flex align-items-start gap-3 flex-grow-1" style="min-width: 0;">
                                    <div class="notification-icon-box <?php echo esc($notif['icon_bg']); ?> rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.1rem;">
                                        <i class="<?php echo esc($notif['category_icon']); ?>"></i>
                                    </div>
                                    <div class="flex-grow-1" style="min-width: 0;">
                                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                            <span class="badge <?php echo esc($notif['badge_class']); ?>" style="font-size: 0.68rem; font-weight: 600;">
                                                <?php echo esc($notif['category_label']); ?>
                                            </span>
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="badge bg-danger text-white" style="font-size: 0.62rem;">NEW</span>
                                            <?php endif; ?>
                                            <span class="text-muted small ms-auto" style="font-size: 0.75rem;">
                                                <i class="fa-regular fa-clock me-1"></i><?php echo esc($notif['time_ago']); ?>
                                            </span>
                                        </div>
                                        
                                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.96rem;">
                                            <a href="<?php echo $targetLink; ?>" class="text-dark text-decoration-none hover-primary">
                                                <?php echo esc($notif['title']); ?>
                                            </a>
                                        </h6>
                                        
                                        <p class="text-muted mb-2 small" style="line-height: 1.45;">
                                            <?php echo nl2br(esc($notif['message'])); ?>
                                        </p>
                                        
                                        <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                                            <a href="<?php echo $targetLink; ?>" class="btn btn-sm btn-outline-primary py-1 px-3 rounded-pill fw-semibold" style="font-size: 0.78rem;">
                                                View Update <i class="fa-solid fa-arrow-right ms-1"></i>
                                            </a>
                                            <small class="text-muted" style="font-size: 0.72rem;">
                                                Posted <?php echo date('d M Y, h:i A', strtotime($notif['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$notif['is_read']): ?>
                                    <div class="flex-shrink-0">
                                        <a href="notifications.php?read_id=<?php echo (int)$notif['notification_id']; ?>" class="btn btn-sm btn-light border text-success rounded-circle d-flex align-items-center justify-content-center" title="Mark as Read" style="width: 32px; height: 32px;">
                                            <i class="fa-solid fa-check"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

