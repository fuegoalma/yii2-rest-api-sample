# 3. Our own RBAC tables instead of `yii\rbac`

**Status:** accepted

## Context

Yii ships `yii\rbac`, a full authorisation manager with hierarchical roles,
rule objects evaluated at check time, and its own schema.

What this API actually needs is flatter than that: a role is a named set of
permissions, a user may hold several, and effective permissions are their union.
There is no inheritance and no per-check rule evaluation.

## Decision

Four owned tables — `permission` (the catalog), `role` (`is_system` protects the
three seeded ones), `role_permission`, `user_role` — and one service,
`AccessControlService`, answering the two questions controllers ask: `can()` for
a global permission and `canOn()` for a per-record one.

Permissions live **only in migrations**, because their lifecycle is the code's:
a permission exists because some line checks it. There is no create/update/delete
for them, only `GET /permissions` for a role-composition UI.

## Consequences

- No role hierarchy. "Admin implies moderator" has to be expressed by granting
  the permissions, not by nesting the roles — which is more typing in a
  migration and less to reason about at a call site.
- Ownership is not a permission. What a caller may do with their *own* records
  is implicit and static (`AccessControlService::OWN_ABILITIES`), so a base user
  is simply a user with no roles rather than a user with a "user" role.
- The catalog can drift from the code — a permission checked but never seeded
  would fail closed and silently. `PermissionContractTest` closes that: it holds
  the catalog, the `x-permission` extensions in `config/openapi.yaml` and the
  permission literals in the code to each other, and enforces "super_admin holds
  every permission".
- Undoing this is a migration plus a rewrite of one service; nothing outside
  `AccessControlService` knows how permissions are stored.
