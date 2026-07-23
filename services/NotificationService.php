<?php
// MAMCET Placement & Learning Portal - Multi-Channel Notification Service

require_once(__DIR__ . '/EmailService.php');

class NotificationService {
    /**
     * Dispatch an in-portal notification to a group of recipient user IDs.
     */
    public static function sendPortalNotification($db, int $senderId, string $title, string $message, array $recipientUserIds): void {
        if (empty($recipientUserIds)) return;

        $db->beginTransaction();
        try {
            // 1. Insert notification record
            $stmt = $db->prepare("INSERT INTO notifications (sender_id, title, message, channel) VALUES (?, ?, ?, 'portal')");
            $stmt->execute([$senderId, $title, $message]);
            $notificationId = (int)$db->lastInsertId();

            // 2. Map recipients
            $stmtRecip = $db->prepare("INSERT INTO notification_recipients (notification_id, user_id, is_read) VALUES (?, ?, 0)");
            foreach ($recipientUserIds as $userId) {
                $stmtRecip->execute([$notificationId, (int)$userId]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to send portal notification: " . $e->getMessage());
        }
    }

    /**
     * Dispatch multi-channel notifications when a new Placement Opportunity is published.
     */
    public static function notifyOpportunityPublished($db, int $opportunityId, int $senderId): void {
        try {
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

            // Fetch students matching department and batch filters of the Job Description
            $stmtDepts = $db->prepare("SELECT dept_id FROM job_description_departments WHERE job_id = ?");
            $stmtDepts->execute([$opp['job_id']]);
            $depts = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

            $stmtBatches = $db->prepare("SELECT batch_id FROM job_description_batches WHERE job_id = ?");
            $stmtBatches->execute([$opp['job_id']]);
            $batches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($depts) || empty($batches)) return;

            // Query matching student users
            $inDepts = implode(',', array_map('intval', $depts));
            $inBatches = implode(',', array_map('intval', $batches));

            $stmtStuds = $db->query("
                SELECT s.student_id, s.user_id, s.student_name, s.email, s.mobile_number 
                FROM students s 
                WHERE s.dept_id IN ($inDepts) AND s.batch_id IN ($inBatches) AND s.user_id IS NOT NULL
            ");
            $students = $stmtStuds->fetchAll();

            $title = "New Placement Drive: " . $opp['company_name'];
            $deadlineFormatted = date('d M Y', strtotime($opp['application_deadline']));
            
            $portalMsg = "A new recruitment drive for " . $opp['job_title'] . " at " . $opp['company_name'] . " has been published. "
                       . "Deadline to register is " . $deadlineFormatted . ". Please check eligibility and register.";

            // 1. Send Portal Notification
            $recipientIds = array_column($students, 'user_id');
            self::sendPortalNotification($db, $senderId, $title, $portalMsg, $recipientIds);

            // 2. Queue Email Notifications
            foreach ($students as $s) {
                $emailBody = "<html><body>"
                           . "<h3>Hello " . htmlspecialchars($s['student_name']) . ",</h3>"
                           . "<p>A new placement opportunity has been posted that matches your batch profiles:</p>"
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

        } catch (Exception $e) {
            error_log("notifyOpportunityPublished failed: " . $e->getMessage());
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

        // Clean mobile number (strip non-digits, ensure country code)
        $cleanPhone = preg_replace('/[^0-9]/', '', $mobile);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone; // Default to India prefix
        }

        return "https://api.whatsapp.com/send?phone=" . urlencode($cleanPhone) . "&text=" . urlencode($msg);
    }
}
