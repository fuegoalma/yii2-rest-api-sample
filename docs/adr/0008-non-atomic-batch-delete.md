# 8. Bulk deletion is batched and deliberately not atomic

**Status:** accepted

## Context

Permanently deleting an album removes its photo rows first, then the album.
An album can hold many photos, and a single `DELETE` covering all of them holds
row locks for as long as it runs — long enough, on a large album, to block
unrelated writes.

## Decision

`BaseRepository::deleteInBatches()` removes rows in 500-row chunks, each its own
statement, so locks stay short. It is **not** wrapped in a transaction, and
therefore not atomic: a failure part-way leaves some rows deleted.

## Consequences

- **Callers must tolerate a partial delete.** `AlbumService::purgeAlbums()` does:
  a retry deletes whatever remains, and a photo row without its album is
  invisible to every endpoint (all photo access is resolved through the album).
- This is the opposite trade from the RBAC mutations, which *are* wrapped
  (`TransactionRunnerInterface`) because a half-applied role change can leave the
  system with no role manager. The difference is what a partial result means: an
  orphaned photo row is garbage, a half-applied permission set is a security
  state.
- If atomicity is ever needed here, the change is one wrapper in the caller —
  and the lock-duration problem comes back with it.
