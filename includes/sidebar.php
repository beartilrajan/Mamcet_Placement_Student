<?php
// MAMCET Placement & Learning Portal - Modern Enterprise Sidebar Navigation

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/functions.php');

$roleId = (int)($_SESSION['role_id'] ?? 0);
$currentScript = basename($_SERVER['PHP_SELF']);
$userName = $_SESSION['username'] ?? 'User';
$userInitials = strtoupper(substr($userName, 0, 2));
$roleLabel = function_exists('getRoleName') ? getRoleName($roleId) : 'User';

$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || strpos($_SERVER['REQUEST_URI'], '/student/') !== false) {
    $baseDir = '../';
}
?>
<aside class="sidebar" id="sidebar" aria-label="Primary navigation">
    <!-- Brand Header -->
    <div class="sidebar-header">
        <a href="<?php echo $baseDir; ?>index.php" class="sidebar-logo">
            <?php if (!empty($collegeLogo) && file_exists(__DIR__ . '/../' . $collegeLogo)): ?>
                <img src="<?php echo $baseDir . $collegeLogo; ?>" alt="Logo">
            <?php else: ?>
                <div class="sidebar-logo-icon">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
            <?php endif; ?>
            <div>
                <span>MAMCET</span>
                <small class="d-block text-muted" style="font-size: 0.62rem; letter-spacing: 0.5px; font-weight: 500; color: #64748b !important;">
                    Placement Portal
                </small>
            </div>
        </a>
        <button type="button" class="sidebar-close-btn d-lg-none" id="sidebarCloseBtn" aria-label="Close Sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    
    <!-- Menu -->
    <ul class="sidebar-menu">
        <?php if ($roleId === ROLE_SUPER_ADMIN): ?>
            <!-- 1. MAIN -->
            <span class="sidebar-heading">Core</span>
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dashboard.php">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- 2. ACADEMICS & ROSTER -->
            <span class="sidebar-heading">Academic Setup</span>
            <li class="<?php echo $currentScript === 'academic-sessions.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/academic-sessions.php">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Academic Sessions</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'batches.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/batches.php">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Programme Batches</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'departments.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/departments.php">
                    <i class="fa-solid fa-building-columns"></i>
                    <span>Departments</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['students.php', 'student-view.php', 'student-add.php', 'student-import.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/students.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Student Roster</span>
                </a>
            </li>

            <!-- 3. PLACEMENT & LMS -->
            <span class="sidebar-heading">Placements & LMS</span>
            <li class="<?php echo $currentScript === 'announcements.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/announcements.php">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span>Announcements</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'courses.php' || $currentScript === 'course-builder.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/courses.php">
                    <i class="fa-solid fa-book-open"></i>
                    <span>LMS Courses</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['trainings.php', 'training-view.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/trainings.php">
                    <i class="fa-solid fa-chalkboard-user"></i>
                    <span>Trainings & Attendance</span>
                </a>
            </li>

            <!-- 4. STAGE 2 SUITE -->
            <span class="sidebar-heading">Stage 2 Control</span>
            <li class="<?php echo $currentScript === 'resume-analytics.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/resume-analytics.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Resume Analytics</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'advanced-reports.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/advanced-reports.php">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Advanced Reports</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'notification-center.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/notification-center.php">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Notification Center</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'stage2-settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/stage2-settings.php">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Stage 2 Settings</span>
                </a>
            </li>

            <!-- 5. SYSTEM SETTINGS -->
            <span class="sidebar-heading">System</span>
            <li class="<?php echo $currentScript === 'settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/settings.php">
                    <i class="fa-solid fa-gear"></i>
                    <span>Portal Settings</span>
                </a>
            </li>

        <?php elseif ($roleId === ROLE_PLACEMENT_OFFICER): ?>
            <!-- 1. MAIN -->
            <span class="sidebar-heading">Core</span>
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dashboard.php">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- 2. ACADEMICS & ROSTER -->
            <span class="sidebar-heading">Academic Setup</span>
            <li class="<?php echo $currentScript === 'academic-sessions.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/academic-sessions.php">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Academic Sessions</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'batches.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/batches.php">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Programme Batches</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'departments.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/departments.php">
                    <i class="fa-solid fa-building-columns"></i>
                    <span>Departments</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['students.php', 'student-view.php', 'student-add.php', 'student-import.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/students.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Student Roster</span>
                </a>
            </li>

            <!-- 3. PLACEMENTS -->
            <span class="sidebar-heading">Placement Operations</span>
            <li class="<?php echo $currentScript === 'announcements.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/announcements.php">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span>Placement Drives</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'opportunities.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/opportunities.php">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>Opportunities</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'applications.php' || $currentScript === 'application-view.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/applications.php">
                    <i class="fa-solid fa-address-book"></i>
                    <span>Applications</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'interview-readiness.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/interview-readiness.php">
                    <i class="fa-solid fa-user-tie"></i>
                    <span>Interview Readiness</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'courses.php' || $currentScript === 'course-builder.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/courses.php">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Learning & Courses</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['trainings.php', 'training-view.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/trainings.php">
                    <i class="fa-solid fa-chalkboard-user"></i>
                    <span>Trainings & Attendance</span>
                </a>
            </li>

            <!-- 4. ANALYTICS & STAGE 2 -->
            <span class="sidebar-heading">Stage 2 & Reports</span>
            <li class="<?php echo $currentScript === 'resume-analytics.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/resume-analytics.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Resume Analytics</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'advanced-reports.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/advanced-reports.php">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Advanced Reports</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'notification-center.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/notification-center.php">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Notifications</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'stage2-settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/stage2-settings.php">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Stage 2 Settings</span>
                </a>
            </li>

            <!-- 5. SYSTEM SETTINGS -->
            <span class="sidebar-heading">System</span>
            <li class="<?php echo $currentScript === 'settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/settings.php">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span>System Settings</span>
                </a>
            </li>

        <?php elseif ($roleId === ROLE_STUDENT): ?>
            <!-- 1. WORKSPACE -->
            <span class="sidebar-heading">Workspace</span>
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/dashboard.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'calendar.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/calendar.php">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>College Calendar</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'placements.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/placements.php">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>Placement Updates</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['learning.php', 'courses.php', 'progress.php', 'course-view.php', 'lesson.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/learning.php">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Learning Hub</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['trainings.php', 'training-view.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/trainings.php">
                    <i class="fa-solid fa-chalkboard-user"></i>
                    <span>Trainings</span>
                </a>
            </li>

            <!-- 2. PRACTICE & SKILLS -->
            <span class="sidebar-heading">Practice & Skills</span>
            <li class="<?php echo $currentScript === 'aptitude.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/aptitude.php">
                    <i class="fa-solid fa-brain"></i>
                    <span>Aptitude</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'leetcode.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/leetcode.php">
                    <i class="fa-solid fa-code"></i>
                    <span>LeetCode Profile</span>
                </a>
            </li>

            <!-- 3. CAREER SUITE -->
            <span class="sidebar-heading">Career Suite</span>
            <li class="<?php echo in_array($currentScript, ['resume.php', 'resume-builder.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/resume.php">
                    <i class="fa-solid fa-file-pdf"></i>
                    <span>Resume Suite</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'certificates.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/certificates.php">
                    <i class="fa-solid fa-certificate"></i>
                    <span>Certificates</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'ats-analysis.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/ats-analysis.php">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>ATS Analysis</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'interview-preparation.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/interview-preparation.php">
                    <i class="fa-solid fa-brain"></i>
                    <span>Interview Prep</span>
                </a>
            </li>
            <li class="<?php echo in_array($currentScript, ['mock-interview.php', 'interview-report.php']) ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/mock-interview.php">
                    <i class="fa-solid fa-comments"></i>
                    <span>Mock Interview</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'readiness.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/readiness.php">
                    <i class="fa-solid fa-award"></i>
                    <span>Placement Readiness</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <!-- Modern User Footer Pill -->
    <div class="sidebar-footer">
        <div class="sidebar-user-pill w-100">
            <div class="d-flex align-items-center gap-2" style="min-width: 0;">
                <div class="sidebar-user-avatar">
                    <?php echo $userInitials; ?>
                </div>
                <div style="min-width: 0; line-height: 1.1;">
                    <div class="text-white fw-bold text-truncate" style="font-size: 0.76rem;"><?php echo esc($userName); ?></div>
                    <small class="text-muted text-truncate d-block" style="font-size: 0.65rem;"><?php echo esc($roleLabel); ?></small>
                </div>
            </div>
            <a href="<?php echo $baseDir; ?>auth/logout.php" class="btn btn-sm btn-link text-danger p-0 ms-1" title="Sign Out" style="font-size: 0.85rem; text-decoration: none;">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<?php if ($roleId === ROLE_STUDENT): ?>
<!-- High-frequency student destinations stay one tap away on phones. -->
<nav class="student-mobile-nav" aria-label="Student quick navigation">
    <a href="<?php echo $baseDir; ?>student/dashboard.php" class="student-mobile-nav-item <?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
        <i class="fa-solid fa-house" aria-hidden="true"></i>
        <span>Home</span>
    </a>
    <a href="<?php echo $baseDir; ?>student/placements.php" class="student-mobile-nav-item <?php echo $currentScript === 'placements.php' ? 'active' : ''; ?>">
        <i class="fa-solid fa-briefcase" aria-hidden="true"></i>
        <span>Drives</span>
    </a>
    <a href="<?php echo $baseDir; ?>student/learning.php" class="student-mobile-nav-item <?php echo in_array($currentScript, ['learning.php', 'courses.php', 'progress.php', 'course-view.php', 'lesson.php'], true) ? 'active' : ''; ?>">
        <i class="fa-solid fa-book-open" aria-hidden="true"></i>
        <span>Learn</span>
    </a>
    <a href="<?php echo $baseDir; ?>student/readiness.php" class="student-mobile-nav-item <?php echo $currentScript === 'readiness.php' ? 'active' : ''; ?>">
        <i class="fa-solid fa-chart-simple" aria-hidden="true"></i>
        <span>Ready</span>
    </a>
    <button type="button" class="student-mobile-nav-item mobile-more-trigger" aria-controls="sidebar" aria-expanded="false">
        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
        <span>More</span>
    </button>
</nav>
<?php endif; ?>
