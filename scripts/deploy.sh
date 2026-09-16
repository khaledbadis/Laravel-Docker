#!/usr/bin/env bash
# Run in a dedicated release directory containing compose.production.yaml.
set -Eeuo pipefail
umask 077
if [[ $# -lt 2 ]]; then
    echo 'Usage: bash scripts/deploy.sh ENV_FILE FULL_COMMIT_SHA [--rollback] [--local]' >&2
    exit 1
fi
deploy_env=$(realpath "$1")
export RELEASE_SHA="$2"
shift 2
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Use a full lowercase 40-character commit SHA.' >&2; exit 1; }
skip_pull=false
rollback=false
for option in "$@"; do
    case "$option" in
        --local) skip_pull=true ;;
        --rollback) rollback=true ;;
        *) echo "Unknown option: $option" >&2; exit 1 ;;
    esac
done
cd "$(dirname "$deploy_env")"
[[ -f compose.production.yaml ]] || { echo 'Place compose.production.yaml next to the environment file.' >&2; exit 1; }
exec 9>.deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
compose() { docker compose --env-file "$deploy_env" -f compose.production.yaml "$@"; }
compose config --quiet
if ! $skip_pull; then compose pull app web db; fi
for service in app web; do
    # Compose includes dependency images when filtering by service. Select the release suffix.
    image=$(compose config --images | grep -- "-$service:$RELEASE_SHA$")
    revision=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")
    [[ "$revision" == "$RELEASE_SHA" ]] || { echo "$service image revision does not match the requested release." >&2; exit 1; }
done
compose up -d --wait --wait-timeout 120 db
# From here a failure keeps ingress stopped for manual recovery.
recover() {
    result=$?
    if [[ $result -ne 0 ]]; then
        compose stop web || true
        echo 'Deployment failed; ingress is stopped. Inspect logs and recover using GUIDE.md. No database rollback was attempted.' >&2
    fi
    exit "$result"
}
trap recover EXIT
compose stop web
mkdir -p backups
backup="backups/$(date -u +%Y%m%dT%H%M%SZ)-before-$RELEASE_SHA.dump"
compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup.partial"
compose exec -T db pg_restore --list < "$backup.partial" > /dev/null
mv "$backup.partial" "$backup"
echo "Database backup saved: $backup"
if ! $rollback; then
    compose run --rm --no-deps app php artisan migrate --force --no-interaction
fi
# Recreate even for the same release so changed runtime secrets/config take effect.
compose up -d --no-deps --force-recreate app
compose up -d --wait --wait-timeout 180 web
compose exec -T web wget -qO- http://127.0.0.1/up > /dev/null
compose exec -T web wget -qO- http://127.0.0.1/login > /dev/null
if [[ -f .release && "$(cat .release)" != "$RELEASE_SHA" ]]; then cp .release .previous-release; fi
printf '%s\n' "$RELEASE_SHA" > .release.tmp
mv .release.tmp .release
echo "Release $RELEASE_SHA is healthy internally. Verify the public HTTPS URL and an authenticated task action."
