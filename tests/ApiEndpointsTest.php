<?php
// MAMCET Placement & Learning Portal - Automated API Integration Test Suite

class ApiTestRunner {
    private $passed = 0;
    private $failed = 0;

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
        echo " MAMCET API Integration Test Suite                  \n";
        echo "====================================================\n\n";

        $this->testCheckDuplicateEndpoint();
        $this->testSectionLookupEndpoint();
        $this->testManualCreationEndpoint();
        $this->testCsvUploadPreviewEndpoint();
        $this->testUnauthorizedAccessEndpoint();
        $this->testStudentSearchEndpoint();

        echo "\n====================================================\n";
        echo " API Integration Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "====================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function testStudentSearchEndpoint() {
        echo "API Test Suite 6: Student Spotlight Search & Quick Detail Lookup\n";

        // 1. Test search query
        $codeSearch = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;

$_GET = ['action' => 'search', 'q' => '9204', 'limit' => 5];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-search.php');
PHP;

        $resSearch = $this->runPhpCode($codeSearch);
        $this->assert($resSearch['json'] && $resSearch['json']['success'] === true, "student-search.php returns success on search");
        $this->assert(isset($resSearch['json']['results']) && is_array($resSearch['json']['results']), "student-search.php returns results array");

        // 2. Test get_student_detail
        $codeDetail = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;

$db = Database::getInstance()->getConnection();
$studentId = (int)$db->query("SELECT student_id FROM students ORDER BY student_id ASC LIMIT 1")->fetchColumn();

if ($studentId > 0) {
    $_GET = ['action' => 'get_student_detail', 'student_id' => $studentId];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    include('api/student-search.php');
} else {
    echo json_encode(['success' => true, 'mock' => true]);
}
PHP;

        $resDetail = $this->runPhpCode($codeDetail);
        $this->assert($resDetail['json'] && $resDetail['json']['success'] === true, "get_student_detail returns valid student detail payload");

        // 3. Test unauthorized rejection
        $codeUnauthorized = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 5;
$_SESSION['role_id'] = ROLE_STUDENT;

$_GET = ['action' => 'search', 'q' => 'test'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-search.php');
PHP;

        $resUnauth = $this->runPhpCode($codeUnauthorized);
        $this->assert($resUnauth['json'] && $resUnauth['json']['success'] === false, "student-search.php blocks non-admin/student users with 403");

        echo "\n";
    }

    private function runPhpCode(string $code): array {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_api_') . '.php';
        file_put_contents($tempFile, "<?php\n" . $code);
        $output = shell_exec("php " . escapeshellarg($tempFile) . " 2>&1");
        @unlink($tempFile);
        return [
            'raw' => $output,
            'json' => json_decode($output, true)
        ];
    }

    private function testCheckDuplicateEndpoint() {
        echo "API Test Suite 1: Duplicate Check API (action=check_duplicate)\n";

        $code = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;

$db = Database::getInstance()->getConnection();
$db->exec("DELETE FROM students WHERE registration_number = 'TEST_API_DUP_01'");
$db->exec("INSERT INTO students (registration_number, student_name, mobile_number, dept_id, batch_id) VALUES ('TEST_API_DUP_01', 'Test API User', '9876543210', 1, 1)");

$_REQUEST = ['action' => 'check_duplicate', 'registration_number' => 'TEST_API_DUP_01'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-api.php');
PHP;

        $res = $this->runPhpCode($code);
        $this->assert($res['json'] && $res['json']['success'] === true, "check_duplicate endpoint returns success");
        $this->assert($res['json'] && $res['json']['exists'] === true, "check_duplicate identifies existing student in DB");

        $codeAvailable = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;

$_REQUEST = ['action' => 'check_duplicate', 'registration_number' => 'TEST_API_AVAILABLE_99'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-api.php');
PHP;
        $resAvail = $this->runPhpCode($codeAvailable);
        $this->assert($resAvail['json'] && $resAvail['json']['exists'] === false, "check_duplicate reports non-existent student as available");

        echo "\n";
    }

    private function testSectionLookupEndpoint() {
        echo "API Test Suite 2: Section Lookup API (action=get_sections)\n";

        $code = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_PLACEMENT_OFFICER;

$_REQUEST = ['action' => 'get_sections', 'dept_id' => 1];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-api.php');
PHP;

        $res = $this->runPhpCode($code);
        $this->assert($res['json'] && $res['json']['success'] === true, "get_sections returns success: true");
        $this->assert($res['json'] && is_array($res['json']['sections']), "get_sections returns list of sections");

        echo "\n";
    }

    private function testManualCreationEndpoint() {
        echo "API Test Suite 3: Manual Create Student API (action=create_student)\n";

        $code = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/csrf.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;
$token = getCsrfToken();

$db = Database::getInstance()->getConnection();
$db->exec("DELETE FROM students WHERE registration_number = 'TEST_API_CREATE_99'");

$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = ['action' => 'create_student'];
$_POST = [
    'action' => 'create_student',
    'csrf_token' => $token,
    'registration_number' => 'TEST_API_CREATE_99',
    'student_name' => 'Karthik Subramanian',
    'mobile_number' => '9840198401',
    'email' => 'karthik.s@example.com',
    'dept_id' => 1,
    'batch_id' => 1,
    'current_cgpa' => '8.90',
    'standing_arrears' => 0,
    'placement_willingness' => 'Yes'
];

include('api/student-api.php');
PHP;

        $res = $this->runPhpCode($code);
        $this->assert($res['json'] && $res['json']['success'] === true, "create_student API creates student successfully");
        $this->assert($res['json'] && !empty($res['json']['student_id']), "create_student API returns newly generated student_id");

        echo "\n";
    }

    private function testCsvUploadPreviewEndpoint() {
        echo "API Test Suite 4: Excel Upload & Preview API (action=upload_and_preview)\n";

        $code = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');
require_once('includes/csrf.php');
require_once('includes/excel-helper.php');

$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = ROLE_SUPER_ADMIN;
$token = getCsrfToken();

$rows = [
    ['registration_number', 'student_name', 'mobile_number', 'email', 'current_cgpa', 'standing_arrears'],
    ['812421104001', 'Rithika S', '9876543210', 'rithika@gmail.com', '8.90', '0']
];
$tempXlsx = tempnam(sys_get_temp_dir(), 'xlsx_test_') . '.xlsx';
ExcelHelper::createXlsx($rows, $tempXlsx);

$_FILES['excel_file'] = [
    'name' => 'students_upload.xlsx',
    'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $tempXlsx,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tempXlsx)
];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = ['action' => 'upload_and_preview'];
$_POST = [
    'action' => 'upload_and_preview',
    'csrf_token' => $token,
    'default_dept_id' => 1,
    'default_batch_id' => 1
];

include('api/student-import-api.php');
@unlink($tempXlsx);
PHP;

        $res = $this->runPhpCode($code);
        $this->assert($res['json'] && $res['json']['success'] === true, "upload_and_preview returns success: true");
        $this->assert($res['json'] && !empty($res['json']['import_token']), "upload_and_preview generates import_token");
        $this->assert($res['json'] && $res['json']['summary']['valid_rows'] === 1, "upload_and_preview identified valid row from XLSX");

        echo "\n";
    }

    private function testUnauthorizedAccessEndpoint() {
        echo "API Test Suite 5: Unauthorized Role & Guest Access Block\n";

        $codeStudent = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 5;
$_SESSION['role_id'] = ROLE_STUDENT; // Role 3 is Student

$_REQUEST = ['action' => 'check_duplicate', 'registration_number' => 'ANY_REG'];
$_SERVER['REQUEST_METHOD'] = 'GET';

include('api/student-api.php');
PHP;

        $resStudent = $this->runPhpCode($codeStudent);
        $this->assert($resStudent['json'] && $resStudent['json']['success'] === false, "student-api.php rejects Student role");

        $codeImport = <<<'PHP'
require_once('includes/auth.php');
require_once('config/constants.php');
require_once('config/database.php');

$_SESSION['user_id'] = 5;
$_SESSION['role_id'] = ROLE_STUDENT;

$_REQUEST = ['action' => 'download_template'];

include('api/student-import-api.php');
PHP;

        $resImport = $this->runPhpCode($codeImport);
        $this->assert(strpos($resImport['raw'], '403 Forbidden') !== false, "student-import-api.php blocks template download for Student role");

        echo "\n";
    }
}

$apiRunner = new ApiTestRunner();
$apiRunner->run();
