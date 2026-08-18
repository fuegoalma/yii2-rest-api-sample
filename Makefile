DC  := docker compose
WEB := $(DC) exec -T web

.PHONY: help init setup up down restart rebuild logs sh \
        migrate migrate-main migrate-test \
        migration-create migration-update \
        seed seed-clear \
        refresh-token-prune rbac-assign \
        test test-unit test-functional test-one test-contract build \
        coverage coverage-html \
        cs-check cs-fix stan check audit hooks-install smoke

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
	@echo "  refresh-token-prune  Delete expired refresh tokens (run on a cron)"
	@echo "  rbac-assign role=<name> email=<email>   Assign a role to a user (bootstrap the first super_admin)"
	@echo "  test                 Run the full test suite"
	@echo "  test-unit            Run unit tests only"
	@echo "  test-functional      Run functional tests only"
	@echo "  test-one suite=<unit|functional> class=<Cest[:testMethod]>   Run one class/test"
	@echo "  test-contract        Run the OpenAPI contract gates only (tests/unit/contract/)"
	@echo "  build                Rebuild Codeception support classes (after changing modules)"
	@echo "  coverage             Run the suite with coverage and enforce the 100% gate"
	@echo "  coverage-html        Same as coverage, then print the HTML report path"
	@echo "  cs-check             Show PSR-12 code style violations (dry-run)"
	@echo "  cs-fix               Auto-fix PSR-12 code style violations"
	@echo "  stan                 Run PHPStan static analysis"
	@echo "  check                cs-check + stan + coverage — what CI runs"
	@echo "  audit                Report known advisories in the installed dependencies"
	@echo "  hooks-install        Install the git hooks (commit-msg, pre-commit, pre-push)"
	@echo "  smoke                Build the prod image and prove it is deployable"

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
	$(DC) up -d web cron worker

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

refresh-token-prune:
	$(WEB) php yii refresh-token/prune

rbac-assign:
	$(WEB) php yii rbac/assign "$(role)" "$(email)"

test:
	$(WEB) php vendor/bin/codecept run

test-unit:
	$(WEB) php vendor/bin/codecept run unit

test-functional:
	$(WEB) php vendor/bin/codecept run functional

test-one:
	$(WEB) php vendor/bin/codecept run $(suite) $(class)

# Inner-loop convenience only: the contract gates live in tests/unit/contract/,
# so a bare `codecept run` already picks them up. `make coverage` must stay a
# bare run — coverage is merged across suites at the end of one process.
test-contract:
	$(WEB) php vendor/bin/codecept run unit contract

build:
	$(WEB) php vendor/bin/codecept build

# Both suites must run in ONE codecept process: coverage from each suite is
# merged into a single report at the end of the run, so two separate runs would
# have the second overwrite the first. pcov is off by default in the image
# (see the Dockerfile), hence -d pcov.enabled=1 here and nowhere else.
coverage:
	$(WEB) php -d pcov.enabled=1 vendor/bin/codecept run \
		--coverage --coverage-xml --coverage-html --coverage-text --disable-coverage-php
	$(WEB) php tests/bin/coverage-check.php tests/_output/coverage.xml

# Infection executes *mutated* application code against a real database, so a
# mutant that disables a guard really does what the guard prevented — one that
# drops the `is_system` check deletes the migration-seeded roles, and every later
# test then fails for a reason that has nothing to do with it. That is why this
# gets its own throwaway schema, rebuilt from scratch each run, and never touches
# the database `make test` uses.
MUTATION_DB := $(shell grep -E '^TEST_DB_NAME=' .env 2>/dev/null | cut -d= -f2)_mutation

mutation: ## Run mutation testing (Infection) against a disposable database
	$(DC) exec -T db sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" \
		-e "DROP DATABASE IF EXISTS \`$(MUTATION_DB)\`; CREATE DATABASE \`$(MUTATION_DB)\`;"'
	$(DC) exec -T -e TEST_DB_NAME=$(MUTATION_DB) web php yii migrate-test/up --interactive=0
	$(DC) exec -T -e TEST_DB_NAME=$(MUTATION_DB) web \
		vendor/bin/infection --threads=$${threads:-4} --no-progress --no-interaction \
		--initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=/var/www/html"

load: ## Run the k6 load scenario against a running stack (vus=, duration=)
	docker run --rm --network host -e BASE_URL=$${BASE_URL:-http://localhost:8084} \
		-e VUS=$${vus:-10} -e DURATION=$${duration:-30s} \
		-v $(PWD)/tests/load:/scripts grafana/k6 run /scripts/api.js

coverage-html: coverage
	@echo "HTML report: tests/_output/coverage/index.html"

cs-check:
	$(WEB) php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(WEB) php vendor/bin/php-cs-fixer fix

stan:
	$(WEB) php vendor/bin/phpstan analyse --no-progress --memory-limit=512M

# Every gate CI runs, in the same order and with the same commands, so a red
# build is never the first time a problem is seen. The slow pair goes last, and
# `mutation` after `coverage`: there is no sense asking whether the tests assert
# anything until they pass and cover everything.
# Keep this list in step with .github/workflows/ci.yml — they must stay runnable
# both ways.
check: cs-check stan coverage mutation

# What .github/workflows/security.yml blocks on. Runtime dependencies only:
# what ships is what has to be clean, and a dev-tool advisory with no upstream
# fix would otherwise wedge every unrelated change.
audit:
	$(WEB) composer audit --locked --no-dev --no-interaction

# Runs on the HOST, not in the container: git hooks are host processes, and the
# hook scripts CaptainHook writes have to be executable where git runs them.
# The heavy tools they call are still invoked through `docker compose exec`.
# Also run automatically after `composer install` (see composer.json scripts).
hooks-install:
	php vendor/bin/captainhook install --force --no-interaction

# Builds the deployable image and exercises it against a real database — the
# same script .github/workflows/cd.yml runs, so the two cannot drift. Uses the
# host's docker directly: the thing under test *is* a container.
smoke:
	docker build --target prod -t yii2-rest-api:smoke .
	./docker/smoke.sh yii2-rest-api:smoke
