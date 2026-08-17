<?php

declare(strict_types=1);

use app\components\CorrelationId;
use app\components\DbTransactionRunner;
use app\components\image\ImagickWebpEncoder;
use app\components\JwtService;
use app\components\PcntlStopSignal;
use app\components\queue\ContainerJobRunner;
use app\components\queue\DbQueue;
use app\components\RateLimiter;
use app\controllers\AlbumsController;
use app\controllers\AuthController;
use app\controllers\HealthController;
use app\controllers\PermissionsController;
use app\controllers\PhotosController;
use app\controllers\RolesController;
use app\controllers\UsersController;
use app\models\contract\image\ImageEncoderInterface;
use app\models\contract\CorrelationIdInterface;
use app\models\contract\queue\JobRunnerInterface;
use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\AlbumRepositoryInterface;
use app\models\contract\repository\PermissionRepositoryInterface;
use app\models\contract\repository\PhotoRepositoryInterface;
use app\models\contract\repository\RefreshTokenRepositoryInterface;
use app\models\contract\repository\RoleRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\contract\service\AccessControlInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\contract\service\TransactionRunnerInterface;
use app\models\contract\StopSignalInterface;
use app\models\repository\PermissionRepository;
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
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use yii\di\Instance;

$params = require __DIR__ . '/params.php';

/**
 * DI container bindings: which concrete implementation each
 * interface-typed constructor parameter receives.
 */
return [
    // One instance per process: the correlation id is the ambient "which
    // request is this" of the current request or worker pass, so every consumer
    // must see the same object — including after the worker adopts a job's id.
    'singletons' => [
        // An inbound X-Request-Id is honoured (and sanitised) so a caller can
        // correlate from their side; the console has no request to ask, and
        // generates one per invocation.
        // Constructed with a generated id; a web request renews it from the
        // caller's header (CorrelationIdBootstrap) and a queued job renews it
        // from the id stored with the job (DbQueue).
        CorrelationIdInterface::class => CorrelationId::class,
    ],
    'definitions' => [
        // single source of JWT config: both the `jwt` application component
        // and constructor injections resolve through this definition
        JwtService::class => [
            'class' => JwtService::class,
            'secret' => getenv('JWT_SECRET') ?: '',
            'ttl' => (int) (getenv('JWT_TTL') ?: 3600),
        ],
        // Photo storage behind Flysystem: local disk by default. To move uploads
        // to S3, swap this one binding for an AwsS3V3Adapter (see README) — no
        // application code changes, since everything depends on FilesystemOperator.
        //
        // The path is read from the live application params when the binding is
        // first resolved, not from this file's own copy — so an environment that
        // overrides `photo_upload_path` (config/test.php points it at @runtime)
        // is honoured without having to restate this construction.
        FilesystemOperator::class => static fn (): FilesystemOperator => new Filesystem(
            new LocalFilesystemAdapter(Yii::getAlias(Yii::$app->params['photo_upload_path']))
        ),
        // Encoding policy lives in config/params.php, not in code: those numbers
        // are published in config/openapi.yaml, so they get one editable home.
        // ImageStorage itself needs no entry — both its constructor parameters
        // are container-resolvable, so reflection autowires it.
        ImageEncoderInterface::class => [
            'class' => ImagickWebpEncoder::class,
            '__construct()' => [
                'maxWidth' => (int) $params['photo_max_width'],
                'maxHeight' => (int) $params['photo_max_height'],
                'quality' => (int) $params['photo_quality'],
            ],
        ],
        // single source of rate-limiting config (brute-force protection on login)
        RateLimiter::class => [
            'class' => RateLimiter::class,
            'maxAttempts' => (int) (getenv('LOGIN_RATE_LIMIT_ATTEMPTS') ?: 5),
            'window' => (int) (getenv('LOGIN_RATE_LIMIT_WINDOW') ?: 60),
        ],
        // Every repository has its own contract (the generic CRUD shape plus only
        // what its consumers actually call), so a service depends on an interface
        // and the container resolves it by type — no per-service wiring, and no
        // narrowing a too-generic ApiRepositoryInterface back down with a cast.
        AlbumRepositoryInterface::class => AlbumRepository::class,
        PhotoRepositoryInterface::class => PhotoRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        RefreshTokenRepositoryInterface::class => RefreshTokenRepository::class,
        // permission checks for the current user
        AccessControlInterface::class => AccessControlService::class,
        // UserService tears down the user's albums through this contract
        AlbumServiceInterface::class => AlbumService::class,
        // atomic multi-row operations (RBAC mutations, user teardown) run through this
        TransactionRunnerInterface::class => DbTransactionRunner::class,
        // background jobs are deferred to the DB queue, drained by the long-running
        // `worker` service (`yii queue/listen`). Tests override this with SyncQueue
        // (config/test.php) so they don't depend on a running worker.
        QueueInterface::class => DbQueue::class,
        // a job names its handler as a string, so it can only be resolved at run
        // time; isolating that lookup here keeps the drivers free of the container
        JobRunnerInterface::class => static fn (): JobRunnerInterface
            => new ContainerJobRunner(Yii::$container),
        // lets the long-running queue worker (`yii queue/listen`) exit between
        // jobs on SIGTERM instead of being killed mid-job
        StopSignalInterface::class => PcntlStopSignal::class,
        // UserService, AlbumService, PhotoService, RoleService and AuthService
        // need no entry of their own: every constructor parameter is either an
        // interface bound above or a concrete class the container can build, so
        // reflection wires them. Only values that cannot be inferred are stated.
        //
        // refresh-token lifetime in seconds (single source, from env)
        RefreshTokenService::class => [
            '__construct()' => [
                'ttl' => (int) (getenv('JWT_REFRESH_TTL') ?: 2592000),
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
