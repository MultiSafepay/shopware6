#!/usr/bin/env bash

# recover-expose.sh
#
# PURPOSE
#   Recover Shopware 6.7 public tunnel connectivity when Expose cannot reach
#   the internal "app" service due to Docker network or alias issues.
#
# WHEN TO USE IT
#   - The Expose public domain times out or does not respond.
#   - Expose is up, but "app" receives no traffic.
#   - After container restarts, network changes, or local runtime incidents.
#
# WHAT IT DOES (SUMMARY)
#   1) Ensures app and expose are running.
#   2) Detects the Docker network used by expose.
#   3) Repairs app network attachment and the required "app" alias.
#   4) Restarts expose to refresh internal DNS resolution.
#   5) Runs public and local checks to validate recovery.
#
# DATA SAFETY
#   - Does NOT run "docker compose down".
#   - Does NOT use "-v" (no volume deletion).
#   - Does NOT delete the database or persistent data.
#   - Only uses "up -d" and "restart" runtime operations.
#
# USAGE
#   ./bin/recover-expose.sh
#   ./bin/recover-expose.sh shopware67-dev-miguel.multisafepay.io
#   ./bin/recover-expose.sh --help

set -euo pipefail

usage() {
  cat <<'EOF'
Recover Expose connectivity for Shopware 6.7.

Usage:
  ./bin/recover-expose.sh [public-domain]

Examples:
  ./bin/recover-expose.sh
  ./bin/recover-expose.sh shopware67-dev-miguel.multisafepay.io

Domain resolution order:
  1) Optional argument [public-domain]
  2) APP_SUBDOMAIN + EXPOSE_HOST from .env
  3) SHOP_DOMAIN from running app container environment

Safety notes:
  - This script does not remove containers or volumes.
  - It does not run "down" and does not delete DB data.
EOF
}

normalize_domain() {
  local raw="${1:-}"
  raw="${raw#https://}"
  raw="${raw#http://}"
  raw="${raw%%/*}"
  raw="${raw%\"}"
  raw="${raw#\"}"
  printf '%s' "${raw}"
}

log() {
  echo "[INFO] $*"
}

warn() {
  echo "[WARN] $*" >&2
}

die() {
  echo "[ERROR] $*" >&2
  exit 1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ $# -gt 1 ]]; then
  die "Too many arguments. Use --help for usage."
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_DIR}"

# Select available compose command for compatibility.
if docker compose version >/dev/null 2>&1; then
  DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DC=(docker-compose)
else
  die "docker compose / docker-compose is not available."
fi

DOMAIN_OVERRIDE="${1:-}"

# Load optional .env settings used to build the public domain.
if [[ -f .env ]]; then
  # shellcheck disable=SC1091
  source .env
fi

if [[ -n "${DOMAIN_OVERRIDE}" ]]; then
  DOMAIN="$(normalize_domain "${DOMAIN_OVERRIDE}")"
elif [[ -n "${APP_SUBDOMAIN:-}" && -n "${EXPOSE_HOST:-}" ]]; then
  DOMAIN="${APP_SUBDOMAIN}.${EXPOSE_HOST}"
else
  DOMAIN=""
fi

log "============================================================"
log "Shopware 6.7 - Expose Recovery"
log "Project: ${PROJECT_DIR}"
if [[ -n "${DOMAIN}" ]]; then
  log "Target public domain: ${DOMAIN}"
else
  warn "Public domain not detected from args or .env."
  warn "The script will try to detect SHOP_DOMAIN from the running app container."
fi
log "Data safety: no down, no volume deletion, no DB wipe."
log "============================================================"

log "Starting app/expose services (safe for DB volumes)"
"${DC[@]}" up -d app expose

APP_CONTAINER="$("${DC[@]}" ps -q app)"
EXPOSE_CONTAINER="$("${DC[@]}" ps -q expose)"

if [[ -z "${APP_CONTAINER}" || -z "${EXPOSE_CONTAINER}" ]]; then
  die "Could not resolve app/expose container IDs."
fi

if [[ -z "${DOMAIN}" ]]; then
  APP_DOMAIN="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "${APP_CONTAINER}" 2>/dev/null | sed -n 's/^SHOP_DOMAIN=//p' | head -n1)"
  APP_DOMAIN="$(normalize_domain "${APP_DOMAIN}")"
  if [[ -n "${APP_DOMAIN}" ]]; then
    DOMAIN="${APP_DOMAIN}"
    log "Detected public domain from app container SHOP_DOMAIN: ${DOMAIN}"
  fi
fi

# Use Expose network as source of truth for routing.
NETWORK_NAME="$(docker inspect -f '{{range $name, $cfg := .NetworkSettings.Networks}}{{println $name}}{{end}}' "${EXPOSE_CONTAINER}" | head -n1 | tr -d '[:space:]')"

if [[ -z "${NETWORK_NAME}" ]]; then
  die "Expose container is not attached to a Docker network."
fi

log "Using Docker network: ${NETWORK_NAME}"

# Verify if app is connected to Expose network.
APP_CONNECTED="$(docker inspect -f "{{if index .NetworkSettings.Networks \"${NETWORK_NAME}\"}}yes{{else}}no{{end}}" "${APP_CONTAINER}" 2>/dev/null || true)"

if [[ "${APP_CONNECTED}" != "yes" ]]; then
  log "App container is not attached to ${NETWORK_NAME}. Connecting with alias app"
  docker network connect --alias app "${NETWORK_NAME}" "${APP_CONTAINER}"
else
  # App is connected; now verify the required DNS alias expected by Expose.
  ALIASES="$(docker inspect -f "{{with index .NetworkSettings.Networks \"${NETWORK_NAME}\"}}{{range .Aliases}}{{printf \"%s \" .}}{{end}}{{end}}" "${APP_CONTAINER}" 2>/dev/null || true)"
  if [[ " ${ALIASES} " != *" app "* ]]; then
    log "App is attached but missing alias app. Reconnecting to restore alias"
    docker network disconnect "${NETWORK_NAME}" "${APP_CONTAINER}" >/dev/null 2>&1 || true
    docker network connect --alias app "${NETWORK_NAME}" "${APP_CONTAINER}"
  else
    log "Alias app is already present on ${NETWORK_NAME}"
  fi
fi

# Restart Expose so it refreshes DNS resolution for upstream "app".
log "Restarting expose to refresh upstream resolution"
"${DC[@]}" restart expose

log "Current container status"
"${DC[@]}" ps app expose

log "Checking host port mapping for app"
docker port "${APP_CONTAINER}" 80 || true

if [[ -n "${DOMAIN}" ]]; then
  log "Public check: https://${DOMAIN}/"
  curl -k -sS --max-time 20 -o /dev/null -w 'public code=%{http_code} time=%{time_total}\n' "https://${DOMAIN}/" || true
else
  warn "Public check skipped: domain not detected."
  warn "Use one of these options:"
  warn "  - Pass domain explicitly: ./bin/recover-expose.sh your-domain.example.com"
  warn "  - Set APP_SUBDOMAIN and EXPOSE_HOST in .env"
fi

log "Local host check: http://localhost/"
curl -sS --max-time 10 -o /dev/null -w 'local code=%{http_code} time=%{time_total}\n' http://localhost/ || true

log "Done"
