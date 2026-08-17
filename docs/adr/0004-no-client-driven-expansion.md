# 4. `?expand=` is not supported

**Status:** accepted

## Context

Yii's `rest\Serializer` resolves an `expand` query parameter against a model's
`extraFields()`, letting a client ask for related records inline. It is free to
enable and genuinely convenient.

It is also an authorisation hole. `extraFields()` is a property of the *model*,
not of the endpoint, so any relation listed there becomes reachable from **every**
action that serializes that model — including one gated by a permission that
action does not require. `GET /roles` needs `role.index`; `permissions` is a
relation on `Role`; `role.view` is what should be needed to see it.

## Decision

`ApiSerializer::getRequestedFields()` drops the requested expand list
unconditionally. The parameter is a silent no-op everywhere.

Relations are embedded only by the endpoint that owns them:
`ApiController::actionView()` and `UsersController::actionMe()` pass
`extraFields()` to `toArray()` themselves.

## Consequences

- A client that wants a relation on a listing gets a purpose-built response
  instead (`AlbumViewResponse`), which is more code and one fewer way to be
  wrong.
- `extraFields()` survives with exactly one consumer — the member actions above.
  Anything else on a model is dead weight, which is how `Album::extraFields()`
  came to be deleted once coverage showed it had no callers.
- Dropping the parameter rather than rejecting it is deliberate: a 400 on
  `?expand=` would tell an attacker the parameter is understood.
