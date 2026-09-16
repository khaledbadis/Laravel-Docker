# Laravel Docker Todo — project specification

Status: Phases 1–8 complete and locally verified. Next: Phase 9, rollout on the real infrastructure. Production image publishing and hosted workflow verification await commit/push and manual release dispatch; no server rollout has been performed.

## Purpose

Build a small todo app while learning how to assemble Laravel and Livewire manually and operate a reproducible Docker deployment on a self-managed server. Follow a production project lifecycle, keeping the feature set small.

This document is the implementation checklist and source of truth. Complete phases in order, record validation results, and update README.md and GUIDE.md with each phase. A phase is complete only when its acceptance criteria pass; document blockers instead of marking unfinished work complete.

## Scope and decisions

- Plain Laravel skeleton installed through containerized Composer; no Herd, Sail, or application starter kit.
- Manually install and configure Livewire and Tailwind through Vite.
- Target Laravel 13, Livewire 4, and Tailwind 4. Confirm compatible PHP, Node, PostgreSQL, and Nginx versions in Phase 1 before pinning image versions and dependency lockfiles. Avoid floating `latest` images.
- Use Docker Compose for local development and a single-server production deployment.
- Use Laravel conventions: Eloquent models, policies, migrations, Blade views, and Livewire components. Add abstractions only when they serve a concrete need.
- Default product assumption: users log in to private task lists. Registration is available locally; production registration can be disabled after initial account creation.
- No sharing, teams, tags, reminders, uploads, public API, queues, or scheduler in the first release. Password recovery and email verification are deferred until mail delivery is in scope.

## Container architecture

### Selected development versions

Registry manifests resolved on 2026-09-13. The Dockerfile and Compose file pin official multi-platform image digests, so moving tags do not silently change the base images.

| Dependency | Selection | Reason |
| --- | --- | --- |
| PHP | 8.4.25, FPM, Debian Bookworm | Meets Laravel 13 requirements; familiar Debian extension tooling |
| Composer | 2.10.3 | Composer 2 dependency management, copied into PHP image |
| PostgreSQL | 18.6, Debian Bookworm | Current PostgreSQL 18 series; use its documented volume layout |
| Nginx | 1.30.4, Alpine | Resolved official stable series; small proxy image |
| Node | 24.21.0 LTS, npm 11.19.0, Debian Bookworm slim | Supported LTS runtime for Vite; exact image fixed by digest |

Phase 2 installed Laravel skeleton v13.10.1 and resolved Laravel Framework v13.31.0; PHP dependencies are recorded in `composer.lock`. Phase 3 adds Livewire 4.4.4 to that lockfile and pins Tailwind 4.3.3 / Vite 8.3.0 in `package-lock.json`. Pinning base-image digests does not freeze Debian packages installed from live apt repositories.

References: [Laravel server requirements](https://laravel.com/docs/13.x/deployment), [Node release lifecycle](https://nodejs.org/en/about/previous-releases), and official [PHP](https://hub.docker.com/_/php), [Composer](https://hub.docker.com/_/composer), [PostgreSQL](https://hub.docker.com/_/postgres), and [Nginx](https://hub.docker.com/_/nginx) images.

### Service roles

| Service | Responsibility | Development | Production |
| --- | --- | --- | --- |
| `app` | PHP-FPM runs Laravel; same image runs Composer/Artisan commands where appropriate | Source bind mount; development dependencies | Built application image with PHP dependencies and no development packages |
| `db` | PostgreSQL | Named data volume; internal network | Persistent volume, internal network, external backups |
| `web` | Nginx serves public assets and forwards PHP requests to `app:9000` using FastCGI | Localhost HTTP port | Public assets copied from the same release build as `app` |
| `node` | Vite development server and frontend tooling | Separate service, localhost Vite port | Build stage only; no running Node service |

Request path: browser → Nginx → PHP-FPM/Laravel → PostgreSQL. Static files stop at Nginx. Livewire updates follow the same application path.

The code container is `app`: containers run processes, so there is no separate idle container just to hold source files. In development, both PHP and Nginx see the needed source paths. In production, code and public assets are baked into matching release images.

Production assumes the server's existing edge proxy terminates HTTPS and forwards to Nginx through a restricted port or shared proxy network. Document trusted proxy settings, HTTPS URL generation, and secure cookies. Confirm the actual host, domain, registry, and edge proxy arrangement before live deployment.

Only Nginx and the development Vite server publish host ports by default. PostgreSQL and PHP-FPM remain internal. A PostgreSQL health check gates dependent startup; it does not replace application error handling or runtime monitoring.

## Product requirements

### Accounts

- Manually implement registration, login, and logout using Laravel's authentication, hashing, validation, and session services.
- Validate unique email addresses and confirmed passwords; throttle login attempts.
- Regenerate sessions on login and invalidate them on logout.
- Guests cannot access tasks. Every task read and mutation is restricted to the authenticated owner, including direct Livewire requests.

### Tasks

- Create a task with a required trimmed title of 1–255 characters and optional notes of up to 5,000 characters.
- Edit title and notes; mark complete or reopen; delete with confirmation.
- Show all, active, and completed filters, newest first with a deterministic ID tie-breaker.
- Paginate at 20 tasks per page; reset or adjust pagination after filtering and deleting.
- Provide empty states, field errors, loading/disabled states, and action feedback.
- Use a responsive Tailwind interface with labeled fields, keyboard-operable controls, visible focus, and readable contrast.

### Data model

Use Laravel's standard users table plus `tasks`: `id`, `user_id` foreign key, `title`, nullable `notes`, nullable `completed_at`, and timestamps. Completion derives from `completed_at`; do not duplicate it in a boolean column. Add an index supporting owner/filter queries and define cascading task deletion when a user is deleted. Validate queries against PostgreSQL.

## Implementation phases

### Phase 0 — Specification and learning outline

- [x] Define architecture, scope, phased deliverables, and acceptance criteria.
- [x] Create README.md for operating instructions and GUIDE.md for explanations.

Acceptance: the planned container roles, app behavior, and development/deployment differences are explicit.

### Phase 1 — Prepare Docker and repository foundations

- [x] Check Docker Engine, Compose plugin, daemon access, host architecture, and available ports.
- [x] Select compatible versions and record them with their rationale.
- [x] Add PHP Dockerfile with required Laravel extensions and PostgreSQL PDO support, plus Composer tooling.
- [x] Add development Compose services, Nginx configuration, PostgreSQL health check and volume, and Node tooling.
- [x] Set a safe local environment template, `.gitignore`, and `.dockerignore`; handle Linux UID/GID and writable paths without blanket world-writable permissions.
- [x] Explain build context, networks, ports, mounts, volumes, and service names in GUIDE.md.

Acceptance: Compose configuration validates; images build; PostgreSQL becomes healthy; container DNS works; PHP has required extensions; generated files have usable host ownership. Keep PHP/Nginx application readiness checks for Phase 2, when source exists.

### Phase 2 — Bootstrap Laravel manually

- [x] Create a plain Laravel app using containerized Composer. Use a temporary empty directory, then copy the skeleton without overwriting this project's documentation.
- [x] Configure `.env`, generate a local application key, select PostgreSQL, and run initial migrations explicitly.
- [x] Wire Nginx's document root to `public/` and FastCGI to PHP-FPM, with matching script paths in both containers.
- [x] Choose and document database-backed sessions and cache; ensure their tables exist.
- [x] Record exact clean-clone bootstrap commands in README.md.

Acceptance: Laravel responds through Nginx; database migrations work; private files such as `.env` are inaccessible over HTTP; data survives container recreation; no host PHP, Composer, or Node installation is required.

Implementation notes: the original Phase 1 Nginx mapping required no changes. Standard Laravel migrations supply session/cache tables. Host-oriented setup/dev scripts and Pail/Pao helpers were omitted. The scaffold includes frontend source, but npm and Livewire installation remain Phase 3 work. Initial smoke tests did not query a database; the reserved testing connection was provisioned as the isolated `db_test` service in Phase 4.

### Phase 3 — Integrate Livewire and Tailwind

- [x] Install Livewire explicitly and create the base Blade layout and a small interactive component.
- [x] Configure Tailwind with Vite, required CSS sources, and asset entry points.
- [x] Configure Vite container binding and browser-facing hot reload addresses.
- [x] Verify frontend development mode and compiled production assets independently.

Acceptance: a Livewire action updates the page; Tailwind styles render; hot reload works from the host browser; a production build works with the Node service stopped and without a stale Vite hot file.

### Phase 4 — Accounts and authorization foundation

- [x] Build registration, login, logout, guest/auth routing, and the production registration setting manually.
- [x] Add validation, throttling, session handling, and authentication tests.
- [x] Establish the task ownership policy and authenticated query conventions for Phase 5.

Acceptance: valid accounts can sign in/out; invalid inputs fail clearly; protected routes reject guests; registration can be disabled; session behavior and throttling have passing tests.

Phase 4 notes: isolated PostgreSQL testing was brought forward because authentication needs database records. The optional `db_test` service has separate credentials and disposable storage; the test bootstrap guards its connection. Ownership model scaffolding and `TaskPolicy` exist, but task schema, fields, and queries remain Phase 5 work.

### Phase 5 — Task persistence and rules

- [x] Add task migration, model, user relationship, factory, and local-only demo seeding.
- [x] Implement validation, owner-scoped queries, policy enforcement, and completion behavior.
- [x] Test database constraints, validation boundaries, and cross-user access on a separate PostgreSQL test database.

Acceptance: tasks persist, completion can be reversed, and one user cannot read or mutate another user's tasks by changing IDs.

Phase 5 implementation: `TaskService` is ready for Livewire integration. It derives the actor from authentication, scopes every query through that user, authorizes records, and permits only validated title/notes input. Database constraints and owner/order/filter indexes are in place. There are no new task routes or screens yet.

### Phase 6 — Todo interface

- [x] Implement Livewire create, edit, complete/reopen, delete, filtering, and pagination.
- [x] Add responsive layout, action feedback, empty states, confirmation, and accessibility details.
- [x] Add Livewire tests for behaviors and unauthorized direct action calls.

Acceptance: all product requirements work in a browser at mobile and desktop sizes; refresh preserves changes; validation and boundary pagination cases work; component tests pass.

### Phase 7 — Quality and reproducibility

- [x] Run automated tests against isolated PostgreSQL, Laravel formatting checks, and the frontend production build.
- [x] Add CI for those checks, then image build validation.
- [x] Follow README.md from a clean checkout using fresh disposable volumes.
- [x] Check logs, health behavior, asset loading, authentication, and persistence during restart.

Acceptance: documented setup works without undocumented host dependencies; all checks pass; automated tests cannot target the development or production database.

### Phase 8 — Production images and deployment workflow

- [x] Add multi-stage production builds and an explicit production Compose configuration with no development bind mounts, Vite service, or exposed database port.
- [x] Build matching PHP and Nginx images tagged with the same commit SHA; configure a registry publishing workflow.
- [x] Inject production environment settings at runtime, keep a stable secret `APP_KEY`, disable debug, and use secure cookies over HTTPS.
- [x] Configure writable runtime storage, appropriate process permissions, log output/rotation, health checks, and restart policies.
- [x] Add a deployment script/runbook: back up, pull the release, run a one-off migration, prepare Laravel caches with runtime configuration, replace services, and verify health.
- [x] Define previous-image rollback and migration compatibility rules. Do not automatically roll back database migrations.
- [x] Rehearse production mode locally, including compiled assets and restart persistence.

Phase 8 implementation: standalone production Compose; matching PHP/Nginx targets; manual GHCR publisher and production CI rehearsal; runtime cache/secret handling; owner-controlled account creation; database backup and guarded deployment/rollback scripts. The real HTTPS edge, registry visibility/pull access, and server setup remain Phase 9 tasks.

Acceptance: a release runs from built images without source checkout or build tools on the server; application and web images match; deployment failure has a documented recovery path. Brief maintenance is acceptable; zero downtime is not a first-release requirement.

### Phase 9 — Server rollout and operational handoff

- [ ] Record the real server, domain, image registry, edge proxy, secret delivery method, and backup destination.
- [ ] Configure HTTPS routing and deploy the tested release to the intended host.
- [ ] Smoke-test login and task operations through the real domain.
- [ ] Schedule database backups outside the database volume; rehearse restoring to an isolated database.
- [ ] Verify previous-release recovery, log inspection, and routine update instructions.
- [ ] Finish README.md and GUIDE.md with verified commands and troubleshooting.

Acceptance: the application runs on the target infrastructure with HTTPS, private database access, tested backups, and a repeatable deployment procedure. If infrastructure access is unavailable, mark this phase blocked and report local production readiness separately from actual deployment.

## Expected repository layout

Laravel source will live at the repository root. Planned supporting files:

```text
docker/php/Dockerfile
docker/nginx/Dockerfile
docker/nginx/default.conf
compose.yaml
compose.production.yaml
.env.example
.env.production.example
.dockerignore
scripts/
.github/workflows/
PROJECT_SPEC.md
README.md
GUIDE.md
```

Compose files must have clearly documented invocation rules so development settings cannot leak into production. Exact filenames may evolve with implementation; keep this spec current.

## Verification record

| Phase | State | Evidence |
| --- | --- | --- |
| 0 | Complete | Specification, README, and initial guide created |
| 1 | Complete (2026-09-13) | Docker 29.7.2 / Compose 5.5.0 on x86_64; configuration valid; PHP image built; PostgreSQL healthy; required extensions loaded; authenticated PDO query through `db` succeeded; PHP and Node files owned by host UID/GID 1000; Nginx config valid; `/healthz` 200, `/` 404 pending Laravel, `/.env` 403 |
| 2 | Complete (2026-09-13) | Laravel 13.31.0; 3 PostgreSQL migrations applied and rerun idempotently; `/` and `/up` returned 200; private files returned 403/404; cache value, session record, and migration history survived full container recreation; Composer validation/platform checks, 2 scaffold tests, and Pint passed |
| 3 | Complete (2026-09-13) | Livewire 4.4.4, Tailwind 4.3.3, Vite 8.3.0; 4 tests / 10 assertions and Pint passed; npm ci/build passed; browser increment/reset worked; CSS hot replacement preserved state and Blade changes auto-refreshed; compiled styling/actions worked with Node stopped and no hot file |
| 4 | Complete (2026-09-13) | 19 tests / 178 assertions; registration validation and toggle; login/password hashing; session and CSRF rotation; lockout expiry/reset and IP throttles; owner-only policy; HTTP CSRF and stale Livewire rejection after logout; forms inspected and frontend build passed |
| 5 | Complete (2026-09-14) | 43 tests / 252 assertions; PostgreSQL task migration and constraints; owner-scoped service/policies; validation boundaries; completion/reopening/deletion; filtering/pagination; factories and guarded demo seeding; Pint passed; development migration applied and rerun idempotently |
| 6 | Complete (2026-09-14) | Livewire task UI; 48 tests / 299 assertions; ownership and pagination boundary tests; Pint and Vite build passed; desktop/mobile browser lifecycle and reload persistence checked |
| 7 | Complete (2026-09-14, local verification) | GitHub Actions workflow added; clean application archive of `58fdafc` plus new verification scripts, empty dependencies and fresh volumes; PHP image build, Composer validation/platform checks, npm ci/build, 48 tests / 299 assertions, Pint (42 files), HTTP authentication/assets, intentional cached-config rejection, and user/task/session/cache persistence after container recreation passed; disposable resources removed. Hosted CI awaits commit/push. |
| 8 | Complete (verification 2026-09-14; documentation finalized 2026-09-16) | Matching production PHP/Nginx images built locally; 51 tests / 315 assertions and Pint (48 files) passed; production Compose isolation and image exclusions checked; HTTPS login and Livewire task creation, assets, runtime caches, redeployment/session persistence, dump restoration, database-outage readiness, invalid-key deployment failure, full-stack recreation, and same-image rollback path passed. Disposable containers and volumes removed. GHCR workflow configured but not dispatched. |
| 9 | Not started | Real infrastructure, HTTPS edge, scheduled off-machine backups, restore drills, and operational handoff remain pending |

Phase 8 verification left `app`, `db`, and `web` running with compiled assets and the tasks table migrated. Node and the disposable test database are stopped. Guests see login; the home page serves the persistent task interface after authentication. Local signup is enabled; demo seeding was tested only in isolation and has not added an account to the development database. Start `node` for hot reload as documented in README.md. `/healthz` checks Nginx only; `/up` checks Laravel boot in development and also queries PostgreSQL in production. The clean-checkout rehearsal passed without host PHP, Composer, or Node. Production images and deployment scripts passed the local rehearsal. The updated Quality workflow and manual GHCR publisher still require hosted runs after commit/push. The Proxmox hosting plan is a local-only document excluded from Git and Docker build contexts; Phase 9 has not been executed.

## Official references

- [Laravel installation](https://laravel.com/docs/13.x/installation)
- [Livewire installation](https://livewire.laravel.com/docs/4.x/installation)
- [Tailwind with Vite](https://tailwindcss.com/docs/installation/using-vite)
- [Docker Compose startup readiness](https://docs.docker.com/compose/how-tos/startup-order/)
