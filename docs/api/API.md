# API Documentation

REST API (Yii2) for managing users, albums and photos. Every response — success or error — is returned in a single unified JSON envelope.

- Base URL (local): `http://localhost:8084`
- Request body format: `application/json` for all endpoints except photo upload, which uses `multipart/form-data`
- Response format: always `application/json`
- Authorization: `Authorization: Bearer <JWT>` — required on every endpoint except `POST /auth/login` and `OPTIONS` preflight requests

## Response envelope

Every response (success or error) is wrapped the same way:

```json
{
  "success": true,
  "data": {},
  "code": 200
}
```

- `success` — `true` for status codes < 400, `false` for errors
- `data` — the payload on success; on error, an object `{"message": "...", "error": {...}}`
- `code` — the HTTP status code, duplicated in the body

Validation error example (`422`):

```json
{
  "success": false,
  "data": {
    "message": "An error occurred during execution",
    "error": {
      "email": ["Email cannot be blank."]
    }
  },
  "code": 422
}
```

Uncaught exception example (`error` is only populated when `YII_DEBUG=true`):

```json
{
  "success": false,
  "data": {
    "message": "Not found",
    "error": {}
  },
  "code": 404
}
```

## Authentication (JWT)

### `POST /auth/login`

The only public endpoint. Login is by `email` + `password`.

**Request body:**

```json
{
  "email": "user@example.com",
  "password": "secret123"
}
```

Validation: both fields required, `email` must be a valid address (max 255 chars), `password` max 72 chars.

**Success response (200):**

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "expires_in": 3600
  },
  "code": 200
}
```

Wrong credentials → `401`.

### Using the token

Send it on every subsequent request:

```
Authorization: Bearer <access_token>
```

The token is a stateless HS256 JWT carrying the user id in its `sub` claim. Its lifetime is `JWT_TTL` (default 3600s, configured via `.env`). Nothing is stored server-side — there is no logout endpoint; the client simply stops using the token, and after `expires_in` seconds a new `POST /auth/login` is required.

## Pagination

List endpoints (`GET /users`, `GET /albums`, `GET /albums/<id>/photos`) are paginated at **20 items per page**:

```json
{
  "success": true,
  "data": {
    "items": [ ... ],
    "pagination": {
      "total": 45,
      "per_page": 20,
      "current_page": 1,
      "last_page": 3,
      "from": 1,
      "to": 20
    }
  },
  "code": 200
}
```

Navigate pages with the standard Yii `ActiveDataProvider` query param: `?page=2`.

## Users

| Method | Path | Description |
|---|---|---|
| GET | `/users` | paginated list of users |
| GET | `/users/<id>` | single user |
| POST | `/users` | create |
| PUT/PATCH | `/users/<id>` | update (partial) |
| DELETE | `/users/<id>` | delete |

**Response fields:** `id`, `first_name`, `last_name`, `email`.
`auth_key`, `access_token`, `password_hash` are never returned or accepted from the client — the password is hashed server-side.

**Expandable field:** add `?expand=albums` to `GET /users/<id>` to include the user's albums in the response.

### `POST /users` — create

```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "password": "secret123"
}
```

All 4 fields are required. `email` must be unique (checked at the form layer — returns 422 on a duplicate before the service is even called). `password` must be 6–72 characters.

**Response (201):**

```json
{
  "success": true,
  "data": {
    "id": 12,
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com"
  },
  "code": 201
}
```

### `PUT /users/<id>` — update

All fields are optional (partial update) — send only what you want to change:

```json
{ "first_name": "Jane" }
```

Email uniqueness is checked excluding the record being updated (a user can keep their own email). Success → `200`.

### `DELETE /users/<id>`

No request body. Success → `204 No Content`.

## Albums

| Method | Path | Description |
|---|---|---|
| GET | `/albums` | paginated list of albums |
| GET | `/albums/<id>` | single album — **including photos and the owner's name** |
| POST | `/albums` | create |
| PUT/PATCH | `/albums/<id>` | update (partial) |
| DELETE | `/albums/<id>` | delete |

**List fields (`GET /albums`):** `id`, `title` (add `?expand=photos` for nested photos).

**`GET /albums/<id>` has a distinct, richer response shape** (different from the list item):

```json
{
  "success": true,
  "data": {
    "id": 5,
    "title": "Vacation 2025",
    "first_name": "John",
    "last_name": "Doe",
    "photos": [
      { "id": 41, "title": "Beach", "url": "http://localhost:8084/uploads/albums/5/ab12cd.webp" }
    ]
  },
  "code": 200
}
```

`first_name`/`last_name` here belong to the album's owner (`Album.user`), not the album itself.

### `POST /albums` — create

```json
{
  "user_id": 3,
  "title": "Vacation 2025"
}
```

`user_id` and `title` are required; `user_id` must reference an existing user (otherwise `422`).

**Response (201):**

```json
{
  "success": true,
  "data": { "id": 5, "title": "Vacation 2025" },
  "code": 201
}
```

### `PUT /albums/<id>` — update

Both fields optional, only what's sent gets updated:

```json
{ "title": "Vacation summer 2025" }
```

### `DELETE /albums/<id>`

Success → `204`. Note: deleting an album does not automatically clean up its photo files — check `AlbumService`/DB constraints if cascading file cleanup is needed.

## Photos

Photos are a **nested resource of an album**: listing and creation are only possible via `/albums/<albumId>/photos`. There is no flat `GET /photos` (returns 405).

| Method | Path | Description |
|---|---|---|
| GET | `/albums/<albumId>/photos` | paginated list of an album's photos |
| POST | `/albums/<albumId>/photos` | upload a new photo into an album |
| GET | `/photos/<id>` | view a single photo |
| PUT/PATCH | `/photos/<id>` | update (title only) |
| DELETE | `/photos/<id>` | delete (also removes the file) |

**Response fields:** `id`, `title`, `url` (full public link, built by `PhotoUrlBuilder`).

### `GET /albums/<albumId>/photos`

Standard paginated list; if `albumId` doesn't exist, the service returns 404.

### `POST /albums/<albumId>/photos` — upload a photo

This is the **only `multipart/form-data` endpoint** (not JSON):

```
POST /albums/5/photos
Content-Type: multipart/form-data
Authorization: Bearer <token>

title=My photo
file=<binary image>
```

curl example:

```bash
curl -X POST http://localhost:8084/albums/5/photos \
  -H "Authorization: Bearer $TOKEN" \
  -F "title=Beach sunset" \
  -F "file=@/path/to/photo.jpg"
```

Rules:
- `title` — required, max 255 characters
- `file` — required, single file, allowed extensions: `jpg, jpeg, png, webp, gif, avif` (checked by extension, not form MIME type — but the actual file content is still validated during image processing)
- The server converts every upload to **WebP quality 80**, scaled to fit **500×500** preserving aspect ratio (never upscaled), and stores it at `web/uploads/albums/<albumId>/<random>.webp`
- If the file isn't actually a valid image despite a matching extension, processing fails and returns `422`, and the uploaded file is deleted

**Response (201):**

```json
{
  "success": true,
  "data": {
    "id": 41,
    "title": "Beach sunset",
    "url": "http://localhost:8084/uploads/albums/5/8f3ac91b.webp"
  },
  "code": 201
}
```

### `PUT /photos/<id>` — update

Only `title` can change — the album and the stored file are immutable once uploaded:

```json
{ "title": "New title" }
```

### `DELETE /photos/<id>`

Deletes the record and the physical file (when `source = 'photo'`; seeded demo photos with `source = 'seed'` share files under `web/default-images` and are not deleted). Success → `204`.

## Status codes — summary

| Code | When |
|---|---|
| 200 | successful GET / PUT / PATCH, `/auth/login` |
| 201 | successful POST (create) |
| 204 | successful DELETE |
| 401 | missing/invalid Bearer token, wrong login credentials |
| 404 | resource not found (`<id>` doesn't exist) |
| 405 | unsupported method/path (e.g. `GET /photos` without an albumId) |
| 422 | request body or model validation failure |
| 500 | unexpected server error |

## CORS

All origins (`*`) and all methods (`GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS`) are allowed. `OPTIONS` preflight requests don't require authorization.

## Typical usage flow

1. `POST /auth/login` → obtain `access_token`
2. Send `Authorization: Bearer <token>` on every subsequent request
3. `POST /albums` → create an album for a user
4. `POST /albums/<albumId>/photos` (multipart) → upload a photo into the album
5. `GET /albums/<albumId>` → fetch the album with all its photos and the owner in one request
