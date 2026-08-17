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

## Consequences

- A compromised access token is valid until it expires. `JWT_TTL` is therefore a
  real security parameter, not a convenience: shortening it shortens the window.
- Logout cannot revoke an access token, only the refresh family — so "log out"
  means "you will be signed out within `JWT_TTL`". Both logout endpoints return
  204 regardless, which is why they are idempotent.
- The `refresh_token` table has a `user_id` foreign key with `ON DELETE CASCADE`,
  so a deleted user's tokens vanish with them. This is why refresh needs no
  separate "does this user still exist" check.
- Undoing this in favour of stateless-only means giving up revocation; undoing
  it in favour of stateful-only means a query per request.
