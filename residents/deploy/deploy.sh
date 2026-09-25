#!/usr/bin/env bash
set -euo pipefail

# Деплой раздела жителей на сервер. Код уезжает архивом через ssh: rsync не нужен
# (его нет ни в Git Bash, ни в WSL на рабочей машине). Секреты (config.php, .env),
# uploads, vendor и кеши на сервере НЕ трогаем.
#
#   deploy/deploy.sh             — выкатить
#   deploy/deploy.sh --dry-run   — только показать, что уедет и что удалится

SERVER="abconsult"                       # ssh-алиас = root@31.128.43.151
DEST="/var/www/skaz-residents"
SRC="$(cd "$(dirname "$0")/.." && pwd)"  # каталог residents/
DRY=""
[ "${1:-}" = "--dry-run" ] && DRY=1

# Что не уезжает и что не считается «лишним» при чистке. Один список на все шаги:
# упаковку, сверку с сервером и удаление. Сортировка обеих сторон — в LC_ALL=C,
# иначе разные локали дадут разный порядок и comm сочтёт совпадающие файлы лишними.
# В той же локали обязан работать и сам comm: порядок он проверяет по правилам
# своего окружения, и в чужой ругается «input is not in sorted order», а дальше
# сравнивает уже неверно — вплоть до того, что нужный файл попадёт в «лишние».
SKIP="-path ./vendor -o -path ./tests -o -path ./public/uploads -o -path ./.phpunit.cache"
SKIP="$SKIP -o -path ./.git -o -path ./config/config.php -o -path ./config/.env"
# Резервные копии рядом с конфигом (config.php.bak-…) — не наши файлы, но сносить их нельзя.
SKIP="$SKIP -o -name *.bak -o -name *.bak-*"
# Живые данные сервера: var/sessions — сессии жителей (src/bootstrap.php). Без этого
# исключения каждая выкатка сочла бы их «лишними» и разлогинила всё поселение.
SKIP="$SKIP -o -path ./var"

cd "$SRC"
LIST="$(eval "find . \\( $SKIP \\) -prune -o -type f -print" | LC_ALL=C sort)"
COUNT="$(printf '%s\n' "$LIST" | grep -c . || true)"
[ "$COUNT" -gt 0 ] || { echo "Список файлов пуст — выкладывать нечего."; exit 1; }

# Файлы, которые на сервере есть, а в проекте их уже нет (аналог rsync --delete).
REMOTE="$(ssh "$SERVER" "cd $DEST 2>/dev/null && find . \\( $SKIP \\) -prune -o -type f -print | LC_ALL=C sort" || true)"
STALE="$(LC_ALL=C comm -23 <(printf '%s\n' "$REMOTE") <(printf '%s\n' "$LIST") || true)"
STALE_COUNT="$(printf '%s\n' "$STALE" | grep -c . || true)"

# Страховка от массового удаления: если «лишних» больше десятой части проекта,
# это скорее сбой сверки, чем правда. Чистку пропускаем, пока не скажут FORCE_DELETE=1.
SUSPICIOUS=""
if [ "$STALE_COUNT" -gt $((COUNT / 10)) ] && [ "${FORCE_DELETE:-}" != "1" ]; then SUSPICIOUS=1; fi

# Миграции БД — ДО кода: новый код может рассчитывать на новые таблицы/колонки.
# Набор для наката (список, .sql и сам migrate.php) едет во временный каталог и
# запускается оттуда — живой каталог к этому моменту ещё старый.
# Ждущие миграции без MIGRATE=1 останавливают деплой.
MIG_TMP="$(ssh "$SERVER" mktemp -d)"
trap 'ssh "$SERVER" "rm -rf $MIG_TMP" >/dev/null 2>&1 || true' EXIT
tar -czf - --warning=no-timestamp config/migrations.txt config/*.sql bin/migrate.php src/Migrations.php src/timezone.php \
  | ssh "$SERVER" "tar -xzf - --no-same-owner -C $MIG_TMP"
MIG_RC=0
ssh "$SERVER" "cd $MIG_TMP && php8.3 bin/migrate.php status" || MIG_RC=$?
if [ "$MIG_RC" -eq 2 ]; then
  if [ -n "$DRY" ]; then
    echo "(dry-run: миграции не накатываю; при деплое понадобится MIGRATE=1)"
  elif [ "${MIGRATE:-}" = "1" ]; then
    echo "Накат миграций ..."
    ssh "$SERVER" "cd $MIG_TMP && php8.3 bin/migrate.php up"
  else
    echo "Есть ненакатанные миграции — код не выкладываю. Повторите с MIGRATE=1 bash residents/deploy/deploy.sh"
    exit 1
  fi
elif [ "$MIG_RC" -ne 0 ]; then
  echo "Проверка миграций упала (код $MIG_RC) — код не выкладываю."
  exit 1
fi

if [ -n "$DRY" ]; then
  echo "Уедет файлов: $COUNT"
  if [ "$STALE_COUNT" -eq 0 ]; then
    echo "Лишних файлов на сервере нет."
  elif [ -n "$SUSPICIOUS" ]; then
    echo "Лишних файлов подозрительно много ($STALE_COUNT из $COUNT) — чистка будет пропущена."
    echo "Если это правда, запустите с FORCE_DELETE=1. Список:"
    printf '%s\n' "$STALE"
  else
    echo "Будет удалено на сервере:"
    printf '%s\n' "$STALE"
  fi
  exit 0
fi

echo "Отправка кода ($COUNT файлов) в $SERVER:$DEST ..."
printf '%s\n' "$LIST" | tar -czf - --warning=no-timestamp -T - \
  | ssh "$SERVER" "mkdir -p $DEST && tar -xzf - --no-same-owner -C $DEST"

if [ "$STALE_COUNT" -gt 0 ] && [ -n "$SUSPICIOUS" ]; then
  echo "Лишних файлов подозрительно много ($STALE_COUNT из $COUNT) — чистку пропускаю."
  echo "Проверьте deploy/deploy.sh --dry-run; если удаление верное, повторите с FORCE_DELETE=1."
elif [ "$STALE_COUNT" -gt 0 ]; then
  echo "Удаление файлов, которых больше нет в проекте ..."
  printf '%s\n' "$STALE"
  printf '%s\n' "$STALE" | ssh "$SERVER" "cd $DEST && xargs -r -d '\n' rm -f"
fi

# Composer нужен только когда менялись зависимости; на сервере он лежит как
# /root/composer.phar (в PATH его нет), поэтому берём то, что найдётся.
echo "Composer install (--no-dev) на сервере ..."
ssh "$SERVER" "cd $DEST && { command -v composer >/dev/null && composer install --no-dev --optimize-autoloader; } || php8.3 /root/composer.phar install --no-dev --optimize-autoloader --no-interaction"

# Архив приезжает с Windows, где владельца нет — вернуть его всему дереву.
# Каталог сессий закрыт от чужих глаз: в сессии лежит, кто вошёл.
echo "Права ..."
ssh "$SERVER" "mkdir -p $DEST/public/uploads $DEST/var/sessions && chmod 700 $DEST/var/sessions && chown -R www-data:www-data $DEST"

echo "Готово. Проверьте https://skaz-kray.ru/poselenie/vhod"
