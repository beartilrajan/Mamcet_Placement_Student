<?php
// MAMCET Placement & Learning Portal - Student Roster Page (Compact Senior UI/UX Edition)

require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../services/StudentService.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Sync batch mappings on page load
syncBatchAcademicSessions($db);

$flash = getFlash();
if ($flash && is_array($flash)) {
    if (($flash['type'] ?? '') === 'success') {
        $success = $flash['message'] ?? '';
    } elseif (($flash['type'] ?? '') === 'error') {
        $error = $flash['message'] ?? '';
    }
}

// Active session details
$activeSessionId = getActiveAcademicSessionId();
$stmtSess = $db->prepare("SELECT session_id, session_name, start_year, end_year FROM academic_sessions WHERE session_id = ?");
$stmtSess->execute([$activeSessionId]);
$currentSession = $stmtSess->fetch();
if (!$currentSession || empty($currentSession['start_year'])) {
    $stmtActSess = $db->query("SELECT session_id, session_name, start_year, end_year FROM academic_sessions WHERE is_active = 1 LIMIT 1");
    $currentSession = $stmtActSess->fetch();
    if (!$currentSession) {
        $currentSession = $db->query("SELECT session_id, session_name, start_year, end_year FROM academic_sessions ORDER BY start_year DESC LIMIT 1")->fetch();
    }
}
$sessStartYear = (int)($currentSession['start_year'] ?? 2026);
$sessEndYear = (int)($currentSession['end_year'] ?? 2027);
$activeSessionName = $currentSession['session_name'] ?? '2026–2027';

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrfRequest();
    $action = $_POST['action'];
    $studentId = (int)($_POST['student_id'] ?? 0);
    
    if ($action === 'toggle_login') {
        $newStatus = $_POST['status'] === 'active' ? 'active' : 'inactive';
        try {
            $stmt = $db->prepare("SELECT user_id, registration_number FROM students WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $sData = $stmt->fetch();
            
            if ($sData && $sData['user_id']) {
                $stmtUpd = $db->prepare("UPDATE users SET status = ? WHERE user_id = ?");
                $stmtUpd->execute([$newStatus, $sData['user_id']]);
                setFlash('success', "Login access for student {$sData['registration_number']} set to " . ($newStatus === 'active' ? 'Enabled' : 'Disabled') . ".");
                logActivity($db, $_SESSION['user_id'], 'Toggle Student Login', "Set login status of student {$sData['registration_number']} to $newStatus");
            } else {
                setFlash('error', "Student has not completed their first-time login setup yet.");
            }
        } catch (Exception $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    } elseif ($action === 'reset_password') {
        try {
            $stmt = $db->prepare("SELECT user_id, registration_number FROM students WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $sData = $stmt->fetch();
            
            if ($sData && $sData['user_id']) {
                $tempPassword = 'MAMCET@' . substr($sData['registration_number'], -4);
                $newHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                
                $stmtUpd = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmtUpd->execute([$newHash, $sData['user_id']]);
                setFlash('success', "Password reset successfully for student {$sData['registration_number']}. Temporary Password is: <strong>$tempPassword</strong>");
                logActivity($db, $_SESSION['user_id'], 'Reset Student Password', "Reset password of student {$sData['registration_number']}");
            } else {
                setFlash('error', "Student has not completed their first-time login setup yet.");
            }
        } catch (Exception $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    } elseif ($action === 'add_note') {
        $noteText = trim($_POST['note_text'] ?? '');
        if (empty($noteText)) {
            setFlash('error', 'Note content cannot be empty.');
        } else {
            try {
                $stmt = $db->prepare("SELECT officer_id FROM placement_officers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $officerId = $stmt->fetchColumn();
                
                if ($officerId) {
                    $stmtIns = $db->prepare("INSERT INTO officer_notes (student_id, officer_id, note_content) VALUES (?, ?, ?)");
                    $stmtIns->execute([$studentId, $officerId, $noteText]);
                    setFlash('success', 'Placement note added successfully.');
                } else {
                    setFlash('error', 'Only designated placement officers can add notes.');
                }
            } catch (Exception $e) {
                setFlash('error', 'Database error: ' . $e->getMessage());
            }
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    } elseif ($action === 'bulk_status') {
        $studentIds = $_POST['bulk_students'] ?? [];
        $newStatus = $_POST['bulk_status'] === 'active' ? 'active' : 'inactive';
        
        if (empty($studentIds)) {
            setFlash('error', 'No students selected for bulk operation.');
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $stmt = $db->prepare("SELECT user_id FROM students WHERE student_id IN ($placeholders) AND user_id IS NOT NULL");
                $stmt->execute($studentIds);
                $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($userIds)) {
                    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                    $stmtUpd = $db->prepare("UPDATE users SET status = ? WHERE user_id IN ($userPlaceholders)");
                    $stmtUpd->execute(array_merge([$newStatus], $userIds));
                    setFlash('success', "Successfully updated login state for " . count($userIds) . " student accounts.");
                    logActivity($db, $_SESSION['user_id'], 'Bulk Login Toggle', "Updated status of " . count($userIds) . " user accounts to $newStatus");
                } else {
                    setFlash('error', "Selected students have not set up user accounts yet.");
                }
            } catch (Exception $e) {
                setFlash('error', 'Database error: ' . $e->getMessage());
            }
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    } elseif ($action === 'delete_student') {
        try {
            $studentService = new StudentService($db);
            $res = $studentService->deleteStudent($studentId, (int)$_SESSION['user_id']);
            if ($res['success']) {
                setFlash('success', $res['message']);
            } else {
                setFlash('error', $res['message']);
            }
        } catch (Exception $e) {
            setFlash('error', 'Failed to delete student: ' . $e->getMessage());
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    } elseif ($action === 'bulk_delete') {
        $studentIds = $_POST['bulk_students'] ?? [];
        if (empty($studentIds)) {
            setFlash('error', 'No students selected for deletion.');
        } else {
            try {
                $studentService = new StudentService($db);
                $res = $studentService->deleteMultipleStudents($studentIds, (int)$_SESSION['user_id']);
                if ($res['success']) {
                    setFlash('success', $res['message']);
                } else {
                    setFlash('error', $res['message']);
                }
            } catch (Exception $e) {
                setFlash('error', 'Failed to delete students: ' . $e->getMessage());
            }
        }
        $redirectUrl = 'students.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: $redirectUrl");
        exit;
    }
}

$pageTitle = 'Student Roster';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Fetch filter options
$allSessions = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$departments = $db->query("SELECT * FROM departments ORDER BY dept_code")->fetchAll();

// Filter inputs
$filterSearch = trim($_GET['search'] ?? ($_GET['q'] ?? ''));
$filterSession = $_GET['session_id'] ?? 'all';
$filterBatch = $_GET['batch_id'] ?? '';
$filterDept = $_GET['dept_id'] ?? '';
$filterWilling = $_GET['willingness'] ?? '';
$filterStatus = $_GET['placement_status'] ?? '';
$filterCGPA = $_GET['cgpa_min'] ?? '';
$filterArrears = $_GET['arrears'] ?? '';
$filterYearTab = $_GET['year_tab'] ?? 'all';

// Construct Base Roster Query
$whereClause = ["s.student_id IS NOT NULL"];
$queryParams = [];

// Filter: Academic Session
if ($filterSession !== 'all' && is_numeric($filterSession)) {
    $sessId = (int)$filterSession;
    $stmtFilter = $db->prepare("SELECT session_name, start_year, end_year FROM academic_sessions WHERE session_id = ?");
    $stmtFilter->execute([$sessId]);
    $filterSessRow = $stmtFilter->fetch();
    if ($filterSessRow && !empty($filterSessRow['start_year'])) {
        $sessStartYear = (int)$filterSessRow['start_year'];
        $sessEndYear = (int)$filterSessRow['end_year'];
    }
    if (empty($filterBatch) && empty($filterSearch)) {
        $whereClause[] = "(
            s.batch_id IN (SELECT batch_id FROM batch_academic_sessions WHERE session_id = ?)
            OR s.batch_id IN (
                SELECT b.batch_id FROM batches b 
                JOIN academic_sessions sess ON sess.session_id = ?
                WHERE b.admission_year <= sess.start_year AND b.graduation_year >= sess.end_year
            )
            OR s.batch_id IS NULL
        )";
        $queryParams[] = $sessId;
        $queryParams[] = $sessId;
    }
}

// Filter: Keyword Search
if (!empty($filterSearch)) {
    $searchTerms = preg_split('/\s+/', $filterSearch, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($searchTerms as $term) {
        $termParam = '%' . $term . '%';
        $whereClause[] = "(
            s.registration_number LIKE ? 
            OR s.student_name LIKE ? 
            OR s.email LIKE ? 
            OR s.college_email LIKE ? 
            OR s.mobile_number LIKE ? 
            OR d.dept_code LIKE ? 
            OR d.dept_name LIKE ? 
            OR b.batch_name LIKE ? 
            OR sec.section_name LIKE ?
        )";
        for ($k = 0; $k < 9; $k++) {
            $queryParams[] = $termParam;
        }
    }
}

if (!empty($filterBatch)) {
    $whereClause[] = "s.batch_id = ?";
    $queryParams[] = $filterBatch;
}
if (!empty($filterDept)) {
    $whereClause[] = "s.dept_id = ?";
    $queryParams[] = $filterDept;
}
if ($filterWilling !== '') {
    $whereClause[] = "s.placement_willingness = ?";
    $queryParams[] = $filterWilling;
}
if (!empty($filterStatus)) {
    $whereClause[] = "s.placement_status = ?";
    $queryParams[] = $filterStatus;
}
if (is_numeric($filterCGPA)) {
    $whereClause[] = "sa.current_cgpa >= ?";
    $queryParams[] = (float)$filterCGPA;
}
if ($filterArrears === 'no') {
    $whereClause[] = "sa.standing_arrears = 0";
} elseif ($filterArrears === 'yes') {
    $whereClause[] = "sa.standing_arrears > 0";
}

$whereSql = implode(' AND ', $whereClause);

ensureCertificationsTable($db);

// Query all matching students
$stmtRoster = $db->prepare("
    SELECT 
        s.*, 
        COALESCE(d.dept_code, 'N/A') AS dept_code,
        COALESCE(d.dept_name, 'N/A') AS dept_name,
        COALESCE(b.batch_name, 'N/A') AS batch_name, 
        b.admission_year,
        b.graduation_year,
        b.programme_duration,
        b.current_year_of_study,
        u.status AS login_status, 
        sa.current_cgpa, 
        sa.standing_arrears, 
        sa.history_of_arrears,
        sec.section_name,
        sp.resume_path,
        COALESCE(sc_counts.cert_count, 0) AS cert_count
    FROM students s
    LEFT JOIN departments d ON s.dept_id = d.dept_id
    LEFT JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS cert_count 
        FROM student_certifications 
        GROUP BY student_id
    ) sc_counts ON s.student_id = sc_counts.student_id
    WHERE $whereSql
    ORDER BY s.registration_number ASC
");
$stmtRoster->execute($queryParams);
$allQueriedStudents = $stmtRoster->fetchAll(PDO::FETCH_ASSOC);

/**
 * Dynamic cohort and study status resolver
 */
function resolveStudentCohort($student, $sessStart, $sessEnd) {
    $adm = (int)($student['admission_year'] ?? 0);
    $grad = (int)($student['graduation_year'] ?? 0);
    $dur = (int)($student['programme_duration'] ?: 4);

    if (($adm <= 0 || $grad <= 0) && !empty($student['batch_name'])) {
        if (preg_match('/(\d{4})\s*[-–—\/]\s*(\d{4})/u', $student['batch_name'], $m)) {
            $adm = (int)$m[1];
            $grad = (int)$m[2];
        }
    }

    if ($sessStart > 0 && $sessEnd > 0 && $adm > 0 && $grad > 0) {
        if ($adm > $sessStart) {
            return [
                'tab_key' => 'upcoming',
                'year_title' => 'Upcoming',
                'year_short' => 'Upcoming',
                'semester' => 'Adm ' . $adm,
                'badge' => '<span class="badge bg-light text-muted border py-1 px-2" style="font-size:0.75rem;"><i class="fa-regular fa-clock me-1"></i> Upcoming</span>',
                'pill_class' => 'bg-secondary'
            ];
        } elseif ($grad <= $sessStart) {
            return [
                'tab_key' => 'graduated',
                'year_title' => 'Graduated',
                'year_short' => 'Graduated',
                'semester' => 'Alumni (' . $grad . ')',
                'badge' => '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle py-1 px-2" style="font-size:0.75rem;"><i class="fa-solid fa-user-graduate me-1"></i> Graduated</span>',
                'pill_class' => 'bg-secondary'
            ];
        } else {
            $yearNum = ($sessStart - $adm) + (4 - $dur) + 1;
            if ($grad === $sessEnd || $yearNum >= 4) {
                return [
                    'tab_key' => 'final_year',
                    'year_title' => 'Final Year',
                    'year_short' => '4th Year',
                    'semester' => 'Sem 7 & 8',
                    'badge' => '<span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-2 fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-award me-1"></i> Final Year</span>',
                    'pill_class' => 'bg-success'
                ];
            } elseif ($yearNum === 3) {
                return [
                    'tab_key' => 'third_year',
                    'year_title' => 'Third Year',
                    'year_short' => '3rd Year',
                    'semester' => 'Sem 5 & 6',
                    'badge' => '<span class="badge bg-warning-subtle text-warning border border-warning-subtle py-1 px-2 fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-book-open me-1"></i> 3rd Year</span>',
                    'pill_class' => 'bg-warning'
                ];
            } elseif ($yearNum === 2) {
                return [
                    'tab_key' => 'second_year',
                    'year_title' => 'Second Year',
                    'year_short' => '2nd Year',
                    'semester' => 'Sem 3 & 4',
                    'badge' => '<span class="badge bg-info-subtle text-info border border-info-subtle py-1 px-2 fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-graduation-cap me-1"></i> 2nd Year</span>',
                    'pill_class' => 'bg-info'
                ];
            } else {
                return [
                    'tab_key' => 'first_year',
                    'year_title' => 'First Year',
                    'year_short' => '1st Year',
                    'semester' => 'Sem 1 & 2',
                    'badge' => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle py-1 px-2 fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-user me-1"></i> 1st Year</span>',
                    'pill_class' => 'bg-primary'
                ];
            }
        }
    }

    $fallback = !empty($student['current_year_of_study']) ? $student['current_year_of_study'] : 'General';
    return [
        'tab_key' => 'general',
        'year_title' => $fallback,
        'year_short' => $fallback,
        'semester' => '-',
        'badge' => '<span class="badge bg-light text-dark border py-1 px-2" style="font-size:0.75rem;">' . esc($fallback) . '</span>',
        'pill_class' => 'bg-primary'
    ];
}

// Compute cohort info & tab counts
$tabCounts = [
    'all' => count($allQueriedStudents),
    'final_year' => 0,
    'third_year' => 0,
    'second_year' => 0,
    'first_year' => 0,
    'graduated' => 0
];

$kpiPlaced = 0;
$kpiWilling = 0;
$kpiClearArrears = 0;

$studentsWithCohort = [];
foreach ($allQueriedStudents as $s) {
    $cohort = resolveStudentCohort($s, $sessStartYear, $sessEndYear);
    $s['cohort'] = $cohort;
    $studentsWithCohort[] = $s;

    if (isset($tabCounts[$cohort['tab_key']])) {
        $tabCounts[$cohort['tab_key']]++;
    }

    if (($s['placement_status'] ?? '') === 'Placed') $kpiPlaced++;
    if (($s['placement_willingness'] ?? '') === 'Yes') $kpiWilling++;
    if ((int)($s['standing_arrears'] ?? 0) === 0) $kpiClearArrears++;
}

// Filter students for the active Year-of-Study tab
$displayedStudents = [];
foreach ($studentsWithCohort as $s) {
    if ($filterYearTab === 'all' || $s['cohort']['tab_key'] === $filterYearTab) {
        $displayedStudents[] = $s;
    }
}

// Helper to build URL with persistent query parameters
function buildFilterUrl($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return 'students.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$hasActiveFilters = !empty($filterSearch) || $filterSession !== 'all' || !empty($filterBatch) || !empty($filterDept) || $filterWilling !== '' || !empty($filterStatus) || !empty($filterCGPA) || !empty($filterArrears) || $filterYearTab !== 'all';
?>

<style>
/* Senior Compact UI Styles */
.compact-kpi-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.compact-kpi-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.compact-kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.compact-kpi-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    line-height: 1;
    margin-bottom: 3px;
}
.compact-kpi-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1;
}

/* Compact Filter Bar */
.compact-filter-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.compact-filter-box .form-label-micro {
    font-size: 0.72rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 3px;
    display: block;
}
.compact-filter-box .form-control-sm, 
.compact-filter-box .form-select-sm {
    font-size: 0.82rem;
    padding: 4px 8px;
    border-radius: 6px;
    border-color: #cbd5e1;
}
.compact-filter-box .form-control-sm:focus, 
.compact-filter-box .form-select-sm:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}

/* Segmented Tabs (Cohort Study Year) */
.segmented-cohort-bar {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    gap: 2px;
    border: 1px solid #e2e8f0;
    max-width: 100%;
    overflow-x: auto;
}
.segmented-tab {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.segmented-tab:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.6);
}
.segmented-tab.active {
    background: #ffffff;
    color: #0b3d91;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.segmented-badge {
    font-size: 0.7rem;
    padding: 1px 6px;
    border-radius: 10px;
    background: #e2e8f0;
    color: #475569;
}
.segmented-tab.active .segmented-badge {
    background: #e0e7ff;
    color: #1e40af;
}

/* High-Density Data Table */
.compact-table thead th {
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.compact-table tbody td {
    padding: 10px 14px;
    font-size: 0.84rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}
.compact-table tbody tr:hover {
    background-color: #fbfcfe;
}
.avatar-chip {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: linear-gradient(135deg, #1e3a8a, #3b82f6);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.status-indicator-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 4px;
}
.status-dot-active { background-color: #10b981; }
.status-dot-disabled { background-color: #ef4444; }
.status-dot-pending { background-color: #94a3b8; }
</style>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container py-3">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo is_array($error) ? esc(implode(', ', $error)) : esc($error); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <i class="fa-solid fa-circle-check me-1"></i> <?php echo is_array($success) ? esc(implode(', ', $success)) : esc($success); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- 1. Header Toolbar -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex align-items-center gap-2">
                <h4 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-users text-primary me-2"></i> Student Roster
                </h4>
                <span class="badge bg-light text-primary border px-2 py-1 small fw-semibold">
                    <i class="fa-solid fa-calendar-check me-1"></i> <?php echo esc($activeSessionName); ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="student-import.php" class="btn btn-sm btn-outline-success">
                    <i class="fa-solid fa-file-excel me-1"></i> Import (Excel / XML)
                </a>
                <a href="student-add.php" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-user-plus me-1"></i> Add Student
                </a>
            </div>
        </div>

        <!-- 2. Compact Inline KPI Strip (Equal-Width 4-Card Grid) -->
        <div class="compact-kpi-bar">
            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Total Enrolled</div>
                    <div class="compact-kpi-value text-primary"><?php echo number_format($tabCounts['all']); ?></div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Final Year (Placement Target)</div>
                    <div class="compact-kpi-value text-success"><?php echo number_format($tabCounts['final_year']); ?></div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">Placed Students</div>
                    <div class="compact-kpi-value text-info"><?php echo number_format($kpiPlaced); ?></div>
                </div>
            </div>

            <div class="compact-kpi-item">
                <div class="compact-kpi-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-shield-check"></i>
                </div>
                <div>
                    <div class="compact-kpi-label">0 Arrears (Drive Ready)</div>
                    <div class="compact-kpi-value text-dark"><?php echo number_format($kpiClearArrears); ?></div>
                </div>
            </div>
        </div>

        <!-- 3. Consolidated Compact Filter Toolbar -->
        <div class="compact-filter-box">
            <form action="students.php" method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="year_tab" value="<?php echo esc($filterYearTab); ?>">

                <div class="col-xl-3 col-lg-3 col-md-6 col-12">
                    <label class="form-label-micro"><i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Search Keyword</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Name, Reg No, Email..." value="<?php echo esc($filterSearch); ?>">
                </div>

                <div class="col-xl-2 col-lg-2 col-md-3 col-6">
                    <label class="form-label-micro">Academic Session</label>
                    <select name="session_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $filterSession === 'all' ? 'selected' : ''; ?>>All Sessions</option>
                        <?php foreach ($allSessions as $s): ?>
                            <option value="<?php echo $s['session_id']; ?>" <?php echo (string)$filterSession === (string)$s['session_id'] ? 'selected' : ''; ?>>
                                <?php echo esc($s['session_name']); ?><?php echo !empty($s['is_active']) ? ' (Active)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-2 col-lg-2 col-md-3 col-6">
                    <label class="form-label-micro">Target Batch</label>
                    <select name="batch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $b): ?>
                            <option value="<?php echo $b['batch_id']; ?>" <?php echo $filterBatch == $b['batch_id'] ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-2 col-lg-2 col-md-4 col-6">
                    <label class="form-label-micro">Department</label>
                    <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['dept_id']; ?>" <?php echo $filterDept == $d['dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-1 col-md-4 col-6">
                    <label class="form-label-micro">Placement</label>
                    <select name="placement_status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="Placed" <?php echo $filterStatus === 'Placed' ? 'selected' : ''; ?>>Placed</option>
                        <option value="Unplaced" <?php echo $filterStatus === 'Unplaced' ? 'selected' : ''; ?>>Unplaced</option>
                        <option value="Higher Studies" <?php echo $filterStatus === 'Higher Studies' ? 'selected' : ''; ?>>Higher Studies</option>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-1 col-md-2 col-6">
                    <label class="form-label-micro">Willing</label>
                    <select name="willingness" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="Yes" <?php echo $filterWilling === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                        <option value="No" <?php echo $filterWilling === 'No' ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-1 col-md-2 col-6 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100 py-1" title="Apply Filters">
                        <i class="fa-solid fa-filter"></i>
                    </button>
                    <?php if ($hasActiveFilters): ?>
                        <a href="students.php" class="btn btn-sm btn-light border py-1" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left text-danger"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 4. Segmented Cohort Study Year Tabs -->
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div class="segmented-cohort-bar">
                <a href="<?php echo buildFilterUrl(['year_tab' => 'all']); ?>" class="segmented-tab <?php echo $filterYearTab === 'all' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i> All Students <span class="segmented-badge"><?php echo $tabCounts['all']; ?></span>
                </a>
                <a href="<?php echo buildFilterUrl(['year_tab' => 'final_year']); ?>" class="segmented-tab <?php echo $filterYearTab === 'final_year' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-award text-success"></i> Final Year (4th) <span class="segmented-badge"><?php echo $tabCounts['final_year']; ?></span>
                </a>
                <a href="<?php echo buildFilterUrl(['year_tab' => 'third_year']); ?>" class="segmented-tab <?php echo $filterYearTab === 'third_year' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-open text-warning"></i> 3rd Year <span class="segmented-badge"><?php echo $tabCounts['third_year']; ?></span>
                </a>
                <a href="<?php echo buildFilterUrl(['year_tab' => 'second_year']); ?>" class="segmented-tab <?php echo $filterYearTab === 'second_year' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-graduation-cap text-info"></i> 2nd Year <span class="segmented-badge"><?php echo $tabCounts['second_year']; ?></span>
                </a>
                <a href="<?php echo buildFilterUrl(['year_tab' => 'first_year']); ?>" class="segmented-tab <?php echo $filterYearTab === 'first_year' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user text-primary"></i> 1st Year <span class="segmented-badge"><?php echo $tabCounts['first_year']; ?></span>
                </a>
                <?php if ($tabCounts['graduated'] > 0): ?>
                    <a href="<?php echo buildFilterUrl(['year_tab' => 'graduated']); ?>" class="segmented-tab <?php echo $filterYearTab === 'graduated' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-user-graduate text-secondary"></i> Alumni <span class="segmented-badge"><?php echo $tabCounts['graduated']; ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Bulk Operation Micro Toolbar -->
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-sm btn-outline-success py-1 px-2" style="font-size:0.78rem;" onclick="executeBulk('active')">
                    <i class="fa-solid fa-user-check me-1"></i> Enable
                </button>
                <button type="button" class="btn btn-sm btn-outline-warning py-1 px-2" style="font-size:0.78rem;" onclick="executeBulk('inactive')">
                    <i class="fa-solid fa-user-xmark me-1"></i> Disable
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" style="font-size:0.78rem;" onclick="executeBulkDelete()">
                    <i class="fa-solid fa-trash-can me-1"></i> Delete
                </button>
            </div>
        </div>

        <!-- 5. High-Density Compact Data Table -->
        <form action="students.php" method="POST" id="bulkForm">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" id="bulk_action" value="bulk_status">
            <input type="hidden" name="bulk_status" id="bulk_status_val" value="">

            <div class="mamcet-card border-0 shadow-sm" style="overflow: hidden; border-radius: 10px;">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 compact-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th width="24" class="text-center no-sort">
                                    <input type="checkbox" id="selectAll" class="form-check-input mt-0">
                                </th>
                                <th>Student Profile</th>
                                <th>Dept / Sec</th>
                                <th>Year of Study</th>
                                <th>Academics</th>
                                <th>Placement</th>
                                <th>Account</th>
                                <th class="text-end no-sort" width="80">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($displayedStudents as $s): 
                                $cohort = $s['cohort'];
                                $initials = strtoupper(substr($s['student_name'], 0, 2));
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="bulk_students[]" value="<?php echo $s['student_id']; ?>" class="select-item form-check-input mt-0">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-chip">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <a href="student-view.php?student_id=<?php echo $s['student_id']; ?>" class="text-dark fw-bold text-decoration-none">
                                                    <?php echo esc($s['student_name']); ?>
                                                </a>
                                                <div class="d-flex align-items-center gap-2 mt-0">
                                                    <span class="badge bg-light text-primary border px-1" style="font-size:0.72rem;">
                                                        <?php echo esc($s['registration_number']); ?>
                                                    </span>
                                                    <span class="text-muted" style="font-size:0.75rem;">
                                                        <?php echo esc($s['email'] ?: ($s['college_email'] ?: '-')); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold" style="font-size:0.75rem;">
                                            <?php echo esc($s['dept_code']); ?>
                                        </span>
                                        <?php if (!empty($s['section_name'])): ?>
                                            <span class="badge bg-light text-dark border ms-1" style="font-size:0.72rem;">
                                                <?php echo esc($s['section_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo $cohort['badge']; ?></div>
                                        <small class="text-muted d-block" style="font-size:0.73rem;">
                                            <?php echo esc($s['batch_name']); ?> • <?php echo esc($cohort['semester']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size:0.85rem;">
                                            <?php echo esc($s['current_cgpa'] !== null ? number_format((float)$s['current_cgpa'], 2) : 'N/A'); ?> 
                                            <span class="text-muted fw-normal" style="font-size:0.7rem;">CGPA</span>
                                        </div>
                                        <div>
                                            <?php if ((int)($s['standing_arrears'] ?? 0) > 0): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.68rem;">
                                                    <?php echo (int)$s['standing_arrears']; ?> Arrears
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.68rem;">
                                                    0 Arrears
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1">
                                            <a href="student-view.php?student_id=<?php echo $s['student_id']; ?>#certificates" class="badge text-decoration-none" style="<?php echo ((int)$s['cert_count'] > 0) ? 'background:#f0fdfa; color:#0d9488; border:1px solid #ccfbf1;' : 'background:#f8fafc; color:#64748b; border:1px solid #e2e8f0;'; ?> font-size:0.68rem;" title="View Uploaded Certificates">
                                                <i class="fa-solid fa-certificate me-1"></i> <?php echo (int)$s['cert_count']; ?> Cert<?php echo (int)$s['cert_count'] === 1 ? '' : 's'; ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $s['placement_status'] === 'Placed' ? 'bg-success' : ($s['placement_status'] === 'Unplaced' ? 'bg-warning text-dark' : 'bg-info'); ?> px-2 py-0" style="font-size:0.74rem;">
                                            <?php echo esc($s['placement_status']); ?>
                                        </span>
                                        <div class="text-muted" style="font-size:0.72rem;">
                                            Willing: <strong class="<?php echo $s['placement_willingness'] === 'Yes' ? 'text-success' : 'text-secondary'; ?>"><?php echo $s['placement_willingness']; ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($s['user_id'] === null): ?>
                                            <span class="text-muted" style="font-size:0.75rem;"><span class="status-indicator-dot status-dot-pending"></span>Pending</span>
                                        <?php elseif ($s['login_status'] === 'active'): ?>
                                            <span class="text-success fw-semibold" style="font-size:0.75rem;"><span class="status-indicator-dot status-dot-active"></span>Active</span>
                                        <?php else: ?>
                                            <span class="text-danger fw-semibold" style="font-size:0.75rem;"><span class="status-indicator-dot status-dot-disabled"></span>Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border py-0 px-2 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size:0.78rem;">
                                                Action
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:0.82rem;">
                                                <li><a class="dropdown-item py-1" href="student-view.php?student_id=<?php echo $s['student_id']; ?>"><i class="fa-solid fa-id-badge text-primary me-2"></i> View Profile</a></li>
                                                <li><a class="dropdown-item py-1" href="student-view.php?student_id=<?php echo $s['student_id']; ?>#certificates"><i class="fa-solid fa-certificate text-success me-2"></i> Certificates (<?php echo (int)$s['cert_count']; ?>)</a></li>
                                                <?php if ($s['user_id'] !== null): ?>
                                                    <li>
                                                        <button class="dropdown-item py-1" type="button" onclick="toggleStudentLogin(<?php echo $s['student_id']; ?>, '<?php echo $s['login_status'] === 'active' ? 'inactive' : 'active'; ?>')">
                                                            <i class="fa-solid <?php echo $s['login_status'] === 'active' ? 'fa-user-slash text-warning' : 'fa-user-check text-success'; ?> me-2"></i> 
                                                            <?php echo $s['login_status'] === 'active' ? 'Disable Login' : 'Enable Login'; ?>
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item py-1" type="button" onclick="resetPassword(<?php echo $s['student_id']; ?>)">
                                                            <i class="fa-solid fa-key text-danger me-2"></i> Reset Password
                                                        </button>
                                                    </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li>
                                                    <button class="dropdown-item py-1" type="button" onclick="openNoteModal(<?php echo $s['student_id']; ?>, '<?php echo esc(addslashes($s['student_name'])); ?>')">
                                                        <i class="fa-solid fa-comment-medical text-muted me-2"></i> Add Note
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li>
                                                    <button class="dropdown-item py-1 text-danger" type="button" onclick="confirmDeleteStudent(<?php echo $s['student_id']; ?>, '<?php echo esc(addslashes($s['student_name'])); ?>', '<?php echo esc(addslashes($s['registration_number'])); ?>')">
                                                        <i class="fa-solid fa-trash-can text-danger me-2"></i> Delete Student
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Hidden action form -->
<form id="actionForm" action="students.php" method="POST" class="d-none">
    <?php csrfInput(); ?>
    <input type="hidden" name="action" id="action_input" value="">
    <input type="hidden" name="student_id" id="student_id_input" value="0">
    <input type="hidden" name="status" id="status_input" value="">
</form>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="students.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="student_id" id="note_student_id" value="0">
                
                <div class="modal-header py-2 px-3">
                    <h6 class="modal-title fw-bold" id="noteModalLabel"><i class="fa-solid fa-comment-dots text-primary me-1"></i> Add Placement Note</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <p class="text-muted small mb-2">Note for student <strong id="note_student_name"></strong> (Placement Officer internal remarks only):</p>
                    <textarea name="note_text" class="form-control form-control-sm" rows="3" placeholder="Enter comments on performance, training, etc." required></textarea>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-primary">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    $('#studentsTable').DataTable({
        "columnDefs": [
            { "orderable": false, "targets": 'no-sort' }
        ],
        "order": [[1, "asc"]],
        "pageLength": 25,
        "language": {
            "emptyTable": "No students found in this view. Try selecting 'All Students' or resetting filters."
        }
    });

    $('#selectAll').on('change', function() {
        $('.select-item').prop('checked', this.checked);
    });
});

function toggleStudentLogin(studentId, newStatus) {
    Swal.fire({
        title: 'Modify Account Access?',
        text: 'This will change student account login privileges.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, modify!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#action_input').val('toggle_login');
            $('#student_id_input').val(studentId);
            $('#status_input').val(newStatus);
            $('#actionForm').submit();
        }
    });
}

function resetPassword(studentId) {
    Swal.fire({
        title: 'Reset student password?',
        text: 'Password will be reset to temporary format (MAMCET@last4digits).',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, reset!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#action_input').val('reset_password');
            $('#student_id_input').val(studentId);
            $('#actionForm').submit();
        }
    });
}

function openNoteModal(studentId, studentName) {
    $('#note_student_id').val(studentId);
    $('#note_student_name').text(studentName);
    var modal = new bootstrap.Modal(document.getElementById('noteModal'));
    modal.show();
}

function executeBulk(status) {
    const checked = $('.select-item:checked').length;
    if (checked === 0) {
        Swal.fire('No Selection', 'Please select at least one student checkbox.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Bulk Modify Privilege?',
        text: 'Change login authorization for ' + checked + ' selected account(s)?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: 'Yes, execute!'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#bulk_action').val('bulk_status');
            $('#bulk_status_val').val(status);
            $('#bulkForm').submit();
        }
    });
}

function confirmDeleteStudent(studentId, studentName, regNo) {
    Swal.fire({
        title: 'Delete Student Record?',
        html: `Are you sure you want to permanently delete <strong>${studentName}</strong> (${regNo})?<br><br><div class="alert alert-danger text-start p-2 mb-0" style="font-size:0.78rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Warning:</strong> All academic marks, resumes, and accounts will be permanently destroyed.</div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Permanently Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#action_input').val('delete_student');
            $('#student_id_input').val(studentId);
            $('#actionForm').submit();
        }
    });
}

function executeBulkDelete() {
    const checked = $('.select-item:checked').length;
    if (checked === 0) {
        Swal.fire('No Selection', 'Please select at least one student checkbox.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Delete Selected Students?',
        html: `Are you sure you want to permanently delete <strong>${checked} selected student(s)</strong>?<br><br><span class="text-danger small fw-bold">All associated records, files, and login accounts will be destroyed. This cannot be undone.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Delete Selected',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#bulk_action').val('bulk_delete');
            $('#bulkForm').submit();
        }
    });
}
</script>
