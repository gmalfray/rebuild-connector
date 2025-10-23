#!/usr/bin/env bash

set -euo pipefail

usage() {
  cat <<USAGE
Usage: ${0##*/} --base-url https://preprod.example.com --api-key YOUR_API_KEY [--lang fr]

Environment variables:
  REBUILD_BASE_URL   Base URL of the shop (https://example.com)
  REBUILD_API_KEY    API key configured for the module

Arguments take precedence over environment variables.
USAGE
}

BASE_URL="${REBUILD_BASE_URL:-}"
API_KEY="${REBUILD_API_KEY:-}"
LOCALE="en"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url)
      BASE_URL="$2"
      shift 2
      ;;
    --api-key)
      API_KEY="$2"
      shift 2
      ;;
    --lang|--locale)
      LOCALE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$BASE_URL" || -z "$API_KEY" ]]; then
  echo "Base URL and API key are required." >&2
  usage
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required to parse JSON responses." >&2
  exit 1
fi

BASE_URL="${BASE_URL%/}"
API_ROOT="$BASE_URL/module/rebuildconnector/api"
AUTH_ENDPOINT="$API_ROOT/connector/login"

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

TMP_TOKEN=$(mktemp)
trap 'rm -f "$TMP_TOKEN"' EXIT

log "Requesting access token..."
AUTH_RESPONSE=$(curl -sS -w "\n%{http_code}" -X POST "$AUTH_ENDPOINT" \
  -H 'Content-Type: application/json' \
  -d "{\"api_key\":\"$API_KEY\"}") || {
    echo "Failed to contact auth endpoint" >&2
    exit 1
  }

AUTH_BODY=${AUTH_RESPONSE%$'\n'*}
AUTH_STATUS=${AUTH_RESPONSE##*$'\n'}

if [[ "$AUTH_STATUS" != "200" ]]; then
  echo "Authentication failed (status $AUTH_STATUS): $AUTH_BODY" >&2
  exit 1
fi

ACCESS_TOKEN=$(echo "$AUTH_BODY" | jq -r '.access_token // empty')
if [[ -z "$ACCESS_TOKEN" ]]; then
  echo "Unable to parse access token from response: $AUTH_BODY" >&2
  exit 1
fi

log "Token acquired. Running checks..."

PASS_COUNT=0
FAIL_COUNT=0

call_endpoint() {
  local description="$1"
  local path="$2"
  local expected=${3:-200}

  local response http body
  response=$(curl -sS -w "\n%{http_code}" -H "Authorization: Bearer $ACCESS_TOKEN" -H "Accept: application/json" "$API_ROOT$path") || {
    echo "✖ $description -> request failed" >&2
    ((FAIL_COUNT+=1))
    return
  }

  body=${response%$'\n'*}
  http=${response##*$'\n'}

  if [[ "$http" == "$expected" ]]; then
    echo "✔ $description (HTTP $http)"
    ((PASS_COUNT+=1))
  else
    echo "✖ $description (HTTP $http, expected $expected)" >&2
    echo "$body" >&2
    ((FAIL_COUNT+=1))
  fi
}

call_endpoint "Orders list" "/orders?limit=1"
call_endpoint "Products list" "/products?limit=1"
call_endpoint "Dashboard metrics" "/dashboard/metrics?period=month&locale=$LOCALE"
call_endpoint "Baskets list" "/baskets?limit=1&has_order=0"
call_endpoint "Reports – best sellers" "/reports/bestsellers?limit=5"
call_endpoint "Reports – best customers" "/reports/bestcustomers?limit=5"
call_endpoint "Customers top (alias)" "/customers/top?limit=5"

if (( FAIL_COUNT == 0 )); then
  log "All checks passed ($PASS_COUNT successful)."
  exit 0
else
  log "$FAIL_COUNT check(s) failed, $PASS_COUNT succeeded."
  exit 1
fi
