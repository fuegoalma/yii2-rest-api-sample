#!/usr/bin/env bash
#
# Smoke-tests the production image against a real database.
#
# `php --version` inside the image proves only that PHP starts. This boots the
# thing the CD pipeline would actually release — Apache, the document root, the
# migrations, the JWT config read from the environment — and asks it the
# questions a caller would. An image that builds but cannot answer /health has
# not been shown to be deployable.
#
# Used by .github/workflows/cd.yml and by `make smoke`, so the two cannot drift.
#
# Usage: docker/smoke.sh <image-tag> [port]

set -euo pipefail

IMAGE="${1:?usage: docker/smoke.sh <image-tag> [port]}"
PORT="${2:-8099}"
BASE="http://localhost:${PORT}"

NETWORK="smoke-$$"
DB="smoke-db-$$"
WEB="smoke-web-$$"

cleanup() {
    docker rm -f "$WEB" "$DB" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

step() { printf '\n\033[1m→ %s\033[0m\n' "$1"; }

step "Starting MySQL and the image under test"
docker network create "$NETWORK" >/dev/null
docker run -d --name "$DB" --network "$NETWORK" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=smoke \
    --health-cmd='mysqladmin ping -uroot -proot --silent' \
    --health-interval=3s --health-retries=20 \
    mysql:8.0 >/dev/null

docker run -d --name "$WEB" --network "$NETWORK" -p "${PORT}:80" \
    -e DB_HOST="$DB" -e DB_NAME=smoke -e DB_USER=root -e DB_PASSWORD=root \
    -e BASE_URL="$BASE" \
    -e COOKIE_VALIDATION_KEY=smoke-cookie-validation-key-0123456789 \
    -e JWT_SECRET=smoke-jwt-secret-at-least-32-characters-long \
    -e JWT_TTL=3600 \
    -e DEFAULT_PASSWORD=123456 \
    -e LOGIN_RATE_LIMIT_ATTEMPTS=100 -e LOGIN_RATE_LIMIT_WINDOW=60 \
    "$IMAGE" >/dev/null

# MySQL's own healthcheck goes green against the temporary server it runs during
# initialisation, so waiting on it is not the same as waiting for a database
# that accepts connections. Retrying the thing we actually need is.
step "Running migrations inside the image (waiting for the database)"
for attempt in $(seq 1 30); do
    if docker exec "$WEB" php yii migrate/up --interactive=0 >/dev/null 2>&1; then
        break
    fi
    if [ "$attempt" = 30 ]; then
        echo "the database never became reachable; last attempt was:"
        docker exec "$WEB" php yii migrate/up --interactive=0
        exit 1
    fi
    sleep 3
done

step "Waiting for Apache"
for _ in $(seq 1 30); do
    curl -fsS -o /dev/null "${BASE}/health" && break
    sleep 2
done

# Every check below reads the whole response into a variable before matching.
# Piping curl into `grep -q` makes grep exit on the first match, closing the
# pipe, and curl then dies with "Failure writing output to destination" — a
# failure of the test, not of the thing being tested.
step "GET /health reports the database is reachable"
grep -q '"status":"ok"' <<< "$(curl -fsS "${BASE}/health")"

step "GET /docs/openapi.yaml serves the published spec"
grep -q '^openapi:' <<< "$(curl -fsS "${BASE}/docs/openapi.yaml")"

step "Every response carries a correlation id"
grep -qi '^x-request-id:' <<< "$(curl -fsS -D - -o /dev/null "${BASE}/health")"

step "A protected endpoint refuses an anonymous caller"
test "$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/albums")" = 401

step "register → create an album → see it listed"
EMAIL="smoke-$(date +%s)@example.com"
TOKEN=$(curl -fsS -X POST "${BASE}/auth/register" -H 'Content-Type: application/json' \
    -d "{\"first_name\":\"Smoke\",\"last_name\":\"Test\",\"email\":\"${EMAIL}\",\"password\":\"secret123\"}" \
    | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')
test -n "$TOKEN"

curl -fsS -X POST "${BASE}/albums" -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' -d '{"title":"Smoke album"}' >/dev/null

grep -q 'Smoke album' <<< "$(curl -fsS "${BASE}/albums/my" -H "Authorization: Bearer ${TOKEN}")"

step "An error answers in the published shape"
grep -q '"error_code":"not_found"' <<< "$(curl -s "${BASE}/albums/999999" -H "Authorization: Bearer ${TOKEN}")"

printf '\n\033[32m✔ the production image is deployable\033[0m\n'
