<?php

$db = require __DIR__ . '/db.php';
return array_merge($db, [
    'dsn' => sprintf(
        'mysql:host=%s;dbname=%s',
        getenv('DB_HOST') ?: 'localhost',
        getenv('TEST_DB_NAME') ?: ''
    ),
]);
