<?php
// MAMCET Placement & Learning Portal - Central AI Coordinator Service

require_once(__DIR__ . '/AIProviderInterface.php');
require_once(__DIR__ . '/GeminiProvider.php');
require_once(__DIR__ . '/OpenAIProvider.php');

class AIService {
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
     * Verify rate limits and cooling periods for a specific student before calling the AI API.
     */
    public static function checkStudentLimits($db, int $studentId): array {
        $config = require(__DIR__ . '/../config/stage2.php');
        $limits = $config['ai']['limits'] ?? ['daily_limit_per_student' => 3, 'monthly_limit_per_student' => 10, 'cooldown_seconds' => 60];
        
        // 1. Cooldown verification (60 seconds)
        $stmtCd = $db->prepare("SELECT created_at FROM ai_usage_logs WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtCd->execute([$studentId]);
        $lastRequestTime = $stmtCd->fetchColumn();
        
        if ($lastRequestTime) {
            $diffSeconds = time() - strtotime($lastRequestTime);
            $cooldown = $limits['cooldown_seconds'] ?? 60;
            if ($diffSeconds < $cooldown) {
                return [
                    'allowed' => false, 
                    'reason' => "Rate Limit: Please wait " . ($cooldown - $diffSeconds) . " seconds before checking again."
                ];
            }
        }

        // 2. Daily limits verification
        $stmtDaily = $db->prepare("SELECT COUNT(*) FROM ai_usage_logs WHERE student_id = ? AND DATE(created_at) = CURDATE()");
        $stmtDaily->execute([$studentId]);
        $dailyCount = (int)$stmtDaily->fetchColumn();
        
        if ($dailyCount >= $limits['daily_limit_per_student']) {
            return [
                'allowed' => false,
                'reason' => "Daily Limit Reached: You have reached the maximum quota of " . $limits['daily_limit_per_student'] . " analyses per day."
            ];
        }

        // 3. Monthly limits verification
        $stmtMonthly = $db->prepare("SELECT COUNT(*) FROM ai_usage_logs WHERE student_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $stmtMonthly->execute([$studentId]);
        $monthlyCount = (int)$stmtMonthly->fetchColumn();

        if ($monthlyCount >= $limits['monthly_limit_per_student']) {
            return [
                'allowed' => false,
                'reason' => "Monthly Limit Reached: You have reached the monthly limit of " . $limits['monthly_limit_per_student'] . " analyses."
            ];
        }

        return [
            'allowed' => true,
            'daily_remaining' => $limits['daily_limit_per_student'] - $dailyCount,
            'monthly_remaining' => $limits['monthly_limit_per_student'] - $monthlyCount
        ];
    }

    /**
     * Log user query usage metrics.
     */
    public static function logUsage($db, ?int $studentId, string $requestType, int $promptTokens, int $completionTokens, string $modelName): void {
        try {
            $stmt = $db->prepare("INSERT INTO ai_usage_logs (student_id, request_type, prompt_tokens, completion_tokens, model_name) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$studentId, $requestType, $promptTokens, $completionTokens, $modelName]);
        } catch (Exception $e) {
            // Silently continue
        }
    }

    /**
     * Execute an AI request requiring a structured JSON response. Retries once on invalid formats.
     */
    public static function generateStructuredJson($db, ?int $studentId, string $requestType, string $prompt, string $systemInstruction): array {
        $provider = self::getProvider($db);
        $attempts = 0;
        $maxAttempts = 2;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                
                // Add strict instructions to system prompt
                $fullSystemInstruction = $systemInstruction . "\nCRITICAL: You MUST return a valid JSON object matching the requested schema. Do not enclose it in markdown blocks like ```json ... ```. Output raw JSON only.";
                
                $response = $provider->generateJson($prompt, $fullSystemInstruction);
                
                // Redact markdown JSON wrappers if returned
                $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
                $response = preg_replace('/\s*```$/i', '', $response);
                $response = trim($response);

                // Clean common LLM JSON syntax errors (like trailing commas before closing braces/brackets)
                $cleanResponse = preg_replace('/,\s*([\]}])/m', '$1', $response);
                $parsedData = json_decode($cleanResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Fallback to original response structure if regex cleaning failed
                    $parsedData = json_decode($response, true);
                }
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Success! Log usage (approximating tokens on character count if API doesn't return them)
                    $promptTokens = ceil(strlen($prompt) / 4);
                    $completionTokens = ceil(strlen($response) / 4);
                    self::logUsage($db, $studentId, $requestType, $promptTokens, $completionTokens, $provider->getProviderName());
                    
                    return $parsedData;
                } else {
                    throw new Exception("JSON Decoding Error: " . json_last_error_msg() . "\nRaw Response: " . $response);
                }
            } catch (Exception $e) {
                $lastException = $e;
                // Log failed attempt in error log file if needed
                error_log("AIService structured JSON attempt $attempts failed: " . $e->getMessage());
            }
        }

        // Expose user friendly exception
        throw new Exception("AI Provider returned an invalid response structure. Please try again in a few moments.");
    }
}
