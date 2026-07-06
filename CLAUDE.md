# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Yii2 (basic template) REST API for users, albums, and photos. PHP 8.4, MySQL 8, everything runs inside Docker — all PHP/console commands must be executed through `docker-compose exec web`.

**All code MUST follow SOLID, DRY, and KISS.** Non-negotiable for every change: no duplicated logic (extract shared code into base classes/traits/helpers, including tests), depend on interfaces from `models/contract/` rather than concretions, keep each class to a single responsibility, and prefer the simplest design that works — don't add abstractions for hypothetical future needs. When touching existing code that violates these principles, fix the violation rather than extending it.

## Commands

```bash
# First-time setup
./init.sh          # creates .env from .env.example
./setup.sh         # starts Docker, composer install, creates main + test DBs, runs migrations on both

# Migrations (two DBs: main and test — keep both migrated)
docker-compose exec web php yii migrate/up --interactive=0
docker-compose exec web php yii migrate-test/up --interactive=0

# Generate migrations from existing DB schema (bizley/yii2-migration)
docker-compose exec web php yii migration-creator/create <table>   # or '*' for all
docker-compose exec web php yii migration-creator/update <table>   # diff against migration history

# Seed data
docker-compose exec web php yii seeder/create   # optional count arg, default 10
docker-compose exec web php yii seeder/clear

# Tests (Codeception, run against TEST_DB_NAME via config/test.php)
docker-compose exec web php vendor/bin/codecept run                # all
docker-compose exec web php vendor/bin/codecept run functional
docker-compose exec web php vendor/bin/codecept run unit
docker-compose exec web php vendor/bin/codecept run functional UsersCest                 # one class
docker-compose exec web php vendor/bin/codecept run functional UsersCest:testMethodName  # one test
docker-compose exec web php vendor/bin/codecept build              # after changing Codeception modules
```

App is served at http://localhost:8084, phpMyAdmin at http://localhost:8085, MySQL exposed on host port 3307. DB credentials come from `.env` (read via `getenv()` in `config/db.php` / `config/test_db.php`).

## Architecture

Layered flow for every endpoint: **Controller → Form Request → Service → Repository → ActiveRecord model**, with interfaces in `models/contract/` defining the service and repository shapes.

- `controllers/basic/ApiController.php` — abstract base extending `yii\rest\ActiveController`. It sets up CORS/JSON + JWT bearer auth (shared `controllers/basic/ApiControllerTrait.php`), replaces the default REST actions with its own generic `actionIndex/View/Create/Update/Delete` implementations that delegate to `getService()`. Concrete controllers (`UsersController`, `AlbumsController`) only set `$modelClass`, inject their service via constructor promotion (resolved by Yii's DI container), and implement `getService()`. Override an action only when the response differs (e.g. `AlbumsController::actionView` returns an `AlbumViewResponse` DTO).
- `models/service/` — `readonly` classes implementing `ApiServiceInterface`; business logic and `NotFoundHttpException` on missing records. Validation failures are returned as the model with errors; the base controller converts them to a 422.
- `models/repository/` — implement `ApiRepositoryInterface`; all DB access (queries, `ActiveDataProvider` with pageSize 20, batch inserts for seeding).
- `models/form/` — form requests extending `basic/ApiForm` (a `yii\base\Model`); validate raw body params in the controller before anything reaches the service. Per resource: an abstract base with type/length rules plus `*CreateForm` (adds `required`) and `*UpdateForm` (all optional — partial updates). Only `validatedData()` (attributes actually present in the request) is passed on, so clients can't set server-managed fields (`auth_key`, `access_token`, `password_hash` — clients send plain `password`, hashed in `UserService`). Concrete controllers implement `createForm()`/`updateForm()`.
- `models/db/` — ActiveRecord models (`User`, `Album`, `Photo`).
- `models/dto/` — readonly response DTOs with `fromModel()`/`toArray()`.

**JWT authentication**: every resource endpoint requires `Authorization: Bearer <token>` (`HttpBearerAuth`, attached after the CORS filter in `ApiControllerTrait` with `except => ['options']` so preflights stay public). Tokens are stateless HS256 JWTs issued/validated by `components/JwtService.php` (app component `jwt`; secret/ttl configured once in `config/di.php` from `JWT_SECRET`/`JWT_TTL` env vars — the secret must be ≥ 32 chars, and changing `.env` requires recreating the web container). `User::findIdentityByAccessToken()` resolves the user from the token's `sub` claim; nothing is stored in the DB. The only public endpoint is `POST /auth/login` (`AuthController`, same layered flow: `LoginForm` → `AuthService` / `AuthServiceInterface` → `UserRepository::findByEmail()` → `LoginResponse` DTO), logging in by unique `email` + `password`; bad credentials → 401. Email uniqueness is validated at the form level: `UserCreateForm` does a plain unique check, `UserUpdateForm` gets the record id via constructor (`updateForm(int $id)`) and excludes it from the check.

**Unified response format**: every response is `{"success": bool, "data": ..., "code": int}`. This is enforced in two places — `components/ApiSerializer.php` (wraps normal REST responses, converts ≥400 statuses to the error shape) and `components/JsonErrorHandler.php` (renders uncaught exceptions in the same shape, with file/line/trace only when `YII_DEBUG`). New endpoints get this automatically; don't hand-build response envelopes.

**Routing**: `yii\rest\UrlRule` in `config/web.php` with `pluralize => false` for `users` and `albums`. Adding a resource means: migration, AR model, repository + service (with contracts), create/update form requests, controller extending `ApiController`, and adding the controller to the `urlManager` rule.

**Console commands** live in `commands/` (namespace `app\commands`), extend `basic/BasicConsoleController` (prints elapsed execution time after each action), and use the same constructor DI pattern as web controllers. `config/console.php` maps `migrate`, `migrate-test` (same `migrations/` path, different DB component), and `migration-creator`.

## Testing Conventions

- Functional tests (`tests/functional/`) extend `BaseCest`, hit real endpoints via REST module, and insert fixtures with `$this->insertRecord('table', [...])` against the test DB. `BaseCest::_before` truncates tables, then creates an auth user (`$this->authUserId`, email `auth.user@example.com`) and sets a Bearer token for every request — user-list pagination totals include this user; call `$I->deleteHeader('Authorization')` to test unauthenticated behavior (see `AuthCest`).
- Unit tests (`tests/unit/`) test services in isolation with PHPUnit mocks of the repositories — no DB (except `User`'s `unique` email rule, which queries the test DB during `validate()`).
- `config/test.php` uses strict URL parsing and disables CSRF; test DB config is `config/test_db.php`.
