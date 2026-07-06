<?php

use app\components\ImageProcessor;
use app\components\JwtService;
use app\controllers\AlbumsController;
use app\controllers\AuthController;
use app\controllers\PhotosController;
use app\controllers\UsersController;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\repository\UserRepository;
use app\models\service\AlbumService;
use app\models\service\AuthService;
use app\models\service\PhotoService;
use app\models\service\UserService;
use yii\di\Instance;

$params = require __DIR__ . '/params.php';

/**
 * DI container bindings: which concrete implementation each
 * interface-typed constructor parameter receives.
 */
return [
    'definitions' => [
        // single source of JWT config: both the `jwt` application component
        // and constructor injections resolve through this definition
        JwtService::class => [
            'class' => JwtService::class,
            'secret' => getenv('JWT_SECRET') ?: '',
            'ttl' => (int) (getenv('JWT_TTL') ?: 3600),
        ],
        ImageProcessor::class => [
            'class' => ImageProcessor::class,
            'uploadPath' => $params['photo_upload_path'],
        ],
        UserService::class => [
            '__construct()' => ['repository' => Instance::of(UserRepository::class)],
        ],
        AlbumService::class => [
            '__construct()' => ['repository' => Instance::of(AlbumRepository::class)],
        ],
        PhotoService::class => [
            '__construct()' => [
                'repository' => Instance::of(PhotoRepository::class),
                'albumRepository' => Instance::of(AlbumRepository::class),
                'imageProcessor' => Instance::of(ImageProcessor::class),
            ],
        ],
        AuthService::class => [
            '__construct()' => [
                'repository' => Instance::of(UserRepository::class),
                'jwt' => Instance::of(JwtService::class),
            ],
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
        PhotosController::class => [
            '__construct()' => [2 => Instance::of(PhotoService::class)],
        ],
        AuthController::class => [
            '__construct()' => [2 => Instance::of(AuthService::class)],
        ],
    ],
];
