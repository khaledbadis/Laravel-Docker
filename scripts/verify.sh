#!/usr/bin/env bash
# Run only in a fresh, disposable checkout. All application tools run in Docker.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ -e .env || -d vendor || -d node_modules ]]; then
    echo 'Use a fresh disposable checkout: .env, vendor, and node_modules must not exist.' >&2
    exit 1
fi
# An explicit project and Compose file isolate this run from developer resources.
verification_project="phase7-$(date +%s)-$$"
verification_port="${VERIFY_WEB_PORT:-18080}"
unset COMPOSE_FILE COMPOSE_PROFILES COMPOSE_PROJECT_NAME
cp .env.example .env
sed -i \
    -e "s/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$verification_project/" \
    -e "s/^LOCAL_UID=.*/LOCAL_UID=$(id -u)/" \
    -e "s/^LOCAL_GID=.*/LOCAL_GID=$(id -g)/" \
    -e "s/^WEB_PORT=.*/WEB_PORT=$verification_port/" \
    -e "s|^APP_URL=.*|APP_URL=http://localhost:$verification_port|" .env
compose() { docker compose --env-file .env -f compose.yaml -p "$verification_project" "$@"; }
cleanup() {
    result=$?
    trap - EXIT
    compose logs --no-color --tail=100 app db web || true
    compose --profile testing --profile frontend down --volumes --remove-orphans || result=1
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
compose config --quiet
compose build app
compose --profile frontend pull db web node
compose up -d --wait
compose exec -T app composer install --no-interaction --prefer-dist
compose exec -T app php artisan key:generate --no-interaction
compose exec -T app php artisan migrate --no-interaction
compose run --rm --no-deps node npm ci
compose run --rm --no-deps node npm run build
compose exec -T app composer validate --strict
compose exec -T app composer check-platform-reqs
compose exec -T web nginx -t
compose up -d --wait db_test
compose exec -T app composer test -- --no-ansi
compose exec -T app vendor/bin/pint --test
# Verify that failed smoke checks propagate a nonzero exit status to CI.
if compose exec -T app php scripts/smoke.php invalid > storage/logs/smoke-guard.log 2>&1; then
    echo 'Smoke checks unexpectedly returned success for invalid input.' >&2
    exit 1
fi
grep -q 'Expected before or after' storage/logs/smoke-guard.log
compose exec -T app php scripts/smoke.php before
# Deliberately cache the disposable development connection: bootstrap must reject it
# before RefreshDatabase can run migrations against it.
compose exec -T app php artisan config:cache
if compose exec -T app vendor/bin/phpunit --filter ExampleTest > storage/logs/test-guard.log 2>&1; then
    echo 'Database isolation guard unexpectedly allowed cached local configuration.' >&2
    exit 1
fi
if ! grep -q 'Tests must use the isolated db_test service' storage/logs/test-guard.log; then
    cat storage/logs/test-guard.log
    exit 1
fi
compose exec -T app php artisan config:clear
# Recreate containers and their network while retaining this run's named volume.
compose --profile testing down
compose up -d --wait
compose exec -T app php artisan migrate --no-interaction
compose exec -T app php scripts/smoke.php after
compose ps
printf 'Verification passed: tests, formatting, assets, image build, isolation guard, and restart persistence.\n'
