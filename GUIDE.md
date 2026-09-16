# Laravel and Docker: project guide

This guide explains the project design. Docker, Laravel, Livewire, Tailwind, authentication, task screens, and production release tooling are integrated; rollout on the real infrastructure is the next stage. Runnable commands belong in [README.md](README.md); progress and acceptance checks belong in [PROJECT_SPEC.md](PROJECT_SPEC.md).

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

Composer installs PHP dependencies. We created Laravel's plain skeleton inside the PHP container; Livewire was then installed separately through Composer. This preserves Laravel's standard structure while making setup steps visible. See [Laravel installation](https://laravel.com/docs/13.x/installation) and [Livewire installation](https://livewire.laravel.com/docs/4.x/installation).

The bootstrap used `create-project` in an empty temporary directory because the repository already contained Docker files and documentation. `--no-install` downloaded only the skeleton; `--no-scripts` prevented its automatic key generation and SQLite migration setup. We copied the application files, then ran the setup steps ourselves. See [Composer's command reference](https://getcomposer.org/doc/03-cli.md#create-project).

| Step | What it changes |
| --- | --- |
| `composer install` | Creates `vendor/` from the locked PHP dependencies and discovers Laravel packages |
| `php artisan key:generate` | Writes an encryption key into the local `.env` |
| `php artisan migrate` | Applies pending schema migrations to PostgreSQL |

The first dependency resolution created `composer.lock`; commit this file so collaborators install the same versions. `vendor/` is generated and ignored by Git. The default host setup/dev shortcuts and their terminal helpers were removed because PHP and Node run in separate containers here.

Artisan is Laravel's command-line tool for tasks such as generating classes and running migrations. It runs inside `app`, using the same PHP runtime as the application.

## Laravel's main directories

| Path | Purpose |
| --- | --- |
| `public/index.php` | Public PHP entry point executed by PHP-FPM |
| `bootstrap/app.php` | Registers routing, middleware, exception handling, and `/up` |
| `routes/web.php` | Browser routes; `/` renders the authenticated Livewire task list |
| `app/` | Application classes, including models and later Livewire components |
| `config/` | Settings resolved from `.env` and documented defaults |
| `resources/` | Blade templates and frontend source |
| `database/migrations/` | Versioned database schema changes |
| `storage/` | Runtime files, compiled Blade views, and local application storage |
| `bootstrap/cache/` | Generated package discovery and configuration caches |
| `tests/` | Automated application checks |

Nginx and PHP mount the project at the same `/var/www/html` path. Nginx passes `/var/www/html/public/index.php` to PHP-FPM; that exact path must exist in the PHP container too. PHP-FPM speaks FastCGI, so a browser connects to Nginx rather than directly to port 9000.

## Configuration and secrets

Laravel's `.env` supplies environment-specific values. `.env.example` documents required settings with safe placeholders. Compose can also use an environment file to substitute values into its configuration; that substitution does not automatically inject every value into a container.

Here Compose maps `DB_*` settings to PostgreSQL's `POSTGRES_*` variables. Laravel reads the bind-mounted `.env` itself. The doubled `$$` in the database health check leaves variable expansion to the container shell instead of Compose.

`APP_KEY` protects Laravel's encrypted values and must remain stable in production. Generate a development key during setup; provision the production key once and preserve it across deployments.

Our example uses `SESSION_SECURE_COOKIE=false` for local HTTP. Production HTTPS must use secure cookies. Mail is sent to logs during development, and queued work runs synchronously; neither needs another container at this stage.

Laravel's configuration cache captures resolved settings. Prepare it with the actual runtime environment, not build-time secrets. Read environment values through configuration files rather than calling `env()` throughout application code.

## Database structure and ownership

A **migration** versions database structure. An **Eloquent model** represents application records and relationships. A **factory** generates test data; a **seeder** inserts intentional starting or demo data.

Each task belongs to a user. Queries must restrict results to that user, and policies must authorize mutations. PostgreSQL foreign keys maintain relationship integrity; application authorization controls who may access records.

Tests use a separate PostgreSQL database because test helpers can erase tables. Demo seeds are for local development, not production deployment.

Authentication tests use the separate `db_test` PostgreSQL container. It has different credentials and disposable memory-backed storage. `phpunit.xml` forces this connection, and `Tests/TestCase.php` checks it before database setup. `RefreshDatabase` migrates the test schema and isolates records between tests. Test sessions/cache use arrays; an additional HTTP smoke check covered real cookies, CSRF, and database sessions.

## Database sessions, cache, and migrations

HTTP is stateless. Laravel uses a session cookie to associate requests with a row in `sessions`; the browser does not receive the entire session record. Database storage lets those sessions survive PHP container recreation while the database volume remains intact.

`CACHE_STORE=database` stores cached values in `cache` and lock records in `cache_locks`. This keeps the initial stack small. The scaffold's migrations already create these tables, so no extra session/cache migration is needed. Cache values remain disposable even though the underlying volume persists.

Laravel records completed migrations in `migrations`. `migrate` applies new ones; `migrate:fresh` drops tables and is unsuitable for preserving existing data. The standard jobs tables also exist, but the synchronous queue setting means we do not run a worker.

Container recreation replaces processes and container filesystems. PostgreSQL data survives in its named volume, while source and `.env` remain in the host bind mount. Restarting therefore does not require regenerating the key or reinstalling dependencies.

## Tailwind and frontend builds

Vite serves frontend assets with hot reload during development. Its server must listen on an interface reachable outside its container, while its browser-facing URL must be reachable from the host browser.

The `frontend` Compose profile keeps Node optional. Explicitly targeting `node` starts Vite. `npm ci` installs the exact dependency versions in `package-lock.json`; `npm run build` writes compiled files and a manifest to `public/build`.

Production uses compiled, versioned assets, so there is no running Vite or Node service. Tailwind is integrated through Vite; see the [official installation guide](https://tailwindcss.com/docs/installation/using-vite).

## Livewire components and the shared layout

`TaskList.php` is a full-page component: public properties hold form state, its methods handle actions, and `render()` selects the Blade view. The `Layout` attribute wraps that view in `layouts/app.blade.php`. `wire:click` calls PHP through a Livewire request; the returned HTML updates the component without a full page reload.

`wire:loading.attr="disabled"` disables buttons while a request is in flight. Form state is temporary; the service saves task records in PostgreSQL so they survive a refresh.

The layout includes `@vite`, `@livewireStyles`, and `@livewireScripts` explicitly so their roles are visible. Livewire supplies Alpine.js already; importing another Alpine instance can cause conflicts. No Livewire configuration file is needed for these conventions. See [Livewire installation](https://livewire.laravel.com/docs/4.x/installation).

## How asset mode is selected

When Vite runs, Laravel's Vite plugin writes its browser-facing URL to `public/hot`. `@vite` uses that file to load development assets and connect hot reload. Without it, Laravel reads `public/build/manifest.json` and generates links to the compiled files Nginx serves.

The Node service runs Vite directly with an init process, allowing stop signals to reach Vite and its cleanup handler. A normal stop deletes `public/hot`; a forced kill may leave it behind. This is why a stale hot file can break asset loading even after a successful build.

Vite binds `0.0.0.0:5173` inside Docker, while browsers use the hostname in `APP_URL` and port in `VITE_PORT`. The WebSocket uses that same published port. These are different addresses for different sides of the container boundary.

Tailwind's Vite plugin generates CSS from class names in our Blade, JavaScript, Livewire PHP, and Laravel pagination sources. Write complete class names so they can be detected. `@theme` sets the font stack; system fonts avoid an external font service. CSS hot updates preserve component state; Blade auto-refresh reloads the page. Saved tasks remain in PostgreSQL.

The tests exercise PHP/Livewire behavior without Vite. Browser checks separately verify scripts, styling, hot reload, and compiled assets; PHP tests alone cannot prove those work.

## Authentication and ownership

`AuthController` handles standard form submissions. Form requests validate input and normalize email addresses; the User model's `hashed` cast hashes new passwords. Laravel's session guard checks credentials with `Auth::attempt` and remembers the authenticated user in the session. We use these Laravel services directly, without a starter kit or custom password hashing.

`guest` middleware keeps signed-in users out of login/signup pages. `auth` middleware protects the home route. Livewire re-applies that route's persistent authentication middleware during subsequent updates, including requests sent after logout.

Each form includes `@csrf`. Login and registration regenerate the session ID to prevent session fixation. Logout calls `Auth::logout`, invalidates session data, and generates a fresh CSRF token. Password fields are never restored from flashed input. See [Laravel authentication](https://laravel.com/docs/13.x/authentication).

Login failures use a cache-backed limiter keyed by normalized email and IP. A second limiter caps total submissions from an IP, including attempts that change email addresses. Registration has its own IP limit. The database cache shares these counters across PHP requests.

The registration middleware reads `config('auth.registration_enabled')` for each request. Turning the setting off blocks both displaying and submitting the form; it does not disable existing accounts. Runtime code reads configuration rather than calling `env()` directly, so it remains compatible with configuration caching.

Authentication answers who is signed in; a policy decides whether that user can access a particular record. Laravel discovers `TaskPolicy` by convention. Its view/update/delete rules compare the owner ID. `TaskService` obtains the signed-in user, queries through that user’s `tasks()` relationship, and then authorizes the record. A caller cannot choose a different owner by submitting a `user_id`.

## Task data and rules

`TaskService` is the shared entry point for the Livewire task screen. It validates title/notes, loads records through the authenticated owner, and invokes policies. Keeping these operations together avoids repeating ownership and validation rules in each UI action. It does not accept a caller-supplied user identity.

`Task` allows mass assignment only for title and notes. The relationship assigns `user_id`, and completion is written explicitly by the service. `completed_at = null` means active; a timestamp means completed. A separate status boolean would duplicate that state and could become inconsistent.

Validation gives users useful field errors. PostgreSQL constraints also reject invalid direct inserts: missing owners, blank titles, oversized titles/notes, and nonexistent user references. The foreign key deletes a user's tasks when that user is deleted. Separate indexes support owner/date ordering and owner/completion filtering.

Factories generate test records and can create completed tasks with `Task::factory()->completed()`. `DemoSeeder` is an explicit local-only convenience; it skips an existing demo email instead of overwriting data. The default seeder is empty so routine setup cannot accidentally create a known account.

The persistence tests reload records from PostgreSQL to verify saves, test cross-user IDs, and attempt invalid SQL inserts to exercise constraints. Timestamp comparisons use whole seconds, matching Eloquent's default stored precision. Livewire tests also cover the UI actions and pagination boundaries.

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

Start with `docker compose ps` and `docker compose logs --tail=100 app db web`. Check Nginx configuration with `docker compose exec web nginx -t`. `/healthz` checks Nginx; `/up` checks Laravel boot through PHP-FPM, but does not check database connectivity. A missing session-table error on `/` usually means the initial migrations have not run.

## How the task interface works

`TaskList` holds temporary form fields and the selected filter. `wire:model` sends inputs with the next action; `wire:submit` calls `save` without a full page reload. The component delegates validation, persistence, and authorization to `TaskService`, then Livewire updates the Blade markup. Blade escapes task text before rendering it.

`#[Locked]` prevents clients from replacing selected edit/delete IDs or the filter directly. It does not replace authorization: every action still loads a task through the authenticated owner. Editing and deleting recheck ownership when saving or confirming. Delete confirmation is a UI safeguard, not an authorization boundary.

`WithPagination` keeps the page in the URL. Filters reset it to page 1; rendering clamps an out-of-range page after a task disappears. Each row has a stable `wire:key` based on its task ID so Livewire tracks the correct row during updates.

The form displays server validation next to labeled inputs. Status messages use a polite live region, filters expose their pressed state, and action buttons disable while requests run. Editing focuses the title; deletion confirmation initially focuses Keep task. Tailwind switches the form/list columns to a vertical layout on smaller screens.

## Continuous integration and repeatable setup

CI runs checks automatically on a fresh machine when code is pushed or a pull request changes. Our workflow calls `scripts/verify.sh`, so the same commands can run locally in a disposable checkout. GitHub supplies Git and Docker; PHP, Composer, Node, and PostgreSQL still run inside containers. The workflow uses a pinned checkout action and read-only permissions. See [GitHub workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax).

Lockfiles fix application dependency versions, while image digests fix base images. Installing from scratch catches missing files and undocumented setup steps. Docker may reuse build layers; this check verifies a clean application checkout and fresh database, not a byte-identical rebuild of operating-system packages.

A Compose project name separates a stack's network and named volumes. The verifier always chooses a new `phase7-*` name. It first recreates containers without deleting the volume to test persistence, then removes that disposable volume at the end. Using `down --volumes` on your normal project would erase its database. See [Compose project names](https://docs.docker.com/compose/how-tos/project-name/) and [volume removal](https://docs.docker.com/reference/cli/docker/compose/down/).

PHPUnit's forced database settings select `db_test`; the bootstrap guard also checks the resolved Laravel configuration before `RefreshDatabase` can migrate anything. This second check matters because a cached configuration can bypass environment changes. CI intentionally tests that rejection against its disposable local database.

Health checks have different scopes: `/healthz` checks Nginx, `/up` checks Laravel boot, and authenticated HTTP requests exercise sessions and PostgreSQL. Fetching compiled CSS/JavaScript proves those files are served; it does not prove browser layout or JavaScript interactions, which were checked separately in Phase 6.

## Phase 8: turning a checkout into a production release

Development mounts your working directory into PHP and Nginx. Editing a file changes what the containers see immediately. Production instead runs a **built image**: a packaged filesystem containing a specific version of the application and its dependencies. Deploying means replacing containers with containers made from a selected image, while keeping persistent data separately.

There are two production images per release:

| Image | Contents and responsibility |
| --- | --- |
| `<repository>-app:<commit SHA>` | PHP-FPM, required PHP extensions, Laravel/Livewire code, production Composer dependencies and compiled assets |
| `<repository>-web:<commit SHA>` | Nginx configuration and the same release's public files and compiled assets |

Nginx handles HTTP and serves CSS/JavaScript directly. PHP-FPM executes Laravel's front controller. The public `index.php` exists in both images at the same path; Nginx sends that path to PHP-FPM rather than executing PHP itself. Both images must come from the **same commit**: mixing an old PHP component with new JavaScript or assets can break requests.

The full Git commit SHA identifies the release. `build-production.sh` supplies it as the image tag and OCI revision label. `deploy.sh` checks both labels before modifying the running service. Treat published SHA tags as immutable: do not deliberately overwrite them with different code. Registry digests provide an even stronger content identity; record the push digests for important releases. The current release workflow builds Linux amd64 on an Ubuntu runner; add and verify other architectures before deploying to them.

### How the multi-stage Dockerfile works

`docker/production/Dockerfile` has several named stages. Docker can copy files from an earlier stage without including that stage's tools in the final image:

1. **extensions** installs development headers and compiles PostgreSQL, internationalization, ZIP and OPcache extensions.
2. **runtime** starts from the pinned PHP base again, installs only the runtime libraries needed by those extensions, and copies the compiled extensions. Production PHP settings disable displayed errors and enable OPcache.
3. **dependencies** temporarily adds Composer, installs the exact locked dependencies with `--no-dev`, then copies the application and builds its optimized autoloader. It does not copy a developer's `vendor` directory.
4. **assets** uses the pinned Node image, runs `npm ci`, and compiles Tailwind/Vite assets. It sees the production Laravel vendor files so Tailwind can discover pagination styles.
5. **app** copies the application and built assets into the PHP runtime. Composer, Git and Node are not installed here.
6. **web** copies only the public files and Nginx configuration into the Nginx base image.

Development dependencies such as PHPUnit and Faker are absent from the PHP release. Build tools run on the build machine or CI runner; the deployment VM only pulls images. `.dockerignore` excludes local secrets, dependencies, generated caches, test scripts, documentation and the private hosting plan from the build context. This matters even for files that Git already ignores: Git exclusion and Docker exclusion are separate mechanisms.

Copying dependency manifests before source files lets Docker reuse expensive install layers when only application code changes. Image digests and lockfiles pin the important inputs, but this is not a promise of byte-identical OS package rebuilds forever: apt repositories and tool behavior can still change. Validate every release build.

### Why production Compose is a separate file

Always select `compose.production.yaml` explicitly, on its own. Compose merges files when multiple `-f` options are provided, which could accidentally carry development source mounts, Vite or exposed ports into a release. The production file has no source bind mounts, build directives or Node service.

Only Nginx publishes a host port, bound to `127.0.0.1`. PostgreSQL and PHP-FPM are reachable through the private application network, not through published host ports. Service names such as `db` and `app` are Docker DNS names; `localhost` inside PHP means the PHP container itself, not PostgreSQL or the VM.

The VM's HTTPS edge proxy forwards to the localhost Nginx port. This keeps domain routing and certificates separate from an individual app's release. When another app is added, give it a different project name, port and environment file. Do not reuse this application's database credentials or encryption key.

### Runtime configuration and the application key

`.env.production` is an operator-managed file outside the images. Compose reads it for interpolation and injects the selected values as container environment variables. PHP does not need a mounted `.env` file. The production Compose file fixes `APP_ENV=production`, `APP_DEBUG=false` and secure cookies; the entrypoint also rejects an invalid key or a non-HTTPS application URL.

Generate `APP_KEY` **once** for a new installation and retain it for all subsequent releases. It protects Laravel-encrypted values, including session cookies. Generating a new key during every deployment would invalidate sessions and can make other encrypted data unreadable. Back up the key securely together with the database credentials. Generating a key is different from rotating one; a planned rotation requires a separate procedure.

Restrict `.env.production` to its owner. Environment variables are available to administrators with Docker access and may appear in `docker inspect` or expanded Compose configuration. Do not paste expanded configuration into public issues or CI logs. This first release uses a protected environment file; an external secret manager can replace it later without changing the principle of runtime injection.

Changing an environment file does not edit a running container's environment. Recreate the app through the deployment script, even when using the same image SHA. Database initialization variables only create credentials for a new volume; editing `DB_PASSWORD` later does not change PostgreSQL's existing account password.

### Caches, permissions and persistent files

The PHP entrypoint prepares Laravel's configuration, route and Blade view caches **inside each container at startup**. It runs before PHP-FPM and before one-off Artisan commands. Caching at image build time would risk baking in the wrong environment, URLs or secrets. A migration container's cache is not reused by the web-serving container: each creates its own from the same runtime configuration.

The app process runs as `www-data` (UID/GID 33), with a read-only root filesystem and no added Linux capabilities. Writable locations are explicit:

| Location | Storage type | Lifetime |
| --- | --- | --- |
| `bootstrap/cache` | Per-container tmpfs owned by UID 33 | Rebuilt after container replacement |
| `storage/framework` | Per-container tmpfs | Compiled views and temporary framework files |
| `/tmp` | Per-container tmpfs | Temporary files only |
| `storage/app` | Named `app_data` volume | Persists across container replacement |
| PostgreSQL data directory | Named `postgres_data` volume | Accounts, tasks, sessions, cache and schema persist |

Sessions and application cache use PostgreSQL, so losing the container's temporary filesystem does not log users out. The todo app does not currently offer uploads. If uploads are added, review storage routing, file permissions and backups; Nginx does not currently mount `app_data` to publish uploaded files.

OPcache stores compiled PHP in memory and does not check source timestamps in production. That is appropriate because source files never change in a running release container. Replacing the container replaces the source and OPcache together. Editing code inside a production container is neither durable nor part of the deployment workflow.

Laravel logs to stderr. Docker rotates each service's JSON logs at 10 MB, keeping three files. This bounds local log usage but is not a centralized audit/monitoring service. `restart: unless-stopped` restarts exited containers after crashes and daemon restarts; an unhealthy status alone does not make Docker restart a still-running process.

### HTTPS and forwarded headers

The edge terminates HTTPS and sends HTTP to the app's localhost Nginx port. Laravel forces generated production URLs to HTTPS, while secure cookies tell browsers to send the session cookie only over HTTPS.

`TRUSTED_PROXIES` is a comma-separated list of exact proxy addresses or CIDRs. The custom middleware reads it from Laravel configuration after boot, so it also works with cached configuration. Only trusted senders may supply the forwarded client address and protocol. Forwarded host headers are not trusted. The edge should preserve the intended Host and overwrite untrusted forwarded headers.

Correct client IP handling matters because login throttling is keyed partly by IP. If every visitor appears to be the edge, unrelated users can share a throttle bucket; if every sender is trusted, clients may spoof addresses. Determine the actual edge source address at deployment and verify it from the real public path. Avoid a wildcard trust setting.

### Readiness versus a running container

A process being alive does not prove the application works. Production checks several layers:

- `/healthz` checks only Nginx.
- `/up` boots Laravel and, in production, queries PostgreSQL through a `DiagnosingHealth` listener.
- The PHP container health check verifies PostgreSQL connectivity and the local PHP-FPM listening socket.
- The deployment script waits for healthy containers and fetches `/login`, exercising a real page and database-backed sessions.
- The production rehearsal logs in over HTTPS, runs a Livewire action, fetches compiled assets and checks persistence.

Internal readiness does not verify public DNS, the router, certificate renewal or the edge configuration. A deployment still needs an external HTTPS check and a real authenticated action. A database outage should make production readiness fail; showing an application error page is not a successful health check.

### Publishing a release

The ordinary **Quality** workflow validates both development setup and production images. **Publish production images** is a separate manually dispatched workflow: choose the intended ref, run the checks and production rehearsal, then push the matching app and web images to GHCR. It needs package-write permission only for publishing; it does not log in to or deploy to your server. Pull requests never run this publisher automatically.

GHCR package visibility is independent of the Git repository. A private package requires a suitable read-only credential on the deployment VM; a public package can be pulled anonymously. The workflow uses its own short-lived GitHub token to publish. See [GitHub's publishing guide](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images) and [container registry access](https://docs.github.com/en/packages/working-with-a-github-packages-registry).

If one image push succeeds and the other fails, the release is incomplete. Do not deploy until both names exist with the expected labels and the workflow passes. The deploy script pulls and checks both before stopping ingress. Do not use a moving `latest` tag to select a release.

### What deploy.sh does, in order

Run it from a dedicated release directory with its production Compose and protected environment file. It requires Bash, GNU utilities, `flock` and Docker Compose on the Linux host. The script accepts a full SHA, uses a lock to prevent overlapping deployments in that directory, and follows this sequence:

1. Validate Compose, pull the selected images, and check app/web revision labels.
2. Start PostgreSQL and wait for it to be healthy.
3. Stop Nginx ingress. This creates a short maintenance window and prevents task writes while taking the backup and migrating. The edge may return 502/503 during this window; a branded maintenance page can be configured there later.
4. Save a custom-format database dump under `backups/`, verify its archive listing, and rename the partial file only after success. On a first installation this is a backup of the newly initialized empty database.
5. Run `php artisan migrate --force` using a one-off container from the **new app image**. This runs pending migrations only. It does not run `migrate:fresh`, seed demo data or generate a new application key.
6. Recreate PHP-FPM and start Nginx, waiting for readiness. Their entrypoints prepare caches using runtime settings.
7. Check the health and login endpoints, then update `.release` and retain the previous different SHA in `.previous-release`.

`app:create-user` is an interactive Artisan command for the first account and later invitations by an operator. It uses the registration validation rules, normalizes the email, and stores a hashed password. The password prompts are hidden, so you do not need to put credentials in a shell command or temporarily open public signup.

The current app has no queue workers or scheduler. If those are added, they must also be paused during this maintenance window and replaced with the matching release. Stopping Nginx alone would not stop background database writes.

The database dump is stored on the deployment host, **outside the database volume but still on the same machine**. It protects against some release mistakes, not loss of the VM or disk. Phase 9 must schedule encrypted off-machine copies and restore drills. Protect the application volume and runtime secrets too when they contain important data.

### Recovering a failed deployment

Before ingress is stopped, a pull or revision-check failure leaves the existing service alone. After that point, a failure leaves ingress stopped and prints a recovery message. The script does not guess whether a partially applied migration can safely be reversed.

Inspect the error and container logs using the failed release SHA and the same environment file. Determine whether migrations ran, whether the database is available, and whether the images/configuration are correct. Then choose one path:

- Fix the configuration or release and rerun the normal deployment command. Migrations skip those already completed.
- If the previous code is compatible with the current schema, deploy its matching image pair using `--rollback`. This takes another backup and replaces containers but intentionally skips migrations.
- If the previous code cannot use the current schema, prefer a forward fix. Restoring a database backup is a deliberate recovery operation, not an automatic step; it may discard writes since that backup.

Example compatible image rollback, from the deployment directory:

```bash
previous_sha=$(cat .previous-release)
bash scripts/deploy.sh .env.production "$previous_sha" --rollback
```

After a failed release, `.release` still contains the last successful SHA; use that instead if appropriate. These files record local deployment history, not a guarantee of schema compatibility. Consult the release's migration changes before rolling back.

Design migrations to allow rollback where practical: add a nullable column, deploy code that can handle old and new values, backfill, then remove old fields only in a later planned release. Dropping a column and immediately deploying code that depends on its absence can make old images unusable. Never assume `migrate:rollback` is safe for production data.

### What the local production rehearsal proves

`verify-production.sh` uses a unique `phase8-*` project with new volumes and a separate temporary deployment directory. It creates a short-lived TLS certificate and explicitly trusts that certificate in its HTTP client; it does not disable certificate verification. Only the rehearsal mounts the test edge's certificate/configuration. The real production Compose file has no such bind mounts.

The check deploys built images, verifies production settings and runtime caches, signs in with secure cookies, creates a task through Livewire over HTTPS, redeploys, restores a data-bearing dump into an isolated database, then recreates the whole stack while retaining volumes. It checks that stopping PostgreSQL fails readiness and that an invalid-key deployment leaves ingress stopped with the last successful release recorded. It then exercises recovery through the rollback command with the same image pair. This last check verifies the command path; compatibility with a future, different release must be reviewed when that release exists.

Temporary containers and volumes are removed afterward; the test's private environment and dump remain in its printed temporary directory for diagnosis. No real production credentials or development accounts are used. The first hosted CI/publishing runs and the real server deployment still need to occur after these changes are committed and pushed.
