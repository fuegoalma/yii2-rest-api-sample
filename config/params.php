<?php

$baseUrl = getenv('BASE_URL') ?: 'http://localhost';

return [
    'version' => '1.0.0',
    'base_url' => $baseUrl,
    // filesystem base for uploaded photos; each album gets its own subdirectory
    'photo_upload_path' => '@webroot/uploads/albums',
    'default_password' => getenv('DEFAULT_PASSWORD') ?: ''
];
