# Architecture decision records

One file per decision a reader would otherwise have to reverse-engineer from
the code — what was chosen, what it cost, and what would have to change to undo
it. They are deliberately short.

A decision belongs here when the code alone cannot explain *why*: when the
obvious alternative was rejected for a reason, or when the cost is paid
somewhere other than where the benefit shows up.

| #                                                    | Decision                                                             |
| ---------------------------------------------------- | -------------------------------------------------------------------- |
| [0001](0001-two-token-authentication.md)             | Stateless access token, stateful refresh token                       |
| [0002](0002-refresh-token-families.md)               | Refresh tokens rotate, and reuse revokes the family                  |
| [0003](0003-own-rbac-tables.md)                      | Our own RBAC tables instead of `yii\rbac`                            |
| [0004](0004-no-client-driven-expansion.md)           | `?expand=` is not supported                                          |
| [0005](0005-openapi-as-a-checked-contract.md)        | The OpenAPI document is checked against the code                     |
| [0006](0006-hundred-percent-coverage-as-a-gate.md)   | 100% line coverage is a gate, not a target                           |
| [0007](0007-db-queue-instead-of-yii2-queue.md)       | A database queue instead of `yiisoft/yii2-queue`                     |
| [0008](0008-non-atomic-batch-delete.md)              | Bulk deletion is batched and deliberately not atomic                 |
| [0009](0009-one-delete-route-two-outcomes.md)        | One delete route, outcome decided by permission                      |
| [0010](0010-config-driven-parameters.md)             | A config-driven parameter has no default in code                     |
| [0011](0011-machine-readable-error-codes.md)         | Errors carry a machine-readable code, and disclose nothing by accident |
| [0012](0012-immutable-cache-for-uploaded-images.md)  | Uploaded images are cached as immutable for a year                   |
