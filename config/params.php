<?php
return [
    'version' => '1.0.0',
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'base_url' => getenv('BASE_URL') ?: 'http://localhost',
    'default_password' => getenv('DEFAULT_PASSWORD') ?: ''
];
