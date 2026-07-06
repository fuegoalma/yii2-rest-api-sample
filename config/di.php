<?php

use app\controllers\AlbumsController;
use app\controllers\UsersController;
use app\models\repository\AlbumRepository;
use app\models\repository\UserRepository;
use app\models\service\AlbumService;
use app\models\service\UserService;
use yii\di\Instance;

/**
 * DI container bindings: which concrete implementation each
 * interface-typed constructor parameter receives.
 */
return [
    'definitions' => [
        UserService::class => [
            '__construct()' => ['repository' => Instance::of(UserRepository::class)],
        ],
        AlbumService::class => [
            '__construct()' => ['repository' => Instance::of(AlbumRepository::class)],
        ],
        // controllers get positional ($id, $module) args at creation time and the
        // container forbids mixing named and positional keys, so bind by position:
        // index 2 is the $service parameter
        UsersController::class => [
            '__construct()' => [2 => Instance::of(UserService::class)],
        ],
        AlbumsController::class => [
            '__construct()' => [2 => Instance::of(AlbumService::class)],
        ],
    ],
];
