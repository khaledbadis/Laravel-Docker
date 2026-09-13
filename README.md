# Laravel Docker Todo

A learning project: manually assemble a Laravel, Livewire, Tailwind, and PostgreSQL todo app, then deploy it using Docker on self-managed infrastructure.

**Current state:** Phase 1 development infrastructure is complete and verified. Laravel is installed in Phase 2; the todo interface comes later.

## Project documents

- [PROJECT_SPEC.md](PROJECT_SPEC.md): requirements, architecture, implementation phases, and acceptance checks. Follow this file when building the project.
- [GUIDE.md](GUIDE.md): concise explanations of the Laravel and Docker concepts used here.

## Services

| Service | Purpose |
| --- | --- |
| `app` | Laravel on PHP-FPM |
| `db` | PostgreSQL with persistent storage |
| `web` | Nginx public entry point |
| `node` | Development Vite server; assets are compiled during production builds |

The host will need Git, Docker Engine, and the Docker Compose plugin. PHP, Composer, and Node will run in containers.

## Implementation sequence

1. Docker environment and repository foundations.
2. Plain Laravel installation and PostgreSQL connection.
3. Manual Livewire and Tailwind integration.
4. Authentication and authorization.
5. Task data model and business rules.
6. Todo UI and interactions.
7. Tests, CI, and clean setup verification.
8. Production images and deployment workflow.
9. Server rollout, backups, and operational handoff.

## Local container setup

Run these commands from the repository root. Docker must be running and your user must have daemon access.

```bash
docker --version
docker compose version
docker info
cp -n .env.example .env
id -u
id -g
```

Set `LOCAL_UID` and `LOCAL_GID` in `.env` to the two IDs printed above (both are `1000` on the current Linux host). Choose unused `WEB_PORT` and `VITE_PORT` values if the defaults conflict. If you change `WEB_PORT`, update `APP_URL` too. The example database password is only for this local development environment.

```bash
docker compose config --quiet
docker compose build app
docker compose --profile frontend pull db web node
docker compose up -d --wait
docker compose ps
curl --fail http://localhost:8080/healthz
```

The health URL should return `nginx ok`. It checks Nginx only. `/` returns 404 until Laravel's `public/index.php` exists in Phase 2.

`node` is behind the `frontend` profile because the application does not have a `package.json` yet. Its tooling can already be invoked:

```bash
docker compose run --rm --no-deps node node --version
docker compose run --rm --no-deps node npm --version
docker compose exec app php --version
docker compose exec app composer --version
docker compose exec app php -m
docker compose exec web nginx -t
```

## Daily container commands

```bash
# Start infrastructure and inspect recent output.
docker compose up -d --wait
docker compose logs --tail=100 app db web

# Open a shell in the PHP container.
docker compose exec app bash

# Open PostgreSQL using the configured container credentials.
docker compose exec db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# Stop and remove containers/network, preserving the named database volume.
docker compose down
```

`docker compose exec` runs a command in an existing container. `docker compose run --rm` starts a temporary container for a command and removes it afterward. Rebuild `app` after changing its Dockerfile or UID/GID settings.

Do not add `--volumes`/`-v` to `down` unless you deliberately want to delete the local database. Keep `COMPOSE_PROJECT_NAME` stable so Compose finds the same named volume. Changing initialization credentials in `.env` does not change accounts already stored in PostgreSQL.

## Remaining operating instructions

- **Phases 2–3:** Laravel installation, application key, migrations, dependencies, and Vite hot reload.
- **Phase 7:** isolated PostgreSQL tests, formatting, and frontend builds.
- **Phases 8–9:** production Compose, releases, HTTPS, backups, restore, and rollback.

`compose.yaml` and the current PHP image are development-only. Production will use a separate Compose file and image targets.

## Verified environment

On 2026-09-13: Docker 29.7.2, Compose 5.5.0, Linux x86_64, PHP 8.4.25, Composer 2.10.3, PostgreSQL 18.6, Nginx 1.30.4, Node 24.21.0, and npm 11.19.0.

Checks passed: image build, Compose startup, PostgreSQL health and authenticated PHP query, required PHP extensions, PHP/Node file ownership, Nginx configuration, health endpoint, and denial of `.env` access. See the spec for remaining phase checks.
