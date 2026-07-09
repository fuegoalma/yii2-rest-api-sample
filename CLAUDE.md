# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Yii2 (basic template) REST API for users, albums, and photos. PHP 8.4, MySQL 8, everything runs inside Docker — all PHP/console commands must be executed through `docker-compose exec web` (use the `Makefile` shortcuts below, e.g. `make test` instead of typing the full command). The PHP image is extended at container start (see `docker-compose.yml`) with the **Imagick** extension, used for photo uploads.

**All code MUST follow SOLID, DRY, and KISS.** Non-negotiable for every change: no duplicated logic (extract shared code into base classes/traits/helpers, including tests), depend on interfaces from `models/contract/` rather than concretions, keep each class to a single responsibility, and prefer the simplest design that works — don't add abstractions for hypothetical future needs. When touching existing code that violates these principles, fix the violation rather than extending it.

## Commands

All PHP/console commands run inside the `web` container; use the `Makefile` shortcuts (`make help` lists all of them) instead of typing `docker-compose exec web ...` by hand:

```bash
# First-time setup
./init.sh          # creates .env from .env.example
./setup.sh         # starts Docker, composer install, creates main + test DBs, runs migrations on both

# Migrations (two DBs: main and test — keep both migrated)
make migrate                        # both DBs
make migrate-main                   # main only
make migrate-test                   # test only

# Generate migrations from existing DB schema (bizley/yii2-migration)
make migration-create table=<table>   # or table='*' for all
make migration-update table=<table>   # diff against migration history

# Seed data
make seed                # optional count=N arg, default 10
make seed-clear

# Tests (Codeception, run against TEST_DB_NAME via config/test.php)
make test                                            # all
make test-functional
make test-unit
make test-one suite=functional class=UsersCest                 # one class
make test-one suite=functional class=UsersCest:testMethodName  # one test
make build                # after changing Codeception modules

# Code style (PSR-12, PHP CS Fixer)
make cs-check             # dry-run, shows violations/diff
make cs-fix               # auto-fix
```

The `Makefile` wraps `docker-compose exec -T web ...` (see `make sh` for an interactive shell into the container, which keeps a TTY). Adding a new recurring command should get a `Makefile` target rather than being typed out in full each time.

App is served at http://localhost:8084, phpMyAdmin at http://localhost:8085, MySQL exposed on host port 3307. DB credentials come from `.env` (read via `getenv()` in `config/db.php` / `config/test_db.php`).

## Architecture

Layered flow for every endpoint: **Controller → Form Request → Service → Repository → ActiveRecord model**, with interfaces in `models/contract/` defining the service and repository shapes.

- `controllers/basic/ApiController.php` — abstract base extending `yii\rest\ActiveController`. It sets up CORS/JSON + JWT bearer auth (shared `controllers/basic/ApiControllerTrait.php`), replaces the default REST actions with its own generic `actionIndex/View/Create/Update/Delete` implementations that delegate to `getService()`. Concrete controllers (`UsersController`, `AlbumsController`) only set `$modelClass`, inject their service via constructor promotion (resolved by Yii's DI container), and implement `getService()`. Override an action only when the response differs (e.g. `AlbumsController::actionView` returns an `AlbumViewResponse` DTO).
- `PhotosController` — photos are a **child resource of albums**, so listing and creation are nested (`GET|POST /albums/<albumId>/photos`) while member actions stay flat (`GET|PUT|DELETE /photos/<id>`); there is deliberately no flat `/photos` listing. It overrides `actionIndex(int $albumId)` (delegates to `PhotoService::getByAlbum()`) and `actionCreate(int $albumId)` (grabs the uploaded file via `UploadedFile::getInstanceByName('file')`, then reuses `handleWrite()` with `PhotoService::createInAlbum()`); `view`/`update`/`delete` use the generic base actions. `PhotoService` implements `PhotoServiceInterface` (extends `ApiServiceInterface`) and additionally depends on `AlbumRepository` (to 404 on a missing album) and the `ImageProcessor` component.
- `models/service/` — `readonly` classes implementing `ApiServiceInterface`; business logic and `NotFoundHttpException` on missing records. Validation failures are returned as the model with errors; the base controller converts them to a 422.
- `models/repository/` — implement `ApiRepositoryInterface`; all DB access (queries, `ActiveDataProvider` with pageSize 20, batch inserts for seeding).
- `models/form/` — form requests extending `basic/ApiForm` (a `yii\base\Model`); validate raw body params in the controller before anything reaches the service. Per resource: an abstract base with type/length rules plus `*CreateForm` (adds `required`) and `*UpdateForm` (all optional — partial updates). Only `validatedData()` (attributes actually present in the request) is passed on, so clients can't set server-managed fields (`auth_key`, `access_token`, `password_hash` — clients send plain `password`, hashed in `UserService`). Concrete controllers implement `createForm()`/`updateForm()`.
- `models/db/` — ActiveRecord models (`User`, `Album`, `Photo`). `Photo::$source` (`'seed'` | `'photo'`) records where a file lives so `Photo::getUrl()` can build the right link; the URL logic itself lives in `components/PhotoUrlBuilder` (single source of truth, used by the model and by tests — never hand-concatenate a photo URL).
- `models/dto/` — readonly response DTOs with `fromModel()`/`toArray()`.

**Photo uploads**: `POST /albums/<id>/photos` is `multipart/form-data` with `title` + `file`. `models/form/PhotoCreateForm` accepts only `jpg, jpeg, png, webp, gif, avif` (extension whitelist; `checkExtensionByMimeType` off) and requires an actual uploaded file. `components/ImageProcessor` (Imagick, configured once in `config/di.php` from the `photo_upload_path` param) converts every upload to **WebP quality 80**, scaled to fit **500×500** preserving aspect ratio (never upscaled), and writes it to `web/uploads/albums/<albumId>/<random>.webp`; the real content is validated here (a non-image passing the extension check fails and returns 422). `PhotoService` sets `source = 'photo'`, and cleans up the file on failed validation or on delete. `PhotoUpdateForm` allows changing **only the title** — the album and the stored file are immutable. Seeded photos use `source = 'seed'` and resolve to `web/default-images/`. The web server must be able to write `web/uploads` (`setup.sh` chmods it 777).

**JWT authentication**: every resource endpoint requires `Authorization: Bearer <token>` (`HttpBearerAuth`, attached after the CORS filter in `ApiControllerTrait` with `except => ['options']` so preflights stay public). Tokens are stateless HS256 JWTs issued/validated by `components/JwtService.php` (app component `jwt`; secret/ttl configured once in `config/di.php` from `JWT_SECRET`/`JWT_TTL` env vars — the secret must be ≥ 32 chars, and changing `.env` requires recreating the web container). `User::findIdentityByAccessToken()` resolves the user from the token's `sub` claim; nothing is stored in the DB. The only public endpoint is `POST /auth/login` (`AuthController`, same layered flow: `LoginForm` → `AuthService` / `AuthServiceInterface` → `UserRepository::findByEmail()` → `LoginResponse` DTO), logging in by unique `email` + `password`; bad credentials → 401. Email uniqueness is validated at the form level: `UserCreateForm` does a plain unique check, `UserUpdateForm` gets the record id via constructor (`updateForm(int $id)`) and excludes it from the check.

**Login rate limiting (brute-force protection)**: `components/RateLimiter.php` is a cache-backed `ActionFilter` attached in `AuthController::behaviors()` that throttles login attempts per client IP — max attempts / window configured once in `config/di.php` from `LOGIN_RATE_LIMIT_ATTEMPTS`/`LOGIN_RATE_LIMIT_WINDOW` env vars (defaults 5 / 60s). Every non-OPTIONS request increments the counter and refreshes the window; exceeding the limit → 429 with a `Retry-After` header; a response < 400 (successful login) resets the counter, while failures (401 thrown, 422 set) do not. It uses the `cache` app component (FileCache; `config/test.php` points it at `@runtime/test-cache`, and `BaseCest::_before` flushes it so counters don't leak between tests).

**Health check**: `GET /health` (`HealthController`, public, unauthenticated, no rate limiting — plain `yii\rest\Controller` + `ApiControllerTrait` like `AuthController`) calls `HealthService::check()` (`HealthServiceInterface`), which runs `SELECT 1` against the injected `yii\db\Connection` and returns a `HealthCheckResult` DTO (`{"status": "ok"|"error", "checks": {"database": ...}}`). Since `db` is an app *component* rather than a container-managed class, it can't be wired with `Instance::of()` (that only resolves classes/definitions known to the container) — `config/di.php` binds `HealthService::class` to a closure that pulls `Yii::$app->db` directly. 200 when healthy, 503 otherwise.

**Unified response format**: every response is `{"success": bool, "data": ..., "code": int}`. This is enforced in two places — `components/ApiSerializer.php` (wraps normal REST responses, converts ≥400 statuses to the error shape) and `components/JsonErrorHandler.php` (renders uncaught exceptions in the same shape, with file/line/trace only when `YII_DEBUG`). New endpoints get this automatically; don't hand-build response envelopes.

**Routing**: `yii\rest\UrlRule` in `config/web.php` with `pluralize => false` for `users` and `albums`. Photos use explicit nested rules (`GET|POST|OPTIONS albums/<albumId:\d+>/photos`) plus a `photos` `UrlRule` with `except => ['index', 'create']` so only `/photos/<id>` (view/update/delete) and CORS preflight exist — no flat collection (`GET /photos` → 405). `config/test.php` keeps its own copy of these rules (and overrides `ImageProcessor::$uploadPath` to `@runtime` so test uploads never touch the web root). Adding a resource means: migration, AR model, repository + service (with contracts), create/update form requests, controller extending `ApiController`, and adding the controller to the `urlManager` rule (in **both** `web.php` and `test.php`).

**Console commands** live in `commands/` (namespace `app\commands`), extend `basic/BasicConsoleController` (prints elapsed execution time after each action), and use the same constructor DI pattern as web controllers. `config/console.php` maps `migrate`, `migrate-test` (same `migrations/` path, different DB component), and `migration-creator`.

## Testing Conventions

- Functional tests (`tests/functional/`) extend `BaseCest`, hit real endpoints via REST module, and insert fixtures with `$this->insertRecord('table', [...])` against the test DB. `BaseCest::_before` truncates tables, then creates an auth user (`$this->authUserId`, email `auth.user@example.com`) and sets a Bearer token for every request — user-list pagination totals include this user; call `$I->deleteHeader('Authorization')` to test unauthenticated behavior (see `AuthCest`).
- Unit tests (`tests/unit/`) test services in isolation with PHPUnit mocks of the repositories (and of `ImageProcessor` for `PhotoService`) — no DB (except rules that query during `validate()`, e.g. `User`'s `unique` email and `Photo`'s `album_id` `exist`).
- `PhotosCest` uploads with `$I->sendPost($url, ['title' => ...], ['file' => $path])` and generates image fixtures on the fly with Imagick; it asserts the stored file is WebP and correctly resized. Photo fixtures inserted directly need a `source` value (`'seed'`/`'photo'`).
- `config/test.php` uses strict URL parsing and disables CSRF; test DB config is `config/test_db.php`.
