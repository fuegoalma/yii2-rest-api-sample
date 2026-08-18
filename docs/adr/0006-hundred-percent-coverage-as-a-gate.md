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

## What the metric cannot see, and what was added because of it

Line coverage answers "was this line executed". It does not answer "would anyone
notice if it were wrong", and the difference is not academic — a test that calls
a method and asserts nothing is worth exactly 100% of its lines.

**Mutation testing is the answer to the second question.** `make mutation` runs
Infection: it changes the code on purpose — flips a comparison, drops a method
call, swaps `&&` for `||` — and reports how many of those changes the suite
noticed. The baseline at the time of writing is **MSI 80.96%, mutation code
coverage 100%**, over `components/`, `models/service/`, `models/repository/` and
`models/form/`, in about a minute. `infection.json5` holds the floor, and CI
enforces it alongside the line gate.

Scope is narrow on purpose: Infection reruns the suite once per mutant, and only
the unit suite is used (`--skip functional`), because the functional suite drives
real HTTP requests and truncates tables between tests — shared state is the wrong
thing to run thousands of times in parallel.

**A second blind spot has no test at all, and is worth naming.** Line coverage is
structurally unable to see a concurrency defect: two correct sequential paths
interleaving badly is not a line anything failed to execute. The refresh-token
rotation race and the queue's missing claim both sat under a green 100% gate for
their whole lifetime. What found them was reading the code, and what pins them
now is a test that reproduces the interleaving deliberately (a stale row read
before another writer's claim) rather than any measurement.

So the honest statement of what the gate buys is: it makes untested code
impossible and untestable code visible. It does not make the tests good. The
mutation score is the number to argue about; the coverage number is only the
floor beneath it.
