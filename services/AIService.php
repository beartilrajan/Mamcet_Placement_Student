<?php
// MAMCET Placement & Learning Portal - Central AI Coordinator Service

require_once(__DIR__ . '/AIProviderInterface.php');
require_once(__DIR__ . '/GeminiProvider.php');
require_once(__DIR__ . '/OpenAIProvider.php');

class AIService {
    /**
     * The student quota is intentionally app-wide: every AI provider request
     * made on behalf of a student consumes one of these three monthly calls.
     */
    private const STUDENT_MONTHLY_LIMIT = 3;

    /**
     * Retrieve and initialize the active AI provider based on DB settings or config fallbacks.
     */
    public static function getProvider($db): AIProviderInterface {
        $config = require(__DIR__ . '/../config/stage2.php');
        $activeProvider = $config['ai']['active_provider'];
        
        try {
            // Check database settings first
            $stmt = $db->query("SELECT p.provider_name, s.model_name, s.api_key, s.temperature, s.max_tokens 
                                FROM ai_providers p 
                                JOIN ai_settings s ON p.provider_id = s.provider_id 
                                WHERE p.is_active = 1 LIMIT 1");
            $dbActive = $stmt->fetch();
            
            if ($dbActive && !empty($dbActive['api_key'])) {
                $activeProvider = $dbActive['provider_name'];
                $providerConfig = [
                    'api_key' => $dbActive['api_key'],
                    'model_name' => $dbActive['model_name'],
                    'temperature' => (float)$dbActive['temperature'],
                    'max_tokens' => (int)$dbActive['max_tokens']
                ];
                if ($activeProvider === 'gemini') {
                    $providerConfig['api_endpoint'] = $config['ai']['gemini']['api_endpoint'];
                    return new GeminiProvider($providerConfig);
                } elseif ($activeProvider === 'openai') {
                    $providerConfig['api_endpoint'] = $config['ai']['openai']['api_endpoint'];
                    return new OpenAIProvider($providerConfig);
                }
            }
        } catch (Exception $e) {
            // Silently catch and fallback to file config
        }

        // Fallback to static config file
        if ($activeProvider === 'gemini') {
            return new GeminiProvider($config['ai']['gemini']);
        } else {
            return new OpenAIProvider($config['ai']['openai']);
        }
    }

    /**
     * Compress and clean text input to minimize token payload and API costs.
     */
    public static function compressText(string $text, int $maxChars = 2000): string {
        if (empty($text)) return '';
        // Redact personal contact information
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text);
        $text = preg_replace('/(?:\+?\d{1,3}[- ]?)?\d{10}/', '[REDACTED_PHONE]', $text);
        // Normalize whitespace and collapse multiple blank lines
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . "\n[TRUNCATED_FOR_TOKEN_EFFICIENCY]";
        }
        return $text;
    }

    /**
     * Return the app-wide student AI quota status.
     *
     * The feature argument is retained for callers that already pass it, but
     * it is deliberately not used as a filter. ATS, interview, mock interview,
     * and job matching all share the same three-call monthly allowance.
     */
    public static function checkStudentLimits($db, int $studentId, ?string $feature = null): array {
        $monthlyLimit = self::STUDENT_MONTHLY_LIMIT;
        $dailyLimit = self::STUDENT_MONTHLY_LIMIT;
        $cooldown = 0;

        try {
            $configFile = __DIR__ . '/../config/stage2.php';
            if (file_exists($configFile)) {
                $config = require($configFile);
                $cooldown = max(0, (int)($config['ai']['limits']['cooldown_seconds'] ?? 0));
            }
        } catch (\Throwable $e) {
            // Keep the fixed quota defaults.
        }

        $quotaBase = self::buildQuotaInfo($feature, 0, 0, $monthlyLimit, $dailyLimit);

        // A null/zero student ID is used by administrator-only provider tests.
        if ($studentId <= 0) {
            return $quotaBase;
        }

        try {
            if (!$db) {
                throw new RuntimeException('No database connection is available.');
            }

            // No request_type condition here: this is intentionally app-wide.
            $stmtDaily = $db->prepare("SELECT COUNT(*) FROM ai_usage_logs WHERE student_id = ? AND created_at >= CURDATE()");
            $stmtDaily->execute([$studentId]);
            $dailyUsed = (int)$stmtDaily->fetchColumn();

            $stmtMonthly = $db->prepare("SELECT COUNT(*) FROM ai_usage_logs WHERE student_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
            $stmtMonthly->execute([$studentId]);
            $monthlyUsed = (int)$stmtMonthly->fetchColumn();

            $quota = self::buildQuotaInfo($feature, $dailyUsed, $monthlyUsed, $monthlyLimit, $dailyLimit);

            if ($monthlyUsed >= $monthlyLimit) {
                $quota['allowed'] = false;
                $quota['reason'] = self::monthlyLimitReason($quota);
                return $quota;
            }

            if ($cooldown > 0) {
                $stmtLast = $db->query("SELECT UNIX_TIMESTAMP(created_at) FROM ai_usage_logs WHERE student_id = " . (int)$studentId . " ORDER BY log_id DESC LIMIT 1");
                $lastTime = $stmtLast->fetchColumn();
                if ($lastTime) {
                    $diff = time() - (int)$lastTime;
                    if ($diff < $cooldown) {
                        $waitSec = $cooldown - $diff;
                        $quota['allowed'] = false;
                        $quota['reason'] = "Please wait {$waitSec} seconds before making another AI request.";
                        return $quota;
                    }
                }
            }

            return $quota;
        } catch (\Throwable $e) {
            // Never fail open: a quota lookup failure must not allow an
            // unbounded number of outbound provider requests.
            error_log('AI quota check failed: ' . $e->getMessage());
            $quotaBase['allowed'] = false;
            $quotaBase['quota_error'] = true;
            $quotaBase['monthly_used'] = $monthlyLimit;
            $quotaBase['monthly_remaining'] = 0;
            $quotaBase['reason'] = 'AI quota is temporarily unavailable. Please try again shortly.';
            return $quotaBase;
        }
    }

    /**
     * Build a consistent quota payload for page banners and API guards.
     */
    private static function buildQuotaInfo(?string $feature, int $dailyUsed, int $monthlyUsed, int $monthlyLimit, int $dailyLimit): array {
        $resetTimestamp = strtotime('first day of next month 00:00:00');
        $secondsUntilReset = max(0, $resetTimestamp - time());
        $daysLeft = (int)floor($secondsUntilReset / 86400);
        $hoursLeft = (int)floor(($secondsUntilReset % 86400) / 3600);
        $minsLeft = (int)floor(($secondsUntilReset % 3600) / 60);
        $secsLeft = (int)($secondsUntilReset % 60);
        $resetDateFormatted = date('M 1, Y', $resetTimestamp);
        $formattedResetIn = ($daysLeft > 0 ? "{$daysLeft} day" . ($daysLeft > 1 ? 's' : '') . ', ' : '')
            . "{$hoursLeft} hr" . ($hoursLeft > 1 ? 's' : '');

        return [
            'allowed' => true,
            'feature' => $feature,
            'feature_label' => 'Monthly AI',
            'daily_used' => $dailyUsed,
            'daily_limit' => $dailyLimit,
            'daily_remaining' => max(0, $dailyLimit - $dailyUsed),
            'monthly_used' => $monthlyUsed,
            'monthly_limit' => $monthlyLimit,
            'monthly_remaining' => max(0, $monthlyLimit - $monthlyUsed),
            'reset_timestamp' => $resetTimestamp,
            'reset_date_formatted' => $resetDateFormatted,
            'days_until_reset' => $daysLeft,
            'hours_left' => $hoursLeft,
            'mins_left' => $minsLeft,
            'secs_left' => $secsLeft,
            'formatted_reset_in' => $formattedResetIn
        ];
    }

    private static function monthlyLimitReason(array $quota): string {
        return 'You have used all ' . (int)$quota['monthly_limit']
            . ' AI calls available across the app this month. Your quota resets on '
            . $quota['reset_date_formatted'] . ' (in ' . $quota['formatted_reset_in'] . ').';
    }

    /**
     * Atomically reserve one monthly call before contacting an AI provider.
     * Locking the student's row prevents two concurrent requests from both
     * observing the last available slot and exceeding the limit.
     */
    private static function reserveStudentApiCall($db, int $studentId, string $requestType): array {
        $quota = self::checkStudentLimits($db, $studentId);
        if (!$quota['allowed']) {
            return ['allowed' => false, 'reason' => $quota['reason'] ?? 'Monthly AI request limit reached.'];
        }

        $startedTransaction = false;
        try {
            if (!$db) {
                throw new RuntimeException('No database connection is available.');
            }

            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTransaction = true;
            }

            $lockStudent = $db->prepare('SELECT student_id FROM students WHERE student_id = ? FOR UPDATE');
            $lockStudent->execute([$studentId]);
            if (!$lockStudent->fetchColumn()) {
                throw new RuntimeException('Student account could not be verified.');
            }

            // Recount after acquiring the lock. The initial status check is
            // informational; this locked count is the actual enforcement.
            $countMonthly = $db->prepare("SELECT COUNT(*) FROM ai_usage_logs WHERE student_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
            $countMonthly->execute([$studentId]);
            $monthlyUsed = (int)$countMonthly->fetchColumn();

            if ($monthlyUsed >= self::STUDENT_MONTHLY_LIMIT) {
                if ($startedTransaction) {
                    $db->rollBack();
                }
                $lockedQuota = self::buildQuotaInfo(null, 0, $monthlyUsed, self::STUDENT_MONTHLY_LIMIT, self::STUDENT_MONTHLY_LIMIT);
                return ['allowed' => false, 'reason' => self::monthlyLimitReason($lockedQuota)];
            }

            // Insert before the network request so failed requests are still
            // counted as outbound API calls and cannot be retried indefinitely.
            $insert = $db->prepare("INSERT INTO ai_usage_logs (student_id, request_type, prompt_tokens, completion_tokens, model_name, created_at) VALUES (?, ?, 0, 0, 'pending', NOW())");
            $insert->execute([$studentId, $requestType]);
            $logId = (int)$db->lastInsertId();

            if ($startedTransaction) {
                $db->commit();
            }

            return ['allowed' => true, 'log_id' => $logId];
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AI quota reservation failed: ' . $e->getMessage());
            return [
                'allowed' => false,
                'reason' => 'AI quota is temporarily unavailable. Please try again shortly.'
            ];
        }
    }

    /**
     * Fill in metrics for a previously reserved request without changing its
     * quota count.
     */
    private static function finalizeUsage($db, int $logId, int $promptTokens, int $completionTokens, string $modelName): void {
        if ($logId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare('UPDATE ai_usage_logs SET prompt_tokens = ?, completion_tokens = ?, model_name = ? WHERE log_id = ?');
            $stmt->execute([$promptTokens, $completionTokens, $modelName, $logId]);
        } catch (\Throwable $e) {
            // A lost connection after the provider response must not turn a
            // successful AI request into a second outbound request.
            try {
                if (class_exists('Database')) {
                    $freshDb = Database::getInstance()->reconnect();
                    $stmt = $freshDb->prepare('UPDATE ai_usage_logs SET prompt_tokens = ?, completion_tokens = ?, model_name = ? WHERE log_id = ?');
                    $stmt->execute([$promptTokens, $completionTokens, $modelName, $logId]);
                }
            } catch (\Throwable $retryException) {
                error_log('AI usage finalization failed: ' . $retryException->getMessage());
            }
        }
    }

    /**
     * Render a standardized header quota pill badge.
     */
    public static function renderQuotaBadge(array $quotaInfo): string {
        $remaining = (int)($quotaInfo['monthly_remaining'] ?? 0);
        $limit = (int)($quotaInfo['monthly_limit'] ?? self::STUDENT_MONTHLY_LIMIT);
        $resetDate = $quotaInfo['reset_date_formatted'] ?? '';
        $daysLeft = (int)($quotaInfo['days_until_reset'] ?? 0);
        $featureLabel = $quotaInfo['feature_label'] ?? 'AI';

        if (!empty($quotaInfo['quota_error'])) {
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.8rem; font-weight: 600;" title="AI quota could not be verified">'
                 . '<i class="fa-solid fa-triangle-exclamation text-warning flex-shrink-0"></i> AI quota temporarily unavailable'
                 . '</span>';
        }
        
        if ($remaining >= 2) {
            $badgeClass = "bg-success-subtle text-success border border-success border-opacity-25";
            $icon = "fa-solid fa-sparkles text-success";
        } elseif ($remaining === 1) {
            $badgeClass = "bg-warning-subtle text-warning-emphasis border border-warning border-opacity-50";
            $icon = "fa-solid fa-triangle-exclamation text-warning";
        } else {
            $badgeClass = "bg-danger-subtle text-danger border border-danger border-opacity-50";
            $icon = "fa-solid fa-lock text-danger";
        }

        return '<span class="badge ' . $badgeClass . ' px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.8rem; font-weight: 600;" title="Resets on ' . htmlspecialchars($resetDate) . ' (in ' . $daysLeft . ' days)">'
             . '<i class="' . $icon . ' flex-shrink-0"></i> ' . htmlspecialchars($featureLabel) . ' Quota: <strong>' . $remaining . ' / ' . $limit . '</strong> calls left'
             . '</span>';
    }

    /**
     * Render a full quota alert banner or live countdown card when 3/3 calls are used.
     */
    public static function renderQuotaBanner(array $quotaInfo): string {
        $remaining = (int)($quotaInfo['monthly_remaining'] ?? 0);
        $limit = (int)($quotaInfo['monthly_limit'] ?? self::STUDENT_MONTHLY_LIMIT);
        $used = (int)($quotaInfo['monthly_used'] ?? 0);
        $resetTimestamp = (int)($quotaInfo['reset_timestamp'] ?? (time() + 86400));
        $resetDate = $quotaInfo['reset_date_formatted'] ?? '1st of next month';
        $daysLeft = (int)($quotaInfo['days_until_reset'] ?? 0);
        $hoursLeft = (int)($quotaInfo['hours_left'] ?? 0);
        $featureLabel = $quotaInfo['feature_label'] ?? 'Monthly AI';

        if (!empty($quotaInfo['quota_error'])) {
            return '<div class="alert alert-warning py-2 px-3 mb-3 border-0 border-start border-4 border-warning">'
                 . '<i class="fa-solid fa-triangle-exclamation me-2"></i><strong>AI quota unavailable:</strong> '
                 . 'AI requests are temporarily disabled until the monthly quota can be verified. Please try again shortly.'
                 . '</div>';
        }

        // When user has ample calls remaining (> 1), suppress duplicate banner since header badge already shows quota
        if ($remaining > 1) {
            return '';
        }

        // When user has only 1 call left, show low-quota warning
        if ($remaining === 1) {
            return '
            <div class="alert alert-warning py-2 px-3 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2 shadow-sm border-0 border-start border-4 border-warning">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
                    <div class="text-dark">
                        <strong>Low ' . htmlspecialchars($featureLabel) . ' Quota:</strong> You have only <strong>1 API call</strong> remaining this month. 
                        <span class="text-muted small">(Quota resets on ' . htmlspecialchars($resetDate) . ' in ~' . $daysLeft . ' days).</span>
                    </div>
                </div>
                <span class="badge bg-light text-dark border"><i class="fa-solid fa-shield-halved text-warning me-1"></i> ' . $limit . ' Calls / Month Limit</span>
            </div>';
        }

        // 0 Calls remaining - Live ticking countdown card
        return '
        <div class="card border-0 shadow-sm text-white mb-4 overflow-hidden" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-left: 5px solid #ef4444 !important; border-radius: 12px;">
            <div class="card-body p-4">
                <div class="row align-items-center g-3">
                    <div class="col-lg-7">
                        <span class="badge bg-danger mb-2 px-3 py-1 text-uppercase fw-bold"><i class="fa-solid fa-lock me-1"></i> Monthly Limit Reached (' . $limit . '/' . $limit . ' Used)</span>
                        <h5 class="fw-bold mb-1 text-white">' . htmlspecialchars($featureLabel) . ' Limit Reached</h5>
                        <p class="text-white-50 mb-0 small">
                            To minimize API key costs, student accounts are limited to <strong>' . $limit . ' AI requests per month across the app</strong>. Standard offline rule-based scoring and templates remain fully functional. Your AI calls will automatically unlock on <strong>' . htmlspecialchars($resetDate) . '</strong>.
                        </p>
                    </div>
                    <div class="col-lg-5">
                        <div class="bg-black bg-opacity-40 p-3 rounded-3 border border-secondary border-opacity-25 text-center">
                            <div class="small text-warning fw-bold mb-2"><i class="fa-solid fa-clock-rotate-left me-1"></i> Next AI Request Unlocks In:</div>
                            <div class="d-flex justify-content-center align-items-center gap-2 text-white" id="aiQuotaCountdownWidget" data-reset-timestamp="' . $resetTimestamp . '">
                                <div class="text-center px-2 py-1 rounded bg-dark border border-secondary border-opacity-50" style="min-width: 52px;">
                                    <span class="fs-4 fw-bold font-monospace text-warning" id="cdDays">' . sprintf('%02d', $daysLeft) . '</span>
                                    <div class="small text-white-50" style="font-size: 0.65rem;">DAYS</div>
                                </div>
                                <span class="fs-5 fw-bold text-white-50">:</span>
                                <div class="text-center px-2 py-1 rounded bg-dark border border-secondary border-opacity-50" style="min-width: 52px;">
                                    <span class="fs-4 fw-bold font-monospace text-warning" id="cdHours">' . sprintf('%02d', $hoursLeft) . '</span>
                                    <div class="small text-white-50" style="font-size: 0.65rem;">HOURS</div>
                                </div>
                                <span class="fs-5 fw-bold text-white-50">:</span>
                                <div class="text-center px-2 py-1 rounded bg-dark border border-secondary border-opacity-50" style="min-width: 52px;">
                                    <span class="fs-4 fw-bold font-monospace text-warning" id="cdMins">00</span>
                                    <div class="small text-white-50" style="font-size: 0.65rem;">MINS</div>
                                </div>
                                <span class="fs-5 fw-bold text-white-50">:</span>
                                <div class="text-center px-2 py-1 rounded bg-dark border border-secondary border-opacity-50" style="min-width: 52px;">
                                    <span class="fs-4 fw-bold font-monospace text-warning" id="cdSecs">00</span>
                                    <div class="small text-white-50" style="font-size: 0.65rem;">SECS</div>
                                </div>
                            </div>
                            <div class="small text-info-subtle mt-2" style="font-size: 0.75rem;">
                                <i class="fa-regular fa-calendar-check me-1"></i> Resets on <strong>' . htmlspecialchars($resetDate) . ' at 00:00 AM</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function() {
            const container = document.getElementById("aiQuotaCountdownWidget");
            if (!container) return;
            const targetTs = parseInt(container.getAttribute("data-reset-timestamp"), 10) * 1000;
            function updateTimer() {
                const now = new Date().getTime();
                const diff = Math.max(0, targetTs - now);
                const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const secs = Math.floor((diff % (1000 * 60)) / 1000);
                const elD = document.getElementById("cdDays");
                const elH = document.getElementById("cdHours");
                const elM = document.getElementById("cdMins");
                const elS = document.getElementById("cdSecs");
                if (elD) elD.textContent = String(days).padStart(2, "0");
                if (elH) elH.textContent = String(hours).padStart(2, "0");
                if (elM) elM.textContent = String(mins).padStart(2, "0");
                if (elS) elS.textContent = String(secs).padStart(2, "0");
            }
            updateTimer();
            setInterval(updateTimer, 1000);
        })();
        </script>';
    }

    /**
     * Log user query usage metrics.
     */
    public static function logUsage($db, ?int $studentId, string $requestType, int $promptTokens, int $completionTokens, string $modelName): void {
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance()->getConnection();
            }
            if ($db) {
                $stmt = $db->prepare("INSERT INTO ai_usage_logs (student_id, request_type, prompt_tokens, completion_tokens, model_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$studentId, $requestType, $promptTokens, $completionTokens, $modelName]);
            }
        } catch (\Throwable $e) {
            // Silently continue - usage logging should not halt core workflows
        }
    }

    /**
     * Execute an AI request requiring a structured JSON response. Retries once on invalid formats.
     */
    public static function generateStructuredJson($db, ?int $studentId, string $requestType, string $prompt, string $systemInstruction, array $options = []): array {
        $provider = self::getProvider($db);
        $attempts = 0;
        $maxAttempts = 1;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $usageLogId = 0;
            try {
                $attempts++;

                // Reserve immediately before every provider request. This is
                // app-wide, so mock chat loops and final reports consume the
                // same quota as ATS and interview requests.
                if ($studentId !== null && $studentId > 0) {
                    $reservation = self::reserveStudentApiCall($db, $studentId, $requestType);
                    if (!$reservation['allowed']) {
                        throw new Exception($reservation['reason'] ?? 'Monthly AI request limit reached.');
                    }
                    $usageLogId = (int)($reservation['log_id'] ?? 0);
                }
                 
                $response = $provider->generateJson($prompt, $systemInstruction, $options);
                
                // Extract JSON object if enclosed in markdown or explanatory text
                $startPos = strpos($response, '{');
                $endPos = strrpos($response, '}');
                $jsonString = ($startPos !== false && $endPos !== false && $endPos > $startPos) 
                    ? substr($response, $startPos, $endPos - $startPos + 1) 
                    : $response;

                // Strip any residual markdown markers
                $jsonString = preg_replace('/^```(?:json)?\s*/i', '', $jsonString);
                $jsonString = preg_replace('/\s*```$/i', '', $jsonString);
                $jsonString = trim($jsonString);

                // Attempt decoding directly
                $parsedData = json_decode($jsonString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Clean trailing commas before closing braces/brackets
                    $cleanResponse = preg_replace('/,\s*([\]}])/m', '$1', $jsonString);
                    $parsedData = json_decode($cleanResponse, true);
                }
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsedData)) {
                    // The quota row was created before the network request;
                    // update that same row with metrics on success.
                    $promptTokens = ceil(strlen($prompt) / 4);
                    $completionTokens = ceil(strlen($response) / 4);
                    if ($studentId !== null && $studentId > 0) {
                        // Student requests must always have a reservation row;
                        // never create a second row as a fallback.
                        self::finalizeUsage($db, $usageLogId, $promptTokens, $completionTokens, $provider->getProviderName());
                    } else {
                        self::logUsage($db, $studentId, $requestType, $promptTokens, $completionTokens, $provider->getProviderName());
                    }
                    
                    return $parsedData;
                } else {
                    throw new Exception("JSON Decoding Error: " . json_last_error_msg() . "\nRaw Response: " . substr($response, 0, 300));
                }
            } catch (Exception $e) {
                $lastException = $e;
                // Log failed attempt in error log file if needed
                error_log("AIService structured JSON attempt $attempts failed: " . $e->getMessage());
            }
        }

        // Expose user friendly exception with exact cause
        $errorMsg = $lastException ? $lastException->getMessage() : "AI Provider returned an invalid response structure.";
        throw new Exception($errorMsg);
    }
}
