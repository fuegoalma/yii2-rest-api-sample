<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';
$test_db = require __DIR__ . '/test_db.php';

$config = [
    'id' => 'basic-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [
        'migration-creator' => [
            'class' => 'bizley\migration\controllers\MigrationController',
        ],
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
            'migrationPath' => '@app/migrations',
        ],
        'migrate-test' => [
            'class' => 'yii\console\controllers\MigrateController',
            'db' => 'testDb',
            'migrationPath' => '@app/migrations',
        ],
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
        '@web' => '@app/web',
    ],
    'components' => [
        'urlManager' => [
            'class' => 'yii\web\UrlManager',
            'hostInfo' => $params['base_url'],
            'baseUrl' => '',
            'enablePrettyUrl' => true,
            'showScriptName' => false,
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'testDb' => $test_db,
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    // requires version `2.1.21` of yii2-debug module
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        'allowedIPs' => ['127.0.0.1', '::1', '172.*.*.*', '192.168.*.*'],
    ];
}

return $config;
