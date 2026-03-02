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
./init.sh
```

### 2. Configure your environment

Edit `.env` with your local settings:

```env
DB_HOST=db
DB_NAME=your_database
DB_USER=root
DB_PASSWORD=your_password
TEST_DB_NAME=your_database_test
```

### 3. Run setup

Run this after configuring `.env`. It starts Docker, installs dependencies, creates both databases (test and prod), and applies all migrations:

```bash
./setup.sh
```

---

## Database Migrations

Migrations are managed using the standard Yii2 migration tool.

#### Apply migrations to main database

```bash
docker-compose exec web php yii migrate/up --interactive=0
```

#### Apply migrations to test database

```bash
docker-compose exec web php yii migrate-test/up --interactive=0
```

---

## Migration Generator

The project uses [bizley/yii2-migration](https://github.com/bizley/yii2-migration) to generate migration files from the existing database schema.

#### Generate migrations for all tables

```bash
docker-compose exec web php yii migration-creator/create '*'
```

#### Generate a migration for a specific table

```bash
docker-compose exec web php yii migration-creator/create user
```

#### Generate an update migration for a specific table

Compares current schema with migration history and generates a diff:

```bash
docker-compose exec web php yii migration-creator/update user
```

---

## Seeders

Seeders populate the database with generated test data.

#### Generate seed data

```bash
docker-compose exec web php yii seeder/create
```

#### Clear all seeded data

```bash
docker-compose exec web php yii seeder/clear
```

---

## Testing

The project uses [Codeception](https://codeception.com/) for functional and unit tests. Tests run against the dedicated test database (`TEST_DB_NAME`).

#### Build test actor classes

Run this after adding or removing Codeception modules:

```bash
docker-compose exec web php vendor/bin/codecept build
```

#### Run all tests

```bash
docker-compose exec web php vendor/bin/codecept run
```

#### Run only functional tests

```bash
docker-compose exec web php vendor/bin/codecept run functional
```

#### Run only unit tests

```bash
docker-compose exec web php vendor/bin/codecept run unit
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
│   ├── repository/    # Repository layer (database access)
│   └── service/       # Service layer (business logic)
├── tests/
│   ├── functional/    # Functional (integration) tests
│   ├── unit/          # Unit tests
│   └── _support/      # Codeception helpers and base classes
├── init.sh            # First-time project initialization
└── setup.sh        # Database creation and migration runner
```

---

## API Endpoints

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
| DELETE | `/albums/{id}` | Delete an album |

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