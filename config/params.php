<?php

declare(strict_types=1);

$baseUrl = getenv('BASE_URL') ?: 'http://localhost';

return [
    'version' => '1.0.0',
    'base_url' => $baseUrl,
    // filesystem base for uploaded photos; each album gets its own subdirectory
    'photo_upload_path' => '@webroot/uploads/albums',
    // Upload encoding. These are a published API contract (see the photo upload
    // description in config/openapi.yaml) — change them and the spec must change too.
    'photo_max_width' => 500,
    'photo_max_height' => 500,
    'photo_quality' => 80,
    // Largest accepted upload, in bytes. Also a published contract, and it has
    // to stay at or below `upload_max_filesize` in docker/php/app.ini: past that
    // PHP rejects the file before the form can say anything useful about it.
    'photo_max_upload_bytes' => 10 * 1024 * 1024,
    // the OpenAPI spec served at /docs — the single source of truth for the API
    'openapi_path' => '@app/config/openapi.yaml',
    'default_password' => getenv('DEFAULT_PASSWORD') ?: ''
];
