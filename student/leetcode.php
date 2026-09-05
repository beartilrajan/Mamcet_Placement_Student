<?php
$pageTitle = 'LeetCode Dashboard';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
requireRole(ROLE_STUDENT);
$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);
if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not found.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}
$studentId = $student['student_id'];

// Auto-create LeetCode tables if they don't exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `leetcode_profiles` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_id` INT NOT NULL UNIQUE,
        `leetcode_username` VARCHAR(100) NOT NULL,
        `is_verified` TINYINT(1) NOT NULL DEFAULT 1,
        `layout_config` JSON DEFAULT NULL,
        `linked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE leetcode_profiles ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER leetcode_username");
    } catch (Exception $ex) {
        // Column already exists
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `leetcode_cache` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_id` INT NOT NULL,
        `cache_key` VARCHAR(100) NOT NULL,
        `cache_data` LONGTEXT,
        `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_student_cache` (`student_id`, `cache_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Tables might already exist or schema variation - continue gracefully
}

// Check if LeetCode is already linked
$lcUsername = '';
try {
    $stmtLC = $db->prepare("SELECT leetcode_username FROM leetcode_profiles WHERE student_id = ?");
    $stmtLC->execute([$studentId]);
    $lcProfile = $stmtLC->fetch();
    $lcUsername = $lcProfile ? $lcProfile['leetcode_username'] : '';
} catch (Exception $e) {
    // Table doesn't exist yet - treat as not connected
}
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    
    <div class="page-container leetcode-dashboard">
        
        <style>
            .leetcode-dashboard {
                --lc-bg: transparent;
                --lc-card-bg: #ffffff;
                --lc-card-border: #e2e8f0;
                --lc-text-primary: #0f172a;
                --lc-text-secondary: #475569;
                --lc-text-muted: #64748b;
                --lc-item-bg: #f8fafc;
                --lc-item-border: #e2e8f0;
                --lc-tag-bg: #f1f5f9;
                --lc-cell-empty: #e2e8f0;
                --lc-brand-orange: #ffa116;
                --lc-brand-orange-hover: #e08e0b;
                --lc-easy: #00b8a3;
                --lc-medium: #ffc01e;
                --lc-hard: #ef4743;
                --lc-blue: #2563eb;
                --lc-green-1: #9be9a8;
                --lc-green-2: #40c463;
                --lc-green-3: #30a14e;
                --lc-green-4: #216e39;
                --lc-hover-bg: #f1f5f9;
                --lc-card-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.05);
                
                background-color: var(--lc-bg);
                color: var(--lc-text-primary);
                min-height: 100vh;
                padding: 1.5rem;
                font-family: inherit;
                transition: background-color 0.3s ease, color 0.3s ease;
            }

            .leetcode-dashboard * {
                box-sizing: border-box;
            }

            /* State 1: Connect Panel */
            .lc-connect-container {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 70vh;
                text-align: center;
                position: relative;
            }

            .lc-connect-card {
                background: var(--lc-card-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 16px;
                padding: 3rem 2rem;
                max-width: 520px;
                width: 100%;
                box-shadow: var(--lc-card-shadow);
                position: relative;
                transition: all 0.3s ease;
            }

            .lc-logo-svg {
                width: 64px;
                height: 64px;
                margin-bottom: 1.5rem;
            }

            .lc-connect-card h2 {
                font-size: 1.75rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                margin-bottom: 0.5rem;
            }

            .lc-connect-card p {
                color: var(--lc-text-secondary);
                margin-bottom: 2rem;
                font-size: 0.95rem;
            }

            .lc-input-group {
                display: flex;
                margin-bottom: 2rem;
            }

            .lc-input-group input {
                flex: 1;
                background: var(--lc-hover-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 8px 0 0 8px;
                padding: 0.75rem 1rem;
                color: var(--lc-text-primary);
                font-size: 1rem;
            }
            
            .lc-input-group input:focus {
                outline: none;
                border-color: var(--lc-brand-orange);
            }

            .lc-btn-primary {
                background: var(--lc-brand-orange);
                color: #fff;
                border: none;
                border-radius: 0 8px 8px 0;
                padding: 0.75rem 1.5rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }

            .lc-btn-primary:hover {
                background: var(--lc-brand-orange-hover);
            }

            .lc-features-preview {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
                margin-top: 2rem;
            }

            .lc-feature-card {
                background: var(--lc-hover-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 8px;
                padding: 1rem;
                font-size: 0.85rem;
                color: var(--lc-text-secondary);
            }
            .lc-feature-card i {
                font-size: 1.5rem;
                color: var(--lc-brand-orange);
                margin-bottom: 0.5rem;
                display: block;
            }

            /* State 2: Dashboard */
            .lc-header-area {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--lc-card-border);
            }

            .lc-header-left {
                display: flex;
                align-items: center;
                gap: 1rem;
            }

            .lc-header-left a {
                color: var(--lc-text-primary);
                text-decoration: none;
                font-size: 1.25rem;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .lc-header-left a:hover {
                color: var(--lc-brand-orange);
            }

            .lc-header-right {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }

            .lc-btn-icon {
                background: var(--lc-card-bg);
                border: 1px solid var(--lc-card-border);
                color: var(--lc-text-primary);
                border-radius: 8px;
                padding: 0.5rem 1rem;
                cursor: pointer;
                font-size: 0.9rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                transition: all 0.2s;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }

            .lc-btn-icon:hover {
                background: var(--lc-hover-bg);
                border-color: var(--lc-brand-orange);
            }

            .lc-btn-danger {
                background: rgba(239, 71, 67, 0.08);
                color: var(--lc-hard);
                border-color: rgba(239, 71, 67, 0.2);
            }
            .lc-btn-danger:hover {
                background: rgba(239, 71, 67, 0.18);
            }

            .lc-grid {
                display: grid;
                grid-template-columns: repeat(12, 1fr);
                gap: 1.5rem;
                transition: all 0.3s ease;
            }

            .lc-widget {
                background: var(--lc-card-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 12px;
                padding: 1.5rem;
                position: relative;
                overflow: hidden;
                display: flex;
                flex-direction: column;
                box-shadow: var(--lc-card-shadow);
                opacity: 1;
                transition: all 0.3s ease;
            }

            .lc-widget[data-size="full"] {
                grid-column: span 12;
            }

            .lc-widget[data-size="two-thirds"] {
                grid-column: span 8;
            }

            .lc-widget[data-size="half"] {
                grid-column: span 6;
            }

            .lc-widget[data-size="third"] {
                grid-column: span 4;
            }
            
            @media (max-width: 992px) {
                .lc-widget[data-size="third"],
                .lc-widget[data-size="half"],
                .lc-widget[data-size="two-thirds"] {
                    grid-column: span 12;
                }
            }

            @media (max-width: 768px) {
                .leetcode-dashboard {
                    padding: 0.85rem 0.65rem;
                }
                .lc-header-area {
                    flex-wrap: wrap;
                    gap: 0.75rem;
                    margin-bottom: 1.25rem;
                    padding-bottom: 0.75rem;
                }
                .lc-header-left {
                    min-width: 0;
                    flex: 1 1 auto;
                }
                .lc-header-left a {
                    font-size: 1.05rem;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .lc-header-right {
                    width: 100%;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                }
                .lc-header-right span {
                    font-size: 0.72rem;
                    width: 100%;
                    text-align: right;
                }
                .lc-profile-wrapper {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 0.85rem;
                }
                .lc-profile-user-details {
                    gap: 0.75rem;
                }
                .lc-avatar {
                    width: 60px;
                    height: 60px;
                    border-radius: 12px;
                }
                .lc-profile-name {
                    font-size: 1.2rem;
                }
                .lc-profile-stats-row {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 0.4rem;
                    width: 100%;
                }
                .lc-profile-stat-box {
                    padding: 0.5rem 0.35rem;
                    text-align: center;
                    border-radius: 8px;
                }
                .lc-stat-label {
                    font-size: 0.68rem;
                    margin-bottom: 0.15rem;
                    white-space: normal;
                    line-height: 1.1;
                }
                .lc-stat-value {
                    font-size: 0.95rem;
                }
                .lc-stats-container {
                    flex-direction: column;
                    gap: 1rem;
                }
                .lc-difficulty-breakdown {
                    margin-left: 0;
                    width: 100%;
                }
                .lc-diff-row {
                    padding: 0.35rem 0.65rem;
                }
                .lc-calendar-main-layout {
                    flex-direction: column;
                    gap: 1rem;
                }
                .lc-calendar-stats-panel {
                    flex-direction: row;
                    width: 100%;
                    gap: 0.4rem;
                }
                .lc-streak-box {
                    padding: 0.5rem 0.35rem;
                }
                .lc-streak-box .lc-stat-label {
                    font-size: 0.64rem;
                }
                .lc-streak-box .lc-stat-value {
                    font-size: 0.88rem;
                }
                .lc-widget {
                    padding: 1rem 0.85rem;
                }
            }

            .lc-widget-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 1rem;
                margin-bottom: 1rem;
            }

            .lc-widget-title {
                font-size: 1.05rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .lc-category-tag {
                font-size: 0.7rem;
                font-weight: 600;
                padding: 2px 8px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.05);
                color: var(--lc-text-secondary);
                border: 1px solid rgba(255,255,255,0.1);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-left: 0.5rem;
            }

            .lc-widget-body {
                flex: 1;
                display: flex;
                flex-direction: column;
            }

            .lc-content-body {
                display: flex;
                flex-direction: column;
                flex: 1;
                justify-content: space-between;
            }

            /* Skeletons */
            .lc-skeleton {
                background: linear-gradient(90deg, var(--lc-card-border) 25%, var(--lc-hover-bg) 50%, var(--lc-card-border) 75%);
                background-size: 200% 100%;
                animation: loading 1.5s infinite;
                border-radius: 6px;
            }
            @keyframes loading {
                0% { background-position: 200% 0; }
                100% { background-position: -200% 0; }
            }

            /* Profile Header */
            .lc-profile-wrapper {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1.5rem;
                flex: 1;
                flex-wrap: wrap;
                padding: 0.5rem 0;
            }
            .lc-profile-user-details {
                display: flex;
                align-items: center;
                gap: 1.25rem;
            }
            .lc-avatar {
                width: 80px;
                height: 80px;
                border-radius: 16px;
                object-fit: cover;
                border: 2px solid var(--lc-brand-orange);
                background: var(--lc-item-bg);
            }
            .lc-profile-name {
                font-size: 1.5rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                margin: 0 0 0.2rem 0;
            }
            .lc-profile-username {
                color: var(--lc-text-secondary);
                font-size: 0.9rem;
                font-weight: 500;
            }
            .lc-profile-stats-row {
                display: flex;
                gap: 1rem;
                flex-wrap: wrap;
            }
            .lc-profile-stat-box {
                display: flex;
                flex-direction: column;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                padding: 0.65rem 0.75rem;
                border-radius: 10px;
                min-width: 0;
                flex: 1 1 0px;
                box-sizing: border-box;
                overflow: hidden;
            }
            .lc-stat-label {
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--lc-text-secondary);
                margin-bottom: 0.25rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .lc-stat-value {
                font-size: 1.15rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            /* Empty States & Summaries */
            .lc-empty-state {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                flex: 1;
                min-height: 200px;
                padding: 1.5rem;
                text-align: center;
                background: rgba(255, 255, 255, 0.01);
                border: 1px dashed var(--lc-card-border);
                border-radius: 12px;
            }
            .lc-empty-icon {
                font-size: 2.2rem;
                color: var(--lc-brand-orange);
                opacity: 0.7;
                margin-bottom: 0.6rem;
            }
            .lc-empty-title {
                font-size: 0.95rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                margin-bottom: 0.25rem;
            }
            .lc-empty-desc {
                font-size: 0.8rem;
                color: var(--lc-text-secondary);
                max-width: 260px;
                line-height: 1.4;
            }
            .lc-badge-summary {
                margin-top: 1rem;
                padding: 0.6rem 1rem;
                border-radius: 8px;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                font-size: 0.82rem;
                color: var(--lc-text-secondary);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }

            /* Problem Stats */
            .lc-stats-container {
                display: flex;
                flex: 1;
                align-items: center;
                justify-content: center;
                padding: 0.5rem 0;
                width: 100%;
            }
            .lc-donut-chart {
                position: relative;
                width: 130px;
                height: 130px;
                flex-shrink: 0;
            }
            .lc-donut-chart svg {
                width: 130px;
                height: 130px;
            }
            .lc-donut-center {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                text-align: center;
            }
            .lc-donut-total {
                font-size: 1.4rem;
                font-weight: 800;
                color: var(--lc-text-primary);
                line-height: 1;
            }
            .lc-donut-label {
                font-size: 0.7rem;
                font-weight: 600;
                color: var(--lc-text-secondary);
            }
            .lc-difficulty-breakdown {
                flex: 1;
                margin-left: 1.5rem;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            .lc-diff-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                padding: 0.45rem 0.85rem;
                border-radius: 8px;
                gap: 0.5rem;
            }
            .lc-diff-name { font-size: 0.85rem; font-weight: 600; min-width: 65px; white-space: nowrap; }
            .lc-diff-count { font-weight: 700; font-size: 0.95rem; color: var(--lc-text-primary); }
            .lc-diff-total { color: var(--lc-text-muted); font-size: 0.8rem; }
            .lc-diff-beats { font-size: 0.75rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; background: var(--lc-tag-bg); color: var(--lc-text-secondary); border: 1px solid var(--lc-card-border); white-space: nowrap; }
            
            .text-easy { color: var(--lc-easy); }
            .text-medium { color: var(--lc-medium); }
            .text-hard { color: var(--lc-hard); }

            /* Submissions Table */
            .lc-subs-list {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                max-height: 300px;
                overflow-y: auto;
                padding-right: 0.5rem;
            }
            .lc-subs-list::-webkit-scrollbar { width: 6px; }
            .lc-subs-list::-webkit-scrollbar-track { background: transparent; }
            .lc-subs-list::-webkit-scrollbar-thumb { background: var(--lc-card-border); border-radius: 3px; }
            
            .lc-sub-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0.75rem 1rem;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 8px;
                transition: all 0.2s ease;
            }
            .lc-sub-item:hover {
                background: var(--lc-hover-bg);
                border-color: var(--lc-brand-orange);
            }
            .lc-sub-title {
                font-weight: 600;
                color: var(--lc-text-primary);
                text-decoration: none;
                font-size: 0.95rem;
            }
            .lc-sub-title:hover { text-decoration: underline; color: var(--lc-brand-orange); }
            .lc-sub-meta {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                font-size: 0.85rem;
                color: var(--lc-text-secondary);
                font-weight: 500;
            }
            .lc-lang-tag {
                background: var(--lc-tag-bg);
                color: var(--lc-blue);
                font-weight: 600;
                padding: 2px 10px;
                border-radius: 12px;
                border: 1px solid var(--lc-card-border);
            }

            /* Heatmap */
            .lc-heatmap-container {
                overflow-x: auto;
                padding-bottom: 1rem;
            }
            .lc-heatmap-container::-webkit-scrollbar {
                height: 6px;
            }
            .lc-heatmap-container::-webkit-scrollbar-track {
                background: rgba(255, 255, 255, 0.02);
                border-radius: 4px;
            }
            .lc-heatmap-container::-webkit-scrollbar-thumb {
                background: rgba(255, 255, 255, 0.1);
                border-radius: 4px;
            }
            .lc-heatmap-container::-webkit-scrollbar-thumb:hover {
                background: rgba(255, 255, 255, 0.2);
            }
            .lc-heatmap {
                width: max-content;
            }
            .lc-heatmap-wrapper {
                display: flex;
                align-items: flex-start;
                gap: 14px;
                width: max-content;
                padding: 0.5rem 0;
            }
            .lc-heatmap-month-block {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
            }
            .lc-heatmap-month-cols {
                display: flex;
                gap: 3px;
            }
            .lc-heatmap-col {
                display: flex;
                flex-direction: column;
                gap: 3px;
            }
            .lc-heatmap-cell {
                width: 11px;
                height: 11px;
                border-radius: 2px;
                background-color: var(--lc-cell-empty);
                border: 1px solid var(--lc-card-border);
                transition: transform 0.15s ease;
            }
            .lc-heatmap-cell:hover {
                transform: scale(1.25);
            }
            .lc-heatmap-cell.empty-placeholder {
                visibility: hidden;
                border: none !important;
                pointer-events: none;
            }
            .lc-heatmap-cell[data-level="1"] { background-color: var(--lc-green-1); border-color: transparent; }
            .lc-heatmap-cell[data-level="2"] { background-color: var(--lc-green-2); border-color: transparent; }
            .lc-heatmap-cell[data-level="3"] { background-color: var(--lc-green-3); border-color: transparent; }
            .lc-heatmap-cell[data-level="4"] { background-color: var(--lc-green-4); border-color: transparent; }

            .lc-heatmap-month-label {
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--lc-text-secondary);
                text-align: center;
            }

            /* Calendar */
            .lc-calendar-main-layout {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1.5rem;
                flex: 1;
                flex-wrap: wrap;
            }
            .lc-calendar-stats-panel {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
                flex: 1;
                min-width: 160px;
            }
            .lc-calendar-month-panel {
                display: flex;
                flex-direction: column;
                align-items: center;
                flex: 1.5;
                min-width: 270px;
            }
            .lc-calendar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.75rem;
                width: 100%;
                max-width: 280px;
            }
            .lc-calendar-header strong {
                color: var(--lc-text-primary);
                font-size: 0.95rem;
                font-weight: 700;
            }
            .lc-calendar-grid-container {
                max-width: 280px;
                width: 100%;
                margin: 0 auto;
            }
            .lc-calendar-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 4px;
                text-align: center;
                justify-items: center;
            }
            .lc-cal-day-label {
                font-size: 0.75rem;
                font-weight: 700;
                color: var(--lc-text-secondary);
                margin-bottom: 0.25rem;
            }
            .lc-cal-cell {
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--lc-text-primary);
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
            }
            .lc-cal-cell.active {
                background: var(--lc-green-3);
                border-color: var(--lc-green-3);
                color: #fff;
            }
            .lc-cal-cell.today {
                box-shadow: 0 0 0 2px var(--lc-brand-orange);
            }
            .lc-cal-cell.missed {
                opacity: 0.5;
            }
            .lc-cal-cell.future {
                opacity: 0.3;
                background: transparent;
                border-color: transparent;
            }
            .lc-streak-info {
                display: flex;
                gap: 0.75rem;
                margin-top: 0.75rem;
                padding-top: 0.75rem;
                border-top: 1px solid var(--lc-card-border);
            }
            .lc-streak-box {
                flex: 1;
                text-align: center;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 8px;
                padding: 0.65rem 0.85rem;
            }

            /* Languages */
            .lc-lang-bars {
                display: flex;
                flex-direction: column;
                gap: 1rem;
                margin-top: 0.5rem;
            }
            .lc-lang-row { display: flex; flex-direction: column; gap: 0.35rem; }
            .lc-lang-info {
                display: flex;
                justify-content: space-between;
                font-size: 0.9rem;
                font-weight: 600;
                color: var(--lc-text-primary);
            }
            .lc-lang-bar-bg {
                height: 10px;
                background: var(--lc-cell-empty);
                border-radius: 6px;
                overflow: hidden;
                border: 1px solid var(--lc-card-border);
            }
            .lc-lang-bar-fill {
                height: 100%;
                border-radius: 6px;
                transition: width 1s ease;
            }

            /* Badges */
            .lc-badges-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(95px, 1fr));
                gap: 0.75rem;
            }
            .lc-badge-item {
                text-align: center;
            }
            .lc-badge-img {
                width: 64px;
                height: 64px;
                margin: 0 auto 0.5rem;
                background: var(--lc-item-bg);
                border: 1px solid var(--lc-card-border);
                border-radius: 50%;
                padding: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .lc-badge-img img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
            .lc-badge-name {
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--lc-text-primary);
                line-height: 1.2;
            }

            /* Skill Tags */
            .lc-skills-cols {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            .lc-skill-col h4 {
                font-size: 0.95rem;
                font-weight: 700;
                color: var(--lc-text-primary);
                margin-bottom: 1rem;
                border-bottom: 2px solid var(--lc-card-border);
                padding-bottom: 0.5rem;
            }
            .lc-skill-tags {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            .lc-skill-tag {
                background: var(--lc-item-bg);
                color: var(--lc-text-primary);
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 0.85rem;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 6px;
                border: 1px solid var(--lc-card-border);
            }
            .lc-skill-tag.fundamental { border-color: rgba(0, 184, 163, 0.4); }
            .lc-skill-tag.intermediate { border-color: rgba(255, 192, 30, 0.5); }
            .lc-skill-tag.advanced { border-color: rgba(239, 71, 67, 0.5); }
            
            .lc-skill-count {
                background: var(--lc-tag-bg);
                color: var(--lc-text-secondary);
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 0.75rem;
                font-weight: 700;
            }

            .hidden { display: none !important; }
        </style>

        <?php if (empty($lcUsername)): ?>
        <!-- STATE 1: NOT CONNECTED -->
        <div class="lc-connect-container">
            <div class="lc-connect-card">
                <svg class="lc-logo-svg" viewBox="0 0 24 24" fill="var(--lc-brand-orange)">
                    <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1-.028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                </svg>
                <h2>Connect Your LeetCode Account</h2>
                <p>Link your public LeetCode profile to view your stats, track your progress, and compete on the leaderboard.</p>
                
                <form id="lc-connect-form">
                    <div class="lc-input-group">
                        <input type="text" id="lc-username-input" placeholder="LeetCode Username" required>
                        <button type="submit" class="lc-btn-primary" id="btn-connect">Connect Account</button>
                    </div>
                </form>
                
                <div class="lc-features-preview">
                    <div class="lc-feature-card">
                        <i class="fas fa-chart-pie"></i>
                        Problem Stats
                    </div>
                    <div class="lc-feature-card">
                        <i class="fas fa-calendar-alt"></i>
                        Activity Heatmap
                    </div>
                    <div class="lc-feature-card">
                        <i class="fas fa-trophy"></i>
                        Contest Rating
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- STATE 2: CONNECTED DASHBOARD -->
        <div class="lc-dashboard-container">
            <!-- Header Area -->
            <div class="lc-header-area">
                <div class="lc-header-left">
                    <svg class="lc-logo-svg" style="width:32px;height:32px;margin:0" viewBox="0 0 24 24" fill="var(--lc-brand-orange)">
                        <path d="M13.483 0a1.374 1.374 0 0 0-.961.438L7.116 6.226l-3.854 4.126a5.266 5.266 0 0 0-1.209 2.104 5.35 5.35 0 0 0-.125.513 5.527 5.527 0 0 0 .062 2.362 5.83 5.83 0 0 0 .349 1.017 5.939 5.939 0 0 0 1.271 1.541l5.967 5.68c.8.761 2.077.761 2.877 0l5.611-5.343a1.363 1.363 0 0 0 .384-1.223 1.371 1.371 0 0 0-.801-1.014 1.36 1.36 0 0 0-1.4.157l-4.996 4.757a.168.168 0 0 1-.237 0l-5.967-5.68a2.592 2.592 0 0 1-.555-.678 2.327 2.327 0 0 1-.154-.452 2.222 2.222 0 0 1-.028-1.05 2.126 2.126 0 0 1 .521-.904l3.854-4.126 5.406-5.788a.17.17 0 0 1 .247 0l5.056 4.814a1.362 1.362 0 0 0 1.399.167 1.37 1.37 0 0 0 .802-1.015 1.364 1.364 0 0 0-.384-1.224l-5.61-5.343a2.036 2.036 0 0 0-1.439-.562zm-4.322 10.155a1.367 1.367 0 0 0-1.366 1.367 1.367 1.367 0 0 0 1.366 1.366h7.684a1.367 1.367 0 0 0 1.366-1.366 1.367 1.367 0 0 0-1.366-1.367H9.161z"/>
                    </svg>
                    <a href="https://leetcode.com/<?php echo esc($lcUsername); ?>" target="_blank" id="lc-username-display">@<?php echo esc($lcUsername); ?></a>
                </div>
                <div class="lc-header-right">
                    <span id="lc-last-refresh" style="font-size:0.8rem;color:var(--lc-text-secondary);margin-right:0.5rem;"></span>
                    <button class="lc-btn-icon" id="btn-refresh" title="Refresh Data"><i class="fas fa-sync-alt"></i></button>
                    <button class="lc-btn-icon lc-btn-danger" id="btn-disconnect" title="Disconnect Account"><i class="fas fa-unlink"></i></button>
                </div>
            </div>

            <!-- Widget Grid -->
            <div class="lc-grid" id="lc-widget-grid">
                
                <!-- Profile Header (Full Width) -->
                <div class="lc-widget" data-widget-id="profile" data-size="full">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-user-circle"></i> Profile Header <span class="lc-category-tag">Overview</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-profile" class="lc-profile-wrapper">
                            <div class="lc-skeleton" style="width:90px;height:90px;border-radius:16px;"></div>
                            <div style="flex:1">
                                <div class="lc-skeleton" style="width:200px;height:2rem;margin-bottom:0.5rem"></div>
                                <div class="lc-skeleton" style="width:100px;height:1rem;margin-bottom:1rem"></div>
                                <div class="lc-skeleton" style="width:300px;height:3rem"></div>
                            </div>
                        </div>
                        <div id="content-profile" class="lc-profile-wrapper hidden">
                            <div class="lc-profile-user-details">
                                <img id="p-avatar" class="lc-avatar" src="" alt="Avatar">
                                <div>
                                    <h2 id="p-name" class="lc-profile-name"></h2>
                                    <div id="p-username" class="lc-profile-username"></div>
                                </div>
                            </div>
                            <div class="lc-profile-stats-row">
                                <div class="lc-profile-stat-box">
                                    <span class="lc-stat-label">Global Rank</span>
                                    <span class="lc-stat-value"><i class="fas fa-medal" style="color:var(--lc-brand-orange)"></i> <span id="p-rank"></span></span>
                                </div>
                                <div class="lc-profile-stat-box">
                                    <span class="lc-stat-label">Reputation</span>
                                    <span class="lc-stat-value"><i class="fas fa-star" style="color:var(--lc-brand-orange)"></i> <span id="p-reputation"></span></span>
                                </div>
                                <div class="lc-profile-stat-box">
                                    <span class="lc-stat-label">Total Solved</span>
                                    <span class="lc-stat-value" id="p-total-solved"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Problem Stats (Half Width) -->
                <div class="lc-widget" data-widget-id="stats" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-chart-pie"></i> Problem Solving Stats <span class="lc-category-tag">Analytics</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-stats" class="lc-skeleton" style="width:100%;height:150px"></div>
                        <div id="content-stats" class="lc-stats-container hidden">
                            <div class="lc-donut-chart" id="stats-donut-container">
                                <svg width="130" height="130" viewBox="0 0 150 150">
                                    <!-- Background ring -->
                                    <circle cx="75" cy="75" r="65" fill="none" stroke="var(--lc-hover-bg)" stroke-width="8"></circle>
                                    <!-- Foreground rings (JS populated) -->
                                    <circle id="donut-easy" cx="75" cy="75" r="65" fill="none" stroke="var(--lc-easy)" stroke-width="8" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                    <circle id="donut-medium" cx="75" cy="75" r="65" fill="none" stroke="var(--lc-medium)" stroke-width="8" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                    <circle id="donut-hard" cx="75" cy="75" r="65" fill="none" stroke="var(--lc-hard)" stroke-width="8" stroke-dasharray="0 408" stroke-linecap="round" transform="rotate(-90 75 75)"></circle>
                                </svg>
                                <div class="lc-donut-center">
                                    <div class="lc-donut-total" id="stats-total">0</div>
                                    <div class="lc-donut-label">Solved</div>
                                </div>
                            </div>
                            <div class="lc-difficulty-breakdown">
                                <div class="lc-diff-row">
                                    <span class="lc-diff-name text-easy">Easy</span>
                                    <div><span class="lc-diff-count" id="stats-easy-count">0</span><span class="lc-diff-total" id="stats-easy-total">/0</span></div>
                                    <span class="lc-diff-beats" id="stats-easy-beats">Beats 0%</span>
                                </div>
                                <div class="lc-diff-row">
                                    <span class="lc-diff-name text-medium">Medium</span>
                                    <div><span class="lc-diff-count" id="stats-med-count">0</span><span class="lc-diff-total" id="stats-med-total">/0</span></div>
                                    <span class="lc-diff-beats" id="stats-med-beats">Beats 0%</span>
                                </div>
                                <div class="lc-diff-row">
                                    <span class="lc-diff-name text-hard">Hard</span>
                                    <div><span class="lc-diff-count" id="stats-hard-count">0</span><span class="lc-diff-total" id="stats-hard-total">/0</span></div>
                                    <span class="lc-diff-beats" id="stats-hard-beats">Beats 0%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contest Rating (Half Width) -->
                <div class="lc-widget" data-widget-id="contest" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-trophy"></i> Contest Rating <span class="lc-category-tag">Analytics</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-contest" class="lc-skeleton" style="width:100%;height:220px"></div>
                        <div id="content-contest" class="hidden" style="display:flex;flex-direction:column;flex:1;">
                            <div style="display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:0.5rem;margin-bottom:1rem;width:100%;box-sizing:border-box;">
                                <div class="lc-profile-stat-box">
                                    <div class="lc-stat-label">Contest Rating</div>
                                    <div class="lc-stat-value" id="cr-rating">N/A</div>
                                </div>
                                <div class="lc-profile-stat-box">
                                    <div class="lc-stat-label">Global Ranking</div>
                                    <div class="lc-stat-value" id="cr-global">N/A</div>
                                </div>
                                <div class="lc-profile-stat-box">
                                    <div class="lc-stat-label">Attended</div>
                                    <div class="lc-stat-value" id="cr-attended">0</div>
                                </div>
                            </div>
                            <div id="cr-chart-wrapper" style="flex:1;min-height:140px;position:relative;display:flex;align-items:center;justify-content:center;width:100%;">
                                <canvas id="contestChart"></canvas>
                                <div id="cr-empty-msg" class="hidden" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1rem;text-align:center;color:var(--lc-text-secondary);width:100%;">
                                    <i class="fas fa-trophy" style="font-size:1.8rem;margin-bottom:0.4rem;opacity:0.35;color:var(--lc-brand-orange);"></i>
                                    <div style="font-weight:600;font-size:0.85rem;color:var(--lc-text-primary);">No Contest Rating History</div>
                                    <div style="font-size:0.75rem;opacity:0.75;margin-top:0.2rem;">Participate in LeetCode contests to see your rating growth graph!</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Submissions Calendar (Half Width) -->
                <div class="lc-widget" data-widget-id="calendar" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-calendar-alt"></i> Daily Submissions <span class="lc-category-tag">Activity</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-calendar" class="lc-skeleton" style="width:100%;height:200px"></div>
                        <div id="content-calendar" class="lc-content-body hidden">
                            <div class="lc-calendar-main-layout">
                                <div class="lc-calendar-stats-panel">
                                    <div class="lc-streak-box">
                                        <div class="lc-stat-label">Current Streak</div>
                                        <div class="lc-stat-value"><span id="cal-streak-curr">0</span> 🔥</div>
                                    </div>
                                    <div class="lc-streak-box">
                                        <div class="lc-stat-label">Max Streak</div>
                                        <div class="lc-stat-value"><span id="cal-streak-max">0</span> 🏆</div>
                                    </div>
                                    <div class="lc-streak-box">
                                        <div class="lc-stat-label">Active Days (Year)</div>
                                        <div class="lc-stat-value"><span id="cal-total-active">0</span> 📅</div>
                                    </div>
                                </div>
                                <div class="lc-calendar-month-panel">
                                    <div class="lc-calendar-header">
                                        <button class="lc-btn-icon" id="cal-prev"><i class="fas fa-chevron-left"></i></button>
                                        <strong id="cal-month-year">Month Year</strong>
                                        <button class="lc-btn-icon" id="cal-next"><i class="fas fa-chevron-right"></i></button>
                                    </div>
                                    <div class="lc-calendar-grid-container">
                                        <div class="lc-calendar-grid" id="cal-grid">
                                            <!-- JS Populated -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Submissions (Half Width) -->
                <div class="lc-widget" data-widget-id="recent" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-history"></i> Recent Submissions <span class="lc-category-tag">Activity</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-recent" class="lc-skeleton" style="width:100%;height:180px"></div>
                        <div id="content-recent" class="lc-content-body hidden">
                            <div class="lc-subs-list" id="rs-list">
                                <!-- JS Populated -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Heatmap (Full Width) -->
                <div class="lc-widget" data-widget-id="heatmap" data-size="full">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-th"></i> <span id="hm-total">0</span> submissions in the past one year <span class="lc-category-tag">Activity</span></div>
                        <div class="lc-sub-meta" style="font-size:0.85rem">
                            <span>Total active days: <strong id="hm-active-days" style="color:var(--lc-text-primary)">0</strong></span>
                            <span style="margin-left:1rem">Max streak: <strong id="hm-max-streak" style="color:var(--lc-text-primary)">0</strong></span>
                        </div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-heatmap" class="lc-skeleton" style="width:100%;height:120px"></div>
                        <div id="content-heatmap" class="lc-heatmap-container hidden">
                            <div class="lc-heatmap" id="hm-grid">
                                <!-- JS Populated -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Languages (Half Width) -->
                <div class="lc-widget" data-widget-id="languages" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-code"></i> Languages <span class="lc-category-tag">Skills</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-languages" class="lc-skeleton" style="width:100%;height:180px"></div>
                        <div id="content-languages" class="lc-content-body hidden">
                            <div class="lc-lang-bars" id="lang-list">
                                <!-- JS Populated -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Badges (Half Width) -->
                <div class="lc-widget" data-widget-id="badges" data-size="half">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-award"></i> Badges <span class="lc-category-tag">Achievements</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-badges" class="lc-skeleton" style="width:100%;height:220px"></div>
                        <div id="content-badges" class="lc-content-body hidden">
                            <div class="lc-badges-grid" id="badge-list">
                                <!-- JS Populated -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Skill Tags (Full Width) -->
                <div class="lc-widget" data-widget-id="skills" data-size="full">
                    <div class="lc-widget-header">
                        <div class="lc-widget-title"><i class="fas fa-tags"></i> Skills <span class="lc-category-tag">Skills</span></div>
                    </div>
                    <div class="lc-widget-body">
                        <div id="skel-skills" class="lc-skeleton" style="width:100%;height:150px"></div>
                        <div id="content-skills" class="lc-content-body hidden">
                            <div class="lc-skills-cols">
                                <div class="lc-skill-col">
                                    <h4>Advanced</h4>
                                    <div class="lc-skill-tags" id="skills-advanced"></div>
                                </div>
                                <div class="lc-skill-col">
                                    <h4>Intermediate</h4>
                                    <div class="lc-skill-tags" id="skills-intermediate"></div>
                                </div>
                                <div class="lc-skill-col">
                                    <h4>Fundamental</h4>
                                    <div class="lc-skill-tags" id="skills-fundamental"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- GLOBAL FOOTER REQUIRE FIRST (Loads jQuery, Bootstrap, SweetAlert2) -->
<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<!-- Page Specific JS Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const apiUrl = '../api/leetcode-proxy.php';
    const isConnected = <?php echo empty($lcUsername) ? 'false' : 'true'; ?>;
    
    // State 1: Connection Form Handler
    if (!isConnected) {
        $('#lc-connect-form').on('submit', function(e) {
            e.preventDefault();
            const username = $('#lc-username-input').val().trim();
            if (!username) return;
            
            const btn = $('#btn-connect');
            const origText = btn.text();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Connecting...');
            
            $.post(apiUrl, {
                action: 'link_account',
                username: username,
                csrf_token: csrfToken
            }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Connected!', text: 'Account linked successfully.', timer: 1500, showConfirmButton: false }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Failed to connect. Make sure username is correct.', 'error');
                    btn.prop('disabled', false).text(origText);
                }
            }, 'json').fail(function(xhr) {
                let msg = 'Server error during connection.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                Swal.fire('Error', msg, 'error');
                btn.prop('disabled', false).text(origText);
            });
        });
        return; // Don't run dashboard code when disconnected
    }

    // Helper for HTML escaping
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // State 2: Dashboard Object
    window.LeetCodeDashboard = {
        rawData: null,
        currentMonth: new Date(),
        chartInstance: null,

        init: function() {
            this.bindEvents();
            this.loadData();
        },

        bindEvents: function() {
            const self = this;
            $('#btn-refresh').on('click', function() {
                const btn = $(this);
                const icon = btn.find('i');
                btn.prop('disabled', true);
                icon.addClass('fa-spin');
                self.loadData(true).always(() => {
                    icon.removeClass('fa-spin');
                    btn.prop('disabled', false);
                });
            });

            $('#btn-disconnect').on('click', function() {
                Swal.fire({
                    title: 'Disconnect LeetCode?',
                    text: "You can reconnect anytime later.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4743',
                    cancelButtonColor: '#4b5563',
                    confirmButtonText: 'Yes, Disconnect'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const btn = $('#btn-disconnect');
                        const origHtml = btn.html();
                        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

                        $.post(apiUrl, { action: 'unlink_account', csrf_token: csrfToken }, function(res) {
                            if (res && res.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Disconnected',
                                    text: 'LeetCode account unlinked successfully.',
                                    timer: 1200,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                btn.prop('disabled', false).html(origHtml);
                                Swal.fire('Error', (res && res.message) ? res.message : 'Failed to disconnect.', 'error');
                            }
                        }, 'json').fail(function(xhr) {
                            btn.prop('disabled', false).html(origHtml);
                            let msg = 'Failed to disconnect.';
                            if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                            Swal.fire('Error', msg, 'error');
                        });
                    }
                });
            });

            $('#cal-prev').on('click', () => {
                this.currentMonth.setMonth(this.currentMonth.getMonth() - 1);
                this.renderCalendarGrid();
            });

            $('#cal-next').on('click', () => {
                this.currentMonth.setMonth(this.currentMonth.getMonth() + 1);
                this.renderCalendarGrid();
            });
        },

        loadData: function(forceRefresh = false) {
            $('[id^="skel-"]').show();
            $('.hidden[id^="content-"]').hide();

            return $.post(apiUrl, {
                action: 'fetch_all',
                force_refresh: forceRefresh ? 1 : 0,
                csrf_token: csrfToken
            }, (res) => {
                if (res && res.success && res.data) {
                    this.rawData = res.data;
                    this.renderAll();
                    
                    const lastSync = new Date();
                    $('#lc-last-refresh').text('Last synced: ' + lastSync.toLocaleTimeString());
                } else if (res && !res.success && res.message) {
                    $('[id^="skel-"]').hide();
                    Swal.fire('Notice', res.message, 'warning');
                }
            }, 'json').fail(function(xhr, status, errorThrown) {
                $('[id^="skel-"]').hide();
                let msg = 'Network error fetching LeetCode data.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    try {
                        const parsed = JSON.parse(xhr.responseText);
                        if (parsed.message) msg = parsed.message;
                    } catch(e) {
                        msg = 'Server Error ' + xhr.status + ':\n' + xhr.responseText.substring(0, 150) + (xhr.responseText.length > 150 ? '...' : '');
                    }
                } else {
                    msg = 'HTTP ' + xhr.status + ' - ' + errorThrown;
                }
                Swal.fire('Error', msg, 'error');
            });
        },

        renderAll: function() {
            const data = this.rawData || {};
            
            try {
                if (data.user_profile) {
                    this.renderProfile(data.user_profile);
                }
            } catch (err) {
                console.error('Error rendering profile:', err);
            }

            try {
                if (data.problem_stats) {
                    this.renderStats(data.problem_stats);
                }
            } catch (err) {
                console.error('Error rendering stats:', err);
            }

            try {
                if (data.languages) {
                    this.renderLanguages(data.languages);
                }
            } catch (err) {
                console.error('Error rendering languages:', err);
            }

            try {
                if (data.badges) {
                    this.renderBadges(data.badges);
                }
            } catch (err) {
                console.error('Error rendering badges:', err);
            }

            try {
                if (data.skill_stats) {
                    this.renderSkills(data.skill_stats);
                }
            } catch (err) {
                console.error('Error rendering skills:', err);
            }

            try {
                if (data.calendar) {
                    this.renderHeatmap(data.calendar);
                    this.renderCalendar(data.calendar);
                }
            } catch (err) {
                console.error('Error rendering calendar:', err);
            }

            try {
                if (data.recent_submissions) {
                    this.renderSubmissions(data.recent_submissions);
                }
            } catch (err) {
                console.error('Error rendering submissions:', err);
            }

            try {
                if (data.contest_ranking) {
                    this.renderContests(data.contest_ranking.ranking, data.contest_ranking.history);
                }
            } catch (err) {
                console.error('Error rendering contests:', err);
            }

            $('[id^="skel-"]').hide();
            $('[id^="content-"]').removeClass('hidden').show();
        },

        renderProfile: function(profileData) {
            const profile = profileData.profile || {};
            const fallbackAvatar = 'https://assets.leetcode.com/users/avatars/avatar_1701233055.png';
            const avatarUrl = profile.userAvatar || fallbackAvatar;

            $('#p-avatar').attr('src', avatarUrl).on('error', function() {
                $(this).attr('src', fallbackAvatar);
            });
            $('#p-name').text(profile.realName || profileData.username || '');
            $('#p-username').text('@' + (profileData.username || ''));
            $('#p-rank').text(profile.ranking ? Number(profile.ranking).toLocaleString() : 'N/A');
            $('#p-reputation').text(profile.reputation || 0);
            
            let totalSolved = 0;
            if (this.rawData && this.rawData.problem_stats && this.rawData.problem_stats.solved) {
                const totalObj = this.rawData.problem_stats.solved.find(x => x.difficulty === 'All');
                if (totalObj) totalSolved = totalObj.count || 0;
            }
            $('#p-total-solved').text(totalSolved);
        },

        renderStats: function(statsData) {
            const solved = statsData.solved || [];
            const allQs = statsData.allQuestions || [];
            const beats = statsData.beats || [];
            
            let total = 0, easy = 0, med = 0, hard = 0;
            let easyAll = 0, medAll = 0, hardAll = 0, totalAll = 0;

            solved.forEach(s => {
                if(s.difficulty === 'All') total = s.count || 0;
                if(s.difficulty === 'Easy') easy = s.count || 0;
                if(s.difficulty === 'Medium') med = s.count || 0;
                if(s.difficulty === 'Hard') hard = s.count || 0;
            });

            allQs.forEach(q => {
                if(q.difficulty === 'All') totalAll = q.count || 0;
                if(q.difficulty === 'Easy') easyAll = q.count || 0;
                if(q.difficulty === 'Medium') medAll = q.count || 0;
                if(q.difficulty === 'Hard') hardAll = q.count || 0;
            });
            
            if (totalAll === 0) totalAll = easyAll + medAll + hardAll || 3200;

            $('#stats-total').text(total);
            $('#stats-easy-count').text(easy);
            $('#stats-easy-total').text('/' + easyAll);
            $('#stats-med-count').text(med);
            $('#stats-med-total').text('/' + medAll);
            $('#stats-hard-count').text(hard);
            $('#stats-hard-total').text('/' + hardAll);
            
            if (beats && Array.isArray(beats)) {
                const formatBeats = (b) => {
                    if (b && typeof b.percentage === 'number' && !isNaN(b.percentage)) {
                        return 'Beats ' + b.percentage.toFixed(1) + '%';
                    }
                    return 'Beats 0%';
                };

                const eBeats = beats.find(x => x.difficulty === 'Easy');
                const mBeats = beats.find(x => x.difficulty === 'Medium');
                const hBeats = beats.find(x => x.difficulty === 'Hard');

                $('#stats-easy-beats').text(formatBeats(eBeats));
                $('#stats-med-beats').text(formatBeats(mBeats));
                $('#stats-hard-beats').text(formatBeats(hBeats));
            } else {
                $('#stats-easy-beats').text('Beats 0%');
                $('#stats-med-beats').text('Beats 0%');
                $('#stats-hard-beats').text('Beats 0%');
            }

            const circ = 2 * Math.PI * 65; // ~408.4
            let offset = 0;
            
            const pEasy = totalAll > 0 ? (easy / totalAll) * circ : 0;
            $('#donut-easy').attr('stroke-dasharray', `${pEasy} ${circ}`);
            $('#donut-easy').attr('stroke-dashoffset', -offset);
            offset += pEasy;

            const pMed = totalAll > 0 ? (med / totalAll) * circ : 0;
            $('#donut-medium').attr('stroke-dasharray', `${pMed} ${circ}`);
            $('#donut-medium').attr('stroke-dashoffset', -offset);
            offset += pMed;

            const pHard = totalAll > 0 ? (hard / totalAll) * circ : 0;
            $('#donut-hard').attr('stroke-dasharray', `${pHard} ${circ}`);
            $('#donut-hard').attr('stroke-dashoffset', -offset);
        },

        renderCalendarGrid: function() {
            if (!this.rawData || !this.rawData.calendar) return;
            let calData = {};
            try {
                calData = typeof this.rawData.calendar.submissionCalendar === 'string'
                    ? JSON.parse(this.rawData.calendar.submissionCalendar || '{}')
                    : (this.rawData.calendar.submissionCalendar || {});
            } catch(e) {
                calData = {};
            }
            
            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            $('#cal-month-year').text(monthNames[month] + ' ' + year);
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
            let html = '';
            const dayLabels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
            dayLabels.forEach(d => {
                html += `<div class="lc-cal-day-label">${d}</div>`;
            });

            for (let i = 0; i < firstDay; i++) {
                html += `<div></div>`;
            }

            const today = new Date();
            today.setHours(0,0,0,0);

            for (let day = 1; day <= daysInMonth; day++) {
                const currentDt = new Date(year, month, day);
                const isFuture = currentDt > today;
                const isToday = currentDt.getTime() === today.getTime();
                
                let subs = 0;
                for (const ts in calData) {
                    const dt = new Date(parseInt(ts) * 1000);
                    if (dt.getFullYear() === year && dt.getMonth() === month && dt.getDate() === day) {
                        subs += calData[ts];
                    }
                }

                let classes = 'lc-cal-cell';
                if (isToday) classes += ' today';
                if (isFuture) classes += ' future';
                else if (subs > 0) classes += ' active';
                else if (!isFuture && !isToday) classes += ' missed';

                html += `<div class="${classes}" title="${subs} submissions">${day}</div>`;
            }

            $('#cal-grid').html(html);
        },

        renderCalendar: function(calendar) {
            let calData = {};
            try {
                calData = typeof calendar.submissionCalendar === 'string'
                    ? JSON.parse(calendar.submissionCalendar || '{}')
                    : (calendar.submissionCalendar || {});
            } catch(e) {
                calData = {};
            }

            const dates = [];
            for (const ts in calData) {
                if (calData[ts] > 0) {
                    const d = new Date(parseInt(ts) * 1000);
                    d.setHours(0, 0, 0, 0);
                    dates.push(d.getTime());
                }
            }

            let maxStreak = calendar.streak || 0;
            let currentStreak = 0;
            let totalActive = calendar.totalActiveDays || 0;

            if (dates.length > 0) {
                const uniqueDates = Array.from(new Set(dates)).sort((a, b) => a - b);
                if (totalActive === 0) totalActive = uniqueDates.length;

                let tempStreak = 0;
                let prevDate = null;
                const ONE_DAY = 86400000;

                uniqueDates.forEach(t => {
                    if (prevDate === null) {
                        tempStreak = 1;
                    } else if (t - prevDate === ONE_DAY) {
                        tempStreak++;
                    } else {
                        tempStreak = 1;
                    }
                    if (tempStreak > maxStreak) maxStreak = tempStreak;
                    prevDate = t;
                });

                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const todayTime = today.getTime();
                const yesterdayTime = todayTime - ONE_DAY;

                if (uniqueDates.includes(todayTime) || uniqueDates.includes(yesterdayTime)) {
                    let checkTime = uniqueDates.includes(todayTime) ? todayTime : yesterdayTime;
                    while (uniqueDates.includes(checkTime)) {
                        currentStreak++;
                        checkTime -= ONE_DAY;
                    }
                }
            }

            $('#cal-streak-curr').text(currentStreak);
            $('#cal-streak-max').text(maxStreak);
            $('#cal-total-active').text(totalActive);

            this.currentMonth = new Date();
            this.renderCalendarGrid();
        },

        renderHeatmap: function(calendar) {
            let calData = {};
            try {
                calData = typeof calendar.submissionCalendar === 'string'
                    ? JSON.parse(calendar.submissionCalendar || '{}')
                    : (calendar.submissionCalendar || {});
            } catch(e) {
                calData = {};
            }

            const totalActive = calendar.totalActiveDays || Object.keys(calData).length;
            
            let totalSubmissions = 0;
            for (const ts in calData) {
                totalSubmissions += calData[ts];
            }
            if (totalSubmissions === 0 && totalActive > 0) totalSubmissions = totalActive;

            $('#hm-total').text(totalSubmissions);
            $('#hm-active-days').text(totalActive);
            $('#hm-max-streak').text(calendar.streak || 0);

            const hmGrid = $('#hm-grid');
            hmGrid.empty();

            const shortMonths = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const monthsToRender = [];
            for (let i = 11; i >= 0; i--) {
                const mDate = new Date(today.getFullYear(), today.getMonth() - i, 1);
                monthsToRender.push({
                    year: mDate.getFullYear(),
                    month: mDate.getMonth()
                });
            }

            const wrapper = $('<div class="lc-heatmap-wrapper"></div>');

            monthsToRender.forEach(mInfo => {
                const year = mInfo.year;
                const month = mInfo.month;
                const daysInMonth = new Date(year, month + 1, 0).getDate();

                const monthBlock = $('<div class="lc-heatmap-month-block"></div>');
                const colsContainer = $('<div class="lc-heatmap-month-cols"></div>');

                let currentColHtml = '<div class="lc-heatmap-col">';

                for (let day = 1; day <= daysInMonth; day++) {
                    const dt = new Date(year, month, day);
                    if (dt > today) break;

                    const dayOfWeek = dt.getDay(); // 0 = Sun, 6 = Sat

                    if (day === 1 && dayOfWeek > 0) {
                        for (let p = 0; p < dayOfWeek; p++) {
                            currentColHtml += '<div class="lc-heatmap-cell empty-placeholder"></div>';
                        }
                    }

                    let subs = 0;
                    for (const ts in calData) {
                        const cDt = new Date(parseInt(ts) * 1000);
                        if (cDt.getFullYear() === year && cDt.getMonth() === month && cDt.getDate() === day) {
                            subs += calData[ts];
                        }
                    }

                    let level = 0;
                    if (subs >= 1) level = 1;
                    if (subs >= 3) level = 2;
                    if (subs >= 6) level = 3;
                    if (subs >= 10) level = 4;

                    const title = `${dt.toDateString()}: ${subs} submission${subs !== 1 ? 's' : ''}`;
                    currentColHtml += `<div class="lc-heatmap-cell" data-level="${level}" title="${title}"></div>`;

                    if (dayOfWeek === 6 || day === daysInMonth || new Date(year, month, day + 1) > today) {
                        if ((day === daysInMonth || new Date(year, month, day + 1) > today) && dayOfWeek < 6) {
                            for (let p = dayOfWeek + 1; p <= 6; p++) {
                                currentColHtml += '<div class="lc-heatmap-cell empty-placeholder"></div>';
                            }
                        }
                        currentColHtml += '</div>';
                        colsContainer.append(currentColHtml);
                        currentColHtml = '<div class="lc-heatmap-col">';
                    }
                }

                monthBlock.append(colsContainer);
                monthBlock.append(`<div class="lc-heatmap-month-label">${shortMonths[month]}</div>`);
                wrapper.append(monthBlock);
            });

            hmGrid.append(wrapper);
        },

        renderSubmissions: function(subs) {
            const list = $('#rs-list');
            list.empty();
            if (!subs || subs.length === 0) {
                list.html(`
                <div class="lc-empty-state">
                    <div class="lc-empty-icon"><i class="fas fa-inbox"></i></div>
                    <div class="lc-empty-title">No Recent Submissions</div>
                    <div class="lc-empty-desc">Solve problems on LeetCode to see your latest submissions and status updates live here.</div>
                    <a href="https://leetcode.com/problemset/all/" target="_blank" class="lc-btn-icon" style="margin-top:0.75rem;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;background:var(--lc-brand-orange);color:#fff;border:none;"><i class="fas fa-external-link-alt"></i> Solve Problems</a>
                </div>
                `);
                return;
            }

            subs.slice(0, 15).forEach(sub => {
                const title = sub.title || 'Problem';
                const lang = sub.lang || 'Code';
                const time = sub.timestamp;
                const slug = sub.titleSlug || '';
                
                let relTime = 'recently';
                if(time) {
                    const diff = Math.floor(Date.now()/1000) - parseInt(time);
                    if(diff < 60) relTime = 'just now';
                    else if(diff < 3600) relTime = Math.floor(diff/60) + 'm ago';
                    else if(diff < 86400) relTime = Math.floor(diff/3600) + 'h ago';
                    else relTime = Math.floor(diff/86400) + 'd ago';
                }

                const item = `
                <div class="lc-sub-item">
                    <a href="https://leetcode.com/problems/${slug}/" target="_blank" class="lc-sub-title">${escHtml(title)}</a>
                    <div class="lc-sub-meta">
                        <span class="lc-lang-tag">${escHtml(lang)}</span>
                        <span>${relTime}</span>
                    </div>
                </div>
                `;
                list.append(item);
            });
        },

        renderContests: function(ranking, history) {
            if (ranking && ranking.attendedContestsCount > 0 && ranking.rating) {
                $('#cr-rating').text(Math.round(ranking.rating));
                $('#cr-global').text(ranking.globalRanking ? (ranking.globalRanking + ' / ' + (ranking.totalParticipants || '')) : 'N/A');
                $('#cr-attended').text(ranking.attendedContestsCount);
            } else {
                $('#cr-rating').text('N/A');
                $('#cr-global').text('N/A');
                $('#cr-attended').text(ranking ? (ranking.attendedContestsCount || 0) : '0');
            }

            const attended = (history || []).filter(h => h && h.attended);
            if (attended.length === 0) {
                $('#contestChart').hide();
                $('#cr-empty-msg').removeClass('hidden').show();
                return;
            }

            $('#cr-empty-msg').addClass('hidden').hide();
            $('#contestChart').show();

            const labels = attended.map(h => (h.contest && h.contest.title ? h.contest.title.replace('Weekly Contest ', 'W').replace('Biweekly Contest ', 'B') : 'Contest'));
            const dataPts = attended.map(h => h.rating || 0);

            if (this.chartInstance) {
                this.chartInstance.destroy();
            }

            const ctx = document.getElementById('contestChart').getContext('2d');
            
            let gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(255, 161, 22, 0.5)');
            gradient.addColorStop(1, 'rgba(255, 161, 22, 0)');

            this.chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Rating',
                        data: dataPts,
                        borderColor: '#ffa116',
                        backgroundColor: gradient,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#64748b' } },
                        y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#64748b' } }
                    }
                }
            });
        },

        renderLanguages: function(langs) {
            if (!langs) langs = [];
            const list = $('#lang-list');
            list.empty();
            
            if (langs.length === 0) {
                list.html(`
                <div class="lc-empty-state">
                    <div class="lc-empty-icon"><i class="fas fa-code"></i></div>
                    <div class="lc-empty-title">No Language Data</div>
                    <div class="lc-empty-desc">Solve problems on LeetCode to see your programming language breakdown here.</div>
                </div>
                `);
                return;
            }

            langs.sort((a,b) => (b.problemsSolved || 0) - (a.problemsSolved || 0));
            
            const max = langs[0].problemsSolved || 1;
            const colors = ['#2563eb', '#00b8a3', '#ffc01e', '#ef4743', '#8b5cf6', '#ec4899'];

            langs.slice(0, 6).forEach((l, i) => {
                const p = ((l.problemsSolved || 0) / max) * 100;
                const c = colors[i % colors.length];
                const html = `
                <div class="lc-lang-row">
                    <div class="lc-lang-info">
                        <span>${escHtml(l.languageName || 'Language')}</span>
                        <span>${l.problemsSolved || 0}</span>
                    </div>
                    <div class="lc-lang-bar-bg">
                        <div class="lc-lang-bar-fill" style="width:0%; background:${c}" data-width="${p}%"></div>
                    </div>
                </div>
                `;
                list.append(html);
            });

            setTimeout(() => {
                $('.lc-lang-bar-fill').each(function() {
                    $(this).css('width', $(this).attr('data-width'));
                });
            }, 100);
        },

        renderBadges: function(badgeData) {
            const list = $('#badge-list');
            list.empty();
            $('#content-badges .lc-badge-summary').remove();
            const badges = (badgeData && badgeData.badges) ? badgeData.badges : [];
            
            if(badges.length === 0) {
                list.html(`
                <div class="lc-empty-state" style="grid-column:1/-1">
                    <div class="lc-empty-icon"><i class="fas fa-award"></i></div>
                    <div class="lc-empty-title">No Badges Yet</div>
                    <div class="lc-empty-desc">Participate in monthly coding challenges and contests on LeetCode to earn badges!</div>
                </div>
                `);
                return;
            }

            badges.forEach(b => {
                let imgUrl = b.icon || '';
                if(imgUrl && !imgUrl.startsWith('http')) imgUrl = 'https://leetcode.com' + imgUrl;
                const displayName = b.displayName || b.name || 'Badge';
                const html = `
                <div class="lc-badge-item" title="${escHtml(displayName)}">
                    <div class="lc-badge-img"><img src="${imgUrl}" alt="${escHtml(displayName)}" onerror="this.src='https://assets.leetcode.com/users/avatars/avatar_1701233055.png'"></div>
                    <div class="lc-badge-name">${escHtml(displayName)}</div>
                </div>
                `;
                list.append(html);
            });

            $('#content-badges').append(`
                <div class="lc-badge-summary"><i class="fas fa-medal" style="color:var(--lc-brand-orange)"></i> Unlocked <strong>${badges.length}</strong> Badges on LeetCode</div>
            `);
        },

        renderSkills: function(skillData) {
            const tags = skillData || {};
            
            const adv = $('#skills-advanced').empty();
            const inter = $('#skills-intermediate').empty();
            const fund = $('#skills-fundamental').empty();

            if (tags.advanced && tags.advanced.length > 0) {
                tags.advanced.forEach(t => {
                    adv.append(`<div class="lc-skill-tag advanced">${escHtml(t.tagName)} <span class="lc-skill-count">${t.problemsSolved}</span></div>`);
                });
            } else {
                adv.append(`<span class="text-muted small">None recorded</span>`);
            }

            if (tags.intermediate && tags.intermediate.length > 0) {
                tags.intermediate.forEach(t => {
                    inter.append(`<div class="lc-skill-tag intermediate">${escHtml(t.tagName)} <span class="lc-skill-count">${t.problemsSolved}</span></div>`);
                });
            } else {
                inter.append(`<span class="text-muted small">None recorded</span>`);
            }

            if (tags.fundamental && tags.fundamental.length > 0) {
                tags.fundamental.forEach(t => {
                    fund.append(`<div class="lc-skill-tag fundamental">${escHtml(t.tagName)} <span class="lc-skill-count">${t.problemsSolved}</span></div>`);
                });
            } else {
                fund.append(`<span class="text-muted small">None recorded</span>`);
            }
        }
    };

    if (isConnected) {
        LeetCodeDashboard.init();
    }
});
</script>
