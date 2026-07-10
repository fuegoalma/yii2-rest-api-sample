# Yii2 REST API

[![CI](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml) [![CD](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml)

A REST API built with Yii2 following SOLID, DRY, and KISS principles. Implements a service/repository architecture with a unified response format.

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
```

`JWT_SECRET` signs the API access tokens (HS256) and must be at least 32 characters long — generate one with `openssl rand -hex 32`. `JWT_TTL` is the token lifetime in seconds.

### 3. Run setup

Run this after configuring `.env`. It starts Docker, installs dependencies, creates both databases (test and prod), and applies all migrations:

```bash
make setup
```

> The **first** `make setup` builds the Docker image (Imagick + PHP extensions are baked in via Buildx), so it takes a minute. Every subsequent `make up` starts instantly — the image is already built. If you later change the [`Dockerfile`](Dockerfile), rebuild the image with `make rebuild`.

---

## Docker Environment

The whole environment is defined by a single **multi-stage** [`Dockerfile`](Dockerfile) — one source of truth, no runtime installs:

| Stage | Used by | What it contains |
|-------|---------|------------------|
| `base` | — | Shared runtime: PHP 8.5 + Apache, Imagick, `pdo_mysql`/`mysqli`, Composer |
| `dev`  | `docker-compose.yml` (`target: dev`) | Your code and `vendor/` are bind-mounted from the host, so edits are live and `make` commands run against your local files |
| `prod` | CD pipeline (`target: prod`) | Self-contained image: production dependencies (`--no-dev`) and app code baked in, no volumes |

Local development uses the `dev` stage through Docker Compose. Handy lifecycle shortcuts (see `make help` for the full list):

```bash
make up        # start the stack
make down      # stop and remove the stack
make sh        # open a shell inside the web container
make rebuild   # rebuild the web image via Buildx (after editing the Dockerfile)
```

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
│   └── console.php    # Console application config
├── controllers/       # API controllers
├── migrations/        # Database migrations
├── models/
│   ├── contract/      # Interfaces (repository & service contracts)
│   ├── db/            # ActiveRecord models
│   ├── dto/           # Data Transfer Objects
│   ├── form/          # Form requests (validation of incoming request data)
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

All resource endpoints require a JWT. Obtain one via the public login endpoint:

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login` | Exchange `email` + `password` for a JWT |

```bash
curl -X POST http://localhost:8084/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email": "user@example.com", "password": "secret123"}'
```

**Response:**
```json
{
    "success": true,
    "data": {
        "access_token": "eyJ0eXAiOiJKV1Qi...",
        "token_type": "Bearer",
        "expires_in": 3600
    },
    "code": 200
}
```

Send the token with every other request:

```bash
curl http://localhost:8084/users -H 'Authorization: Bearer <access_token>'
```

Requests without a valid (unexpired, correctly signed) token get a `401` response. Invalid credentials on login also return `401`; validation errors return `422`.

---

## API Endpoints

All endpoints below require the `Authorization: Bearer <token>` header.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/users` | List all users |
| GET | `/users/{id}` | Get user with albums |
| POST | `/users` | Create a user |
| PUT | `/users/{id}` | Update a user |
| DELETE | `/users/{id}` | Delete a user |
| GET | `/albums` | List all albums |
| GET | `/albums/{id}` | Get album with photos and user info |
| POST | `/albums` | Create an album |
| PUT | `/albums/{id}` | Update an album |
| DELETE | `/albums/{id}` | Delete an album |
| GET | `/albums/{albumId}/photos` | List the photos of an album |
| POST | `/albums/{albumId}/photos` | Upload a photo to an album (`multipart/form-data`) |
| GET | `/photos/{id}` | Get a single photo |
| PUT | `/photos/{id}` | Update a photo (title only) |
| DELETE | `/photos/{id}` | Delete a photo (removes its file) |

Photos are always scoped to an album — there is no flat `GET /photos` listing. Uploads take `title` + `file` as `multipart/form-data`; the image (`jpg, jpeg, png, webp, gif, avif`) is converted to WebP (quality 80), resized to fit 500×500 preserving aspect ratio, and stored under `web/uploads/albums/{albumId}/`.

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

Use the `?page=N` query parameter to navigate pages.