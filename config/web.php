<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'app\\components\\CorrelationIdBootstrap'],
    'container' => require __DIR__ . '/di.php',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => getenv('COOKIE_VALIDATION_KEY') ?: '',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
            // Which hosts may tell us who the client is.
            //
            // `RateLimiter` buckets by client IP, so this decides whether the
            // limit means anything. Empty (the default) means `X-Forwarded-For`
            // is ignored and the address is whoever opened the socket — right
            // for a directly exposed app, and wrong behind a load balancer,
            // where every caller then shares the balancer's address and one
            // brute-force attempt exhausts the budget for everybody.
            //
            // Deployments behind a proxy set TRUSTED_PROXIES to that proxy's
            // address or CIDR. It is deliberately not a wildcard default:
            // trusting the header from anyone lets a caller mint a fresh rate
            // limit per request by rotating it.
            'trustedHosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) getenv('TRUSTED_PROXIES'))
            ))),
        ],
        'response' => [
            'class' => 'yii\web\Response',
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
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
            // the handler itself defaults to false, so a config nobody
            // wrote cannot leak internals; here it follows the environment
            'debugDetail' => YII_DEBUG,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    // structured, on stderr, carrying the correlation id — see
                    // the class docblock for why not a file under runtime/
                    'class' => 'app\components\log\JsonLogTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'enableStrictParsing' => false,
            'showScriptName' => false,
            'rules' => require __DIR__ . '/url_rules.php',
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        'allowedIPs' => ['127.0.0.1', '::1', '172.*.*.*', '192.168.*.*'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        'allowedIPs' => ['127.0.0.1', '::1', '172.*.*.*', '192.168.*.*'],
    ];
}

return $config;
