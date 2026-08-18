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

## The two callers take different sides of this, on purpose

`AlbumService::purgeAlbums()` opens no transaction. Whether one is open around it
is decided by the caller, and the two callers decide differently. This is the
escape hatch above, exercised — recorded here because the code cannot say why
one path pays for atomicity and the other does not.

| | `AlbumService::delete()` | `UserService::delete()` |
| --- | --- | --- |
| Route | `DELETE /albums/{id}` | `DELETE /users/{id}` |
| Transaction | none | wraps albums **and** the account row |
| Locks | short, per 500-row batch | held until commit |
| On a crash mid-way | some rows gone; an upload directory may never be collected | nothing happened |

The single-album delete is a request users make often, so short locks win. The
exposure is a crash between the row deletes and the enqueue of
`DeleteAlbumDirectoryJob`, which strands a directory: garbage on disk, invisible
to the API, and cheaper than blocking unrelated writes.

Closing an account is rare and admin-initiated, and its parts cannot be separated
— an account row whose albums are half gone is not a state anyone can act on. So
it takes the transaction and gives up the short locks that batching exists for.
Committing the queue rows together with the deletes is a second reason: the
worker then cannot see a cleanup job for an album that still exists.

**A previous version of the `purgeAlbums()` docblock claimed the transactional
behaviour for both paths.** It was only ever true of the account teardown.
