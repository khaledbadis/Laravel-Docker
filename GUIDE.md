# Laravel and Docker: project guide

This guide explains the project design. Docker foundations are implemented first; the Laravel and deployment sections describe upcoming phases. Runnable commands belong in [README.md](README.md); progress and acceptance checks belong in [PROJECT_SPEC.md](PROJECT_SPEC.md).

## What each tool does

| Tool | Role in this app |
| --- | --- |
| Laravel | Routing, validation, authentication, database access, and application structure |
| Livewire | Server-side PHP components that update the interface through browser requests |
| Blade | Laravel's HTML templates |
| Tailwind | Utility classes for styling HTML |
| Vite | Frontend development server and production asset builder |
| PostgreSQL | Durable application data |
| PHP-FPM | Executes PHP requests forwarded by Nginx |
| Nginx | Serves static files and sends application requests to PHP-FPM |
| Docker Compose | Defines and starts the application's cooperating containers |

## How a request reaches Laravel

The browser connects to Nginx. Nginx serves existing public assets directly and routes application requests to Laravel's `public/index.php` through PHP-FPM using FastCGI. Laravel runs middleware and the route handler, reads or writes PostgreSQL when needed, and returns a response.

Nginx exposes only Laravel's `public/` directory. The source tree, `.env`, and dependency files must not be served as public files.

Livewire initially renders HTML on the server. User actions send subsequent requests that run PHP component methods and update the browser. Authorization and validation must run on the server for every relevant action; hiding a button does not restrict access.

## Images, containers, and Compose

An **image** is a packaged filesystem and startup configuration. A **Dockerfile** describes how to build it. A **container** is a running instance of that image, with its own process and writable layer.

Compose connects services and configures their images, ports, environment variables, and storage. Rebuilding changes an image; recreating a container starts an instance with the updated configuration. Neither should erase a separately managed database volume.

The build context is the directory Docker can use as build input. `.dockerignore` excludes unnecessary or sensitive files, including real environment files, from that context.

Our PHP Dockerfile copies Composer from its official image into the PHP image, then adds PostgreSQL PDO, Intl, Zip, and OPcache extensions. Composer is a command-line tool inside `app`, so it does not need a permanent service. Most Laravel-required extensions already ship in the PHP base image; the runtime check verifies the complete list.

The image references include a `sha256` digest. Tags are readable names that may change; a digest fixes the chosen image contents. Update digests deliberately to receive fixes. Debian package installation still uses the current repository, so this is not a byte-for-byte reproducible build of all operating-system packages.

## Networking and ports

Containers on a Compose network find each other by service name. Laravel uses `db` as its database host; Nginx sends FastCGI requests to `app:9000`. Inside a container, `localhost` means that container itself.

A published port connects the host to a container port. Internal service communication does not need published ports. We expose local web port 8080 and, when enabled, Vite port 5173 on loopback and leave PostgreSQL and PHP-FPM unpublished. The `backend` bridge network still permits outbound access for package downloads; its name does not imply an air-gapped network.

Startup order alone does not mean PostgreSQL is ready to accept connections. Compose can wait for a successful database health check before starting a dependent service. Runtime failures still require separate handling. See [Docker's readiness documentation](https://docs.docker.com/compose/how-tos/startup-order/).

## Source mounts and persistent storage

A **bind mount** makes a host directory visible inside a container. Development uses this so code edits take effect immediately.

A **named volume** stores data separately from a container's writable layer. PostgreSQL uses one so recreating its container preserves the database. A volume is not a backup: accidental deletion or disk failure can still destroy it.

PostgreSQL 18 stores its data beneath `/var/lib/postgresql/18/docker`; our named volume mounts its parent `/var/lib/postgresql`, following the [official image's PostgreSQL 18 instructions](https://hub.docker.com/_/postgres). Initialization settings apply only to an empty data directory. Editing the password variable later does not change an existing database user's password.

Production images contain the source and compiled assets. They do not rely on mounting a checkout from the server. Both the PHP and Nginx images must come from the same release.

## Manual Laravel setup

Composer installs PHP dependencies. We will create Laravel's plain skeleton through Composer inside a container, then install Livewire explicitly and connect its layout and components ourselves. This preserves Laravel's standard structure while making setup steps visible. See [Laravel installation](https://laravel.com/docs/13.x/installation) and [Livewire installation](https://livewire.laravel.com/docs/4.x/installation).

Artisan is Laravel's command-line tool for tasks such as generating classes and running migrations. It runs inside `app`, using the same PHP runtime as the application.

## Configuration and secrets

Laravel's `.env` supplies environment-specific values. `.env.example` documents required settings with safe placeholders. Compose can also use an environment file to substitute values into its configuration; that substitution does not automatically inject every value into a container.

Here Compose maps `DB_*` settings to PostgreSQL's `POSTGRES_*` variables. Laravel will read the bind-mounted `.env` itself. The doubled `$$` in the database health check leaves variable expansion to the container shell instead of Compose.

`APP_KEY` protects Laravel's encrypted values and must remain stable in production. Generate a development key during setup; provision the production key once and preserve it across deployments.

Laravel's configuration cache captures resolved settings. Prepare it with the actual runtime environment, not build-time secrets. Read environment values through configuration files rather than calling `env()` throughout application code.

## Database structure and ownership

A **migration** versions database structure. An **Eloquent model** represents application records and relationships. A **factory** generates test data; a **seeder** inserts intentional starting or demo data.

Each task belongs to a user. Queries must restrict results to that user, and policies must authorize mutations. PostgreSQL foreign keys maintain relationship integrity; application authorization controls who may access records.

Tests use a separate PostgreSQL database because test helpers can erase tables. Demo seeds are for local development, not production deployment.

## Tailwind and frontend builds

Vite serves frontend assets with hot reload during development. Its server must listen on an interface reachable outside its container, while its browser-facing URL must be reachable from the host browser.

The `frontend` Compose profile keeps Node from starting before frontend files exist. We can still run individual Node/npm commands by explicitly targeting that service. Phase 3 will enable its long-running Vite process.

Production uses compiled, versioned assets, so there is no running Vite or Node service. Tailwind is integrated through Vite; see the [official installation guide](https://tailwindcss.com/docs/installation/using-vite).

## Permissions and logs

Laravel needs writable `storage/` and `bootstrap/cache/` directories. Development container users should produce files the host user can edit. Set ownership deliberately instead of applying `chmod 777` to the project.

The PHP image creates a `developer` user using `LOCAL_UID`/`LOCAL_GID`; Node runs with the same numeric IDs. Both write source files as the host user. Nginx's source mount is read-only. Build steps run as root to install packages; PHP runs as the developer user afterward.

Container logs should be accessible through Docker, with rotation configured. Database storage and any persistent runtime files have separate lifecycles from logs and release code.

## What deployment changes

Development favors editable source and debugging. Production uses immutable release images, compiled assets, runtime secrets, disabled debug output, HTTPS, and restart/health settings.

The server's edge proxy will terminate HTTPS and forward traffic to the application's Nginx service. Laravel must trust the intended proxy and recognize the original HTTPS scheme for redirects and secure cookies.

A deployment pulls a specific release, applies reviewed migrations once, prepares runtime caches, starts the matching services, and checks health. Database migrations should not run independently on every PHP container startup.

Rolling back an image does not roll back database changes. Favor schema changes compatible with the previous release and take backups before risky migrations. A backup is useful only when a restore has been tested.

## Troubleshooting map

| Symptom | First checks |
| --- | --- |
| Nginx returns 502 | PHP-FPM status, service hostname, port, and FastCGI configuration |
| Laravel cannot reach PostgreSQL | `DB_HOST=db`, credentials, database name, and database health |
| Permission denied | Ownership and writable Laravel directories |
| Styles or hot reload missing | Vite URL/port in development; asset manifest and stale hot file in production |
| Changed environment value has no effect | Container environment and cached Laravel configuration |
| Data disappeared | Compose project/volume selection and whether volumes were deleted |

Start with `docker compose ps` and `docker compose logs --tail=100 app db web`. Check Nginx configuration with `docker compose exec web nginx -t`. `/healthz` checks only Nginx; end-to-end Laravel readiness is added when the application exists.
