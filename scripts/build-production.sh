#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
: "${IMAGE_REPOSITORY:?Set the lowercase image repository prefix}"
: "${RELEASE_SHA:?Set the full commit SHA}"
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || exit 1
for target in app web; do
    docker build -f docker/production/Dockerfile --target "$target" \
        --build-arg "RELEASE_SHA=$RELEASE_SHA" --build-arg "SOURCE_URL=${SOURCE_URL:-}" \
        -t "$IMAGE_REPOSITORY-$target:$RELEASE_SHA" .
done
