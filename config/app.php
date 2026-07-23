<?php
// MAMCET Placement & Learning Portal - Application Configuration

// Set error reporting based on environment
$environment = 'development'; // Change to 'production' on deployment

if ($environment === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// App Settings
return [
    'app_name' => 'MAMCET Placement & Learning Portal',
    'environment' => $environment,
    
    // File upload settings
    'upload' => [
        'max_size' => 5 * 1024 * 1024, // 5MB
        'allowed_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ],
        'allowed_images' => [
            'image/jpeg',
            'image/png',
            'image/webp'
        ],
        'allowed_excel' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain'
        ],
        'paths' => [
            'resumes' => __DIR__ . '/../assets/uploads/resumes/',
            'courses' => __DIR__ . '/../assets/uploads/courses/',
            'thumbnails' => __DIR__ . '/../assets/uploads/thumbnails/',
            'attachments' => __DIR__ . '/../assets/uploads/attachments/',
        ]
    ],
    
    // Session configurations
    'session' => [
        'name' => 'mamcet_portal_session',
        'lifetime' => 7200, // 2 hours
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]
];
