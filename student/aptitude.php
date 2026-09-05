<?php
// MAMCET Placement & Learning Portal - Daily Aptitude Challenge & Streak System

$pageTitle = 'Daily Aptitude Challenge';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login. Contact Placement Officer.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

require_once(__DIR__ . '/../services/AptitudeService.php');

$studentId = (int)$student['student_id'];
$todayDate = date('Y-m-d');
$formattedToday = date('l, F j, Y');

// Fetch today's questions
$dailyQuestions = AptitudeService::getDailyChallenge($db, $todayDate, false);

// Fetch today's progress (if completed)
$todayProgress = AptitudeService::getStudentDailyProgress($db, $studentId, $todayDate);
$isCompletedToday = $todayProgress['is_completed'];

// Fetch streak stats, weekly tracker, category breakdown, leaderboard
$streakStats = AptitudeService::getStudentStreakStats($db, $studentId);
$weeklyTracker = AptitudeService::getWeeklyTracker($db, $studentId);
$categoryBreakdown = AptitudeService::getCategoryBreakdown($db, $studentId);
$leaderboard = AptitudeService::getLeaderboard($db, 10);
$monthlyMap = AptitudeService::getMonthlyActivityMap($db, $studentId);
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>
    
    <div class="page-container aptitude-portal-container">
        
        <!-- Aptitude-specific UI. Keep this scoped to prevent page-wide side effects. -->
        <style>
            .aptitude-portal-container {
                --apt-primary: #2563eb;
                --apt-primary-light: #eff6ff;
                --apt-ink: #0f172a;
                --apt-muted: #64748b;
                --apt-line: #e2e8f0;
                --apt-blue: #2563eb;
                padding: 1.75rem clamp(0.85rem, 2.5vw, 2.25rem) calc(3rem + var(--mobile-nav-height, 0px) + env(safe-area-inset-bottom)) !important;
                max-width: 1340px;
                margin: 0 auto;
            }

            /* ──────── STREAK HERO CARD ──────── */
            .streak-hero-card {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                margin-bottom: 1.75rem;
                padding: clamp(1.25rem, 3vw, 2rem);
                color: #fff;
                border: 1px solid rgba(255, 255, 255, 0.14);
                border-radius: 24px;
                background: radial-gradient(130% 120% at 90% 10%, rgba(99, 102, 241, 0.25) 0%, transparent 50%),
                            radial-gradient(100% 100% at 10% 90%, rgba(249, 115, 22, 0.22) 0%, transparent 50%),
                            linear-gradient(140deg, #0b1120 0%, #111a38 52%, #1e1b4b 100%);
                box-shadow: 0 20px 45px -20px rgba(15, 23, 42, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.15);
            }

            .streak-hero-card::before,
            .streak-hero-card::after {
                content: '';
                position: absolute;
                z-index: -1;
                pointer-events: none;
                border-radius: 50%;
                filter: blur(40px);
                opacity: 0.6;
            }

            .streak-hero-card::before {
                top: -5rem;
                right: -2rem;
                width: 18rem;
                height: 18rem;
                background: radial-gradient(circle, rgba(251, 146, 60, 0.35), transparent 70%);
            }

            .streak-hero-card::after {
                bottom: -6rem;
                left: 10%;
                width: 20rem;
                height: 20rem;
                background: radial-gradient(circle, rgba(99, 102, 241, 0.35), transparent 70%);
            }

            /* Topline Bar */
            .streak-hero-topline {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                margin-bottom: 1.25rem;
                flex-wrap: wrap;
            }

            .streak-eyebrow-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.38rem 0.85rem;
                color: #fef08a;
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                border: 1px solid rgba(251, 191, 36, 0.35);
                border-radius: 999px;
                background: rgba(245, 158, 11, 0.16);
                backdrop-filter: blur(8px);
                box-shadow: 0 0 14px rgba(245, 158, 11, 0.15);
            }

            .streak-eyebrow-pill i {
                color: #fbbf24;
                font-size: 0.75rem;
            }

            .streak-date-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.35rem 0.8rem;
                color: rgba(255, 255, 255, 0.82);
                font-size: 0.75rem;
                font-weight: 600;
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.06);
                backdrop-filter: blur(8px);
            }

            /* Hero Content Grid (Left Intro + Right Stats) */
            .streak-hero-content {
                position: relative;
                z-index: 1;
                display: grid;
                grid-template-columns: minmax(0, 1.15fr) minmax(360px, 0.85fr);
                gap: clamp(1.25rem, 3vw, 2.25rem);
                align-items: center;
            }

            .streak-intro-block {
                display: flex;
                flex-direction: column;
                gap: 0.95rem;
                min-width: 0;
            }

            .streak-title-wrapper {
                display: flex;
                align-items: center;
                gap: 1.1rem;
            }

            .streak-flame-badge {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 62px;
                height: 62px;
                flex: 0 0 62px;
                color: #fff;
                font-size: 1.85rem;
                border: 1.5px solid rgba(255, 255, 255, 0.28);
                border-radius: 18px;
                background: linear-gradient(145deg, #fb923c 0%, #f97316 55%, #ea580c 100%);
                box-shadow: 0 0 24px rgba(249, 115, 22, 0.45), 0 8px 16px -4px rgba(234, 88, 12, 0.5);
                animation: flamePulse 3s ease-in-out infinite;
            }

            @keyframes flamePulse {
                0%, 100% { transform: translateY(0) scale(1); box-shadow: 0 0 24px rgba(249, 115, 22, 0.45); }
                50% { transform: translateY(-2px) scale(1.03); box-shadow: 0 0 30px rgba(249, 115, 22, 0.65); }
            }

            .streak-title-text {
                min-width: 0;
            }

            .streak-heading {
                margin: 0 0 0.25rem 0;
                color: #fff;
                font-family: var(--font-heading);
                font-size: clamp(1.6rem, 3.5vw, 2.25rem);
                font-weight: 800;
                line-height: 1.15;
                letter-spacing: -0.03em;
            }

            .streak-heading-highlight {
                background: linear-gradient(135deg, #fef08a 0%, #fb923c 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }

            .streak-meta-tag {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                color: rgba(255, 255, 255, 0.7);
                font-size: 0.76rem;
                font-weight: 600;
            }

            /* Status Banner */
            .streak-status-banner {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 0.75rem 1rem;
                border-radius: 14px;
                font-size: 0.82rem;
                line-height: 1.45;
                backdrop-filter: blur(10px);
            }

            .streak-status-banner.status-completed {
                background: rgba(16, 185, 129, 0.14);
                border: 1px solid rgba(52, 211, 153, 0.35);
                color: #ecfdf5;
            }
            .streak-status-banner.status-completed .status-icon {
                color: #34d399;
                font-size: 1.1rem;
                flex-shrink: 0;
            }

            .streak-status-banner.status-risk {
                background: rgba(245, 158, 11, 0.15);
                border: 1px solid rgba(251, 191, 36, 0.35);
                color: #fffbeb;
            }
            .streak-status-banner.status-risk .status-icon {
                color: #fbbf24;
                font-size: 1.1rem;
                flex-shrink: 0;
            }

            .streak-status-banner.status-pending {
                background: rgba(99, 102, 241, 0.15);
                border: 1px solid rgba(165, 180, 252, 0.3);
                color: #eef2ff;
            }
            .streak-status-banner.status-pending .status-icon {
                color: #818cf8;
                font-size: 1.1rem;
                flex-shrink: 0;
            }

            .streak-status-banner .status-content strong {
                display: block;
                font-weight: 700;
                font-size: 0.84rem;
                margin-bottom: 0.1rem;
            }
            .streak-status-banner .status-content span {
                opacity: 0.88;
                font-size: 0.76rem;
            }

            /* ──────── 4 STATS GRID ──────── */
            .streak-stats-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: clamp(0.6rem, 1.5vw, 0.85rem);
            }

            .streak-stat-pill {
                position: relative;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-height: 90px;
                padding: 0.9rem 0.95rem;
                border: 1px solid rgba(255, 255, 255, 0.11);
                border-radius: 16px;
                background: rgba(255, 255, 255, 0.05);
                backdrop-filter: blur(10px);
                transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), background 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
            }

            .streak-stat-pill:hover {
                transform: translateY(-2px);
                background: rgba(255, 255, 255, 0.09);
                border-color: rgba(255, 255, 255, 0.22);
                box-shadow: 0 8px 20px -8px rgba(0, 0, 0, 0.4);
            }

            .streak-stat-top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.5rem;
            }

            .stat-icon-wrap {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border-radius: 8px;
                font-size: 0.78rem;
            }

            .stat-icon-amber { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
            .stat-icon-cyan { background: rgba(6, 182, 212, 0.2); color: #38bdf8; }
            .stat-icon-emerald { background: rgba(16, 185, 129, 0.2); color: #34d399; }
            .stat-icon-purple { background: rgba(139, 92, 246, 0.2); color: #a78bfa; }

            .streak-stat-pill .stat-num {
                margin: 0.35rem 0 0.15rem;
                font-family: var(--font-heading);
                font-size: clamp(1.35rem, 2.6vw, 1.7rem);
                font-weight: 800;
                line-height: 1.1;
            }

            .text-stat-amber { color: #fbbf24; }
            .text-stat-cyan { color: #38bdf8; }
            .text-stat-emerald { color: #34d399; }
            .text-stat-purple { color: #c084fc; }

            .streak-stat-pill .stat-lbl {
                color: rgba(255, 255, 255, 0.72);
                font-size: 0.68rem;
                font-weight: 600;
                letter-spacing: 0.02em;
                line-height: 1.25;
            }

            /* ──────── WEEKLY 7-DAY TRACKER ──────── */
            .weekly-tracker {
                position: relative;
                z-index: 1;
                margin-top: 1.5rem;
                padding-top: 1.2rem;
                border-top: 1px solid rgba(255, 255, 255, 0.12);
            }

            .weekly-tracker-heading {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                margin-bottom: 0.85rem;
                color: rgba(255, 255, 255, 0.85);
                font-size: 0.76rem;
                font-weight: 700;
            }

            .weekly-heading-left {
                display: flex;
                align-items: center;
                gap: 0.45rem;
            }

            .weekly-heading-right {
                color: rgba(255, 255, 255, 0.5);
                font-size: 0.72rem;
                font-weight: 500;
            }

            .weekly-tracker-container {
                display: grid;
                grid-template-columns: repeat(7, minmax(0, 1fr));
                gap: clamp(0.35rem, 1.2vw, 0.75rem);
                padding: 0;
            }

            .weekly-day-chip {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-width: 0;
                padding: 0.65rem 0.35rem;
                color: rgba(255, 255, 255, 0.75);
                font-size: 0.72rem;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 14px;
                background: rgba(0, 0, 0, 0.22);
                backdrop-filter: blur(6px);
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .weekly-day-name {
                font-size: 0.68rem;
                font-weight: 600;
                opacity: 0.8;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }

            .weekly-day-number {
                margin: 0.18rem 0;
                color: #fff;
                font-size: 0.86rem;
                font-weight: 800;
                font-family: var(--font-heading);
            }

            .weekly-day-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                height: 1.1rem;
                margin-top: 0.15rem;
                font-size: 0.8rem;
                line-height: 1;
            }

            /* Day states */
            .weekly-day-chip.completed {
                color: #d1fae5;
                border-color: rgba(52, 211, 153, 0.4);
                background: rgba(16, 185, 129, 0.16);
            }

            .weekly-day-chip.completed .weekly-day-number {
                color: #6ee7b7;
            }

            .weekly-day-chip.today {
                color: #fff;
                border-color: rgba(251, 146, 60, 0.85);
                background: radial-gradient(circle at 50% 0%, rgba(249, 115, 22, 0.32), rgba(249, 115, 22, 0.14));
                box-shadow: 0 0 18px rgba(249, 115, 22, 0.28);
            }

            .weekly-day-chip.today.completed {
                border-color: rgba(251, 146, 60, 0.9);
                background: radial-gradient(circle at 50% 0%, rgba(249, 115, 22, 0.35), rgba(16, 185, 129, 0.18));
            }

            /* ──────── MAIN CONTENT GRID ──────── */
            .aptitude-content-grid {
                --bs-gutter-x: 1.35rem;
                --bs-gutter-y: 1.35rem;
                align-items: flex-start;
            }

            .aptitude-portal-container .daily-challenge-card,
            .aptitude-portal-container .aptitude-side-card {
                overflow: hidden;
                border: 1px solid var(--apt-line);
                border-radius: 20px;
                background: #fff;
                box-shadow: 0 12px 28px -22px rgba(15, 23, 42, 0.45);
            }

            .daily-challenge-card { margin-bottom: 1.35rem; }

            .daily-challenge-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding: 1.25rem 1.5rem;
                border-bottom: 1px solid #eef2f7;
            }

            .challenge-heading-row {
                display: flex;
                align-items: center;
                gap: 0.6rem;
                margin-bottom: 0.38rem;
            }

            .challenge-eyebrow {
                padding: 0;
                color: var(--apt-blue);
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                border: 0;
                background: transparent;
            }

            .challenge-count {
                color: #94a3b8;
                font-size: 0.74rem;
                font-weight: 600;
            }

            .daily-challenge-header h4 {
                margin: 0;
                color: #0f172a;
                font-family: var(--font-heading);
                font-size: clamp(1.15rem, 2.5vw, 1.4rem);
                font-weight: 700;
                letter-spacing: -0.025em;
            }

            .daily-challenge-header p {
                margin: 0.35rem 0 0;
                color: #64748b;
                font-size: 0.8rem;
                line-height: 1.45;
            }

            .quiz-timer-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.5rem 0.85rem;
                color: #9a3412;
                font-size: 0.82rem;
                font-weight: 800;
                font-family: monospace;
                border: 1px solid #fed7aa;
                border-radius: 999px;
                background: #fff7ed;
                box-shadow: 0 2px 6px rgba(234, 88, 12, 0.08);
            }

            .challenge-body { padding: 1.5rem !important; }

            .quiz-progress-label {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                margin-bottom: 0.7rem;
                color: #94a3b8;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .quiz-stepper { display: flex; gap: 0.6rem; margin-bottom: 1.5rem; }

            .step-pill {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                flex: 1;
                height: 42px;
                color: #64748b;
                font-size: 0.86rem;
                font-weight: 800;
                border: 1.5px solid #e2e8f0;
                border-radius: 12px;
                background: #f8fafc;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .step-pill:hover {
                border-color: #cbd5e1;
                background: #f1f5f9;
            }

            .step-pill.active {
                color: #1d4ed8;
                border-color: #3b82f6;
                background: #eff6ff;
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
            }

            .step-pill.answered {
                color: #047857;
                border-color: #34d399;
                background: #ecfdf5;
            }

            .step-pill .answered-icon {
                position: absolute;
                top: 0.24rem;
                right: 0.35rem;
                font-size: 0.58rem;
            }

            .question-meta { margin-bottom: 1.25rem !important; }
            .question-meta .badge {
                padding: 0.45rem 0.7rem !important;
                font-size: 0.72rem;
                font-weight: 700;
                border-radius: 999px;
            }

            .question-title {
                margin-bottom: 1.35rem;
                color: #0f172a;
                font-family: var(--font-heading);
                font-size: clamp(1.08rem, 2.3vw, 1.32rem) !important;
                line-height: 1.6 !important;
                letter-spacing: -0.015em;
            }

            .option-item {
                display: flex;
                align-items: center;
                min-height: 58px;
                margin-bottom: 0.75rem;
                padding: 0.85rem 1.1rem;
                border: 1.5px solid #e2e8f0;
                border-radius: 14px;
                background: #ffffff;
                cursor: pointer;
                user-select: none;
                transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
            }

            .option-item:hover {
                transform: translateY(-1px);
                border-color: #93c5fd;
                background: #f8fafc;
                box-shadow: 0 6px 16px rgba(37, 99, 235, 0.08);
            }

            .option-item.selected {
                border-color: #2563eb;
                background: #eff6ff;
                box-shadow: 0 6px 18px rgba(37, 99, 235, 0.12);
            }

            .option-letter-badge {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                flex: 0 0 34px;
                margin-right: 0.85rem;
                color: #475569;
                font-size: 0.82rem;
                font-weight: 800;
                border: 1px solid #dbe3ee;
                border-radius: 10px;
                background: #f1f5f9;
                transition: all 0.18s ease;
            }

            .option-item.selected .option-letter-badge {
                color: #fff;
                border-color: #2563eb;
                background: #2563eb;
            }

            .option-text {
                color: #1e293b;
                font-size: 0.92rem;
                font-weight: 600;
                line-height: 1.45;
            }

            .quiz-footer {
                display: grid !important;
                grid-template-columns: auto 1fr auto;
                gap: 0.85rem;
                align-items: center;
                margin-top: 1.5rem;
                padding-top: 1.15rem !important;
                border-top: 1px solid #eef2f7 !important;
            }

            .answered-meter {
                color: #94a3b8;
                font-size: 0.76rem;
                font-weight: 700;
                text-align: center;
            }
            .answered-meter strong { color: var(--apt-blue); }
            .quiz-footer .btn {
                min-height: 44px;
                padding: 0.6rem 1.25rem;
                border-radius: 12px;
                font-weight: 700;
            }

            /* Side Cards */
            .aptitude-side-card { margin-bottom: 1.35rem !important; }
            .aptitude-side-card .card-header { padding: 1.2rem 1.25rem 0.25rem; }
            .aptitude-side-card .card-body { padding: 0.95rem 1.25rem 1.25rem; }
            .aptitude-tip-card { padding: 1.35rem !important; }
            .aptitude-side-card h5 { color: #0f172a; font-size: 1.08rem; letter-spacing: -0.02em; }
            .aptitude-side-card .progress { height: 8px !important; background: #eef2ff; border-radius: 999px; }
            .aptitude-side-card .progress-bar { border-radius: 999px; }
            .aptitude-side-card .table-responsive { border: 0; border-radius: 0; }
            .student-portal .aptitude-portal-container .aptitude-side-card .table { min-width: 0 !important; margin: 0; }
            .aptitude-side-card .table th { color: #94a3b8; font-size: 0.65rem; letter-spacing: 0.04em; text-transform: uppercase; }
            .aptitude-side-card .table td, .aptitude-side-card .table th { padding: 0.75rem 0.55rem; white-space: nowrap; }

            /* Solution Cards */
            .solution-card {
                margin-bottom: 1rem;
                overflow: hidden;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #fff;
            }
            .solution-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.95rem 1.15rem;
            }
            .solution-card.correct .solution-card-header { border-left: 4px solid #10b981; background: #f0fdf9; }
            .solution-card.incorrect .solution-card-header { border-left: 4px solid #ef4444; background: #fff5f5; }
            .explanation-box {
                margin-top: 0.85rem;
                padding: 0.9rem 1rem;
                font-size: 0.85rem;
                border-left: 4px solid #3b82f6;
                border-radius: 12px;
                background: #f8fafc;
            }
            .leaderboard-rank-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                font-size: 0.76rem;
                font-weight: 800;
            }
            .rank-1 { color: #854d0e; border: 1px solid #facc15; background: #fef08a; }
            .rank-2 { color: #475569; border: 1px solid #cbd5e1; background: #e2e8f0; }
            .rank-3 { color: #9a3412; border: 1px solid #fdba74; background: #fed7aa; }

            /* ──────── RESPONSIVE BREAKPOINTS ──────── */
            @media (max-width: 991.98px) {
                .aptitude-portal-container { padding-top: 1.25rem !important; }
                .streak-hero-content { grid-template-columns: 1fr; gap: 1.35rem; }
                .streak-stats-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            }

            @media (max-width: 767.98px) {
                .streak-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.65rem; }
            }

            @media (max-width: 575.98px) {
                .aptitude-portal-container {
                    padding: 0.85rem var(--mobile-gutter, 12px) calc(2.5rem + var(--mobile-nav-height, 60px) + env(safe-area-inset-bottom)) !important;
                }
                .streak-hero-card {
                    padding: 1.15rem 1rem !important;
                    border-radius: 20px !important;
                    margin-bottom: 1.2rem;
                }
                .streak-hero-topline {
                    margin-bottom: 0.85rem;
                    gap: 0.45rem;
                }
                .streak-eyebrow-pill {
                    padding: 0.32rem 0.65rem;
                    font-size: 0.65rem;
                }
                .streak-date-pill {
                    padding: 0.3rem 0.65rem;
                    font-size: 0.68rem;
                }
                .streak-title-wrapper {
                    gap: 0.8rem;
                }
                .streak-flame-badge {
                    width: 50px !important;
                    height: 50px !important;
                    flex-basis: 50px !important;
                    border-radius: 14px !important;
                    font-size: 1.45rem !important;
                }
                .streak-heading {
                    font-size: 1.45rem !important;
                }
                .streak-meta-tag {
                    font-size: 0.7rem;
                }
                .streak-status-banner {
                    padding: 0.65rem 0.85rem;
                    font-size: 0.76rem;
                    gap: 0.6rem;
                }
                .streak-status-banner .status-icon {
                    font-size: 0.95rem;
                }
                .streak-status-banner .status-content strong {
                    font-size: 0.8rem;
                }
                .streak-status-banner .status-content span {
                    font-size: 0.72rem;
                }

                .streak-stats-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 0.5rem;
                }
                .streak-stat-pill {
                    min-height: 76px;
                    padding: 0.75rem 0.75rem;
                    border-radius: 14px;
                }
                .stat-icon-wrap {
                    width: 24px;
                    height: 24px;
                    font-size: 0.7rem;
                    border-radius: 7px;
                }
                .streak-stat-pill .stat-num {
                    font-size: 1.3rem;
                    margin: 0.25rem 0 0.1rem;
                }
                .streak-stat-pill .stat-lbl {
                    font-size: 0.64rem;
                }

                .weekly-tracker {
                    margin-top: 1.15rem;
                    padding-top: 0.95rem;
                }
                .weekly-tracker-heading {
                    font-size: 0.68rem;
                    margin-bottom: 0.65rem;
                }
                .weekly-tracker-container {
                    gap: 0.25rem;
                }
                .weekly-day-chip {
                    padding: 0.5rem 0.15rem;
                    border-radius: 10px;
                }
                .weekly-day-name {
                    font-size: 0.58rem;
                }
                .weekly-day-number {
                    font-size: 0.74rem;
                    margin: 0.1rem 0;
                }
                .weekly-day-icon {
                    height: 0.9rem;
                    margin-top: 0.1rem;
                    font-size: 0.68rem;
                }

                .daily-challenge-card, .aptitude-side-card { border-radius: 17px; }
                .daily-challenge-header {
                    align-items: flex-start;
                    flex-direction: column;
                    gap: 0.75rem;
                    padding: 1rem;
                }
                .challenge-heading-row { margin-bottom: 0.25rem; }
                .daily-challenge-header p { font-size: 0.75rem; }
                .quiz-timer-badge { align-self: flex-start; padding: 0.4rem 0.7rem; font-size: 0.75rem; }
                .aptitude-portal-container .challenge-body { padding: 1rem !important; }
                .quiz-progress-label { font-size: 0.66rem; }
                .quiz-stepper { gap: 0.4rem; margin-bottom: 1.15rem; }
                .step-pill { height: 38px; border-radius: 10px; font-size: 0.78rem; }
                .step-pill .answered-icon { top: 0.2rem; right: 0.25rem; }
                .question-meta { gap: 0.35rem !important; }
                .question-meta .badge { font-size: 0.64rem; padding: 0.35rem 0.55rem !important; }
                .question-title { margin-bottom: 1rem; font-size: 1.02rem !important; }
                .option-item { min-height: 54px; padding: 0.72rem 0.8rem; border-radius: 13px; margin-bottom: 0.6rem; }
                .option-letter-badge { width: 30px; height: 30px; flex-basis: 30px; margin-right: 0.65rem; border-radius: 9px; font-size: 0.75rem; }
                .option-text { font-size: 0.85rem; }

                .quiz-footer { grid-template-columns: 1fr 1fr; gap: 0.55rem; margin-top: 1.1rem; }
                .answered-meter { grid-column: 1 / -1; grid-row: 1; order: -1; }
                .quiz-footer #btn-prev-q, .quiz-footer > div:last-child { width: 100%; }
                .quiz-footer > div:last-child { display: flex; }
                .quiz-footer > div:last-child .btn { width: 100%; min-height: 40px; font-size: 0.85rem; }

                .aptitude-side-card .card-header { padding: 1rem 1rem 0.25rem; }
                .aptitude-side-card .card-body { padding: 0.8rem 1rem 1rem; }
                .aptitude-tip-card { padding: 1rem !important; }
                .aptitude-side-card .table td, .aptitude-side-card .table th { padding: 0.62rem 0.35rem; font-size: 0.68rem; }
                .solution-card-header { align-items: flex-start; flex-direction: column; padding: 0.75rem; gap: 0.5rem; }
            }
        </style>

        <!-- 1. Streak Hero Header -->
        <section class="streak-hero-card" aria-labelledby="aptitude-hero-title">
            
            <!-- Topline Badges -->
            <div class="streak-hero-topline">
                <div class="streak-eyebrow-pill">
                    <i class="fa-solid fa-bolt-lightning"></i>
                    <span>Daily Aptitude</span>
                </div>
                <div class="streak-date-pill">
                    <i class="fa-regular fa-calendar-days"></i>
                    <span><?php echo esc($formattedToday); ?></span>
                </div>
            </div>

            <!-- Hero Body (Intro on Left, Stats on Right) -->
            <div class="streak-hero-content">
                <div class="streak-intro-block">
                    <div class="streak-title-wrapper">
                        <div class="streak-flame-badge" aria-hidden="true">
                            <i class="fa-solid fa-fire"></i>
                        </div>
                        <div class="streak-title-text">
                            <h2 id="aptitude-hero-title" class="streak-heading">
                                <?php echo $streakStats['current_streak']; ?> Day<?php echo $streakStats['current_streak'] == 1 ? '' : 's'; ?> <span class="streak-heading-highlight">Streak!</span>
                            </h2>
                            <div class="streak-meta-tag">
                                <i class="fa-solid fa-layer-group"></i>
                                <span><?php echo count($dailyQuestions); ?> Questions · Daily Placement Prep</span>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic Status Banner -->
                    <?php if ($isCompletedToday): ?>
                        <div class="streak-status-banner status-completed">
                            <div class="status-icon"><i class="fa-solid fa-circle-check"></i></div>
                            <div class="status-content">
                                <strong>Today's Challenge Completed!</strong>
                                <span>Great consistency. Your practice for today is logged.</span>
                            </div>
                        </div>
                    <?php elseif ($streakStats['streak_status'] === 'at_risk'): ?>
                        <div class="streak-status-banner status-risk">
                            <div class="status-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <div class="status-content">
                                <strong>Streak At Risk!</strong>
                                <span>Complete today's 5 questions before midnight to keep your streak.</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="streak-status-banner status-pending">
                            <div class="status-icon"><i class="fa-solid fa-bolt"></i></div>
                            <div class="status-content">
                                <strong>5 Questions Ready</strong>
                                <span>Complete the challenge to boost placement readiness and streak score.</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 4 Frosted Glass Stat Cards -->
                <div class="streak-stats-grid" aria-label="Aptitude streak statistics">
                    <div class="streak-stat-pill">
                        <div class="streak-stat-top">
                            <span class="stat-lbl">Current Streak</span>
                            <div class="stat-icon-wrap stat-icon-amber"><i class="fa-solid fa-fire"></i></div>
                        </div>
                        <div class="stat-num text-stat-amber"><?php echo $streakStats['current_streak']; ?><small style="font-size: 0.8rem; font-weight: 600; opacity: 0.7; margin-left: 2px;">d</small></div>
                    </div>

                    <div class="streak-stat-pill">
                        <div class="streak-stat-top">
                            <span class="stat-lbl">Longest Streak</span>
                            <div class="stat-icon-wrap stat-icon-cyan"><i class="fa-solid fa-trophy"></i></div>
                        </div>
                        <div class="stat-num text-stat-cyan"><?php echo $streakStats['longest_streak']; ?><small style="font-size: 0.8rem; font-weight: 600; opacity: 0.7; margin-left: 2px;">d</small></div>
                    </div>

                    <div class="streak-stat-pill">
                        <div class="streak-stat-top">
                            <span class="stat-lbl">Accuracy</span>
                            <div class="stat-icon-wrap stat-icon-emerald"><i class="fa-solid fa-bullseye"></i></div>
                        </div>
                        <div class="stat-num text-stat-emerald"><?php echo number_format($streakStats['accuracy_percentage'], 0); ?>%</div>
                    </div>

                    <div class="streak-stat-pill">
                        <div class="streak-stat-top">
                            <span class="stat-lbl">Days Completed</span>
                            <div class="stat-icon-wrap stat-icon-purple"><i class="fa-solid fa-calendar-check"></i></div>
                        </div>
                        <div class="stat-num text-stat-purple"><?php echo $streakStats['total_days_completed']; ?></div>
                    </div>
                </div>
            </div>

            <!-- Weekly 7-Day Tracker -->
            <div class="weekly-tracker">
                <div class="weekly-tracker-heading">
                    <div class="weekly-heading-left">
                        <i class="fa-solid fa-chart-line text-warning"></i>
                        <span>Weekly Momentum</span>
                    </div>
                    <div class="weekly-heading-right">
                        <span>7-Day Activity</span>
                    </div>
                </div>
                <div class="weekly-tracker-container">
                    <?php foreach ($weeklyTracker as $day): ?>
                        <div class="weekly-day-chip <?php echo $day['is_today'] ? 'today' : ''; ?> <?php echo $day['is_completed'] ? 'completed' : ''; ?>">
                            <span class="weekly-day-name"><?php echo esc($day['day_name']); ?></span>
                            <span class="weekly-day-number"><?php echo esc($day['day_number']); ?></span>
                            <div class="weekly-day-icon">
                                <?php if ($day['is_completed']): ?>
                                    <i class="fa-solid fa-fire text-warning"></i>
                                <?php elseif ($day['is_today']): ?>
                                    <i class="fa-solid fa-bolt text-warning"></i>
                                <?php else: ?>
                                    <i class="fa-regular fa-circle" style="color: rgba(255,255,255,0.22); font-size: 0.65rem;"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 2. Main Daily Challenge Container -->
        <div class="row aptitude-content-grid">
            
            <!-- Left Column: Quiz or Results (8 Cols) -->
            <div class="col-lg-8 col-md-12">
                
                <?php if (!$isCompletedToday): ?>
                    <!-- STATE A: Quiz Not Taken Yet -->
                    <div class="daily-challenge-card" id="quiz-container">
                        
                        <!-- Header with Timer and Info -->
                        <div class="daily-challenge-header">
                            <div class="challenge-title-wrap">
                                <div class="challenge-heading-row">
                                    <span class="challenge-eyebrow"><i class="fa-solid fa-sparkles"></i> Focus session</span>
                                    <span class="challenge-count"><?php echo count($dailyQuestions); ?> questions</span>
                                </div>
                                <h4>Today's challenge</h4>
                                <p>Complete the set to log your practice and update your streak.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="quiz-timer-badge">
                                    <i class="fa-regular fa-clock"></i>
                                    <span id="quiz-timer-display">00:00</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 challenge-body">
                            <!-- Question Stepper Tabs -->
                            <div class="quiz-progress-label"><span>Your progress</span><span>One question at a time</span></div>
                            <div class="quiz-stepper">
                                <?php for ($i = 0; $i < count($dailyQuestions); $i++): ?>
                                    <div class="step-pill <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>" id="step-pill-<?php echo $i; ?>" aria-label="Question <?php echo $i + 1; ?>">
                                        <span class="step-num"><?php echo $i + 1; ?></span>
                                        <i class="fa-solid fa-check d-none answered-icon"></i>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Question Containers -->
                            <form id="daily-quiz-form">
                                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                <input type="hidden" name="date" value="<?php echo esc($todayDate); ?>">
                                
                                <?php foreach ($dailyQuestions as $idx => $q): ?>
                                    <div class="question-view-wrapper" id="q-wrapper-<?php echo $idx; ?>" style="<?php echo $idx === 0 ? '' : 'display: none;'; ?>">
                                        
                                        <!-- Category & Difficulty Tags -->
                                        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap question-meta">
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                                <i class="fa-solid <?php echo esc($q['category_icon'] ?: 'fa-brain'); ?> me-1"></i>
                                                <?php echo esc($q['category_name']); ?>
                                            </span>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                                                <?php echo esc($q['topic']); ?>
                                            </span>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <i class="fa-solid fa-building me-1 text-muted"></i><?php echo esc($q['company_tag']); ?>
                                            </span>
                                            <span class="badge bg-info-subtle text-info border px-2 py-1 ms-auto">
                                                <?php echo esc($q['difficulty']); ?>
                                            </span>
                                        </div>

                                        <!-- Question Statement -->
                                        <div class="mb-4">
                                            <h5 class="fw-bold text-dark lh-base question-title">
                                                <span class="text-primary me-2">Q<?php echo $idx + 1; ?>.</span>
                                                <?php echo nl2br(esc($q['question_text'])); ?>
                                            </h5>
                                        </div>

                                        <!-- 4 Options -->
                                        <div class="options-group mb-4" data-qid="<?php echo (int)$q['question_id']; ?>" data-index="<?php echo $idx; ?>">
                                            <div class="option-item" data-opt="A" onclick="handleOptionSelect(this, 'A', <?php echo (int)$q['question_id']; ?>, <?php echo $idx; ?>)">
                                                <div class="option-letter-badge">A</div>
                                                <div class="option-text flex-grow-1"><?php echo esc($q['option_a']); ?></div>
                                            </div>
                                            <div class="option-item" data-opt="B" onclick="handleOptionSelect(this, 'B', <?php echo (int)$q['question_id']; ?>, <?php echo $idx; ?>)">
                                                <div class="option-letter-badge">B</div>
                                                <div class="option-text flex-grow-1"><?php echo esc($q['option_b']); ?></div>
                                            </div>
                                            <div class="option-item" data-opt="C" onclick="handleOptionSelect(this, 'C', <?php echo (int)$q['question_id']; ?>, <?php echo $idx; ?>)">
                                                <div class="option-letter-badge">C</div>
                                                <div class="option-text flex-grow-1"><?php echo esc($q['option_c']); ?></div>
                                            </div>
                                            <div class="option-item" data-opt="D" onclick="handleOptionSelect(this, 'D', <?php echo (int)$q['question_id']; ?>, <?php echo $idx; ?>)">
                                                <div class="option-letter-badge">D</div>
                                                <div class="option-text flex-grow-1"><?php echo esc($q['option_d']); ?></div>
                                            </div>
                                            <input type="hidden" name="answers[<?php echo (int)$q['question_id']; ?>]" id="ans-input-<?php echo (int)$q['question_id']; ?>" value="">
                                        </div>

                                    </div>
                                <?php endforeach; ?>

                                <!-- Bottom Navigation Bar -->
                                <div class="d-flex justify-content-between align-items-center pt-3 border-top quiz-footer">
                                    <button type="button" class="btn btn-outline-secondary px-3" id="btn-prev-q" onclick="prevQuestion()" style="visibility: hidden;">
                                        <i class="fa-solid fa-arrow-left me-1"></i> Previous
                                    </button>

                                    <div class="text-muted small answered-meter">
                                        Answered: <strong id="answered-count-badge" class="text-primary">0</strong> / <?php echo count($dailyQuestions); ?>
                                    </div>

                                    <div>
                                        <button type="button" class="btn btn-primary px-4" id="btn-next-q" onclick="nextQuestion()">
                                            Next <i class="fa-solid fa-arrow-right ms-1"></i>
                                        </button>
                                        <button type="button" class="btn btn-success px-4" id="btn-submit-quiz" onclick="confirmSubmitQuiz()" style="display: none;">
                                            <i class="fa-solid fa-check-circle me-1"></i> Submit Challenge
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- STATE B: Already Completed for Today (Solutions & Score View) -->
                    <div class="daily-challenge-card">
                        <div class="daily-challenge-header bg-success-subtle border-success-subtle">
                            <div class="challenge-title-wrap">
                                <div class="challenge-heading-row">
                                    <span class="challenge-eyebrow text-success"><i class="fa-solid fa-circle-check"></i> Focus session</span>
                                </div>
                                <h4 class="fw-bold text-success">
                                    <i class="fa-solid fa-circle-check me-2"></i>Today's Challenge Completed!
                                </h4>
                                <p class="text-muted small mb-0">
                                    Submitted at <?php echo date('h:i A', strtotime($todayProgress['completed_at'])); ?> • Time spent: <?php echo gmdate('i\m s\s', $todayProgress['time_taken_seconds']); ?>
                                </p>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="text-end">
                                    <div class="h3 fw-bold text-success mb-0"><?php echo $todayProgress['score']; ?> / 5</div>
                                    <small class="text-muted fw-bold">SCORE</small>
                                </div>
                            </div>
                        </div>

                        <div class="p-4">
                            <h5 class="fw-bold mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Detailed Solutions & Explanations</h5>
                            
                            <?php foreach ($todayProgress['submissions'] as $subIdx => $sub): ?>
                                <div class="solution-card <?php echo $sub['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                    <div class="solution-card-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge <?php echo $sub['is_correct'] ? 'bg-success' : 'bg-danger'; ?> rounded-pill px-2">
                                                <?php if ($sub['is_correct']): ?>
                                                    <i class="fa-solid fa-check me-1"></i> Correct
                                                <?php else: ?>
                                                    <i class="fa-solid fa-xmark me-1"></i> Incorrect
                                                <?php endif; ?>
                                            </span>
                                            <span class="fw-semibold text-dark">Q<?php echo $subIdx + 1; ?>: <?php echo esc($sub['category_name']); ?></span>
                                            <span class="badge bg-light text-muted border"><?php echo esc($sub['topic']); ?></span>
                                        </div>
                                        <div>
                                            <span class="small text-muted">Your answer: <strong>Option <?php echo esc($sub['selected_option'] ?: 'Skipped'); ?></strong></span>
                                        </div>
                                    </div>
                                    
                                    <div class="p-3 border-top">
                                        <p class="fw-medium text-dark mb-3"><?php echo nl2br(esc($sub['question_text'])); ?></p>
                                        
                                        <div class="row g-2 mb-3">
                                            <div class="col-md-6">
                                                <div class="p-2 rounded border small <?php echo $sub['correct_option'] === 'A' ? 'bg-success-subtle border-success fw-bold' : ($sub['selected_option'] === 'A' ? 'bg-danger-subtle border-danger' : 'bg-light'); ?>">
                                                    <strong>A:</strong> <?php echo esc($sub['option_a']); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="p-2 rounded border small <?php echo $sub['correct_option'] === 'B' ? 'bg-success-subtle border-success fw-bold' : ($sub['selected_option'] === 'B' ? 'bg-danger-subtle border-danger' : 'bg-light'); ?>">
                                                    <strong>B:</strong> <?php echo esc($sub['option_b']); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="p-2 rounded border small <?php echo $sub['correct_option'] === 'C' ? 'bg-success-subtle border-success fw-bold' : ($sub['selected_option'] === 'C' ? 'bg-danger-subtle border-danger' : 'bg-light'); ?>">
                                                    <strong>C:</strong> <?php echo esc($sub['option_c']); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="p-2 rounded border small <?php echo $sub['correct_option'] === 'D' ? 'bg-success-subtle border-success fw-bold' : ($sub['selected_option'] === 'D' ? 'bg-danger-subtle border-danger' : 'bg-light'); ?>">
                                                    <strong>D:</strong> <?php echo esc($sub['option_d']); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Explanation -->
                                        <div class="explanation-box">
                                            <div class="fw-bold text-primary mb-1">
                                                <i class="fa-solid fa-lightbulb me-1"></i> Correct Answer: Option <?php echo esc($sub['correct_option']); ?>
                                            </div>
                                            <p class="text-muted mb-0"><?php echo nl2br(esc($sub['explanation'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="text-center p-3 bg-light rounded-3 mt-3 border">
                                <span class="text-muted small">
                                    <i class="fa-solid fa-hourglass-half text-warning me-1"></i> Next Daily 5 Questions unlock tomorrow at <strong>12:00 AM</strong>.
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Post-Submission Container (Dynamically injected via AJAX if taken in same session) -->
                <div id="dynamic-result-container" style="display: none;"></div>

            </div>

            <!-- Right Column: Category Stats & Leaderboard (4 Cols) -->
            <div class="col-lg-4 col-md-12">
                
                <!-- Category Mastery Card -->
                <div class="card aptitude-side-card aptitude-category-card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="fw-bold mb-0 text-dark" style="font-family: var(--font-heading);">Category Accuracy</h5>
                        <small class="text-muted">Performance breakdown across aptitude modules</small>
                    </div>
                    <div class="card-body">
                        <?php foreach ($categoryBreakdown as $cat): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-semibold text-dark">
                                        <i class="fa-solid <?php echo esc($cat['icon']); ?> text-primary me-1" style="width: 16px;"></i>
                                        <?php echo esc($cat['category_name']); ?>
                                    </span>
                                    <span class="small fw-bold text-dark"><?php echo $cat['accuracy']; ?>%</span>
                                </div>
                                <div class="progress" style="height: 6px; border-radius: 4px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $cat['accuracy']; ?>%;" aria-valuenow="<?php echo $cat['accuracy']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="d-flex justify-content-between text-muted" style="font-size: 0.7rem; margin-top: 2px;">
                                    <span><?php echo $cat['correct']; ?> / <?php echo $cat['attempted']; ?> correct</span>
                                    <span><?php echo $cat['attempted']; ?> total</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Leaderboard Card -->
                <div class="card aptitude-side-card aptitude-leaderboard-card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-0 text-dark" style="font-family: var(--font-heading);">Streak Leaderboard</h5>
                            <small class="text-muted">Top placement daily streaks</small>
                        </div>
                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-trophy me-1"></i>Top Streaks</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Rank</th>
                                        <th>Student</th>
                                        <th>Dept</th>
                                        <th class="text-end pe-4">Streak</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($leaderboard)): ?>
                                        <?php foreach ($leaderboard as $rIdx => $lb): ?>
                                            <tr class="<?php echo $lb['student_id'] == $studentId ? 'table-primary fw-bold' : ''; ?>">
                                                <td class="ps-4">
                                                    <span class="leaderboard-rank-badge <?php echo $rIdx === 0 ? 'rank-1' : ($rIdx === 1 ? 'rank-2' : ($rIdx === 2 ? 'rank-3' : 'bg-light text-muted')); ?>">
                                                        <?php echo $rIdx + 1; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="text-dark fw-semibold text-truncate" style="max-width: 120px;">
                                                        <?php echo esc($lb['student_name']); ?>
                                                        <?php if ($lb['student_id'] == $studentId): ?>
                                                            <span class="badge bg-primary text-white" style="font-size:0.6rem;">You</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary-subtle text-secondary"><?php echo esc($lb['dept_code']); ?></span>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <span class="fw-bold text-warning">
                                                        <i class="fa-solid fa-fire me-1"></i><?php echo $lb['active_streak']; ?>d
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No streak records yet. Be the first to start today!</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Motivation & Placement Tips Box -->
                <div class="card aptitude-side-card aptitude-tip-card shadow-sm border-0 rounded-4 text-white p-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #4f46e5 100%);">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="fa-solid fa-award text-warning"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-white" style="font-family: var(--font-heading);">Why Daily Practice?</h6>
                            <small class="text-white-50">Campus Placement Advantage</small>
                        </div>
                    </div>
                    <p class="small text-white-50 mb-0" style="line-height: 1.55;">
                        Top recruiters like TCS, Infosys, Zoho, and Wipro test speed and accuracy in round 1. Practicing 5 questions daily keeps your problem-solving sharp!
                    </p>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- JavaScript Logic for Live Timer, Stepper, and AJAX Submissions -->
<script>
var currentQuestionIdx = 0;
var totalQuestions = <?php echo count($dailyQuestions); ?>;
var quizSeconds = 0;
var timerInterval = null;

// Global option click handler
function handleOptionSelect(el, optLetter, qid, qIdx) {
    var optionGroup = el.closest('.options-group');
    if (!optionGroup) return;

    // Remove selected class from sibling options
    var allOptions = optionGroup.querySelectorAll('.option-item');
    allOptions.forEach(function(opt) {
        opt.classList.remove('selected');
    });

    // Mark clicked option as selected
    el.classList.add('selected');

    // Update hidden input
    var hiddenInput = document.getElementById('ans-input-' + qid);
    if (hiddenInput) {
        hiddenInput.value = optLetter;
    }

    // Mark stepper pill as answered
    var pill = document.getElementById('step-pill-' + qIdx);
    if (pill) {
        pill.classList.add('answered');
        var icon = pill.querySelector('.answered-icon');
        if (icon) icon.classList.remove('d-none');
    }

    // Update answered count
    updateAnsweredCount();
}

function updateAnsweredCount() {
    var answered = 0;
    var inputs = document.querySelectorAll('input[name^="answers"]');
    inputs.forEach(function(inp) {
        if (inp.value) answered++;
    });
    var badge = document.getElementById('answered-count-badge');
    if (badge) badge.innerText = answered;
}

// Show specific question index
function showQuestion(index) {
    if (index < 0 || index >= totalQuestions) return;
    currentQuestionIdx = index;

    // Hide all question wrappers
    var wrappers = document.querySelectorAll('.question-view-wrapper');
    wrappers.forEach(function(w, i) {
        w.style.display = (i === index) ? 'block' : 'none';
    });

    // Update stepper pills
    var pills = document.querySelectorAll('.step-pill');
    pills.forEach(function(p, i) {
        if (i === index) {
            p.classList.add('active');
        } else {
            p.classList.remove('active');
        }
    });

    // Navigation buttons visibility
    var prevBtn = document.getElementById('btn-prev-q');
    var nextBtn = document.getElementById('btn-next-q');
    var submitBtn = document.getElementById('btn-submit-quiz');

    if (prevBtn) {
        prevBtn.style.visibility = (currentQuestionIdx === 0) ? 'hidden' : 'visible';
    }

    if (currentQuestionIdx === totalQuestions - 1) {
        if (nextBtn) nextBtn.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'inline-block';
    } else {
        if (nextBtn) nextBtn.style.display = 'inline-block';
        if (submitBtn) submitBtn.style.display = 'none';
    }
}

function nextQuestion() {
    if (currentQuestionIdx < totalQuestions - 1) {
        showQuestion(currentQuestionIdx + 1);
    }
}

function prevQuestion() {
    if (currentQuestionIdx > 0) {
        showQuestion(currentQuestionIdx - 1);
    }
}

function confirmSubmitQuiz() {
    var answered = 0;
    var answersObj = {};
    var inputs = document.querySelectorAll('input[name^="answers"]');

    inputs.forEach(function(inp) {
        var match = inp.name.match(/\[(\d+)\]/);
        if (match) {
            var qid = match[1];
            var val = inp.value;
            answersObj[qid] = val ? val : 'SKIP';
            if (val) answered++;
        }
    });

    var unAnswered = totalQuestions - answered;
    var confirmText = 'You have answered ' + answered + ' of ' + totalQuestions + ' questions.';
    if (unAnswered > 0) {
        confirmText += ' (' + unAnswered + ' skipped question' + (unAnswered > 1 ? 's' : '') + ')';
    }
    confirmText += ' Ready to submit?';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Submit Daily Challenge?',
            text: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Submit Answers'
        }).then(function(result) {
            if (result.isConfirmed) {
                executeQuizSubmission(answersObj);
            }
        });
    } else {
        if (confirm(confirmText)) {
            executeQuizSubmission(answersObj);
        }
    }
}

function executeQuizSubmission(answersObj) {
    if (timerInterval) clearInterval(timerInterval);
    var submitBtn = document.getElementById('btn-submit-quiz');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Evaluating...';
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]').value;
    var challengeDate = document.querySelector('input[name="date"]').value;

    var formData = new URLSearchParams();
    formData.append('action', 'submit');
    formData.append('csrf_token', csrfToken);
    formData.append('date', challengeDate);
    formData.append('time_taken', quizSeconds);
    formData.append('answers', JSON.stringify(answersObj));

    fetch('../api/aptitude.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(response) {
        if (response.success) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: '🎉 Challenge Completed!',
                    html: '<div class="py-2">' +
                          '<div class="h2 fw-bold text-success mb-1">' + response.score + ' / ' + response.total_questions + ' Correct</div>' +
                          '<p class="text-muted small mb-3">Accuracy: ' + response.accuracy + '% • Time: ' + Math.floor(response.time_taken_seconds / 60) + 'm ' + (response.time_taken_seconds % 60) + 's</p>' +
                          '<div class="p-3 bg-warning-subtle rounded-3 border border-warning-subtle text-dark">' +
                          '<i class="fa-solid fa-fire text-warning fa-lg me-1"></i>' +
                          '<strong>' + response.current_streak + ' Day Streak!</strong>' +
                          (response.is_new_streak_record ? '<span class="badge bg-success ms-2">New Record!</span>' : '') +
                          '</div>' +
                          '</div>',
                    icon: 'success',
                    confirmButtonText: 'View Solutions & Explanations',
                    confirmButtonColor: '#2563eb'
                }).then(function() {
                    window.location.reload();
                });
            } else {
                alert('Challenge Completed! Score: ' + response.score + '/' + response.total_questions + ' | Current Streak: ' + response.current_streak + ' Days');
                window.location.reload();
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Error', response.message || 'Submission failed.', 'error');
            } else {
                alert(response.message || 'Submission failed.');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i> Submit Challenge';
            }
        }
    })
    .catch(function(err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Error', 'Network or server error during submission.', 'error');
        } else {
            alert('Network or server error during submission.');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i> Submit Challenge';
        }
    });
}

// Initialize Timer and Stepper on load
function initAptitudeQuiz() {
    var quizContainer = document.getElementById('quiz-container');
    if (quizContainer) {
        var timerDisplay = document.getElementById('quiz-timer-display');
        if (timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(function() {
            quizSeconds++;
            var mins = String(Math.floor(quizSeconds / 60)).padStart(2, '0');
            var secs = String(quizSeconds % 60).padStart(2, '0');
            if (timerDisplay) timerDisplay.innerText = mins + ':' + secs;
        }, 1000);
    }

    // Add click listeners to stepper pills
    var pills = document.querySelectorAll('.step-pill');
    pills.forEach(function(pill) {
        pill.addEventListener('click', function() {
            var idx = parseInt(this.getAttribute('data-index'));
            showQuestion(idx);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAptitudeQuiz);
} else {
    initAptitudeQuiz();
}
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
