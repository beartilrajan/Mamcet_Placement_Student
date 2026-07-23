<?php
// MAMCET Placement & Learning Portal - Scan Datasets API

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$dataDir = __DIR__ . '/../data';

if (!file_exists($dataDir)) {
    echo json_encode(['success' => false, 'message' => 'Data directory data/ does not exist.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Fetch departments for auto-detection matching
    $depts = $db->query("SELECT dept_id, dept_code, short_name FROM departments")->fetchAll();
    
    $files = scandir($dataDir);
    $detectedFilesCount = 0;
    
    foreach ($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['xlsx', 'xls', 'csv'])) {
            $filePath = $dataDir . '/' . $file;
            $fileSize = filesize($filePath);
            $modTime = date('Y-m-d H:i:s', filemtime($filePath));
            
            // Detect department
            $detectedDeptId = null;
            $cleanName = strtolower(str_replace([' ', '-', '_'], '', $file));
            
            foreach ($depts as $d) {
                $code = strtolower(str_replace([' ', '-', '_'], '', $d['dept_code']));
                $short = strtolower(str_replace([' ', '-', '_'], '', $d['short_name']));
                
                if (strpos($cleanName, $code) !== false || strpos($cleanName, $short) !== false) {
                    $detectedDeptId = $d['dept_id'];
                    break;
                }
            }
            
            // Check if file is registered
            $stmt = $db->prepare("SELECT file_id, filesize, last_modified FROM dataset_files WHERE filename = ?");
            $stmt->execute([$file]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update size and modification date if changed
                if ($existing['filesize'] != $fileSize || $existing['last_modified'] != $modTime) {
                    $upd = $db->prepare("
                        UPDATE dataset_files 
                        SET filesize = ?, last_modified = ?, import_status = 'pending' 
                        WHERE file_id = ?
                    ");
                    $upd->execute([$fileSize, $modTime, $existing['file_id']]);
                }
            } else {
                // Register new file
                $ins = $db->prepare("
                    INSERT INTO dataset_files (filename, filepath, filesize, last_modified, detected_dept_id, import_status) 
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $ins->execute([$file, $filePath, $fileSize, $modTime, $detectedDeptId]);
            }
            $detectedFilesCount++;
        }
    }
    
    // Log activity
    logActivity($db, $_SESSION['user_id'], 'Scan Datasets', "Scanned data directory. Detected $detectedFilesCount files.");
    
    echo json_encode(['success' => true, 'count' => $detectedFilesCount]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error scanning datasets: ' . $e->getMessage()]);
}
