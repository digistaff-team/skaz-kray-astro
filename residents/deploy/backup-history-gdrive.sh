#!/bin/bash
# История дампов БД на Google Drive — дополнение к backup_to_gdrive.sh.
#
# backup_to_gdrive.sh делает rclone sync /root/backups → gdrive:BackupsVPS/: это
# ЗЕРКАЛО (локально по одному набору), истории там нет — порча, замеченная не в
# первые сутки, перезаписалась бы и в облаке. Здесь каждый день кладём свежие
# дампы БД в папку с датой и храним HISTORY_DAYS дней.
#
# Только БД (≈33 МБ/сутки: оба WordPress, abconsult-app, раздел жителей + его
# секреты): полные архивы файлов сайтов (~1,7 ГБ/сутки) в квоту 15 ГБ не влезут.
#
# Установка (от root), cron после синхронизации в 04:00:
#   install -m 700 residents/deploy/backup-history-gdrive.sh /usr/local/bin/backup_history_gdrive.sh
#   30 4 * * * /usr/local/bin/backup_history_gdrive.sh >> /var/log/backup_history_gdrive.log 2>&1
#
# Восстановление: rclone copy gdrive:BackupsVPS-history/<ГГГГ-ММ-ДД> /root/restore/
set -euo pipefail

SRC=/root/backups
DEST=gdrive:BackupsVPS-history
HISTORY_DAYS=30
DAY=$(date +%F)

log() { echo "$(date '+%F %T') $*"; }

# Только сегодняшние дампы (--max-age 24h): вчерашние уже лежат во вчерашней папке.
rclone copy "$SRC" "$DEST/$DAY" --max-age 24h \
    --include '*_db_*.sql.gz' \
    --include 'app-db/*.sql.gz' \
    --include 'skaz-residents/*'
log "OK: $DEST/$DAY — $(rclone size "$DEST/$DAY" 2>/dev/null | tr '\n' ' ')"

# Ротация: старше HISTORY_DAYS — удалить мимо корзины (иначе корзина съест квоту).
rclone delete "$DEST" --min-age "${HISTORY_DAYS}d" --drive-use-trash=false
rclone rmdirs "$DEST" --leave-root
