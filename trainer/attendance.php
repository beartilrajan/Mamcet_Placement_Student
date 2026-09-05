<?php
// MAMCET Placement & Learning Portal - Trainer Attendance Portal (Mobile-First)

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../services/TrainingService.php');

$token = trim((string)($_GET['token'] ?? ''));
$db = Database::getInstance()->getConnection();
$service = new TrainingService($db);
$dashboard = $service->getTrainerAttendanceDashboard($token, [
    'search' => trim((string)($_GET['search'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'dept_id' => (int)($_GET['dept_id'] ?? 0)
]);

if (!$dashboard) {
    http_response_code(404);
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <title>Trainer Link Unavailable - MAMCET</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
            .error-card { max-width: 480px; border-radius: 20px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
        </style>
    </head>
    <body class="d-flex align-items-center min-vh-100 p-3">
        <div class="card error-card border-0 mx-auto text-center p-4 p-sm-5 bg-white">
            <div class="text-danger mb-3"><i class="fa-solid fa-link-slash fa-3x"></i></div>
            <h3 class="fw-bold text-dark mb-2">Attendance Link Unavailable</h3>
            <p class="text-muted small mb-0">This trainer attendance link is invalid, expired, or the training session is no longer active.</p>
        </div>
    </body>
    </html><?php
    exit;
}

$training = $dashboard['training'];
$summary = $dashboard['summary'];
$records = $dashboard['records'];
$eventType = $training['event_type'] ?? 'Online';
$isSubmitted = !empty($training['attendance_submitted_at']);
$csrfToken = getCsrfToken();

$statusClasses = [
    'Present' => 'status-present',
    'Late' => 'status-late',
    'Excused' => 'status-excused',
    'Absent' => 'status-absent',
    'Pending' => 'status-pending'
];

$departments = [];
try {
    $deptQuery = $db->prepare("SELECT DISTINCT d.dept_id, d.dept_code FROM departments d JOIN training_departments td ON td.dept_id = d.dept_id WHERE td.training_id = ? ORDER BY d.dept_code ASC");
    $deptQuery->execute([(int)$training['training_id']]);
    $departments = $deptQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $departments = [];
}

$totalEligible = (int)($summary['eligible_count'] ?? 0);
$totalMarked = (int)($summary['attended_any_count'] ?? 0) + (int)($summary['absent_count'] ?? 0);
$totalPresent = (int)($summary['present_count'] ?? 0);
$totalLate = (int)($summary['late_count'] ?? 0);
$totalExcused = (int)($summary['excused_count'] ?? 0);
$totalAbsent = (int)($summary['absent_count'] ?? 0);
$totalPending = (int)($summary['pending_count'] ?? 0);
$progressPct = $totalEligible > 0 ? round(($totalMarked / $totalEligible) * 100) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Trainer Attendance: <?php echo esc($training['title']); ?> - MAMCET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-subtle: #eff6ff;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
            --present-color: #10b981;
            --present-subtle: #ecfdf5;
            --late-color: #f59e0b;
            --late-subtle: #fffbeb;
            --excused-color: #06b6d4;
            --excused-subtle: #ecfeff;
            --absent-color: #ef4444;
            --absent-subtle: #fef2f2;
            --pending-color: #64748b;
            --pending-subtle: #f8fafc;
        }

        body {
            background-color: #f4f7fb;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--slate-900);
            -webkit-tap-highlight-color: transparent;
            padding-bottom: 90px;
        }

        .font-heading { font-family: 'Outfit', sans-serif; }

        .trainer-shell {
            max-width: 1280px;
            margin: 0 auto;
            padding: 12px;
        }

        @media (min-width: 768px) {
            .trainer-shell { padding: 24px; }
            body { padding-bottom: 40px; }
        }

        /* Top Brand Strip */
        .portal-brand-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 4px 6px;
        }
        .portal-logo-text {
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--slate-900);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .portal-logo-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #38bdf8);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        /* Hero Banner */
        .trainer-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
            color: #ffffff;
            border-radius: 20px;
            padding: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(30, 58, 138, 0.25);
            margin-bottom: 16px;
        }
        .trainer-hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.2) 0%, rgba(255,255,255,0) 70%);
            top: -60px;
            right: -60px;
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(8px);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 600;
            color: #ffffff;
        }
        .hero-title {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 8px;
            color: #ffffff;
        }
        @media (min-width: 768px) {
            .trainer-hero { padding: 28px; }
            .hero-title { font-size: 1.85rem; }
        }
        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 18px;
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.88);
        }

        /* Sticky Action Dock */
        .submit-dock {
            position: sticky;
            top: 10px;
            z-index: 100;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 16px;
            padding: 12px 16px;
            box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.08);
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        .submit-dock.locked {
            background: rgba(240, 253, 244, 0.95);
            border-color: #bbf7d0;
        }
        .dock-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .dock-status-dot.saving {
            background-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25);
            animation: pulse-saving 1.2s infinite;
        }
        .dock-status-dot.saved {
            background-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
        }
        @keyframes pulse-saving {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* KPI Grid / Filter Chips */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        @media (min-width: 576px) {
            .kpi-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (min-width: 992px) {
            .kpi-row { grid-template-columns: repeat(6, 1fr); }
        }
        .kpi-chip {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: 14px;
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: left;
            position: relative;
            user-select: none;
        }
        .kpi-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
            border-color: var(--slate-400);
        }
        .kpi-chip.active-filter {
            border-color: var(--primary);
            background: var(--primary-subtle);
            box-shadow: 0 0 0 2px var(--primary);
        }
        .kpi-chip-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--slate-500);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-chip-value {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.1;
            margin-top: 4px;
            color: var(--slate-900);
        }
        .kpi-chip.present .kpi-chip-value { color: var(--present-color); }
        .kpi-chip.late .kpi-chip-value { color: var(--late-color); }
        .kpi-chip.excused .kpi-chip-value { color: var(--excused-color); }
        .kpi-chip.absent .kpi-chip-value { color: var(--absent-color); }
        .kpi-chip.pending .kpi-chip-value { color: var(--slate-600); }

        /* Toolbar & Search */
        .filter-section {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--slate-200);
            padding: 14px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        }
        .search-box-wrap {
            position: relative;
        }
        .search-box-wrap .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            pointer-events: none;
            font-size: 0.85rem;
        }
        .search-input {
            padding-left: 36px !important;
            padding-right: 32px !important;
            height: 42px;
            border-radius: 11px;
            border: 1px solid var(--slate-200);
            font-size: 0.88rem;
        }
        .search-clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--slate-400);
            font-size: 0.85rem;
            cursor: pointer;
            display: none;
        }

        /* Fast Action Buttons Bar */
        .batch-btn-strip {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn-quick-action {
            font-size: 0.78rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            min-height: 36px;
        }

        /* Multi-select bar */
        .bulk-selection-banner {
            background: var(--slate-900);
            color: #ffffff;
            border-radius: 14px;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.18);
        }

        /* Student Roster Cards */
        .roster-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        @media (min-width: 768px) {
            .roster-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
        }
        @media (min-width: 1200px) {
            .roster-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; }
        }

        .student-card {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: 16px;
            padding: 14px;
            transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
            position: relative;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
        }
        .student-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
        }
        .student-avatar {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 11px;
            background: linear-gradient(135deg, #dbeafe, #eff6ff);
            color: var(--primary);
            font-weight: 800;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #bfdbfe;
        }
        .student-name {
            font-size: 0.94rem;
            font-weight: 700;
            color: var(--slate-900);
            line-height: 1.25;
        }
        .student-reg {
            font-size: 0.78rem;
            color: var(--slate-500);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 600;
        }
        .status-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .status-present { color: #065f46; background: #d1fae5; border: 1px solid #a7f3d0; }
        .status-late { color: #92400e; background: #fef3c7; border: 1px solid #fde68a; }
        .status-excused { color: #155e75; background: #cffafe; border: 1px solid #a5f3fc; }
        .status-absent { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; }
        .status-pending { color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; }

        /* 1-Touch Segmented Status Buttons (Mobile Optimized) */
        .status-pills-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            margin-top: 10px;
            margin-bottom: 8px;
            background: #f8fafc;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid var(--slate-200);
        }
        .btn-status-pill {
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 7px 2px;
            color: var(--slate-600);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            transition: all 0.12s ease;
            cursor: pointer;
            min-height: 42px;
        }
        .btn-status-pill i {
            font-size: 0.88rem;
        }
        .btn-status-pill:hover:not(:disabled) {
            background: rgba(0,0,0,0.04);
            color: var(--slate-900);
        }
        .btn-status-pill:disabled {
            cursor: default;
            opacity: 0.6;
        }

        /* Active Pill States */
        .btn-status-pill.active-Present {
            background: var(--present-color) !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
        }
        .btn-status-pill.active-Late {
            background: var(--late-color) !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.35);
        }
        .btn-status-pill.active-Excused {
            background: var(--excused-color) !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(6, 182, 212, 0.35);
        }
        .btn-status-pill.active-Absent {
            background: var(--absent-color) !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.35);
        }
        .btn-status-pill.active-Pending {
            background: var(--slate-700) !important;
            color: #ffffff !important;
            box-shadow: 0 2px 6px rgba(51, 65, 85, 0.25);
        }

        /* Card Note Drawer */
        .note-toggle-btn {
            font-size: 0.74rem;
            font-weight: 600;
            color: var(--slate-500);
            background: none;
            border: none;
            padding: 2px 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .note-toggle-btn:hover {
            color: var(--primary);
        }
        .note-drawer {
            display: none;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed var(--slate-200);
        }
        .note-drawer.open {
            display: block;
        }
        .note-input {
            font-size: 0.8rem;
            border-radius: 9px;
            border: 1px solid var(--slate-200);
            padding: 6px 10px;
            min-height: 48px;
            resize: vertical;
        }

        /* Card Micro-Feedback */
        .card-footer-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.72rem;
            color: var(--slate-400);
            margin-top: 6px;
        }
        .inline-save-state {
            font-weight: 600;
        }
        .inline-save-state.saving { color: #f59e0b; }
        .inline-save-state.saved { color: #10b981; }

        /* Floating Bottom Bar on Mobile */
        @media (max-width: 767.98px) {
            .mobile-bottom-bar {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.97);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-top: 1px solid var(--slate-200);
                padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
                z-index: 1050;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                box-shadow: 0 -6px 20px rgba(15, 23, 42, 0.08);
            }
        }
        @media (min-width: 768px) {
            .mobile-bottom-bar { display: none !important; }
        }

        /* Empty state */
        .empty-roster-box {
            background: #ffffff;
            border: 2px dashed var(--slate-200);
            border-radius: 20px;
            padding: 40px 20px;
            text-align: center;
            color: var(--slate-500);
        }

        /* Confirmation Modal Enhancements */
        .confirm-modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #ffffff;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            padding: 18px 22px;
        }
        .confirm-summary-table td {
            padding: 8px 12px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>

<div class="trainer-shell">
    <!-- Top Brand Strip -->
    <header class="portal-brand-bar">
        <div class="portal-logo-text">
            <span class="portal-logo-icon"><i class="fa-solid fa-graduation-cap"></i></span>
            <span>MAMCET Placement Portal</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-secondary border small px-2 py-1"><i class="fa-solid fa-shield-halved me-1 text-primary"></i>Trainer Link</span>
        </div>
    </header>

    <!-- Hero Banner -->
    <section class="trainer-hero">
        <div class="hero-badge-row">
            <span class="hero-chip"><i class="fa-solid fa-<?php echo $eventType === 'Offline' ? 'location-dot' : 'wifi'; ?>"></i> <?php echo esc($eventType); ?> Session</span>
            <span class="hero-chip"><i class="fa-regular fa-calendar"></i> <?php echo date('M d, Y', strtotime($training['start_date_time'])); ?></span>
            <?php if ($eventType === 'Offline' && !empty($training['venue_location'])): ?>
                <span class="hero-chip"><i class="fa-solid fa-building"></i> <?php echo esc($training['venue_location']); ?></span>
            <?php endif; ?>
            <?php if ($isSubmitted): ?>
                <span class="hero-chip bg-success border-success text-white"><i class="fa-solid fa-lock me-1"></i> Submitted &amp; Locked</span>
            <?php else: ?>
                <span class="hero-chip bg-warning text-dark border-warning"><i class="fa-solid fa-pen-ruler me-1"></i> Draft Mode</span>
            <?php endif; ?>
        </div>

        <h1 class="hero-title font-heading"><?php echo esc($training['title']); ?></h1>

        <div class="hero-meta">
            <div><i class="fa-regular fa-clock me-1 text-info"></i> <?php echo date('h:i A', strtotime($training['start_date_time'])); ?> – <?php echo date('h:i A', strtotime($training['end_date_time'])); ?></div>
            <div><i class="fa-regular fa-user-circle me-1 text-warning"></i> Assigned Trainer: <strong><?php echo esc($training['trainer_name'] ?: 'Assigned Trainer'); ?></strong></div>
            <?php if (!empty($training['department_ids'])): ?>
                <div><i class="fa-solid fa-users me-1 text-success"></i> Depts: <strong><?php echo esc($training['departments_display']); ?></strong></div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Sticky Action Dock (Desktop / Tablet) -->
    <section class="submit-dock <?php echo $isSubmitted ? 'locked' : ''; ?> d-none d-md-block" aria-live="polite">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle <?php echo $isSubmitted ? 'bg-success' : 'bg-primary'; ?> text-white d-flex align-items-center justify-content-center" style="width:42px;height:42px;flex:0 0 42px;">
                    <i class="fa-solid <?php echo $isSubmitted ? 'fa-lock' : 'fa-cloud-arrow-up'; ?> fs-5"></i>
                </div>
                <div>
                    <?php if ($isSubmitted): ?>
                        <div class="fw-bold text-success"><i class="fa-solid fa-check-circle me-1"></i>Attendance Finalized &amp; Locked</div>
                        <div class="small text-muted">Submitted <?php echo date('M d, Y h:i A', strtotime($training['attendance_submitted_at'])); ?><?php if (!empty($training['attendance_submitted_by'])): ?> by <?php echo esc($training['attendance_submitted_by']); ?><?php endif; ?>. Data is officially synced with the portal.</div>
                    <?php else: ?>
                        <div class="fw-bold text-dark d-flex align-items-center">
                            <span class="dock-status-dot saved" id="dockStatusDot"></span>
                            <span id="dockStatusText">Changes are auto-saved as drafts</span>
                        </div>
                        <div class="small text-muted">Tap any status button to save a draft. Submit once finished to publish records to the portal.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$isSubmitted): ?>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-primary fw-bold px-4 py-2 shadow-sm rounded-3" onclick="openSubmitModal()">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit &amp; Lock Attendance
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Interactive KPI Grid / Tap-to-Filter -->
    <section class="kpi-row" id="kpiContainer">
        <div class="kpi-chip" onclick="setKpiFilter('')" title="Click to show all students" id="kpiCardAll">
            <div class="kpi-chip-label"><span>Eligible</span> <i class="fa-solid fa-users text-primary"></i></div>
            <div class="kpi-chip-value" id="kpiEligible"><?php echo number_format($totalEligible); ?></div>
        </div>
        <div class="kpi-chip present" onclick="setKpiFilter('Present')" title="Click to filter Present" id="kpiCardPresent">
            <div class="kpi-chip-label"><span>Present</span> <i class="fa-solid fa-circle-check text-success"></i></div>
            <div class="kpi-chip-value" id="kpiPresent"><?php echo number_format($totalPresent); ?></div>
        </div>
        <div class="kpi-chip late" onclick="setKpiFilter('Late')" title="Click to filter Late" id="kpiCardLate">
            <div class="kpi-chip-label"><span>Late</span> <i class="fa-regular fa-clock text-warning"></i></div>
            <div class="kpi-chip-value" id="kpiLate"><?php echo number_format($totalLate); ?></div>
        </div>
        <div class="kpi-chip excused" onclick="setKpiFilter('Excused')" title="Click to filter Excused" id="kpiCardExcused">
            <div class="kpi-chip-label"><span>Excused</span> <i class="fa-solid fa-shield-halved text-info"></i></div>
            <div class="kpi-chip-value" id="kpiExcused"><?php echo number_format($totalExcused); ?></div>
        </div>
        <div class="kpi-chip absent" onclick="setKpiFilter('Absent')" title="Click to filter Absent" id="kpiCardAbsent">
            <div class="kpi-chip-label"><span>Absent</span> <i class="fa-solid fa-circle-xmark text-danger"></i></div>
            <div class="kpi-chip-value" id="kpiAbsent"><?php echo number_format($totalAbsent); ?></div>
        </div>
        <div class="kpi-chip pending" onclick="setKpiFilter('Pending')" title="Click to filter Pending" id="kpiCardPending">
            <div class="kpi-chip-label"><span>Pending</span> <i class="fa-solid fa-hourglass-half text-secondary"></i></div>
            <div class="kpi-chip-value" id="kpiPending"><?php echo number_format($totalPending); ?></div>
        </div>
    </section>

    <!-- Search & Fast Action Toolbar -->
    <section class="filter-section">
        <div class="row g-2 align-items-center">
            <!-- Search Input -->
            <div class="col-12 col-md-5">
                <div class="search-box-wrap">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" id="liveSearchInput" class="form-control search-input" placeholder="Search name, roll no, or email..." oninput="handleSearchInput(this.value)" autocomplete="off">
                    <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearLiveSearch()"><i class="fa-solid fa-times"></i></button>
                </div>
            </div>

            <!-- Department Filter -->
            <?php if (count($departments) > 1): ?>
                <div class="col-6 col-md-3">
                    <select id="deptFilterSelect" class="form-select form-select-sm h-100 py-2 rounded-3" onchange="handleDeptFilter(this.value)">
                        <option value="0">All Departments (<?php echo count($departments); ?>)</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo (int)$dept['dept_id']; ?>"><?php echo esc($dept['dept_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Fast Action Buttons -->
            <?php if (!$isSubmitted): ?>
                <div class="col-<?php echo count($departments) > 1 ? '6 col-md-4' : '12 col-md-7'; ?> text-end">
                    <div class="batch-btn-strip justify-content-start justify-content-md-end">
                        <button type="button" class="btn btn-success btn-quick-action" onclick="confirmTrainerMarkAll('Present')" title="Mark all visible students as Present">
                            <i class="fa-solid fa-check-double"></i> Mark All Present
                        </button>
                        <button type="button" class="btn btn-outline-success btn-quick-action" onclick="confirmTrainerMarkAll('Present', true)" title="Set only pending students to Present">
                            <i class="fa-solid fa-user-check"></i> Pending → Present
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-quick-action" onclick="confirmTrainerMarkAll('Absent', true)" title="Set only pending students to Absent">
                            <i class="fa-solid fa-user-xmark"></i> Pending → Absent
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Multi-select Bulk Bar (Appears when student checkboxes are selected) -->
    <?php if (!$isSubmitted): ?>
        <div id="trainerBulkBar" class="bulk-selection-banner d-none">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-circle-check text-success fs-5"></i>
                <span class="fw-bold"><span id="trainerSelectedCount">0</span> selected</span>
            </div>
            <div class="d-flex align-items-center gap-1 flex-wrap">
                <span class="small text-white-50 me-1">Apply:</span>
                <button type="button" class="btn btn-sm btn-success fw-bold px-2 py-1" onclick="applyTrainerBulk('Present')">Present</button>
                <button type="button" class="btn btn-sm btn-warning text-dark fw-bold px-2 py-1" onclick="applyTrainerBulk('Late')">Late</button>
                <button type="button" class="btn btn-sm btn-info text-dark fw-bold px-2 py-1" onclick="applyTrainerBulk('Excused')">Excused</button>
                <button type="button" class="btn btn-sm btn-danger fw-bold px-2 py-1" onclick="applyTrainerBulk('Absent')">Absent</button>
                <button type="button" class="btn btn-sm btn-secondary fw-bold px-2 py-1" onclick="applyTrainerBulk('Pending')">Pending</button>
                <button type="button" class="btn btn-sm btn-outline-light ms-2 px-2 py-1" onclick="clearTrainerSelections()">Cancel</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Student Attendance Cards Grid -->
    <main class="roster-grid" id="trainerRoster">
        <?php if (empty($records)): ?>
            <div class="col-12 w-100" style="grid-column: 1 / -1;">
                <div class="empty-roster-box">
                    <i class="fa-solid fa-user-slash fa-3x mb-3 text-muted opacity-50"></i>
                    <h5 class="fw-bold text-dark">No Students Found</h5>
                    <p class="small text-muted mb-0">No eligible students match your filter criteria.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($records as $index => $record): ?>
            <?php
                $recordStatus = in_array(($record['attendance_status'] ?? 'Pending'), ['Partial', 'In Progress'], true) ? 'Pending' : ($record['attendance_status'] ?? 'Pending');
                $recordStatus = array_key_exists($recordStatus, $statusClasses) ? $recordStatus : 'Pending';
                $hasDraft = !empty($record['has_draft']);
                $recordNote = $hasDraft ? (string)($record['draft_note'] ?? '') : (string)($record['attendance_note'] ?? '');
                $updatedAt = $hasDraft ? ($record['draft_updated_at'] ?? null) : ($record['marked_at'] ?? null);
                $initial = strtoupper(substr(trim((string)$record['student_name']), 0, 1));
                $studentId = (int)$record['student_id'];
                $hasNote = !empty(trim($recordNote));
            ?>
            <article class="student-card trainer-row"
                     id="card-<?php echo $studentId; ?>"
                     data-student-id="<?php echo $studentId; ?>"
                     data-student-name="<?php echo esc($record['student_name']); ?>"
                     data-reg-no="<?php echo esc($record['registration_number']); ?>"
                     data-email="<?php echo esc($record['email']); ?>"
                     data-dept-id="<?php echo (int)($record['dept_id'] ?? 0); ?>"
                     data-status="<?php echo esc($recordStatus); ?>"
                     data-has-draft="<?php echo $hasDraft ? '1' : '0'; ?>">

                <!-- Card Header -->
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2 min-w-0">
                        <?php if (!$isSubmitted): ?>
                            <input type="checkbox" class="form-check-input trainer-select-cb" value="<?php echo $studentId; ?>" onchange="updateTrainerBulkBar()" title="Select student">
                        <?php endif; ?>
                        <span class="student-avatar"><?php echo esc($initial ?: '?'); ?></span>
                        <div class="min-w-0">
                            <div class="student-name text-truncate" title="<?php echo esc($record['student_name']); ?>"><?php echo esc($record['student_name']); ?></div>
                            <div class="student-reg text-truncate"><?php echo esc($record['registration_number']); ?></div>
                        </div>
                    </div>
                    <span class="status-badge <?php echo $statusClasses[$recordStatus]; ?> status-pill-badge" id="badge-<?php echo $studentId; ?>">
                        <?php echo esc($recordStatus); ?>
                    </span>
                </div>

                <!-- Department & Contact Sub-row -->
                <div class="d-flex align-items-center justify-content-between gap-2 small text-muted mb-2">
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-building-columns me-1 text-secondary"></i><?php echo esc($record['dept_code']); ?></span>
                    <?php if (!empty($record['email'])): ?>
                        <span class="text-truncate small text-muted" title="<?php echo esc($record['email']); ?>"><i class="fa-regular fa-envelope me-1"></i><?php echo esc($record['email']); ?></span>
                    <?php endif; ?>
                </div>

                <!-- 1-Touch Status Buttons Row (Mobile & Desktop) -->
                <div class="status-pills-row" role="group" aria-label="Select attendance status">
                    <button type="button" 
                            class="btn-status-pill <?php echo $recordStatus === 'Present' ? 'active-Present' : ''; ?>" 
                            data-status="Present" 
                            onclick="setStudentStatus(<?php echo $studentId; ?>, 'Present')"
                            <?php echo $isSubmitted ? 'disabled' : ''; ?>
                            title="Mark Present">
                        <i class="fa-solid fa-check"></i>
                        <span>Present</span>
                    </button>
                    <button type="button" 
                            class="btn-status-pill <?php echo $recordStatus === 'Late' ? 'active-Late' : ''; ?>" 
                            data-status="Late" 
                            onclick="setStudentStatus(<?php echo $studentId; ?>, 'Late')"
                            <?php echo $isSubmitted ? 'disabled' : ''; ?>
                            title="Mark Late">
                        <i class="fa-regular fa-clock"></i>
                        <span>Late</span>
                    </button>
                    <button type="button" 
                            class="btn-status-pill <?php echo $recordStatus === 'Excused' ? 'active-Excused' : ''; ?>" 
                            data-status="Excused" 
                            onclick="setStudentStatus(<?php echo $studentId; ?>, 'Excused')"
                            <?php echo $isSubmitted ? 'disabled' : ''; ?>
                            title="Mark Excused">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Excused</span>
                    </button>
                    <button type="button" 
                            class="btn-status-pill <?php echo $recordStatus === 'Absent' ? 'active-Absent' : ''; ?>" 
                            data-status="Absent" 
                            onclick="setStudentStatus(<?php echo $studentId; ?>, 'Absent')"
                            <?php echo $isSubmitted ? 'disabled' : ''; ?>
                            title="Mark Absent">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Absent</span>
                    </button>
                    <button type="button" 
                            class="btn-status-pill <?php echo $recordStatus === 'Pending' ? 'active-Pending' : ''; ?>" 
                            data-status="Pending" 
                            onclick="setStudentStatus(<?php echo $studentId; ?>, 'Pending')"
                            <?php echo $isSubmitted ? 'disabled' : ''; ?>
                            title="Mark Pending">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Pending</span>
                    </button>
                </div>

                <!-- Expandable Note Field -->
                <div>
                    <button type="button" class="note-toggle-btn" onclick="toggleNoteDrawer(<?php echo $studentId; ?>)">
                        <i class="fa-regular fa-comment-dots"></i>
                        <span id="noteToggleText-<?php echo $studentId; ?>"><?php echo $hasNote ? 'Note added (edit)' : '+ Add Note'; ?></span>
                    </button>
                    <div class="note-drawer <?php echo $hasNote ? 'open' : ''; ?>" id="noteDrawer-<?php echo $studentId; ?>">
                        <textarea class="form-control note-input" 
                                  id="note-<?php echo $studentId; ?>" 
                                  placeholder="Reason for late/excused or note..." 
                                  maxlength="2000" 
                                  oninput="handleNoteChange(<?php echo $studentId; ?>)" 
                                  onblur="saveNoteOnBlur(<?php echo $studentId; ?>)"
                                  <?php echo $isSubmitted ? 'disabled' : ''; ?>><?php echo esc($recordNote); ?></textarea>
                    </div>
                </div>

                <!-- Footer & Inline Feedback -->
                <div class="card-footer-info">
                    <span class="inline-save-state <?php echo $hasDraft ? 'saved' : ''; ?>" id="state-<?php echo $studentId; ?>">
                        <?php if ($isSubmitted): ?>
                            <i class="fa-solid fa-lock text-success me-1"></i>Finalized
                        <?php elseif ($hasDraft): ?>
                            <i class="fa-solid fa-check text-success me-1"></i>Draft saved
                        <?php else: ?>
                            <i class="fa-regular fa-clock me-1"></i>Not marked
                        <?php endif; ?>
                    </span>
                    <span class="small text-muted" id="time-<?php echo $studentId; ?>">
                        <?php if (!empty($updatedAt)): ?>
                            <?php echo date('M d, h:i A', strtotime($updatedAt)); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>
    </main>

    <!-- Bottom Confidentiality Notice -->
    <div class="text-center text-muted small py-4">
        <i class="fa-solid fa-shield-halved me-1 text-primary"></i>Keep this trainer link private. All updates save as drafts until final submission.
    </div>
</div>

<!-- Mobile Bottom Fixed Bar -->
<?php if (!$isSubmitted): ?>
    <div class="mobile-bottom-bar" id="mobileBottomBar">
        <div class="min-w-0">
            <div class="small fw-bold text-dark d-flex align-items-center">
                <span class="dock-status-dot saved me-1" id="mobileDockDot"></span>
                <span class="text-truncate" id="mobileDockText">Drafts saved</span>
            </div>
            <div class="text-muted" style="font-size: 0.72rem;">
                <span id="mobileMarkedCount"><?php echo $totalMarked; ?></span> of <?php echo $totalEligible; ?> marked
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm fw-bold px-3 py-2 rounded-3" onclick="openSubmitModal()">
            <i class="fa-solid fa-paper-plane me-1"></i>Submit
        </button>
    </div>
<?php endif; ?>

<!-- Submission Confirmation Modal -->
<?php if (!$isSubmitted): ?>
<div class="modal fade" id="submitConfirmModal" tabindex="-1" aria-labelledby="submitConfirmTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="confirm-modal-header d-flex align-items-center justify-content-between">
                <h5 class="modal-title fw-bold text-white mb-0" id="submitConfirmTitle">
                    <i class="fa-solid fa-lock text-warning me-2"></i>Submit &amp; Lock Attendance?
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3 text-secondary">
                    You are about to publish the final attendance roster for <strong><?php echo esc($training['title']); ?></strong>.
                </p>

                <!-- Breakdown Summary Table -->
                <div class="card border rounded-3 bg-light p-3 mb-3">
                    <div class="fw-bold small text-dark mb-2 text-uppercase" style="letter-spacing: 0.05em;">Attendance Summary</div>
                    <table class="w-100 confirm-summary-table">
                        <tr>
                            <td class="text-muted"><i class="fa-solid fa-users text-primary me-2"></i>Total Eligible Students</td>
                            <td class="text-end fw-bold text-dark" id="modalCountEligible"><?php echo number_format($totalEligible); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fa-solid fa-circle-check text-success me-2"></i>Present</td>
                            <td class="text-end fw-bold text-success" id="modalCountPresent"><?php echo number_format($totalPresent); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fa-regular fa-clock text-warning me-2"></i>Late / Excused</td>
                            <td class="text-end fw-bold text-warning" id="modalCountLateExcused"><?php echo number_format($totalLate + $totalExcused); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fa-solid fa-circle-xmark text-danger me-2"></i>Absent</td>
                            <td class="text-end fw-bold text-danger" id="modalCountAbsent"><?php echo number_format($totalAbsent); ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="text-muted"><i class="fa-solid fa-hourglass-half text-secondary me-2"></i>Pending (Unmarked)</td>
                            <td class="text-end fw-bold text-secondary" id="modalCountPending"><?php echo number_format($totalPending); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Pending Alert if any students are pending -->
                <div class="alert alert-warning border-0 rounded-3 small mb-3 <?php echo $totalPending > 0 ? '' : 'd-none'; ?>" id="modalPendingAlert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <strong>Notice:</strong> <span id="modalPendingText"><?php echo $totalPending; ?> student(s)</span> are still marked as <strong>Pending</strong>.
                </div>

                <!-- Irreversible Warning Banner -->
                <div class="alert alert-danger border-0 rounded-3 small mb-0">
                    <div class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Irreversible Action</div>
                    Once submitted, attendance is officially posted to the portal and <strong>this trainer link will be permanently locked</strong>. You will not be able to edit or change any status after submitting.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 pb-4 px-4">
                <button type="button" class="btn btn-light border fw-semibold px-3 py-2 rounded-3" data-bs-dismiss="modal">
                    <i class="fa-solid fa-arrow-left me-1"></i> Review Roster
                </button>
                <button type="button" class="btn btn-primary fw-bold px-4 py-2 rounded-3 shadow" id="confirmSubmitBtn" onclick="submitTrainerAttendance()">
                    <i class="fa-solid fa-paper-plane me-1"></i> Confirm &amp; Lock
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const TRAINER_TOKEN = <?php echo json_encode($token); ?>;
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const IS_SUBMITTED = <?php echo $isSubmitted ? 'true' : 'false'; ?>;
const STATUS_CLASSES = <?php echo json_encode($statusClasses); ?>;

let currentFilterStatus = '';
let currentDeptFilter = 0;
let currentSearchQuery = '';
const noteDebounceTimers = {};

function getTrainerErrorMessage(err, defaultMsg = 'Unable to save draft. Please try again.') {
    if (!err) return defaultMsg;
    if (typeof err === 'string') return err;
    if (err.responseJSON && err.responseJSON.message) return err.responseJSON.message;
    if (err.message && typeof err.message === 'string' && err.message !== 'error') return err.message;
    if (err.responseText) {
        try {
            const parsed = JSON.parse(err.responseText);
            if (parsed && parsed.message) return parsed.message;
        } catch (e) {}
    }
    if (err.statusText && err.statusText !== 'error') return err.statusText;
    return defaultMsg;
}

function postTrainerAction(data) {
    return $.ajax({
        url: '../api/training-api.php',
        type: 'POST',
        dataType: 'json',
        data: data
    });
}

function showTrainerToast(icon, title, text = '') {
    if (window.Swal) {
        Swal.fire({
            icon: icon,
            title: title,
            text: text,
            timer: icon === 'success' ? 1600 : undefined,
            showConfirmButton: icon !== 'success',
            toast: icon === 'success',
            position: icon === 'success' ? 'top-end' : 'center'
        });
    } else if (text) {
        window.alert(text);
    }
}

function updateTrainerKpi(summary) {
    if (!summary) return;
    const elEligible = document.getElementById('kpiEligible');
    const elPresent = document.getElementById('kpiPresent');
    const elLate = document.getElementById('kpiLate');
    const elExcused = document.getElementById('kpiExcused');
    const elAbsent = document.getElementById('kpiAbsent');
    const elPending = document.getElementById('kpiPending');

    if (elEligible) elEligible.textContent = Number(summary.eligible_count || 0).toLocaleString();
    if (elPresent) elPresent.textContent = Number(summary.present_count || 0).toLocaleString();
    if (elLate) elLate.textContent = Number(summary.late_count || 0).toLocaleString();
    if (elExcused) elExcused.textContent = Number(summary.excused_count || 0).toLocaleString();
    if (elAbsent) elAbsent.textContent = Number(summary.absent_count || 0).toLocaleString();
    if (elPending) elPending.textContent = Number(summary.pending_count || 0).toLocaleString();

    // Update modal counters
    const mEligible = document.getElementById('modalCountEligible');
    const mPresent = document.getElementById('modalCountPresent');
    const mLateExcused = document.getElementById('modalCountLateExcused');
    const mAbsent = document.getElementById('modalCountAbsent');
    const mPending = document.getElementById('modalCountPending');
    const mPendingAlert = document.getElementById('modalPendingAlert');
    const mPendingText = document.getElementById('modalPendingText');
    const mobileMarked = document.getElementById('mobileMarkedCount');

    const totalMarked = (summary.attended_any_count || 0) + (summary.absent_count || 0);
    if (mobileMarked) mobileMarked.textContent = totalMarked;

    if (mEligible) mEligible.textContent = Number(summary.eligible_count || 0).toLocaleString();
    if (mPresent) mPresent.textContent = Number(summary.present_count || 0).toLocaleString();
    if (mLateExcused) mLateExcused.textContent = Number((summary.late_count || 0) + (summary.excused_count || 0)).toLocaleString();
    if (mAbsent) mAbsent.textContent = Number(summary.absent_count || 0).toLocaleString();
    if (mPending) mPending.textContent = Number(summary.pending_count || 0).toLocaleString();

    if (mPendingAlert && mPendingText) {
        const pCount = summary.pending_count || 0;
        if (pCount > 0) {
            mPendingAlert.classList.remove('d-none');
            mPendingText.textContent = `${pCount} student(s)`;
        } else {
            mPendingAlert.classList.add('d-none');
        }
    }
}

function setStudentStatus(studentId, newStatus) {
    if (IS_SUBMITTED) return;
    const card = document.getElementById('card-' + studentId);
    if (!card) return;

    const oldStatus = card.dataset.status;
    if (oldStatus === newStatus && card.dataset.dirty !== '1') {
        return; // No change needed
    }

    // Instantly update active button styling
    card.dataset.status = newStatus;
    const pills = card.querySelectorAll('.btn-status-pill');
    pills.forEach(p => {
        p.classList.remove('active-Present', 'active-Late', 'active-Excused', 'active-Absent', 'active-Pending');
        if (p.dataset.status === newStatus) {
            p.classList.add('active-' + newStatus);
        }
    });

    // Update status badge
    const badge = document.getElementById('badge-' + studentId);
    if (badge) {
        badge.className = 'status-badge ' + (STATUS_CLASSES[newStatus] || STATUS_CLASSES.Pending);
        badge.textContent = newStatus;
    }

    // Mark saving state
    setCardSavingState(studentId, 'saving');

    const noteEl = document.getElementById('note-' + studentId);
    const note = noteEl ? noteEl.value.trim() : '';

    postTrainerAction({
        action: 'save_trainer_attendance_draft',
        trainer_token: TRAINER_TOKEN,
        student_id: studentId,
        status: newStatus,
        note: note,
        csrf_token: CSRF_TOKEN
    }).then(res => {
        if (!res || !res.success) {
            throw new Error((res && res.message) ? res.message : 'Unable to save draft.');
        }
        card.dataset.dirty = '0';
        card.dataset.hasDraft = '1';
        setCardSavingState(studentId, 'saved', res.drafted_at || 'Just now');
        updateTrainerKpi(res.summary);
    }).catch(err => {
        setCardSavingState(studentId, 'error');
        const msg = getTrainerErrorMessage(err, 'Unable to save draft. Please try again.');
        showTrainerToast('error', 'Draft not saved', msg);
    });
}

function setCardSavingState(studentId, state, timestamp = '') {
    const stateEl = document.getElementById('state-' + studentId);
    const timeEl = document.getElementById('time-' + studentId);
    const dockDot = document.getElementById('dockStatusDot');
    const dockText = document.getElementById('dockStatusText');
    const mobileDot = document.getElementById('mobileDockDot');
    const mobileText = document.getElementById('mobileDockText');

    if (state === 'saving') {
        if (stateEl) {
            stateEl.className = 'inline-save-state saving';
            stateEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-warning me-1"></i>Saving...';
        }
        if (dockDot) dockDot.className = 'dock-status-dot saving';
        if (dockText) dockText.textContent = 'Saving draft...';
        if (mobileDot) mobileDot.className = 'dock-status-dot saving me-1';
        if (mobileText) mobileText.textContent = 'Saving...';
    } else if (state === 'saved') {
        if (stateEl) {
            stateEl.className = 'inline-save-state saved';
            stateEl.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i>Draft saved';
        }
        if (timeEl && timestamp) {
            timeEl.textContent = timestamp;
        }
        if (dockDot) dockDot.className = 'dock-status-dot saved';
        if (dockText) dockText.textContent = 'All changes saved as drafts';
        if (mobileDot) mobileDot.className = 'dock-status-dot saved me-1';
        if (mobileText) mobileText.textContent = 'Drafts saved';
    } else if (state === 'error') {
        if (stateEl) {
            stateEl.className = 'inline-save-state text-danger';
            stateEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>Failed';
        }
    }
}

function toggleNoteDrawer(studentId) {
    const drawer = document.getElementById('noteDrawer-' + studentId);
    const toggleText = document.getElementById('noteToggleText-' + studentId);
    if (!drawer) return;
    const isOpen = drawer.classList.toggle('open');
    if (isOpen) {
        const textarea = document.getElementById('note-' + studentId);
        if (textarea) textarea.focus();
    }
}

function handleNoteChange(studentId) {
    if (IS_SUBMITTED) return;
    const card = document.getElementById('card-' + studentId);
    if (card) card.dataset.dirty = '1';

    clearTimeout(noteDebounceTimers[studentId]);
    noteDebounceTimers[studentId] = setTimeout(() => {
        saveNoteOnBlur(studentId);
    }, 1200);
}

function saveNoteOnBlur(studentId) {
    if (IS_SUBMITTED) return;
    clearTimeout(noteDebounceTimers[studentId]);
    const card = document.getElementById('card-' + studentId);
    if (!card || card.dataset.dirty !== '1') return;

    const noteEl = document.getElementById('note-' + studentId);
    const note = noteEl ? noteEl.value.trim() : '';
    const status = card.dataset.status || 'Pending';

    // Update note toggle label
    const toggleText = document.getElementById('noteToggleText-' + studentId);
    if (toggleText) {
        toggleText.textContent = note ? 'Note added (edit)' : '+ Add Note';
    }

    setCardSavingState(studentId, 'saving');

    postTrainerAction({
        action: 'save_trainer_attendance_draft',
        trainer_token: TRAINER_TOKEN,
        student_id: studentId,
        status: status,
        note: note,
        csrf_token: CSRF_TOKEN
    }).then(res => {
        if (!res || !res.success) {
            throw new Error((res && res.message) ? res.message : 'Unable to save note.');
        }
        card.dataset.dirty = '0';
        card.dataset.hasDraft = '1';
        setCardSavingState(studentId, 'saved', res.drafted_at || 'Just now');
    }).catch(err => {
        setCardSavingState(studentId, 'error');
        const msg = getTrainerErrorMessage(err, 'Unable to save note.');
        showTrainerToast('error', 'Note not saved', msg);
    });
}

function setKpiFilter(status) {
    currentFilterStatus = (currentFilterStatus === status) ? '' : status;

    // Highlight active KPI chip
    document.querySelectorAll('.kpi-chip').forEach(chip => chip.classList.remove('active-filter'));
    if (currentFilterStatus) {
        const activeChip = document.getElementById('kpiCard' + currentFilterStatus);
        if (activeChip) activeChip.classList.add('active-filter');
    }

    applyRosterFilters();
}

function handleSearchInput(query) {
    currentSearchQuery = (query || '').toLowerCase().trim();
    const clearBtn = document.getElementById('searchClearBtn');
    if (clearBtn) {
        clearBtn.style.display = currentSearchQuery ? 'block' : 'none';
    }
    applyRosterFilters();
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) input.value = '';
    handleSearchInput('');
}

function handleDeptFilter(deptId) {
    currentDeptFilter = parseInt(deptId, 10) || 0;
    applyRosterFilters();
}

function applyRosterFilters() {
    const cards = document.querySelectorAll('.trainer-row');
    let visibleCount = 0;

    cards.forEach(card => {
        const name = (card.dataset.studentName || '').toLowerCase();
        const reg = (card.dataset.regNo || '').toLowerCase();
        const email = (card.dataset.email || '').toLowerCase();
        const status = card.dataset.status || 'Pending';
        const deptId = parseInt(card.dataset.deptId, 10) || 0;

        let matchesSearch = true;
        if (currentSearchQuery) {
            matchesSearch = name.includes(currentSearchQuery) || reg.includes(currentSearchQuery) || email.includes(currentSearchQuery);
        }

        let matchesStatus = true;
        if (currentFilterStatus) {
            matchesStatus = (status === currentFilterStatus);
        }

        let matchesDept = true;
        if (currentDeptFilter > 0) {
            matchesDept = (deptId === currentDeptFilter);
        }

        const isVisible = matchesSearch && matchesStatus && matchesDept;
        card.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    });

    updateTrainerBulkBar();
}

function updateTrainerBulkBar() {
    const checked = document.querySelectorAll('.trainer-select-cb:checked');
    const bulkBar = document.getElementById('trainerBulkBar');
    const countEl = document.getElementById('trainerSelectedCount');
    if (countEl) countEl.textContent = checked.length;
    if (bulkBar) {
        if (checked.length > 0) {
            bulkBar.classList.remove('d-none');
        } else {
            bulkBar.classList.add('d-none');
        }
    }
}

function clearTrainerSelections() {
    document.querySelectorAll('.trainer-select-cb').forEach(cb => { cb.checked = false; });
    updateTrainerBulkBar();
}

function applyTrainerBulk(status) {
    if (IS_SUBMITTED) return;
    const checked = Array.from(document.querySelectorAll('.trainer-select-cb:checked'));
    if (!checked.length) {
        showTrainerToast('info', 'No students selected', 'Please select at least one student.');
        return;
    }

    const ids = checked.map(cb => cb.value);

    Swal.fire({
        title: `Save ${ids.length} student(s) as ${status}?`,
        text: 'This saves as a draft. You can make more changes before submitting.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: `Yes, Save as ${status}`
    }).then(result => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Saving drafts...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        postTrainerAction({
            action: 'bulk_mark_trainer_attendance',
            trainer_token: TRAINER_TOKEN,
            student_ids: ids,
            status: status,
            csrf_token: CSRF_TOKEN
        }).then(res => {
            if (!res.success) throw new Error(res.message || 'Unable to save drafts.');
            checked.forEach(cb => {
                const card = cb.closest('.trainer-row');
                if (card) {
                    const sid = card.dataset.studentId;
                    card.dataset.status = status;
                    card.dataset.dirty = '0';
                    card.dataset.hasDraft = '1';

                    // Update pills
                    const pills = card.querySelectorAll('.btn-status-pill');
                    pills.forEach(p => {
                        p.classList.remove('active-Present', 'active-Late', 'active-Excused', 'active-Absent', 'active-Pending');
                        if (p.dataset.status === status) p.classList.add('active-' + status);
                    });

                    // Update badge
                    const badge = document.getElementById('badge-' + sid);
                    if (badge) {
                        badge.className = 'status-badge ' + (STATUS_CLASSES[status] || STATUS_CLASSES.Pending);
                        badge.textContent = status;
                    }

                    setCardSavingState(sid, 'saved');
                }
            });

            updateTrainerKpi(res.summary);
            clearTrainerSelections();
            Swal.fire({ icon: 'success', title: 'Drafts Saved', text: res.message || 'Changes saved successfully.', timer: 1500, showConfirmButton: false });
        }).catch(err => {
            const msg = getTrainerErrorMessage(err, 'Failed to save drafts.');
            Swal.fire('Error', msg, 'error');
        });
    });
}

function confirmTrainerMarkAll(status, onlyPending = false) {
    if (IS_SUBMITTED) return;
    let rows = Array.from(document.querySelectorAll('.trainer-row')).filter(r => r.style.display !== 'none');
    if (onlyPending) {
        rows = rows.filter(r => r.dataset.status === 'Pending');
    }

    if (!rows.length) {
        showTrainerToast('info', 'No students found', onlyPending ? 'There are no visible pending students.' : 'There are no visible students.');
        return;
    }

    const ids = rows.map(r => r.dataset.studentId);
    const title = onlyPending ? `Set ${ids.length} pending student(s) to ${status}?` : `Set all ${ids.length} visible student(s) to ${status}?`;

    Swal.fire({
        title: title,
        text: 'This will update drafts for all matching students.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: `Save Drafts as ${status}`
    }).then(result => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Saving drafts...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        postTrainerAction({
            action: 'bulk_mark_trainer_attendance',
            trainer_token: TRAINER_TOKEN,
            student_ids: ids,
            status: status,
            csrf_token: CSRF_TOKEN
        }).then(res => {
            if (!res || !res.success) {
                throw new Error((res && res.message) ? res.message : 'Unable to save drafts.');
            }
            rows.forEach(card => {
                const sid = card.dataset.studentId;
                card.dataset.status = status;
                card.dataset.dirty = '0';
                card.dataset.hasDraft = '1';

                const pills = card.querySelectorAll('.btn-status-pill');
                pills.forEach(p => {
                    p.classList.remove('active-Present', 'active-Late', 'active-Excused', 'active-Absent', 'active-Pending');
                    if (p.dataset.status === status) p.classList.add('active-' + status);
                });

                const badge = document.getElementById('badge-' + sid);
                if (badge) {
                    badge.className = 'status-badge ' + (STATUS_CLASSES[status] || STATUS_CLASSES.Pending);
                    badge.textContent = status;
                }

                setCardSavingState(sid, 'saved');
            });

            updateTrainerKpi(res.summary);
            Swal.fire({ icon: 'success', title: 'Drafts Saved', text: res.message || 'All records saved as drafts.', timer: 1500, showConfirmButton: false });
        }).catch(err => {
            const msg = getTrainerErrorMessage(err, 'Failed to save drafts.');
            Swal.fire('Error', msg, 'error');
        });
    });
}

function openSubmitModal() {
    if (IS_SUBMITTED) return;
    const modalEl = document.getElementById('submitConfirmModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function submitTrainerAttendance() {
    if (IS_SUBMITTED) return;
    const button = document.getElementById('confirmSubmitBtn');
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Finalizing...';
    }

    postTrainerAction({
        action: 'submit_trainer_attendance',
        trainer_token: TRAINER_TOKEN,
        csrf_token: CSRF_TOKEN
    }).then(res => {
        if (!res || !res.success) {
            throw new Error((res && res.message) ? res.message : 'Unable to submit attendance.');
        }
        const modalEl = document.getElementById('submitConfirmModal');
        if (modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }

        return Swal.fire({
            icon: 'success',
            title: 'Attendance Submitted & Locked',
            text: 'Attendance has been officially posted to student records. This link is now locked.',
            confirmButtonText: 'Done',
            confirmButtonColor: '#2563eb'
        });
    }).then(() => {
        window.location.reload();
    }).catch(err => {
        if (button) {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Confirm &amp; Lock';
        }
        const msg = getTrainerErrorMessage(err, 'Please check your connection and try again.');
        showTrainerToast('error', 'Submission Failed', msg);
    });
}
</script>
</body>
</html>
