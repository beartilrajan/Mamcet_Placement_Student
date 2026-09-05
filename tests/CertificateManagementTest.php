<?php
// MAMCET Placement & Learning Portal - Automated Certificate Management Integration Test Suite

class CertificateManagementTest {
    private $passed = 0;
    private $failed = 0;
    private $projectRoot;

    public function __construct() {
        $this->projectRoot = dirname(__DIR__);
    }

    public function assert($condition, $testName, $detail = '') {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] $testName\n";
        } else {
            $this->failed++;
            echo "  [FAIL] $testName" . ($detail ? " - $detail" : "") . "\n";
        }
    }

    public function run() {
        echo "====================================================\n";
        echo " MAMCET Certificate Management Integration Test Suite\n";
        echo "====================================================\n\n";

        $this->setupTestEnvironment();
        $this->testSchemaAndHelperFunctions();
        $this->testStudentUploadValidation();
        $this->testStudentUploadSuccess();
        $this->testCertificateFileAccessControl();
        $this->testCertificateStatusUpdates();
        $this->testDashboardAndRosterCertificateCounts();
        $this->testCertificateDeletionAndCleanup();

        echo "\n====================================================\n";
        echo " Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "====================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function runPhpCode(string $code): array {
        $tempFile = tempnam(sys_get_temp_dir(), 'cert_test_') . '.php';
        // Prepend chdir to project root so relative paths resolve correctly
        $bootstrap = "chdir(" . var_export($this->projectRoot, true) . ");\n";
        file_put_contents($tempFile, "<?php\n" . $bootstrap . $code);
        $output = shell_exec("php " . escapeshellarg($tempFile) . " 2>&1");
        @unlink($tempFile);
        return [
            'raw' => $output ?? '',
            'json' => json_decode($output ?? '', true)
        ];
    }

    private function setupTestEnvironment() {
        echo "Setup: Preparing test users and database state...\n";
        require_once(__DIR__ . '/../config/database.php');
        require_once(__DIR__ . '/../config/constants.php');
        require_once(__DIR__ . '/../includes/functions.php');

        $db = Database::getInstance()->getConnection();
        ensureCertificationsTable($db);

        // Clean up test certificates from previous runs
        $db->exec("DELETE FROM student_certifications WHERE student_id IN (1, 2)");

        // Ensure test_student_1 user exists and is linked to student_id 1
        $u1 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();
        if (!$u1) {
            $hash = password_hash("password123", PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username, password_hash, role_id, status) VALUES (?, ?, ?, ?)")
               ->execute(["test_student_1", $hash, ROLE_STUDENT, "active"]);
            $u1 = $db->lastInsertId();
        }
        $db->prepare("UPDATE students SET user_id = ? WHERE student_id = 1")->execute([$u1]);

        // Ensure test_student_2 user exists and is linked to student_id 2
        $u2 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_2'")->fetchColumn();
        if (!$u2) {
            $hash = password_hash("password123", PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username, password_hash, role_id, status) VALUES (?, ?, ?, ?)")
               ->execute(["test_student_2", $hash, ROLE_STUDENT, "active"]);
            $u2 = $db->lastInsertId();
        }
        $db->prepare("UPDATE students SET user_id = ? WHERE student_id = 2")->execute([$u2]);

        echo "  Setup complete: Test student 1 (User ID: $u1, Student ID: 1), Test student 2 (User ID: $u2, Student ID: 2)\n\n";
    }

    private function testSchemaAndHelperFunctions() {
        echo "Test Suite 1: Schema Integrity & Helper Functions\n";

        $code = <<<'PHP'
require_once('config/database.php');
require_once('includes/functions.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$cols = $db->query("SHOW COLUMNS FROM student_certifications")->fetchAll(PDO::FETCH_COLUMN);
$hasFileName = in_array('file_name', $cols);
$hasFileSize = in_array('file_size', $cols);

$fnExists = function_exists('formatBytes');
$testZero = $fnExists ? formatBytes(0) : '';
$testBytes = $fnExists ? formatBytes(512) : '';
$testKb = $fnExists ? formatBytes(1024) : '';
$testMb = $fnExists ? formatBytes(2.5 * 1024 * 1024) : '';

echo json_encode([
    'success' => true,
    'has_file_name' => $hasFileName,
    'has_file_size' => $hasFileSize,
    'format_bytes_exists' => $fnExists,
    'format_bytes_zero' => $testZero,
    'format_bytes_512' => $testBytes,
    'format_bytes_1kb' => $testKb,
    'format_bytes_2_5mb' => $testMb
]);
PHP;

        $res = $this->runPhpCode($code);
        $this->assert($res['json'] && $res['json']['success'] === true, "ensureCertificationsTable executed without error");
        $this->assert($res['json'] && $res['json']['has_file_name'] === true, "student_certifications table contains file_name column");
        $this->assert($res['json'] && $res['json']['has_file_size'] === true, "student_certifications table contains file_size column");
        $this->assert($res['json'] && $res['json']['format_bytes_exists'] === true, "formatBytes() function is defined globally");
        $this->assert($res['json'] && $res['json']['format_bytes_1kb'] === '1 KB', "formatBytes(1024) correctly formats to '1 KB'");
        $this->assert($res['json'] && $res['json']['format_bytes_2_5mb'] === '2.5 MB', "formatBytes(2621440) correctly formats to '2.5 MB'");
        echo "\n";
    }

    private function testStudentUploadValidation() {
        echo "Test Suite 2: Student Upload Validation (Invalid Types & Oversized Files)\n";

        // 1. Missing file validation
        $codeNoFile = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$u = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();

$_SESSION['user_id'] = (int)$u;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();
$_POST = ['csrf_token' => $token, 'title' => 'Test Certificate'];
$_SERVER['REQUEST_METHOD'] = 'POST';

include('api/upload-certification.php');
PHP;
        $resNoFile = $this->runPhpCode($codeNoFile);
        $this->assert($resNoFile['json'] && $resNoFile['json']['success'] === false, "Upload rejects when no file is selected");
        $this->assert($resNoFile['json'] && strpos($resNoFile['json']['message'], 'select a certificate file') !== false, "Descriptive error for missing certificate file");

        // 2. Disallowed extension (.txt)
        $codeBadExt = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$u = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();

$_SESSION['user_id'] = (int)$u;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$tmpFile = tempnam(sys_get_temp_dir(), 'bad_') . '.txt';
file_put_contents($tmpFile, 'Plain text content that is not a certificate');

$_FILES['cert_file'] = [
    'name' => 'malicious_script.txt',
    'type' => 'text/plain',
    'tmp_name' => $tmpFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpFile)
];

$_POST = ['csrf_token' => $token, 'title' => 'Invalid Format Cert'];
$_SERVER['REQUEST_METHOD'] = 'POST';

include('api/upload-certification.php');
@unlink($tmpFile);
PHP;
        $resBadExt = $this->runPhpCode($codeBadExt);
        $this->assert($resBadExt['json'] && $resBadExt['json']['success'] === false, "Upload rejects non-whitelisted file extension");
        $this->assert($resBadExt['json'] && strpos($resBadExt['json']['message'], 'PDF, JPG, and PNG') !== false, "Error message explicitly specifies PDF, JPG, and PNG formats");

        // 3. Oversized file (> 5MB)
        $codeOversized = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$u = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();

$_SESSION['user_id'] = (int)$u;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$tmpFile = tempnam(sys_get_temp_dir(), 'big_') . '.pdf';
file_put_contents($tmpFile, '%PDF-1.4 Mock large header');

$_FILES['cert_file'] = [
    'name' => 'huge_portfolio_scan.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $tmpFile,
    'error' => UPLOAD_ERR_OK,
    'size' => (5 * 1024 * 1024) + 1024 // 5MB + 1KB
];

$_POST = ['csrf_token' => $token, 'title' => 'Oversized Certificate'];
$_SERVER['REQUEST_METHOD'] = 'POST';

include('api/upload-certification.php');
@unlink($tmpFile);
PHP;
        $resOversized = $this->runPhpCode($codeOversized);
        $this->assert($resOversized['json'] && $resOversized['json']['success'] === false, "Upload rejects files exceeding 5MB");
        $this->assert($resOversized['json'] && strpos($resOversized['json']['message'], '5MB') !== false, "Error message informs user of 5MB limit");

        echo "\n";
    }

    private function testStudentUploadSuccess() {
        echo "Test Suite 3: Successful Student Certificate Upload (PDF & PNG)\n";

        // 1. Upload valid PDF certificate
        $codePdf = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$u = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();

$_SESSION['user_id'] = (int)$u;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$tmpPdf = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

$_FILES['cert_file'] = [
    'name' => 'AWS_Certified_Cloud_Practitioner.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $tmpPdf,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpPdf)
];

$_POST = [
    'csrf_token' => $token,
    'title' => 'AWS Certified Cloud Practitioner',
    'issuing_organization' => 'Amazon Web Services',
    'issue_date' => '2026-05-15',
    'credential_id' => 'AWS-99001122',
    'credential_url' => 'https://aws.amazon.com/verification',
    'skills' => 'Cloud Computing, AWS, IAM, S3'
];
$_SERVER['REQUEST_METHOD'] = 'POST';

include('api/upload-certification.php');
@unlink($tmpPdf);
PHP;

        $resPdf = $this->runPhpCode($codePdf);
        $this->assert($resPdf['json'] && $resPdf['json']['success'] === true, "Valid PDF certificate uploaded successfully");
        $this->assert($resPdf['json'] && !empty($resPdf['json']['cert']['cert_id']), "API returns created cert_id");
        $this->assert($resPdf['json'] && ($resPdf['json']['cert']['file_name'] ?? '') === 'AWS_Certified_Cloud_Practitioner.pdf', "Original file_name preserved in certificate metadata");
        $this->assert($resPdf['json'] && (int)($resPdf['json']['total_count'] ?? 0) >= 1, "API returns updated total_count");

        // 2. Upload valid PNG certificate
        $codePng = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$u = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();

$_SESSION['user_id'] = (int)$u;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$tmpPng = tempnam(sys_get_temp_dir(), 'png_') . '.png';
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($tmpPng, $pngData);

$_FILES['cert_file'] = [
    'name' => 'Python_Data_Structures_Coursera.png',
    'type' => 'image/png',
    'tmp_name' => $tmpPng,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpPng)
];

$_POST = [
    'csrf_token' => $token,
    'title' => 'Python Data Structures',
    'issuing_organization' => 'Coursera / University of Michigan',
    'issue_date' => '2026-06-20',
    'credential_id' => 'COURSERA-PY-889',
    'skills' => 'Python, Algorithms, Data Structures'
];
$_SERVER['REQUEST_METHOD'] = 'POST';

include('api/upload-certification.php');
@unlink($tmpPng);
PHP;

        $resPng = $this->runPhpCode($codePng);
        $this->assert($resPng['json'] && $resPng['json']['success'] === true, "Valid PNG certificate uploaded successfully");
        $this->assert($resPng['json'] && ($resPng['json']['cert']['file_name'] ?? '') === 'Python_Data_Structures_Coursera.png', "PNG certificate file name captured correctly");

        echo "\n";
    }

    private function testCertificateFileAccessControl() {
        echo "Test Suite 4: Access Control & Authorization on Certificate Files\n";

        // 1. Unauthenticated / Guest Access Block
        $codeGuest = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

unset($_SESSION['user_id']);
unset($_SESSION['role_id']);

$_GET = ['cert_id' => 1, 'mode' => 'view'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/certificate-file.php');
PHP;
        $resGuest = $this->runPhpCode($codeGuest);
        $this->assert(strpos($resGuest['raw'], 'Unauthorized') !== false || strpos($resGuest['raw'], '401') !== false || strpos($resGuest['raw'], 'login') !== false, "Guest access to certificate-file.php is blocked (401/Unauthorized)");

        // 2. Student 2 trying to access Student 1's certificate is forbidden (403)
        $codeCrossStudent = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();

// Pick Student 1's certificate
$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

// Log in as Student 2 (student_id = 2)
$u2 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_2'")->fetchColumn();

$_SESSION['user_id'] = (int)$u2;
$_SESSION['role_id'] = ROLE_STUDENT;

$_GET = ['cert_id' => $certId, 'mode' => 'view'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include('api/certificate-file.php');
$content = ob_get_clean();

// The endpoint should respond with Access denied or 403
echo $content;
PHP;
        $resCross = $this->runPhpCode($codeCrossStudent);
        $this->assert(strpos($resCross['raw'], 'Access denied') !== false || strpos($resCross['raw'], '403') !== false || strpos($resCross['raw'], 'Forbidden') !== false, "Student cannot access another student's certificate (403 Forbidden)");

        // 3. Admin can access Student 1's certificate (binary content returned = success)
        // Note: certificate-file.php clears ob buffers and calls exit after readfile(),
        // so we check the raw subprocess output for error keywords instead.
        $codeAdminAccess = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;

$_GET = ['cert_id' => $certId, 'mode' => 'view'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/certificate-file.php');
PHP;
        $resAdmin = $this->runPhpCode($codeAdminAccess);
        $hasAdminError = (strpos($resAdmin['raw'], 'Unauthorized') !== false || strpos($resAdmin['raw'], 'Forbidden') !== false || strpos($resAdmin['raw'], 'Not Found') !== false);
        $this->assert(!$hasAdminError && strlen($resAdmin['raw']) > 0, "Admin can successfully view any student's certificate");

        // 4. Student 1 can access own certificate
        $codeOwnAccess = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$db = Database::getInstance()->getConnection();
$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

$u1 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();
$_SESSION['user_id'] = (int)$u1;
$_SESSION['role_id'] = ROLE_STUDENT;

$_GET = ['cert_id' => $certId, 'mode' => 'view'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/certificate-file.php');
PHP;
        $resOwn = $this->runPhpCode($codeOwnAccess);
        $hasOwnError = (strpos($resOwn['raw'], 'Unauthorized') !== false || strpos($resOwn['raw'], 'Forbidden') !== false || strpos($resOwn['raw'], 'Not Found') !== false);
        $this->assert(!$hasOwnError && strlen($resOwn['raw']) > 0, "Student can successfully view their own certificate");

        echo "\n";
    }

    private function testCertificateStatusUpdates() {
        echo "Test Suite 5: Admin Certificate Status Workflow (Verify & Reject)\n";

        // 1. Student attempting to update status (must be blocked)
        $codeStudentBlocked = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/functions.php');
require_once('services/StudentService.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

$u1 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();
$_SESSION['user_id'] = (int)$u1;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$_POST = [
    'action' => 'update_status',
    'cert_id' => $certId,
    'status' => 'Verified',
    'csrf_token' => $token
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/api/certificate-actions.php';

include('api/certificate-actions.php');
PHP;
        $resStudentBlocked = $this->runPhpCode($codeStudentBlocked);
        $this->assert($resStudentBlocked['json'] && $resStudentBlocked['json']['success'] === false, "Student role is blocked from updating certificate status");

        // 2. Admin updating status to Verified
        $codeAdminVerify = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/functions.php');
require_once('services/StudentService.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;
$token = getCsrfToken();

$_POST = [
    'action' => 'update_status',
    'cert_id' => $certId,
    'status' => 'Verified',
    'csrf_token' => $token
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/api/certificate-actions.php';

include('api/certificate-actions.php');
PHP;
        $resAdminVerify = $this->runPhpCode($codeAdminVerify);
        $this->assert($resAdminVerify['json'] && $resAdminVerify['json']['success'] === true, "Admin can update certificate status to Verified");

        // 3. Verify DB reflects status
        require_once(__DIR__ . '/../config/database.php');
        $db = Database::getInstance()->getConnection();
        $status = $db->query("SELECT status FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetchColumn();
        $this->assert($status === 'Verified', "Database accurately reflects status 'Verified'", "Got: $status");

        echo "\n";
    }

    private function testDashboardAndRosterCertificateCounts() {
        echo "Test Suite 6: Student Dashboard & Admin Roster Certificate Counts\n";

        $codeCounts = <<<'PHP'
require_once('config/database.php');
require_once('config/constants.php');
require_once('services/StudentService.php');
require_once('includes/functions.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);
$studentService = new StudentService($db);

$studentId = 1;

// 1. Direct DB count for student 1
$stmtActual = $db->prepare("SELECT COUNT(*) FROM student_certifications WHERE student_id = ?");
$stmtActual->execute([$studentId]);
$actualCount = (int)$stmtActual->fetchColumn();

// 2. Check StudentService::getStudentCertificateCount
$serviceCount = $studentService->getStudentCertificateCount($studentId);

// 3. Check Admin Roster query join
$stmtRoster = $db->prepare("
    SELECT s.student_id, COALESCE(sc_counts.cert_count, 0) AS cert_count
    FROM students s
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS cert_count 
        FROM student_certifications 
        GROUP BY student_id
    ) sc_counts ON s.student_id = sc_counts.student_id
    WHERE s.student_id = ?
");
$stmtRoster->execute([$studentId]);
$row = $stmtRoster->fetch(PDO::FETCH_ASSOC);
$rosterCount = (int)$row['cert_count'];

// 4. Check Admin Dashboard total
$adminTotalCount = (int)$db->query("SELECT COUNT(cert_id) FROM student_certifications")->fetchColumn();
$directTotalCount = (int)$db->query("SELECT COUNT(*) FROM student_certifications")->fetchColumn();

echo json_encode([
    'success' => true,
    'actual_count' => $actualCount,
    'service_count' => $serviceCount,
    'roster_count' => $rosterCount,
    'counts_match' => ($serviceCount === $actualCount && $rosterCount === $actualCount && $actualCount > 0),
    'admin_total_matches' => ($adminTotalCount === $directTotalCount)
]);
PHP;

        $resCounts = $this->runPhpCode($codeCounts);
        $this->assert($resCounts['json'] && $resCounts['json']['success'] === true, "Count queries executed successfully");
        $this->assert($resCounts['json'] && $resCounts['json']['counts_match'] === true, "Student dashboard count and Admin roster count match actual certificate count");
        $this->assert($resCounts['json'] && $resCounts['json']['admin_total_matches'] === true, "Admin dashboard total certificate metric matches database total");

        echo "\n";
    }

    private function testCertificateDeletionAndCleanup() {
        echo "Test Suite 7: Certificate Deletion & Physical File Cleanup\n";

        // 1. Cross-student delete rejection (Student 2 cannot delete Student 1's certificate)
        $codeCrossDelete = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/functions.php');
require_once('services/StudentService.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$cert = $db->query("SELECT cert_id FROM student_certifications WHERE student_id = 1 ORDER BY cert_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];

$u2 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_2'")->fetchColumn();
$_SESSION['user_id'] = (int)$u2;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$_POST = [
    'action' => 'delete',
    'cert_id' => $certId,
    'csrf_token' => $token
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/api/certificate-actions.php';

include('api/certificate-actions.php');
PHP;
        $resCrossDelete = $this->runPhpCode($codeCrossDelete);
        $this->assert($resCrossDelete['json'] && $resCrossDelete['json']['success'] === false, "Student cannot delete another student's certificate (Unauthorized)");

        // 2. Owner student deletes own certificate
        $codeOwnerDelete = <<<'PHP'
require_once('includes/auth.php');
require_once('includes/csrf.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/functions.php');
require_once('services/StudentService.php');

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);
$studentService = new StudentService($db);

$cert = $db->query("SELECT * FROM student_certifications WHERE student_id = 1 ORDER BY cert_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$certId = (int)$cert['cert_id'];
$studentId = 1;
$filePath = $cert['file_path'];

$countBefore = $studentService->getStudentCertificateCount($studentId);

$u1 = $db->query("SELECT user_id FROM users WHERE username = 'test_student_1'")->fetchColumn();
$_SESSION['user_id'] = (int)$u1;
$_SESSION['role_id'] = ROLE_STUDENT;
$token = getCsrfToken();

$_POST = [
    'action' => 'delete',
    'cert_id' => $certId,
    'csrf_token' => $token
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/api/certificate-actions.php';

ob_start();
include('api/certificate-actions.php');
$deleteRes = json_decode(ob_get_clean(), true);

$dbCheck = $db->query("SELECT COUNT(*) FROM student_certifications WHERE cert_id = $certId")->fetchColumn();
$fullPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $filePath), '/');
$fileExists = file_exists($fullPath);
$countAfter = $studentService->getStudentCertificateCount($studentId);

echo json_encode([
    'delete_success' => ($deleteRes && $deleteRes['success'] === true),
    'db_removed' => ((int)$dbCheck === 0),
    'file_cleaned' => !$fileExists,
    'count_decremented' => ($countAfter === $countBefore - 1)
]);
PHP;

        $resOwnerDelete = $this->runPhpCode($codeOwnerDelete);
        $this->assert($resOwnerDelete['json'] && $resOwnerDelete['json']['delete_success'] === true, "Student can delete own certificate");
        $this->assert($resOwnerDelete['json'] && $resOwnerDelete['json']['db_removed'] === true, "Certificate row permanently removed from database");
        $this->assert($resOwnerDelete['json'] && $resOwnerDelete['json']['file_cleaned'] === true, "Certificate file unlinked from disk");
        $this->assert($resOwnerDelete['json'] && $resOwnerDelete['json']['count_decremented'] === true, "Certificate count properly decremented");

        echo "\n";
    }
}

$testRunner = new CertificateManagementTest();
$testRunner->run();
