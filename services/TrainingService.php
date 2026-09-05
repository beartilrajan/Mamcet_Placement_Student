<?php
// MAMCET Placement & Learning Portal - Training Management & Attendance Service

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/excel-helper.php');

class TrainingService {
    private PDO $db;
    private static bool $tablesEnsured = false;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance()->getConnection();
        if (!self::$tablesEnsured) {
            self::ensureTables($this->db);
            self::$tablesEnsured = true;
        }
    }

    /**
     * Automatically ensure all training attendance and draft tables/columns exist.
     * Prevents database migration errors on freshly deployed servers.
     */
    public static function ensureTables(PDO $db): void {
        try {
            // 1. Ensure training_attendance_drafts table exists
            $db->exec("
                CREATE TABLE IF NOT EXISTS `training_attendance_drafts` (
                    `draft_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `training_id` INT NOT NULL,
                    `student_id` INT NOT NULL,
                    `status` ENUM('Pending', 'Present', 'Absent', 'Late', 'Excused') NOT NULL DEFAULT 'Pending',
                    `note` TEXT DEFAULT NULL,
                    `drafted_by` VARCHAR(150) DEFAULT NULL,
                    `drafted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_training_attendance_draft` (`training_id`, `student_id`),
                    INDEX `idx_training_attendance_draft_training` (`training_id`, `updated_at`),
                    INDEX `idx_training_attendance_draft_student` (`student_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 2. Ensure training_attendance_audit table exists
            $db->exec("
                CREATE TABLE IF NOT EXISTS `training_attendance_audit` (
                    `audit_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `attendance_id` INT DEFAULT NULL,
                    `training_id` INT NOT NULL,
                    `student_id` INT NOT NULL,
                    `old_status` VARCHAR(30) DEFAULT NULL,
                    `new_status` VARCHAR(30) NOT NULL,
                    `source` ENUM('trainer', 'admin') NOT NULL DEFAULT 'trainer',
                    `changed_by_user_id` INT DEFAULT NULL,
                    `changed_by_name` VARCHAR(150) DEFAULT NULL,
                    `note` TEXT DEFAULT NULL,
                    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_training_attendance_audit_training` (`training_id`, `changed_at`),
                    INDEX `idx_training_attendance_audit_student` (`student_id`, `changed_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 3. Ensure columns in trainings table
            $colsTrainings = $db->query("SHOW COLUMNS FROM `trainings`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('trainer_access_token', $colsTrainings, true)) {
                $db->exec("ALTER TABLE `trainings` ADD COLUMN `trainer_access_token` VARCHAR(64) DEFAULT NULL UNIQUE AFTER `attendance_rule`");
            }
            if (!in_array('attendance_submitted_at', $colsTrainings, true)) {
                $db->exec("ALTER TABLE `trainings` ADD COLUMN `attendance_submitted_at` DATETIME DEFAULT NULL AFTER `trainer_access_token`");
            }
            if (!in_array('attendance_submitted_by', $colsTrainings, true)) {
                $db->exec("ALTER TABLE `trainings` ADD COLUMN `attendance_submitted_by` VARCHAR(150) DEFAULT NULL AFTER `attendance_submitted_at`");
            }

            // 4. Ensure columns in training_attendance table
            $colsAttendance = $db->query("SHOW COLUMNS FROM `training_attendance`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('marked_by', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `marked_by` VARCHAR(150) DEFAULT NULL AFTER `status`");
            }
            if (!in_array('marked_at', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `marked_at` DATETIME DEFAULT NULL AFTER `marked_by`");
            }
            if (!in_array('attendance_note', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `attendance_note` TEXT DEFAULT NULL AFTER `marked_at`");
            }
            if (!in_array('manually_adjusted', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `manually_adjusted` TINYINT(1) DEFAULT 0 AFTER `session_count`");
            }
            if (!in_array('adjustment_reason', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `adjustment_reason` TEXT DEFAULT NULL AFTER `manually_adjusted`");
            }
            if (!in_array('adjusted_by', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `adjusted_by` INT DEFAULT NULL AFTER `adjustment_reason`");
            }
            if (!in_array('adjusted_at', $colsAttendance, true)) {
                $db->exec("ALTER TABLE `training_attendance` ADD COLUMN `adjusted_at` DATETIME DEFAULT NULL AFTER `adjusted_by`");
            }
        } catch (\Throwable $e) {
            error_log('TrainingService::ensureTables migration warning: ' . $e->getMessage());
        }
    }

    /**
     * Generate the bearer token used by the assigned trainer to open the
     * attendance roster. The token is deliberately unrelated to a student or
     * admin session because trainers are not portal users in this application.
     */
    private function generateTrainerAccessToken(): string {
        return bin2hex(random_bytes(32));
    }

    private function normalizeEventType($eventType): string {
        $eventType = strtolower(trim((string)$eventType));
        if ($eventType === '' || $eventType === 'online') {
            return 'Online';
        }
        return $eventType === 'offline' ? 'Offline' : '';
    }

    private function isAttendedStatus(string $status): bool {
        return in_array($status, ['Present', 'Late', 'Excused'], true);
    }

    private function insertAttendanceAudit(
        int $trainingId,
        int $studentId,
        ?int $attendanceId,
        ?string $oldStatus,
        string $newStatus,
        string $source,
        ?int $changedByUserId,
        ?string $changedByName,
        ?string $note
    ): void {
        $stmt = $this->db->prepare("\n            INSERT INTO training_attendance_audit (\n                attendance_id, training_id, student_id, old_status, new_status,\n                source, changed_by_user_id, changed_by_name, note, changed_at\n            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n        ");
        $stmt->execute([
            $attendanceId,
            $trainingId,
            $studentId,
            $oldStatus,
            $newStatus,
            $source,
            $changedByUserId,
            $changedByName,
            $note
        ]);
    }

    /**
     * Validate training input parameters.
     */
    public function validateTrainingData(array $data, array $deptIds, int $excludeId = 0): array {
        $errors = [];

        $title = trim($data['title'] ?? '');
        if (empty($title)) {
            $errors[] = 'Training title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors[] = 'Training title cannot exceed 255 characters.';
        }

        $eventType = $this->normalizeEventType($data['event_type'] ?? 'Online');
        if (!in_array($eventType, ['Online', 'Offline'], true)) {
            $errors[] = 'Please select a valid event type.';
        }

        $trainingUrl = trim($data['training_url'] ?? '');
        if ($eventType === 'Online' && empty($trainingUrl)) {
            $errors[] = 'An online training URL is required for online events.';
        } elseif (!empty($trainingUrl)) {
            if (!filter_var($trainingUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Please enter a valid external training URL.';
            } else {
                $scheme = parse_url($trainingUrl, PHP_URL_SCHEME);
                if (!in_array(strtolower($scheme ?: ''), ['http', 'https'], true)) {
                    $errors[] = 'Training URL must start with http:// or https:// (HTTPS recommended).';
                }
            }
        }

        if ($eventType === 'Offline' && empty(trim($data['venue_location'] ?? ''))) {
            $errors[] = 'A venue or location is required for offline events.';
        }

        if (empty($deptIds)) {
            $errors[] = 'At least one eligible department must be selected.';
        } else {
            $validDeptCount = (int)$this->db->query("SELECT COUNT(*) FROM departments WHERE dept_id IN (" . implode(',', array_map('intval', $deptIds)) . ")")->fetchColumn();
            if ($validDeptCount === 0) {
                $errors[] = 'Please select valid departments.';
            }
        }

        $startDateTime = trim($data['start_date_time'] ?? '');
        $endDateTime = trim($data['end_date_time'] ?? '');

        if (empty($startDateTime)) {
            $errors[] = 'Start date and time is required.';
        } elseif (!strtotime($startDateTime)) {
            $errors[] = 'Invalid start date and time format.';
        }

        if (empty($endDateTime)) {
            $errors[] = 'End date and time is required.';
        } elseif (!strtotime($endDateTime)) {
            $errors[] = 'Invalid end date and time format.';
        }

        if (!empty($startDateTime) && !empty($endDateTime) && strtotime($startDateTime) && strtotime($endDateTime)) {
            if (strtotime($endDateTime) <= strtotime($startDateTime)) {
                $errors[] = 'End date and time must be later than start date and time.';
            }
        }

        $minDuration = isset($data['minimum_engagement_seconds']) ? (int)$data['minimum_engagement_seconds'] : 0;
        if ($minDuration < 0) {
            $errors[] = 'Minimum engagement duration cannot be negative.';
        }

        // Duplicate check: avoid accidental duplicate training with identical title and start time
        if (empty($errors)) {
            $sql = "SELECT COUNT(*) FROM trainings WHERE title = ? AND start_date_time = ? AND status != 'Archived'";
            $params = [$title, date('Y-m-d H:i:s', strtotime($startDateTime))];
            if ($excludeId > 0) {
                $sql .= " AND training_id != ?";
                $params[] = $excludeId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'A training with the same title and start time already exists.';
            }
        }

        return $errors;
    }

    /**
     * Create a new training program.
     */
    public function createTraining(array $data, array $deptIds, int $userId): array {
        $errors = $this->validateTrainingData($data, $deptIds);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $trainingUrl = trim($data['training_url'] ?? '');

        try {
            $this->db->beginTransaction();

            $status = $data['status'] ?? 'Upcoming';
            $allowedStatuses = ['Draft', 'Upcoming', 'Active', 'Completed', 'Archived'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'Upcoming';
            }

            $stmt = $this->db->prepare("
                INSERT INTO trainings (
                    title, description, training_url, thumbnail_url, trainer_name,
                    event_type, venue_location, trainer_access_token,
                    start_date_time, end_date_time, minimum_engagement_seconds,
                    status, attendance_rule, session_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                trim($data['title']),
                trim($data['description'] ?? ''),
                $trainingUrl !== '' ? $trainingUrl : null,
                trim($data['thumbnail_url'] ?? ''),
                trim($data['trainer_name'] ?? ''),
                $this->normalizeEventType($data['event_type'] ?? 'Online'),
                trim($data['venue_location'] ?? '') ?: null,
                $this->generateTrainerAccessToken(),
                date('Y-m-d H:i:s', strtotime($data['start_date_time'])),
                date('Y-m-d H:i:s', strtotime($data['end_date_time'])),
                max(0, (int)($data['minimum_engagement_seconds'] ?? 0)),
                $status,
                $data['attendance_rule'] ?? 'trainer_marked',
                (int)($data['session_id'] ?? 1),
                $userId
            ]);

            $trainingId = (int)$this->db->lastInsertId();

            $stmtDept = $this->db->prepare("INSERT INTO training_departments (training_id, dept_id) VALUES (?, ?)");
            $uniqueDepts = array_unique(array_map('intval', $deptIds));
            foreach ($uniqueDepts as $deptId) {
                if ($deptId > 0) {
                    $stmtDept->execute([$trainingId, $deptId]);
                }
            }

            $this->db->commit();
            logActivity($this->db, $userId, 'Create Training', "Created training '{$data['title']}' (ID: $trainingId)");

            // Dispatch notification to eligible students
            if ($status !== 'Draft' && $status !== 'Archived') {
                require_once(__DIR__ . '/NotificationService.php');
                NotificationService::notifyTrainingPublished($this->db, $trainingId, $userId);
            }

            return ['success' => true, 'training_id' => $trainingId, 'message' => 'Training program created successfully.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()], 'message' => 'Failed to create training: ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing training program.
     */
    public function updateTraining(int $trainingId, array $data, array $deptIds, int $userId = 0): array {
        $errors = $this->validateTrainingData($data, $deptIds, $trainingId);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $trainingUrl = trim($data['training_url'] ?? '');

        try {
            $this->db->beginTransaction();

            $existing = $this->getTrainingById($trainingId);
            if (!$existing) {
                $this->db->rollBack();
                return ['success' => false, 'errors' => ['Training not found.'], 'message' => 'Training program not found.'];
            }

            $status = $data['status'] ?? $existing['status'];
            $allowedStatuses = ['Draft', 'Upcoming', 'Active', 'Completed', 'Archived'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = $existing['status'];
            }

            $thumbnailUrl = !empty($data['thumbnail_url']) ? trim($data['thumbnail_url']) : $existing['thumbnail_url'];

            $stmt = $this->db->prepare("
                UPDATE trainings SET
                    title = ?,
                    description = ?,
                    training_url = ?,
                    thumbnail_url = ?,
                    trainer_name = ?,
                    event_type = ?,
                    venue_location = ?,
                    start_date_time = ?,
                    end_date_time = ?,
                    minimum_engagement_seconds = ?,
                    status = ?,
                    attendance_rule = ?,
                    trainer_access_token = COALESCE(NULLIF(trainer_access_token, ''), ?)
                WHERE training_id = ?
            ");

            $stmt->execute([
                trim($data['title']),
                trim($data['description'] ?? ''),
                $trainingUrl !== '' ? $trainingUrl : null,
                $thumbnailUrl,
                trim($data['trainer_name'] ?? ''),
                $this->normalizeEventType($data['event_type'] ?? ($existing['event_type'] ?? 'Online')),
                trim($data['venue_location'] ?? '') ?: null,
                date('Y-m-d H:i:s', strtotime($data['start_date_time'])),
                date('Y-m-d H:i:s', strtotime($data['end_date_time'])),
                max(0, (int)($data['minimum_engagement_seconds'] ?? 0)),
                $status,
                $data['attendance_rule'] ?? 'trainer_marked',
                $this->generateTrainerAccessToken(),
                $trainingId
            ]);

            // Refresh department mappings
            $this->db->prepare("DELETE FROM training_departments WHERE training_id = ?")->execute([$trainingId]);
            $stmtDept = $this->db->prepare("INSERT INTO training_departments (training_id, dept_id) VALUES (?, ?)");
            $uniqueDepts = array_unique(array_map('intval', $deptIds));
            foreach ($uniqueDepts as $deptId) {
                if ($deptId > 0) {
                    $stmtDept->execute([$trainingId, $deptId]);
                }
            }

            $this->db->commit();
            if ($userId > 0) {
                logActivity($this->db, $userId, 'Update Training', "Updated training '{$data['title']}' (ID: $trainingId)");
            }

            if ($status !== 'Draft' && $status !== 'Archived') {
                require_once(__DIR__ . '/NotificationService.php');
                NotificationService::notifyTrainingPublished($this->db, $trainingId, $userId);
            }

            return ['success' => true, 'training_id' => $trainingId, 'message' => 'Training program updated successfully.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()], 'message' => 'Failed to update training: ' . $e->getMessage()];
        }
    }

    /**
     * Duplicate an existing training program.
     */
    public function duplicateTraining(int $trainingId, int $userId): array {
        try {
            $existing = $this->getTrainingById($trainingId);
            if (!$existing) {
                return ['success' => false, 'message' => 'Training program not found.'];
            }

            $deptIds = $existing['department_ids'] ?? [];
            if (empty($deptIds)) {
                $stmt = $this->db->prepare("SELECT dept_id FROM training_departments WHERE training_id = ?");
                $stmt->execute([$trainingId]);
                $deptIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $cloneData = [
                'title' => 'Copy of ' . $existing['title'],
                'description' => $existing['description'],
                'training_url' => $existing['training_url'],
                'thumbnail_url' => $existing['thumbnail_url'],
                'trainer_name' => $existing['trainer_name'],
                'start_date_time' => date('Y-m-d H:i:s', strtotime('+1 day', strtotime($existing['start_date_time']))),
                'end_date_time' => date('Y-m-d H:i:s', strtotime('+1 day', strtotime($existing['end_date_time']))),
                'minimum_engagement_seconds' => $existing['minimum_engagement_seconds'],
                'status' => 'Draft',
                'attendance_rule' => 'trainer_marked',
                'event_type' => $existing['event_type'] ?? 'Online',
                'venue_location' => $existing['venue_location'] ?? '',
                'session_id' => $existing['session_id'] ?? 1
            ];

            $res = $this->createTraining($cloneData, $deptIds, $userId);
            if ($res['success']) {
                logActivity($this->db, $userId, 'Duplicate Training', "Duplicated training ID $trainingId to new ID {$res['training_id']}");
            }
            return $res;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to duplicate training: ' . $e->getMessage()];
        }
    }

    /**
     * Archive a training program.
     */
    public function archiveTraining(int $trainingId, int $userId = 0): array {
        try {
            $stmt = $this->db->prepare("UPDATE trainings SET status = 'Archived', archived_at = NOW() WHERE training_id = ?");
            $stmt->execute([$trainingId]);
            if ($userId > 0) {
                logActivity($this->db, $userId, 'Archive Training', "Archived training ID $trainingId");
            }
            return ['success' => true, 'message' => 'Training program archived successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to archive training: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a training program and associated attendance records.
     */
    public function deleteTraining(int $trainingId, int $userId = 0): array {
        try {
            $stmt = $this->db->prepare("DELETE FROM trainings WHERE training_id = ?");
            $stmt->execute([$trainingId]);
            if ($userId > 0) {
                logActivity($this->db, $userId, 'Delete Training', "Deleted training ID $trainingId");
            }
            return ['success' => true, 'message' => 'Training program deleted successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to delete training: ' . $e->getMessage()];
        }
    }

    /**
     * Get a single training by ID with mapped departments.
     */
    public function getTrainingById(int $trainingId): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, u.username AS creator_username,
                   GROUP_CONCAT(DISTINCT d.dept_id) AS dept_ids_str,
                   GROUP_CONCAT(DISTINCT d.dept_code ORDER BY d.dept_code SEPARATOR ', ') AS dept_codes_str,
                   GROUP_CONCAT(DISTINCT d.dept_name ORDER BY d.dept_code SEPARATOR '; ') AS dept_names_str
            FROM trainings t
            LEFT JOIN users u ON t.created_by = u.user_id
            LEFT JOIN training_departments td ON t.training_id = td.training_id
            LEFT JOIN departments d ON td.dept_id = d.dept_id
            WHERE t.training_id = ?
            GROUP BY t.training_id
        ");
        $stmt->execute([$trainingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['department_ids'] = !empty($row['dept_ids_str']) ? array_map('intval', explode(',', $row['dept_ids_str'])) : [];
        $row['departments_display'] = $row['dept_codes_str'] ?: 'None';

        // Compute dynamic status if not Draft or Archived
        $row['computed_status'] = $this->computeDynamicStatus($row);

        return $row;
    }

    /**
     * Resolve a trainer's bearer link to a training. Draft and archived
     * trainings are intentionally not exposed through the link.
     */
    public function getTrainingByTrainerToken(string $token): ?array {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT training_id FROM trainings WHERE trainer_access_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $trainingId = (int)$stmt->fetchColumn();
        if ($trainingId <= 0) {
            return null;
        }

        $training = $this->getTrainingById($trainingId);
        if (!$training || $training['status'] === 'Archived') {
            return null;
        }

        return $training;
    }

    /**
     * Get the attendance roster using a trainer access token.
     */
    public function getTrainerAttendanceDashboard(string $token, array $filters = []): ?array {
        $training = $this->getTrainingByTrainerToken($token);
        return $training ? $this->getTrainingAttendanceDashboard((int)$training['training_id'], $filters, true) : null;
    }

    /**
     * Dynamically compute current temporal status (Draft/Archived are sticky).
     */
    public function computeDynamicStatus(array $training): string {
        $status = $training['status'] ?? 'Draft';
        if ($status === 'Draft' || $status === 'Archived') {
            return $status;
        }

        $now = time();
        $start = strtotime($training['start_date_time']);
        $end = strtotime($training['end_date_time']);

        if ($now < $start) {
            return 'Upcoming';
        } elseif ($now >= $start && $now <= $end) {
            return 'Active';
        } else {
            return 'Completed';
        }
    }

    /**
     * List trainings with multi-column filtering, search, and pagination.
     */
    public function listTrainings(array $filters = [], int $page = 1, int $perPage = 10): array {
        $where = ['1=1'];
        $params = [];

        // Search query
        if (!empty($filters['search'])) {
            $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.trainer_name LIKE ?)';
            $searchTerm = '%' . trim($filters['search']) . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Department filter
        if (!empty($filters['dept_id'])) {
            $where[] = 't.training_id IN (SELECT training_id FROM training_departments WHERE dept_id = ?)';
            $params[] = (int)$filters['dept_id'];
        }

        // Status filter
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'Draft' || $filters['status'] === 'Archived') {
                $where[] = 't.status = ?';
                $params[] = $filters['status'];
            } elseif ($filters['status'] === 'Upcoming') {
                $where[] = "t.status != 'Draft' AND t.status != 'Archived' AND t.start_date_time > NOW()";
            } elseif ($filters['status'] === 'Active') {
                $where[] = "t.status != 'Draft' AND t.status != 'Archived' AND t.start_date_time <= NOW() AND t.end_date_time >= NOW()";
            } elseif ($filters['status'] === 'Completed') {
                $where[] = "t.status != 'Draft' AND t.status != 'Archived' AND t.end_date_time < NOW()";
            }
        }

        // Date range filters
        if (!empty($filters['date_from'])) {
            $where[] = 't.start_date_time >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime($filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $where[] = 't.end_date_time <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime($filters['date_to']));
        }

        $whereClause = implode(' AND ', $where);

        // Total count
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM trainings t WHERE $whereClause");
        $stmtCount->execute($params);
        $totalItems = (int)$stmtCount->fetchColumn();

        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        // Allowed sorting columns
        $sortBy = 't.created_at';
        $sortOrder = 'DESC';
        if (!empty($filters['sort_by'])) {
            $allowedSorts = [
                'title' => 't.title',
                'start_date_time' => 't.start_date_time',
                'end_date_time' => 't.end_date_time',
                'created_at' => 't.created_at',
                'status' => 't.status'
            ];
            $sortBy = $allowedSorts[$filters['sort_by']] ?? 't.created_at';
        }
        if (!empty($filters['sort_order']) && strtoupper($filters['sort_order']) === 'ASC') {
            $sortOrder = 'ASC';
        }

        $query = "
            SELECT t.*, u.username AS creator_username,
                   GROUP_CONCAT(DISTINCT d.dept_code ORDER BY d.dept_code SEPARATOR ', ') AS dept_codes,
                   (
                       SELECT COUNT(DISTINCT s.student_id)
                       FROM students s
                       JOIN training_departments td ON s.dept_id = td.dept_id
                       WHERE td.training_id = t.training_id
                   ) AS eligible_students_count,
                    (
                        SELECT COUNT(DISTINCT ta.student_id)
                        FROM training_attendance ta
                        WHERE ta.training_id = t.training_id AND ta.status IN ('Present', 'Late', 'Excused')
                    ) AS attended_students_count,
                    (
                        SELECT COUNT(DISTINCT ta.student_id)
                        FROM training_attendance ta
                        WHERE ta.training_id = t.training_id AND ta.status = 'Present'
                    ) AS present_students_count
            FROM trainings t
            LEFT JOIN users u ON t.created_by = u.user_id
            LEFT JOIN training_departments td ON t.training_id = td.training_id
            LEFT JOIN departments d ON td.dept_id = d.dept_id
            WHERE $whereClause
            GROUP BY t.training_id
            ORDER BY $sortBy $sortOrder
            LIMIT $perPage OFFSET $offset
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['computed_status'] = $this->computeDynamicStatus($item);
            $eligible = (int)($item['eligible_students_count'] ?? 0);
            $attended = (int)($item['attended_students_count'] ?? 0);
            $item['attendance_percentage'] = $eligible > 0 ? round(($attended / $eligible) * 100, 1) : 0.0;
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }

    /**
     * Get real-time summary statistics for a training program.
     */
    public function getTrainingSummary(int $trainingId, bool $includeDrafts = false): array {
        $stmtEligible = $this->db->prepare("
            SELECT COUNT(DISTINCT s.student_id)
            FROM students s
            JOIN training_departments td ON s.dept_id = td.dept_id
            WHERE td.training_id = ?
        ");
        $stmtEligible->execute([$trainingId]);
        $eligibleCount = (int)$stmtEligible->fetchColumn();

        $statusSql = $includeDrafts
            ? "COALESCE(tad.status, CASE WHEN ta.status IN ('Partial', 'In Progress') THEN 'Pending' ELSE COALESCE(ta.status, 'Pending') END)"
            : "CASE WHEN ta.status IN ('Partial', 'In Progress') THEN 'Pending' ELSE COALESCE(ta.status, 'Pending') END";
        $legacyPendingSql = $includeDrafts
            ? "CASE WHEN tad.status IS NULL AND ta.status IN ('Partial', 'In Progress') THEN 1 ELSE 0 END"
            : "CASE WHEN ta.status IN ('Partial', 'In Progress') THEN 1 ELSE 0 END";
        $stmtAgg = $this->db->prepare("
            SELECT
                SUM(CASE WHEN $statusSql = 'Present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN $statusSql = 'Late' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN $statusSql = 'Excused' THEN 1 ELSE 0 END) AS excused_count,
                SUM(CASE WHEN $statusSql = 'Absent' THEN 1 ELSE 0 END) AS marked_absent_count,
                SUM($legacyPendingSql) AS legacy_pending_count,
                COUNT(*) AS attendance_record_count,
                MAX(COALESCE(tad.updated_at, ta.marked_at)) AS last_marked_at
            FROM students s
            JOIN training_departments td ON td.dept_id = s.dept_id
            LEFT JOIN training_attendance ta ON ta.training_id = td.training_id AND ta.student_id = s.student_id
            LEFT JOIN training_attendance_drafts tad ON tad.training_id = td.training_id AND tad.student_id = s.student_id
            WHERE td.training_id = ?
        ");
        $stmtAgg->execute([$trainingId]);
        $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];

        $presentCount = (int)($agg['present_count'] ?? 0);
        $lateCount = (int)($agg['late_count'] ?? 0);
        $excusedCount = (int)($agg['excused_count'] ?? 0);
        $markedAbsentCount = (int)($agg['marked_absent_count'] ?? 0);
        $legacyPendingCount = (int)($agg['legacy_pending_count'] ?? 0);
        $attendedCount = $presentCount + $lateCount + $excusedCount;
        $markedCount = $attendedCount + $markedAbsentCount;
        $pendingCount = max(0, $eligibleCount - $markedCount);
        $attendancePct = $eligibleCount > 0 ? round(($attendedCount / $eligibleCount) * 100, 1) : 0.0;

        return [
            'eligible_count' => $eligibleCount,
            'present_count' => $presentCount,
            'late_count' => $lateCount,
            'excused_count' => $excusedCount,
            'marked_absent_count' => $markedAbsentCount,
            'pending_count' => $pendingCount,
            'partial_count' => 0,
            'in_progress_count' => $legacyPendingCount,
            'absent_count' => $markedAbsentCount,
            'attended_any_count' => $attendedCount,
            'attendance_percentage' => $attendancePct,
            'currently_engaged_count' => 0,
            'last_activity_at' => $agg['last_marked_at'] ?? null
        ];
    }

    /**
     * Get the trainer-controlled attendance dashboard and complete eligible roster.
     */
    public function getTrainingAttendanceDashboard(int $trainingId, array $filters = [], bool $includeDrafts = false): ?array {
        $training = $this->getTrainingById($trainingId);
        if (!$training) {
            return null;
        }

        $summary = $this->getTrainingSummary($trainingId, $includeDrafts);
        $canonicalStatusSql = "CASE WHEN ta.status IN ('Partial', 'In Progress') THEN 'Pending' ELSE COALESCE(ta.status, 'Pending') END";
        $displayStatusSql = $includeDrafts ? "COALESCE(tad.status, ($canonicalStatusSql))" : $canonicalStatusSql;

        $studentWhere = ['td.training_id = ?'];
        $studentParams = [$trainingId];

        if (!empty($filters['dept_id'])) {
            $studentWhere[] = 's.dept_id = ?';
            $studentParams[] = (int)$filters['dept_id'];
        }

        if (!empty($filters['search'])) {
            $studentWhere[] = '(s.student_name LIKE ? OR s.registration_number LIKE ? OR s.email LIKE ?)';
            $search = '%' . trim($filters['search']) . '%';
            $studentParams[] = $search;
            $studentParams[] = $search;
            $studentParams[] = $search;
        }

        if (!empty($filters['status'])) {
            $statusFilter = trim($filters['status']);
            if ($statusFilter === 'Pending') {
                $studentWhere[] = "($displayStatusSql = 'Pending')";
            } elseif (in_array($statusFilter, ['Present', 'Absent', 'Late', 'Excused'], true)) {
                $studentWhere[] = "$displayStatusSql = ?";
                $studentParams[] = $statusFilter;
            }
        }

        $studentWhereClause = implode(' AND ', $studentWhere);
        $recordsQuery = "
            SELECT
                s.student_id,
                s.registration_number,
                s.student_name,
                s.email,
                d.dept_code,
                d.dept_name,
                COALESCE(ta.attendance_id, 0) AS attendance_id,
                $displayStatusSql AS attendance_status,
                $canonicalStatusSql AS canonical_attendance_status,
                CASE WHEN tad.draft_id IS NULL THEN 0 ELSE 1 END AS has_draft,
                tad.status AS draft_status,
                tad.note AS draft_note,
                tad.updated_at AS draft_updated_at,
                ta.first_accessed_at,
                ta.last_activity_at,
                COALESCE(ta.total_engagement_seconds, 0) AS total_engagement_seconds,
                ta.attended_at,
                COALESCE(ta.session_count, 0) AS session_count,
                COALESCE(ta.manually_adjusted, 0) AS manually_adjusted,
                ta.adjustment_reason,
                ta.adjusted_at,
                u_adj.username AS adjusted_by_name,
                ta.marked_by,
                ta.marked_at,
                ta.attendance_note,
                0 AS is_currently_active
            FROM students s
            JOIN training_departments td ON s.dept_id = td.dept_id
            JOIN departments d ON s.dept_id = d.dept_id
            LEFT JOIN training_attendance ta ON (ta.training_id = td.training_id AND ta.student_id = s.student_id)
            LEFT JOIN training_attendance_drafts tad ON (tad.training_id = td.training_id AND tad.student_id = s.student_id)
            LEFT JOIN users u_adj ON ta.adjusted_by = u_adj.user_id
            WHERE $studentWhereClause
            ORDER BY
                CASE
                    WHEN $displayStatusSql = 'Present' THEN 1
                    WHEN $displayStatusSql = 'Late' THEN 2
                    WHEN $displayStatusSql = 'Excused' THEN 3
                    WHEN $displayStatusSql = 'Absent' THEN 4
                    ELSE 5
                END,
                s.registration_number ASC
        ";

        $stmtRecords = $this->db->prepare($recordsQuery);
        $stmtRecords->execute($studentParams);
        $records = $stmtRecords->fetchAll(PDO::FETCH_ASSOC);

        return [
            'training' => $training,
            'summary' => $summary,
            'records' => $records
        ];
    }

    /**
     * Get individual session logs for a specific student's attendance.
     */
    public function getAttendanceSessions(int $attendanceId): array {
        $stmt = $this->db->prepare("
            SELECT session_id, session_token, started_at, last_heartbeat_at, ended_at,
                   engagement_seconds, session_status, ip_address, user_agent, created_at
            FROM training_sessions
            WHERE attendance_id = ?
            ORDER BY session_id DESC
        ");
        $stmt->execute([$attendanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Save one trainer change as a draft. Drafts are intentionally kept out
     * of training_attendance until the trainer submits the complete roster.
     */
    public function saveTrainerAttendanceDraft(string $token, int $studentId, string $newStatus, string $note = ''): array {
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        $note = trim($note);

        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }
        if (mb_strlen($note) > 2000) {
            return ['success' => false, 'message' => 'Attendance note cannot exceed 2000 characters.'];
        }

        $training = $this->getTrainingByTrainerToken($token);
        if (!$training) {
            return ['success' => false, 'message' => 'This trainer attendance link is invalid or no longer active.'];
        }
        if (!empty($training['attendance_submitted_at'])) {
            return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
        }

        $trainingId = (int)$training['training_id'];
        $trainerName = trim($training['trainer_name'] ?? '') ?: 'Assigned Trainer';

        try {
            $eligibility = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id = ? LIMIT 1");
            $eligibility->execute([$trainingId, $studentId]);
            if (!$eligibility->fetchColumn()) {
                return ['success' => false, 'message' => 'This student is not eligible for the selected training.'];
            }

            $this->db->beginTransaction();
            $lockTraining = $this->db->prepare("SELECT attendance_submitted_at FROM trainings WHERE training_id = ? FOR UPDATE");
            $lockTraining->execute([$trainingId]);
            if (!empty($lockTraining->fetchColumn())) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
            }

            $stmt = $this->db->prepare("INSERT INTO training_attendance_drafts (training_id, student_id, status, note, drafted_by, drafted_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), drafted_by = VALUES(drafted_by), updated_at = NOW()");
            $stmt->execute([$trainingId, $studentId, $newStatus, $note !== '' ? $note : null, $trainerName]);
            $draftId = (int)$this->db->lastInsertId();
            if ($draftId === 0) {
                $draftStmt = $this->db->prepare("SELECT draft_id FROM training_attendance_drafts WHERE training_id = ? AND student_id = ?");
                $draftStmt->execute([$trainingId, $studentId]);
                $draftId = (int)$draftStmt->fetchColumn();
            }
            $this->db->commit();

            $summary = $this->getTrainingSummary($trainingId, true);
            return [
                'success' => true,
                'draft_id' => $draftId,
                'student_id' => $studentId,
                'status' => $newStatus,
                'drafted_at' => date('M d, h:i A'),
                'summary' => $summary,
                'message' => 'Draft saved. Submit attendance when the roster is complete.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to save attendance draft: ' . $e->getMessage()];
        }
    }

    /**
     * Save one bulk trainer action as drafts without publishing attendance.
     */
    public function bulkSaveTrainerAttendanceDrafts(string $token, array $studentIds, string $newStatus, string $note = ''): array {
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        $note = trim($note);

        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }
        if (mb_strlen($note) > 2000) {
            return ['success' => false, 'message' => 'Attendance note cannot exceed 2000 characters.'];
        }

        $training = $this->getTrainingByTrainerToken($token);
        if (!$training) {
            return ['success' => false, 'message' => 'Trainer attendance link is invalid or expired.'];
        }
        if (!empty($training['attendance_submitted_at'])) {
            return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
        }

        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), fn($id) => $id > 0)));
        if (empty($studentIds)) {
            return ['success' => false, 'message' => 'No valid students selected.'];
        }

        $trainingId = (int)$training['training_id'];
        $trainerName = trim($training['trainer_name'] ?? '') ?: 'Assigned Trainer';

        try {
            $this->db->beginTransaction();
            $lockTraining = $this->db->prepare("SELECT attendance_submitted_at FROM trainings WHERE training_id = ? FOR UPDATE");
            $lockTraining->execute([$trainingId]);
            if (!empty($lockTraining->fetchColumn())) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
            }

            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $eligibility = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id IN ($placeholders)");
            $eligibility->execute(array_merge([$trainingId], $studentIds));
            $validStudentIds = array_map('intval', $eligibility->fetchAll(PDO::FETCH_COLUMN));
            if (empty($validStudentIds)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'None of the selected students are eligible for this training.'];
            }

            $upsert = $this->db->prepare($note !== ''
                ? "INSERT INTO training_attendance_drafts (training_id, student_id, status, note, drafted_by, drafted_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), drafted_by = VALUES(drafted_by), updated_at = NOW()"
                : "INSERT INTO training_attendance_drafts (training_id, student_id, status, drafted_by, drafted_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), drafted_by = VALUES(drafted_by), updated_at = NOW()");
            foreach ($validStudentIds as $sid) {
                $upsert->execute($note !== ''
                    ? [$trainingId, $sid, $newStatus, $note, $trainerName]
                    : [$trainingId, $sid, $newStatus, $trainerName]);
            }
            $this->db->commit();

            return [
                'success' => true,
                'updated_count' => count($validStudentIds),
                'student_ids' => $validStudentIds,
                'status' => $newStatus,
                'summary' => $this->getTrainingSummary($trainingId, true),
                'message' => 'Draft saved for ' . count($validStudentIds) . ' student(s). Submit attendance when the roster is complete.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to save attendance drafts: ' . $e->getMessage()];
        }
    }

    /**
     * Publish all trainer drafts to canonical attendance and lock the link.
     */
    public function submitTrainerAttendance(string $token): array {
        $training = $this->getTrainingByTrainerToken($token);
        if (!$training) {
            return ['success' => false, 'message' => 'This trainer attendance link is invalid or no longer active.'];
        }
        if (!empty($training['attendance_submitted_at'])) {
            return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
        }

        $trainingId = (int)$training['training_id'];
        $trainerName = trim($training['trainer_name'] ?? '') ?: 'Assigned Trainer';

        try {
            $this->db->beginTransaction();
            $lockTraining = $this->db->prepare("SELECT * FROM trainings WHERE training_id = ? FOR UPDATE");
            $lockTraining->execute([$trainingId]);
            $lockedTraining = $lockTraining->fetch(PDO::FETCH_ASSOC);
            if (!$lockedTraining) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Training could not be found.'];
            }
            if (!empty($lockedTraining['attendance_submitted_at'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Attendance has already been submitted and locked.'];
            }

            $eligibleStmt = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ?");
            $eligibleStmt->execute([$trainingId]);
            $eligibleIds = array_fill_keys(array_map('intval', $eligibleStmt->fetchAll(PDO::FETCH_COLUMN)), true);

            $draftStmt = $this->db->prepare("SELECT draft_id, student_id, status, note FROM training_attendance_drafts WHERE training_id = ? ORDER BY draft_id ASC FOR UPDATE");
            $draftStmt->execute([$trainingId]);
            $drafts = $draftStmt->fetchAll(PDO::FETCH_ASSOC);
            $now = date('Y-m-d H:i:s');
            $publishedCount = 0;

            foreach ($drafts as $draft) {
                $studentId = (int)$draft['student_id'];
                if (!isset($eligibleIds[$studentId])) {
                    throw new \RuntimeException('A drafted student is no longer eligible for this training.');
                }

                $newStatus = (string)$draft['status'];
                $note = trim((string)($draft['note'] ?? ''));
                $existingStmt = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
                $existingStmt->execute([$trainingId, $studentId]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $oldStatus = $existing['status'] ?? null;
                $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
                $markedAt = $newStatus === 'Pending' ? null : $now;
                $markedBy = $newStatus === 'Pending' ? null : $trainerName;

                if ($existing) {
                    $update = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 0, adjustment_reason = NULL, adjusted_by = NULL, adjusted_at = NULL, last_activity_at = ? WHERE attendance_id = ?");
                    $update->execute([$newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt, $existing['attendance_id']]);
                    $attendanceId = (int)$existing['attendance_id'];
                } else {
                    $insert = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())");
                    $insert->execute([$trainingId, $studentId, $newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt]);
                    $attendanceId = (int)$this->db->lastInsertId();
                }

                $this->insertAttendanceAudit($trainingId, $studentId, $attendanceId, $oldStatus, $newStatus, 'trainer', null, $trainerName, $note !== '' ? $note : null);
                $publishedCount++;
            }

            $this->db->prepare("DELETE FROM training_attendance_drafts WHERE training_id = ?")->execute([$trainingId]);
            $this->db->prepare("UPDATE trainings SET attendance_submitted_at = NOW(), attendance_submitted_by = ? WHERE training_id = ?")->execute([$trainerName, $trainingId]);
            $this->db->commit();

            $submittedAt = date('M d, Y h:i A');
            return [
                'success' => true,
                'submitted_count' => $publishedCount,
                'submitted_at' => $submittedAt,
                'summary' => $this->getTrainingSummary($trainingId),
                'message' => 'Attendance submitted successfully. The trainer roster is now locked.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to submit attendance: ' . $e->getMessage()];
        }
    }

    /**
     * Backward-compatible alias for callers using the original method name.
     */
    public function markTrainerAttendance(string $token, int $studentId, string $newStatus, string $note = ''): array {
        return $this->saveTrainerAttendanceDraft($token, $studentId, $newStatus, $note);
        /*
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        $note = trim($note);

        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }
        if (mb_strlen($note) > 2000) {
            return ['success' => false, 'message' => 'Attendance note cannot exceed 2000 characters.'];
        }

        $training = $this->getTrainingByTrainerToken($token);
        if (!$training) {
            return ['success' => false, 'message' => 'This trainer attendance link is invalid or no longer active.'];
        }

        $trainingId = (int)$training['training_id'];
        $trainerName = trim($training['trainer_name'] ?? '') ?: 'Assigned Trainer';

        try {
            $eligibility = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id = ? LIMIT 1");
            $eligibility->execute([$trainingId, $studentId]);
            if (!$eligibility->fetchColumn()) {
                return ['success' => false, 'message' => 'This student is not eligible for the selected training.'];
            }

            $this->db->beginTransaction();
            $stmtExisting = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
            $stmtExisting->execute([$trainingId, $studentId]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
            $oldStatus = $existing['status'] ?? null;
            $now = date('Y-m-d H:i:s');
            $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
            $markedAt = $newStatus === 'Pending' ? null : $now;
            $markedBy = $newStatus === 'Pending' ? null : $trainerName;

            if ($existing) {
                $stmt = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 0, adjustment_reason = NULL, adjusted_by = NULL, adjusted_at = NULL, last_activity_at = ? WHERE attendance_id = ?");
                $stmt->execute([$newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt, $existing['attendance_id']]);
                $attendanceId = (int)$existing['attendance_id'];
            } else {
                $stmt = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())");
                $stmt->execute([$trainingId, $studentId, $newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt]);
                $attendanceId = (int)$this->db->lastInsertId();
            }

            $this->insertAttendanceAudit($trainingId, $studentId, $attendanceId, $oldStatus, $newStatus, 'trainer', null, $trainerName, $note !== '' ? $note : null);
            $this->db->commit();

            $summary = $this->getTrainingSummary($trainingId);
            return [
                'success' => true,
                'attendance_id' => $attendanceId,
                'student_id' => $studentId,
                'status' => $newStatus,
                'marked_at' => $markedAt ? date('M d, h:i A', strtotime($markedAt)) : 'Not marked',
                'summary' => $summary,
                'message' => 'Attendance saved successfully.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to save attendance: ' . $e->getMessage()];
        }
        */
    }

    /**
     * Mark multiple eligible students' attendance from the trainer link.
     */
    public function bulkMarkTrainerAttendance(string $token, array $studentIds, string $newStatus, string $note = ''): array {
        return $this->bulkSaveTrainerAttendanceDrafts($token, $studentIds, $newStatus, $note);
        /*
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        $note = trim($note);

        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }

        $training = $this->getTrainingByTrainerToken($token);
        if (!$training) {
            return ['success' => false, 'message' => 'Trainer attendance link is invalid or expired.'];
        }

        $trainingId = (int)$training['training_id'];
        $trainerName = trim($training['trainer_name'] ?? '') ?: 'Assigned Trainer';

        $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($id) => $id > 0));
        if (empty($studentIds)) {
            return ['success' => false, 'message' => 'No valid students selected.'];
        }

        try {
            $this->db->beginTransaction();

            $inClause = implode(',', $studentIds);
            $eligibilityStmt = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id IN ($inClause)");
            $eligibilityStmt->execute([$trainingId]);
            $validStudentIds = $eligibilityStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($validStudentIds)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'None of the selected students are eligible for this training.'];
            }

            $now = date('Y-m-d H:i:s');
            $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
            $markedAt = $newStatus === 'Pending' ? null : $now;
            $markedBy = $newStatus === 'Pending' ? null : $trainerName;
            $auditNote = $note !== '' ? $note : "Bulk marked as $newStatus by $trainerName";

            $updatedCount = 0;
            foreach ($validStudentIds as $sid) {
                $sid = (int)$sid;
                $stmtExisting = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
                $stmtExisting->execute([$trainingId, $sid]);
                $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                $oldStatus = $existing['status'] ?? null;

                if ($existing) {
                    $stmt = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 0, adjustment_reason = NULL, adjusted_by = NULL, adjusted_at = NULL, last_activity_at = ? WHERE attendance_id = ?");
                    $stmt->execute([$newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt, $existing['attendance_id']]);
                    $attendanceId = (int)$existing['attendance_id'];
                } else {
                    $stmt = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())");
                    $stmt->execute([$trainingId, $sid, $newStatus, $markedBy, $markedAt, $note !== '' ? $note : null, $attendedAt, $markedAt]);
                    $attendanceId = (int)$this->db->lastInsertId();
                }

                $this->insertAttendanceAudit($trainingId, $sid, $attendanceId, $oldStatus, $newStatus, 'trainer', null, $trainerName, $auditNote);
                $updatedCount++;
            }

            $this->db->commit();

            $summary = $this->getTrainingSummary($trainingId);
            return [
                'success' => true,
                'updated_count' => $updatedCount,
                'student_ids' => $validStudentIds,
                'status' => $newStatus,
                'marked_at' => $markedAt ? date('M d, h:i A', strtotime($markedAt)) : 'Not marked',
                'summary' => $summary,
                'message' => "Successfully marked $updatedCount student(s) as $newStatus."
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to save attendance: ' . $e->getMessage()];
        }
        */
    }

    /**
     * Fast 1-click attendance marking for administrators without requiring full modal inputs.
     */
    public function quickMarkAttendance(int $trainingId, int $studentId, string $newStatus, int $adminUserId, ?string $reason = null): array {
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }

        try {
            $eligibility = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id = ? LIMIT 1");
            $eligibility->execute([$trainingId, $studentId]);
            if (!$eligibility->fetchColumn()) {
                return ['success' => false, 'message' => 'This student is not eligible for this training.'];
            }

            $stmtUser = $this->db->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmtUser->execute([$adminUserId]);
            $adminName = (string)($stmtUser->fetchColumn() ?: 'Administrator');

            $this->db->beginTransaction();
            $stmtExisting = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
            $stmtExisting->execute([$trainingId, $studentId]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
            $oldStatus = $existing['status'] ?? null;
            $now = date('Y-m-d H:i:s');
            $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
            $markedAt = $newStatus === 'Pending' ? null : $now;
            $markedBy = $newStatus === 'Pending' ? null : $adminName;
            $note = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Quick marked by ' . $adminName;

            if ($existing) {
                $stmt = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 1, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW(), last_activity_at = ? WHERE attendance_id = ?");
                $stmt->execute([$newStatus, $markedBy, $markedAt, $note, $attendedAt, $note, $adminUserId, $markedAt, $existing['attendance_id']]);
                $attendanceId = (int)$existing['attendance_id'];
            } else {
                $stmt = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, manually_adjusted, adjustment_reason, adjusted_by, adjusted_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), ?, 0, 0, NOW())");
                $stmt->execute([$trainingId, $studentId, $newStatus, $markedBy, $markedAt, $note, $attendedAt, $note, $adminUserId, $markedAt]);
                $attendanceId = (int)$this->db->lastInsertId();
            }

            $this->insertAttendanceAudit($trainingId, $studentId, $attendanceId, $oldStatus, $newStatus, 'admin', $adminUserId, $adminName, $note);
            $this->db->commit();

            $summary = $this->getTrainingSummary($trainingId);
            return [
                'success' => true,
                'attendance_id' => $attendanceId,
                'student_id' => $studentId,
                'status' => $newStatus,
                'marked_at' => $markedAt ? date('M d, h:i A', strtotime($markedAt)) : 'Pending',
                'marked_by' => $markedBy ?: '—',
                'summary' => $summary,
                'message' => 'Attendance updated successfully.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to update attendance: ' . $e->getMessage()];
        }
    }

    /**
     * Bulk mark attendance for multiple students by administrator.
     */
    public function bulkMarkAttendance(int $trainingId, array $studentIds, string $newStatus, int $adminUserId, ?string $reason = null): array {
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused'];
        $newStatus = trim($newStatus);
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }

        $studentIds = array_values(array_filter(array_map('intval', $studentIds), fn($id) => $id > 0));
        if (empty($studentIds)) {
            return ['success' => false, 'message' => 'No valid students selected for bulk marking.'];
        }

        try {
            $stmtUser = $this->db->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmtUser->execute([$adminUserId]);
            $adminName = (string)($stmtUser->fetchColumn() ?: 'Administrator');

            $this->db->beginTransaction();

            $inClause = implode(',', $studentIds);
            $eligibilityStmt = $this->db->prepare("SELECT s.student_id FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id IN ($inClause)");
            $eligibilityStmt->execute([$trainingId]);
            $validStudentIds = $eligibilityStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($validStudentIds)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'None of the selected students are eligible for this training.'];
            }

            $now = date('Y-m-d H:i:s');
            $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
            $markedAt = $newStatus === 'Pending' ? null : $now;
            $markedBy = $newStatus === 'Pending' ? null : $adminName;
            $note = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Bulk marked by ' . $adminName;

            $updatedCount = 0;
            foreach ($validStudentIds as $sid) {
                $sid = (int)$sid;
                $stmtExisting = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
                $stmtExisting->execute([$trainingId, $sid]);
                $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                $oldStatus = $existing['status'] ?? null;

                if ($existing) {
                    $stmt = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 1, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW(), last_activity_at = ? WHERE attendance_id = ?");
                    $stmt->execute([$newStatus, $markedBy, $markedAt, $note, $attendedAt, $note, $adminUserId, $markedAt, $existing['attendance_id']]);
                    $attendanceId = (int)$existing['attendance_id'];
                } else {
                    $stmt = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, manually_adjusted, adjustment_reason, adjusted_by, adjusted_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), ?, 0, 0, NOW())");
                    $stmt->execute([$trainingId, $sid, $newStatus, $markedBy, $markedAt, $note, $attendedAt, $note, $adminUserId, $markedAt]);
                    $attendanceId = (int)$this->db->lastInsertId();
                }

                $this->insertAttendanceAudit($trainingId, $sid, $attendanceId, $oldStatus, $newStatus, 'admin', $adminUserId, $adminName, $note);
                $updatedCount++;
            }

            $this->db->commit();
            logActivity($this->db, $adminUserId, 'Bulk Attendance Update', "Bulk updated $updatedCount students to status '$newStatus' for training ID $trainingId");

            $summary = $this->getTrainingSummary($trainingId);
            return [
                'success' => true,
                'updated_count' => $updatedCount,
                'student_ids' => $validStudentIds,
                'status' => $newStatus,
                'marked_at' => $markedAt ? date('M d, h:i A', strtotime($markedAt)) : 'Pending',
                'marked_by' => $markedBy ?: '—',
                'summary' => $summary,
                'message' => "Successfully marked $updatedCount student(s) as $newStatus."
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Bulk attendance update failed: ' . $e->getMessage()];
        }
    }

    /**
     * Manually adjust student attendance as an admin with an audit trail.
     */
    public function manualAdjustAttendance(int $attendanceId, string $newStatus, string $reason, int $adminUserId, ?int $trainingId = null, ?int $studentId = null): array {
        $allowedStatuses = ['Pending', 'Present', 'Absent', 'Late', 'Excused', 'Partial', 'In Progress'];
        if (in_array($newStatus, ['Partial', 'In Progress'], true)) {
            $newStatus = 'Pending';
        }
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid attendance status selected.'];
        }

        $reason = trim($reason);
        if (empty($reason)) {
            return ['success' => false, 'message' => 'An audit reason for manual correction is required.'];
        }

        try {
            $this->db->beginTransaction();
            $oldStatus = null;

            if ($attendanceId > 0) {
                $stmtExisting = $this->db->prepare("SELECT attendance_id, training_id, student_id, status FROM training_attendance WHERE attendance_id = ? FOR UPDATE");
                $stmtExisting->execute([$attendanceId]);
                $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$existing) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Attendance record not found.'];
                }
                $trainingId = (int)$existing['training_id'];
                $studentId = (int)$existing['student_id'];
                $oldStatus = $existing['status'];
            } elseif ($trainingId > 0 && $studentId > 0) {
                $stmtExisting = $this->db->prepare("SELECT attendance_id, status FROM training_attendance WHERE training_id = ? AND student_id = ? FOR UPDATE");
                $stmtExisting->execute([$trainingId, $studentId]);
                $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                $oldStatus = $existing['status'] ?? null;
                if ($existing) {
                    $attendanceId = (int)$existing['attendance_id'];
                }
            } else {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Training and student are required.'];
            }

            $eligibility = $this->db->prepare("SELECT COUNT(*) FROM students s JOIN training_departments td ON td.dept_id = s.dept_id WHERE td.training_id = ? AND s.student_id = ?");
            $eligibility->execute([$trainingId, $studentId]);
            if ((int)$eligibility->fetchColumn() === 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'This student is not eligible for the selected training.'];
            }

            $stmtUser = $this->db->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmtUser->execute([$adminUserId]);
            $adminName = (string)($stmtUser->fetchColumn() ?: 'Administrator');
            $now = date('Y-m-d H:i:s');
            $attendedAt = $this->isAttendedStatus($newStatus) ? $now : null;
            $markedAt = $newStatus === 'Pending' ? null : $now;
            $markedBy = $newStatus === 'Pending' ? null : $adminName;

            if ($attendanceId > 0) {
                $stmt = $this->db->prepare("UPDATE training_attendance SET status = ?, marked_by = ?, marked_at = ?, attendance_note = ?, attended_at = ?, manually_adjusted = 1, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW(), last_activity_at = ? WHERE attendance_id = ?");
                $stmt->execute([$newStatus, $markedBy, $markedAt, $reason, $attendedAt, $reason, $adminUserId, $markedAt, $attendanceId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO training_attendance (training_id, student_id, status, marked_by, marked_at, attendance_note, attended_at, manually_adjusted, adjustment_reason, adjusted_by, adjusted_at, last_activity_at, total_engagement_seconds, session_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), ?, 0, 0, NOW())");
                $stmt->execute([$trainingId, $studentId, $newStatus, $markedBy, $markedAt, $reason, $attendedAt, $reason, $adminUserId, $markedAt]);
                $attendanceId = (int)$this->db->lastInsertId();
            }

            $this->insertAttendanceAudit($trainingId, $studentId, $attendanceId, $oldStatus, $newStatus, 'admin', $adminUserId, $adminName, $reason);
            $this->db->commit();
            logActivity($this->db, $adminUserId, 'Manual Attendance Adjustment', "Adjusted attendance ID $attendanceId to status '$newStatus'. Reason: $reason");

            $summary = $this->getTrainingSummary($trainingId);
            return [
                'success' => true,
                'attendance_id' => $attendanceId,
                'student_id' => $studentId,
                'status' => $newStatus,
                'marked_at' => $markedAt ? date('M d, h:i A', strtotime($markedAt)) : 'Pending',
                'marked_by' => $markedBy ?: '—',
                'summary' => $summary,
                'message' => 'Attendance status updated successfully.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to adjust attendance: ' . $e->getMessage()];
        }
    }

    /**
     * Generate Microsoft Excel (.xlsx) report for training attendance.
     */
    public function exportAttendanceExcel(int $trainingId, array $filters = [], string $adminUsername = 'admin'): string {
        $data = $this->getTrainingAttendanceDashboard($trainingId, $filters);
        if (!$data) {
            throw new RuntimeException("Training not found.");
        }

        $training = $data['training'];
        $records = $data['records'];

        $scheduleStr = date('Y-m-d h:i A', strtotime($training['start_date_time'])) . ' - ' . date('Y-m-d h:i A', strtotime($training['end_date_time']));
        $venueOrLink = ($training['event_type'] ?? 'Online') === 'Offline' 
            ? ($training['venue_location'] ?? 'Venue not set') 
            : ($training['training_url'] ?? 'External link');

        $headers = [
            'S.No',
            'Registration Number',
            'Student Name',
            'Department',
            'Email',
            'Attendance Status',
            'Marked By',
            'Marked At',
            'Attendance Note / Remarks',
            'Manually Adjusted',
            'Adjusted By',
            'Adjusted At',
            'Adjustment Reason',
            'Training Title',
            'Trainer Name',
            'Event Type',
            'Schedule',
            'Venue / Link'
        ];

        $rows = [$headers];
        $sno = 1;
        foreach ($records as $r) {
            $rows[] = [
                (string)$sno++,
                (string)($r['registration_number'] ?: $r['student_id']),
                (string)($r['student_name'] ?? ''),
                (string)($r['dept_code'] ?? ''),
                (string)($r['email'] ?? ''),
                (string)($r['attendance_status'] ?? 'Pending'),
                (string)($r['marked_by'] ?? '—'),
                !empty($r['marked_at']) ? date('Y-m-d H:i:s', strtotime($r['marked_at'])) : '',
                (string)($r['attendance_note'] ?? ''),
                !empty($r['manually_adjusted']) ? 'Yes' : 'No',
                (string)($r['adjusted_by_name'] ?? ''),
                !empty($r['adjusted_at']) ? date('Y-m-d H:i:s', strtotime($r['adjusted_at'])) : '',
                (string)($r['adjustment_reason'] ?? ''),
                (string)($training['title'] ?? ''),
                (string)($training['trainer_name'] ?? 'N/A'),
                (string)($training['event_type'] ?? 'Online'),
                $scheduleStr,
                (string)$venueOrLink
            ];
        }

        return ExcelHelper::createXlsx($rows, null, 'Attendance');
    }

    /**
     * Generate XML report adhering strictly to specifications.
     */
    public function exportAttendanceXml(int $trainingId, array $filters = [], string $adminUsername = 'admin'): string {
        $data = $this->getTrainingAttendanceDashboard($trainingId, $filters);
        if (!$data) {
            throw new RuntimeException("Training not found.");
        }

        $training = $data['training'];
        $summary = $data['summary'];
        $records = $data['records'];

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('attendanceReport');

        // <training>
        $xml->startElement('training');
        $xml->writeElement('id', (string)$training['training_id']);
        $xml->writeElement('title', $training['title']);
        $xml->writeElement('eventType', $training['event_type'] ?? 'Online');
        $xml->writeElement('startDateTime', date('c', strtotime($training['start_date_time'])));
        $xml->writeElement('endDateTime', date('c', strtotime($training['end_date_time'])));
        $xml->writeElement('trainingUrl', $training['training_url'] ?? '');
        $xml->writeElement('venueLocation', $training['venue_location'] ?? '');
        $xml->writeElement('trainerName', $training['trainer_name'] ?? '');
        $xml->writeElement('status', $training['computed_status'] ?? $training['status']);
        $xml->writeElement('departments', $training['departments_display']);
        $xml->endElement(); // </training>

        // <summary>
        $xml->startElement('summary');
        $xml->writeElement('eligibleStudents', (string)$summary['eligible_count']);
        $xml->writeElement('presentStudents', (string)$summary['present_count']);
        $xml->writeElement('lateStudents', (string)$summary['late_count']);
        $xml->writeElement('excusedStudents', (string)$summary['excused_count']);
        $xml->writeElement('markedStudents', (string)($summary['attended_any_count'] + $summary['absent_count']));
        $xml->writeElement('pendingStudents', (string)$summary['pending_count']);
        $xml->writeElement('absentStudents', (string)$summary['absent_count']);
        $xml->writeElement('attendancePercentage', $summary['attendance_percentage'] . '%');
        $xml->writeElement('lastMarkedAt', !empty($summary['last_activity_at']) ? date('c', strtotime($summary['last_activity_at'])) : '');
        $xml->writeElement('exportedAt', date('c'));
        $xml->writeElement('exportedBy', $adminUsername);
        $xml->endElement(); // </summary>

        // <records>
        $xml->startElement('records');
        foreach ($records as $r) {
            $xml->startElement('record');
            $xml->writeElement('studentId', $r['registration_number'] ?: (string)$r['student_id']);
            $xml->writeElement('studentName', $r['student_name']);
            $xml->writeElement('email', $r['email'] ?: '');
            $xml->writeElement('department', $r['dept_code']);
            $xml->writeElement('status', $r['attendance_status']);
            $xml->writeElement('markedBy', $r['marked_by'] ?: '');
            $xml->writeElement('markedAt', !empty($r['marked_at']) ? date('c', strtotime($r['marked_at'])) : '');
            $xml->writeElement('note', $r['attendance_note'] ?: '');
            $xml->writeElement('manuallyAdjusted', $r['manually_adjusted'] ? 'true' : 'false');
            $xml->writeElement('adjustmentReason', $r['adjustment_reason'] ?: '');
            $xml->writeElement('adjustedBy', $r['adjusted_by_name'] ?: '');
            $xml->writeElement('adjustedAt', !empty($r['adjusted_at']) ? date('c', strtotime($r['adjusted_at'])) : '');
            $xml->endElement(); // </record>
        }
        $xml->endElement(); // </records>

        $xml->endElement(); // </attendanceReport>
        $xml->endDocument();

        return $xml->outputMemory();
    }

    // =========================================================================
    // STUDENT TRAINING ACCESS METHODS
    // =========================================================================

    /**
     * Get trainings eligible for a specific student (strictly scoped to their department).
     */
    public function getStudentEligibleTrainings(int $studentId, int $deptId, array $filters = []): array {
        $where = [
            'td.dept_id = ?',
            "t.status != 'Draft'",
            "t.status != 'Archived'"
        ];
        $params = [$deptId, $studentId];

        if (!empty($filters['status'])) {
            $st = $filters['status'];
            if ($st === 'Upcoming') {
                $where[] = 't.start_date_time > NOW()';
            } elseif ($st === 'Active') {
                $where[] = 't.start_date_time <= NOW() AND t.end_date_time >= NOW()';
            } elseif ($st === 'Completed') {
                $where[] = 't.end_date_time < NOW()';
            }
        }

        $whereClause = implode(' AND ', $where);

        $query = "
            SELECT t.*,
                   GROUP_CONCAT(DISTINCT d.dept_code SEPARATOR ', ') AS eligible_departments,
                   ta.attendance_id,
                   CASE
                       WHEN ta.status IN ('Partial', 'In Progress') THEN 'Pending'
                       ELSE COALESCE(ta.status, 'Pending')
                   END AS my_attendance_status,
                   ta.marked_by,
                   ta.marked_at,
                   ta.attendance_note,
                   ta.first_accessed_at,
                   ta.last_activity_at,
                   COALESCE(ta.total_engagement_seconds, 0) AS my_total_engagement_seconds,
                   ta.attended_at
            FROM trainings t
            JOIN training_departments td ON t.training_id = td.training_id
            JOIN departments d ON td.dept_id = d.dept_id
            LEFT JOIN training_attendance ta ON (t.training_id = ta.training_id AND ta.student_id = ?)
            WHERE $whereClause
            GROUP BY t.training_id
            ORDER BY t.start_date_time DESC
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($trainings as &$tr) {
            $tr['computed_status'] = $this->computeDynamicStatus($tr);
        }
        unset($tr);

        return $trainings;
    }

    /**
     * Verify student is authorized to access a specific training.
     */
    public function verifyStudentAccess(int $trainingId, int $studentId): array {
        // Fetch student's department
        $stmtStudent = $this->db->prepare("SELECT student_id, dept_id FROM students WHERE student_id = ?");
        $stmtStudent->execute([$studentId]);
        $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return ['allowed' => false, 'message' => 'Student record not found.'];
        }

        $training = $this->getTrainingById($trainingId);
        if (!$training) {
            return ['allowed' => false, 'message' => 'Training program does not exist.'];
        }

        if ($training['status'] === 'Draft' || $training['status'] === 'Archived') {
            return ['allowed' => false, 'message' => 'This training is currently unavailable.'];
        }

        // Verify department authorization
        $deptIds = $training['department_ids'] ?? [];
        if (!in_array((int)$student['dept_id'], $deptIds, true)) {
            return ['allowed' => false, 'message' => 'You are not eligible for this training program based on your department.'];
        }

        return ['allowed' => true, 'training' => $training, 'student' => $student];
    }

    /**
     * Deprecated compatibility guard: students cannot create attendance through engagement.
     */
    public function startStudentSession(int $trainingId, int $studentId, ?string $clientIp = null, ?string $userAgent = null): array {
        return ['success' => false, 'message' => 'Attendance is controlled by the assigned trainer.'];

        $access = $this->verifyStudentAccess($trainingId, $studentId);
        if (!$access['allowed']) {
            return ['success' => false, 'message' => $access['message']];
        }

        $training = $access['training'];
        $minSeconds = (int)$training['minimum_engagement_seconds'];

        try {
            $this->db->beginTransaction();

            // 1. Get or create attendance summary record
            $stmtAtt = $this->db->prepare("SELECT * FROM training_attendance WHERE training_id = ? AND student_id = ?");
            $stmtAtt->execute([$trainingId, $studentId]);
            $attendance = $stmtAtt->fetch(PDO::FETCH_ASSOC);

            $initialStatus = 'In Progress';
            $attendedAt = null;

            // Attendance rule: If minimumEngagementSeconds is 0, mark Present upon valid access
            if ($minSeconds <= 0) {
                $initialStatus = 'Present';
                $attendedAt = date('Y-m-d H:i:s');
            }

            if (!$attendance) {
                $stmtIns = $this->db->prepare("
                    INSERT INTO training_attendance (
                        training_id, student_id, status, first_accessed_at, last_activity_at,
                        total_engagement_seconds, attended_at, session_count, created_at
                    ) VALUES (?, ?, ?, NOW(), NOW(), 0, ?, 1, NOW())
                ");
                $stmtIns->execute([$trainingId, $studentId, $initialStatus, $attendedAt]);
                $attendanceId = (int)$this->db->lastInsertId();
                $totalSeconds = 0;
                $currentStatus = $initialStatus;
            } else {
                $attendanceId = (int)$attendance['attendance_id'];
                $totalSeconds = (int)$attendance['total_engagement_seconds'];
                $currentStatus = $attendance['status'];

                // If already Present, keep it. Otherwise, if minSeconds <= 0, mark Present.
                if ($currentStatus !== 'Present' && $minSeconds <= 0) {
                    $currentStatus = 'Present';
                    $attendedAt = date('Y-m-d H:i:s');
                }

                $stmtUpd = $this->db->prepare("
                    UPDATE training_attendance SET
                        status = ?,
                        attended_at = COALESCE(attended_at, ?),
                        last_activity_at = NOW(),
                        session_count = session_count + 1
                    WHERE attendance_id = ?
                ");
                $stmtUpd->execute([$currentStatus, $attendedAt, $attendanceId]);
            }

            // 2. Terminate any stale active sessions for this attendance
            $this->db->prepare("
                UPDATE training_sessions SET
                    session_status = 'abandoned',
                    ended_at = last_heartbeat_at
                WHERE attendance_id = ? AND session_status = 'active'
            ")->execute([$attendanceId]);

            // 3. Create fresh training_sessions entry with secure random token
            $sessionToken = bin2hex(random_bytes(32));
            $stmtSess = $this->db->prepare("
                INSERT INTO training_sessions (
                    attendance_id, session_token, started_at, last_heartbeat_at,
                    engagement_seconds, session_status, ip_address, user_agent, created_at
                ) VALUES (?, ?, NOW(), NOW(), 0, 'active', ?, ?, NOW())
            ");
            $stmtSess->execute([
                $attendanceId,
                $sessionToken,
                substr($clientIp ?? '', 0, 45),
                substr($userAgent ?? '', 0, 255)
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'training_url' => $training['training_url'],
                'minimum_engagement_seconds' => $minSeconds,
                'total_engagement_seconds' => $totalSeconds,
                'attendance_status' => $currentStatus,
                'message' => 'Session initialized successfully.'
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Could not initiate training session: ' . $e->getMessage()];
        }
    }

    /**
     * Record a periodic heartbeat from the client.
     * Calculated server-side to prevent tampering or inflated refresh times.
     */
    public function recordHeartbeat(string $sessionToken, int $studentId): array {
        return ['success' => false, 'message' => 'Student engagement heartbeats are no longer used for attendance.'];

        if (empty($sessionToken)) {
            return ['success' => false, 'message' => 'Invalid session token.'];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT ts.*,
                       TIMESTAMPDIFF(SECOND, ts.last_heartbeat_at, NOW()) AS db_elapsed_seconds,
                       ta.training_id, ta.student_id, ta.total_engagement_seconds,
                       ta.status AS attendance_status, ta.manually_adjusted,
                       t.minimum_engagement_seconds
                FROM training_sessions ts
                JOIN training_attendance ta ON ts.attendance_id = ta.attendance_id
                JOIN trainings t ON ta.training_id = t.training_id
                WHERE ts.session_token = ?
            ");
            $stmt->execute([$sessionToken]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Active session not found.'];
            }

            // Verify session belongs to the authenticated student
            if ((int)$session['student_id'] !== $studentId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Unauthorized session owner.'];
            }

            if ($session['session_status'] !== 'active') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Session is no longer active.'];
            }

            // Server-calculated elapsed time since last heartbeat using DB clock
            $elapsed = (int)($session['db_elapsed_seconds'] ?? 0);

            // Guard against idle/stale gaps and repeated bursts:
            // Valid heartbeats usually arrive every 15-30s.
            // If elapsed < 1, delta is 0 (idempotent duplicate rejection).
            // If elapsed > 60, cap at 45s to avoid adding days of fake time after sleep/hibernate.
            $deltaSeconds = 0;
            if ($elapsed >= 1) {
                $deltaSeconds = min($elapsed, 45);
            }

            $newSessionEngagement = (int)$session['engagement_seconds'] + $deltaSeconds;
            $newTotalEngagement = (int)$session['total_engagement_seconds'] + $deltaSeconds;
            $minRequired = (int)$session['minimum_engagement_seconds'];
            $currentStatus = $session['attendance_status'];
            $manuallyAdjusted = (bool)$session['manually_adjusted'];

            // Determine if attendance condition is met
            $attendedAtClause = "";
            $newStatus = $currentStatus;

            // Only auto-upgrade status if not manually overridden by an admin
            if (!$manuallyAdjusted && $currentStatus !== 'Present') {
                if ($minRequired <= 0 || $newTotalEngagement >= $minRequired) {
                    $newStatus = 'Present';
                    $attendedAtClause = ", attended_at = COALESCE(attended_at, NOW())";
                } elseif ($newTotalEngagement > 0 && $currentStatus === 'Absent') {
                    $newStatus = 'In Progress';
                }
            }

            // 1. Update session
            $this->db->prepare("
                UPDATE training_sessions SET
                    last_heartbeat_at = NOW(),
                    engagement_seconds = ?
                WHERE session_id = ?
            ")->execute([$newSessionEngagement, $session['session_id']]);

            // 2. Update attendance summary
            $this->db->prepare("
                UPDATE training_attendance SET
                    total_engagement_seconds = ?,
                    status = ?,
                    last_activity_at = NOW()
                    $attendedAtClause
                WHERE attendance_id = ?
            ")->execute([$newTotalEngagement, $newStatus, $session['attendance_id']]);

            $this->db->commit();

            return [
                'success' => true,
                'engagement_seconds' => $newSessionEngagement,
                'total_engagement_seconds' => $newTotalEngagement,
                'minimum_engagement_seconds' => $minRequired,
                'attendance_status' => $newStatus,
                'is_present' => ($newStatus === 'Present')
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => 'Heartbeat processing error: ' . $e->getMessage()];
        }
    }

    /**
     * End a student training session.
     */
    public function endStudentSession(string $sessionToken, int $studentId): array {
        return ['success' => false, 'message' => 'Student engagement sessions are no longer used for attendance.'];

        if (empty($sessionToken)) {
            return ['success' => false, 'message' => 'Invalid session token.'];
        }

        try {
            // First run one final heartbeat to capture the tail engagement time
            $this->recordHeartbeat($sessionToken, $studentId);

            $stmt = $this->db->prepare("
                UPDATE training_sessions ts
                JOIN training_attendance ta ON ts.attendance_id = ta.attendance_id
                SET ts.session_status = 'completed',
                    ts.ended_at = NOW()
                WHERE ts.session_token = ? AND ta.student_id = ?
            ");
            $stmt->execute([$sessionToken, $studentId]);

            return ['success' => true, 'message' => 'Session closed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to end session: ' . $e->getMessage()];
        }
    }

    /**
     * Format duration in seconds to human readable representation.
     */
    public static function formatDuration(int $seconds): string {
        if ($seconds <= 0) {
            return '0s';
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }
}
