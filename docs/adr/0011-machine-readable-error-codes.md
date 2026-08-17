# 11. Errors carry a machine-readable code, and disclose nothing by accident

**Status:** accepted

## Context

Every response ≥ 400 used to say `"An error occurred during execution"` — a
sentence true of every failure ever, and therefore useful for none. A client had
to keep its own table of messages to show a person anything at all.

Worse, the debug backtrace was written into `data.error`, the same key that
carries validation messages. A client could not read field errors without first
guessing which entries were real.

And an uncaught exception's message was returned verbatim, so a driver error
naming a table — or a credential — would have reached the caller in production.

## Decision

Three changes to one response shape.

- **`components/ApiErrorCatalog`** is the single `status → [code, message]`
  table, used by both `ApiSerializer` and `JsonErrorHandler`.
- **`data.error_code`** is stable and machine-readable. It defaults to the status
  (`not_found`, `conflict`, …); an endpoint that can refuse for several
  distinguishable reasons narrows it by throwing one of the `models/exception/`
  classes, which extend the matching Yii exception and add `getErrorCode()` via
  `ErrorCodeAwareInterface` — so `instanceof UnauthorizedHttpException` still
  holds and the code is an added capability, not a new hierarchy.
- **`data.error` is strictly `field => string[]`** and `{}` when empty. Debug
  detail moved to `data.debug`, present only under `YII_DEBUG`.

What may be disclosed is decided by `models/dto/ApiError::fromException()`, apart
from the handler that renders it: a `UserException` carries wording the
application chose and survives verbatim; anything else is a bug report addressed
to us, and outside a debug environment the caller gets the catalog's wording.
`JsonErrorHandler::$debugDetail` defaults to **false** — a handler nobody
configured is the one running where nobody was watching.

## Consequences

- Branch on `error_code`, never on `message`: prose is what no localisation
  survives.
- Adding a narrowed code means a throw site and one line in the `error_code`
  description in `config/openapi.yaml`. Nothing else.
- The catalog can only ever be a fallback. A 409 that names the invariant it
  refused is more useful than any generic wording, so the rule is that a
  deliberate message always wins.
