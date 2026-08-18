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

**The database decides who spent the token**, not the application.
`RefreshTokenRepository::consume()` is a conditional update —
`SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL` — and the caller
branches on whether it matched a row. This is the part that has to be atomic:
two simultaneous refreshes both pass `findByHash()` while the row is still
active, so a read-then-save would let both rotate and neither would look like
reuse. The claim collapses replay and a lost race into one answer, because they
are the same evidence: one token value presented twice.

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
