#!/bin/bash
# Постановка/снятие/просмотр webhook @SkazKray_bot на residents-v2 (Vercel).
#
# Замена residents/bin/council-set-webhook.php (PHP больше не запускается).
# webhook у бота ОДИН: установка нашего отключает прежний.
#
# Токен и секрет берутся из residents-v2/.env. С URL-прокси идём через
# TELEGRAM_PROXY_URL из корневого .env (или его синтаксис http://login:pass@host:port),
# если задан — без него api.telegram.org с части машин недоступен.
#
# Запуск:  bash residents-v2/scripts/set-webhook.sh [--info] [--delete]
set -euo pipefail

cd "$(dirname "$0")/.."

TOKEN=$(grep -m1 '^SKAZKRAY_BOT_TOKEN=' .env | cut -d= -f2-)
SECRET=$(grep -m1 '^SKAZKRAY_WEBHOOK_SECRET=' .env | cut -d= -f2-)
BASE_URL=$(grep -m1 '^BASE_URL=' .env 2>/dev/null | cut -d= -f2- || true)
BASE_URL=${BASE_URL:-https://skaz-kray.ru}

# Прокси: TELEGRAM_PROXY_URL из корневого .env (residents-v2 лежит в корне репо).
ROOT_ENV="$(cd ../.. && pwd)/.env"
PROXY_ARGS=()
if [[ -f "$ROOT_ENV" ]]; then
  PROXY_URL=$(grep -m1 '^TELEGRAM_PROXY_URL=' "$ROOT_ENV" | cut -d= -f2- || true)
  PROXY_LOGIN=$(grep -m1 '^PROXY_LOGIN=' "$ROOT_ENV" | cut -d= -f2- || true)
  PROXY_PASS=$(grep -m1 '^PROXY_PASS=' "$ROOT_ENV" | cut -d= -f2- || true)
  # Если в URL уже есть логин/пароль — оставляем как есть, иначе достраиваем.
  if [[ -n "${PROXY_URL:-}" && "$PROXY_URL" != *"@"* && -n "${PROXY_LOGIN:-}" ]]; then
    SCHEME=${PROXY_URL%%://*}
    HOSTPART=${PROXY_URL#*://}
    PROXY_URL="$SCHEME://$PROXY_LOGIN:${PROXY_PASS:-}@$HOSTPART"
  fi
  if [[ -n "${PROXY_URL:-}" ]]; then
    PROXY_ARGS=(-x "$PROXY_URL")
  fi
fi

HOOK="$BASE_URL/api/council/webhook/"
api() {  # api <method> [кодированное тело]
  curl -s "${PROXY_ARGS[@]}" --max-time 30 \
    ${2:+-H 'Content-Type: application/json' -d "$2"} \
    "https://api.telegram.org/bot$TOKEN/$1"
}

case "${1:-}" in
  --info)
    echo "getWebhookInfo:"; api getWebhookInfo; echo
    ;;
  --delete)
    echo "deleteWebhook:"; api deleteWebhook; echo
    ;;
  "")
    if [[ -z "$SECRET" ]]; then
      echo "Не задан SKAZKRAY_WEBHOOK_SECRET в residents-v2/.env" >&2; exit 1
    fi
    BODY=$(printf '{"url":"%s","secret_token":"%s","allowed_updates":["callback_query"],"drop_pending_updates":true}' \
      "$HOOK" "$SECRET")
    echo "setWebhook ($HOOK):"; api setWebhook "$BODY"; echo
    echo "getWebhookInfo:"; api getWebhookInfo; echo
    ;;
  *)
    echo "Использование: $0 [--info|--delete]" >&2; exit 1
    ;;
esac