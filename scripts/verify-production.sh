#!/usr/bin/env bash
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
: "${IMAGE_REPOSITORY:?Build the production images first}"
: "${RELEASE_SHA:?Set their full commit SHA}"
export IMAGE_REPOSITORY RELEASE_SHA
export COMPOSE_PROJECT_NAME="phase8-$(date +%s)-$$"
rehearsal_dir=$(mktemp -d)
mkdir "$rehearsal_dir/release" "$rehearsal_dir/certs"
cp "$source_root/compose.production.yaml" "$rehearsal_dir/release/"
cd "$rehearsal_dir/release"
rehearsal_key=$(docker run --rm --entrypoint php "$IMAGE_REPOSITORY-app:$RELEASE_SHA" -r 'echo "base64:".base64_encode(random_bytes(32));')
umask 077
cat > .env.production <<ENV
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
IMAGE_REPOSITORY=$IMAGE_REPOSITORY
APP_URL=https://edge
APP_KEY=$rehearsal_key
DB_DATABASE=phase8_test
DB_USERNAME=phase8_test
DB_PASSWORD=disposable-production-rehearsal
WEB_PORT=${VERIFY_PRODUCTION_PORT:-18081}
AUTH_REGISTRATION_ENABLED=false
TRUSTED_PROXIES=
ENV
compose() { docker compose --env-file .env.production -f compose.production.yaml "$@"; }
cleanup() {
    result=$?
    trap - EXIT
    compose logs --no-color --tail=60 app web db || true
    docker compose --env-file .env.production -f compose.production.yaml -f edge.yaml down --volumes --remove-orphans || result=1
    echo "Rehearsal files and private backup retained at $rehearsal_dir"
    exit "$result"
}
trap cleanup EXIT
# The certificate is trusted explicitly by the smoke client; no insecure TLS bypass.
if ! docker run --rm --user "$(id -u):$(id -g)" --entrypoint openssl \
    -v "$rehearsal_dir/certs:/certs" "$IMAGE_REPOSITORY-app:$RELEASE_SHA" \
    req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=edge -addext subjectAltName=DNS:edge \
    -keyout /certs/key.pem -out /certs/cert.pem > "$rehearsal_dir/tls.log" 2>&1; then
    cat "$rehearsal_dir/tls.log" >&2
    exit 1
fi
cat > "$rehearsal_dir/edge.conf" <<'NGINX'
server {
    listen 443 ssl;
    ssl_certificate /certs/cert.pem;
    ssl_certificate_key /certs/key.pem;
    location / {
        proxy_pass http://web;
        proxy_set_header Host edge;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $remote_addr;
    }
}
NGINX
cat > edge.yaml <<ENV
services:
  edge:
    image: $IMAGE_REPOSITORY-web:$RELEASE_SHA
    volumes:
      - $rehearsal_dir/certs:/certs:ro
      - $rehearsal_dir/edge.conf:/etc/nginx/conf.d/default.conf:ro
    networks: [backend]
ENV
bash "$source_root/scripts/deploy.sh" .env.production "$RELEASE_SHA" --local
docker compose --env-file .env.production -f compose.production.yaml -f edge.yaml up -d edge
copy_checks() {
    compose exec -T app sh -c 'cat > /tmp/production-smoke.php' < "$source_root/scripts/production-smoke.php"
    compose exec -T app sh -c 'cat > /tmp/rehearsal.crt' < "$rehearsal_dir/certs/cert.pem"
}
copy_checks
compose exec -T app php /tmp/production-smoke.php before
compose exec -T app sh -c 'test ! -e /var/www/html/.env && test ! -e /var/www/html/HOSTING.md && ! command -v composer && ! command -v node && ! command -v git'
# Re-deployment takes a data-bearing backup and recreates app runtime caches.
bash "$source_root/scripts/deploy.sh" .env.production "$RELEASE_SHA" --local
# Nginx resolves upstreams on startup; refresh this test edge after app ingress changes.
docker compose --env-file .env.production -f compose.production.yaml -f edge.yaml restart edge
copy_checks
compose exec -T app php /tmp/production-smoke.php after
backup=$(ls -t backups/*.dump | head -1)
compose exec -T db sh -c 'createdb -U "$POSTGRES_USER" restored_check'
compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d restored_check --exit-on-error' < "$backup"
restored=$(compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d restored_check -Atc "select count(*) from tasks"')
[[ "$restored" == 1 ]] || { echo 'Backup restore did not recover the task.' >&2; exit 1; }
# A database outage must fail HTTP readiness.
compose stop db
if compose exec -T web wget -qO- http://127.0.0.1/up > /dev/null 2>&1; then
    echo 'Readiness incorrectly passed with PostgreSQL stopped.' >&2
    exit 1
fi
compose up -d --wait --wait-timeout 120 db
# A bad runtime key must stop deployment without recording success or reopening ingress.
cp .env.production .env.production.good
sed -i 's/^APP_KEY=.*/APP_KEY=invalid/' .env.production
if bash "$source_root/scripts/deploy.sh" .env.production "$RELEASE_SHA" --local > failed-deploy.log 2>&1; then
    echo 'Invalid runtime settings unexpectedly deployed.' >&2
    exit 1
fi
grep -q 'Production requires' failed-deploy.log
if compose ps --status running --services | grep -qx web; then
    echo 'Failed deployment left ingress running.' >&2
    exit 1
fi
[[ "$(cat .release)" == "$RELEASE_SHA" ]]
mv .env.production.good .env.production
# Recreate the complete stack while retaining its volumes before rollback.
docker compose --env-file .env.production -f compose.production.yaml -f edge.yaml down
# The same-image rollback exercises the rollback path without inventing schema compatibility.
bash "$source_root/scripts/deploy.sh" .env.production "$RELEASE_SHA" --local --rollback
docker compose --env-file .env.production -f compose.production.yaml -f edge.yaml up -d edge
copy_checks
compose exec -T app php /tmp/production-smoke.php after
echo 'Production rehearsal passed, including backup restore and rollback command path.'
