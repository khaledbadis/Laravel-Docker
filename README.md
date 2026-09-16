# Laravel Docker Todo

A learning project: manually assemble a Laravel, Livewire, Tailwind, and PostgreSQL todo app, then deploy it using Docker on self-managed infrastructure.

**Current state:** Phase 8 is complete and locally verified. Production images, deployment/rollback scripts, and CI publishing configuration are implemented. Real infrastructure rollout is Phase 9. The authenticated home page supports creating, editing, completing/reopening, and deleting your tasks, with filters and pagination.

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
| `db_test` | Disposable PostgreSQL instance, started only for tests |

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
docker compose run --rm --no-deps node npm ci
docker compose run --rm --no-deps node npm run build
docker compose ps
docker compose exec web wget -qO- http://127.0.0.1/up
```

Open [http://localhost:8080](http://localhost:8080) to log in. Create a local account at `/register`, then add your first task. Use your configured port if you changed `WEB_PORT`. No host PHP, Composer, or Node is required.

Generate the application key only when setting up a new `.env` with an empty `APP_KEY`. Keep an existing key when updating or restarting the app. Migrations create the `users`, `sessions`, `cache`, and other standard Laravel tables; rerunning `migrate` applies only pending migrations.

`/healthz` returns `nginx ok` and checks Nginx alone. `/up` runs through PHP-FPM and confirms Laravel boots; the default Laravel health route does not query PostgreSQL. The home page also exercises database-backed sessions.

`composer.lock` records the PHP dependency versions. Use `composer install` after pulling code; use `composer update` only for deliberate dependency upgrades. There is no need to rerun `composer create-project` after cloning this repository.

`package-lock.json` pins frontend dependencies. Use `npm ci` after cloning or pulling dependency changes. The page requires either compiled assets or a running Vite server; the scaffold fallback styles have been removed. You can inspect the tooling with:

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

## Frontend development and compiled assets

Start hot reload after installing dependencies:

```bash
docker compose up -d node
docker compose logs --tail=30 node
```

Keep the browser on [http://localhost:8080](http://localhost:8080). Vite serves assets on port 5173; it does not serve the Laravel page. CSS changes update in place; Blade changes trigger a page refresh. Saved tasks persist in PostgreSQL across refreshes; unsaved form input does not.

To use compiled assets with Node stopped:

```bash
docker compose stop node
docker compose run --rm --no-deps node npm run build
```

Reload the browser afterward. A normal stop removes `public/hot`. If Vite was killed abruptly and the page still points at port 5173, confirm the Node service is stopped and remove only the generated `public/hot` file, then reload. Never commit `public/hot`, `public/build`, or `node_modules`.

`APP_URL` supplies Vite's allowed browser origin and hostname; `VITE_PORT` supplies its published port and browser WebSocket port. The container always listens on port 5173. After changing these settings in `.env`, run `docker compose up -d --force-recreate node`. This configuration targets local HTTP development; production asset builds do not use these development URLs.

## Phase 3 implementation

Livewire was installed explicitly with `docker compose exec app composer require 'livewire/livewire:^4.0'`. Future clones install it from `composer.lock`.

- `app/Livewire/TaskList.php`: task form state, actions, filters, pagination, and layout selection (replaces the Phase 3 counter).
- `resources/views/livewire/task-list.blade.php`: interactive component markup.
- `resources/views/layouts/app.blade.php`: shared page layout and explicit Vite/Livewire asset directives.
- `resources/css/app.css` and `vite.config.js`: Tailwind source discovery and Docker hot reload settings.

We use system fonts and Livewire's bundled Alpine.js. No separate Alpine installation, external font download, or host process runner is needed.

## Accounts and registration

- `/login` and `/register` are guest-only pages. Successful authentication redirects to `/`, your task list.
- Email addresses are trimmed and lowercased. New passwords require at least 12 characters and at most 72 bytes, matching bcrypt's input limit.
- Five failed login attempts for the same email/IP block further attempts for 60 seconds. A successful login clears that counter. Separate IP limits allow 30 login submissions and 10 registration submissions per minute.
- The header logout button submits a CSRF-protected POST. Login rotates the session; logout invalidates it and regenerates the CSRF token.

Local `.env` enables registration. To close signup while preserving login for existing users, set:

```dotenv
AUTH_REGISTRATION_ENABLED=false
```

Then run `docker compose exec app php artisan config:clear` in development. Both GET and POST `/register` return 404, and the signup link disappears. The configuration defaults to disabled when the variable is absent. In production, rebuild the configuration cache after changing this setting. Password recovery and email verification remain outside the first-release scope.

`TaskService` queries through the authenticated user’s `tasks()` relationship and checks `TaskPolicy` for reads and mutations. Other users’ task IDs return not found. The Livewire interface calls this service for every task query and mutation.

## Task persistence and demo data

After pulling Phase 5, apply the new table:

```bash
docker compose exec app php artisan migrate --no-interaction
```

`TaskService` provides create, find, update, complete/reopen, delete, and paginated listing operations for the signed-in user. Titles are trimmed and limited to 255 characters; optional notes are trimmed and limited to 5,000 characters. Blank notes become `null`. Only title and notes are accepted from submitted data; the service assigns ownership and completion status itself.

Lists accept `all`, `active`, or `completed`, return 20 records per page, and sort newest first with ID as the tie-breaker. Completing an already-completed task preserves its timestamp. Reopening clears it.

Optional local demo data:

```bash
docker compose exec app php artisan db:seed --class=DemoSeeder
```

This creates `demo@example.test` with password `local-demo-password` and four tasks, one completed. It runs only with `APP_ENV=local`. If that email already exists, nothing changes. The default `db:seed` creates no accounts or tasks. Demo seeding was tested in isolation and has not been run on your development database. Log in with that account to view its tasks.

## Current checks

```bash
docker compose exec app composer validate --strict
docker compose exec app composer check-platform-reqs
docker compose up -d --wait db_test
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
```

The suite uses the isolated `db_test` PostgreSQL server, with array-backed sessions/cache and Vite disabled. Authentication tests apply migrations with `RefreshDatabase`; they do not touch the development database. Forced test settings and a bootstrap guard reject another database host, account, database name, or a database URL override.

The test database uses memory-backed storage and is disposable. Start it before running the suite; stopping it discards its data:

```bash
docker compose up -d --wait db_test
docker compose exec app composer test
docker compose stop db_test
```

Do not run `migrate:fresh` against the regular `db` service. The CI workflow below runs this suite against `db_test`.

## How Laravel was installed

The one-time bootstrap used this command inside the existing PHP service:

```bash
docker compose exec app composer create-project laravel/laravel /tmp/laravel-phase2 '^13.0' --prefer-dist --no-install --no-scripts --no-interaction
```

The skeleton files were copied into the repository while preserving the existing README, environment template, and ignore rules. The generated host setup/dev scripts and their Pail/Pao helpers were omitted; our Docker commands handle setup and logs explicitly. Tool-specific bootstrap instructions were also omitted. Composer dependencies, key generation, and migrations were then run separately. See [GUIDE.md](GUIDE.md) for why those steps are distinct.

## Remaining operating instructions

- **Phase 9:** real infrastructure rollout, HTTPS edge, scheduled backups and operational handoff.

`compose.yaml` and `docker/php/Dockerfile` are development-only. Production uses `compose.production.yaml` and `docker/production/Dockerfile`.

## Verified environment

On 2026-09-13: Docker 29.7.2, Compose 5.5.0, Linux x86_64, PHP 8.4.25, Composer 2.10.3, PostgreSQL 18.6, Nginx 1.30.4, Node 24.21.0, and npm 11.19.0.

Checks passed: image build, Compose startup, PostgreSQL health and authenticated PHP query, required PHP extensions, PHP/Node file ownership, Nginx configuration, health endpoint, and denial of `.env` access. See the spec for remaining phase checks.

Laravel skeleton v13.10.1 resolved Laravel Framework v13.31.0; PHP dependencies are pinned in `composer.lock`. Phase 2 validation results are recorded in [PROJECT_SPEC.md](PROJECT_SPEC.md).

Phase 3 verified Livewire 4.4.4, Tailwind 4.3.3, and Vite 8.3.0: four tests (10 assertions), Pint, npm clean install/build, browser Livewire actions, CSS hot replacement, Blade auto-refresh, and compiled assets with Node stopped.

Phase 4: 19 tests / 178 assertions pass. HTTP smoke checks verified CSRF rejection, registration/login, logout, and rejection of a stale Livewire action after logout. The synthetic smoke account was removed.

Phase 5: 43 tests / 252 assertions and Pint pass. PostgreSQL checks cover foreign keys, cascade deletion, title/notes limits, ownership isolation, lifecycle operations, filtering, pagination, and local-only demo seeding. The additive development migration was applied successfully.

## Phase 6 interface

Use the form to add a title and optional notes. Edit loads a task into the same form; Cancel editing discards unsaved changes. Complete/Reopen changes its status. Delete opens an inline confirmation; Keep task cancels it.

All, Active, and Completed show only your tasks, newest first, 20 per page. Changing filters resets the page. Deleting or completing the last matching task on a page moves to the last available page. Adding a task switches to All on page 1 so the new task is visible. Filters and unsaved edits reset on refresh; saved changes persist.

Phase 6 verification: 48 tests / 299 assertions, Pint, and the frontend build pass. Browser checks cover task creation, editing, persistence after reload, status changes, filters, and deletion at desktop and mobile sizes.

## CI and clean setup verification

`.github/workflows/quality.yml` runs on pushes, pull requests, and manual dispatch using a GitHub-hosted Ubuntu runner. It has read-only repository permissions and needs no repository secrets. It builds the development PHP image, installs both lockfiles, runs the PostgreSQL tests and Pint, builds frontend assets, then checks HTTP behavior and persistence after container recreation. It also builds and rehearses the production images.

To run the same check locally, use a **separate fresh checkout** containing these scripts, on Linux with a non-root user, Bash, standard GNU utilities, Git, Docker, and Compose:

```bash
git clone <repository-url> laravel-todo-check
cd laravel-todo-check
bash scripts/verify.sh
# If port 18080 is occupied, use this instead:
# VERIFY_WEB_PORT=18081 bash scripts/verify.sh
```

The script refuses an existing `.env`, `vendor`, or `node_modules`. It generates an isolated `phase7-*` Compose project, creates fresh dependencies and database storage, and retains the same application key through recreation. On exit it prints recent container logs and removes only that project's containers, network, and volumes. Generated files and the local image remain for inspection; use another fresh checkout for a repeat run. Your normal `laravel-todo` database is separate. If Nginx reports `Permission denied`, check that the checkout directory is traversable by its container user; a directly bind-mounted `mktemp -d` directory normally has restrictive `0700` permissions. Clone into a normal subdirectory instead.

The HTTP checks register a disposable account, verify private-file protection and compiled asset responses, and confirm the account, task, session, and cache survive `down` followed by `up`. They also verify logout CSRF protection and login. Livewire behavior is exercised by the component tests; this script does not automate a graphical browser.

The test-isolation check deliberately caches the disposable local database configuration and expects PHPUnit's bootstrap guard to reject it before migrations run. The subsequent persistence check confirms the fixture remains intact. Avoid a cached configuration when running your normal development tests; `composer test` clears it automatically.

Verified on 2026-09-14 from committed application `58fdafc` plus the new verification scripts: clean dependency installs, development image build, 48 tests / 299 assertions, Pint (42 files), HTTP checks, cached-configuration guard rejection, and user/task/session/cache persistence after container recreation all passed. Temporary containers, networks, and volumes were removed; the existing development stack stayed running.

After committing and pushing the workflow, inspect **Actions → Quality → Docker tests and clean setup** on GitHub. A local pass verifies the script, but the first hosted workflow run still needs to pass after push.

## Production releases (Phase 8)

Use [GUIDE.md](GUIDE.md#phase-8-turning-a-checkout-into-a-production-release) for the detailed design, migration rules and recovery procedure. Production uses `compose.production.yaml` **alone**. Never combine it with the development Compose file.

Build and rehearse locally on Linux (Docker, Compose, Bash, GNU utilities and `flock`):

```bash
export IMAGE_REPOSITORY=local-todo
export RELEASE_SHA=$(git rev-parse HEAD)
bash scripts/build-production.sh
bash scripts/verify-production.sh
```

The rehearsal uses fresh, disposable volumes and localhost port 18081; override with `VERIFY_PRODUCTION_PORT=18082` if occupied. It validates HTTPS, secure sessions, a Livewire task creation, compiled assets, redeployment, database dump restoration, full-stack recreation, database-outage readiness, failed-deployment handling and the rollback command path. The local tag identifies the checked-out commit; uncommitted changes are included in a local build, so publish only from a committed CI checkout.

For real releases, push the commit, then run **Actions → Publish production images → Run workflow** for the intended ref. Wait for verification and both GHCR pushes. The image names are `ghcr.io/<lowercase-owner>/<lowercase-repository>-app:<full-sha>` and the corresponding `-web:<full-sha>`. The workflow publishes images; it does not deploy to your infrastructure. Set package visibility or host pull credentials separately.

Prepare a small deployment bundle from that release on your workstation:

```bash
tar -czf /tmp/little-list-deploy.tar.gz compose.production.yaml .env.production.example scripts/deploy.sh
```

Transfer/extract the bundle into a dedicated directory on the Docker VM, for example `/opt/apps/little-list`. The server does not need the source tree, PHP, Composer or Node. Use a non-root deployment account with Docker access; protect the directory and back up its configuration privately.

```bash
cp .env.production.example .env.production
chmod 600 .env.production
```

Fill in the real `IMAGE_REPOSITORY`, HTTPS `APP_URL`, database credentials, stable project name, localhost port and trusted edge address. Generate a new installation's key with the published PHP image, then store the output as `APP_KEY` in the protected environment file:

```bash
# Replace both placeholders with the actual published values.
docker run --rm --entrypoint php ghcr.io/OWNER/REPOSITORY-app:FULL_SHA \
  -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Do this once per installation. Keep the same key on upgrades. Use a password manager to generate and retain the database password; do not reuse the development credentials.

Deploy (substitute the full lowercase 40-character commit SHA):

```bash
bash scripts/deploy.sh .env.production FULL_SHA
```

The script creates a database dump in `backups/`, applies migrations and replaces the app. It writes `.release` only on success. A failure after ingress stops leaves it stopped for manual recovery; consult GUIDE.md. Expect brief downtime. `--local` is for already-built local rehearsal images and skips registry pulls; normal server deployments should pull.

For subsequent operational commands, select the successful SHA explicitly:

```bash
export RELEASE_SHA=$(cat .release)
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs --tail=100 app web db

# Interactive prompts keep the password out of command history; signup stays closed.
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps app php artisan app:create-user
```

Configure the VM's HTTPS edge to forward the chosen domain to `127.0.0.1:8081` (or the selected port). The Compose network gateway is normally the source seen for a host-installed edge. Inspect it, configure `TRUSTED_PROXIES` narrowly, redeploy and confirm real client IP handling. Test the public HTTPS URL and task actions after every rollout.

Rollback to a compatible previous image pair:

```bash
previous_sha=$(cat .previous-release)
bash scripts/deploy.sh .env.production "$previous_sha" --rollback
```

After a failed release, `.release` still names the last successful release. `--rollback` skips migrations and never restores a database automatically. Verify schema compatibility first. Pre-release dumps remain on the same machine: schedule off-machine backups, secret protection and restore drills during Phase 9.


Phase 8 verification (2026-09-14; documentation finalized 2026-09-16): 51 tests / 315 assertions and Pint (48 files) passed. Matching production images passed the HTTPS/Livewire rehearsal, backup restoration, session/task persistence across full recreation, database-outage readiness, and invalid-key failure/recovery checks. The rollback rehearsal used the same image pair; compatibility with a different release must be reviewed when it exists. Private hosting notes and environment files were absent from the app image. Temporary containers and volumes were removed. Images have not been pushed to GHCR and no real server deployment has been performed.
