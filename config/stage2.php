<?php
return array (
  'installed' => true,
  'ai' => 
  array (
    'active_provider' => 'gemini',
    'gemini' => 
    array (
      'api_key' => '',
      'model_name' => 'gemini-3.6-flash',
      'api_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/',
      'temperature' => 0.2,
      'max_tokens' => 1024,
    ),
    'openai' => 
    array (
      'api_key' => '',
      'model_name' => 'gpt-4o-mini',
      'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
      'temperature' => 0.2,
      'max_tokens' => 1024,
    ),
    'limits' => 
    array (
      'daily_limit_per_student' => 3,
      'monthly_limit_per_student' => 3,
      'cooldown_seconds' => 0,
    ),
  ),
  'smtp' => 
  array (
    'host' => '',
    'port' => 587,
    'encryption' => 'tls',
    'username' => '',
    'password' => '',
    'sender_name' => 'MAMCET Placement & Learning Portal',
    'sender_email' => 'placement@mamcet.org',
    'batch_size' => 25,
  ),
  'whatsapp' => 
  array (
    'provider' => 'click_to_chat',
    'webhook_url' => '',
    'api_token' => '',
    'phone_number_id' => '',
  ),
  'modules' => 
  array (
    'resume_management' => true,
    'ats_analysis' => true,
    'resume_builder' => true,
    'job_matching' => true,
    'interview_preparation' => true,
    'mock_interview' => true,
    'eligibility_automation' => true,
    'application_tracking' => true,
    'notifications' => true,
    'advanced_reports' => true,
  ),
  'resume' => 
  array (
    'max_file_size_mb' => 5,
    'allowed_types' => 
    array (
      0 => 'pdf',
      1 => 'docx',
    ),
  ),
);
