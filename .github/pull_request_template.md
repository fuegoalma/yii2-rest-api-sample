## What and why

<!-- What changes, and what problem it solves. Link the endpoint or the section
     of config/openapi.yaml if the change follows one. -->

## How it was verified

<!-- `codegraph affected <files>` names the suites that actually cover the
     change; run those first, then the full gate. -->

- [ ] `make check` is green (cs-check · stan · tests at 100% coverage)
- [ ] Tests were written first — a failing test for a bug, a red test for new behaviour
- [ ] A new contract gate, or a changed one, was **proved to bite by mutation** (break one thing, confirm the failure names it, restore)
- [ ] `config/openapi.yaml` matches the code — including `x-permission` on any new operation

## Notes for the reviewer

<!-- A deliberate deviation from the architecture, a trade-off taken, or a
     follow-up left out of scope. Delete if there is none. -->
