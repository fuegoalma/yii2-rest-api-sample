# Yii2 REST API

[![CI](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml) [![CD](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml)

A REST API built with Yii2 following SOLID, DRY, and KISS principles. Implements a service/repository architecture with a unified response format, JWT authentication, and a flat, role-based access control (**RBAC**) layer.

The project ships a full **CI/CD** pipeline on [GitHub Actions](.github/workflows/):

- **CI** ([`ci.yml`](.github/workflows/ci.yml)) — runs on every push and pull request: code style (PHP CS Fixer), static analysis (PHPStan), and the full Codeception suite against MySQL.
- **CD** ([`cd.yml`](.github/workflows/cd.yml)) — chains off a green CI on `master`: builds a self-contained production Docker image from the [`Dockerfile`](Dockerfile) (proving the app containerises and is deployable) and runs a `production` GitHub Environment deployment. The release step is simulated — this sample intentionally provisions no real server — but the whole CI → build → deploy chain runs on every green build.

---

## Requirements

- **Docker** (Engine 20.10+)
- **Docker Compose v2** — the `docker compose` plugin (with a space), *not* the legacy `docker-compose` v1
- **Buildx** — the BuildKit builder plugin, used to build the image

On Ubuntu these two plugins come from the `docker-compose-v2` and `docker-buildx` packages:

```bash
sudo apt-get install -y docker-compose-v2 docker-buildx
```

Verify with `docker compose version` and `docker buildx version`.

---

## Getting Started

### 1. Initialize the project

Run this once to create your `.env` file and install dependencies:

```bash
make init
```

### 2. Configure your environment

Edit `.env` with your local settings:

```env
DB_HOST=db
DB_NAME=your_database
DB_USER=root
DB_PASSWORD=your_password
TEST_DB_NAME=your_database_test
JWT_SECRET=your-random-secret-at-least-32-chars
JWT_TTL=3600
JWT_REFRESH_TTL=2592000
```

`JWT_SECRET` signs the API tokens (HS256) and must be at least 32 characters long — generate one with `openssl rand -hex 32`. `JWT_TTL` is the access-token lifetime in seconds; `JWT_REFRESH_TTL` is the refresh-token lifetime (default 30 days).

### 3. Run setup

Run this after configuring `.env`. It starts Docker, installs dependencies, creates both databases (test and prod), and applies all migrations:

```bash
make setup
```

> The **first** `make setup` builds the Docker image (Imagick + PHP extensions are baked in via Buildx), so it takes a minute. Every subsequent `make up` starts instantly — the image is already built. If you later change the [`Dockerfile`](Dockerfile), rebuild the image with `make rebuild`.

Once it finishes, the stack is live:

| Service | URL |
|---------|-----|
| REST API | http://localhost:8084 |
| **Interactive API docs (Swagger UI)** | **http://localhost:8084/docs** |
| phpMyAdmin | http://localhost:8085 |

---

## Docker Environment

The whole environment is defined by a single **multi-stage** [`Dockerfile`](Dockerfile) — one source of truth, no runtime installs:

| Stage | Used by | What it contains |
|-------|---------|------------------|
| `base` | — | Shared runtime: PHP 8.5 + Apache, Imagick, `pdo_mysql`/`mysqli`, `pcntl` (worker signals), Composer |
| `dev`  | `docker-compose.yml` (`target: dev`) | Your code and `vendor/` are bind-mounted from the host, so edits are live and `make` commands run against your local files |
| `prod` | CD pipeline (`target: prod`) | Self-contained image: production dependencies (`--no-dev`) and app code baked in, no volumes |

The Compose stack runs the `web` app plus three supporting services that all reuse the same image: `db` (MySQL), `cron` (scheduled console jobs), and `worker` (a long-running process that drains the background-job queue — see [Background Jobs](#background-jobs)).

Local development uses the `dev` stage through Docker Compose. Handy lifecycle shortcuts (see `make help` for the full list):

```bash
make up        # start the stack
make down      # stop and remove the stack
make sh        # open a shell inside the web container
make rebuild   # rebuild the web image via Buildx (after editing the Dockerfile)
```

---

## File Storage

Photo storage is abstracted behind [Flysystem](https://flysystem.thephpleague.com/) (`League\Flysystem\FilesystemOperator`). The application never touches the filesystem directly: [`ImageProcessor`](components/ImageProcessor.php) transforms the upload (Imagick → resized WebP) and hands the bytes to the injected filesystem, so **where** files live is a single DI decision in [`config/di.php`](config/di.php):

```php
// local disk (default)
FilesystemOperator::class => static fn () => new Filesystem(
    new LocalFilesystemAdapter(Yii::getAlias($params['photo_upload_path']))
),

// move everything to S3 — no application code changes:
// FilesystemOperator::class => static fn () => new Filesystem(
//     new AwsS3V3Adapter(new S3Client([...]), 'my-bucket')
// ),
```

The `league/flysystem-aws-s3-v3` adapter is already installed, so switching to (or adding a CDN in front of) object storage is config-only. Tests point the same binding at `@runtime` (see [`config/test.php`](config/test.php)) so uploads never hit the web root.

---

## Background Jobs

Slow, retriable side-effects are pushed onto a queue instead of blocking the request. Everything depends on the small [`QueueInterface`](models/contract/queue/QueueInterface.php) / [`JobInterface`](models/contract/queue/JobInterface.php) seam, with two drivers:

- **`DbQueue`** (default) — persists jobs to the `queue_job` table; the long-running **`worker`** service (`yii queue/listen`) drains them continuously, sleeping only when idle and shutting down gracefully on `SIGTERM` (`docker stop`). `yii queue/run` drains once (handy for CI/manual runs).
- **`SyncQueue`** — runs jobs in-process; bound in tests so they don't depend on a running worker.

The first use case is permanently deleting an album: the rows go in a transaction, and each album's on-disk directory cleanup is enqueued (`DeleteAlbumDirectoryJob`) rather than done inline, so a large delete never blocks the response and a failure is retried by the worker instead of aborting the teardown.

> **Why a hand-rolled queue?** The idiomatic choice is `yiisoft/yii2-queue`, but its current release caps `symfony/process` at `^7` while this project runs `^8` (PHP 8.5), so it can't be installed here. On a mainstream stack yii2-queue (Redis/DB/AMQP driver) would back the same `QueueInterface` with no call-site changes.

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

Pass a count with `make seed count=20` (default is 10).

#### Clear all seeded data

```bash
make seed-clear
```

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

- **CI** ([`ci.yml`](.github/workflows/ci.yml)) — runs on every push and pull request. It installs dependencies, spins up a MySQL service, and runs the same three gates as locally: code style (PHP CS Fixer), static analysis (PHPStan), and the full test suite.
- **CD** ([`cd.yml`](.github/workflows/cd.yml)) — runs only *after* CI passes on `master`. It builds the self-contained production image (the `prod` stage of the [`Dockerfile`](Dockerfile), via Buildx) to prove the app containerises and is deployable, then runs a deployment through a `production` GitHub Environment. The release step itself is **simulated** — this sample intentionally provisions no real server — but the complete CI → build → deploy chain runs on every green build.

---

## Project Structure

```
├── .github/workflows/ # CI (cs-fixer, phpstan, tests) + CD (build image, deploy) pipelines
├── Dockerfile         # Multi-stage image: base → dev → prod
├── .dockerignore      # Build-context excludes for the prod image
├── docker-compose.yml # Local dev stack (web + db + phpMyAdmin), builds the dev stage
├── commands/          # Console commands (seeders, etc.)
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
├── tests/
│   ├── functional/    # Functional (integration) tests
│   ├── unit/          # Unit tests
│   └── _support/      # Codeception helpers and base classes
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

> **Interactive docs:** a full OpenAPI 3.0 specification is served with **Swagger UI at [`/docs`](http://localhost:8084/docs)** (raw spec at [`/docs/openapi.yaml`](http://localhost:8084/docs/openapi.yaml)). The spec lives in [`config/openapi.yaml`](config/openapi.yaml) — the single source of truth for request/response shapes and RBAC gates. The tables below are a quick reference.

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
    "data": {},
    "code": 404
}
```

**Paginated list** (`GET /users`, `GET /albums`) — items are wrapped alongside a `pagination` block:
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

Sortable / filterable attributes per resource:

| Resource | Sortable | Filterable |
|----------|----------|------------|
| Users | `id`, `first_name`, `last_name`, `email`, `created_at`, `updated_at` | `first_name`, `last_name`, `email` (partial match) |
| Albums | `id`, `user_id`, `title`, `created_at`, `updated_at` | `title` (partial match), `user_id` (exact), `is_deleted` (exact — the review queue on `GET /albums`) |
| Photos | `id`, `title`, `created_at` | `title` (partial match) |
| Roles | `id`, `name` | `name` (partial match) |

An unknown `sort` attribute or an out-of-range `per_page` returns `422`. Example:

```bash
curl "http://localhost:8084/users?first_name=jo&sort=-created_at&per_page=50&page=2" \
  -H "Authorization: Bearer <token>"
```