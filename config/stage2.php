<?php
// MAMCET Placement & Learning Portal - Stage 2 Global Configurations
// Safe configuration settings file. Keys are kept protected server-side.

return [
    // Database schema validation indicator
    'installed' => false,

    // Core AI Provider configurations
    'ai' => [
        'active_provider' => 'gemini', // 'gemini' or 'openai'
        'gemini' => [
            'api_key' => 'AIzaSyBtIc9T5aIjUojDeS4Dg7aB-h9_W-kRwUc',
            'model_name' => 'gemini-1.5-flash',
            'api_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/',
            'temperature' => 0.20,
            'max_tokens' => 2048,
        ],
        'openai' => [
            'api_key' => '',
            'model_name' => 'gpt-4o-mini',
            'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'temperature' => 0.20,
            'max_tokens' => 2048,
        ],
        // Rate limits
        'limits' => [
            'daily_limit_per_student' => 3,
            'monthly_limit_per_student' => 10,
            'cooldown_seconds' => 60
        ]
    ],

    // Email SMTP settings (PHPMailer compatible)
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls', // 'ssl' or 'tls'
        'username' => '',
        'password' => '',
        'sender_name' => 'MAMCET Placement Cell',
        'sender_email' => 'placement@mamcet.org',
        'batch_size' => 25 // 20-50 emails per queue execution
    ],

    // WhatsApp Click-to-Chat / API redirect
    'whatsapp' => [
        'provider' => 'click_to_chat', // 'click_to_chat' or 'cloud_api'
        'webhook_url' => '',
        'api_token' => '',
        'phone_number_id' => '',
        'template_name' => 'placement_opportunity_notice',
        'language_code' => 'en'
    ],

    // Module toggles (Super Admin controlled)
    'modules' => [
        'resume_management' => true,
        'ats_analysis' => true,
        'resume_builder' => true,
        'job_matching' => true,
        'interview_preparation' => true,
        'mock_interview' => true,
        'eligibility_automation' => true,
        'application_tracking' => true,
        'notifications' => true,
        'advanced_reports' => true
    ],

    // Global settings limits
    'resume' => [
        'max_file_size_mb' => 5,
        'allowed_types' => ['pdf', 'docx']
    ]
];
