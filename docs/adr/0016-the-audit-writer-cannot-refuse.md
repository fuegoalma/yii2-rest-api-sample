# 16. The audit writer cannot refuse the change it records

**Status:** accepted

## Context

Everything about the authorization model is reconstructible from the `role`,
`role_permission` and `user_role` tables — but only its *current* state. "Who
gave this account `super_admin`, and when" was unanswerable, and it is the one
question asked after an incident.

The obvious place to write that record is `RoleService`, which already knows
what changed and already runs inside a transaction. It is also where the guards
live: the anti-escalation check and the last-role-manager invariant, both of
which refuse.

## Decision

A separate contract. `models/contract/service/RbacAuditInterface` has one method
— `record(string $action, int $subjectId, array $detail = [])` — implemented by
`models/service/RbacAudit`, appending to the `rbac_audit` table on the four
mutations that exist (`role.created`, `role.updated`, `role.deleted`,
`roles.assigned`).

**It is separate because the two fail differently.** The service *must* refuse an
unsafe change; the writer must *never* refuse anything. An audit writer that can
veto the operation it is describing has stopped describing it and become part of
it — and the failure mode is the worst available: a legitimate change rejected
because the record of it could not be written.

The write happens **inside the same transaction** as the change, so a refused
change leaves no trace of having been attempted. An audit of things that did not
happen is worse than no audit, because it has to be disbelieved selectively.

The stored detail is a **diff** — `granted` / `revoked` role ids — not the whole
resulting set. Somebody reconstructing an incident is after what changed; the
end state is already in `user_role`.

## Consequences

- **The record has to outlive both parties.** `actor_id` is
  `ON DELETE SET NULL`: what happened must survive the deletion of whoever did
  it. `subject_id` is **not a foreign key at all**, because deleting a user is
  itself an auditable event and the row has to survive its subject. That costs
  referential integrity on that column, deliberately.
- **A failed write takes the transaction with it**, since it is inside one. That
  is not a veto — the writer never *decides* to refuse — but it is the same
  outcome if the table is broken. The alternative, writing outside the
  transaction, buys availability at the price of recording changes that rolled
  back. Availability of the audit is not the property worth having here.
- **Coverage is only as good as the call sites.** Nothing forces a new RBAC
  mutation to record itself; the interface is injected, not enforced. The check
  is `RbacAuditCest`, and a fifth mutation without an `ACTION_` constant is the
  shape of the regression to watch for.
- **This is not a general audit log.** It records changes to the authorization
  model and nothing else, which is what keeps it small enough to read. Widening
  it to "every mutation" is a different feature with a retention problem.
- Undoing this means folding `record()` back into `RoleService` and accepting
  that the thing which describes the change can also stop it. See
  [ADR 3](0003-own-rbac-tables.md) for why the tables are ours to extend in the
  first place.
