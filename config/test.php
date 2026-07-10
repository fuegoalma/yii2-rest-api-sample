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
            'rules' => [
                'GET,OPTIONS health' => 'health/index',
                'POST,OPTIONS auth/login' => 'auth/login',
                'POST,OPTIONS auth/register' => 'auth/register',
                'POST,OPTIONS auth/refresh' => 'auth/refresh',
                'POST,OPTIONS auth/logout' => 'auth/logout',
                'POST,OPTIONS auth/logout-all' => 'auth/logout-all',
                'GET albums/<albumId:\d+>/photos' => 'photos/index',
                'POST albums/<albumId:\d+>/photos' => 'photos/create',
                'OPTIONS albums/<albumId:\d+>/photos' => 'photos/options',
                ['class' => 'yii\rest\UrlRule', 'controller' => 'users'],
                ['class' => 'yii\rest\UrlRule', 'controller' => 'albums'],
                ['class' => 'yii\rest\UrlRule', 'controller' => 'photos', 'except' => ['index', 'create']],
            ],
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
        ],
    ],
    'params' => $params,
];
