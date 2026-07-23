<?php
// MAMCET Placement & Learning Portal - Student Settings Page

$pageTitle = 'Account Settings';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfRequest();
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        try {
            // Get user record password hash
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userHash = $stmt->fetchColumn();
            
            if (password_verify($currentPassword, $userHash)) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtUpd = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmtUpd->execute([$newHash, $_SESSION['user_id']]);
                
                $success = 'Password changed successfully.';
                logActivity($db, $_SESSION['user_id'], 'Change Password', 'Changed user login password.');
            } else {
                $error = 'Incorrect current password.';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Account Settings</h4>
        </div>
        
        <div class="navbar-right">
            <div class="session-selector-container">
                <span class="small text-muted fw-bold d-none d-md-inline">Active Session:</span>
                <select class="session-selector-select" id="globalSessionSelector">
                    <?php foreach ($allSessions as $s): ?>
                        <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                            <?php echo esc($s['session_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-profile-dropdown">
                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:36px;height:36px;border-radius:50%;font-weight:bold;">
                    <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex">
                    <span class="user-name"><?php echo esc($student['student_name']); ?></span>
                    <span class="user-role">Student</span>
                </div>
            </div>
        </div>
    </header>

    <div class="page-container">
        
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-key text-primary me-2"></i> Update Password</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 mb-3" style="font-size:0.9rem;">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success py-2 mb-3" style="font-size:0.9rem;">
                                <i class="fa-solid fa-circle-check me-2"></i> <?php echo esc($success); ?>
                            </div>
                        <?php endif; ?>

                        <form action="settings.php" method="POST">
                            <?php csrfInput(); ?>
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary">Current Password</label>
                                <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary">New Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label text-secondary">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required minlength="6">
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Change Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

