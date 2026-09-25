#!/bin/bash
# Ночной бэкап раздела жителей и серверной обвязки skaz-kray.ru.
#
# Что сохраняем (всё это есть ТОЛЬКО на сервере, в git этого нет):
#   residents_db_*.sql.gz     — БД skazkray_residents (mysqldump, согласованный снимок);
#   residents_files_*.tar.gz  — секреты и серверный код: config.php и .env раздела,
#                               загрузки, вход в CMS (oauth/, editor-auth/), tg-media
#                               (без кеша), скрипт автодеплоя сайта, nginx-конфиги
#                               skaz-kray, crontab root.
#
# Куда: /root/backups/skaz-residents/ — оттуда backup_to_gdrive.sh (04:00) зеркалит
# весь /root/backups на Google Drive. Там rclone sync, т.е. зеркало, а не архив, —
# поэтому глубину истории держим здесь: KEEP наборов (дампы маленькие, ~сотни КБ).
#
# Установка (от root):
#   install -m 700 residents/deploy/backup-residents.sh /usr/local/bin/backup_skaz-residents.sh
#   crontab: 25 2 * * * /usr/local/bin/backup_skaz-residents.sh >> /var/log/backup_skaz-residents.log 2>&1
#
# Восстановление БД:
#   gunzip -c residents_db_<дата>.sql.gz | mysql skazkray_residents
set -euo pipefail

BACKUP_DIR=/root/backups/skaz-residents
DB=skazkray_residents
APP=/var/www/skaz-residents
SITE=/var/www/new.skaz-kray.ru/html
KEEP=14
DATE=$(date +%Y%m%d_%H%M%S)

umask 077                      # в архивах токены ботов и пароли БД
mkdir -p "$BACKUP_DIR"

fail() { echo "$(date -Iseconds) ОШИБКА: $*" >&2; exit 1; }

# --- БД ---------------------------------------------------------------------
DB_OUT="$BACKUP_DIR/residents_db_$DATE.sql.gz"
if ! mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 "$DB" | gzip > "$DB_OUT"; then
    rm -f "$DB_OUT"; fail "mysqldump $DB не удался"
fi
gzip -t "$DB_OUT" 2>/dev/null || { rm -f "$DB_OUT"; fail "дамп повреждён"; }
gunzip -c "$DB_OUT" | grep -q '^-- Dump completed' || { rm -f "$DB_OUT"; fail "дамп оборван (нет «Dump completed»)"; }

# --- Файлы ------------------------------------------------------------------
FILES_OUT="$BACKUP_DIR/residents_files_$DATE.tar.gz"
CRON_TMP=$(mktemp)
trap 'rm -f "$CRON_TMP"' EXIT
crontab -l > "$CRON_TMP" 2>/dev/null || true

PATHS=()
for p in \
    "$APP/config/config.php" "$APP/config/.env" "$APP/public/uploads" \
    "$SITE/oauth" "$SITE/editor-auth" "$SITE/tg-media" "$SITE/tg-media-admin" \
    /usr/local/bin/skaz-kray-autodeploy.sh /usr/local/bin/backup_skaz-residents.sh \
    /etc/nginx/sites-available/skaz-kray_ru_astro /etc/nginx/sites-available/new.skaz-kray_ru; do
    [ -e "$p" ] && PATHS+=("$p")
done

tar -czf "$FILES_OUT" --exclude="$SITE/tg-media/cache" \
    --transform="s|^${CRON_TMP#/}|crontab-root.txt|" \
    "${PATHS[@]}" "$CRON_TMP" 2>/dev/null \
    || { rm -f "$FILES_OUT"; fail "tar не удался"; }

echo "$(date -Iseconds) OK: db $(du -h "$DB_OUT" | cut -f1), files $(du -h "$FILES_OUT" | cut -f1)"

# --- Ротация: KEEP новейших каждого типа ------------------------------------
for pat in 'residents_db_*.sql.gz' 'residents_files_*.tar.gz'; do
    ls -1t "$BACKUP_DIR"/$pat 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
done
