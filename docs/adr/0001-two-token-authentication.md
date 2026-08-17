# 1. Stateless access token, stateful refresh token

**Status:** accepted

## Context

Every resource endpoint has to authenticate its caller. A self-contained JWT is
cheap — a signature check, no I/O — which matters when it happens on every
request. But it is also unrevokable: a stolen token stays valid until it
expires, and there is no server-side state to delete.

The opposite choice, an opaque session token looked up in the database, is
revocable but pays a query on every single request.

## Decision

Split the two, and put each cost where it can be afforded.

- The **access token** is a stateless HS256 JWT (`components/JwtService`),
  checked on every request without touching the database. `User::findIdentityByAccessToken()`
  resolves the caller from the `sub` claim; nothing is stored.
- The **refresh token** is an opaque high-entropy string — deliberately *not* a
  JWT — stored in `refresh_token` and exchanged rarely: once per access-token
  lifetime. That is exactly where a database round trip is affordable, and it is
  what buys back **revocability**.

Only the SHA-256 hash of a refresh token is stored, so a database leak exposes
no usable tokens. The raw value is returned to the client once.

### Withdrawing access tokens: `token_version`

The split above leaves one thing that cannot be undone — an access token already
issued. That is acceptable for ordinary logout, and not acceptable for "my
account is compromised", where an hour of continued access is the wrong answer.

`user.token_version` closes it without giving up the stateless design. The value
is minted into the token as `ver` and compared during authentication; bumping the
column invalidates every token issued before the bump, at once.

**The comparison is free.** `findIdentityByAccessToken()` was already loading the
user row to resolve `sub`, so the check is an extra column in a `WHERE` clause,
not an extra query. This is the reason it is a version counter on the user rather
than a denylist of token ids, which *would* be a second lookup and would need its
own expiry.

A token carrying no `ver` claim is refused. Reading a missing claim as version 0
would let a token minted before the column existed survive the bump meant to end
it.

Bumped by `POST /auth/logout-all`. Ordinary `POST /auth/logout` deliberately does
not: it ends one device, and taking the other devices' access tokens with it
would make the two endpoints the same thing.

## Consequences

- A compromised access token is valid until it expires **or until its version is
  bumped**. `JWT_TTL` still bounds the ordinary case, so it remains a real
  security parameter rather than a convenience.
- `POST /auth/logout` still cannot revoke an access token, only the refresh
  family — "log out this device" means "within `JWT_TTL`". `logout-all` is now
  immediate. Both return 204 regardless, which is why they are idempotent.
- **Refresh reads the account.** Issuing a pair whose `ver` matched the old token
  rather than the current account would hand back a token the bump had just
  withdrawn, so `AuthService::refresh()` loads the user — one query, once per
  access-token lifetime, on the path that was always allowed a database round
  trip. A refresh token whose owner is gone now answers `refresh_token.invalid`.
- The `refresh_token` table has a `user_id` foreign key with `ON DELETE CASCADE`,
  so a deleted user's tokens vanish with them. This is why refresh needs no
  separate "does this user still exist" check.
- Undoing this in favour of stateless-only means giving up revocation; undoing
  it in favour of stateful-only means a query per request.
