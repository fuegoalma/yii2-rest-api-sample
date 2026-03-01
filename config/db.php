<?php

return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'mysql:host=%s;dbname=%s',
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_NAME') ?: ''
    ),
    'username' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8',
];
