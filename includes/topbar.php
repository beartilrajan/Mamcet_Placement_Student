<?php
// MAMCET Placement & Learning Portal - Refactored Top Navigation Bar Component

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/functions.php');

$userId = $_SESSION['user_id'] ?? null;
$roleId = (int)($_SESSION['role_id'] ?? 0);
$studentInfo = null;
$officerInfo = null;
$recentNotifications = [];
$unreadNotificationsCount = 0;
$allSessions = $allSessions ?? [];
$activeSessionId = $activeSessionId ?? ($_SESSION['active_session_id'] ?? 1);
$activeSessionName = $activeSessionName ?? ($_SESSION['active_session_name'] ?? '');

try {
    $db = Database::getInstance()->getConnection();
    
    if ($roleId === ROLE_STUDENT) {
        $studentInfo = getActiveStudent($db);
        if ($studentInfo && $userId) {
            require_once(__DIR__ . '/../services/NotificationService.php');
            $notifData = NotificationService::getStudentNotifications($db, (int)$userId, 8, true);
            $unreadNotificationsCount = $notifData['unread_count'];
            $recentNotifications = $notifData['notifications'];
        }
    } elseif ($roleId === ROLE_PLACEMENT_OFFICER) {
        if ($userId) {
            $stmtOff = $db->prepare("SELECT name, email FROM placement_officers WHERE user_id = ?");
            $stmtOff->execute([$userId]);
            $officerInfo = $stmtOff->fetch() ?: null;
        }
    }

    if (empty($allSessions) && $db) {
        $stmtSessions = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC");
        $allSessions = $stmtSessions->fetchAll() ?: [];
    }

    if (empty($activeSessionName) && !empty($allSessions)) {
        foreach ($allSessions as $s) {
            if ((int)$s['session_id'] === (int)$activeSessionId) {
                $activeSessionName = $s['session_name'];
                break;
            }
        }
    }
    if (empty($activeSessionName)) {
        $activeSessionName = '2026–2027';
    }
} catch (\Throwable $topbarEx) {
    error_log("Topbar info load error: " . $topbarEx->getMessage());
}

$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/student/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/auth/') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    $baseDir = '../';
}

if (!empty($studentInfo)) {
    $userName = $studentInfo['student_name'] ?? ($_SESSION['username'] ?? 'Student');
    $userSubtitle = $studentInfo['registration_number'] ?? 'Student';
    $userDept = $studentInfo['dept_code'] ?? 'STUDENT';
} elseif (!empty($officerInfo)) {
    $userName = $officerInfo['name'] ?? ($_SESSION['username'] ?? 'Placement Officer');
    $userSubtitle = 'Placement Officer';
    $userDept = 'OFFICER';
} else {
    $userName = $_SESSION['username'] ?? 'Super Admin';
    $userSubtitle = ($roleId === ROLE_SUPER_ADMIN || $roleId === 1) ? 'Super Admin' : 'User';
    $userDept = ($roleId === ROLE_SUPER_ADMIN || $roleId === 1) ? 'ADMIN' : 'STAFF';
}
?>

<header class="top-navbar">
    <div class="navbar-left">
        <button class="sidebar-toggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
        <h4 class="navbar-title mb-0 text-dark fw-bold" style="font-family: var(--font-heading);">
            <?php echo isset($pageTitle) ? esc($pageTitle) : 'Dashboard'; ?>
        </h4>
    </div>
    
    <?php if ($roleId === ROLE_SUPER_ADMIN || $roleId === ROLE_PLACEMENT_OFFICER): ?>
        <!-- Global Student Spotlight Search Trigger (Ctrl + K) for Admin/Officer -->
        <div class="spotlight-trigger-wrapper mx-3 d-none d-md-block flex-grow-1" style="max-width: 340px;">
            <button type="button" class="topbar-spotlight-btn w-100" id="openSpotlightSearchBtn" title="Search students (Ctrl + K)">
                <i class="fa-solid fa-magnifying-glass text-muted me-2"></i>
                <span class="spotlight-btn-placeholder text-muted">Search students...</span>
                <span class="topbar-spotlight-kbd-wrapper ms-auto">
                    <kbd class="topbar-spotlight-kbd">Ctrl K</kbd>
                </span>
            </button>
        </div>
    <?php endif; ?>

    <div class="navbar-right">
        <?php if ($roleId === ROLE_SUPER_ADMIN || $roleId === ROLE_PLACEMENT_OFFICER): ?>
            <!-- Mobile Spotlight Search Trigger -->
            <button type="button" class="btn-topbar-icon-trigger d-md-none" id="openSpotlightSearchMobileBtn" title="Search students">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        <?php endif; ?>

        <!-- Academic Session Selector -->
        <div class="session-selector-container <?php echo $roleId === ROLE_STUDENT ? 'd-none d-md-flex' : 'd-none d-sm-flex'; ?>">
            <span class="small text-muted fw-bold d-none d-lg-inline">Session:</span>
            <select class="session-selector-select" id="globalSessionSelector">
                <?php foreach ($allSessions as $s): ?>
                    <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                        <?php echo esc($s['session_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>


        <!-- Notification Center Bell & Popover Drawer -->
        <div class="dropdown">
            <button class="notification-bell-btn" type="button" id="notificationDropdown" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotificationsCount > 0): ?>
                    <span class="notification-badge"><?php echo $unreadNotificationsCount; ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-drawer" aria-labelledby="notificationDropdown">
                <div class="notification-drawer-header">
                    <span class="fw-bold text-dark" style="font-size: 0.95rem;">Notifications</span>
                    <?php if ($unreadNotificationsCount > 0): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-primary fw-semibold" id="markAllNotificationsRead">
                            Mark all read
                        </button>
                    <?php endif; ?>
                </div>
                <div class="notification-list">
                    <?php if (!empty($recentNotifications)): ?>
                        <?php foreach ($recentNotifications as $notif): 
                            $rawTarget = !empty($notif['target_url']) ? $notif['target_url'] : 'student/notifications.php';
                            $targetLink = $baseDir . 'student/notifications.php?read_id=' . (int)$notif['notification_id'] . '&redirect=' . urlencode($baseDir . $rawTarget);
                        ?>
                            <a href="<?php echo $targetLink; ?>" class="notification-item <?php echo empty($notif['is_read']) ? 'unread' : ''; ?>">
                                <div class="notification-icon-box <?php echo esc($notif['icon_bg']); ?>">
                                    <i class="<?php echo esc($notif['category_icon']); ?>"></i>
                                </div>
                                <div style="line-height: 1.25; min-width:0;" class="flex-grow-1">
                                    <span class="d-block fw-semibold text-dark mb-1 text-truncate" style="font-size: 0.84rem; max-width:230px;">
                                        <?php echo esc($notif['title']); ?>
                                    </span>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge <?php echo esc($notif['badge_class']); ?>" style="font-size:0.62rem; padding: 2px 5px;"><?php echo esc($notif['category_label']); ?></span>
                                        <span class="text-muted" style="font-size: 0.70rem;"><?php echo esc($notif['time_ago']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted" style="font-size: 0.85rem;">
                            <i class="fa-regular fa-bell-slash d-block fa-2x mb-2 text-secondary opacity-50"></i>
                            No unread notifications
                        </div>
                    <?php endif; ?>
                </div>
                <div class="p-2 text-center border-top bg-light">
                    <a href="<?php echo $roleId === ROLE_STUDENT ? $baseDir . 'student/notifications.php' : $baseDir . 'admin/notification-center.php'; ?>" class="small text-primary fw-bold text-decoration-none">
                        View All Notifications <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- User Profile Dropdown Trigger (Top-Right) -->
        <div class="dropdown">
            <button class="profile-dropdown-btn" type="button" id="userProfileDropdown" data-bs-toggle="dropdown" data-bs-display="static" data-bs-auto-close="outside" aria-expanded="false" aria-label="Toggle user profile menu">
                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:36px;height:36px;border-radius:50%;font-weight:bold;">
                    <?php echo strtoupper(substr($userName, 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex text-start">
                    <span class="user-name" style="font-size: 0.88rem; font-weight: 600; line-height:1.2;"><?php echo esc($userName); ?></span>
                    <span class="user-role" style="font-size: 0.72rem; color: #64748b;"><?php echo esc($userSubtitle); ?></span>
                </div>
                <i class="fa-solid fa-chevron-down text-muted small d-none d-md-inline ms-1"></i>
            </button>
            
            <div class="dropdown-menu dropdown-menu-end profile-dropdown-menu" aria-labelledby="userProfileDropdown">
                <!-- Dropdown Header with User Details -->
                <div class="profile-dropdown-header">
                    <div class="profile-avatar-large">
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    </div>
                    <div class="profile-header-info">
                        <div class="profile-user-name"><?php echo esc($userName); ?></div>
                        <span class="profile-user-role"><?php echo esc($userSubtitle); ?></span>
                        <span class="profile-dept-badge"><?php echo esc($userDept); ?></span>
                    </div>
                </div>

                <!-- Mobile Session Switcher in Drawer -->
                <div class="profile-dropdown-session d-md-none">
                    <div class="profile-session-label-row">
                        <span class="profile-session-label">
                            <i class="fa-solid fa-calendar-days text-primary"></i> Academic Session
                        </span>
                        <?php if (!empty($activeSessionName)): ?>
                            <span class="badge bg-primary-subtle text-primary" style="font-size:0.65rem; font-weight:600;"><?php echo esc($activeSessionName); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-session-input-wrap">
                        <select class="profile-session-select" id="mobileSessionSelector" aria-label="Select session">
                            <?php foreach ($allSessions as $s): ?>
                                <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                                    <?php echo esc($s['session_name']); ?><?php echo $s['session_id'] == $activeSessionId ? ' (Active)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fa-solid fa-chevron-down profile-session-arrow"></i>
                    </div>
                </div>
                
                <!-- Quick Navigation Links -->
                <div class="profile-dropdown-body">
                    <?php if ($roleId === ROLE_STUDENT): ?>
                        <a href="<?php echo $baseDir; ?>student/profile.php" class="profile-dropdown-item">
                            <span class="profile-item-icon profile-icon-primary">
                                <i class="fa-solid fa-id-card"></i>
                            </span>
                            <span class="profile-item-text">My Profile</span>
                            <i class="fa-solid fa-chevron-right profile-item-arrow"></i>
                        </a>
                        <a href="<?php echo $baseDir; ?>student/settings.php" class="profile-dropdown-item">
                            <span class="profile-item-icon profile-icon-secondary">
                                <i class="fa-solid fa-user-gear"></i>
                            </span>
                            <span class="profile-item-text">Account Settings</span>
                            <i class="fa-solid fa-chevron-right profile-item-arrow"></i>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo $baseDir; ?>admin/settings.php" class="profile-dropdown-item">
                            <span class="profile-item-icon profile-icon-primary">
                                <i class="fa-solid fa-gears"></i>
                            </span>
                            <span class="profile-item-text">Portal Settings</span>
                            <i class="fa-solid fa-chevron-right profile-item-arrow"></i>
                        </a>
                    <?php endif; ?>
                    
                    <div class="profile-dropdown-divider"></div>
                    
                    <a href="<?php echo $baseDir; ?>auth/logout.php" class="profile-dropdown-item profile-item-logout">
                        <span class="profile-item-icon profile-icon-danger">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </span>
                        <span class="profile-item-text">Logout</span>
                        <i class="fa-solid fa-chevron-right profile-item-arrow"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>
