# Laravel Docker Todo

A learning project: manually assemble a Laravel, Livewire, Tailwind, and PostgreSQL todo app, then deploy it using Docker on self-managed infrastructure.

**Current state:** Phase 2 is complete. Laravel is installed and connected to PostgreSQL, with database-backed sessions and cache. Livewire integration and the todo interface come in later phases.

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

## Setup from a fresh clone

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
docker compose exec app composer install --no-interaction --prefer-dist
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --no-interaction
docker compose ps
curl --fail http://localhost:8080/up
```

Open [http://localhost:8080](http://localhost:8080) to see Laravel's welcome page. Use your configured port if you changed `WEB_PORT`. No host PHP, Composer, or Node is required.

Generate the application key only when setting up a new `.env` with an empty `APP_KEY`. Keep an existing key when updating or restarting the app. Migrations create the `users`, `sessions`, `cache`, and other standard Laravel tables; rerunning `migrate` applies only pending migrations.

`/healthz` returns `nginx ok` and checks Nginx alone. `/up` runs through PHP-FPM and confirms Laravel boots; the default Laravel health route does not query PostgreSQL. The home page also exercises database-backed sessions.

`composer.lock` records the PHP dependency versions. Use `composer install` after pulling code; use `composer update` only for deliberate dependency upgrades. There is no need to rerun `composer create-project` after cloning this repository.

The scaffold includes frontend source and `package.json`, but npm dependencies and Livewire have not been installed. The welcome page includes fallback styles, so it works before Vite is configured. `node` stays behind the `frontend` profile until Phase 3. You can inspect the tooling now:

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

# Inspect Laravel routes and applied migrations.
docker compose exec app php artisan route:list
docker compose exec app php artisan migrate:status

# Open PostgreSQL using the configured container credentials.
docker compose exec db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# Stop and remove containers/network, preserving the named database volume.
docker compose down
```

`docker compose exec` runs a command in an existing container. `docker compose run --rm` starts a temporary container for a command and removes it afterward. Rebuild `app` after changing its Dockerfile or UID/GID settings.

Do not add `--volumes`/`-v` to `down` unless you deliberately want to delete the local database. Keep `COMPOSE_PROJECT_NAME` stable so Compose finds the same named volume. Changing initialization credentials in `.env` does not change accounts already stored in PostgreSQL.

## Current checks

```bash
docker compose exec app composer validate --strict
docker compose exec app composer check-platform-reqs
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
```

The initial tests check the scaffold and a successful home response. They use in-memory session/cache stores and do not access a database. `phpunit.xml` reserves a separate PostgreSQL database/account named `todo_test`; it is not provisioned yet, so database-dependent tests will fail until Phase 5 sets it up. It never defaults to the development database. PostgreSQL integration and persistence are checked separately during Phase 2.

## How Laravel was installed

The one-time bootstrap used this command inside the existing PHP service:

```bash
docker compose exec app composer create-project laravel/laravel /tmp/laravel-phase2 '^13.0' --prefer-dist --no-install --no-scripts --no-interaction
```

The skeleton files were copied into the repository while preserving the existing README, environment template, and ignore rules. The generated host setup/dev scripts and their Pail/Pao helpers were omitted; our Docker commands handle setup and logs explicitly. Tool-specific bootstrap instructions were also omitted. Composer dependencies, key generation, and migrations were then run separately. See [GUIDE.md](GUIDE.md) for why those steps are distinct.

## Remaining operating instructions

- **Phase 3:** Livewire installation, frontend dependencies, and Vite hot reload.
- **Phase 7:** isolated PostgreSQL tests, formatting, and frontend builds.
- **Phases 8–9:** production Compose, releases, HTTPS, backups, restore, and rollback.

`compose.yaml` and the current PHP image are development-only. Production will use a separate Compose file and image targets.

## Verified environment

On 2026-09-13: Docker 29.7.2, Compose 5.5.0, Linux x86_64, PHP 8.4.25, Composer 2.10.3, PostgreSQL 18.6, Nginx 1.30.4, Node 24.21.0, and npm 11.19.0.

Checks passed: image build, Compose startup, PostgreSQL health and authenticated PHP query, required PHP extensions, PHP/Node file ownership, Nginx configuration, health endpoint, and denial of `.env` access. See the spec for remaining phase checks.

Laravel skeleton v13.10.1 resolved Laravel Framework v13.31.0; PHP dependencies are pinned in `composer.lock`. Phase 2 validation results are recorded in [PROJECT_SPEC.md](PROJECT_SPEC.md).
