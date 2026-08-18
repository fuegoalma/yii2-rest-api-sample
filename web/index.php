<?php

declare(strict_types=1);

// Read from the environment, defaulting to production. The Yii2 template ships
// these hard-coded to dev with a comment asking you to remember to change them —
// which is how a production image ends up bootstrapping the debug module that
// `composer install --no-dev` did not install, and failing on every console
// command. A safe default plus `YII_DEBUG`/`YII_ENV` in .env costs nothing and
// cannot be forgotten.
defined('YII_DEBUG') or define('YII_DEBUG', filter_var(getenv('YII_DEBUG') ?: '', FILTER_VALIDATE_BOOL));
defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'prod');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// loading .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
