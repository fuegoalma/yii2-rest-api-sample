# 9. One delete route, outcome decided by permission

**Status:** accepted

## Context

An album can be removed in two ways. An owner (or a holder of
`album.delete.any`) deletes it outright, files and all. A moderator may only
*flag* it for review — a soft delete with an optional reason, which an admin
later restores or makes permanent.

The obvious modelling is two routes: `DELETE /albums/{id}` and something like
`POST /albums/{id}/flag`.

## Decision

One route. `DELETE /albums/{id}` deletes permanently for whoever may delete
outright, and soft-deletes for a caller holding only `album.soft-delete.any`.
Permanent wins when a role has both.

## Consequences

- A client does not have to know which kind of caller it is holding a token for.
  It asks to delete; the API decides what that means. This is the same reasoning
  as the UI only hiding what it cannot do — the server re-decides regardless.
- The outcome differs by caller for the same request, so the two paths must be
  tested per audience rather than per route, which is what `AlbumsCest` does.
- Soft-deleted albums are hidden from every listing by default and are a 404 for
  their owner, so the flag is not a state the owner can observe or act on. Only
  the review audience (`album.view.any`) sees them.
- Adding a third outcome means a third branch in one action rather than a third
  route — worth watching, and the point at which this decision should be revisited.
