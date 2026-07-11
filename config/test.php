<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/test_db.php';

// keep uploaded test images out of the web root
$container = require __DIR__ . '/di.php';
$container['definitions'][\app\components\ImageProcessor::class]['uploadPath'] = '@runtime/uploads/albums';

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
