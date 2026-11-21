#!/bin/sh
# Runs the PHP test suite inside the app's own image, so it has the same PHP
# extensions (intl, gd, sqlite) as production. No local PHP is needed.
#
#   dev/test.sh              every case
#   dev/test.sh currency     cases matching "currency"
#
# CONTAINER_ENGINE picks docker or podman (default: whichever is installed).
# TEST_IMAGE overrides the image; it is built from this repo when missing.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
if [ -z "${CONTAINER_ENGINE:-}" ]; then
    if command -v podman >/dev/null 2>&1; then
        CONTAINER_ENGINE=podman
    else
        CONTAINER_ENGINE=docker
    fi
fi
IMAGE=${TEST_IMAGE:-diamon-local}

if ! "$CONTAINER_ENGINE" image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "Building $IMAGE from $ROOT..." >&2
    "$CONTAINER_ENGINE" build -t "$IMAGE" "$ROOT"
fi

exec "$CONTAINER_ENGINE" run --rm \
    --entrypoint php \
    -v "$ROOT":/var/www/html:Z \
    -w /var/www/html \
    "$IMAGE" \
    tests/run.php "$@"
