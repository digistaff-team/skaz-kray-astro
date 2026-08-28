#!/usr/bin/env bash
set -euo pipefail

# Деплой раздела жителей на сервер. Секреты (config.php) и uploads НЕ трогаем.
SERVER="abconsult"                       # ssh-алиас = root@31.128.43.151
DEST="/var/www/skaz-residents"
SRC="$(cd "$(dirname "$0")/.." && pwd)"  # каталог residents/

echo "Синхронизация кода в $SERVER:$DEST ..."
rsync -az --delete \
  --exclude 'config/config.php' \
  --exclude 'public/uploads/' \
  --exclude 'vendor/' \
  --exclude '.phpunit.cache/' \
  --exclude 'tests/' \
  "$SRC/" "$SERVER:$DEST/"

echo "Composer install (--no-dev) на сервере ..."
ssh "$SERVER" "cd $DEST && composer install --no-dev --optimize-autoloader"

echo "Права на uploads ..."
ssh "$SERVER" "mkdir -p $DEST/public/uploads && chown -R www-data:www-data $DEST/public/uploads $DEST/vendor"

echo "Готово. Проверьте https://skaz-kray.ru/poselenie/vhod"
