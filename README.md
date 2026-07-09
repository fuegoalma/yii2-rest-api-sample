# Yii2 REST API

A REST API built with Yii2 following SOLID, DRY, and KISS principles. Implements a service/repository architecture with a unified response format.

---

## Requirements

- Docker
- Docker Compose

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

## Project Structure

```
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
└── Makefile           # Short aliases for docker-compose exec commands (make help)
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