<?php

/**
 * REST API URL rules — single source of truth shared by the web (`web.php`) and
 * test (`test.php`) configs so the route table never drifts between them. Only
 * the `rules` array lives here; each config keeps its own `urlManager` flags
 * (e.g. `enableStrictParsing`).
 */

return [
    'GET,OPTIONS health' => 'health/index',
    'POST,OPTIONS auth/login' => 'auth/login',
    'POST,OPTIONS auth/register' => 'auth/register',
    'POST,OPTIONS auth/refresh' => 'auth/refresh',
    'POST,OPTIONS auth/logout' => 'auth/logout',
    'POST,OPTIONS auth/logout-all' => 'auth/logout-all',
    // photos are nested under their album for listing and creation
    'GET albums/<albumId:\d+>/photos' => 'photos/index',
    'POST albums/<albumId:\d+>/photos' => 'photos/create',
    'OPTIONS albums/<albumId:\d+>/photos' => 'photos/options',
    ['class' => 'yii\rest\UrlRule',
        'controller' => ['users', 'albums'],
        'pluralize' => false,
    ],
    // photos/<id> for view/update/delete only — no flat collection
    ['class' => 'yii\rest\UrlRule',
        'controller' => ['photos'],
        'pluralize' => false,
        'except' => ['index', 'create'],
    ],
];
