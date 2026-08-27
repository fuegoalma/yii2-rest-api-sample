# 7. A database queue instead of `yiisoft/yii2-queue`

**Status:** accepted

## Context

Slow, retriable side effects should not happen inside a request. There are three
here, and they divide into two kinds: removing bytes from disk once the rows are
gone (`DeleteAlbumDirectoryJob`, `DeletePhotoFileJob`), and talking to a third
party the caller is waiting on (`SendEmailJob`). The first kind is about
correctness — once the rows are committed the deletion has happened as far as any
caller can tell, so a filesystem error afterwards would answer 500 for an
operation that succeeded, and a client retrying that 500 gets a 404. The second
is about not letting an SMTP conversation decide whether registration worked.

The idiomatic Yii answer is `yiisoft/yii2-queue`.

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
- **We own the delivery semantics, and that is the real cost.** A mature queue
  ships them; ours had to grow them, and until it did, the driver was a plain
  `SELECT ... LIMIT n` that every worker saw the same rows in. Three things now
  make up for it:
  - **A claim.** `reserved_at` is taken with a conditional
    `UPDATE ... WHERE id = ? AND <still due>`, so exactly one worker can win a
    row — the same idiom as `RefreshTokenRepository::consume()`. A claim expires
    after `RESERVATION_TIMEOUT`, or a worker killed mid-job would strand its
    jobs forever, which is worse than the double-run the claim prevents.
  - **Backoff.** A failed job waits `available_at` out, doubling from 5s and
    capped at 300s. Without it all three attempts are spent inside one
    worker-loop delay, so a fault that would have cleared in a minute never gets
    the chance — which is `SendEmailJob`'s ordinary failure, a mail host briefly
    refusing connections.
  - **A dead letter.** A job that exhausts its attempts moves to
    `queue_job_failed` with its payload, correlation id and last error, instead
    of being deleted with only a log line. For `DeleteAlbumDirectoryJob` that
    difference is an upload directory nobody will ever remove, and no record that
    it was meant to be; for `SendEmailJob` it is a password reset the user is
    still waiting for.

  Delivery is **at-least-once**: a worker that dies after the job's side effect
  but before the row is deleted will run it again once the reservation expires.
  Handlers must tolerate that.
- `ContainerJobRunner` is the only service-locator call in the application, and
  it is one class with the container passed in — so both drivers stay
  constructible in a test without mutating global state.
- Services belong on a handler, never on a job: a job is serialized into a table
  and must carry only plain data.
