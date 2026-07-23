<?php
// MAMCET Placement & Learning Portal - OpenAI API Provider

require_once(__DIR__ . '/AIProviderInterface.php');

class OpenAIProvider implements AIProviderInterface {
    private string $apiKey;
    private string $model;
    private string $endpoint;
    private float $temperature;
    private int $maxTokens;

    public function __construct(array $config = []) {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model_name'] ?? 'gpt-4o-mini';
        $this->endpoint = $config['api_endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $this->temperature = (float)($config['temperature'] ?? 0.20);
        $this->maxTokens = (int)($config['max_tokens'] ?? 2048);
    }

    public function getProviderName(): string {
        return 'openai';
    }

    public function generateText(string $prompt, array $options = []): string {
        return $this->callOpenAI($prompt, '', false, $options);
    }

    public function generateJson(string $prompt, string $systemInstruction, array $options = []): string {
        return $this->callOpenAI($prompt, $systemInstruction, true, $options);
    }

    private function callOpenAI(string $prompt, string $systemInstruction, bool $isJson, array $options): string {
        if (empty($this->apiKey)) {
            throw new Exception("OpenAI API key is not configured.");
        }

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemInstruction
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => (float)($options['temperature'] ?? $this->temperature),
            'max_tokens' => (int)($options['max_tokens'] ?? $this->maxTokens)
        ];

        if ($isJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $jsonData = json_encode($payload);

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

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
            throw new Exception("OpenAI API HTTP Error ($httpCode): " . $msg);
        }

        $responseData = json_decode($response, true);
        $extractedText = $responseData['choices'][0]['message']['content'] ?? '';

        return trim($extractedText);
    }
}
