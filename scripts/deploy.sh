#!/usr/bin/env bash

set -Eeuo pipefail

APP_BASE="${APP_BASE:-/var/www/xml-mapper}"
RELEASES_DIR="${APP_BASE}/releases"
SHARED_DIR="${APP_BASE}/shared"
CURRENT_LINK="${APP_BASE}/current"
REPO_URL="${DEPLOY_REPO_URL:?DEPLOY_REPO_URL is required}"
BRANCH="${DEPLOY_BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
HEALTH_PATH="${FEED_MEDIATOR_DEPLOY_HEALTH_URL:-/health}"
SMOKE_FEED_PROFILE_ID="${FEED_MEDIATOR_DEPLOY_SMOKE_FEED_PROFILE_ID:-}"

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
TEMP_RELEASE_DIR="${RELEASES_DIR}/.${TIMESTAMP}.tmp"

mkdir -p "${RELEASES_DIR}" "${SHARED_DIR}/storage/app" "${SHARED_DIR}/storage/framework/cache" "${SHARED_DIR}/storage/framework/sessions" "${SHARED_DIR}/storage/framework/views" "${SHARED_DIR}/storage/logs"

git clone --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${TEMP_RELEASE_DIR}"
REVISION="$(git -C "${TEMP_RELEASE_DIR}" rev-parse HEAD)"
RELEASE_NAME="${TIMESTAMP}-${REVISION:0:7}"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_NAME}"
mv "${TEMP_RELEASE_DIR}" "${RELEASE_DIR}"

ln -sfn "${SHARED_DIR}/storage" "${RELEASE_DIR}/storage"
ln -sfn "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"
mkdir -p "${RELEASE_DIR}/bootstrap/cache"

"${COMPOSER_BIN}" install --working-dir="${RELEASE_DIR}" --no-dev --prefer-dist --optimize-autoloader --no-interaction

pushd "${RELEASE_DIR}" >/dev/null
"${PHP_BIN}" artisan migrate --force
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan route:cache
"${PHP_BIN}" artisan view:cache
popd >/dev/null

ln -sfn "${RELEASE_DIR}" "${CURRENT_LINK}"

pushd "${CURRENT_LINK}" >/dev/null
"${PHP_BIN}" artisan queue:restart
"${PHP_BIN}" artisan ops:preflight-production

if [[ -n "${APP_URL:-}" ]]; then
  curl --fail --silent --show-error "${APP_URL%/}${HEALTH_PATH}" >/dev/null
fi

if [[ -n "${SMOKE_FEED_PROFILE_ID}" ]]; then
  "${PHP_BIN}" artisan feed:smoke-check "${SMOKE_FEED_PROFILE_ID}" --latest-published --reason="Post-deploy smoke check"
fi

"${PHP_BIN}" artisan ops:record-deploy deploy "${RELEASE_NAME}" --revision="${REVISION}"
popd >/dev/null

echo "Deploy completed: ${RELEASE_NAME}"
