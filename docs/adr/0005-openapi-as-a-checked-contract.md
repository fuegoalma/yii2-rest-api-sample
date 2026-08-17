# 5. The OpenAPI document is checked against the code

**Status:** accepted

## Context

`config/openapi.yaml` is written by hand and published as this API's source of
truth. The usual alternative is generating it from annotations, which trades
drift for clutter: every controller, form and model grows a second, parallel
description of itself.

But a hand-written document that nothing verifies is worse than either. It is
believed — by clients, by the docs site, by the next person to change an
endpoint — and it decays silently. This repository's own guidance used to admit
as much: *"the trade-off for zero code clutter is that the YAML is updated by
hand, and CI could lint it."*

## Decision

Keep the document hand-written, and make it a **checked oracle**: six gates in
`tests/unit/contract/` hold the code to it — routes (both directions, through
the real `UrlManager` so a shadowed rule is caught), response schemas, search
forms, write forms, RBAC, and the document's own integrity.

Every gate is a set difference against an explicit registry plus an explicit,
commented skip list. Adding a schema, an operation, a form or a permission fails
the build until it has been *placed*.

Where the document carries something only in prose — a resource's sortable
attributes, the accepted upload extensions, the encoder's numbers — the gate
parses that prose. The sentence a human reads is then the thing under test.

## Consequences

- The document and the code are duplicated effort by design. The gates are what
  make the duplicate safe, so a schema left out of them is the real risk — hence
  the census assertions, which fail on anything unplaced.
- Prose parsing is brittle to rewording, accepted deliberately: each regex is
  paired with an "it matched at all" assertion, so a reword fails loudly instead
  of comparing nothing.
- The gates are green on a tree that already agrees, which proves nothing by
  itself. A new or changed gate must be **proved to bite by mutation** — break
  one thing, confirm the failure names it, restore — and that is a line in the
  pull request template.
- Writing them found three real defects on day one: the document was not valid
  YAML, it promised a 255-character email address that `EmailValidator` can
  never accept, and nothing enforced "super_admin holds every permission".
