<?php
// MAMCET Placement & Learning Portal - Gemini API Provider

require_once(__DIR__ . '/AIProviderInterface.php');

class GeminiProvider implements AIProviderInterface {
    private string $apiKey;
    private string $model;
    private string $endpoint;
    private float $temperature;
    private int $maxTokens;

    public function __construct(array $config = []) {
        $key = trim($config['api_key'] ?? '');
        $this->apiKey = $key;
        $rawModel = trim($config['model_name'] ?? 'gemini-3.6-flash');
        // Map legacy/discontinued models to gemini-3.6-flash
        if (empty($rawModel) || strpos($rawModel, '1.5') !== false || strpos($rawModel, '2.5-flash') !== false) {
            $rawModel = 'gemini-3.6-flash';
        }
        $this->model = $rawModel;
        $this->endpoint = $config['api_endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/';
        $this->temperature = (float)($config['temperature'] ?? 0.20);
        $this->maxTokens = (int)($config['max_tokens'] ?? 1024);
    }

    public function getProviderName(): string {
        return 'gemini';
    }

    public function generateText(string $prompt, array $options = []): string {
        return $this->callGemini($prompt, '', false, $options);
    }

    public function generateJson(string $prompt, string $systemInstruction, array $options = []): string {
        return $this->callGemini($prompt, $systemInstruction, true, $options);
    }

    private function callGemini(string $prompt, string $systemInstruction, bool $isJson, array $options): string {
        if (empty($this->apiKey)) {
            throw new Exception("Gemini API key is not configured.");
        }

        $url = rtrim($this->endpoint, '/') . '/' . $this->model . ':generateContent?key=' . urlencode($this->apiKey);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => (float)($options['temperature'] ?? $this->temperature),
                'maxOutputTokens' => (int)($options['max_tokens'] ?? $this->maxTokens)
            ]
        ];

        if (!empty($systemInstruction)) {
            // Note: System instruction payload structure in Gemini API
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        if ($isJson) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $jsonData = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Network Error: " . $error);
        }

        if ($httpCode !== 200) {
            $respData = json_decode($response, true);
            $msg = $respData['error']['message'] ?? 'Unknown API Error';
            throw new Exception("Gemini API HTTP Error ($httpCode): " . $msg);
        }

        $responseData = json_decode($response, true);
        $extractedText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return trim($extractedText);
    }
}
