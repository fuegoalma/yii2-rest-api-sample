# Yii2 REST API

[![CI](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/ci.yml)
[![CD](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/cd.yml)
[![Security](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/security.yml/badge.svg)](https://github.com/fuegoalma/yii2-rest-api-sample/actions/workflows/security.yml)

A REST API for **users, albums and photos**, built with Yii2 and PHP 8.5. JWT
authentication on a two-token model, flat role-based access control, background
jobs, and an OpenAPI document that is **checked against the code** rather than
merely written alongside it.

Everything runs in Docker. The whole toolchain is behind `make` — see
`make help`.

```bash
make init && make setup     # first time: .env, containers, databases, migrations
make check                  # every gate CI runs: style, static analysis, tests at 100% coverage
```

- API — <http://localhost:8084>
- Interactive docs (Swagger UI) — <http://localhost:8084/docs>
- phpMyAdmin — <http://localhost:8085>

---

## What is worth looking at

| | |
| --- | --- |
| **The contract gates** | `config/openapi.yaml` is the published source of truth, and six gates in [`tests/unit/contract/`](tests/unit/contract/) hold the code to it — routes in both directions, response schemas, search forms, write forms, RBAC and the document's own integrity. Writing them found three real defects, including a document that was not valid YAML and a 255-character email address the API could never have accepted. See [ADR 5](docs/adr/0005-openapi-as-a-checked-contract.md). |
| **100% coverage as a gate** | Not a target — the build fails below it, `@covers` is forbidden, and an exemption needs a written argument that the code is unreachable *by construction*. Untestable code becomes a design signal. See [ADR 6](docs/adr/0006-hundred-percent-coverage-as-a-gate.md). |
| **Two-token auth with reuse detection** | Stateless access token, rotating opaque refresh token; replaying a spent one is treated as a leak and revokes the whole session family. See [ADR 1](docs/adr/0001-two-token-authentication.md) and [ADR 2](docs/adr/0002-refresh-token-families.md). |
| **Errors you can act on** | One shape, a status → message catalog, a stable `error_code` to branch on, validation errors strictly separated from debug detail — and an uncaught exception's message never reaching a caller in production. See [ADR 11](docs/adr/0011-machine-readable-error-codes.md). |
| **A deployable image, proved** | `make smoke` boots the production image against a real database, migrates inside it and exercises it end to end. It replaced a `php --version` check, and immediately found that console commands were broken in the production image and that every logged error was dumping `JWT_SECRET` into the log stream. |
| **One id, end to end** | Every request carries `X-Request-Id` through the response, the structured logs, and into any background job it queued — so `docker compose logs web` and `logs worker` tell one story. |

## Documentation

- **[Handbook](docs/handbook.md)** — the environment, every subsystem, and how to run every gate.
- **[Architecture decision records](docs/adr/README.md)** — what was chosen, what it cost, and what undoing it would take.
- **[CLAUDE.md](CLAUDE.md)** — the working agreement for changes to this repository.
- **`GET /docs`** — the interactive OpenAPI documentation, served by the app itself.

---

## Requirements

- **Docker** (Engine 20.10+)
- **Docker Compose v2** — the `docker compose` plugin (with a space), *not* the legacy `docker-compose` v1
- **Buildx** — the BuildKit builder plugin, used to build the image

On Ubuntu these come from the `docker-compose-v2` and `docker-buildx` packages:

```bash
sudo apt-get install -y docker-compose-v2 docker-buildx
```

Verify with `docker compose version` and `docker buildx version`.

## Getting started

```bash
make init     # creates .env from .env.example
```

Edit `.env` if the defaults do not suit — database credentials, `JWT_SECRET`
(at least 32 characters), and `YII_ENV`/`YII_DEBUG`, which default to
**production** when absent.

```bash
make setup    # starts Docker, installs dependencies, creates both databases, migrates
```

Then bootstrap the first administrator, since everything RBAC-driven needs an
existing role manager:

```bash
make rbac-assign role=super_admin email=you@example.com
```

The [handbook](docs/handbook.md) covers the rest: the container layout, the
architecture, migrations, seeding, the test suites, and the CI/CD pipeline.

## Commands

`make help` lists them all. The ones used most:

```bash
make up / down / logs / sh    # container lifecycle
make test                     # the full suite
make test-contract            # only the OpenAPI contract gates
make coverage                 # the suite with the 100% gate enforced
make check                    # everything CI runs
make smoke                    # build the production image and prove it is deployable
make hooks-install            # commit-msg, pre-commit and pre-push hooks
```
