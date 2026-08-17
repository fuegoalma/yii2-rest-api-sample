# 2. Refresh tokens rotate, and reuse revokes the family

**Status:** accepted

## Context

A long-lived refresh token is the most valuable credential the API issues: it
mints access tokens for as long as it lives (30 days by default). If one leaks,
nothing about a plain "check it and issue a new access token" scheme notices.

## Decision

A refresh token is **single-use**. Exchanging it revokes it and issues a
successor in the same **family** (`family_id` — one login session, one device).

Presenting an already-revoked token means a copy is loose: either the attacker
or the legitimate client is replaying one the other has already spent. There is
no way to tell which, so `RefreshTokenService::consume()` revokes the **whole
family**, forcing a re-login.

Revocation is a soft `revoked_at` timestamp. Rows are kept rather than deleted,
because a deleted row cannot be recognised as a replay.

## Consequences

- **A client must serialise its refreshes.** Two concurrent refreshes with the
  same token look exactly like a leak, and the second one signs the user out.
  Any client needs a single-flight lock around refresh.
- Kept rows grow without bound, which is what `refresh-token/prune`
  (`make refresh-token-prune`, run daily by the `cron` service) exists for. It
  hard-deletes only *fully expired* rows: an expired token cannot be exchanged,
  so it is not needed for reuse detection either. Still-valid revoked rows stay.
- The error a client sees distinguishes the three failures
  (`refresh_token.invalid` / `.reused` / `.expired`, see
  [ADR 11](0011-machine-readable-error-codes.md)) — `.reused` is a security
  event, and a client that cannot tell it apart cannot react to it.
