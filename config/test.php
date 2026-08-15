<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/test_db.php';
$container = require __DIR__ . '/di.php';

// Keep uploaded test images out of the web root. The Flysystem binding in
// config/di.php reads this param when it is first resolved, so overriding the
// param is enough — the binding itself is not restated here.
//
// This must come AFTER the require above: di.php loads params.php into its own
// $params, and `require` runs in the caller's scope, so an override placed
// before it would be silently discarded.
$params['photo_upload_path'] = '@runtime/uploads/albums';

// run background jobs inline in tests, so they don't depend on a running worker.
// This is also what keeps job handlers visible to code coverage — under DbQueue
// they would execute in the worker process instead.
$container['definitions'][\app\models\contract\queue\QueueInterface::class] =
    \app\components\queue\SyncQueue::class;

/**
 * Application configuration shared by all test types
 */
return [
    'id' => 'basic-tests',
    'basePath' => dirname(__DIR__),
    'container' => $container,
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'language' => 'en-US',
    'components' => [
        'db' => $db,
        // backs the login RateLimiter; kept apart from the dev app cache
        'cache' => [
            'class' => \yii\caching\FileCache::class,
            'cachePath' => '@runtime/test-cache',
        ],
        'assetManager' => [
            'basePath' => __DIR__ . '/../web/assets',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => require __DIR__ . '/url_rules.php',
        ],
        'user' => [
            'identityClass' => 'app\models\db\User',
            'enableAutoLogin' => false,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'jwt' => [
            'class' => 'app\components\JwtService',
        ],
        'errorHandler' => [
            'class' => 'app\components\JsonErrorHandler',
        ],
        'request' => [
            'cookieValidationKey' => 'test',
            'enableCsrfValidation' => false,
            // mirror production so JSON request bodies are parsed into bodyParams
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
    ],
    'params' => $params,
];
