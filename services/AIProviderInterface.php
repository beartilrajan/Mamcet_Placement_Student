<?php
// MAMCET Placement & Learning Portal - AI Provider Contract Interface

interface AIProviderInterface {
    /**
     * Generate raw text response based on prompts.
     */
    public function generateText(string $prompt, array $options = []): string;

    /**
     * Generate strict structured JSON response.
     */
    public function generateJson(string $prompt, string $systemInstruction, array $options = []): string;
    
    /**
     * Retrieve the provider name identifier.
     */
    public function getProviderName(): string;
}
