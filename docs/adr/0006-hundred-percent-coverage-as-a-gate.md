# 6. 100% line coverage is a gate, not a target

**Status:** accepted

## Context

A coverage *target* is advisory, and advisory numbers drift downward: every
individual exception is reasonable, and the sum of them is a suite nobody
trusts. The usual objection to 100% is that it forces tests for trivial code and
invites gaming.

## Decision

`tests/bin/coverage-check.php` fails the build below 100% line coverage of
`commands/`, `components/`, `controllers/` and `models/`. CI runs the same gate.

Three rules keep it honest:

- **`@codeCoverageIgnore` requires a written justification** saying why the code
  is unreachable *by construction*. "Hard to test" is not unreachable.
- **`@covers` / `#[CoversClass]` are forbidden.** Codeception's test wrapper
  implements `StrictCoverage`, so the annotation narrows what a test is credited
  with and lowers the total without erroring.
- **When code turns out to be unreachable because nothing uses it, delete it.**

## Consequences

- Untestable code becomes a design signal rather than an exemption request.
  `StopSignalInterface` exists because the queue worker's infinite loop could not
  otherwise be tested; `models/dto/ApiError` exists because "may this exception
  message leave the building" is a decision worth asserting directly, and the
  handler that renders it is not.
- The gate cannot distinguish a line that runs from a line that is *checked*, so
  it is a floor and not a substitute for judgement. This is why a contract test
  must never be the sole coverer of a line: it asserts a shape, and a shape
  passing does not mean the behaviour is right.
- All suites must run in **one** `codecept run`: coverage is merged across
  suites at the end of the process, so a split run has the second report
  overwrite the first. `make coverage` has no per-suite variant for that reason.
