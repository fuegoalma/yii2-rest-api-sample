# 7. A database queue instead of `yiisoft/yii2-queue`

**Status:** accepted

## Context

Slow, retriable side effects — deleting an album's upload directory — should not
happen inside a request. The idiomatic Yii answer is `yiisoft/yii2-queue`.

Its current release caps `symfony/process` at `^7`, and this project runs `^8`
(PHP 8.5). It cannot be installed here.

## Decision

A small queue behind our own seam: `QueueInterface` (`push`), a command/handler
split (`JobInterface` carries data and names its handler; `JobHandlerInterface`
holds the behaviour and takes its services by constructor injection), and two
drivers — `DbQueue` (a serialized job in `queue_job`) and `SyncQueue` (in-process,
bound in `config/test.php`).

Resolving a handler by name is the one lookup that cannot be wired in advance,
so it is isolated behind `JobRunnerInterface` / `ContainerJobRunner`, which takes
the container **by injection** rather than reading `Yii::$container`.

## Consequences

- On a mainstream stack, `yii2-queue` would back the same `QueueInterface` and
  nothing above the seam would change. That is the whole point of the interface
  existing rather than services calling a driver.
- We own the retry policy: a throwing job is retried to `maxAttempts` (3), each
  attempt logged as a warning, then dropped with `Yii::error()` so one poison job
  cannot wedge the queue.
- `ContainerJobRunner` is the only service-locator call in the application, and
  it is one class with the container passed in — so both drivers stay
  constructible in a test without mutating global state.
- Services belong on a handler, never on a job: a job is serialized into a table
  and must carry only plain data.
