<?php

namespace app\models\contract;

/**
 * An exception that names *which* rule refused, not just what kind of refusal
 * it was.
 *
 * A status code says a request was rejected; it cannot say whether a 401 meant
 * "wrong password", "token expired" or "this token was replayed, so the whole
 * session has been revoked" — three answers a client must react to differently.
 * The message says it, but only in one language and only to a human.
 *
 * Implementations return a stable, machine-readable slug (`refresh_token.reused`,
 * `role.last_manager`) that is part of the published contract. Anything that
 * does not implement this falls back to the status-derived code in
 * {@see \app\components\ApiErrorCatalog}.
 */
interface ErrorCodeAwareInterface
{
    public function getErrorCode(): string;
}
