<?php
// MAMCET Placement & Learning Portal - Secure Certificate File Controller
// Enforces role-based access control:
// - Students can only access their own certificates.
// - Admins and Placement Officers can access any student's certificate.
// - Unauthorized users are rejected with HTTP 403 Forbidden.

require_once(__DIR__ . '/../config/constants.php');
require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn()) {
    http_response_code(401);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>401 Unauthorized</h2><p>Please log in to access this certificate.</p></div>");
}

$roleId = (int)($_SESSION['role_id'] ?? 0);
$isAdmin = ($roleId === ROLE_SUPER_ADMIN || $roleId === ROLE_PLACEMENT_OFFICER);
$isStudent = ($roleId === ROLE_STUDENT);

$certId = (int)($_GET['cert_id'] ?? $_GET['id'] ?? 0);
$mode = strtolower(trim($_GET['mode'] ?? 'view'));
if (!in_array($mode, ['view', 'download'], true)) {
    $mode = 'view';
}

if ($certId <= 0) {
    http_response_code(400);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>400 Bad Request</h2><p>Valid certificate ID is required.</p></div>");
}

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$stmt = $db->prepare("
    SELECT cert_id, student_id, file_path, file_name, file_size, title, status 
    FROM student_certifications 
    WHERE cert_id = ?
");
$stmt->execute([$certId]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    http_response_code(404);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>404 Not Found</h2><p>Certificate record not found in system.</p></div>");
}

// Enforce strict access boundaries
if ($isStudent) {
    $currentStudent = getActiveStudent($db);
    if (!$currentStudent || (int)$cert['student_id'] !== (int)$currentStudent['student_id']) {
        http_response_code(403);
        die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>403 Forbidden</h2><p>Access denied. You can only view or download your own certificates.</p></div>");
    }
} elseif (!$isAdmin) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>403 Forbidden</h2><p>Access denied. Insufficient permissions to access certificates.</p></div>");
}

// Resolve and validate absolute file path on disk
if (empty($cert['file_path'])) {
    http_response_code(404);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>404 Not Found</h2><p>No document file attached to this certificate record.</p></div>");
}

$cleanRelativePath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $cert['file_path']), DIRECTORY_SEPARATOR);
$fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $cleanRelativePath;
$realPath = realpath($fullPath);

$uploadBase = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads');

if (!$realPath || !file_exists($realPath) || !is_file($realPath)) {
    http_response_code(404);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>404 File Not Found</h2><p>Certificate document file is missing on the server disk.</p></div>");
}

// Prevent path traversal outside authorized uploads directory
if ($uploadBase !== false && strpos($realPath, $uploadBase) !== 0) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>403 Forbidden</h2><p>Security violation: Path traversal detected.</p></div>");
}

// Determine MIME type
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg'
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($realPath);
    if ($detectedMime && in_array($detectedMime, ['application/pdf', 'image/png', 'image/jpeg'])) {
        $mime = $detectedMime;
    }
}

// Choose download filename
$downloadName = !empty($cert['file_name']) ? basename($cert['file_name']) : basename($realPath);
// Sanitize filename for HTTP header
$safeDownloadName = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $downloadName);

// Clear any previous output buffers
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($mode === 'download') {
    header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeDownloadName . '"');
}

readfile($realPath);
exit;
