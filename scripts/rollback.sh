#!/usr/bin/env bash

set -Eeuo pipefail

APP_BASE="${APP_BASE:-/var/www/xml-mapper}"
RELEASES_DIR="${APP_BASE}/releases"
CURRENT_LINK="${APP_BASE}/current"
PHP_BIN="${PHP_BIN:-php}"
HEALTH_PATH="${FEED_MEDIATOR_DEPLOY_HEALTH_URL:-/health}"
SMOKE_FEED_PROFILE_ID="${FEED_MEDIATOR_DEPLOY_SMOKE_FEED_PROFILE_ID:-}"

CURRENT_TARGET="$(readlink -f "${CURRENT_LINK}")"
TARGET_RELEASE="${1:-}"

if [[ -z "${TARGET_RELEASE}" ]]; then
  TARGET_RELEASE="$(ls -1dt "${RELEASES_DIR}"/* | grep -Fvx "${CURRENT_TARGET}" | head -n 1)"
fi

if [[ ! -d "${TARGET_RELEASE}" ]]; then
  echo "Rollback target not found: ${TARGET_RELEASE}" >&2
  exit 1
fi

ln -sfn "${TARGET_RELEASE}" "${CURRENT_LINK}"

pushd "${CURRENT_LINK}" >/dev/null
"${PHP_BIN}" artisan queue:restart
"${PHP_BIN}" artisan ops:preflight-production

if [[ -n "${APP_URL:-}" ]]; then
  curl --fail --silent --show-error "${APP_URL%/}${HEALTH_PATH}" >/dev/null
fi

if [[ -n "${SMOKE_FEED_PROFILE_ID}" ]]; then
  "${PHP_BIN}" artisan feed:smoke-check "${SMOKE_FEED_PROFILE_ID}" --latest-published --reason="Post-rollback smoke check"
fi

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  REVISION="$(git rev-parse HEAD)"
else
  REVISION="unknown"
fi

"${PHP_BIN}" artisan ops:record-deploy rollback "$(basename "${TARGET_RELEASE}")" --revision="${REVISION}" --note="Database rollback is manual and must be handled separately if a migration was destructive."
popd >/dev/null

echo "Rollback completed: ${TARGET_RELEASE}"
