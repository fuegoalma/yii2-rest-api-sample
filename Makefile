DC  := docker compose
WEB := $(DC) exec -T web

.PHONY: help init setup up down restart rebuild logs sh \
        migrate migrate-main migrate-test \
        migration-create migration-update \
        seed seed-clear \
        test test-unit test-functional test-one build \
        cs-check cs-fix stan

help:
	@echo "Available targets:"
	@echo "  init                 Create .env from .env.example"
	@echo "  setup                Start Docker, install deps, create DBs, run migrations"
	@echo "  up / down / restart  Docker Compose lifecycle"
	@echo "  rebuild              Rebuild the web image via BuildKit/buildx (after changing the Dockerfile)"
	@echo "  logs                 Follow container logs"
	@echo "  sh                   Shell into the web container"
	@echo "  migrate              Run migrations on main + test DBs"
	@echo "  migrate-main         Run migrations on main DB only"
	@echo "  migrate-test         Run migrations on test DB only"
	@echo "  migration-create table=<table>   Generate migration(s) (quote the wildcard: table='*')"
	@echo "  migration-update table=<table>   Diff a table against migration history"
	@echo "  seed [count=N]       Seed the DB (default count: 10)"
	@echo "  seed-clear           Clear seeded data"
	@echo "  test                 Run the full test suite"
	@echo "  test-unit            Run unit tests only"
	@echo "  test-functional      Run functional tests only"
	@echo "  test-one suite=<unit|functional> class=<Cest[:testMethod]>   Run one class/test"
	@echo "  build                Rebuild Codeception support classes (after changing modules)"
	@echo "  cs-check             Show PSR-12 code style violations (dry-run)"
	@echo "  cs-fix               Auto-fix PSR-12 code style violations"
	@echo "  stan                 Run PHPStan static analysis"

init:
	./init.sh

setup:
	./setup.sh

up:
	$(DC) up -d

down:
	$(DC) down

restart:
	$(DC) restart

rebuild:
	$(DC) build web
	$(DC) up -d web

logs:
	$(DC) logs -f

sh:
	$(DC) exec web sh

migrate: migrate-main migrate-test

migrate-main:
	$(WEB) php yii migrate/up --interactive=0

migrate-test:
	$(WEB) php yii migrate-test/up --interactive=0

migration-create:
	$(WEB) php yii migration-creator/create "$(table)"

migration-update:
	$(WEB) php yii migration-creator/update "$(table)"

seed:
	$(WEB) php yii seeder/create $(count)

seed-clear:
	$(WEB) php yii seeder/clear

test:
	$(WEB) php vendor/bin/codecept run

test-unit:
	$(WEB) php vendor/bin/codecept run unit

test-functional:
	$(WEB) php vendor/bin/codecept run functional

test-one:
	$(WEB) php vendor/bin/codecept run $(suite) $(class)

build:
	$(WEB) php vendor/bin/codecept build

cs-check:
	$(WEB) php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(WEB) php vendor/bin/php-cs-fixer fix

stan:
	$(WEB) php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
