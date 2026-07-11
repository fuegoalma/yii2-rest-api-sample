<?php

use app\components\ImageProcessor;
use app\components\JwtService;
use app\components\RateLimiter;
use app\controllers\AlbumsController;
use app\controllers\AuthController;
use app\controllers\HealthController;
use app\controllers\PermissionsController;
use app\controllers\PhotosController;
use app\controllers\RolesController;
use app\controllers\UsersController;
use app\models\contract\service\AccessControlInterface;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\repository\RefreshTokenRepository;
use app\models\repository\RoleRepository;
use app\models\repository\UserRepository;
use app\models\service\AccessControlService;
use app\models\service\AlbumService;
use app\models\service\AuthService;
use app\models\service\HealthService;
use app\models\service\PermissionService;
use app\models\service\PhotoService;
use app\models\service\RefreshTokenService;
use app\models\service\RoleService;
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
        // single source of rate-limiting config (brute-force protection on login)
        RateLimiter::class => [
            'class' => RateLimiter::class,
            'maxAttempts' => (int) (getenv('LOGIN_RATE_LIMIT_ATTEMPTS') ?: 5),
            'window' => (int) (getenv('LOGIN_RATE_LIMIT_WINDOW') ?: 60),
        ],
        // permission checks for the current user; concrete repositories autowire
        AccessControlInterface::class => AccessControlService::class,
        UserService::class => [
            '__construct()' => ['repository' => Instance::of(UserRepository::class)],
        ],
        AlbumService::class => [
            '__construct()' => [
                'repository' => Instance::of(AlbumRepository::class),
                'imageProcessor' => Instance::of(ImageProcessor::class),
            ],
        ],
        RoleService::class => [
            '__construct()' => [
                'roles' => Instance::of(RoleRepository::class),
                'access' => Instance::of(AccessControlInterface::class),
            ],
        ],
        PhotoService::class => [
            '__construct()' => [
                'repository' => Instance::of(PhotoRepository::class),
                'albumRepository' => Instance::of(AlbumRepository::class),
                'imageProcessor' => Instance::of(ImageProcessor::class),
            ],
        ],
        // refresh-token lifetime in seconds (single source, from env)
        RefreshTokenService::class => [
            '__construct()' => [
                'repository' => Instance::of(RefreshTokenRepository::class),
                'ttl' => (int) (getenv('JWT_REFRESH_TTL') ?: 2592000),
            ],
        ],
        AuthService::class => [
            '__construct()' => [
                'repository' => Instance::of(UserRepository::class),
                'userService' => Instance::of(UserService::class),
                'refreshTokens' => Instance::of(RefreshTokenService::class),
                'jwt' => Instance::of(JwtService::class),
            ],
        ],
        // 'db' is an app component, not a container definition, so it can't be
        // referenced with Instance::of() (that only resolves container-managed
        // classes) — build the service from the live app component instead
        HealthService::class => static fn () => new HealthService(Yii::$app->db),
        // controllers get positional ($id, $module) args at creation time and the
        // container forbids mixing named and positional keys, so bind by position:
        // index 2 is the $service parameter, index 3 the access control (resource
        // controllers), index 4 an extra service where needed
        UsersController::class => [
            '__construct()' => [
                2 => Instance::of(UserService::class),
                3 => Instance::of(AccessControlInterface::class),
                4 => Instance::of(RoleService::class),
            ],
        ],
        AlbumsController::class => [
            '__construct()' => [
                2 => Instance::of(AlbumService::class),
                3 => Instance::of(AccessControlInterface::class),
            ],
        ],
        PhotosController::class => [
            '__construct()' => [
                2 => Instance::of(PhotoService::class),
                3 => Instance::of(AccessControlInterface::class),
            ],
        ],
        RolesController::class => [
            '__construct()' => [
                2 => Instance::of(RoleService::class),
                3 => Instance::of(AccessControlInterface::class),
            ],
        ],
        PermissionsController::class => [
            '__construct()' => [
                2 => Instance::of(PermissionService::class),
                3 => Instance::of(AccessControlInterface::class),
            ],
        ],
        AuthController::class => [
            '__construct()' => [2 => Instance::of(AuthService::class)],
        ],
        HealthController::class => [
            '__construct()' => [2 => Instance::of(HealthService::class)],
        ],
    ],
];
