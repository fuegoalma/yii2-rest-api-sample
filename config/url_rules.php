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
    // the current user: profile, and the caller's roles + permissions for the client UI
    'GET users/me' => 'users/me',
    'OPTIONS users/me' => 'users/options',
    'GET users/me/permissions' => 'users/me-permissions',
    'OPTIONS users/me/permissions' => 'users/options',
    // role assignments are a child resource of users
    'GET users/<id:\d+>/roles' => 'users/roles',
    'PUT users/<id:\d+>/roles' => 'users/set-roles',
    'OPTIONS users/<id:\d+>/roles' => 'users/options',
    // the caller's own albums ("my albums" page)
    'GET albums/my' => 'albums/my',
    'OPTIONS albums/my' => 'albums/options',
    // lifting a pseudo-deletion after review
    'POST albums/<id:\d+>/restore' => 'albums/restore',
    'OPTIONS albums/<id:\d+>/restore' => 'albums/options',
    // photos are nested under their album for listing and creation
    'GET albums/<albumId:\d+>/photos' => 'photos/index',
    'POST albums/<albumId:\d+>/photos' => 'photos/create',
    'OPTIONS albums/<albumId:\d+>/photos' => 'photos/options',
    // the permission catalog is read-only (permissions live in migrations)
    'GET,OPTIONS permissions' => 'permissions/index',
    ['class' => 'yii\rest\UrlRule',
        'controller' => ['users', 'albums', 'roles'],
        'pluralize' => false,
    ],
    // photos/<id> for view/update/delete only — no flat collection
    ['class' => 'yii\rest\UrlRule',
        'controller' => ['photos'],
        'pluralize' => false,
        'except' => ['index', 'create'],
    ],
];
