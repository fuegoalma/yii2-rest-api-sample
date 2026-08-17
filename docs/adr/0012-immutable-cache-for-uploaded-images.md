# 12. Uploaded images are cached as immutable for a year

## Status

Accepted.

## Context

Photos are not served by the application. `PhotoUrlBuilder` hands the client a
plain URL under `/uploads/albums/<albumId>/` or `/default-images/`, and Apache
serves the file directly — PHP never sees the request.

Apache's defaults give those responses an `ETag` and a `Last-Modified`, but no
`Cache-Control`. A response with no freshness lifetime is stale immediately, so
a browser revalidates **every image on every page view**. The bodies are saved
by the resulting `304`s; the round trips are not. An album page of thirty
photos costs thirty conditional requests each time it is opened.

## Decision

The policy lives in `web/.htaccess`, split by path:

- `/uploads/albums/**` → `public, max-age=31536000, immutable`
- `/default-images/**` → `public, max-age=86400`

Uploads get the maximum lifetime *and* `immutable` — which suppresses
revalidation even on an explicit reload — because the bytes behind an upload URL
can never change. That is not a hope about how the API is used; it follows from
two facts in the code:

1. `ImageStorage::save()` names every stored file with a 40-character random
   string, so no two uploads share a URL.
2. `PhotoUpdateForm` accepts a title and nothing else — there is no route that
   replaces the file behind an existing photo.

Seeded demo images are the opposite case: their names are fixed (`1.jpg`,
`2.jpg`, …) and a release can change what those files contain, so they get a
day of freshness and then revalidate against the ETag Apache already sends.

`Header always set` rather than `Header set`: a `304` that omits `Cache-Control`
resets the browser's freshness clock, so the policy has to be attached to the
revalidation response too.

## Consequences

**The immutability precondition is now load-bearing.** Adding a "replace the
file of an existing photo" feature that keeps the file name would serve stale
bytes to every client that already fetched it — for up to a year, with no way to
invalidate short of changing the URL. Any such feature must generate a new file
name and update `photo.file_name`, which is what the current upload path already
does. This ADR is the note that says so; the code cannot.

`mod_headers` is now required. `Dockerfile` enables it (`a2enmod rewrite
headers`) in the shared `base` stage, so dev and prod cannot diverge.

**Verified where it actually runs.** No PHP test starts Apache, so the policy is
checked in `docker/smoke.sh` against the production image: upload a photo through
the API, assert the header on its URL, repeat the request with `If-None-Match`
and assert the `304` still carries it. Breaking the `.htaccess` value fails that
step, which is how the check was shown to bite.

## Alternatives considered

**`mod_expires` with `ExpiresByType image/*`.** Simpler to write, but it cannot
tell an upload from a seeded demo image — they are both `image/webp` or
`image/jpeg` — so the two lifetimes would collapse into one. It also emits an
absolute `Expires` date, which depends on the client's clock, where
`Cache-Control: max-age` is relative and does not.

**Serving images through PHP to set headers there.** It would put the policy next
to the code that knows why it is safe, but at the cost of a PHP process per
image and the loss of Apache's conditional-request handling. The policy is three
lines of configuration; the machinery to move it into the application is not.

**`FileETag MTime Size`.** Considered because Apache's historical default
included the file's inode, which differs between containers and would break
revalidation behind more than one replica. Checked rather than assumed: Apache
2.4 already defaults to `MTime Size` (the served ETags have two components, not
three), so the directive would only restate the default.
