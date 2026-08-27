# 14. One table for every single-use token

**Status:** accepted

## Context

Password recovery arrived first and brought a `password_reset_token` table with
it: a SHA-256 hash of the value handed to the user, an expiry, a `used_at`
column claimed atomically, and a cascade on the owner.

Email verification arrived next and needs the same four things. The obvious
move is a second table — `email_verification_token` — because the two are
different features with different endpoints, wording and consequences.

They are, but the *token* is not. Only what spending it means differs, and that
is one column.

## Decision

Rename the table to `one_time_token`, add a `purpose` column
(`password_reset` | `email_verification`), and share the mechanics through
`models/service/basic/OneTimeTokenFlow` — issue, redeem, refuse — leaving each
service holding only what its purpose actually means.

The rename matters. Keeping the old name and putting verification rows in it
would be a name that lies, and the next person to read the schema would have to
check. Existing rows are password resets by definition, so the backfill is a
constant (`m260820_000000_generalise_one_time_tokens`).

**Every lookup is scoped by purpose.** `OneTimeTokenRepositoryInterface`'s
`findByHash($hash, $purpose)` and `invalidateAllForUser($userId, $purpose)` both
take it, because a token issued to prove an address must never be spendable as a
password reset and the hash alone cannot say which it is. That is the one thing
a shared table makes possible to get wrong, so it is the one thing the interface
does not let a caller omit.

## Consequences

- **The security properties are stated once.** Store only the digest
  (`HashesRawTokens`); claim with a conditional
  `UPDATE ... WHERE used_at IS NULL` so a token cannot be spent twice; retire a
  user's previous tokens when a new one is issued. A second table would have been
  a second copy of all three, and the copy that drifts is the one that matters.
- **The refusal is deliberately vague, and now vague in one place.**
  `OneTimeTokenFlow::refuse()` derives both halves from the purpose —
  `password_reset` yields `password_reset.invalid` and "The password reset token
  is invalid." — and never separates "no such token" from "already spent".
  Telling them apart would tell whoever is guessing which of their guesses had
  once been real. See [ADR 11](0011-machine-readable-error-codes.md) for the code.
- **A third purpose is a constant and a migration**, not a table. If one ever
  needs a different lifecycle — multi-use, or a payload of its own — that is the
  signal this decision has run out, and the fix is its own table rather than a
  nullable column here.
- The index is `(user_id, purpose)` for the invalidation sweep, alongside the
  unique hash for the hot lookup. A single-purpose table would have needed
  neither, which is the small price paid for the sharing.
- `one_time_token` rows are **not pruned**. Unlike `refresh_token`, nothing needs
  them after expiry — reuse detection is per-row here, not per-family — so they
  accumulate. `GET /metrics` counts the live ones
  (`one_time_tokens_live`, see [ADR 18](0018-metrics-are-read-at-scrape-time.md));
  a cron entry alongside `refresh-token/prune` is the fix when it matters.
