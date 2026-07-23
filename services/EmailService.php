<?php
// MAMCET Placement & Learning Portal - SMTP Mailer & Queue Service

class EmailService {
    /**
     * Insert a mail message into the pending delivery queue database.
     */
    public static function queueEmail($db, string $to, string $name, string $subject, string $body): bool {
        try {
            $stmt = $db->prepare("INSERT INTO email_queue (recipient_email, recipient_name, subject, body, status) VALUES (?, ?, ?, ?, 'pending')");
            return $stmt->execute([$to, $name, $subject, $body]);
        } catch (Exception $e) {
            error_log("Failed to queue email to $to: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve pending mails and execute batch dispatch delivery.
     */
    public static function processQueue($db, int $batchSize = 25): array {
        $stmt = $db->prepare("SELECT * FROM email_queue WHERE status = 'pending' AND scheduled_at <= NOW() ORDER BY queue_id ASC LIMIT ?");
        $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $emails = $stmt->fetchAll();

        $sentCount = 0;
        $failedCount = 0;
        $logs = [];

        foreach ($emails as $email) {
            $queueId = $email['queue_id'];
            $to = $email['recipient_email'];
            $name = $email['recipient_name'];
            $subject = $email['subject'];
            $body = $email['body'];
            $attempts = (int)$email['attempts'] + 1;

            // Mark in progress
            $db->prepare("UPDATE email_queue SET status = 'processing', attempts = ? WHERE queue_id = ?")->execute([$attempts, $queueId]);

            $success = self::sendDirectSmtp($to, $name, $subject, $body);

            if ($success) {
                $db->prepare("UPDATE email_queue SET status = 'sent', processed_at = NOW() WHERE queue_id = ?")->execute([$queueId]);
                $db->prepare("INSERT INTO email_logs (recipient_email, subject, status) VALUES (?, ?, 'sent')")->execute([$to, $subject]);
                $sentCount++;
                $logs[] = "Email sent to $to successfully.";
            } else {
                $status = ($attempts >= 3) ? 'failed' : 'pending';
                $err = "SMTP execution failed on attempt $attempts.";
                $db->prepare("UPDATE email_queue SET status = ?, error_message = ? WHERE queue_id = ?")->execute([$status, $err, $queueId]);
                $db->prepare("INSERT INTO email_logs (recipient_email, subject, status, error_message) VALUES (?, ?, 'failed', ?)")->execute([$to, $subject, $err]);
                $failedCount++;
                $logs[] = "Email failed to $to: $err";
            }
        }

        return [
            'processed' => count($emails),
            'sent' => $sentCount,
            'failed' => $failedCount,
            'details' => $logs
        ];
    }

    /**
     * Send direct SMTP email via raw socket connection fsockopen.
     */
    public static function sendDirectSmtp(string $to, string $name, string $subject, string $body): bool {
        // Load configurations
        $config = require(__DIR__ . '/../config/stage2.php');
        $smtp = $config['smtp'] ?? [];

        $host = $smtp['host'] ?? '';
        $port = (int)($smtp['port'] ?? 587);
        $encryption = $smtp['encryption'] ?? 'tls';
        $user = $smtp['username'] ?? '';
        $pass = $smtp['password'] ?? '';
        $fromEmail = $smtp['sender_email'] ?? 'placement@mamcet.org';
        $fromName = $smtp['sender_name'] ?? 'MAMCET Placement Cell';

        // Fallback: If username/host is not configured, try using PHP native mail()
        if (empty($host) || empty($user)) {
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n";
            return @mail($to, $subject, $body, $headers);
        }

        try {
            $socketHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
            $socket = @fsockopen($socketHost, $port, $errno, $errstr, 15);
            if (!$socket) {
                throw new Exception("Socket connection failed: $errstr ($errno)");
            }

            self::readResponse($socket, '220');

            // Say hello to SMTP server
            fwrite($socket, "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n");
            self::readResponse($socket, '250');

            // Handle TLS upgrade
            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                self::readResponse($socket, '220');
                
                // Secure connection
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("TLS negotiation failed.");
                }
                
                // Resend EHLO inside TLS session
                fwrite($socket, "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n");
                self::readResponse($socket, '250');
            }

            // Auth sequence
            if (!empty($user) && !empty($pass)) {
                fwrite($socket, "AUTH LOGIN\r\n");
                self::readResponse($socket, '334');

                fwrite($socket, base64_encode($user) . "\r\n");
                self::readResponse($socket, '334');

                fwrite($socket, base64_encode($pass) . "\r\n");
                self::readResponse($socket, '235');
            }

            // Mail From
            fwrite($socket, "MAIL FROM: <$fromEmail>\r\n");
            self::readResponse($socket, '250');

            // Recipient To
            fwrite($socket, "RCPT TO: <$to>\r\n");
            self::readResponse($socket, '250');

            // Send payload DATA
            fwrite($socket, "DATA\r\n");
            self::readResponse($socket, '354');

            // Format body content
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
            $headers .= "To: =?UTF-8?B?" . base64_encode($name) . "?= <$to>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "\r\n";

            // End data operator is a single dot in its own line
            fwrite($socket, $headers . $body . "\r\n.\r\n");
            self::readResponse($socket, '250');

            // Quit session
            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return true;
        } catch (Exception $e) {
            error_log("SMTP Dispatch Exception: " . $e->getMessage());
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    private static function readResponse($socket, string $expectedCode): void {
        $response = "";
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $code = substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("SMTP protocol error. Expected $expectedCode, got: " . $response);
        }
    }
}
