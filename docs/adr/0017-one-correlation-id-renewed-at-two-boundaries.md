# 17. One correlation id, renewed at two boundaries

**Status:** accepted

## Context

`web` and `worker` are separate containers writing to the same log stream, and
the work crosses between them: a request deletes an album, the bytes are removed
later by a job in another process. "The album was deleted but its directory is
still there" is untraceable past the response unless a line can say which unit of
work it belongs to.

Yii's default log target writes prose to a file under `runtime/`, with no such
identifier and nowhere anyone outside the container can read it.

## Decision

An ambient id — `models/contract/CorrelationIdInterface`, implemented by
`components/CorrelationId`, bound as a **singleton** so every consumer in the
process sees the same object.

Besides the getter it has exactly one method: `renew(?string $inbound)`, called
at **two boundaries and nowhere else**.

- **The start of a web request** (`components/CorrelationIdBootstrap`, on
  `Application::EVENT_BEFORE_REQUEST`), which also echoes it back as
  `X-Request-Id`.
- **The start of a queued job** (`DbQueue::runOne()`), renewing from
  `queue_job.correlation_id`, written by `push()` — so a job inherits the id of
  the request that enqueued it.

It is a *renewal* rather than a constructor argument because one process serves
many units of work. The worker runs for days; an id fixed when the container
first built the object would file every later line under the first job.

**An inbound `X-Request-Id` is honoured and sanitised.** A caller's own id is
adopted so their logs and ours can be read side by side — but the value is
echoed in a response header *and* written into log lines, so an unfiltered one is
header injection and log forging in the same field. Everything outside
`[A-Za-z0-9._-]` is stripped, the result capped at 64 characters, and a
generated id used when nothing usable is left.

Logging is `components/log/JsonLogTarget`: one JSON object per line, on
**stderr**, carrying the correlation id, level, category, route, `user_id` and
the message. It replaces `FileTarget` in both `config/web.php` and
`config/console.php`.

## Consequences

- **stderr, not a file.** `docker compose logs` is where these are read, and a
  log nobody can reach from outside the container is a log nobody reads. The
  target's `$stream` is a configurable path rather than a hard-coded
  `php://stderr` so a test can read back what was written — the format is the
  whole point of the class, and a formatter nobody can assert on is a format
  nobody knows.
- **`logVars = []` lives on the target, not on the log component.** Yii's
  `Target` appends a dump of `$_GET`/`$_POST`/`$_SERVER` to every logged error by
  default, and in a container the environment *is* the configuration — so that
  dump was writing `JWT_SECRET`, `DB_PASSWORD` and `COOKIE_VALIDATION_KEY` into
  the log stream on every failure. It is overridden on the class so it holds
  wherever the target is used, including a config nobody remembered to write.
  It also cannot go on the component: `logVars` is not a `yii\log\Dispatcher`
  property, and the application refuses to boot. `JsonLogTargetTest` pins it
  empty.
- **The header is set before the action runs**, which is what puts it on error
  responses too — precisely the answer a caller quotes in a bug report. It is not
  written at bootstrap because Codeception's Yii2 connector recreates the
  response component before each request, so a header set there is silently
  discarded: right in production, absent under test, and therefore not something
  anyone could hold to its behaviour.
- **Registered for web and test only.** A console application has no request
  headers and no response headers; the console generates one id per invocation.
- **A header nobody can read is an id no bug report can quote.**
  `X-Request-Id` is named in `Access-Control-Expose-Headers` for that reason —
  see the amendment in
  [ADR 13](0013-conditional-get-saves-bandwidth-not-work.md), where it shipped
  hidden alongside `ETag` and `Retry-After`.
- This is correlation, not tracing. There are no spans, no parent ids and no
  sampling: one id per unit of work, propagated down one hop into the queue. A
  real tracer (OpenTelemetry) would subsume it, and the two boundaries above are
  where it would attach.
