# Laravel and Docker: project guide

This guide explains the project design. Docker, Laravel, Livewire, Tailwind, and authentication are integrated; task screens and deployment are upcoming phases. Runnable commands belong in [README.md](README.md); progress and acceptance checks belong in [PROJECT_SPEC.md](PROJECT_SPEC.md).

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

`TaskService` is the shared entry point for the next phase's Livewire screens. It validates title/notes, loads records through the authenticated owner, and invokes policies. Keeping these operations together avoids repeating ownership and validation rules in each UI action. It does not accept a caller-supplied user identity.

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
