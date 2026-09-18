# Установка раздела жителей на сервере

Сервер: `ssh abconsult` (root@31.128.43.151), тот же, что и статика skaz-kray.ru.

## 1. База данных
```sql
CREATE DATABASE skazkray_residents CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'skaz_residents'@'127.0.0.1' IDENTIFIED BY '<СИЛЬНЫЙ_ПАРОЛЬ>';
GRANT SELECT, INSERT, UPDATE, DELETE ON skazkray_residents.* TO 'skaz_residents'@'127.0.0.1';
FLUSH PRIVILEGES;
```
Накатить схему:
```
mysql skazkray_residents < /var/www/skaz-residents/config/schema.sql
```

## 2. Конфиг (секреты, вне git)
```
cp /var/www/skaz-residents/config/config.example.php /var/www/skaz-residents/config/config.php
chmod 640 /var/www/skaz-residents/config/config.php
chown root:www-data /var/www/skaz-residents/config/config.php
# заполнить: db.pass, smtp.* (ящик на skaz-kray.ru), при необходимости uploads_dir
```

## 3. Редактор-модератор
Завести аккаунт редактора (через регистрацию на сайте, затем в БД):
```sql
UPDATE families SET status='active', role='editor' WHERE email='<email редактора>';
```

## 4. nginx
Вставить блоки из `deploy/nginx-residents.conf.example` в vhost `skaz-kray_ru_astro`
(ориентир — рабочие блоки /oauth/ и /editor-auth/), затем:
```
nginx -t && systemctl reload nginx
```

## 5. Автодеплой статики не конфликтует
Приложение живёт в `/var/www/skaz-residents/`, вне докрута статики
(`/var/www/new.skaz-kray.ru/html`). `skaz-kray-autodeploy.sh` его не трогает —
дополнительных `--exclude` не требуется.

## 6. Обновление кода
```bash
bash residents/deploy/deploy.sh             # выкатить
bash residents/deploy/deploy.sh --dry-run   # только посмотреть, что уедет и что удалится
```
Работает и с Windows (Git Bash), и с Linux/macOS: `rsync` не нужен — дерево уезжает
архивом через `ssh`. Скрипт сам:

- отправляет весь код, кроме `config/config.php`, `config/.env`, `public/uploads/`,
  `vendor/`, `tests/`, `.phpunit.cache/` и `.git/`;
- удаляет на сервере файлы, которых больше нет в проекте (аналог `rsync --delete`).
  Если «лишних» набралось больше десятой части дерева, чистка пропускается — это
  почти всегда сбой сверки, а не правда. Посмотрите `--dry-run` и, если удаление
  верное, повторите с `FORCE_DELETE=1 bash residents/deploy/deploy.sh`;
- пересобирает автозагрузчик: `composer` из PATH, а если его нет (как на нашем
  сервере) — `php8.3 /root/composer.phar`;
- возвращает всему дереву владельца `www-data:www-data` (архив приезжает с Windows,
  где владельца нет, а `config.php` читается только этим пользователем).

**OPcache** перезагружать не нужно: на сервере `opcache.validate_timestamps=On`,
байткод перечитывается по mtime изменённых файлов — поэтому деплой не трогает
PHP-FPM. Новые `.sql`-миграции деплой НЕ применяет: их накатывают вручную от root
(см. разделы про соответствующие схемы).

### 6a. Тесты (PHP только на сервере)
Локально PHP нет — прогон в изолированной папке на сервере (рабочее дерево, а не
только закоммиченное):
```bash
ssh abconsult 'rm -rf /root/res-test && mkdir -p /root/res-test'
tar -C residents -cf - --exclude=vendor --exclude=.phpunit.cache \
  --exclude=public/uploads --exclude=node_modules --exclude=config/config.php . \
  | ssh abconsult 'tar -x -C /root/res-test'
ssh abconsult 'cd /root/res-test && ([ -f vendor/bin/phpunit ] || php8.3 /root/composer.phar install --no-interaction -q) && php8.3 vendor/bin/phpunit'
ssh abconsult 'rm -rf /root/res-test'
```

## 7. Раздел «Попечительский совет» (/sovet/)
Живёт в том же приложении и БД, но с отдельной таблицей аккаунтов
`council_members` (email независим от `families`).

1. Накатить схему совета (один раз):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/council-schema.sql
   ```
2. Завести первого администратора совета (дальше он добавляет остальных через
   веб-интерфейс `/sovet/upravlenie`):
   ```
   cd /var/www/skaz-residents
   php bin/council-admin.php <email> "<Имя>" "<пароль>"
   ```
3. nginx: блоки `/sovet` уже включены в `deploy/nginx-residents.conf.example`
   (location `= /sovet` и `^~ /sovet/` → тот же фронт-контроллер). После
   вставки — `nginx -t && systemctl reload nginx`.
4. Контент (состав совета, документы, протоколы, ближайшее собрание, направления)
   правится в коде — `src/CouncilData.php` (ссылки на Google Docs — заменить
   плейсхолдеры `PLACEHOLDER-*` на реальные). Доска задач `/sovet/zadachi`
   редактируется членами совета прямо в интерфейсе.

## 8. Шеринг инструментов (/poselenie/instrumenty)
Часть раздела жителей: жители делятся своими инструментами (P2P), заявку
одобряет владелец. Модерации нет. Фото — в существующей таблице `images`
(owner_type='tool'), каталог виден только вошедшим жителям.

1. Накатить схему инструментов (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/tools-schema.sql
   ```
2. Отдельных env/nginx-правок не нужно — всё под уже настроенным `/poselenie/`.
   Пункт меню «Инструменты» ведёт на `/poselenie/instrumenty`.

## 9. Обмен книгами (/poselenie/knigi)
Зеркало сервиса инструментов, но для книг (title/author/genre). P2P, бронь
одобряет владелец, возврат с проверкой состояния. Фото — `images` owner_type='book'.

1. Накатить схему книг (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/books-schema.sql
   ```
2. Отдельных env/nginx-правок не нужно. Пункт меню «Книги» → `/poselenie/knigi`.

## 11. Совместные поездки (/poselenie/poezdki)
Попутки: водитель-семья публикует поездку A→B на дату/время с числом мест;
пассажир бронирует место; при подтверждении водителем места списываются.
Только для вошедших жителей. Эталон — Ride_Share_Bot (carpool-домен).

1. Накатить схему поездок (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/rides-schema.sql
   ```
2. Отдельных env/nginx-правок не нужно. Пункт меню «Поездки» → `/poselenie/poezdki`.

## 12. Вкл/выкл разделов приложения (админ сайта)
Админ сайта может скрывать разделы (плитки на главной `/poselenie/app` + пункты
меню): Дневники, Инструменты, Книги, Поездки, Бюджет, Ярмарка, Наши соседи.
«Наше поместье» — базовый раздел, не выключается. Состояние хранится в таблице
`app_sections` (переопределения; строки нет → раздел включён).

**Роли.** В `families.role` теперь три значения: `resident` | `editor` | `admin`.
`admin` — надмножество `editor` (`Auth::isEditor()` истинно и для админа): админ
делает всё, что редактор (модерация заявок/дневников/товаров), плюс управляет
разделами. Управление разделами — ТОЛЬКО у админа (`Auth::requireAdmin`), редактор
его не видит. Так текстовый редактор (модерация Новостей/Дневников/Статей) отделён
от управления сайтом.

1. Накатить схему (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/app-sections-schema.sql
   ```
2. Назначить админом сайта аккаунт `poselenie@skaz-kray.ru` (был `editor`):
   ```sql
   UPDATE families SET role='admin' WHERE email='poselenie@skaz-kray.ru';
   ```
   (после этого нужно перелогиниться — роль пишется в сессию при входе).
3. Управление — на странице `/poselenie/moderation/razdely` (ссылка «Разделы»
   в меню и на странице модерации, видна только админу). Отдельных
   env/nginx-правок не нужно. Выключение скрывает только плитку и пункт меню —
   прямой URL раздела продолжает работать.

## 10. Публичные страницы = дизайн сайта (site-mirror.css)
`/dnevniki-pomestiy/` и `/yarmarka/` рендерятся раздельным `templates/public/layout.php`,
который грузит `public/assets/site-mirror.css` — точную копию скомпилированного
CSS внешнего Astro-сайта (не `residents.css`). Меню в шапке/подвале захардкожено
в `templates/public/site_header.php` / `site_footer.php` — **синхронизировать
вручную** при правке `src/data/nav.js` на сайте.

**Регенерация `site-mirror.css` при изменении дизайна сайта:**
```
# 1) взять актуальные бандлы сайта (имена хешей меняются каждый билд —
#    подсмотреть <link ... /_astro/_slug_.*.css> в HTML любой рубрики):
curl -s https://skaz-kray.ru/_astro/_slug_.XXXX.css > b1.css   # global + Header/Footer
curl -s https://skaz-kray.ru/_astro/_slug_.YYYY.css > b2.css   # Category/PostCard/Post
# 2) снять Astro-скоуп и убрать @font-face из b1 (заменяются локальными):
perl -0pe 's/^.*?:root\{/:root{/s' b1.css | perl -pe 's/\[data-astro-cid-[a-z0-9]+\]//g' > b1c.css
perl -pe 's/\[data-astro-cid-[a-z0-9]+\]//g' b2.css > b2c.css
# 3) собрать: <локальные @font-face> + b1c + b2c + служебные .pager/.archive-note
#    (см. текущую шапку site-mirror.css). Шрифты уже лежат в public/assets/fonts/.
```
