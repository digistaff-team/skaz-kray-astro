#!/usr/bin/env bash
#
# Анонс свежих статей в «ОБЩИЙ чат СК» от @SkazKray_bot.
# Вызывается из /usr/local/bin/skaz-kray-autodeploy.sh после успешной выкладки.
#
# Дедуп: scripts/announce-post.mjs пишет context='post-announce:<slug>' в
# таблицу telegram_messages и перед отправкой проверяет журнал. Поэтому
# повторные запуски (каждые 10 минут, если деплой ничего не изменил, скрипт
# вообще не зовётся) ничего не постят повторно.
#
# Окно --since 7: анонсируются статьи с датой за последние 7 дней, у которых
# анонса ещё не было. Статьи старше окна не трогаются задним числом.
#
# Запуск вручную:
#   /usr/local/bin/skaz-kray-announce-pending.sh            # боевой
#   /usr/local/bin/skaz-kray-announce-pending.sh --dry-run  # только план

set -uo pipefail

export HOME=/root
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/sbin:/usr/bin:/bin

BUILD_DIR="/opt/skaz-kray-astro-build"
LOG="/var/log/skaz-kray-announce.log"
LOCK="/var/lock/skaz-kray-announce.lock"

log() { echo "[$(date '+%F %T')] $*"; }

exec 9>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

exec >>"$LOG" 2>&1

if [ ! -f "$BUILD_DIR/scripts/announce-post.mjs" ]; then
  log "skip: нет $BUILD_DIR/scripts/announce-post.mjs"
  exit 0
fi
if [ ! -d "$BUILD_DIR/node_modules/gray-matter" ]; then
  log "skip: в $BUILD_DIR нет node_modules/gray-matter"
  exit 0
fi

log "=== announce start ==="
cd "$BUILD_DIR" || { log "skip: нет $BUILD_DIR"; exit 0; }

# --local: серверная PHP-часть выполняется здесь же, без ssh.
# Неудача анонса не должна ломать деплой: логируем и выходим с 0.
if node scripts/announce-post.mjs --pending --since 7 --local "$@"; then
  log "announce ok"
else
  log "announce FAILED (rc=$?)"
fi
log "=== announce end ==="
exit 0
