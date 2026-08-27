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

### Startup order

`db` declares a `healthcheck` and every other service — `web`, `cron`, `worker` and `phpmyadmin` —
depends on it with `condition: service_healthy`. Plain `depends_on` waits only for the container to
*start*, which for MySQL is several seconds before it accepts a connection — long enough for `web`
and `worker` to come up against a database that is not there yet.

### PHP configuration

The official image ships **no `php.ini`**, so without one the runtime falls back to PHP's
compiled-in defaults — `display_errors` among them. The `base` stage installs `php.ini-production`
plus [`docker/php/app.ini`](../docker/php/app.ini) (`expose_php` off, 256M memory, 10M/12M upload
limits), and each stage layers its own file on top:

| | `dev` | `prod` |
| --- | --- | --- |
| `display_errors` | on — a fatal escaping Yii's handler should be visible | off |
| `opcache.validate_timestamps` | 1 — code is bind-mounted and changes | 0 — code is baked in, so there is nothing to revalidate |
| opcache / realpath cache sizes | defaults | sized for the full Yii tree |

`docker/smoke.sh` asserts the production image loads an ini, hides errors and does not revalidate.

### Database privileges

`DB_USER` is the account the **application** connects with, and `setup.sh` creates it with rights on
the two application databases only — no `CREATE USER`, no `GRANT`, no access to `mysql` or any other
schema. Migrations run as this user, so DDL on those two databases is included; anything above that
needs `DB_ROOT_PASSWORD`, which only `setup.sh` uses.

Setting `DB_USER=root` keeps the older single-account behaviour, and `setup.sh` then skips creating a
separate user.

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

### Caching

Stored images are served by Apache as plain files — the application never sees those requests — so
the freshness policy is stated in [`web/.htaccess`](../web/.htaccess):

| Path | `Cache-Control` |
| --- | --- |
| `/uploads/albums/**` | `public, max-age=31536000, immutable` |
| `/default-images/**` | `public, max-age=86400` |

An upload can carry the maximum lifetime because its URL is immutable **by construction**:
`ImageStorage` names every file with a 40-character random string, and `PhotoUpdateForm` accepts a
title change only, so nothing can replace the bytes behind an existing URL. Seeded demo images keep
fixed names and a release can change them, so they revalidate daily.

That precondition is load-bearing — a future "replace this photo's file" feature must mint a new
file name, or clients will hold stale bytes for a year. See
[ADR 12](adr/0012-immutable-cache-for-uploaded-images.md).

Because no PHP test starts Apache, the policy is verified in
[`docker/smoke.sh`](../docker/smoke.sh) against the production image: upload through the API, assert
the header, then repeat with `If-None-Match` and assert the `304` still carries it.

---

## CORS

The filter is attached to every REST controller by [`ApiControllerTrait`](../controllers/basic/ApiControllerTrait.php): all standard methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`), all request headers, credentials **off**, and a 24-hour preflight cache (`Access-Control-Max-Age: 86400`).

The allowed origins come from **`CORS_ALLOWED_ORIGINS`** (comma-separated), which defaults to `*`.
A wildcard is the right answer here — the API is token-authenticated and sets no cookies, so a
browser reading it cross-origin still needs a token it does not have — but it is a per-deployment
decision, so it lives in `config/params.php` rather than in a trait shared by every controller.
`ApiControllerTrait` reads that param **without a fallback**: wiring that has gone dead must fail
loudly rather than silently restore a wildcard nobody chose.

Credentials stay off regardless. `Origin: *` with `Allow-Credentials: true` is a combination
browsers reject anyway, and with a narrowed origin list it is the point at which a wildcard would
stop being merely permissive.

`OPTIONS` preflights are deliberately exempt from everything that could reject them:

- **Never authenticated** — the authenticator is attached *after* the CORS filter with `except => ['options']`, so a preflight needs no bearer token.
- **Never throttled** — [`RateLimiter`](../components/RateLimiter.php) passes `OPTIONS` straight through, so a browser's preflights can't burn the caller's auth-endpoint budget.

### The filter runs first, and that is not a detail

`apiBehaviors()` **prepends** `corsFilter` to the behaviours array rather than assigning it, because
filters run in declaration order and everything after it can refuse the request: the throttle, the
authenticator, the verb filter, content negotiation. A refusal produced *ahead* of the CORS filter
carries no `Access-Control-*` at all, so a browser hands the page a network error instead of the
response — and the responses most worth reading (`401`, `429`) are exactly the ones produced before
the action runs.

Assigning was not enough for a subtle reason worth knowing: `yii\rest\Controller::behaviors()`
already declares a `rateLimiter` key, so `AuthController` overwriting it **keeps the parent's
position** — ahead of a `corsFilter` appended at the end. The 429 was the one response a browser
could not read, on the endpoint whose entire purpose is telling a client how long to wait.
[`CorsCest`](../tests/functional/CorsCest.php) pins it.

### What a cross-origin client may read

A browser hides every response header outside the CORS safelist, and Yii's `Cors` emits
`Access-Control-Expose-Headers` **only when the key is present in the `cors` array** — so leaving it
out is silent: the API keeps sending the header and no cross-origin client can see it. That is
exactly what happened here, and it was found by someone writing a browser client, not by the suite:
`ETag`, `Retry-After` and `X-Request-Id` were all sent and all invisible, which made
`Retry-After` unreadable on a `429` and conditional GET unreachable from a browser.

The list is composed from the emitting components' own constants
(`ConditionalGet::HEADER`, `RateLimiter::HEADER`, `CorrelationId::HEADER`), so it cannot come to
name a header that has been renamed or has stopped being sent, and
[`CorsCest`](../tests/functional/CorsCest.php) asserts the headers **on a response** rather than the
keys in a behaviours array — a unit test reading the same config could not have caught the omission,
because the config was exactly what somebody had written.

---

## Response headers

| Header | On | Set by |
| --- | --- | --- |
| `X-Request-Id` | every response | [`CorrelationIdBootstrap`](../components/CorrelationIdBootstrap.php) |
| `ETag` | `200` from a `GET` | [`ConditionalGet`](../components/ConditionalGet.php) |
| `Cache-Control: private, no-cache` | `200` from a `GET` | [`ConditionalGet`](../components/ConditionalGet.php) |
| `Vary: Authorization, Origin` | `200` from a `GET` | [`ConditionalGet`](../components/ConditionalGet.php) |
| `Retry-After` | `429` | [`RateLimiter`](../components/RateLimiter.php) |

The caching pair is what makes the `ETag` reach a browser at all. A browser revalidates only what it
was told it may store, so with no `Cache-Control` it keeps nothing, never sends `If-None-Match`, and
never reaches the `304` — the filter was machinery no browser client could get to. `no-cache` is not
"do not store": it is *store and revalidate every time*, which is what makes it safe for a body
answered per bearer token, since every reuse is re-authorized by the request that revalidates it.
`private` keeps the copy out of shared caches.

`Vary` names what the stored copy is keyed by. `Origin` is in there because
`Access-Control-Allow-Origin` echoes the caller whenever the allowed list is not a wildcard, so the
grant differs per origin while a browser's cache key does not include `Origin` on its own. It is set
beside the freshness directive rather than by the CORS filter: one `Vary`, written in the one place
that decides the response may be stored. See
[ADR 13](adr/0013-conditional-get-saves-bandwidth-not-work.md).

---

## The response envelope, and what an error says

Every response is `{"success": bool, "data": ..., "code": int}`, built by
[`BasicResponse`](../models/dto/BasicResponse.php). An index action's
`DataProviderInterface` result is serialized to `data.items` + `data.pagination`
via [`PaginationMeta`](../models/dto/PaginationMeta.php); anything `≥ 400` puts
`{"message": ..., "error_code": ..., "error": {...}}` **inside** `data`, so
validation errors surface at `data.error` and never at the top level.

Two writers produce it and they must agree: [`ApiSerializer`](../components/ApiSerializer.php)
wraps normal REST responses, [`JsonErrorHandler`](../components/JsonErrorHandler.php)
renders uncaught exceptions. New endpoints get this automatically — don't
hand-build an envelope.

Four rules make an error worth reading, and each exists because the opposite was
once true here. The full argument is in
[ADR 11](adr/0011-machine-readable-error-codes.md); what follows is what you need
to use them.

**Branch on `data.error_code`, never on the prose.** It defaults to the status
(`not_found`, `conflict`, …) via [`ApiErrorCatalog`](../components/ApiErrorCatalog.php),
the single `status → [code, message]` table both writers read. An endpoint that
can refuse for several distinguishable reasons narrows it:

| `error_code` | Raised by |
| --- | --- |
| `auth.invalid_credentials` | bad login; a wrong `current_password` on `PUT /users/me/password` |
| `refresh_token.invalid` / `.expired` / `.reused` | `RefreshTokenService::consume()` — `.reused` is a security event |
| `password_reset.invalid` / `.expired` | spending a reset token |
| `email_verification.invalid` / `.expired` | spending a verification token |
| `role.system_immutable` / `.escalation_denied` / `.last_manager` | the three RBAC refusals |
| `payload.too_large` | [`RequestSizeLimit`](../components/RequestSizeLimit.php), before PHP discards the body |

Adding one means a new throw site in [`models/exception/`](../models/exception/)
and a line in the `error_code` description in `config/openapi.yaml`. Nothing
else.

**`data.error` is strictly `field => string[]`,** and `{}` — never `[]` — when
there is nothing to say. Debug detail lives under its own `data.debug`, present
only under `YII_DEBUG`. Mixing the two meant a client could not read field errors
without first guessing which entries were a backtrace.

**A deliberate message survives verbatim.** A `yii\base\UserException` (every
`HttpException` is one) carries wording the application chose — a `409` naming
the invariant it refused — and the catalog only fills silence. Anything else is a
bug report addressed to us: a driver exception can name tables or credentials, so
outside a debug environment it is replaced. That decision lives in
[`ApiError::fromException()`](../models/dto/ApiError.php), deliberately apart from
the handler, because "may this string leave the building" is a security question
worth testing directly. `JsonErrorHandler::$debugDetail` defaults to **false** —
a handler nobody configured is the one running where nobody was watching.

---

## Background Jobs

Slow, retriable side-effects are pushed onto a queue instead of blocking the request. Everything depends on a small seam in [`models/contract/queue/`](../models/contract/queue/): a **job** ([`JobInterface`](../models/contract/queue/JobInterface.php)) is a plain serializable message that names its **handler** ([`JobHandlerInterface`](../models/contract/queue/JobHandlerInterface.php)), which holds the behaviour and takes its services by constructor injection. That split is what lets a job survive `serialize()` without carrying a database connection or a filesystem client around with it.

Resolving a handler by name is the one lookup that can only happen at run time, so it is isolated behind [`JobRunnerInterface`](../models/contract/queue/JobRunnerInterface.php) — the drivers below never touch the DI container themselves. Two drivers implement [`QueueInterface`](../models/contract/queue/QueueInterface.php):

- **`DbQueue`** (default) — persists jobs to the `queue_job` table; the long-running **`worker`** service (`yii queue/listen`) drains up to 100 pending jobs per pass continuously, sleeping only when idle and shutting down gracefully on `SIGTERM` (`docker stop`). `yii queue/run` drains once (handy for CI/manual runs).
- **`SyncQueue`** — runs jobs in-process; bound in tests so they don't depend on a running worker.

### Delivery

Each row is **claimed** before it runs: a pass lists the due ids, then takes each one with a
conditional `UPDATE queue_job SET reserved_at = NOW() WHERE id = ? AND <still due>`. Exactly one
worker's update can match, so `docker compose up --scale worker=3` is safe and no transaction is
needed to arbitrate. A claim lapses after `DbQueue::RESERVATION_TIMEOUT` (300 s), which is how a
worker killed mid-job gives its rows back.

That makes delivery **at-least-once**: a worker that dies after a job's side effect but before its
row is deleted will run the job again. Handlers must tolerate a repeat.

A job that throws is retried (logged as a warning each time) up to 3 attempts, each after an
exponential backoff written to `available_at` — 5 s, doubling, capped at 300 s. The backoff is not
cosmetic: the worker loop comes round every 3 s, so without it all three attempts are spent in under
ten seconds and a fault that would have cleared never gets the chance.

A job that exhausts its attempts moves to **`queue_job_failed`** ([`FailedQueueJob`](../models/db/FailedQueueJob.php))
with its payload, correlation id and last error, so it can be inspected and replayed. Deleting it —
which is what used to happen — left the work undone with only a log line to say it had existed.

```sql
SELECT id, attempts, last_error, failed_at FROM queue_job_failed ORDER BY failed_at DESC;
```

### The three jobs, and why each is one

| Job | Enqueued by | Why it is not inline |
| --- | --- | --- |
| `DeleteAlbumDirectoryJob` | permanently deleting an album | the rows are already committed, so a filesystem error afterwards would answer `500` for an operation that succeeded — and a client retrying that `500` gets a `404` |
| `DeletePhotoFileJob` | deleting one photo | same reason, one file instead of a directory |
| `SendEmailJob` | password reset, email verification | an SMTP conversation is a third-party network call inside a request the user is waiting on, and a transient failure should be retried rather than become a `500` |

The first two are about **correctness, not speed**, which is the part worth
remembering: once the rows are gone the deletion has happened as far as any
caller can tell. Removing bytes is the queue's job everywhere — no service
deletes a file inline.

Each job pairs with a handler (`DeleteAlbumDirectoryHandler`, and so on) that
takes its services by constructor injection. Services belong on the handler,
never on the job: a job is serialized into a table and must carry only plain
data. The shared type guard — a handler satisfying itself that the payload it was
handed is the one it knows how to read — lives once, on
[`BaseJobHandler`](../models/jobs/basic/BaseJobHandler.php).

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

## Observability

`web` and `worker` are separate containers writing to the same place, so every
line has to say which unit of work it belongs to.

### The correlation id

[`CorrelationIdInterface`](../models/contract/CorrelationIdInterface.php) is that
id — a **singleton**, so every consumer in the process sees the same value. It is
*renewed* at exactly two boundaries, and nowhere else:

| Boundary | Renewed from | By |
| --- | --- | --- |
| the start of a web request | the caller's `X-Request-Id`, or a fresh id | [`CorrelationIdBootstrap`](../components/CorrelationIdBootstrap.php) |
| the start of a queued job | `queue_job.correlation_id`, written when it was pushed | `DbQueue::runOne()` |

A renewal rather than a constructor argument because one process serves many
units of work — the worker runs for days, and an id that outlived its request
would file every later line under the first one.

**An inbound `X-Request-Id` is honoured and sanitised.** A caller's own id is
adopted so their logs and ours can be read side by side, but the value is echoed
in a response header *and* written into log lines: unfiltered, that is header
injection and log forging in the same field. Everything outside `[A-Za-z0-9._-]`
is stripped and the result capped at 64 characters, falling back to a generated
id when nothing usable is left.

The header is set **before the action runs**, which is what puts it on error
responses too — exactly the answer a caller quotes in a bug report. A browser can
read it only because it is named in `Access-Control-Expose-Headers` (see
[What a cross-origin client may read](#what-a-cross-origin-client-may-read)).

```bash
curl -i http://localhost:8084/health -H 'X-Request-Id: my-trace-1'
# X-Request-Id: my-trace-1
```

### Structured logs

[`JsonLogTarget`](../components/log/JsonLogTarget.php) replaces `FileTarget` in
both `config/web.php` and `config/console.php`: one JSON object per line on
**stderr**, carrying `correlation_id`, level, category, route, `user_id` and the
message.

stderr rather than a file under `runtime/` because `docker compose logs` is where
these are read, and a log nobody can reach from outside the container is a log
nobody reads. A job inherits the id of the request that enqueued it, so
`docker compose logs web` and `logs worker` tell one story — without it, "the
album was deleted but its directory is still there" is untraceable past the
response.

```bash
docker compose logs web worker | grep my-trace-1
```

One setting on that target is load-bearing: `logVars = []`. Yii's `Target`
otherwise appends a dump of `$_GET`/`$_POST`/`$_SERVER` to every logged error,
and in a container the environment *is* the configuration — so that dump wrote
`JWT_SECRET`, `DB_PASSWORD` and `COOKIE_VALIDATION_KEY` into the log stream on
every failure. It lives on the **target class**, not in a config file, so it
holds wherever the target is used; it also cannot go on the `log` component,
where `logVars` is not a `yii\log\Dispatcher` property and the application
refuses to boot.

See [ADR 17](adr/0017-one-correlation-id-renewed-at-two-boundaries.md).

---

## Metrics

`GET /metrics` exposes operational gauges in the **Prometheus text exposition
format** — a plain `yii\web\Controller` with `FORMAT_RAW`, deliberately *not* the
JSON envelope, since Prometheus parses a specific line format.

```bash
curl http://localhost:8084/metrics
```

```
# HELP queue_jobs_pending Jobs waiting to be claimed by a worker.
# TYPE queue_jobs_pending gauge
queue_jobs_pending 0
```

| Metric | What a rise means |
| --- | --- |
| `queue_jobs_pending` | the worker is gone or wedged |
| `queue_jobs_reserved` | jobs are being claimed but not finishing |
| `queue_jobs_failed_total` | jobs are exhausting their attempts and nobody has looked |
| `users_total` | growth, and a sanity check after a destructive `seeder/clear` |
| `one_time_tokens_live` | unspent reset and verification tokens outstanding |

**Values are read from the database at scrape time**, not accumulated in the
process: PHP shares no memory between requests, so a counter would report one
container's slice with no way to tell which.

Public and unauthenticated for the same reason as `/health` — a scraper is
infrastructure and has no account. That is only acceptable because nothing
exposed is per-user; **a metric that leaks something means moving this endpoint
behind the network boundary.** Request rate and latency are deliberately absent:
they belong to the web server or a sidecar, which still sees the requests PHP
never got to serve.

See [ADR 18](adr/0018-metrics-are-read-at-scrape-time.md).

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
- **Genuinely unreachable code** is marked with `@codeCoverageIgnore` **and a comment explaining why it cannot be reached**. Unreachable is a high bar: it means unreachable by construction, not merely inconvenient to test. See `RolesController::accessResource()` for the shape of an acceptable justification.
- Don't add `@covers` / `#[CoversClass]` annotations — Codeception treats them as strict, silently narrowing what a test is credited with covering.

---

## Mutation Testing

Coverage answers "was this line executed". It does not answer "would anyone
notice if it were wrong", and a test that calls a method and asserts nothing is
worth exactly 100% of its lines.

```bash
make mutation                  # threads=4 by default
make mutation threads=1
```

[Infection](https://infection.github.io/) changes the code on purpose — flips a
comparison, drops a method call, swaps `&&` for `||` — and reports how many of
those changes the suite noticed. The baseline is **MSI ~79%, mutation code
coverage 100%** (754 mutants, ~154 surviving), over `components/`, `models/service/`, `models/repository/` and
`models/form/`, in about a minute. The floor lives in
[`infection.json5`](../infection.json5) (`minMsi: 76`, a little under the
measurement so the gate catches a regression without failing on the couple of
points that move as coverage shifts between suites), and CI enforces it.

Three things about the setup are non-obvious, and none should be undone.

**It runs against a disposable database.** Infection executes *mutated* code
against a real schema, so a mutant that removes the `is_system` guard genuinely
deletes the seeded roles and every later test fails for unrelated reasons.
`make mutation` drops and rebuilds `<TEST_DB_NAME>_mutation` per run and never
touches the database `make test` uses.

**Only the unit suite participates** (`--skip functional`), and not for speed:
the functional suite truncates one shared database, so across threads workers
clobber each other and a mutant gets scored "killed" by another worker's
`TRUNCATE`. The same file measured 100% MSI at four threads and 97% at one.
**The score is therefore a lower bound** — `RoleService`'s ~30 survivors all die
against the full suite.

**A survivor is a candidate, not a defect.** Apply it, run `make test`, and write
a test only if it really survives. Many are equivalent mutants nothing can kill
(`?? 0` → `?? -1` where neither value crosses the threshold), and chasing 100%
produces tests that assert the implementation rather than the behaviour.

Two pieces of wiring make it work at all: `composer.json` declares a **PSR-4
mapping for `app\`** (Yii resolves those classes through its own alias
autoloader, which only exists after the framework boots), and pcov is enabled via
`--initial-tests-php-options` because `-d` on the Infection process does not reach
the child test run — the failure mode is a bare `exit code 143` reported as
"tests must be in a passing state".

See [ADR 6](adr/0006-hundred-percent-coverage-as-a-gate.md) for what the two
gates buy together, including the blind spot neither can see.

---

## The Contract Gates

`config/openapi.yaml` is the published source of truth for the API, written by
hand — and **checked, not trusted**. Eight gates in
[`tests/unit/contract/`](../tests/unit/contract/) answer the one question the
rest of the suite cannot: *does the code do what the published document
promises?*

```bash
make test-contract     # only the gates; a bare `codecept run` picks them up too
```

| Gate | Holds |
| --- | --- |
| `RouteContractTest` | every route the app answers is documented, every documented operation is **actually routable** (through the real `UrlManager`, so a shadowed rule is caught), every documented route targets a real action |
| `ResponseSchemaContractTest` | each response schema's property set equals the `fields()`/`toArray()` producing it — and every schema is either mirrored or listed in `NOT_MIRRORED` with a written reason |
| `SearchFormContractTest` | each `*SearchForm`'s sortable whitelist and filters equal what the index operation documents; no documented query parameter is left unclaimed |
| `WriteFormContractTest` | each request schema's attributes, `required` list and length limits equal the form that validates them, **probed at the boundary in both directions** |
| `PermissionContractTest` | the catalog (read from the test DB — the migration's *effect*), the `x-permission` extensions and the permission literals in the code describe the same model; super_admin holds everything |
| `UploadParamsContractTest` | `photo_max_upload_bytes`, `upload_max_filesize` and `post_max_size` stay ordered, and match the limit and conversion numbers the document publishes |
| `SpecIntegrityContractTest` | the document parses, every `$ref` resolves, every operation declares a response — the external linter's job, done in-toolchain so a PHP image needs no Node |
| `HeaderContractTest` | the headers the document promises are the headers the filters send |

They live in a subdirectory of the *unit* suite rather than a suite of their own:
Codeception's loader recurses, so they need no configuration, and every gate needs
a booted application anyway. Shared readers (`OpenApiSpec`, `RouteTable`,
`ContractTestCase`) live in `tests/_support/` because Codeception autoloads only
that directory, and the suite loader's `~Test\.php$~` pattern would never load a
`*TestCase.php` from beside the gates.

Four standing rules:

- **Every gate is a set difference against an explicit registry plus an explicit,
  commented skip list** — never a spot check. Add a schema, operation, form or
  permission and the build stays red until it has been *placed*.
- **Always both directions.** "Documented but not implemented" is as much a
  defect as "implemented but not documented".
- **Where the document carries something only in prose** — sortable attributes,
  accepted upload extensions, the 500×500/quality-80 numbers — the gate parses
  that prose rather than keeping a transcribed copy, with an "it matched at all"
  assertion so a reword fails instead of comparing nothing.
- **A contract test asserts a shape, never a behaviour.** If deleting
  `tests/unit/contract/` would drop line coverage, the missing test is
  behavioural and belongs in `tests/unit/` or `tests/functional/`.

Because the gates are green on a tree that already agrees, **prove a new gate
bites by mutation**: break one thing (delete a route, rename a field, drop a
sortable attribute), confirm the failure names the culprit, restore. Record it in
the PR.

See [ADR 5](adr/0005-openapi-as-a-checked-contract.md).

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

On top of PSR-12 the config enables one **risky** rule, `declare_strict_types`: every PHP file
declares `declare(strict_types=1)`, and a new file that forgets it fails `make cs-check`. The rule
is classified risky because it changes runtime behaviour — scalar arguments are no longer coerced
at call sites inside the file — which is the point; the full suite is what proves nothing depended
on the coercion.

The config appends itself to its own finder. The `pre-commit` hook passes staged files explicitly
rather than using the finder's paths, so without that line the hook would check a file `make
cs-check` never saw.

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

The project is analysed with [PHPStan](https://phpstan.org/) (level 6, configuration in `phpstan.neon.dist`).

```bash
make stan
```

Level 6 adds the missing-type checks: every `array` must declare a value type
(`array<string, mixed>`, `string[]`, `list<int>`) and every property must have one. Declare it on
the contract in `models/contract/` and the implementations inherit it — that is why the whole tree
needed no `ignoreErrors` entry.

Level 6 is the practical ceiling on this stack rather than a compromise: levels 7–8 turn Yii's
untyped `ActiveRecord` magic properties and `Yii::$app->…` component access into a flood of
findings that say more about the framework's own annotations than about this code.

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

Four GitHub Actions workflows. The badges at the top of the [README](../README.md) reflect the
latest runs on the default branch.

### CI ([`ci.yml`](../.github/workflows/ci.yml))

Runs on pushes to `master` and on every pull request — narrowed that way so a branch with an open
PR is not built twice for the same commit, with a `concurrency` group that cancels superseded runs
everywhere except `master`, where CD chains off the completed run.

It installs dependencies, spins up a MySQL service, and runs **six gates in the same order as
`make check`**:

| # | Gate | Local equivalent |
| --- | --- | --- |
| 1 | `composer audit` on runtime dependencies | `make audit` |
| 2 | Code style (PHP CS Fixer) | `make cs-check` |
| 3 | Static analysis (PHPStan) | `make stan` |
| 4 | The full Codeception suite | `make test` |
| 5 | [The 100% coverage gate](#code-coverage) | `make coverage` |
| 6 | [The mutation gate](#mutation-testing) | `make mutation` |

`make check` runs 2, 3, 5 and 6 — the tests run *inside* the coverage step, since the pass/fail
signal is identical and a second full run would only double the wall clock. Mutation goes last both
places: there is no sense asking whether the tests assert anything until they pass and cover
everything, and Infection executes mutated code against the database, which would leave it unusable
for anything after it.

CI runs natively on the runner (PHP 8.5 + Imagick + pcov via `shivammathur/setup-php`), not through
Docker, so the workflow's `env:` block and `ini-values` have to mirror `.env.example` and
[`docker/php/app.ini`](../docker/php/app.ini) — `UploadParamsContractTest` fails the build if the
runtime accepts less than the app promises. Keep the steps in step with the `Makefile` targets:
they must stay runnable both ways.

The HTML coverage report is uploaded as a build artifact on every run, red or green — a gate that
passes at exactly 100% is the run you most want the report from when the next commit drops below it.

### Security ([`security.yml`](../.github/workflows/security.yml))

A different question from CI: not "is the code well formed" but "what are we shipping alongside it,
and has anything secret leaked". CodeQL has no PHP analyzer, so the equivalent here is two jobs:

- **`composer audit`** — blocking for runtime dependencies, **advisory-only for dev tooling**. A
  static analyser that only reads our own source is a different risk from code running in
  production, and a transitive advisory with no upstream fix would otherwise wedge every unrelated
  pull request.
- **`gitleaks`** over the **full history** — a secret deleted in a later commit is still published,
  and a leaked `JWT_SECRET` here would forge access tokens for every account.

It also runs **weekly**, because an advisory published against unchanged code is exactly what a
commit-triggered run can never catch. `make audit` is the local half.

Dependency updates come from [`dependabot.yml`](../.github/dependabot.yml) across three ecosystems:
`composer` (grouped, so a week's patches arrive as one PR that still passes every gate),
`github-actions` (the workflows pin actions by major version, and this is the only thing stopping
them ageing onto a deprecated runner) and `docker` (the `Dockerfile`'s base images).

### CD ([`cd.yml`](../.github/workflows/cd.yml))

Chained to CI via `workflow_run`, so it triggers only after the **CI** workflow completes on
`master` and a red CI never deploys — and its badge stays neutral rather than red. Two jobs:

- **`build-image`** builds the `prod` stage of the [`Dockerfile`](../Dockerfile) with Buildx and GHA
  layer cache, then smoke-tests it with [`docker/smoke.sh`](../docker/smoke.sh) — the same script
  `make smoke` runs, so the two cannot drift. It carries the explicit
  `if: github.event.workflow_run.conclusion == 'success'` guard.
- **`deploy`** runs through a `production` GitHub Environment so it appears in the repository's
  Environments/Deployments tab. It has no `if` of its own and is gated indirectly by
  `needs: build-image`. The release itself is **simulated** — this sample deliberately provisions no
  real server.

The image is built with `push: false` / `load: true` and never reaches a registry.

### Proving the image is deployable

```bash
make smoke     # build the prod image and exercise it against a real MySQL
```

[`docker/smoke.sh`](../docker/smoke.sh) boots the production image against a real database, runs the
migrations *inside it*, and asks the questions a caller would: `/health`, the published spec, the
`X-Request-Id` header, an anonymous `401`, a register → create album → list round trip, and the
documented error shape.

**It is also the only place two whole subsystems can be checked at all**, because no PHP test starts
Apache: the [static-image cache policy](#caching) from `web/.htaccess` (including that a `304` still
carries it) and the production PHP configuration (an ini is loaded, `display_errors` off, opcache
not revalidating). Anything whose behaviour belongs to the web server or the image rather than to
the application goes here.

It replaced a `php --version` check that could only prove PHP starts, and writing it immediately
caught two defects that check could never see: **console commands failed in the production image**
(the entry scripts hard-coded `YII_ENV=dev`, so the app bootstrapped a debug module
`composer install --no-dev` had not installed), and **every logged error dumped `$_SERVER`** —
`JWT_SECRET`, `DB_PASSWORD` and `COOKIE_VALIDATION_KEY` included — into the log stream.

Every check reads the whole response into a variable before matching: piping `curl` into `grep -q`
makes grep exit on the first match and curl die of `SIGPIPE`, which is a failure of the test rather
than of the thing tested.

### Git hooks

```bash
make hooks-install    # also run automatically after `composer install`
```

[`captainhook.json`](../captainhook.json) declares three hooks, split by what is worth paying for
when:

| Hook | Runs | Why there |
| --- | --- | --- |
| `commit-msg` | Conventional Commits regex + subject/body length | the history is the first thing a reviewer reads, and a convention only holds while something checks it |
| `pre-commit` | PHP CS Fixer over the **staged** PHP files | committing stays fast |
| `pre-push` | PHPStan + the full suite | the two gates that only mean anything whole-project — too slow per commit, cheap enough per push |

The hooks run on the **host**, because git hooks are host processes, but invoke the heavy tools
through `docker compose exec`. There is one toolchain, not two.

### Docs publishing ([`pages.yml`](../.github/workflows/pages.yml))

Chains off a green CI on `master` and publishes [`config/openapi.yaml`](../config/openapi.yaml) to
GitHub Pages as a self-contained Redoc page, plus the raw document. The application already serves
`/docs`, but only where an instance is running; this is the copy anyone can read.

Redoc renders to a single HTML file on purpose — the Swagger UI the app serves depends on a CDN
staying up, which is fine for a developer with the stack running and wrong for a published page. The
same workflow lints the document (advisory).

---

## Project Structure

```
├── .github/
│   ├── workflows/     # ci.yml, security.yml, cd.yml, pages.yml
│   └── dependabot.yml # composer + github-actions + docker updates
├── Dockerfile         # Multi-stage image: base → dev → prod
├── .dockerignore      # Build-context excludes for the prod image
├── docker-compose.yml # Local dev stack (web + db + phpMyAdmin + cron + worker), builds the dev stage
├── docker/
│   ├── cron/          # Cron service: entrypoint + the versioned schedule (crontab)
│   ├── php/           # app.ini (shared) + dev.ini / prod.ini (per stage)
│   └── smoke.sh       # Boots the prod image and proves it is deployable (`make smoke`)
├── commands/          # Console commands (seeders, RBAC bootstrap, refresh-token pruning, queue worker)
├── components/        # App components: JWT, rate limiter, image processing, queue drivers, response serialization
│   ├── image/         # ImagickWebpEncoder — the ImageEncoderInterface implementation
│   ├── log/           # JsonLogTarget — structured lines on stderr
│   ├── mail/          # LogMailer — the MailerInterface implementation
│   └── queue/         # DbQueue, SyncQueue, ContainerJobRunner
├── config/            # Application configuration
│   ├── db.php         # Main database config (reads from .env)
│   ├── test_db.php    # Test database config (reads from .env)
│   ├── web.php        # Web application config
│   ├── console.php    # Console application config
│   ├── test.php       # Test overrides (SyncQueue, @runtime storage, strict routing)
│   ├── di.php         # Container bindings — every interface → implementation
│   ├── params.php     # Published constants (upload limits, encoder numbers, CORS origins)
│   ├── url_rules.php  # Shared REST route table (used by web + test)
│   └── openapi.yaml   # OpenAPI 3.0 spec — source of truth for the API docs (/docs)
├── controllers/       # API controllers
├── docs/
│   ├── handbook.md    # This file
│   └── adr/           # Architecture decision records
├── migrations/        # Database migrations
├── models/
│   ├── contract/      # Interfaces (repository, service, queue & image contracts)
│   ├── db/            # ActiveRecord models
│   ├── dto/           # Data Transfer Objects
│   ├── exception/     # Exceptions carrying a narrowed `error_code`
│   ├── form/          # Form requests (validation of incoming request data)
│   ├── jobs/          # Background-queue jobs and their handlers
│   ├── repository/    # Repository layer (database access)
│   └── service/       # Service layer (business logic)
├── web/               # Document root: entry script, .htaccess, uploads/, default-images/
├── codeception.yml    # Test runner config (paths, modules, coverage scope)
├── phpstan.neon.dist  # Static analysis config (level 6)
├── infection.json5    # Mutation testing scope and MSI floor
├── captainhook.json   # commit-msg / pre-commit / pre-push hooks (`make hooks-install`)
├── .gitleaks.toml     # Secret-scanning config used by security.yml
├── .php-cs-fixer.dist.php # PSR-12 + strict_types code style config
├── tests/
│   ├── functional/    # Functional (integration) tests
│   ├── unit/          # Unit tests
│   │   └── contract/  # The eight gates holding the code to config/openapi.yaml
│   ├── _support/      # Codeception helpers and base classes (BaseCest, BaseUnitTest, OpenApiSpec)
│   ├── load/          # api.js — the k6 scenario (`make load`)
│   └── bin/           # coverage-check.php — the 100% coverage gate
├── init.sh            # First-time project initialization (`make init`)
├── setup.sh           # Database creation and migration runner (`make setup`)
└── Makefile           # The entry point for every command in this project (make help)
```

---

## Authentication

All resource endpoints require a JWT. The `/auth/*` endpoints are public and
rate-limited per IP; the token-issuing ones return a pair — a short-lived
**access token** (a stateless JWT) for the `Authorization` header and a
long-lived **refresh token** (an opaque, server-stored credential) to obtain a
new pair without re-entering credentials. See
[ADR 1](adr/0001-two-token-authentication.md) for why the two differ in kind.

What follows is a walkthrough of the flow. The endpoints themselves — request
bodies, status codes, error shapes — are in the
[OpenAPI document](https://fuegoalma.github.io/yii2-rest-api-sample/), which is
the only place they are described.

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
        "access_token": "<a signed JWT>",
        "refresh_token": "<an opaque random string, not a JWT>",
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

### Changing a password

```bash
curl -X PUT http://localhost:8084/users/me/password \
    -H 'Authorization: Bearer <access_token>' \
    -H 'Content-Type: application/json' \
    -d '{"current_password": "secret123", "password": "a-better-one"}'
```

`current_password` is required even though the caller is already authenticated: a
bearer token left on a shared machine must not be enough to take an account over
for good. There is **no id in the route**, so an admin cannot use this against
somebody else — that is what the RBAC-gated user endpoints are for.

### Recovering a forgotten one

```bash
curl -X POST http://localhost:8084/auth/forgot-password \
    -H 'Content-Type: application/json' -d '{"email": "user@example.com"}'
# 204, always

curl -X POST http://localhost:8084/auth/reset-password \
    -H 'Content-Type: application/json' \
    -d '{"token": "<from the message>", "password": "a-better-one"}'
```

**`forgot-password` always answers `204`.** A different answer for an unknown
address would rebuild the account-enumeration oracle `login()` works to avoid.

The token is stored as a SHA-256 hash only, claimed with an atomic
`UPDATE ... WHERE used_at IS NULL` so it cannot be spent twice, and a new request
retires the previous one. TTL from `PASSWORD_RESET_TTL` (default 1h) —
deliberately short, because the token is a bearer credential sitting in an inbox.

### Every password change ends every session

Both flows end in the same place (`PasswordService::applyNewPassword()`), and it
does two things: revokes every refresh family **and** bumps `token_version`, so
already-issued access tokens stop working immediately. If the reason for the
change was that somebody else knew the old password, leaving their sessions alive
defeats the exercise.

### Email verification

```bash
curl -X POST http://localhost:8084/auth/verify-email \
    -H 'Content-Type: application/json' -d '{"token": "<from the message>"}'

curl -X POST http://localhost:8084/users/me/resend-verification \
    -H 'Authorization: Bearer <access_token>'
```

**Verification is recorded, not enforced.** Registration succeeds, the account
works, `user.email_verified_at` simply stays null, and `email_verified` on the
user shape lets a client prompt. `verify-email` is public because the token *is*
the proof — demanding a session as well breaks opening the link in another
browser. Resend is a no-op once verified, so it cannot spray mail at a confirmed
address. TTL from `EMAIL_VERIFICATION_TTL` (default 24h).

See [ADR 15](adr/0015-email-verification-is-recorded-not-enforced.md) for why the
gate is provided rather than built and switched off.

### Where the messages go

Mail goes through [`MailerInterface`](../models/contract/MailerInterface.php),
queued as `SendEmailJob`. The bound implementation is
[`LogMailer`](../components/mail/LogMailer.php), which **writes the message to
the structured log** — honest for a sample with no mail server, and something
that must not stay in front of real users, since a reset link in a log is a reset
link anyone with log access can spend.

```bash
docker compose logs web | grep -i 'Mail to'
```

Swapping it for `yii\symfonymailer\Mailer` is one binding in `config/di.php`.

### One table, two purposes

Reset and verification tokens share the **`one_time_token`** table, separated by
a `purpose` column, because a second table would have been the same hash /
expiry / single-use-claim machinery copied. Every repository lookup is scoped by
purpose — a verification token must never be spendable as a password reset, and
the hash alone cannot say which it is. See
[ADR 14](adr/0014-one-table-for-every-single-use-token.md).

---

## Rate Limiting

The eight `/auth/*` endpoints are throttled per client IP to protect against brute-force credential
guessing. Each action (`login`, `register`, `refresh`, `logout`, `logout-all`, `forgot-password`,
`reset-password`, `verify-email`) has its **own independent budget** — the cache key includes
`$action->getUniqueId()`, so hammering `/auth/login` doesn't affect your `/auth/refresh` allowance.

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

A browser can read that header only because the CORS filter runs **before** the throttle and names
`Retry-After` in `Access-Control-Expose-Headers` — see [What a cross-origin client may
read](#what-a-cross-origin-client-may-read). It did neither for a while, and the symptom was a
client whose "try again in N seconds" was permanently `undefined`.

### Which address counts as "the client"

Everything above rests on `Yii::$app->request->userIP`, and that is a configuration decision, not a
fact. Yii believes `X-Forwarded-For` only from a host listed in the request component's
`trustedHosts`, which this application fills from **`TRUSTED_PROXIES`** (empty by default).

| Deployment | `TRUSTED_PROXIES` | What the limiter counts |
| --- | --- | --- |
| Exposed directly | empty | the peer address — correct |
| Behind a load balancer | **must list the balancer** | otherwise the balancer's own address: every caller shares one budget, and a single attacker locks out everybody |
| Anything | never `0.0.0.0/0` | a header believed from anyone lets a caller reset their own limit by rotating it |

The last row is covered by a test — `AuthCest::testRateLimitCannotBeSteppedAroundWithAForwardedForHeader`
keeps a `429` in place across a rotating `X-Forwarded-For`, so widening this setting fails the build
rather than quietly disabling the protection.

### Known limit

The counters live in the `cache` component, which is a `FileCache` — per container. Run more than
one web container and each keeps its own tally, so the effective limit multiplies by the number of
replicas. A shared store (Redis, Memcached) is a one-line change to the `cache` component and no
change to `RateLimiter`, which depends on `yii\caching\CacheInterface`.

---

## Load testing

`tests/load/api.js` is a [k6](https://k6.io/) scenario over the read paths a client actually polls.
It runs against a **running stack**, not as part of `make check` — a number produced on a laptop
that is also compiling something is not a number anybody should act on.

```bash
make load                      # 10 VUs, 30s
make load vus=50 duration=2m
```

Measured on the dev stack (10 VUs, 20s, ~1 600 albums and 64 000 photos seeded):

| | |
| --- | --- |
| Throughput | **824 req/s** |
| Reads, p95 | **17.3 ms** |
| Auth (bcrypt-bound), p95 | 346 ms |
| Failed requests | **0 of 16 837** |

The scenario **has thresholds**, and that is the point: a load test without them prints graphs, and
graphs do not fail. Reads are held to p95 < 300 ms, auth to p95 < 1.5 s, request failures to under
1%. The auth budget is separate on purpose — `AuthService` spends a deliberate bcrypt round on every
attempt, including the ones that fail (see the timing-oracle fix), so holding it to the read budget
would either fail honestly or push somebody to weaken the hash.

The thresholds are the **published budget**, not the current measurement. A threshold set to what
the machine happens to do today ratchets silently and never fails.

The scenario also exercises the revalidation path from
[ADR 13](adr/0013-conditional-get-saves-bandwidth-not-work.md), asserting the `304` under load —
that path is where a polling client spends most of its requests.

---

## Indexes and what they are for

Every index here was checked with `EXPLAIN` against seeded data (~1 600 albums, 64 000 photos)
rather than assumed. Two results are worth writing down, because both contradict what the schema
looks like at a glance:

| Query | Index used | Rows examined |
| --- | --- | --- |
| `GET /albums/my` (`user_id` + `is_deleted = 0`) | `user_id` | 40 of 1 603 |
| review queue (`?is_deleted=1`) | `idx_album_is_deleted` | 16 of 1 603 |
| photos of one album | `album_id` | 40 |
| `?title=` partial search | **none possible** | full scan |

**`idx_album_is_deleted` looks like a useless index on a boolean and is not.** Selectivity runs the
other way from the column's cardinality: soft-deleted albums are *rare*, so `is_deleted = 1` — the
review queue, the query the index exists for — is exactly the selective case. For `is_deleted = 0`
the optimizer gains nothing, and loses nothing either.

**A composite `(user_id, is_deleted)` was measured and rejected.** With the albums-per-user counts
this API produces, the optimizer prefers the narrower `user_id` index and ignores the composite when
both are present. It would be an index carried, maintained on every write, and never chosen.

**Partial search cannot be indexed and is not.** `?title=` becomes `LIKE '%term%'`, and a leading
wildcard rules out a B-tree — `EXPLAIN` reports no possible key. That is a deliberate limit, not an
oversight: `FULLTEXT` with `MATCH … AGAINST` would index it, but it matches whole words rather than
substrings, so `?title=lbum` would stop working. At this scale the scan is cheaper than the change
in behaviour; at a scale where it is not, the fix is a search index rather than a different SQL
operator.

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

### Every RBAC mutation is recorded

Everything else about the model is reconstructible from the tables — but only its
*current* state. "Who gave this account `super_admin`, and when" is the question
asked after an incident, and it needs its own record.

[`RbacAuditInterface`](../models/contract/service/RbacAuditInterface.php)
(implemented by `models/service/RbacAudit`) appends to the **`rbac_audit`** table
on the four mutations that exist — `role.created`, `role.updated`,
`role.deleted`, `roles.assigned` — storing actor, subject, action and a JSON
diff (`granted` / `revoked` role ids, not the whole set: the diff is what
somebody reconstructing an incident is after).

```sql
SELECT actor_id, subject_id, action, detail, created_at
FROM rbac_audit ORDER BY created_at DESC;
```

Two details are deliberate and easy to undo by accident:

- **It is a separate contract from `RoleService` on purpose.** The service must
  refuse an unsafe change; the writer must never refuse anything, because an
  audit writer that can veto the operation it describes has become part of the
  operation. The write happens inside the same transaction, so a refused change
  leaves no trace of having been attempted.
- **The record outlives both parties.** `actor_id` is `ON DELETE SET NULL` and
  `subject_id` is not a foreign key at all — deleting a user is itself an
  auditable event, so the row has to survive its subject.

See [ADR 16](adr/0016-the-audit-writer-cannot-refuse.md).

---

## API Endpoints

The endpoint reference is the OpenAPI document, and **only** the OpenAPI document:

- **<https://fuegoalma.github.io/yii2-rest-api-sample/>** — published on every green build, nothing needs to be running
- **[`/docs`](http://localhost:8084/docs)** — the same document as Swagger UI, served by the app when the stack is up
- **[`config/openapi.yaml`](../config/openapi.yaml)** — the source, and the thing the code is held to

This page used to repeat all of it: a table per resource, a table of validation
limits, a table of sortable and filterable attributes, and sample envelopes.
That was a **second source of truth**, checked by nothing — and it drifted
within a single afternoon. While the contract gates were being written, the
email limit moved from 255 to 254 and every error response gained an
`error_code`; the spec and the code were updated together, because
`WriteFormContractTest` and `ResponseSchemaContractTest` fail otherwise. The
tables here were not, and nothing noticed.

So they are gone. Each of them now has a gate behind it instead:

| What used to be tabulated here | What holds it to the code now |
| --- | --- |
| Endpoints and their RBAC gates | `RouteContractTest`, `PermissionContractTest` (`x-permission` on every operation) |
| Validation limits | `WriteFormContractTest` (probed at the boundary, both directions) |
| Sortable / filterable attributes | `SearchFormContractTest` (read out of the `sort` parameter's own description) |
| Response envelopes | `ResponseSchemaContractTest` |
| Upload conversion (WebP, quality, bounding box) | `UploadParamsContractTest` |

[The contract gates](#the-contract-gates) covers all eight and how to run them;
[ADR 5](adr/0005-openapi-as-a-checked-contract.md) covers why the document is
written by hand and checked rather than generated.

---

## Behaviour the document does not describe

What follows is the part a schema cannot carry: things you would otherwise have
to discover by trying them.

### Deleting an album has two outcomes

`DELETE /albums/{id}` is one route. It deletes **permanently** for whoever may
delete outright — the owner, or a holder of `album.delete.any` — and **soft**
(a flag plus an optional `{"reason": "..."}`, idempotent) for a caller holding
only `album.soft-delete.any`. Permanent wins when a role has both.

A soft-deleted album is hidden from every listing by default and is a `404` for
its own owner, until an admin restores it (`POST /albums/{id}/restore`). The
review queue is `GET /albums?is_deleted=1`, visible to the review audience only.

See [ADR 9](adr/0009-one-delete-route-two-outcomes.md).

### Relations are embedded by the endpoint that owns them

There is no `?expand=`: the parameter is accepted and ignored, deliberately, so
a query string can never route around the permission gating a relation (see
[ADR 4](adr/0004-no-client-driven-expansion.md)). A relation is included
unconditionally by the endpoint it belongs to, or not at all.

One consequence worth knowing: the `albums` embedded in `GET /users/{id}` and
`GET /users/me` contains **live albums only**. [`User::getAlbums()`](../models/db/User.php)
filters soft-deleted ones at the relation level, even for a `super_admin` — to
review a user's flagged albums, ask `GET /albums?user_id={id}&is_deleted=1`.

### Photos are never listed flat

There is no `GET /photos`. Listing and creation are nested under the album
(`GET|POST /albums/{albumId}/photos`) because a photo's ownership *is* its
album's, and that is what the implicit own-abilities resolve against. The member
routes (`GET|PUT|DELETE /photos/{id}`) stay flat.

### Pagination and filtering edge cases

- **A page past the last one is not clamped.** It returns an empty `items` array
  while `total` and `last_page` still describe the full result set, and
  `current_page` echoes back whatever was asked for.
- **`last_page` is `0`, not `1`, for an empty result set**, and `from`/`to` are
  both `0`.
- **Filter values are matched literally.** Partial-match filters go through
  Yii's `like` operator, which escapes `%`, `_` and `\` — so `?title=100%` looks
  for that string, not a wildcard.
- **An empty filter value is treated as absent.** `?title=` returns everything
  rather than matching `title = ''` or `NULL`, because the repository applies
  filters with `andFilterWhere`, which skips empty operands.

### Examples

Uploading a photo is the one `multipart/form-data` endpoint, and the one worth
spelling out:

```bash
curl -X POST http://localhost:8084/albums/1/photos \
  -H "Authorization: Bearer <token>" \
  -F "title=My Photo" \
  -F "file=@/path/to/image.jpg"
```

Listing with pagination, sorting and a partial-match filter:

```bash
curl "http://localhost:8084/users?first_name=jo&sort=-created_at&per_page=50&page=2" \
  -H "Authorization: Bearer <token>"
```
