<?php
// MAMCET Placement & Learning Portal - Sidebar Navigation Template

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/functions.php');

$roleId = (int)($_SESSION['role_id'] ?? 0);
$currentScript = basename($_SERVER['PHP_SELF']);
$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || strpos($_SERVER['REQUEST_URI'], '/student/') !== false) {
    $baseDir = '../';
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="<?php echo $baseDir; ?>index.php" class="sidebar-logo">
            <?php if (!empty($collegeLogo) && file_exists(__DIR__ . '/../' . $collegeLogo)): ?>
                <img src="<?php echo $baseDir . $collegeLogo; ?>" alt="Logo" style="max-height: 40px; border-radius: 4px; margin-right: 8px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));">
            <?php else: ?>
                <i class="fa-solid fa-graduation-cap fa-2x text-white"></i>
            <?php endif; ?>
            <span>
                <?php 
                $shortName = 'MAMCET';
                if (isset($portalName) && strpos($portalName, ' ') !== false) {
                    $parts = explode(' ', $portalName);
                    if (!empty($parts[0])) $shortName = $parts[0];
                }
                echo esc($shortName);
                ?>
                <br>
                <small style="font-size: 0.65rem; opacity: 0.8; font-weight: normal; letter-spacing: 0.5px;">
                    <?php echo isset($portalName) ? esc(str_replace($shortName . ' ', '', $portalName)) : 'Placement Portal'; ?>
                </small>
            </span>
        </a>
    </div>
    
    <ul class="sidebar-menu">
        <?php if ($roleId === ROLE_SUPER_ADMIN): ?>
            <!-- Super Admin Navigation -->
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dashboard.php">
                    <i class="fa-solid fa-gauge"></i>
                    <span>Dashboard</span>
                </a>
            </li>
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
            <li class="<?php echo $currentScript === 'students.php' || $currentScript === 'student-view.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/students.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Student Roster</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'dataset-manager.php' || $currentScript === 'import-preview.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dataset-manager.php">
                    <i class="fa-solid fa-file-excel"></i>
                    <span>Dataset Manager</span>
                </a>
            </li>
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
            <li class="<?php echo $currentScript === 'settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/settings.php">
                    <i class="fa-solid fa-gears"></i>
                    <span>Portal Settings</span>
                </a>
            </li>
            
            <!-- Super Admin Stage 2 Menu -->
            <li class="menu-divider mt-3 mb-2" style="font-size: 0.7rem; text-transform: uppercase; color: rgba(255,255,255,0.4); padding-left: 20px;">Stage 2 Control</li>
            <li class="<?php echo $currentScript === 'resume-analytics.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/resume-analytics.php">
                    <i class="fa-solid fa-chart-simple"></i>
                    <span>Resume Analytics</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'advanced-reports.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/advanced-reports.php">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span>Advanced Reports</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'notification-center.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/notification-center.php">
                    <i class="fa-solid fa-envelope-open-text"></i>
                    <span>Notification Center</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'stage2-settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/stage2-settings.php">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Stage 2 Settings</span>
                </a>
            </li>

        <?php elseif ($roleId === ROLE_PLACEMENT_OFFICER): ?>
            <!-- Placement Officer Navigation -->
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dashboard.php">
                    <i class="fa-solid fa-gauge"></i>
                    <span>Dashboard</span>
                </a>
            </li>
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
            <li class="<?php echo $currentScript === 'dataset-manager.php' || $currentScript === 'import-preview.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/dataset-manager.php">
                    <i class="fa-solid fa-file-excel"></i>
                    <span>Dataset Manager</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'students.php' || $currentScript === 'student-view.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/students.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Student Roster</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'announcements.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/announcements.php">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span>Placement Drives</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'courses.php' || $currentScript === 'course-builder.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/courses.php">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Learning & Courses</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'reports.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/reports.php">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Reports & Exports</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/settings.php">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span>System Settings</span>
                </a>
            </li>
            
            <!-- Placement Officer Stage 2 Menu -->
            <li class="menu-divider mt-3 mb-2" style="font-size: 0.7rem; text-transform: uppercase; color: rgba(255,255,255,0.4); padding-left: 20px;">Placement Stage 2</li>
            <li class="<?php echo $currentScript === 'resume-analytics.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/resume-analytics.php">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                    <span>Resume Analytics</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'job-descriptions.php' || $currentScript === 'job-description-form.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/job-descriptions.php">
                    <i class="fa-solid fa-file-invoice"></i>
                    <span>Job Descriptions</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'opportunities.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/opportunities.php">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>Placement Opportunities</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'eligibility-manager.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/eligibility-manager.php">
                    <i class="fa-solid fa-user-check"></i>
                    <span>Eligibility Manager</span>
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
            <li class="<?php echo $currentScript === 'notification-center.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/notification-center.php">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Notifications</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'advanced-reports.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/advanced-reports.php">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Advanced Reports</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'stage2-settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>admin/stage2-settings.php">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Stage 2 Settings</span>
                </a>
            </li>

        <?php elseif ($roleId === ROLE_STUDENT): ?>
            <!-- Student Navigation -->
            <li class="<?php echo $currentScript === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/dashboard.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'profile.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/profile.php">
                    <i class="fa-solid fa-id-card"></i>
                    <span>My Profile</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'placements.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/placements.php">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>Placement Updates</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'courses.php' || $currentScript === 'course-view.php' || $currentScript === 'lesson.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/courses.php">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Courses & Learning</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'progress.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/progress.php">
                    <i class="fa-solid fa-spinner"></i>
                    <span>Learning Progress</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'settings.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/settings.php">
                    <i class="fa-solid fa-user-gear"></i>
                    <span>Account Settings</span>
                </a>
            </li>
            
            <!-- Student Stage 2 Menu -->
            <li class="menu-divider mt-3 mb-2" style="font-size: 0.7rem; text-transform: uppercase; color: rgba(255,255,255,0.4); padding-left: 20px;">Career Hub</li>
            <li class="<?php echo $currentScript === 'resume.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/resume.php">
                    <i class="fa-solid fa-file-pdf"></i>
                    <span>My Resume</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'resume-builder.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/resume-builder.php">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Resume Builder</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'ats-analysis.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/ats-analysis.php">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>ATS Analysis</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'job-match.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/job-match.php">
                    <i class="fa-solid fa-code-compare"></i>
                    <span>Job Match</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'interview-preparation.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/interview-preparation.php">
                    <i class="fa-solid fa-brain"></i>
                    <span>Interview Preparation</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'mock-interview.php' || $currentScript === 'interview-report.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/mock-interview.php">
                    <i class="fa-solid fa-comments"></i>
                    <span>Mock Interview</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'applications.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/applications.php">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>My Applications</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'readiness.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/readiness.php">
                    <i class="fa-solid fa-award"></i>
                    <span>Placement Readiness</span>
                </a>
            </li>
            <li class="<?php echo $currentScript === 'notifications.php' ? 'active' : ''; ?>">
                <a href="<?php echo $baseDir; ?>student/notifications.php">
                    <i class="fa-solid fa-bell"></i>
                    <span>Notifications</span>
                </a>
            </li>
        <?php endif; ?>
        
        <li class="mt-5">
            <a href="<?php echo $baseDir; ?>auth/logout.php" class="text-danger">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </li>
    </ul>
    
    <div class="sidebar-footer">
        <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:34px;height:34px;border-radius:50%;font-weight:bold;">
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
        </div>
        <div style="line-height: 1.2;">
            <span style="font-size:0.85rem; font-weight:600; display:block; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <?php echo esc($_SESSION['username'] ?? 'User'); ?>
            </span>
            <span style="font-size:0.7rem; color: rgba(255,255,255,0.5);">
                <?php echo $roleId === 1 ? 'Super Admin' : ($roleId === 2 ? 'Placement Officer' : 'Student'); ?>
            </span>
        </div>
    </div>
</aside>
