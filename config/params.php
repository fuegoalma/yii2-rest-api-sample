<?php

$baseUrl = getenv('BASE_URL') ?: 'http://localhost';

return [
    'version' => '1.0.0',
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'base_url' => $baseUrl,
    'photo_base_url' => $baseUrl . '/default-images',
    'default_password' => getenv('DEFAULT_PASSWORD') ?: ''
];
