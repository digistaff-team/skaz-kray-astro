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

## 4a. PHP-FPM: лимиты на загрузку фото
Дефолты Debian режут снимки с телефона: `upload_max_filesize = 2M` при том, что
`Upload::saveImage` рассчитан на 5 МБ. Такой файл PHP отбрасывает молча
(`UPLOAD_ERR_INI_SIZE`) — карточка товара сохраняется, а фото к ней не появляется
никогда. А если тело перевалит за `post_max_size`, PHP выбросит его целиком вместе
с `_csrf`, и житель получит страницу «Файлы слишком тяжёлые» (см. `Csrf::guard()`).

В `/etc/php/8.3/fpm/php.ini`:
```
upload_max_filesize = 8M
post_max_size = 32M
```
```
systemctl reload php8.3-fpm
php8.3 -c /etc/php/8.3/fpm/php.ini -r 'echo ini_get("upload_max_filesize"), " / ", ini_get("post_max_size"), PHP_EOL;'
```
Лимит в тексте ошибки берётся из `ini_get('post_max_size')` — менять его в коде не нужно.

Снимки ужимаются до 1600 px ещё на телефоне (`templates/partials/photo-shrink.php`),
так что до этих потолков доходят только HEIC и браузеры без `createImageBitmap` —
серверные лимиты остаются страховкой, а не основным путём.

`client_max_body_size` в nginx уже 200M (`nginx.conf`), то есть узкое место — именно php.ini.

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
5. Вход через Telegram: при первом входе член совета вводит фамилию **и
   одноразовый код привязки**, который администратор выдаёт в `/sovet/upravlenie`
   (кнопка «код привязки Telegram», код живёт 7 дней). Колонки кода — миграция
   (один раз, до деплоя кода):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/council-claim-code.sql
   ```

## 7a. Заявки в совладельцы поместья
Присоединиться к занятому поместью можно только заявкой, которую принимает
владелец в «Моём поместье» (там же — объединение нового входа MAX с его
аккаунтом). Таблица заявок — миграция (один раз, до деплоя кода):
```
mysql skazkray_residents < /var/www/skaz-residents/config/household-join-requests.sql
```

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

## 13. Уровень воды в Шебше (иконка-капля на главной приложения)
Модальное окно по иконке в хедере `/poselenie/app` — рядом с картой. Крупная
цифра — запас до нижней кромки моста, под ней спарклайн за 30 суток.

Замеры берём у приложения [shebsh-water-level](https://shebsh-water-level.vercel.app)
(Vercel), которое скрапит гидропост AllRivers: `GET /api/water-level` →
`{"water_level":см,"change_24h":см}`. **Историю ведём свою**: чужой `/api/history`
живёт в Vercel KV и уже отдавал 502, а CORS-заголовков у него нет, так что из
браузера его всё равно не прочитать — запрос идёт с нашего сервера.

1. Накатить схему истории (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/water-level-history.sql
   ```
2. Поставить часовой снимок в cron (от root, `crontab -e`):
   ```
   5 * * * * /usr/bin/php8.3 /var/www/skaz-residents/bin/water-level-snapshot.php >> /var/log/water-level-snapshot.log 2>&1
   ```
   Проверка руками: `php8.3 bin/water-level-snapshot.php --dry-run` — спросит
   источник и покажет замер, ничего не записывая.
3. Отдельных env/nginx-правок не нужно. Адрес источника при необходимости
   переопределяется ключом `water_level_api` в `config/config.php`.

Гидрологические отметки (`src/Service/WaterLevel.php`) совпадают с `constants.ts`
того приложения: ноль гидропоста 38.158 м БСВ, нижняя кромка моста 35.160 м
(−300 см от нуля), опасная отметка 36.160 м (−200 см). Пороги — наши: предупреждаем
за 3 м до кромки, тревога за 1 м (`CALM_GAP_CM` / `WATCH_GAP_CM` там же). Отметка
«опасный уровень» в порогах не участвует, она только рисуется линией на диаграмме:
предупредить жителей нужно раньше, чем вода дойдёт до неё.
Диаграмма показывает линии отметок только когда они попадают в диапазон данных:
обычно вода много ниже моста, и линия ушла бы за рамку.

Панель сама освежает замер, если последний старше 90 минут, — то есть работает
и до того, как cron наберёт историю (диаграмма появится через сутки наблюдений).

### 13a. Оповещение группы о подходе воды к мосту
Решение принимает тот же часовой cron: после записи замера `WaterAlert` сравнивает
обстановку с сохранённой (таблица `water_alert_state`, одна строка) и пишет в
Telegram-канал `telegram.water_alert_chat_id` (если ключ не задан — в общий чат
жителей `telegram.group_chat_id`) и в группу MAX (`max.group_chat_id`).

**Бот должен быть администратором канала** с правом публикации, иначе Telegram
ответит `403: bot is not a member of the channel chat` и сообщение не уйдёт.
Проверить доступ: `getChat` по этому chat_id должен вернуть `ok: true`.

1. Накатить таблицу состояния (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/water-alert-state.sql
   ```
2. Отдельного cron не нужно — вызов встроен в `bin/water-level-snapshot.php`.

Когда уходит сообщение:

| Событие | Запас до кромки | Текст |
|---|---|---|
| спокойно → внимание | меньше 3 м (уровень выше −600 см, 32,16 м БСВ) | ⚠️ Шебш поднимается: до нижней кромки моста … |
| внимание → тревога | 1 м и меньше (уровень от −400 см, 34,16 м БСВ) | 🚨 Вода у моста: до нижней кромки … |
| вода выше кромки | уровень от −300 см (35,16 м БСВ) | 🚨 Мост под водой: уровень выше нижней кромки на … |
| тревога держится дольше 6 часов | — | тот же текст ещё раз |
| тревога → внимание (вода спадает) | снова больше 1 м | 🌊 Вода отходит от моста: до нижней кромки … |
| вернулось в спокойное | снова 3 м и больше | ✅ Вода отступила: до нижней кромки моста … |

Пока обстановка не меняется, cron молчит. На первом запуске о спокойной воде не
пишем — сообщение уйдёт только если вода уже подошла к мосту.

Скачок уровня больше 3 м за шаг — повод не верить источнику на слово (у AllRivers
уже менялась привязка шкалы), но замалчивать его нельзя: ливневый паводок в горах
поднимает воду быстро. Поэтому скачок **к опасной обстановке** идёт в группу с
пометкой «проверьте обстановку лично», а скачок **к спокойной** — только в лог:
ложный отбой опаснее лишней строки в журнале.

### 13b. Заливка прошлых замеров
```
php8.3 bin/water-level-import.php --dry-run            # посмотреть, что получится
php8.3 bin/water-level-import.php                      # залить
php8.3 bin/water-level-import.php --from=/path/history.json
```
По умолчанию берёт архив приложения shebsh-water-level из его репозитория; с
`--from=` принимает любой файл или URL с тем же форматом записей
(`{"created_at":…,"water_level":…,"change_24h":…}`) — например его `/api/history`,
когда Vercel KV оживёт. Записи с нечитаемой датой, без уровня и с уровнем вне
границ поста пропускаются, недостоверное суточное изменение обнуляется, замер за
уже занятый час перезаписывается — повторный запуск безопасен.

**Заливать сейчас почти нечего:** в архиве проекта одна запись (31.05.2026), а
`/api/history` отдаёт 502; исторические данные самого AllRivers закрыты их
PRO-подпиской. История копится нашим часовым cron'ом.

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
