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
noticed. The baseline is **MSI 98%, mutation code coverage 100%**, over
`components/`, `models/service/`, `models/repository/` and `models/form/`, in
about seven minutes. `infection.json5` holds the floor, and CI enforces it
alongside the line gate.

### What the score measures here, and what it does not

Two constraints shape the configuration, and both were found by running into
them.

**Only the unit suite takes part** (`--skip functional`). Not for speed: the
functional suite shares one test database and truncates it between tests, so it
is not parallel-safe. Running mutants across threads makes workers clobber each
other's fixtures, and a mutant then gets recorded as "killed" by another worker's
`TRUNCATE` rather than by the mutation. That is not flakiness — it is the metric
reporting a number it did not measure. It showed up as irreproducibility: the
same file scored 100% MSI at four threads and 97% at one. Single-threaded is
correct and unaffordable — one service class takes four minutes.

**The run gets a disposable database.** Infection executes *mutated* code against
a real schema, so a mutant that removes a guard really does what the guard
prevented: one that drops the `is_system` check deletes the migration-seeded
roles, and every later test fails for reasons unrelated to itself. `make
mutation` therefore drops and rebuilds `<test-db>_mutation` on each run and never
touches the database `make test` uses. Before that isolation existed, a mutation
run left duplicate `user_role` rows behind and cost two rounds of debugging a
"failing" suite that was fine.

So the score measures **what the unit suite alone catches**. That is a lower
bound on test quality, not test quality.

### An escaped mutant is a candidate, not a defect

The 159 survivors fall into three groups, and telling them apart is the work:

- **Covered by the functional suite, which did not run.** `RoleService` reports
  ~30 survivors here; running those same mutants against the whole suite kills
  every one. Two were confirmed by hand — inverting the `.any` branch of
  `AccessControlService::canOn()` and the anti-escalation guard in
  `RoleService::assertUserManageable()` — and four separate tests catch the
  second.
- **Equivalent mutants, which cannot be killed by anyone.** In
  `RequestSizeLimit`, `?? 0` becomes `?? -1`: with no `Content-Length` header
  neither value exceeds the limit, so the behaviour is identical. No test can
  distinguish them because there is nothing to distinguish.
- **Real gaps.** `ApiErrorCatalog::messageFor()` returned `[1]`; the mutant
  returned `[0]` — the error *code* instead of the message — and every test still
  passed. They asserted non-empty and distinct-per-status, which the code
  satisfies just as well. **100% line coverage hid this completely.** The fix is
  `testAMessageIsProseAndNotTheCodeAgain()`, which pins the one property that
  actually separates the two halves of an entry.

The working rule: apply a survivor, run `make test`, and only write a test if it
really survives. Driving the number to 100% is not a goal — equivalent mutants
make it unreachable, and chasing it produces tests that assert the
implementation rather than the behaviour.

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
