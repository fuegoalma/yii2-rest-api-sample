# Handbook

Everything about this API that does not fit on the front page: how the
environment is put together, how each subsystem works, and how to run every
gate. The [README](../README.md) is the tour; this is the reference.

Decisions that would otherwise have to be reverse-engineered from the code live
in [docs/adr/](adr/README.md) instead — what was chosen, what it cost, and what
undoing it would take.

---

## Docker Environment

The whole environment is defined by a single **multi-stage** [`Dockerfile`](../Dockerfile) — one source of truth, no runtime installs:

| Stage | Used by | What it contains |
|-------|---------|------------------|
| `base` | — | Shared runtime: PHP 8.5 + Apache, Imagick, `pdo_mysql`/`mysqli`, `pcntl` (worker signals), Composer |
| `dev`  | `docker-compose.yml` (`target: dev`) | Your code and `vendor/` are bind-mounted from the host, so edits are live and `make` commands run against your local files |
| `prod` | CD pipeline (`target: prod`) | Self-contained image: production dependencies (`--no-dev`) and app code baked in, no volumes |

The Compose stack is five services. `cron` (scheduled console jobs) and `worker` (a long-running process that drains the background-job queue — see [Background Jobs](#background-jobs)) reuse the **same** `yii-app:dev` image as `web`, differing only in entrypoint, so the image is built once for all three. The remaining two are stock upstream images: `db` (`mysql:8.0`) and `phpmyadmin`.

Local development uses the `dev` stage through Docker Compose. Handy lifecycle shortcuts (see `make help` for the full list):

```bash
make up        # start the stack
make down      # stop and remove the stack
make restart   # restart the stack
make logs      # follow container logs
make sh        # open a shell inside the web container
make rebuild   # rebuild the web image via Buildx (after editing the Dockerfile)
```

---

## File Storage

An upload passes through two independent seams, so **what the bytes look like** and **where they end up** are separate decisions:

- [`ImageEncoderInterface`](../models/contract/image/ImageEncoderInterface.php) turns the uploaded file into storable bytes. The default [`ImagickWebpEncoder`](../components/image/ImagickWebpEncoder.php) produces WebP, scaled to fit the configured bounding box (aspect ratio preserved, never upscaled). The dimensions and quality are a published API contract, so they live in [`config/params.php`](../config/params.php) rather than in code.
- `League\Flysystem\FilesystemOperator` decides where those bytes live. [`ImageStorage`](../components/ImageStorage.php) composes the two: it names the file and hands it to the filesystem, and never touches an imaging library or a disk itself.

So switching storage is a single DI decision in [`config/di.php`](../config/di.php):

```php
// local disk (default) — the path comes from the `photo_upload_path` param
FilesystemOperator::class => static fn () => new Filesystem(
    new LocalFilesystemAdapter(Yii::getAlias(Yii::$app->params['photo_upload_path']))
),

// move everything to S3 — no application code changes:
// FilesystemOperator::class => static fn () => new Filesystem(
//     new AwsS3V3Adapter(new S3Client([...]), 'my-bucket')
// ),
```

The `league/flysystem-aws-s3-v3` adapter is already installed, so switching to (or adding a CDN in front of) object storage is config-only. Tests override the `photo_upload_path` param to point at `@runtime` (see [`config/test.php`](../config/test.php)) so uploads never hit the web root.

---

## CORS

Cross-origin requests are allowed from anywhere. The filter is attached to every REST controller by [`ApiControllerTrait`](../controllers/basic/ApiControllerTrait.php): `Origin: *`, all standard methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`), all request headers, credentials **off**, and a 24-hour preflight cache (`Access-Control-Max-Age: 86400`).

`OPTIONS` preflights are deliberately exempt from everything that could reject them:

- **Never authenticated** — the authenticator is attached *after* the CORS filter with `except => ['options']`, so a preflight needs no bearer token.
- **Never throttled** — [`RateLimiter`](../components/RateLimiter.php) passes `OPTIONS` straight through, so a browser's preflights can't burn the caller's auth-endpoint budget.

---

## Background Jobs

Slow, retriable side-effects are pushed onto a queue instead of blocking the request. Everything depends on a small seam in [`models/contract/queue/`](../models/contract/queue/): a **job** ([`JobInterface`](../models/contract/queue/JobInterface.php)) is a plain serializable message that names its **handler** ([`JobHandlerInterface`](../models/contract/queue/JobHandlerInterface.php)), which holds the behaviour and takes its services by constructor injection. That split is what lets a job survive `serialize()` without carrying a database connection or a filesystem client around with it.

Resolving a handler by name is the one lookup that can only happen at run time, so it is isolated behind [`JobRunnerInterface`](../models/contract/queue/JobRunnerInterface.php) — the drivers below never touch the DI container themselves. Two drivers implement [`QueueInterface`](../models/contract/queue/QueueInterface.php):

- **`DbQueue`** (default) — persists jobs to the `queue_job` table; the long-running **`worker`** service (`yii queue/listen`) drains up to 100 pending jobs per pass continuously, sleeping only when idle and shutting down gracefully on `SIGTERM` (`docker stop`). `yii queue/run` drains once (handy for CI/manual runs).
- **`SyncQueue`** — runs jobs in-process; bound in tests so they don't depend on a running worker.

A job that throws is retried (logged as a warning each time) up to 3 attempts, then dropped with a logged error so one poison job can't wedge the queue.

The first use case is permanently deleting an album: the rows go in a transaction, and each album's on-disk directory cleanup is enqueued (`DeleteAlbumDirectoryJob`, run by `DeleteAlbumDirectoryHandler`) rather than done inline, so a large delete never blocks the response and a failure is retried by the worker instead of aborting the teardown.

> **Why a hand-rolled queue?** The idiomatic choice is `yiisoft/yii2-queue`, but its current release caps `symfony/process` at `^7` while this project runs `^8` (PHP 8.5), so it can't be installed here. On a mainstream stack yii2-queue (Redis/DB/AMQP driver) would back the same `QueueInterface` with no call-site changes.

---

## Health Check

`GET /health` is public, unauthenticated and never rate-limited (monitoring/orchestration tooling can't hold a JWT or tolerate a 429). It runs `SELECT 1` against the database and reports the result inside the standard response envelope:

```bash
curl http://localhost:8084/health
```

```json
{
    "success": true,
    "data": {
        "status": "ok",
        "checks": { "database": "ok" }
    },
    "code": 200
}
```

Returns **200** when healthy, **503** (with `status: "error"`) otherwise — point your load balancer / uptime monitor at this endpoint.

---

## Database Migrations

Migrations are managed using the standard Yii2 migration tool.

#### Apply migrations to main database

```bash
make migrate-main
```

#### Apply migrations to test database

```bash
make migrate-test
```

Or run both at once with `make migrate`.

---

## Migration Generator

The project uses [bizley/yii2-migration](https://github.com/bizley/yii2-migration) to generate migration files from the existing database schema.

#### Generate migrations for all tables

```bash
make migration-create table='*'
```

#### Generate a migration for a specific table

```bash
make migration-create table=user
```

#### Generate an update migration for a specific table

Compares current schema with migration history and generates a diff:

```bash
make migration-update table=user
```

---

## Seeders

Seeders populate the database with generated test data.

#### Generate seed data

```bash
make seed
```

Pass a count with `make seed count=20` (default is 10). Seeded users all get the password from the `DEFAULT_PASSWORD` env var; seeded photos use `source = 'seed'` and resolve to `web/default-images/` rather than a real upload.

> **`count` is cubic, not linear.** [`SeederService`](../models/service/SeederService.php) nests its loops: **N** users, **N²** albums (N per user), **N³** photos (N per album). The default `count=10` creates 10 users / 100 albums / **1,000** photos; `count=50` would create 125,000 photo rows. Pick it accordingly.

#### Clear all seeded data

```bash
make seed-clear
```

> ⚠️ **This is destructive well beyond seed data.** `seeder/clear` runs an unfiltered `DELETE FROM user` ([`UserRepository::clearAll()`](../models/repository/UserRepository.php)), so it removes **every** account — hand-made ones too — and the FK cascade takes every album, photo and role assignment with it, **including the `super_admin` you appointed with `make rbac-assign`**. Re-run [`make rbac-assign`](#appointing-the-first-super-admin) afterwards to get back into the RBAC-gated endpoints.

#### Prune expired refresh tokens

Refresh tokens are stored server-side; once they expire they're just dead rows. This is **automated** — a dedicated `cron` container (started with the stack) runs the prune daily at 03:30 (see `docker/cron/crontab`, the single place to declare scheduled jobs). You can also run it on demand:

```bash
make refresh-token-prune
```

It deletes only fully-expired tokens and keeps still-valid ones (which reuse detection still needs). Watch the scheduled runs with `docker compose logs cron`.

---

## Testing

The project uses [Codeception](https://codeception.com/) for functional and unit tests. Tests run against the dedicated test database (`TEST_DB_NAME`).

#### Build test actor classes

Run this after adding or removing Codeception modules:

```bash
make build
```

#### Run all tests

```bash
make test
```

#### Run only functional tests

```bash
make test-functional
```

#### Run only unit tests

```bash
make test-unit
```

#### Run a single test class or method

```bash
make test-one suite=functional class=UsersCest
make test-one suite=functional class=UsersCest:testMethodName
```

### Code Coverage

Coverage is measured with [pcov](https://github.com/krakjoe/pcov) (baked into the Docker `base` stage) and reported by Codeception. **The gate is 100% line coverage** of `commands/`, `components/`, `controllers/` and `models/` — CI fails below it.

```bash
make coverage        # run the suite with coverage and enforce the gate
make coverage-html   # the same, then print the HTML report path
```

The HTML report lands in `tests/_output/coverage/index.html`, with the Clover XML the gate reads at `tests/_output/coverage.xml`. When coverage falls short, the check lists every offending file with its uncovered line numbers:

```
Files below 100% line coverage (1):

  models/service/PermissionService.php                        50.00%  (2/4)
      uncovered lines: 22, 24

Total line coverage: 99.84% (1270/1272 statements), required 100.00%
```

A few things worth knowing:

- **pcov is disabled by default** (`pcov.enabled=0`), so `make test` and `make test-one` — the inner TDD loop — run at full speed. Only `make coverage` turns it on, for that process alone.
- **Both suites must run in a single `codecept run`.** Coverage from unit and functional is merged at the end of the run, so running the suites separately makes the second report overwrite the first and halves the number.
- **`config/`, `migrations/`, `web/` and `tests/` are out of scope**, as are the interfaces in `models/contract/` — a file with no executable lines counts as 0/0 and neither helps nor hurts the total.
- **Genuinely unreachable code** is marked with `@codeCoverageIgnore` **and a comment explaining why it cannot be reached**. Unreachable is a high bar: it means unreachable by construction, not merely inconvenient to test. See `RefreshTokenRepository::revoke()` for the shape of an acceptable justification.
- Don't add `@covers` / `#[CoversClass]` annotations — Codeception treats them as strict, silently narrowing what a test is credited with covering.

---

## Test-Driven Development

New work on this project is **test-first**. The cycle is the usual one:

1. **Red** — write a test that expresses the behaviour you want, and watch it fail. A test that has never failed has not been shown to test anything.
2. **Green** — write the least code that makes it pass.
3. **Refactor** — clean up with the test as your safety net, and re-run it.

```bash
make test-one suite=unit class=AlbumServiceTest:testSomething   # tight loop
make test                                                        # whole suite
make coverage                                                    # before you call it done
```

#### Where a test belongs

- **Unit** (`tests/unit/`) — a class in isolation with its collaborators mocked. Services, forms, DTOs, components. Extend `tests\unit\BaseUnitTest`; put anything two test classes both need on that base rather than copying it.
- **Functional** (`tests/functional/`) — a real HTTP request through the whole stack against the test database. Endpoint behaviour, RBAC gates, response shapes. Extend `tests\functional\BaseCest` and use its fixture helpers (`insertRecord`, `actingAsUserWithRole`, `insertRole`, `sendPutJson`).

Prefer a functional test when the thing you're specifying *is* the integration — repositories and ActiveRecord models are covered far better by exercising them against a real database than by asserting on mocks.

#### Checklist for a new endpoint

1. Migration for the table (run it on **both** databases: `make migrate`).
2. **Failing tests first** — a functional Cest for the endpoint's contract, unit tests for the service logic.
3. ActiveRecord model, repository and service (with their contracts in `models/contract/`).
4. Create/update/search form requests.
5. Controller extending `ApiController`, implementing `accessResource()`.
6. Permissions seeded in a migration — **and granted to `super_admin`**.
7. Route in `config/url_rules.php`.
8. Document the endpoint in `config/openapi.yaml` (the single source of truth for the API).
9. `make coverage` green, then `make cs-check` and `make stan`.

---

## Code Style

The project follows the [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard, enforced with [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) (configuration in `.php-cs-fixer.dist.php`).

#### Check code style

Shows the violations and a diff of what would be changed, without modifying any files:

```bash
make cs-check
```

#### Fix code style

Automatically reformats all project files to comply with PSR-12:

```bash
make cs-fix
```

---

## Static Analysis

The project is analysed with [PHPStan](https://phpstan.org/) (level 5, configuration in `phpstan.neon.dist`).

```bash
make stan
```

---

## AI-Assisted Development (CodeGraph)

The repo is indexed with [CodeGraph](https://github.com/colbymchenry/codegraph) — a local knowledge graph of symbols, calls and dependencies — so AI coding assistants (e.g. Claude Code) can look up "where is X" / "who calls X" / "what breaks if I change X" directly from the index instead of grepping or reading whole files. **It's required tooling for this repo**: `CLAUDE.md` instructs every AI assistant to prefer it over `grep`/`find`/reading whole files for "where is X" style questions, so install it before doing any AI-assisted work here. The index lives in `.codegraph/` (local to each machine, gitignored) and is rebuilt with:

```bash
codegraph init      # first-time index for a fresh checkout
codegraph sync       # refresh after a batch of local changes
codegraph status     # check whether the index is stale
```

This is a local dev-tooling aid, not part of the running application — nothing under `.codegraph/` is deployed or required to run the app.

#### Installing CodeGraph

```bash
# macOS/Linux — self-contained binary, no Node.js required
curl -fsSL https://raw.githubusercontent.com/colbymchenry/codegraph/main/install.sh | sh

# Windows (PowerShell)
irm https://raw.githubusercontent.com/colbymchenry/codegraph/main/install.ps1 | iex

# npm (any platform with Node.js)
npm i -g @colbymchenry/codegraph
```

Verify with `codegraph --version`, then run `codegraph init` from the project root to build the initial index.

---

## Continuous Integration & Delivery

The project ships a two-stage GitHub Actions pipeline — the two badges at the top of this README reflect the latest runs on the default branch:

- **CI** ([`ci.yml`](../.github/workflows/ci.yml)) — runs on every push and pull request. It installs dependencies, spins up a MySQL service, and runs the same four gates as locally: code style (PHP CS Fixer), static analysis (PHPStan), the full test suite, and the [100% coverage gate](#code-coverage) (`make coverage`). When the coverage gate goes red the HTML report is uploaded as a build artifact, so the per-file breakdown is available without reproducing the run locally.
- **CD** ([`cd.yml`](../.github/workflows/cd.yml)) — runs only *after* CI passes on `master`. It builds the self-contained production image (the `prod` stage of the [`Dockerfile`](../Dockerfile), via Buildx) to prove the app containerises and is deployable, then runs a deployment through a `production` GitHub Environment. The release step itself is **simulated** — this sample intentionally provisions no real server — but the complete CI → build → deploy chain runs on every green build.

---

## Project Structure

```
├── .github/workflows/ # CI (cs-fixer, phpstan, tests) + CD (build image, deploy) pipelines
├── Dockerfile         # Multi-stage image: base → dev → prod
├── .dockerignore      # Build-context excludes for the prod image
├── docker-compose.yml # Local dev stack (web + db + phpMyAdmin + cron + worker), builds the dev stage
├── docker/cron/       # Cron service: entrypoint + the versioned schedule (crontab)
├── commands/          # Console commands (seeders, RBAC bootstrap, refresh-token pruning, queue worker)
├── components/        # App components: JWT, rate limiter, image processing, queue drivers, response serialization
├── config/            # Application configuration
│   ├── db.php         # Main database config (reads from .env)
│   ├── test_db.php    # Test database config (reads from .env)
│   ├── web.php        # Web application config
│   ├── console.php    # Console application config
│   ├── url_rules.php  # Shared REST route table (used by web + test)
│   └── openapi.yaml   # OpenAPI 3.0 spec — source of truth for the API docs (/docs)
├── controllers/       # API controllers
├── migrations/        # Database migrations
├── models/
│   ├── contract/      # Interfaces (repository, service & queue contracts)
│   ├── db/            # ActiveRecord models
│   ├── dto/           # Data Transfer Objects
│   ├── form/          # Form requests (validation of incoming request data)
│   ├── jobs/          # Background-queue jobs
│   ├── repository/    # Repository layer (database access)
│   └── service/       # Service layer (business logic)
├── web/               # Document root: entry script, uploads/, default-images/
├── codeception.yml    # Test runner config (paths, modules, coverage scope)
├── phpstan.neon.dist  # Static analysis config (level 5)
├── .php-cs-fixer.dist.php # PSR-12 code style config
├── tests/
│   ├── functional/    # Functional (integration) tests
│   ├── unit/          # Unit tests
│   ├── _support/      # Codeception helpers and base classes (BaseCest, BaseUnitTest)
│   └── bin/           # coverage-check.php — the 100% coverage gate
├── init.sh            # First-time project initialization
├── setup.sh           # Database creation and migration runner
└── Makefile           # Short aliases for docker compose exec commands (make help)
```

---

## Authentication

All resource endpoints require a JWT. The auth endpoints below are public (and rate-limited per IP); the token-issuing ones return a pair — a short-lived **access token** (a stateless JWT) for the `Authorization` header and a long-lived **refresh token** (an opaque, server-stored credential) to obtain a new pair without re-entering credentials.

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/register` | Create an account and receive a token pair (201) |
| POST | `/auth/login` | Exchange `email` + `password` for a token pair |
| POST | `/auth/refresh` | Exchange a valid `refresh_token` for a fresh token pair |
| POST | `/auth/logout` | Revoke the refresh token's session — log out this device (204) |
| POST | `/auth/logout-all` | Revoke every session of the token's owner — log out everywhere (204) |

**Register** a new account (no token required — this is how you bootstrap the first user):

```bash
curl -X POST http://localhost:8084/auth/register \
    -H 'Content-Type: application/json' \
    -d '{"first_name": "John", "last_name": "Doe", "email": "user@example.com", "password": "secret123"}'
```

**Log in** with an existing account:

```bash
curl -X POST http://localhost:8084/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email": "user@example.com", "password": "secret123"}'
```

Both return the same shape (register responds with `201`, login with `200`):
```json
{
    "success": true,
    "data": {
        "access_token": "eyJ0eXAiOiJKV1Qi...",
        "refresh_token": "eyJ0eXAiOiJKV1Qi...",
        "token_type": "Bearer",
        "expires_in": 3600
    },
    "code": 200
}
```

Send the access token with every other request (`/users/me` works for any authenticated user; most other endpoints are gated by [role](#authorization-rbac)):

```bash
curl http://localhost:8084/users/me -H 'Authorization: Bearer <access_token>'
```

Once the access token expires, **refresh** it. Refresh tokens **rotate**: each one is single-use and the response carries a new refresh token to replace it. Reusing an already-spent refresh token is treated as a leak — the whole session chain is revoked and you must log in again.

```bash
curl -X POST http://localhost:8084/auth/refresh \
    -H 'Content-Type: application/json' \
    -d '{"refresh_token": "<refresh_token>"}'
```

**Log out.** Because refresh tokens are stored server-side, they can be revoked. Log out just the current device, or everywhere at once (handy when you signed in on a shared machine):

```bash
# this device only
curl -X POST http://localhost:8084/auth/logout \
    -H 'Content-Type: application/json' \
    -d '{"refresh_token": "<refresh_token>"}'

# all devices of this user
curl -X POST http://localhost:8084/auth/logout-all \
    -H 'Content-Type: application/json' \
    -d '{"refresh_token": "<refresh_token>"}'
```

Requests without a valid (unexpired, correctly signed) access token get a `401` — a refresh token is opaque and cannot be used as a bearer credential. Invalid credentials on login, and an invalid/expired/revoked refresh token, also return `401`; validation errors (e.g. a duplicate email on register) return `422`.

---

## Rate Limiting

The five `/auth/*` endpoints are throttled per client IP to protect against brute-force credential guessing. Each action (`login`, `register`, `refresh`, `logout`, `logout-all`) has its **own independent budget** — hammering `/auth/login` doesn't affect your `/auth/refresh` allowance.

- Every non-OPTIONS request increments the counter and refreshes the window.
- A **successful** response (status `< 400`) resets the counter early.
- Exceeding the limit returns **`429`** with a `Retry-After` header (seconds until the window clears).
- Tune it with `LOGIN_RATE_LIMIT_ATTEMPTS` / `LOGIN_RATE_LIMIT_WINDOW` in `.env` (default: 5 attempts per 60s).

```bash
# after 5 failed logins within the window:
curl -i -X POST http://localhost:8084/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email": "user@example.com", "password": "wrong"}'
# HTTP/1.1 429 Too Many Requests
# Retry-After: 60
```

---

## Authorization (RBAC)

Authentication proves *who* you are; authorization decides *what* you may do. Access control here is **flat** — there is no role hierarchy or inheritance. A **role** is just a named set of permissions, a user may hold **several** roles, and their effective permissions are the **union** of all of them. A caller lacking a permission gets a `403`.

**A freshly registered account has no roles — it is a "base user".** Registration and admin-created accounts assign no role. Base abilities are granted implicitly to *every* authenticated user by **ownership**, not by a role: anyone can create albums, and view/update/delete **their own** albums and photos and edit **their own** profile. A role is therefore an *upgrade* stacked on top of the base — it only ever adds power, never removes it (an admin keeps every base ability over their own content).

### Roles

Three roles are seeded (they cannot be deleted or renamed, but a super admin can re-compose their permissions):

| Role | What it adds on top of the base user |
|------|--------------------------------------|
| `moderator` | See all users; manage **any** album but delete only via **soft-delete** (pending admin review); full access to **any** photo, including permanent deletion |
| `admin` | Full user CRUD; permanently delete or restore **any** album; list roles and **assign** them to users |
| `super_admin` | Everything, including composing custom roles and viewing the permission catalog |

Permissions are code-checked and therefore defined **only in migrations** (there is no create/update/delete for them) — the `GET /permissions` catalog exists so a super admin can compose new roles from it.

### Appointing the first super admin

Every role-management action needs an existing super admin, so the very first one is appointed from the console (idempotent):

```bash
make rbac-assign role=super_admin email=user@example.com
```

### Two safety rules

- **Anti-escalation** — an admin (who can *assign* roles but not *manage* them) can hand out unprivileged roles but can never grant or revoke a role carrying `role.manage`/`role.assign`. So an admin cannot mint or demote another admin/super admin — only a super admin can.
- **Last-role-manager invariant** — no operation (deleting a role, re-composing it, changing assignments, deleting a user) may leave the system with **zero** users able to manage roles. Such an attempt returns `409` (e.g. the last super admin trying to strip their own role).

These mutations are **atomic and concurrency-safe**: each runs inside a DB transaction (injected via `TransactionRunnerInterface`) and takes a `SELECT ... FOR UPDATE` lock on the current role-managers before checking the invariant, so two concurrent requests can't each pass the check and *together* remove the last manager. User deletion (account + all its albums, photos and files) is wrapped in the same way.

---

## API Endpoints

> **Interactive docs:** a full OpenAPI 3.0 specification is served with **Swagger UI at [`/docs`](http://localhost:8084/docs)** (raw spec at [`/docs/openapi.yaml`](http://localhost:8084/docs/openapi.yaml)). The spec lives in [`config/openapi.yaml`](../config/openapi.yaml) — the single source of truth for request/response shapes and RBAC gates. The tables below are a quick reference.

All endpoints below require the `Authorization: Bearer <token>` header. The **Who can access** column summarises the RBAC gate — "base user" means any authenticated caller (see [Authorization](#authorization-rbac)).

### The current user

| Method | Endpoint | Description | Who can access |
|--------|----------|-------------|----------------|
| GET | `/users/me` | The authenticated user's profile + their role names | Base user |
| GET | `/users/me/permissions` | The caller's roles + the union of their permissions (so a client can build its UI) | Base user |

### Users

| Method | Endpoint | Description | Who can access |
|--------|----------|-------------|----------------|
| GET | `/users` | List all users | `moderator`+ |
| GET | `/users/{id}` | Get user with albums | `moderator`+ |
| POST | `/users` | Create a user (assigned no role) | `admin`+ |
| PUT | `/users/{id}` | Update a user | Owner (self) or `admin`+ |
| DELETE | `/users/{id}` | Delete a user | `admin`+ |
| GET | `/users/{id}/roles` | List a user's roles | `admin`+ |
| PUT | `/users/{id}/roles` | Replace a user's role set (`{"roles": [...]}`, empty array revokes all) | `admin`+ |

> The `albums` embedded in `GET /users/{id}` only ever contains **live** albums — [`User::getAlbums()`](../models/db/User.php) filters out soft-deleted ones at the relation level, even for a `super_admin`. To review a user's flagged albums, use `GET /albums?user_id={id}&is_deleted=1`.

### Albums

| Method | Endpoint | Description | Who can access |
|--------|----------|-------------|----------------|
| GET | `/albums/my` | List **the caller's own** albums | Base user |
| GET | `/albums` | List all albums (the admin/moderator view) | `moderator`+ |
| GET | `/albums/{id}` | Get album with photos and user info | Owner or `moderator`+ |
| POST | `/albums` | Create an album (owned by the caller) | Base user |
| PUT | `/albums/{id}` | Update an album | Owner or `moderator`+ |
| DELETE | `/albums/{id}` | Delete an album — see below | Owner, `moderator` or `admin`+ |
| POST | `/albums/{id}/restore` | Restore a soft-deleted album | `admin`+ |

### Photos

| Method | Endpoint | Description | Who can access |
|--------|----------|-------------|----------------|
| GET | `/albums/{albumId}/photos` | List the photos of an album | Album owner or `moderator`+ |
| POST | `/albums/{albumId}/photos` | Upload a photo to an album (`multipart/form-data`) | Album owner or `moderator`+ |
| GET | `/photos/{id}` | Get a single photo | Album owner or `moderator`+ |
| PUT | `/photos/{id}` | Update a photo (title only) | Album owner or `moderator`+ |
| DELETE | `/photos/{id}` | Delete a photo (removes its file, permanent) | Album owner or `moderator`+ |

### Roles & permissions

| Method | Endpoint | Description | Who can access |
|--------|----------|-------------|----------------|
| GET | `/roles` | List roles (name + description) | `admin`+ |
| GET | `/roles/{id}` | Get a role including its permissions | `super_admin` |
| POST | `/roles` | Compose a custom role from catalog permissions | `super_admin` |
| PUT | `/roles/{id}` | Update a role's description/permission set | `super_admin` |
| DELETE | `/roles/{id}` | Delete a custom role | `super_admin` |
| GET | `/permissions` | The permission catalog (to compose roles from) | `super_admin` |

Photos are always scoped to an album — there is no flat `GET /photos` listing. Uploads take `title` + `file` as `multipart/form-data`; the image (`jpg, jpeg, png, webp, gif, avif`) is converted to WebP (quality 80), resized to fit 500×500 preserving aspect ratio, and stored under `web/uploads/albums/{albumId}/`.

**Deleting an album** (`DELETE /albums/{id}`) is one endpoint with two outcomes decided by the caller's permissions:

- **Permanent** for whoever may delete it outright — its **owner**, or an **admin** (`album.delete.any`). The album, its photos and their files are removed.
- **Soft** (pending review) for a **moderator**: the album is flagged (with an optional `{"reason": "..."}` body) instead of removed, and the request is idempotent. Soft-deleted albums are hidden from every listing by default and become a `404` for their owner until an admin restores them (`POST /albums/{id}/restore`). To review the queue, an admin lists them with `?is_deleted=1`.

```bash
curl -X POST http://localhost:8084/albums/1/photos \
  -H "Authorization: Bearer <token>" \
  -F "title=My Photo" \
  -F "file=@/path/to/image.jpg"
```

### Request Validation Limits

Enforced by the form requests in [`models/form/`](../models/form/); a breach is a `422` with the field errors under `data.error`.

| Field | Constraint |
|-------|------------|
| `password` | 6–72 characters (72 is bcrypt's own input limit) |
| `email` | ≤255 chars, valid address, unique — an update excludes the record's own id |
| `first_name` / `last_name` | ≤255 chars |
| album / photo `title` | ≤255 chars |
| album soft-delete `reason` | ≤255 chars, optional |
| role `name` | ≤64 chars, unique |
| role `description` | ≤255 chars |
| `permissions` (role composition) | every name must exist in the catalog |
| `roles` (role assignment body) | must be **present**; `[]` is valid and revokes every role; an unknown name → `422` |
| photo upload `file` | required, extension in `jpg, jpeg, png, webp, gif, avif` — the bytes are re-validated by Imagick, so a renamed non-image still `422`s |
| `per_page` | integer, 1–100 |

### Response Format

All endpoints return a unified JSON response:

**Success:**
```json
{
    "success": true,
    "data": {},
    "code": 200
}
```

**Error:**
```json
{
    "success": false,
    "data": {
        "message": "An error occurred during execution",
        "error": {}
    },
    "code": 404
}
```

Validation failures (422) put the field errors under `data.error` — e.g. creating an album without a title:
```json
{
    "success": false,
    "data": {
        "message": "An error occurred during execution",
        "error": { "title": ["Title cannot be blank."] }
    },
    "code": 422
}
```

**Paginated list** — every index endpoint (`GET /users`, `GET /albums`, `GET /albums/my`, `GET /albums/{albumId}/photos`, `GET /roles`) wraps its items alongside a `pagination` block:
```json
{
    "success": true,
    "data": {
        "items": [
            { "id": 1, "title": "..." },
            { "id": 2, "title": "..." }
        ],
        "pagination": {
            "total": 100,
            "per_page": 20,
            "current_page": 1,
            "last_page": 5,
            "from": 1,
            "to": 20
        }
    },
    "code": 200
}
```

### List query parameters

The list endpoints (`GET /users`, `GET /albums`, `GET /albums/my`, `GET /albums/{albumId}/photos`, `GET /roles`) accept optional query parameters for pagination, sorting and filtering:

| Parameter | Description |
|-----------|-------------|
| `page` | Page number to return (default `1`). |
| `per_page` | Items per page, `1`–`100` (default `20`). |
| `sort` | Comma-separated attribute list; prefix an attribute with `-` for descending order (e.g. `sort=-created_at,title`). |
| *filters* | One parameter per filterable attribute (see below). |

List endpoints return the plain resource shape — related records are not embeddable from the query string. Where a relation belongs to a response, the endpoint that owns it includes it unconditionally (`GET /users/{id}` and `GET /users/me` carry `albums`, `GET /albums/{id}` carries `photos`, `GET /roles/{id}` carries `permissions` behind `role.view`).

Sortable / filterable attributes per resource:

| Resource | Sortable | Filterable |
|----------|----------|------------|
| Users | `id`, `first_name`, `last_name`, `email`, `created_at`, `updated_at` | `first_name`, `last_name`, `email` (partial match) |
| Albums | `id`, `user_id`, `title`, `created_at`, `updated_at` | `title` (partial match), `user_id` (exact), `is_deleted` (exact — the review queue on `GET /albums`) |
| Photos | `id`, `title`, `created_at` | `title` (partial match) |
| Roles | `id`, `name` | `name` (partial match) |

An unknown `sort` attribute or an out-of-range `per_page` returns `422`. A `page` past the last one is not clamped back — it returns an **empty** `items` array with `total`/`last_page` still reflecting the full result set (`current_page` echoes back whatever was requested). When `sort` is omitted (or resolves to nothing sortable), results default to `id` ascending.

A few more edge cases worth knowing:

- **`last_page` is `0`, not `1`, for an empty result set** (`total: 0`), and `from`/`to` are both `0`.
- **Filter values are matched literally.** Partial-match filters go through Yii's `like` operator, which escapes `%`, `_` and `\` — so `?title=100%` looks for the literal string, not a wildcard.
- **An empty filter value is treated as absent.** `?title=` returns everything rather than matching `title = ''` or `NULL` (the repository applies filters with `andFilterWhere`, which skips empty operands).

Example:

```bash
curl "http://localhost:8084/users?first_name=jo&sort=-created_at&per_page=50&page=2" \
  -H "Authorization: Bearer <token>"
```
