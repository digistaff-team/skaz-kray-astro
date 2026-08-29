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
Локально: `bash residents/deploy/deploy.sh`

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
