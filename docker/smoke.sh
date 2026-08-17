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
UPLOAD="/tmp/smoke-upload-$$.png"

cleanup() {
    docker rm -f "$WEB" "$DB" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -f "$UPLOAD"
}
trap cleanup EXIT

step() { printf '\n\033[1m→ %s\033[0m\n' "$1"; }

# Generated per run rather than written down. Nothing here needs a fixed value —
# the container is thrown away at the end of the script — and a literal that
# looks like a credential is a literal a secret scanner has to be told to
# ignore, which is a habit worth not starting.
JWT_SECRET=$(openssl rand -hex 32)
COOKIE_KEY=$(openssl rand -hex 16)

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
    -e COOKIE_VALIDATION_KEY="$COOKIE_KEY" \
    -e JWT_SECRET="$JWT_SECRET" \
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

# The image used to ship no php.ini at all, so the runtime ran on PHP's
# compiled-in defaults and would have printed internals on a fatal error.
step "The runtime is configured for production"
PHP_SETTINGS=$(docker exec "$WEB" php -r \
    'echo "ini=", (php_ini_loaded_file() ?: "NONE"),
           " display_errors=", (ini_get("display_errors") ?: "0"),
           " validate_timestamps=", ini_get("opcache.validate_timestamps");')
grep -qv 'ini=NONE' <<< "$PHP_SETTINGS"
grep -q 'display_errors=0' <<< "$PHP_SETTINGS"
grep -q 'validate_timestamps=0' <<< "$PHP_SETTINGS"

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

ALBUM_ID=$(curl -fsS -X POST "${BASE}/albums" -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' -d '{"title":"Smoke album"}' \
    | sed -n 's/.*"id":\([0-9]*\).*/\1/p')
test -n "$ALBUM_ID"

grep -q 'Smoke album' <<< "$(curl -fsS "${BASE}/albums/my" -H "Authorization: Bearer ${TOKEN}")"

# The cache policy for uploads lives in web/.htaccess and is applied by Apache,
# which no PHP test ever starts — this is the only place it can be checked.
step "An uploaded image is served with an immutable cache policy"
docker exec "$WEB" php -r \
    '$i = new Imagick(); $i->newPseudoImage(64, 64, "plasma:fractal"); $i->setImageFormat("png"); $i->writeImage("/tmp/smoke.png");'
docker cp "$WEB:/tmp/smoke.png" "$UPLOAD" >/dev/null

PHOTO_URL=$(curl -fsS -X POST "${BASE}/albums/${ALBUM_ID}/photos" \
    -H "Authorization: Bearer ${TOKEN}" \
    -F 'title=Smoke photo' -F "file=@${UPLOAD}" \
    | sed -n 's|.*"url":"\([^"]*\)".*|\1|p')
test -n "$PHOTO_URL"

PHOTO_HEADERS=$(curl -fsS -D - -o /dev/null "$PHOTO_URL")
grep -qi 'cache-control: public, max-age=31536000, immutable' <<< "$PHOTO_HEADERS"

# A 304 that drops Cache-Control resets the browser's freshness clock, so the
# policy has to survive revalidation — that is what `Header always` is for.
step "A 304 still carries the cache policy"
ETAG=$(sed -n 's/^[Ee][Tt][Aa][Gg]: //p' <<< "$PHOTO_HEADERS" | tr -d '\r')
NOT_MODIFIED=$(curl -fsS -D - -o /dev/null -H "If-None-Match: ${ETAG}" "$PHOTO_URL")
grep -q '^HTTP/[0-9.]* 304' <<< "$NOT_MODIFIED"
grep -qi 'cache-control: public, max-age=31536000, immutable' <<< "$NOT_MODIFIED"

step "Seeded demo images are cacheable, but expire so a release can replace them"
grep -qi 'cache-control: public, max-age=86400' \
    <<< "$(curl -fsS -D - -o /dev/null "${BASE}/default-images/1.jpg")"

step "An error answers in the published shape"
grep -q '"error_code":"not_found"' <<< "$(curl -s "${BASE}/albums/999999" -H "Authorization: Bearer ${TOKEN}")"

printf '\n\033[32m✔ the production image is deployable\033[0m\n'
