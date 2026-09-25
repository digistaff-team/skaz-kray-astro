#!/usr/bin/env bash
set -euo pipefail

# Деплой раздела жителей на сервер. Код уезжает архивом через ssh: rsync не нужен
# (его нет ни в Git Bash, ни в WSL на рабочей машине). Секреты (config.php, .env),
# uploads, vendor и кеши на сервере НЕ трогаем.
#
#   deploy/deploy.sh             — выкатить (сначала phpunit, снимок текущей версии)
#   deploy/deploy.sh --dry-run   — только показать, что уедет и что удалится
#   deploy/deploy.sh --rollback  — вернуть код из последнего снимка (БД не откатывается!)
#
#   MIGRATE=1     — накатить ждущие миграции БД (иначе деплой при них останавливается)
#   SKIP_TESTS=1  — не гонять phpunit перед выкладкой (только в крайнем случае)

SERVER="abconsult"                       # ssh-алиас = root@31.128.43.151
DEST="/var/www/skaz-residents"
RELEASES="/root/skaz-residents-releases" # снимки кода перед каждой выкладкой
KEEP_RELEASES=5
TEST_DIR="/root/skaz-residents-deploy-test"  # vendor там переживает прогоны — composer быстрый
SRC="$(cd "$(dirname "$0")/.." && pwd)"  # каталог residents/
DRY=""
ROLLBACK=""
[ "${1:-}" = "--dry-run" ] && DRY=1
[ "${1:-}" = "--rollback" ] && ROLLBACK=1

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

# --- Откат: код из последнего снимка ----------------------------------------
# Файлы, которых в снимке нет, удаляются (новые файлы откатываемой версии); снимок
# после восстановления убирается — следующий --rollback уйдёт ещё на шаг назад.
if [ -n "$ROLLBACK" ]; then
  SNAP="$(ssh "$SERVER" "ls -1t $RELEASES/*.tar.gz 2>/dev/null | head -1" || true)"
  [ -n "$SNAP" ] || { echo "Снимков в $SERVER:$RELEASES нет — откатываться не на что."; exit 1; }
  echo "Откат кода к снимку $SNAP ..."
  ssh "$SERVER" "set -e; cd $DEST
    find . \( $SKIP \) -prune -o -type f -print | LC_ALL=C sort > /tmp/rollback-now.txt
    tar -tzf '$SNAP' | grep -v '/\$' | sed 's|^\([^.]\)|./\1|' | LC_ALL=C sort > /tmp/rollback-snap.txt
    LC_ALL=C comm -23 /tmp/rollback-now.txt /tmp/rollback-snap.txt | xargs -r -d '\n' rm -f
    tar -xzf '$SNAP' --no-same-owner -C $DEST
    rm -f /tmp/rollback-now.txt /tmp/rollback-snap.txt '$SNAP'"
  ssh "$SERVER" "cd $DEST && php8.3 /root/composer.phar install --no-dev --optimize-autoloader --no-interaction -q && chown -R www-data:www-data $DEST"
  echo "Код откачен. Миграции БД НЕ откатываются — если откатываемая версия их добавляла, база осталась новой."
  exit 0
fi

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

# --- Тесты: phpunit на сервере, по рабочему дереву (локально PHP нет) ---------
# Красные тесты останавливают деплой до того, как что-либо изменится на проде.
if [ -z "$DRY" ] && [ "${SKIP_TESTS:-}" != "1" ]; then
  echo "phpunit на сервере ..."
  ssh "$SERVER" "mkdir -p $TEST_DIR && cd $TEST_DIR && find . -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +"
  tar -czf - --warning=no-timestamp --exclude=./vendor --exclude=./.phpunit.cache --exclude=./public/uploads \
      --exclude=./config/config.php --exclude=./config/.env --exclude=./var . \
    | ssh "$SERVER" "tar -xzf - --warning=no-timestamp --no-same-owner -C $TEST_DIR"
  if ! ssh "$SERVER" "cd $TEST_DIR && php8.3 /root/composer.phar install --no-interaction -q && php8.3 vendor/bin/phpunit --colors=never" > /tmp/skaz-deploy-phpunit.log 2>&1; then
    tail -40 /tmp/skaz-deploy-phpunit.log
    echo "Тесты не прошли — код не выкладываю (полный вывод: /tmp/skaz-deploy-phpunit.log)."
    exit 1
  fi
  grep -E '^(OK|Tests:)' /tmp/skaz-deploy-phpunit.log || true
fi

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

# Снимок текущей версии — для deploy.sh --rollback. Храним KEEP_RELEASES последних.
echo "Снимок текущей версии в $SERVER:$RELEASES ..."
ssh "$SERVER" "mkdir -p $RELEASES && chmod 700 $RELEASES && cd $DEST \
  && find . \( $SKIP \) -prune -o -type f -print | tar -czf $RELEASES/\$(date +%Y%m%d-%H%M%S).tar.gz -T - \
  && ls -1t $RELEASES/*.tar.gz | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -f"

echo "Отправка кода ($COUNT файлов) в $SERVER:$DEST ..."
printf '%s\n' "$LIST" | tar -czf - --warning=no-timestamp -T - \
  | ssh "$SERVER" "mkdir -p $DEST && tar -xzf - --warning=no-timestamp --no-same-owner -C $DEST"

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
