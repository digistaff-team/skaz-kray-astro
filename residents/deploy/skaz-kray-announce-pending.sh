#!/usr/bin/env bash
#
# Анонс свежих статей сайта skaz-kray.ru в «ОБЩИЙ чат СК» от @SkazKray_bot.
#
# Запускается cron'ом (см. crontab root). Каждый прогон:
#   1. обновляет shallow-клон репозитория в /opt/skaz-announce;
#   2. зовёт scripts/announce-post.mjs --pending --since 7 --local:
#      статья анонсируется, только если её нет в журнале telegram_messages
#      (context='post-announce:<slug>') — повторные прогоны ничего не постят.
#
# Сайт собирается и публикуется на Vercel по push в main; серверный
# skaz-kray-autodeploy.sh к сайту отношения не имеет и не используется
# (падает на astro build из-за Node 20 < 22.12). Этот скрипт от него
# независим: ему нужны только исходники статей + php8.3 + доступ к БД.
#
# Ручной запуск:
#   /usr/local/bin/skaz-kray-announce-pending.sh              # боевой
#   /usr/local/bin/skaz-kray-announce-pending.sh --dry-run    # только план

set -uo pipefail

export HOME=/root
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/sbin:/usr/bin:/bin

REPO="git@github-skaz-kray:digistaff-team/skaz-kray-astro.git"
DIR="/opt/skaz-announce"
LOG="/var/log/skaz-kray-announce.log"
LOCK="/var/lock/skaz-kray-announce.lock"

log() { echo "[$(date '+%F %T')] $*"; }

exec 9>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

exec >>"$LOG" 2>&1
log "=== announce start ==="

if [ ! -d "$DIR/.git" ]; then
  log "нет клона $DIR — клонирую"
  if ! git clone --depth 1 "$REPO" "$DIR"; then
    log "FAILED: не удалось клонировать $REPO"
    exit 0
  fi
fi

cd "$DIR" || { log "FAILED: нет $DIR"; exit 0; }

if ! git fetch --depth 1 origin main; then
  log "WARN: git fetch не удался — работаем на прошлой версии"
fi
git reset --hard origin/main >/dev/null 2>&1 || log "WARN: git reset не удался"

if [ ! -d "$DIR/node_modules/gray-matter" ]; then
  log "ставлю зависимость gray-matter"
  npm install --no-save --no-package-lock --no-audit --no-fund gray-matter >/dev/null 2>&1 \
    || log "WARN: npm install не удался"
fi

if [ ! -f "$DIR/scripts/announce-post.mjs" ]; then
  log "FAILED: нет scripts/announce-post.mjs"
  exit 0
fi

# --local: PHP-часть выполняется здесь же, без ssh.
# Неудача не должна валить cron-задачу — только лог.
if node scripts/announce-post.mjs --pending --since 7 --local "$@"; then
  log "announce ok"
else
  log "announce FAILED (rc=$?)"
fi

log "=== announce end ==="
exit 0
