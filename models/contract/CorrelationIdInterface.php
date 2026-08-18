<?php

declare(strict_types=1);

namespace app\models\contract;

/**
 * The id that ties everything one request caused back to that request.
 *
 * `web` and `worker` are separate containers writing separate log streams, so
 * without a shared id "the upload succeeded but its file was never cleaned up"
 * is two unrelated stories. Every log line carries this, the response echoes it
 * in `X-Request-Id`, and a queued job inherits the id of the request that
 * enqueued it.
 */
interface CorrelationIdInterface
{
    public function get(): string;

    /**
     * Begin a new unit of work, inheriting $inbound when there is something
     * usable in it and generating a fresh id otherwise.
     *
     * Called at exactly two boundaries: the start of a web request (adopting
     * the caller's `X-Request-Id`, so their logs and ours can be read side by
     * side) and the start of a queued job (adopting the id of the request that
     * enqueued it). It must be a *renewal* rather than a one-time construction
     * because one process serves many units of work — the queue worker runs for
     * days — and an id that outlived its request would file every later line
     * under the first one.
     */
    public function renew(?string $inbound): void;
}
