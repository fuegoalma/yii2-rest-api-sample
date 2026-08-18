# 13. Conditional GET saves bandwidth, not work

**Status:** accepted

## Context

Read endpoints are polled. A client watching an album for new photos asks the
same question repeatedly and usually gets the same answer, and every one of
those answers is a full JSON body on the wire.

## Decision

`components/ConditionalGet` is an `ActionFilter` on every REST controller. On a
`200` from a `GET` it hashes the serialized body into a weak ETag, sets the
header, and answers `304` when the client's `If-None-Match` matches.

The hash is taken on `Response::EVENT_AFTER_PREPARE`, not in `afterAction()`.
At `afterAction()` time `$response->data` is still whatever the action returned —
an `ActiveDataProvider`, a model — and hashing that produces a validator that
does **not** follow the payload: two different result sets can present an
identical object graph to `json_encode`. The body only exists once
`ApiSerializer` has run, which happens during `prepare()`. This was caught by a
test that created an album and still got a `304`.

Weak validators (`W/"…"`), because the comparison is over the serialized body.
Two byte-identical payloads are semantically equivalent, which is all a weak
validator claims, and equality is the only comparison a client may make against
one.

## Consequences

- **The action still runs.** The query is executed, the models are loaded, the
  response is built — and only then discarded. What is saved is the body on the
  wire, which for a polled listing is most of the cost to the client and none of
  the cost to the server.
- **So this is not a cache, and must not be described as one.** Anyone expecting
  it to reduce database load will be disappointed, and the disappointment will
  arrive as a production incident rather than as a bug report.
- A real saving needs an ETag computable *without* doing the work — a
  `MAX(updated_at)` per collection, or a version counter bumped on write. That is
  a different feature with an invalidation problem of its own (every write path
  has to remember to bump it, and the one that forgets serves stale data), which
  is why it is not this one.
- Writes and error responses are untouched: the filter acts only on a `200` from
  a `GET`. A `304` for a `POST` would tell a client nothing happened when
  something did, and an ETag on a `404` would outlive the resource being created.
