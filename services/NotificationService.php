<?php
// MAMCET Placement & Learning Portal - Multi-Channel Notification Service

require_once(__DIR__ . '/EmailService.php');

class NotificationService {

    /**
     * Automatically ensure notification tables and required columns exist
     */
    public static function ensureTables($db): void {
        try {
            // 1. Notifications table
            $db->exec("
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `sender_id` INT DEFAULT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `category` VARCHAR(50) DEFAULT 'general',
                    `target_url` VARCHAR(255) DEFAULT NULL,
                    `reference_id` INT DEFAULT NULL,
                    `reference_type` VARCHAR(50) DEFAULT NULL,
                    `channel` VARCHAR(20) DEFAULT 'portal',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_notifications_created` (`created_at`),
                    INDEX `idx_notifications_category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Add missing columns if table already existed with older schema
            $colCat = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'category'")->fetch();
            if (!$colCat) {
                $db->exec("ALTER TABLE `notifications` ADD COLUMN `category` VARCHAR(50) DEFAULT 'general' AFTER `message`");
            }
            $colUrl = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'target_url'")->fetch();
            if (!$colUrl) {
                $db->exec("ALTER TABLE `notifications` ADD COLUMN `target_url` VARCHAR(255) DEFAULT NULL AFTER `category`");
            }
            $colRefId = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'reference_id'")->fetch();
            if (!$colRefId) {
                $db->exec("ALTER TABLE `notifications` ADD COLUMN `reference_id` INT DEFAULT NULL AFTER `target_url`");
            }
            $colRefType = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'reference_type'")->fetch();
            if (!$colRefType) {
                $db->exec("ALTER TABLE `notifications` ADD COLUMN `reference_type` VARCHAR(50) DEFAULT NULL AFTER `reference_id`");
            }

            // 2. Notification Recipients table
            $db->exec("
                CREATE TABLE IF NOT EXISTS `notification_recipients` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `notification_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `is_read` TINYINT(1) DEFAULT 0,
                    `read_at` TIMESTAMP NULL DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_notif_user` (`notification_id`, `user_id`),
                    INDEX `idx_notif_recip_user_read` (`user_id`, `is_read`),
                    FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`notification_id`) ON DELETE CASCADE,
                    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 3. Announcement reads table
            $db->exec("
                CREATE TABLE IF NOT EXISTS `announcement_reads` (
                    `announcement_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `is_read` TINYINT(1) DEFAULT 1,
                    `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`announcement_id`, `user_id`),
                    FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
                    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            error_log("NotificationService::ensureTables error: " . $e->getMessage());
        }
    }

    /**
     * Dispatch an in-portal notification to a group of recipient user IDs.
     */
    public static function sendNotification(
        $db, 
        ?int $senderId, 
        string $title, 
        string $message, 
        array $recipientUserIds, 
        string $category = 'general', 
        ?string $targetUrl = null,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ?int {
        self::ensureTables($db);

        // Sanitize and deduplicate recipient IDs
        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds))));
        if (empty($recipientUserIds)) {
            return null;
        }

        try {
            $db->beginTransaction();

            // 1. Insert notification record
            $stmt = $db->prepare("
                INSERT INTO notifications (sender_id, title, message, category, target_url, reference_id, reference_type, channel) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'portal')
            ");
            $stmt->execute([
                $senderId ?: null, 
                $title, 
                $message, 
                $category, 
                $targetUrl,
                $referenceId,
                $referenceType
            ]);
            $notificationId = (int)$db->lastInsertId();

            // 2. Batch insert recipients
            $stmtRecip = $db->prepare("
                INSERT INTO notification_recipients (notification_id, user_id, is_read) 
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE is_read = 0
            ");
            foreach ($recipientUserIds as $userId) {
                $stmtRecip->execute([$notificationId, $userId]);
            }

            $db->commit();
            return $notificationId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Failed to send notification: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Legacy portal notification wrapper
     */
    public static function sendPortalNotification($db, int $senderId, string $title, string $message, array $recipientUserIds): void {
        self::sendNotification($db, $senderId, $title, $message, $recipientUserIds, 'broadcast', 'student/notifications.php');
    }

    /**
     * Dispatch notification when a College Calendar Event is created or updated
     */
    public static function notifyCalendarEvent($db, int $eventId, ?int $senderId = null): void {
        try {
            self::ensureTables($db);
            $stmt = $db->prepare("SELECT * FROM college_events WHERE event_id = ?");
            $stmt->execute([$eventId]);
            $ev = $stmt->fetch();
            if (!$ev) return;

            // Fetch active students in active session
            $sessionId = (int)($ev['session_id'] ?? 1);
            $stmtStuds = $db->query("
                SELECT user_id FROM students 
                WHERE user_id IS NOT NULL
            ");
            $recipientIds = $stmtStuds->fetchAll(PDO::FETCH_COLUMN);

            if (empty($recipientIds)) return;

            $dateFormatted = date('d M Y, h:i A', strtotime($ev['start_date']));
            $title = "🗓️ College Calendar: " . $ev['title'];
            $loc = !empty($ev['location']) ? " at " . $ev['location'] : "";
            $message = "A new " . strtolower($ev['event_type']) . " '" . $ev['title'] . "' has been scheduled for " . $dateFormatted . $loc . ". View your calendar for schedule details.";

            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $message, 
                $recipientIds, 
                'calendar', 
                'student/calendar.php', 
                $eventId, 
                'college_event'
            );
        } catch (\Throwable $e) {
            error_log("notifyCalendarEvent failed: " . $e->getMessage());
        }
    }

    /**
     * Dispatch notification when a Placement Opportunity is published
     */
    public static function notifyOpportunityPublished($db, int $opportunityId, ?int $senderId = null): void {
        try {
            self::ensureTables($db);
            // Fetch Opportunity & Job details
            $stmt = $db->prepare("
                SELECT o.*, jd.company_name, jd.job_title, jd.job_role, jd.application_deadline, jd.google_form_url
                FROM placement_opportunities o
                JOIN job_descriptions jd ON o.job_id = jd.job_id
                WHERE o.opportunity_id = ?
            ");
            $stmt->execute([$opportunityId]);
            $opp = $stmt->fetch();
            if (!$opp) return;

            // Fetch departments and batches
            $stmtDepts = $db->prepare("SELECT dept_id FROM job_description_departments WHERE job_id = ?");
            $stmtDepts->execute([$opp['job_id']]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            $stmtBatches = $db->prepare("SELECT batch_id FROM job_description_batches WHERE job_id = ?");
            $stmtBatches->execute([$opp['job_id']]);
            $batches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts) || empty($batches)) {
                // If not restricted to specific depts/batches, notify all students
                $stmtStuds = $db->query("SELECT student_id, user_id, student_name, email, mobile_number FROM students WHERE user_id IS NOT NULL");
            } else {
                $inDepts = implode(',', array_map('intval', $depts));
                $inBatches = implode(',', array_map('intval', $batches));
                $stmtStuds = $db->query("
                    SELECT s.student_id, s.user_id, s.student_name, s.email, s.mobile_number 
                    FROM students s 
                    WHERE s.dept_id IN ($inDepts) AND s.batch_id IN ($inBatches) AND s.user_id IS NOT NULL
                ");
            }
            $students = $stmtStuds->fetchAll();
            if (empty($students)) return;

            $title = "💼 New Placement Drive: " . $opp['company_name'];
            $deadlineFormatted = !empty($opp['application_deadline']) ? date('d M Y', strtotime($opp['application_deadline'])) : 'Open';
            
            $portalMsg = "A new recruitment drive for " . $opp['job_title'] . " (" . $opp['job_role'] . ") at " . $opp['company_name'] . " has been published. "
                       . "Deadline to register is " . $deadlineFormatted . ". Please check eligibility and register.";

            $recipientIds = array_column($students, 'user_id');
            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $portalMsg, 
                $recipientIds, 
                'placement', 
                'student/placements.php', 
                $opportunityId, 
                'placement_opportunity'
            );

            // Queue Email Notifications
            foreach ($students as $s) {
                if (empty($s['email'])) continue;
                $emailBody = "<html><body>"
                           . "<h3>Hello " . htmlspecialchars($s['student_name']) . ",</h3>"
                           . "<p>A new placement opportunity has been posted that matches your batch profile:</p>"
                           . "<ul>"
                           . "  <li><strong>Company:</strong> " . htmlspecialchars($opp['company_name']) . "</li>"
                           . "  <li><strong>Position:</strong> " . htmlspecialchars($opp['job_title']) . " (" . htmlspecialchars($opp['job_role']) . ")</li>"
                           . "  <li><strong>Registration Deadline:</strong> " . $deadlineFormatted . "</li>"
                           . "</ul>"
                           . "<p>Log into your student dashboard to verify your automated eligibility status and submit your application.</p>"
                           . "<br><p>Best Regards,<br>MAMCET Placement Cell</p>"
                           . "</body></html>";
                
                EmailService::queueEmail($db, $s['email'], $s['student_name'], $title, $emailBody);
            }
        } catch (\Throwable $e) {
            error_log("notifyOpportunityPublished failed: " . $e->getMessage());
        }
    }

    /**
     * Dispatch notification when a Placement Announcement / Update is published
     */
    public static function notifyAnnouncementPublished($db, int $announcementId, ?int $senderId = null): void {
        try {
            self::ensureTables($db);
            $stmt = $db->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
            $stmt->execute([$announcementId]);
            $ann = $stmt->fetch();
            if (!$ann) return;

            // Fetch target departments and batches
            $stmtDepts = $db->prepare("SELECT dept_id FROM announcement_departments WHERE announcement_id = ?");
            $stmtDepts->execute([$announcementId]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            $stmtBatches = $db->prepare("SELECT batch_id FROM announcement_batches WHERE announcement_id = ?");
            $stmtBatches->execute([$announcementId]);
            $batches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts) || empty($batches)) {
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE user_id IS NOT NULL");
            } else {
                $inDepts = implode(',', array_map('intval', $depts));
                $inBatches = implode(',', array_map('intval', $batches));
                $stmtStuds = $db->query("
                    SELECT user_id FROM students 
                    WHERE dept_id IN ($inDepts) AND batch_id IN ($inBatches) AND user_id IS NOT NULL
                ");
            }
            $recipientIds = $stmtStuds->fetchAll(PDO::FETCH_COLUMN);
            if (empty($recipientIds)) return;

            $prefix = $ann['announcement_type'] !== 'General' ? "📢 " . $ann['announcement_type'] . ": " : "📢 Placement Update: ";
            $title = $prefix . $ann['title'];
            $snippet = mb_strimwidth(strip_tags($ann['description']), 0, 160, '...');
            $targetUrl = 'student/placements.php?announcement_id=' . $announcementId;

            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $snippet, 
                $recipientIds, 
                'placement', 
                $targetUrl, 
                $announcementId, 
                'announcement'
            );
        } catch (\Throwable $e) {
            error_log("notifyAnnouncementPublished failed: " . $e->getMessage());
        }
    }

    /**
     * Dispatch notification when an LMS Course is created or updated
     */
    public static function notifyCoursePublished($db, int $courseId, ?int $senderId = null, string $actionType = 'created'): void {
        try {
            self::ensureTables($db);
            $stmt = $db->prepare("SELECT * FROM courses WHERE course_id = ?");
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course) return;

            // Fetch target departments and batches
            $stmtDepts = $db->prepare("SELECT dept_id FROM course_departments WHERE course_id = ?");
            $stmtDepts->execute([$courseId]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            $stmtBatches = $db->prepare("SELECT batch_id FROM course_batches WHERE course_id = ?");
            $stmtBatches->execute([$courseId]);
            $batches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts) && empty($batches)) {
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE user_id IS NOT NULL");
            } else {
                $conditions = [];
                if (!empty($depts)) {
                    $conditions[] = "dept_id IN (" . implode(',', array_map('intval', $depts)) . ")";
                }
                if (!empty($batches)) {
                    $conditions[] = "batch_id IN (" . implode(',', array_map('intval', $batches)) . ")";
                }
                $where = implode(' OR ', $conditions);
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE ($where) AND user_id IS NOT NULL");
            }
            $recipientIds = $stmtStuds->fetchAll(PDO::FETCH_COLUMN);
            if (empty($recipientIds)) return;

            $actionVerb = $actionType === 'created' ? "New Course Added" : "Course Updated";
            $title = "🎓 Learning Hub: " . $actionVerb . " - " . $course['course_title'];
            $instructorInfo = !empty($course['instructor_name']) ? " by " . $course['instructor_name'] : "";
            $message = "The course '" . $course['course_title'] . "' (" . $course['course_code'] . ")" . $instructorInfo . " is now ready in your Learning Hub. Access lessons, videos, and study notes.";

            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $message, 
                $recipientIds, 
                'learning', 
                'student/learning.php', 
                $courseId, 
                'course'
            );
        } catch (\Throwable $e) {
            error_log("notifyCoursePublished failed: " . $e->getMessage());
        }
    }

    /**
     * Dispatch notification when curriculum (module/lesson) is added inside a course
     */
    public static function notifyCourseCurriculumUpdated($db, int $courseId, string $itemTitle, ?int $senderId = null, string $itemType = 'Module'): void {
        try {
            self::ensureTables($db);
            $stmt = $db->prepare("SELECT * FROM courses WHERE course_id = ?");
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course) return;

            // Fetch assigned students
            $stmtDepts = $db->prepare("SELECT dept_id FROM course_departments WHERE course_id = ?");
            $stmtDepts->execute([$courseId]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            $stmtBatches = $db->prepare("SELECT batch_id FROM course_batches WHERE course_id = ?");
            $stmtBatches->execute([$courseId]);
            $batches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts) && empty($batches)) {
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE user_id IS NOT NULL");
            } else {
                $conditions = [];
                if (!empty($depts)) {
                    $conditions[] = "dept_id IN (" . implode(',', array_map('intval', $depts)) . ")";
                }
                if (!empty($batches)) {
                    $conditions[] = "batch_id IN (" . implode(',', array_map('intval', $batches)) . ")";
                }
                $where = implode(' OR ', $conditions);
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE ($where) AND user_id IS NOT NULL");
            }
            $recipientIds = $stmtStuds->fetchAll(PDO::FETCH_COLUMN);
            if (empty($recipientIds)) return;

            $title = "📚 Learning Hub: New " . $itemType . " Added";
            $message = "New " . strtolower($itemType) . " '" . $itemTitle . "' has been published in course '" . $course['course_title'] . "'.";

            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $message, 
                $recipientIds, 
                'learning', 
                'student/learning.php', 
                $courseId, 
                'course_curriculum'
            );
        } catch (\Throwable $e) {
            error_log("notifyCourseCurriculumUpdated failed: " . $e->getMessage());
        }
    }

    /**
     * Dispatch notification when a Training program is scheduled or updated
     */
    public static function notifyTrainingPublished($db, int $trainingId, ?int $senderId = null): void {
        try {
            self::ensureTables($db);
            $stmt = $db->prepare("SELECT * FROM trainings WHERE training_id = ?");
            $stmt->execute([$trainingId]);
            $tr = $stmt->fetch();
            if (!$tr || $tr['status'] === 'Draft' || $tr['status'] === 'Archived') return;

            // Fetch target departments
            $stmtDepts = $db->prepare("SELECT dept_id FROM training_departments WHERE training_id = ?");
            $stmtDepts->execute([$trainingId]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts)) {
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE user_id IS NOT NULL");
            } else {
                $inDepts = implode(',', array_map('intval', $depts));
                $stmtStuds = $db->query("SELECT user_id FROM students WHERE dept_id IN ($inDepts) AND user_id IS NOT NULL");
            }
            $recipientIds = $stmtStuds->fetchAll(PDO::FETCH_COLUMN);
            if (empty($recipientIds)) return;

            $dateFormatted = date('d M Y, h:i A', strtotime($tr['start_date_time']));
            $loc = ($tr['event_type'] === 'Offline' && !empty($tr['venue_location'])) ? " at " . $tr['venue_location'] : " (" . $tr['event_type'] . ")";
            $trainer = !empty($tr['trainer_name']) ? " conducted by " . $tr['trainer_name'] : "";
            
            $title = "🧑‍🏫 New Training: " . $tr['title'];
            $message = "A training program '" . $tr['title'] . "'" . $trainer . " is scheduled for " . $dateFormatted . $loc . ". View details and track your attendance.";

            self::sendNotification(
                $db, 
                $senderId, 
                $title, 
                $message, 
                $recipientIds, 
                'training', 
                'student/trainings.php', 
                $trainingId, 
                'training'
            );
        } catch (\Throwable $e) {
            error_log("notifyTrainingPublished failed: " . $e->getMessage());
        }
    }

    /**
     * Format time relative to current timestamp
     */
    public static function formatTimeAgo(string $datetime): string {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = max(1, floor($diff / 60));
            return $mins . 'm ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h ago';
        } elseif ($diff < 172800) {
            return 'Yesterday';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd ago';
        } else {
            return date('M d', $timestamp);
        }
    }

    /**
     * Get category visual metadata (icon, badge style, label)
     */
    public static function getCategoryMeta(string $category): array {
        switch (strtolower($category)) {
            case 'calendar':
                return [
                    'label' => 'Calendar',
                    'icon'  => 'fa-solid fa-calendar-days',
                    'badge' => 'bg-info-subtle text-info border border-info-subtle',
                    'icon_bg' => 'bg-info-subtle text-info',
                    'default_url' => 'student/calendar.php'
                ];
            case 'placement':
                return [
                    'label' => 'Placement',
                    'icon'  => 'fa-solid fa-briefcase',
                    'badge' => 'bg-success-subtle text-success border border-success-subtle',
                    'icon_bg' => 'bg-success-subtle text-success',
                    'default_url' => 'student/placements.php'
                ];
            case 'learning':
                return [
                    'label' => 'Learning Hub',
                    'icon'  => 'fa-solid fa-graduation-cap',
                    'badge' => 'bg-primary-subtle text-primary border border-primary-subtle',
                    'icon_bg' => 'bg-primary-subtle text-primary',
                    'default_url' => 'student/learning.php'
                ];
            case 'training':
                return [
                    'label' => 'Training',
                    'icon'  => 'fa-solid fa-chalkboard-user',
                    'badge' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                    'icon_bg' => 'bg-warning-subtle text-warning-emphasis',
                    'default_url' => 'student/trainings.php'
                ];
            case 'broadcast':
                return [
                    'label' => 'Broadcast',
                    'icon'  => 'fa-solid fa-bullhorn',
                    'badge' => 'bg-danger-subtle text-danger border border-danger-subtle',
                    'icon_bg' => 'bg-danger-subtle text-danger',
                    'default_url' => 'student/notifications.php'
                ];
            default:
                return [
                    'label' => 'Update',
                    'icon'  => 'fa-solid fa-bell',
                    'badge' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                    'icon_bg' => 'bg-secondary-subtle text-secondary',
                    'default_url' => 'student/notifications.php'
                ];
        }
    }

    /**
     * Retrieve notifications for a student user with formatted UI metadata
     */
    public static function getStudentNotifications($db, int $userId, int $limit = 20, bool $unreadOnly = false, ?string $category = null): array {
        self::ensureTables($db);

        $where = "nr.user_id = ?";
        $params = [$userId];

        if ($unreadOnly) {
            $where .= " AND nr.is_read = 0";
        }
        if (!empty($category) && $category !== 'all') {
            $where .= " AND n.category = ?";
            $params[] = $category;
        }

        // Get unread count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND is_read = 0");
        $stmtCount->execute([$userId]);
        $unreadCount = (int)$stmtCount->fetchColumn();

        // Fetch notifications
        $sql = "
            SELECT n.notification_id, n.title, n.message, n.category, n.target_url, 
                   n.reference_id, n.reference_type, n.created_at,
                   nr.is_read, nr.read_at
            FROM notification_recipients nr
            JOIN notifications n ON nr.notification_id = n.notification_id
            WHERE $where
            ORDER BY n.created_at DESC
            LIMIT " . (int)$limit . "
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $meta = self::getCategoryMeta($r['category'] ?? 'general');
            $targetUrl = !empty($r['target_url']) ? $r['target_url'] : $meta['default_url'];
            
            $items[] = [
                'notification_id' => (int)$r['notification_id'],
                'title'           => $r['title'],
                'message'         => $r['message'],
                'category'        => $r['category'] ?? 'general',
                'category_label'  => $meta['label'],
                'category_icon'   => $meta['icon'],
                'badge_class'     => $meta['badge'],
                'icon_bg'         => $meta['icon_bg'],
                'target_url'      => $targetUrl,
                'created_at'      => $r['created_at'],
                'time_ago'        => self::formatTimeAgo($r['created_at']),
                'is_read'         => (int)$r['is_read'] === 1,
                'read_at'         => $r['read_at']
            ];
        }

        return [
            'unread_count'  => $unreadCount,
            'notifications' => $items
        ];
    }

    /**
     * Mark single notification or all notifications as read for a user
     */
    public static function markAsRead($db, int $userId, ?int $notificationId = null): bool {
        self::ensureTables($db);
        try {
            if ($notificationId !== null && $notificationId > 0) {
                $stmt = $db->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
                $stmt->execute([$notificationId, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userId]);

                // Also update announcement reads
                $stmtAnn = $db->prepare("
                    INSERT INTO announcement_reads (announcement_id, user_id, is_read, read_at)
                    SELECT a.announcement_id, ?, 1, NOW()
                    FROM announcements a
                    ON DUPLICATE KEY UPDATE is_read = 1, read_at = NOW()
                ");
                $stmtAnn->execute([$userId]);
            }
            return true;
        } catch (\Throwable $e) {
            error_log("NotificationService::markAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prepares WhatsApp click-to-chat redirection link.
     */
    public static function getWhatsAppClickToChat(string $mobile, string $studentName, string $company, string $role, string $deadline, string $applyLink): string {
        $msg = "Hello " . $studentName . ",\n\n"
             . "You are eligible for the " . $role . " opportunity at " . $company . ".\n\n"
             . "Application deadline: " . $deadline . "\n\n"
             . "Apply here:\n" . $applyLink . "\n\n"
             . "Regards,\nMAMCET Placement Cell";

        $cleanPhone = preg_replace('/[^0-9]/', '', $mobile);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }

        return "https://api.whatsapp.com/send?phone=" . urlencode($cleanPhone) . "&text=" . urlencode($msg);
    }
}

