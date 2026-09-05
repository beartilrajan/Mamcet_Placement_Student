<?php
// MAMCET Placement & Learning Portal - Global Student Search & Quick Detail API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');

// Restrict to Super Admin and Placement Officer
if (!isLoggedIn() || !in_array((int)$_SESSION['role_id'], [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Administrator privileges required.'
    ]);
    exit;
}

$action = $_GET['action'] ?? 'search';

try {
    $db = Database::getInstance()->getConnection();

    if ($action === 'search') {
        $query = trim($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 25) : 10;

        if (mb_strlen($query) < 1) {
            echo json_encode([
                'success' => true,
                'query' => $query,
                'total' => 0,
                'results' => []
            ]);
            exit;
        }

        $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($terms)) {
            echo json_encode([
                'success' => true,
                'query' => $query,
                'total' => 0,
                'results' => []
            ]);
            exit;
        }

        $whereParts = [];
        $params = [];

        foreach ($terms as $idx => $term) {
            $termParam = '%' . $term . '%';
            $whereParts[] = "(
                s.registration_number LIKE ? 
                OR s.student_name LIKE ? 
                OR s.college_email LIKE ? 
                OR s.email LIKE ? 
                OR s.mobile_number LIKE ?
                OR d.dept_code LIKE ?
                OR d.dept_name LIKE ?
                OR b.batch_name LIKE ?
                OR sec.section_name LIKE ?
                OR s.placement_status LIKE ?
                OR s.placement_willingness LIKE ?
            )";
            for ($i = 0; $i < 11; $i++) {
                $params[] = $termParam;
            }
        }

        $whereClauseSql = implode(' AND ', $whereParts);
        $fullQueryParam = '%' . $query . '%';
        $prefixQueryParam = $query . '%';
        $firstTerm = $terms[0];

        // High-relevance search across registration number, name, email, dept, batch, mobile
        $sql = "
            SELECT 
                s.student_id,
                s.registration_number,
                s.student_name,
                s.email,
                s.college_email,
                s.mobile_number,
                s.placement_status,
                s.placement_willingness,
                d.dept_code,
                d.dept_name,
                b.batch_name,
                sec.section_name,
                sa.current_cgpa,
                sa.standing_arrears,
                sa.history_of_arrears,
                (SELECT COUNT(*) FROM student_placements sp WHERE sp.student_id = s.student_id) AS placement_count,
                (SELECT MAX(sp2.package_lpa) FROM student_placements sp2 WHERE sp2.student_id = s.student_id) AS highest_package_lpa
            FROM students s
            LEFT JOIN departments d ON s.dept_id = d.dept_id
            LEFT JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN sections sec ON s.section_id = sec.section_id
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
            WHERE $whereClauseSql
            ORDER BY 
                CASE 
                    WHEN s.registration_number = ? THEN 1
                    WHEN s.registration_number LIKE ? THEN 2
                    WHEN s.student_name LIKE ? THEN 3
                    WHEN s.student_name LIKE ? THEN 4
                    WHEN d.dept_code = ? THEN 5
                    ELSE 6
                END,
                s.student_name ASC
            LIMIT " . (int)$limit;

        $orderParams = [
            $query,
            $prefixQueryParam,
            $prefixQueryParam,
            $fullQueryParam,
            $firstTerm
        ];

        $execParams = array_merge($params, $orderParams);
        $stmt = $db->prepare($sql);
        $stmt->execute($execParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sanitize & format result payload
        $formatted = array_map(function($row) {
            return [
                'student_id' => (int)$row['student_id'],
                'registration_number' => $row['registration_number'],
                'student_name' => $row['student_name'],
                'email' => $row['email'] ?: ($row['college_email'] ?: ''),
                'college_email' => $row['college_email'] ?: '',
                'mobile_number' => $row['mobile_number'] ?: '',
                'dept_code' => $row['dept_code'] ?: 'N/A',
                'dept_name' => $row['dept_name'] ?: 'N/A',
                'batch_name' => $row['batch_name'] ?: 'N/A',
                'section_name' => $row['section_name'] ?: '',
                'current_cgpa' => $row['current_cgpa'] !== null ? number_format((float)$row['current_cgpa'], 2) : 'N/A',
                'standing_arrears' => (int)($row['standing_arrears'] ?? 0),
                'history_of_arrears' => (int)($row['history_of_arrears'] ?? 0),
                'placement_status' => $row['placement_status'] ?: 'Unplaced',
                'placement_willingness' => $row['placement_willingness'] ?: 'Yes',
                'placement_count' => (int)$row['placement_count'],
                'highest_package_lpa' => $row['highest_package_lpa'] !== null ? number_format((float)$row['highest_package_lpa'], 2) : null
            ];
        }, $results);

        echo json_encode([
            'success' => true,
            'query' => $query,
            'total' => count($formatted),
            'results' => $formatted
        ]);
        exit;
    }

    if ($action === 'get_student_detail') {
        $studentId = (int)($_GET['student_id'] ?? 0);

        if ($studentId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid student_id is required.']);
            exit;
        }

        // 1. Fetch main student and academic record
        $stmt = $db->prepare("
            SELECT 
                s.*,
                d.dept_name, d.dept_code,
                b.batch_name,
                pr.programme_name,
                sec.section_name,
                sa.tenth_percentage, sa.twelfth_percentage, sa.diploma_percentage, 
                sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears,
                sa.sem1_gpa, sa.sem2_gpa, sa.sem3_gpa, sa.sem4_gpa, sa.sem5_gpa, sa.sem6_gpa, sa.sem7_gpa, sa.sem8_gpa,
                sp.linkedin_url, sp.github_url, sp.portfolio_url, sp.skills, sp.projects, sp.internships, 
                sp.certifications, sp.address, sp.city, sp.district, sp.resume_path, 
                sp.career_objective, sp.preferred_job_role, sp.preferred_location
            FROM students s
            LEFT JOIN departments d ON s.dept_id = d.dept_id
            LEFT JOIN batches b ON s.batch_id = b.batch_id
            LEFT JOIN programmes pr ON b.programme_id = pr.programme_id
            LEFT JOIN sections sec ON s.section_id = sec.section_id
            LEFT JOIN student_academics sa ON s.student_id = sa.student_id
            LEFT JOIN student_profiles sp ON s.student_id = sp.student_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        // 2. Fetch placement offers
        $stmtPl = $db->prepare("
            SELECT placement_id, company_name, package_lpa, placed_date, offer_letter_path, notes, created_at
            FROM student_placements
            WHERE student_id = ?
            ORDER BY placed_date DESC, created_at DESC
        ");
        $stmtPl->execute([$studentId]);
        $placements = $stmtPl->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch count of officer notes
        $stmtNotes = $db->prepare("SELECT COUNT(*) FROM officer_notes WHERE student_id = ?");
        $stmtNotes->execute([$studentId]);
        $notesCount = (int)$stmtNotes->fetchColumn();

        // 4. Calculate profile completion
        $profileCompletion = calculateProfileCompletion($db, $studentId);

        echo json_encode([
            'success' => true,
            'student' => [
                'student_id' => (int)$student['student_id'],
                'registration_number' => $student['registration_number'],
                'student_name' => $student['student_name'],
                'dob' => $student['dob'] ? date('M d, Y', strtotime($student['dob'])) : null,
                'gender' => $student['gender'] ?: null,
                'blood_group' => $student['blood_group'] ?: null,
                'mobile_number' => $student['mobile_number'] ?: '',
                'alt_mobile_number' => $student['alt_mobile_number'] ?: '',
                'parent_mobile' => $student['parent_mobile'] ?: '',
                'email' => $student['email'] ?: '',
                'college_email' => $student['college_email'] ?: '',
                'dept_id' => (int)$student['dept_id'],
                'dept_code' => $student['dept_code'] ?: 'N/A',
                'dept_name' => $student['dept_name'] ?: 'N/A',
                'batch_name' => $student['batch_name'] ?: 'N/A',
                'programme_name' => $student['programme_name'] ?: 'B.Tech / B.E.',
                'section_name' => $student['section_name'] ?: '',
                'year_of_study' => $student['year_of_study'] ?: 'Final Year',
                'placement_status' => $student['placement_status'] ?: 'Unplaced',
                'placement_willingness' => $student['placement_willingness'] ?: 'Yes',
                'hosteller_status' => $student['hosteller_status'] ?: null,
                
                // Academics
                'tenth_percentage' => $student['tenth_percentage'] !== null ? number_format((float)$student['tenth_percentage'], 2) : null,
                'twelfth_percentage' => $student['twelfth_percentage'] !== null ? number_format((float)$student['twelfth_percentage'], 2) : null,
                'diploma_percentage' => $student['diploma_percentage'] !== null ? number_format((float)$student['diploma_percentage'], 2) : null,
                'current_cgpa' => $student['current_cgpa'] !== null ? number_format((float)$student['current_cgpa'], 2) : 'N/A',
                'standing_arrears' => (int)($student['standing_arrears'] ?? 0),
                'history_of_arrears' => (int)($student['history_of_arrears'] ?? 0),
                'gpa_records' => [
                    'sem1' => $student['sem1_gpa'],
                    'sem2' => $student['sem2_gpa'],
                    'sem3' => $student['sem3_gpa'],
                    'sem4' => $student['sem4_gpa'],
                    'sem5' => $student['sem5_gpa'],
                    'sem6' => $student['sem6_gpa'],
                    'sem7' => $student['sem7_gpa'],
                    'sem8' => $student['sem8_gpa'],
                ],

                // Portfolio / Profile
                'skills' => $student['skills'] ?: '',
                'projects' => $student['projects'] ?: '',
                'internships' => $student['internships'] ?: '',
                'certifications' => $student['certifications'] ?: '',
                'linkedin_url' => $student['linkedin_url'] ?: '',
                'github_url' => $student['github_url'] ?: '',
                'portfolio_url' => $student['portfolio_url'] ?: '',
                'resume_path' => $student['resume_path'] ?: '',
                'has_resume' => !empty($student['resume_path']),
                'career_objective' => $student['career_objective'] ?: '',
                'preferred_job_role' => $student['preferred_job_role'] ?: '',
                'preferred_location' => $student['preferred_location'] ?: '',
                'city' => $student['city'] ?: '',
                'district' => $student['district'] ?: '',
                
                // Extra metrics
                'profile_completion' => $profileCompletion,
                'notes_count' => $notesCount,
                'placements' => array_map(function($pl) {
                    return [
                        'placement_id' => (int)$pl['placement_id'],
                        'company_name' => $pl['company_name'],
                        'package_lpa' => number_format((float)$pl['package_lpa'], 2),
                        'placed_date' => $pl['placed_date'] ? date('M d, Y', strtotime($pl['placed_date'])) : 'N/A',
                        'offer_letter_path' => $pl['offer_letter_path'] ?: null,
                        'notes' => $pl['notes'] ?: ''
                    ];
                }, $placements)
            ]
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Invalid action '$action'."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
